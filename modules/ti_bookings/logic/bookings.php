<?php
/**
 * modules/ti_bookings/logic/bookings.php — Rezerwacje terminów konsultacji (przeniesione z karty30/schedules/*).
 *
 * Tabela k30_schedules bez zmian — te same statusy (K30_SCHEDULE_STATUSES), rozliczenie (K30_BILLING_TYPES:
 * bezpłatne / odpłatne / PFRON, wycena k30_calculate_amount_v2 z licznikami k30_clients.used/used_paid i
 * k30_pfron_contracts.hours_used), serie (k30_generate_series_dates / k30_create_series), sale wspólne z Systemem
 * Rezerwacji Sal (modules/srs: godziny dostępności, kolizje z k30_schedules i resource_reservations), faktura
 * (notatka i aktywność w CRM + powiadomienie administratorów), kolejka oczekujących (k30_waiting_list).
 * Nowe względem starego modułu: ostrzeżenie o zajętości konsultanta, wpis w CRM przy każdej zmianie statusu,
 * powiązanie rezerwacji z kolejką oczekujących (scheduled_id), filtr serii na liście.
 * Ekrany: karty30/ti/dydaktyk/rezerwacje.php, rezerwacja.php, rezerwacjeKalendarz.php, rezerwacjaPdf.php, kolejka.php.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/pfron.php';

final class TiBookings
{
    /** Szybka zmiana statusu → [typ aktywności CRM, tytuł, status aktywności] (jak dawne edit.php). */
    private const CRM = [
        'attended'            => ['meeting', 'Odbyła się wizyta TyfloK.', 'done'],
        'cancelled_by_feer'   => ['task',    'Odwołano wizytę (FEER)', 'done'],
        'cancelled_by_client' => ['task',    'Odwołano wizytę (beneficjent)', 'done'],
        'no_show'             => ['call',    'Beneficjent nie pojawił się', 'done'],
        'confirmed'           => ['meeting', 'Potwierdzono wizytę TyfloK.', 'planned'],
        'cancelled'           => ['task',    'Anulowano wizytę TyfloK.', 'done'],
    ];
    public const RECUR_FREQ = ['daily' => 'codziennie', 'weekly' => 'co tydzień', 'biweekly' => 'co 2 tygodnie', 'monthly' => 'co miesiąc'];

    public static function srs(): void
    {
        require_once dirname(__DIR__, 3) . '/modules/srs/logic/srs.php';
        resources_migrate();
    }

    // ── Odczyt ───────────────────────────────────────────────────────────────

    public static function get(int $id): ?array
    {
        self::srs();
        return db_one(
            "SELECT s.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email, c.address AS client_address,
                    c.date_of_birth AS client_dob, u.name AS consultant_name, u.email AS consultant_email, cr.name AS created_by_name,
                    r.name AS resource_name, rc.name AS resource_cat, pc.contract_number AS pfron_number
               FROM k30_schedules s
               LEFT JOIN k30_clients c ON c.id = s.client_id
               LEFT JOIN users u ON u.id = s.assigned_to
               LEFT JOIN users cr ON cr.id = s.created_by
               LEFT JOIN resources r ON r.id = s.resource_id
               LEFT JOIN resource_categories rc ON rc.id = r.category_id
               LEFT JOIN k30_pfron_contracts pc ON pc.id = s.pfron_contract_id
              WHERE s.id = ?", [$id]) ?: null;
    }

    /** Lista z filtrami: q (beneficjent), status, from, to (daty), client_id, consultant, series. */
    public static function list(array $f, int $limit = 50, int $offset = 0): array
    {
        [$w, $p] = self::where($f);
        return db_all(
            "SELECT s.*, c.name AS client_name, u.name AS consultant_name,
                    (SELECT COUNT(*) FROM k30_consultations k WHERE k.schedule_id = s.id AND k.status <> 'cancelled') AS cards
               FROM k30_schedules s LEFT JOIN k30_clients c ON c.id = s.client_id LEFT JOIN users u ON u.id = s.assigned_to
              WHERE $w ORDER BY s.start_time DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset, $p);
    }

    public static function count(array $f): int
    {
        [$w, $p] = self::where($f);
        return (int)(db_one("SELECT COUNT(*) AS n FROM k30_schedules s LEFT JOIN k30_clients c ON c.id = s.client_id WHERE $w", $p)['n'] ?? 0);
    }

    private static function where(array $f): array
    {
        $w = ['1=1']; $p = [];
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = 'c.name LIKE ?'; $p[] = "%$q%"; }
        if (isset(K30_SCHEDULE_STATUSES[$f['status'] ?? ''])) { $w[] = 's.status = ?'; $p[] = $f['status']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? ''))) { $w[] = 'DATE(s.start_time) >= ?'; $p[] = $f['from']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? ''))) { $w[] = 'DATE(s.start_time) <= ?'; $p[] = $f['to']; }
        if ((int)($f['client_id'] ?? 0) > 0) { $w[] = 's.client_id = ?'; $p[] = (int)$f['client_id']; }
        if ((int)($f['consultant'] ?? 0) > 0) { $w[] = 's.assigned_to = ?'; $p[] = (int)$f['consultant']; }
        if (preg_match('/^[a-f0-9]{8,32}$/', (string)($f['series'] ?? ''))) { $w[] = 's.series_id = ?'; $p[] = $f['series']; }
        return [implode(' AND ', $w), $p];
    }

    /** Zdarzenia kalendarza (FullCalendar) w zakresie dat. */
    public static function events(string $from, string $to, array $f = []): array
    {
        $f = ['from' => substr($from, 0, 10), 'to' => substr($to, 0, 10)] + $f;
        $out = [];
        foreach (self::list($f, 2000) as $s) {
            $st = K30_SCHEDULE_STATUSES[$s['status']] ?? ['label' => $s['status'], 'color' => '#6b7280', 'bg' => '#f3f4f6'];
            $start = str_replace(' ', 'T', substr((string)$s['start_time'], 0, 16));
            $out[] = [
                'id' => (int)$s['id'], 'title' => ($s['client_name'] ?: '?') . ($s['consultant_name'] ? ' / ' . $s['consultant_name'] : ''),
                'start' => $start, 'end' => date('Y-m-d\TH:i', strtotime((string)$s['start_time']) + max(15, (int)$s['duration_minutes']) * 60),
                'url' => 'rezerwacja.php?id=' . (int)$s['id'], 'backgroundColor' => $st['bg'], 'borderColor' => $st['color'], 'textColor' => '#111827',
                'extendedProps' => ['status' => $st['label'], 'series' => (string)$s['series_id'] !== ''],
            ];
        }
        return $out;
    }

    /** Wolne sloty do szybkiej rezerwacji: 7 dni roboczych, 9:00–16:00 co godzinę; zajętość per konsultant (0 = globalnie). */
    public static function quickSlots(int $consultant = 0, int $max = 12): array
    {
        $out = []; $d = strtotime('tomorrow');
        for ($days = 0; $days < 7 && count($out) < $max; $d += 86400) {
            if ((int)date('N', $d) >= 6) continue;
            $days++;
            for ($h = 9; $h <= 16 && count($out) < $max; $h++) {
                $start = date('Y-m-d', $d) . sprintf(' %02d:00:00', $h);
                $busy = db_one("SELECT 1 FROM k30_schedules WHERE status IN ('preliminary','confirmed')
                                  AND start_time < ? AND datetime(start_time, '+' || duration_minutes || ' minutes') > ?" . ($consultant ? ' AND assigned_to = ?' : ''),
                               array_merge([date('Y-m-d H:i:s', strtotime($start) + 3600), $start], $consultant ? [$consultant] : []));
                if (!$busy) $out[] = $start;
            }
        }
        return $out;
    }

    // ── Walidacja ────────────────────────────────────────────────────────────

    /**
     * Sprawdza dane rezerwacji. $d: client_id, assigned_to, date, time, duration, status, location_type (resource|remote),
     * resource_id, billing_type, pfron_contract_id, invoice (needs, type, name, nip, address, email).
     * @return array{errors:string[], warnings:string[]}
     */
    public static function validate(array $d, int $excludeId = 0): array
    {
        self::srs();
        $err = []; $warn = [];
        $date = (string)($d['date'] ?? ''); $time = (string)($d['time'] ?? ''); $dur = (int)($d['duration'] ?? 0);
        if (!(int)($d['client_id'] ?? 0) || !db_one("SELECT 1 FROM k30_clients WHERE id=?", [(int)$d['client_id']])) $err[] = 'Wybierz beneficjenta.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) $err[] = 'Podaj datę i godzinę.';
        if ($dur < 5) $err[] = 'Czas trwania musi mieć co najmniej 5 minut.';
        if (!isset(K30_SCHEDULE_STATUSES[$d['status'] ?? ''])) $err[] = 'Nieznany status.';
        $bt = (string)($d['billing_type'] ?? 'free');
        if (!isset(K30_BILLING_TYPES[$bt])) $err[] = 'Nieznany rodzaj rozliczenia.';
        if ($bt === 'pfron' && !(int)($d['pfron_contract_id'] ?? 0)) $err[] = 'Rozliczenie PFRON wymaga wskazania umowy PFRON.';
        if (!empty($d['needs_invoice'])) {
            if (trim((string)($d['invoice_name'] ?? '')) === '' || trim((string)($d['invoice_address'] ?? '')) === '') $err[] = 'Faktura: podaj nazwę i adres.';
            if (($d['invoice_type'] ?? 'company') === 'company' && preg_replace('/\D/', '', (string)($d['invoice_nip'] ?? '')) === '') $err[] = 'Faktura firmowa wymaga NIP.';
        }
        $loc = (string)($d['location_type'] ?? '');
        $rid = (int)($d['resource_id'] ?? 0);
        if ($loc !== 'remote' && !$rid) $err[] = 'Wybierz salę albo zaznacz konsultację zdalną.';
        if ($err) return ['errors' => $err, 'warnings' => $warn];

        $end = date('H:i', strtotime("$date $time") + $dur * 60);
        if ($loc !== 'remote' && $rid) {
            if (!res_is_available_at($rid, $date, $time, $end)) {
                $r = res_get($rid); $av = res_availability($rid); $dow = (int)date('w', strtotime($date));
                $err[] = !empty($av[$dow]['is_closed']) ? 'Sala „' . ($r['name'] ?? '') . '” jest w tym dniu zamknięta.'
                    : 'Termin ' . $time . '–' . $end . ' wykracza poza godziny sali „' . ($r['name'] ?? '') . '”'
                      . (isset($av[$dow]) ? ' (' . $av[$dow]['time_open'] . '–' . $av[$dow]['time_close'] . ')' : '') . '.';
            } elseif ($c = db_one(
                "SELECT s.id, c.name AS client_name, TIME(s.start_time) AS t1, TIME(s.start_time, '+' || s.duration_minutes || ' minutes') AS t2
                   FROM k30_schedules s LEFT JOIN k30_clients c ON c.id = s.client_id
                  WHERE s.resource_id = ? AND s.id <> ? AND s.status NOT IN ('cancelled','rejected','cancelled_by_feer','cancelled_by_client')
                    AND DATE(s.start_time) = ? AND TIME(s.start_time) < ? AND TIME(s.start_time, '+' || s.duration_minutes || ' minutes') > ?",
                [$rid, $excludeId, $date, $end, $time])) {
                $err[] = 'Sala jest zajęta (rezerwacja #' . $c['id'] . ', ' . ($c['client_name'] ?? '?') . ', ' . substr((string)$c['t1'], 0, 5) . '–' . substr((string)$c['t2'], 0, 5) . ').';
            } elseif ($c = db_one(
                "SELECT rr.purpose, u.name AS user_name FROM resource_reservations rr JOIN users u ON u.id = rr.user_id
                  WHERE rr.resource_id = ? AND rr.status NOT IN ('odmowa','anulowana') AND rr.date_from <= ? AND rr.date_to >= ?
                    AND (rr.time_from = '' OR rr.time_to = '' OR (rr.time_from < ? AND rr.time_to > ?))", [$rid, $date, $date, $end, $time])) {
                $err[] = 'Sala jest zarezerwowana w Systemie Rezerwacji Sal' . (trim((string)$c['purpose']) !== '' ? ' — ' . $c['purpose'] : '') . ' (' . ($c['user_name'] ?? '?') . ').';
            }
        }
        // Zajętość konsultanta — ostrzeżenie (dawniej nie sprawdzane)
        if ((int)($d['assigned_to'] ?? 0) && ($c = db_one(
            "SELECT s.id, c.name AS client_name, TIME(s.start_time) AS t1 FROM k30_schedules s LEFT JOIN k30_clients c ON c.id = s.client_id
              WHERE s.assigned_to = ? AND s.id <> ? AND s.status IN ('preliminary','confirmed') AND DATE(s.start_time) = ?
                AND TIME(s.start_time) < ? AND TIME(s.start_time, '+' || s.duration_minutes || ' minutes') > ?",
            [(int)$d['assigned_to'], $excludeId, $date, $end, $time]))) {
            $warn[] = 'Konsultant ma w tym czasie inną rezerwację (#' . $c['id'] . ', ' . ($c['client_name'] ?? '?') . ', ' . substr((string)$c['t1'], 0, 5) . ').';
        }
        return ['errors' => $err, 'warnings' => $warn];
    }

    // ── Zapis ────────────────────────────────────────────────────────────────

    /**
     * Nowa rezerwacja (pojedyncza albo seria). $d jak validate() + description, time_to, recurring (bool), recur_freq,
     * recur_days[], recur_count, recur_until, recur_end_by (count|date), from_waiting (id wpisu kolejki).
     * @return array{ok:bool, ids:int[], errors:string[], warnings:string[]}
     */
    public static function create(array $d, int $uid, string $byName): array
    {
        $v = self::validate($d);
        if ($v['errors']) return ['ok' => false, 'ids' => [], 'errors' => $v['errors'], 'warnings' => $v['warnings']];
        $client = (int)$d['client_id']; $dur = (int)$d['duration']; $bt = (string)($d['billing_type'] ?? 'free');
        $pfron = $bt === 'pfron' ? ((int)($d['pfron_contract_id'] ?? 0) ?: null) : null;
        $remote = ($d['location_type'] ?? '') === 'remote';
        $inv = !empty($d['needs_invoice']);
        $rule = '';
        if (!empty($d['recurring'])) {
            $rule = json_encode(['freq' => $d['recur_freq'] ?? 'weekly', 'days' => array_map('intval', (array)($d['recur_days'] ?? [])),
                                 'count' => (int)($d['recur_count'] ?? 0), 'until' => (string)($d['recur_until'] ?? ''), 'end_by' => $d['recur_end_by'] ?? 'count'], JSON_UNESCAPED_UNICODE);
        }
        $base = [
            'client_id' => $client, 'assigned_to' => ((int)($d['assigned_to'] ?? 0)) ?: null,
            'start_time' => $d['date'] . ' ' . $d['time'] . ':00', 'duration_minutes' => $dur,
            'time_from' => $d['time'], 'time_to' => (string)($d['time_to'] ?? '') ?: date('H:i', strtotime($d['date'] . ' ' . $d['time']) + $dur * 60),
            'status' => (string)$d['status'], 'description' => trim((string)($d['description'] ?? '')) ?: null,
            'resource_id' => $remote ? null : ((int)($d['resource_id'] ?? 0) ?: null), 'is_remote' => $remote ? 1 : 0,
            'billing_type' => $bt, 'pfron_contract_id' => $pfron, 'pfron_status' => $bt === 'pfron' ? 'pending' : '', 'recurrence_rule' => $rule,
            'needs_invoice' => $inv ? 1 : 0, 'invoice_type' => (string)($d['invoice_type'] ?? 'company'),
            'invoice_name' => $inv ? trim((string)$d['invoice_name']) : '', 'invoice_nip' => $inv ? preg_replace('/\D/', '', (string)($d['invoice_nip'] ?? '')) : '',
            'invoice_address' => $inv ? trim((string)$d['invoice_address']) : '', 'invoice_email' => $inv ? trim((string)($d['invoice_email'] ?? '')) : '',
            'created_by' => $uid ?: null,
        ];
        if ($rule !== '') {
            $dates = k30_generate_series_dates((string)$d['date'], (string)($d['recur_freq'] ?? 'weekly'), array_map('intval', (array)($d['recur_days'] ?? [])),
                ($d['recur_end_by'] ?? 'count') === 'count' ? max(1, min(52, (int)($d['recur_count'] ?? 1))) : 0,
                ($d['recur_end_by'] ?? '') === 'date' ? (string)($d['recur_until'] ?? '') : '');
            if (!$dates) return ['ok' => false, 'ids' => [], 'errors' => ['Ustawienia powtarzania nie dają żadnego terminu.'], 'warnings' => $v['warnings']];
            $ids = k30_create_series($base, $dates);
        } else {
            $pr = k30_calculate_amount_v2($client, round($dur / 60, 4), $bt, $pfron);
            unset($base['recurrence_rule']);
            $ids = [(int)db_insert('k30_schedules', $base + ['recurrence_rule' => '', 'billed_hours' => $pr['billed_hours'], 'free_hours' => $pr['free_hours'],
                     'charged_hours' => $pr['charged_hours'], 'amount_due' => $pr['amount_due'], 'pricing_note' => $pr['pricing_note'], 'series_index' => 0])];   // series_id: domyślne '' (db_insert zamienia '' w *_id na NULL)
            db()->prepare("UPDATE k30_clients SET used=used+?, used_paid=used_paid+?, updated_at=datetime('now') WHERE id=?")->execute([$pr['billed_hours'], $pr['charged_hours'], $client]);
            if ($pfron && $pr['free_hours'] > 0) db()->prepare("UPDATE k30_pfron_contracts SET hours_used=hours_used+?, updated_at=datetime('now') WHERE id=?")->execute([$pr['free_hours'], $pfron]);
        }
        if ((int)($d['from_waiting'] ?? 0) && $ids) {   // kolejka oczekujących → zaplanowane (dawniej nie łączone)
            try { k30_waiting_change_status((int)$d['from_waiting'], 'scheduled', $ids[0]); } catch (\Throwable $e) {}
        }
        if ($inv && $ids) self::invoiceNotify($ids[0], $base, $uid);
        // Beneficjent konsultacji bez konta w panelu → konto „k1234” z hasłem SMS-em (kursanci szkoleń mają konta po staremu)
        require_once dirname(__DIR__, 2) . '/ti_consultations/logic/consultations.php';
        $acc = $ids ? TiConsultations::ensureBeneficiaryAccount($client, $uid) : null;
        return ['ok' => true, 'ids' => $ids, 'errors' => [], 'warnings' => $v['warnings'], 'account' => $acc];
    }

    /** Edycja jak dawniej (beneficjent, konsultant, termin, czas, status, opis) + sala/zdalnie ze sprawdzeniem kolizji. Bez ponownej wyceny. */
    public static function update(int $id, array $d, int $uid, string $byName): array
    {
        $old = self::get($id);
        if (!$old) return ['ok' => false, 'errors' => ['Nie znaleziono rezerwacji.'], 'warnings' => []];
        $d += ['billing_type' => $old['billing_type'], 'pfron_contract_id' => $old['pfron_contract_id']];
        $v = self::validate($d, $id);
        if ($v['errors']) return ['ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']];
        $remote = ($d['location_type'] ?? '') === 'remote';
        db()->prepare("UPDATE k30_schedules SET client_id=?, assigned_to=?, start_time=?, duration_minutes=?, time_from=?, time_to=?, status=?, description=?,
                              resource_id=?, is_remote=?, updated_at=datetime('now') WHERE id=?")
            ->execute([(int)$d['client_id'], ((int)($d['assigned_to'] ?? 0)) ?: null, $d['date'] . ' ' . $d['time'] . ':00', (int)$d['duration'], $d['time'],
                       date('H:i', strtotime($d['date'] . ' ' . $d['time']) + (int)$d['duration'] * 60), (string)$d['status'],
                       trim((string)($d['description'] ?? '')) ?: null, $remote ? null : ((int)($d['resource_id'] ?? 0) ?: null), $remote ? 1 : 0, $id]);
        if ($old['status'] !== $d['status']) self::crmStatus($id, (string)$d['status'], '', $uid);
        return ['ok' => true, 'errors' => [], 'warnings' => $v['warnings']];
    }

    /** Szybka zmiana statusu (z powodem odwołania) + wpis w CRM. */
    public static function setStatus(int $id, string $status, string $reason, int $uid): bool
    {
        if (!isset(self::CRM[$status])) return false;
        db()->prepare("UPDATE k30_schedules SET status=?, cancel_reason=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $reason, $id]);
        self::crmStatus($id, $status, $reason, $uid);
        return true;
    }

    /** Odwołanie przyszłych terminów serii (bez odbytych i już odwołanych). */
    public static function cancelSeries(int $id, string $reason): int
    {
        $s = db_one("SELECT series_id, start_time FROM k30_schedules WHERE id=?", [$id]);
        if (!$s || (string)$s['series_id'] === '') return 0;
        $st = db()->prepare("UPDATE k30_schedules SET status='cancelled', cancel_reason=?, updated_at=datetime('now')
                              WHERE series_id=? AND start_time >= ? AND status NOT IN ('attended','cancelled')");
        $st->execute([$reason, $s['series_id'], $s['start_time']]);
        return $st->rowCount();
    }

    private static function crmStatus(int $id, string $status, string $reason, int $uid): void
    {
        if (!isset(self::CRM[$status])) return;
        try {
            $s = db_one("SELECT client_id, start_time FROM k30_schedules WHERE id=?", [$id]);
            $cl = $s ? db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$s['client_id']]) : null;
            if (!$cl) return;
            $cid = k30_get_crm_contact($cl) ?: k30_sync_to_crm($cl, $uid);
            [$type, $title, $ast] = self::CRM[$status];
            if ($cid) k30_log_crm_activity($cid, $type, $title, $reason, (string)$s['start_time'], $ast, $reason, $uid);
        } catch (\Throwable $e) { error_log('ti_bookings CRM: ' . $e->getMessage()); }
    }

    /** Prośba o fakturę: notatka i aktywność w CRM + powiadomienie i e-mail do administratorów (jak dawne add.php). */
    private static function invoiceNotify(int $id, array $b, int $uid): void
    {
        $client = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$b['client_id']]) ?: [];
        $date = substr((string)$b['start_time'], 0, 10);
        $body = 'Prośba o FV (' . ($b['invoice_type'] === 'personal' ? 'imienna' : 'firmowa') . ') do terminu konsultacji ' . $date . ":\n"
              . 'Nazwa: ' . $b['invoice_name'] . "\n" . ($b['invoice_nip'] ? 'NIP: ' . $b['invoice_nip'] . "\n" : '') . 'Adres: ' . $b['invoice_address']
              . ($b['invoice_email'] ? "\nE-mail FV: " . $b['invoice_email'] : '');
        try {
            if ($client && ($cid = k30_sync_to_crm($client, $uid))) {
                require_once dirname(__DIR__, 3) . '/includes/crm.php';
                crm_migrate();
                CrmManager::addNote($cid, $body, $uid, true);
                k30_log_crm_activity($cid, 'task', 'Faktura — konsultacja ' . $date, $body, (string)$b['start_time'], 'planned', '', $uid);
            }
        } catch (\Throwable $e) { error_log('ti_bookings invoice CRM: ' . $e->getMessage()); }
        try {
            require_once dirname(__DIR__, 3) . '/includes/notifications.php';
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            notif_migrate();
            $name = (string)($client['name'] ?? 'beneficjent');
            $title = 'Prośba o FV — konsultacja — ' . $name . ' (' . $date . ')';
            $url = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php?next=' . rawurlencode('rezerwacja.php?id=' . $id);
            $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $admins = db_all("SELECT id, name, email FROM users WHERE role='admin' AND is_active=1");
            foreach ($admins as $a) {
                notif_create((int)$a['id'], 'system', $title, $body, $url);
                $html = '<p>Beneficjent <strong>' . $e($name) . '</strong> prosi o fakturę do konsultacji w dniu <strong>' . $e($date) . '</strong>.</p><pre style="font-family:inherit">'
                      . $e($body) . '</pre><p><a href="' . $e($url) . '">Przejdź do rezerwacji</a></p>';
                mail_queue_add((string)$a['email'], (string)$a['name'], $title, $html, $body, 'k30_invoice', $id);
            }
            if ($admins) mail_queue_process(count($admins));
        } catch (\Throwable $e) { error_log('ti_bookings invoice notify: ' . $e->getMessage()); }
    }

    /** Liczba terminów serii i pozycja (do „Wizyta n z N”). */
    public static function seriesInfo(array $s): ?array
    {
        if ((string)($s['series_id'] ?? '') === '') return null;
        $n = (int)(db_one("SELECT COUNT(*) AS n FROM k30_schedules WHERE series_id=?", [$s['series_id']])['n'] ?? 0);
        return ['index' => (int)$s['series_index'] + 1, 'total' => $n];
    }

    /** Wycena podglądowa (bez zapisu) — do formularza. */
    public static function quote(int $client, int $minutes, string $bt, ?int $pfron): array
    {
        return k30_calculate_amount_v2($client, round(max(0, $minutes) / 60, 4), isset(K30_BILLING_TYPES[$bt]) ? $bt : 'free', $pfron ?: null);
    }

    // ── Kolejka oczekujących ────────────────────────────────────────────────

    public static function waitingAdd(int $client, string $priority, string $reason, string $notes, int $uid): array
    {
        if (db_one("SELECT 1 FROM k30_waiting_list WHERE client_id=? AND status IN ('waiting','contacted')", [$client]))
            return ['ok' => false, 'msg' => 'Ten beneficjent już czeka w kolejce.'];
        // Jak k30_waiting_add(), ale z autorem z panelu (tam created_by = current_user() — w panelu puste → błąd klucza obcego)
        $now = date('Y-m-d H:i:s');
        db_insert('k30_waiting_list', ['client_id' => $client, 'priority' => isset(K30_WAIT_PRIORITIES[$priority]) ? $priority : 'zwykly',
            'reason' => $reason, 'notes' => $notes, 'status' => 'waiting', 'created_by' => $uid ?: null, 'created_at' => $now, 'updated_at' => $now]);
        try {
            $cl = db_one("SELECT * FROM k30_clients WHERE id=?", [$client]);
            if ($cl && ($cid = k30_sync_to_crm($cl, $uid)))
                k30_log_crm_activity($cid, 'task', 'Dodano do kolejki konsultacji [' . (K30_WAIT_PRIORITIES[$priority]['label'] ?? 'Zwykły') . ']', trim($reason . ($notes !== '' ? "\n" . $notes : '')), $now, 'planned', '', $uid);
        } catch (\Throwable $e) { error_log('ti_bookings waiting CRM: ' . $e->getMessage()); }
        return ['ok' => true, 'msg' => 'Dodano do kolejki oczekujących.'];
    }
}
