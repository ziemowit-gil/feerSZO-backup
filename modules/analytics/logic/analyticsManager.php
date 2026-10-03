<?php
/**
 * modules/analytics/logic/analyticsManager.php — Analityka TI: wskaźniki (KPI) i raporty zarządcze.
 *
 * Tylko odczyt: żadnych tabel i migracji, dane pochodzą z istniejących modułów —
 *   zapisy kursantów (k30_ti_enrollments, k30_ti_courses), licencje (modules/ti_licencje),
 *   sprzęt (wypożyczenia laptopów k30_pl_laptop_loans, pole „sprzęt” kursanta k30_clients.equipment
 *   i rejestr sprzętu karty kursanta k30_ti_client_equipment), wnioski o zwrot kosztów (zwroty_kosztow),
 *   lekcje i limity kadry (k30_ti_sessions, planista).
 *
 * Ekrany stoją w panelu dydaktyka (karty30/ti/dydaktyk/analityka*.php). Dostęp ściśle zarządczy:
 * administrator, zarząd (ustawienie zarzad_user_ids) albo uprawnienie modułowe „analityka” (rola lub konto).
 * Brak dostępu do danych osobowych dla prowadzących i pozostałych użytkowników panelu.
 *
 * Raport to „zestaw danych”: ['title','subtitle','headers','rows','num','dec','sheet'] — ten sam kształt dla tabeli
 * na ekranie, widoku listy (czytniki ekranu) i eksportu CSV / Excel. Komórki liczbowe są liczbami (int/float),
 * reszta tekstem; kolumny liczbowe wskazuje 'num', liczbę miejsc po przecinku 'dec' (kolumna => miejsca).
 */
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class AnalyticsManager
{
    public const PERMISSION = 'analityka';

    /** Raporty: klucz => [etykieta, ikona bi-*, opis]. */
    public const REPORTS = [
        'bez_sprzetu' => ['Kursanci bez sprzętu', 'laptop',
            'Aktywni kursanci, u których nie ma żadnej informacji o sprzęcie: brak otwartego wypożyczenia laptopa, pusta rubryka „sprzęt” i brak wpisu w rejestrze sprzętu karty kursanta.'],
        'licencje' => ['Wygasające licencje', 'key',
            'Licencje kursantów (bez unieważnionych), które wygasają w wybranym oknie czasu — do odnowienia lub rozliczenia.'],
        'obciazenie' => ['Obciążenie instruktorów', 'person-workspace',
            'Lekcje odbyte i zaplanowane w miesiącu, godziny oraz wykorzystanie miesięcznego limitu godzin prowadzącego.'],
        'rozliczenia' => ['Rozliczenia licencji (FAK / FAZ / DSC / GST)', 'receipt',
            'Przypisane licencje wystawione w wybranym okresie, z formą rozliczenia i numerem faktury lub kodem.'],
    ];

    /** Statusy wniosku o zwrot kosztów uznawane za otwarte (FinanceManager::STATUSES bez „wypłacono” i „odrzucony”). */
    public const OPEN_REIMBURSEMENT = ['oczekuje', 'weryfikacja', 'zatwierdzony', 'do_wyplaty'];

    /** Wpisy w rubryce „sprzęt” kursanta znaczące brak sprzętu (bez względu na wielkość liter). */
    private const NO_EQUIPMENT_TEXT = ['brak', 'brak sprzętu', 'nie', 'nie posiada', 'nie ma', '-', '—', 'b.d.', 'bd', 'n/a'];

    /** Warunek „aktywny kursant”: jak na pulpicie kierownika (zapis aktywny w aktywnej, nieanulowanej i niezarchiwizowanej grupie). */
    private const ACTIVE_ENROLLED = "e.status = 'active' AND c.is_active = 1 AND c.status NOT IN ('cancelled','archived')";

    // ── Dostęp ───────────────────────────────────────────────────────────────

    /**
     * Czy użytkownik ma dostęp do Analityki. $actor: ['id' => users.id, 'role' => users.role].
     * W panelu dydaktyka nie ma sesji SZO (current_user() jest puste), więc rozstrzygamy po id i roli.
     * Zarząd czytamy wprost z ustawienia — includes/byli.php ma efekty uboczne przy dołączeniu.
     */
    public static function canAccess(array $actor): bool
    {
        $uid  = (int)($actor['id'] ?? 0);
        $role = (string)($actor['role'] ?? '');
        if ($uid <= 0) return false;
        if ($role === 'admin') return true;
        if (function_exists('org_setting')) {
            $board = array_filter(array_map('intval', explode(',', (string)org_setting('zarzad_user_ids'))));
            if (in_array($uid, $board, true)) return true;
        }
        if (!function_exists('role_permissions')) return false;
        if (!empty(role_permissions($role)[self::PERMISSION]['can_read'])) return true;
        return !empty(user_permissions($uid)[self::PERMISSION]['can_read']);
    }

    /** To samo dla wiersza z tabeli users (aktywne konto). */
    public static function canUser(array $u): bool
    {
        return !empty($u['is_active']) && self::canAccess(['id' => $u['id'] ?? 0, 'role' => $u['role'] ?? '']);
    }

    // ── Wskaźniki (KPI) ──────────────────────────────────────────────────────

    /**
     * Wskaźniki pulpitu. Każdy zespół danych jest liczony osobno — gdy źródło jest niedostępne
     * (brak tabeli, błąd), wartość to null, a ekran pokazuje „brak danych” zamiast zer.
     *
     * @return array{students:?int,licenses:?array,equipment:?array,reimbursements:?array,forms:?array,at:string}
     */
    public static function kpis(): array
    {
        $lic = self::guard('licencje', function () {
            require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php';
            return TiLicencjeManager::stats();
        });
        return [
            'students'       => self::guard('kursanci', fn() => self::activeStudents()),
            'licenses'       => $lic === null ? null : ['valid' => $lic['active'] + $lic['expiring'], 'expiring' => $lic['expiring'], 'expired' => $lic['expired'], 'revoked' => $lic['revoked']],
            'equipment'      => self::guard('sprzet', fn() => self::equipmentOut()),
            'reimbursements' => self::guard('zwroty', fn() => self::reimbursements()),
            'forms'          => $lic['forms'] ?? null,
            'at'             => date('d.m.Y H:i'),
        ];
    }

    private static function guard(string $what, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('analytics: wskaźnik „' . $what . '” niedostępny: ' . $e->getMessage());
            return null;
        }
    }

    private static function hasTable(string $name): bool
    {
        return (bool)db_one("SELECT 1 AS x FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]);
    }

    private static function activeStudents(): int
    {
        return (int)(db_one(
            "SELECT COUNT(DISTINCT e.client_id) AS n FROM k30_ti_enrollments e
               JOIN k30_ti_courses c ON c.id = e.course_id WHERE " . self::ACTIVE_ENROLLED
        )['n'] ?? 0);
    }

    /**
     * Sprzęt w terenie = otwarte wypożyczenia laptopów z puli (k30_pl_laptop_loans, bez zwrotu).
     * Osobno: wypożyczenia zapisane w rejestrze sprzętu karty kursanta — NIE są sumowane z pulą,
     * bo to ten sam sprzęt mógłby być wpisany w obu miejscach.
     *
     * @return array{pool:?int,registry:?int}
     */
    private static function equipmentOut(): array
    {
        $pool = self::hasTable('k30_pl_laptop_loans')
            ? (int)(db_one("SELECT COUNT(*) AS n FROM k30_pl_laptop_loans WHERE returned_at IS NULL")['n'] ?? 0) : null;
        $reg = self::hasTable('k30_ti_client_equipment')
            ? (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_client_equipment WHERE ownership = 'loan' AND COALESCE(returned_at, '') = ''")['n'] ?? 0) : null;
        return ['pool' => $pool, 'registry' => $reg];
    }

    /**
     * Wnioski o zwrot kosztów: otwarte (oczekuje → do wypłaty, bez zarchiwizowanych) z kwotą i rozbiciem na statusy.
     *
     * @return array{open:int,amount:float,by_status:array<string,array{label:string,n:int,amount:float}>}
     */
    private static function reimbursements(): array
    {
        if (!self::hasTable('zwroty_kosztow')) throw new \RuntimeException('brak tabeli zwroty_kosztow');
        require_once dirname(__DIR__, 3) . '/includes/zwroty_kosztow.php';
        $by = [];
        foreach (self::OPEN_REIMBURSEMENT as $st) $by[$st] = ['label' => FinanceManager::STATUSES[$st]['label'] ?? $st, 'n' => 0, 'amount' => 0.0];
        $ph = implode(',', array_fill(0, count(self::OPEN_REIMBURSEMENT), '?'));
        $open = 0; $sum = 0.0;
        foreach (db_all("SELECT status, COUNT(*) AS n, COALESCE(SUM(kwota), 0) AS s FROM zwroty_kosztow
                          WHERE status IN ($ph) AND archived_at IS NULL GROUP BY status", self::OPEN_REIMBURSEMENT) as $r) {
            $by[$r['status']]['n'] = (int)$r['n']; $by[$r['status']]['amount'] = round((float)$r['s'], 2);
            $open += (int)$r['n']; $sum += (float)$r['s'];
        }
        return ['open' => $open, 'amount' => round($sum, 2), 'by_status' => $by];
    }

    // ── Raporty: filtry i zestawy danych ─────────────────────────────────────

    public static function reportKeys(): array { return array_keys(self::REPORTS); }

    public static function reportLabel(string $key): string { return self::REPORTS[$key][0] ?? ''; }

    /**
     * Filtry raportu z GET/POST sprowadzone do znanych wartości.
     *   licencje: days = 14|30|60|90 (okno „wygasa w ciągu”), obciazenie: ym = RRRR-MM,
     *   rozliczenia: from, to = daty RRRR-MM-DD (domyślnie od początku roku do dziś, maks. ok. 3 lata).
     *
     * @return array<string,string|int>
     */
    public static function filters(string $key, array $src): array
    {
        if ($key === 'licencje') {
            $d = (int)($src['days'] ?? 30);
            return ['days' => in_array($d, [14, 30, 60, 90], true) ? $d : 30];
        }
        if ($key === 'obciazenie') {
            $ym = (string)($src['ym'] ?? '');
            return ['ym' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) ? $ym : date('Y-m')];
        }
        if ($key === 'rozliczenia') {
            $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false && date('Y-m-d', strtotime($v)) === $v;
            $from = $ok($src['from'] ?? null) ? (string)$src['from'] : date('Y-01-01');
            $to   = $ok($src['to'] ?? null)   ? (string)$src['to']   : date('Y-m-d');
            if ($from > $to) [$from, $to] = [$to, $from];
            if (strtotime($to) - strtotime($from) > 1100 * 86400) $from = date('Y-m-d', strtotime($to . ' -1100 days'));
            return ['from' => $from, 'to' => $to];
        }
        return [];
    }

    /** Zestaw danych raportu (null = nieznany raport). */
    public static function dataset(string $key, array $filters = []): ?array
    {
        $f = self::filters($key, $filters);
        return match ($key) {
            'bez_sprzetu' => self::studentsWithoutEquipment(),
            'licencje'    => self::expiringLicenses((int)$f['days']),
            'obciazenie'  => self::instructorLoad((string)$f['ym']),
            'rozliczenia' => self::licenseBilling((string)$f['from'], (string)$f['to']),
            default       => null,
        };
    }

    private static function pl(string $ymd): string { return $ymd === '' ? '' : date('d.m.Y', strtotime($ymd)); }

    /** Telefon do czytelnej postaci tekstowej (z odstępami — arkusz nie zamieni numeru na liczbę). */
    private static function phone(string $p): string
    {
        $p = trim($p);
        $d = preg_replace('/\D+/', '', $p);
        if (strlen($d) === 9) return implode(' ', str_split($d, 3));
        // Nawias zamiast plusa na początku: „+…” arkusz traktuje jak początek formuły (eksport CSV dopisałby apostrof).
        if (strlen($d) === 11 && str_starts_with($d, '48')) return '(+48) ' . implode(' ', str_split(substr($d, 2), 3));
        return preg_replace('/^\+(\d+)/', '(+$1)', $p);
    }

    private static function studentsWithoutEquipment(): array
    {
        $ph = implode(',', array_fill(0, count(self::NO_EQUIPMENT_TEXT), '?'));
        $params = self::NO_EQUIPMENT_TEXT;
        $sql = "SELECT k.id, k.name, k.email, k.phone, c.name AS course, COALESCE(NULLIF(u.name, ''), '') AS instructor,
                       COALESCE(NULLIF(e.start_date, ''), substr(e.created_at, 1, 10)) AS since
                  FROM k30_ti_enrollments e
                  JOIN k30_ti_courses c ON c.id = e.course_id
                  JOIN k30_clients k ON k.id = e.client_id
                  LEFT JOIN users u ON u.id = c.instructor_id
                 WHERE " . self::ACTIVE_ENROLLED . "
                   AND (TRIM(COALESCE(k.equipment, '')) = '' OR LOWER(TRIM(k.equipment)) IN ($ph))";
        if (self::hasTable('k30_pl_laptop_loans')) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM k30_pl_laptop_loans ll WHERE ll.client_id = k.id AND ll.returned_at IS NULL)";
        }
        if (self::hasTable('k30_ti_client_equipment')) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM k30_ti_client_equipment q WHERE q.client_id = k.id AND (q.ownership = 'own' OR COALESCE(q.returned_at, '') = ''))";
        }
        $sql .= " ORDER BY k.name COLLATE NOCASE, c.name COLLATE NOCASE";

        $by = [];
        foreach (db_all($sql, $params) as $r) {
            $id = (int)$r['id'];
            $by[$id] ??= ['name' => (string)$r['name'], 'email' => (string)$r['email'], 'phone' => (string)$r['phone'], 'courses' => [], 'instructors' => [], 'since' => ''];
            if ($r['course'] !== '' && !in_array($r['course'], $by[$id]['courses'], true)) $by[$id]['courses'][] = (string)$r['course'];
            if ($r['instructor'] !== '' && !in_array($r['instructor'], $by[$id]['instructors'], true)) $by[$id]['instructors'][] = (string)$r['instructor'];
            if ($r['since'] !== '' && ($by[$id]['since'] === '' || $r['since'] < $by[$id]['since'])) $by[$id]['since'] = (string)$r['since'];
        }
        $rows = [];
        foreach ($by as $c) {
            $rows[] = [$c['name'], $c['email'], self::phone($c['phone']), implode('; ', $c['courses']), implode('; ', $c['instructors']), self::pl($c['since'])];
        }
        return [
            'title'    => self::REPORTS['bez_sprzetu'][0],
            'subtitle' => 'Stan na ' . date('d.m.Y H:i') . ' · aktywni kursanci w aktywnych grupach · pozycji: ' . count($rows),
            'headers'  => ['Kursant', 'E-mail', 'Telefon', 'Grupy', 'Prowadzący', 'W grupie od'],
            'rows'     => $rows, 'num' => [], 'dec' => [], 'sheet' => 'Bez sprzętu',
        ];
    }

    private static function licenseRows(string $where, array $params, string $order): array
    {
        require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php';
        ti_licencje_migrate();
        return db_all(
            "SELECT l.id, k.name AS client, lic.name AS software, lic.vendor AS vendor, l.status, l.expires_at,
                    COALESCE(NULLIF(l.issued_at, ''), substr(l.assigned_at, 1, 10)) AS issued, l.billing_form, l.billing_ref
               FROM k30_ti_client_licenses l
               JOIN k30_ti_licenses lic ON lic.id = l.license_id
               JOIN k30_clients k ON k.id = l.client_id
              WHERE $where ORDER BY $order",
            $params
        );
    }

    private static function formLabel(string $form): string
    {
        return $form === '' ? '—' : $form . ' — ' . (TiLicencjeManager::BILLING_FORMS[$form]['label'] ?? $form);
    }

    private static function expiringLicenses(int $days): array
    {
        $today = date('Y-m-d');
        $to    = date('Y-m-d', strtotime("$today +$days days"));
        $rs = self::licenseRows("l.status <> 'revoked' AND l.expires_at IS NOT NULL AND l.expires_at <> '' AND l.expires_at >= ? AND l.expires_at <= ?",
                                [$today, $to], "l.expires_at, k.name COLLATE NOCASE, lic.name COLLATE NOCASE");
        $rows = [];
        foreach ($rs as $r) {
            $rows[] = [(string)$r['client'], (string)$r['software'], (string)$r['vendor'], self::pl((string)$r['expires_at']),
                       (int)round((strtotime((string)$r['expires_at']) - strtotime($today)) / 86400),
                       self::formLabel((string)$r['billing_form']), (string)$r['billing_ref']];
        }
        return [
            'title'    => self::REPORTS['licencje'][0],
            'subtitle' => 'Licencje ważne do ' . self::pl($to) . ' (dziś + ' . $days . ' dni), stan na ' . date('d.m.Y') . ' · pozycji: ' . count($rows),
            'headers'  => ['Kursant', 'Oprogramowanie', 'Producent', 'Ważna do', 'Dni do końca', 'Forma rozliczenia', 'Numer / kod'],
            'rows'     => $rows, 'num' => [4], 'dec' => [4 => 0], 'sheet' => 'Wygasające licencje',
        ];
    }

    private static function licenseBilling(string $from, string $to): array
    {
        $rs = self::licenseRows("COALESCE(NULLIF(l.issued_at, ''), substr(l.assigned_at, 1, 10)) BETWEEN ? AND ?", [$from, $to],
                                "issued, k.name COLLATE NOCASE, lic.name COLLATE NOCASE");
        $count = array_fill_keys([...array_keys(TiLicencjeManager::BILLING_FORMS), ''], 0);
        $revoked = 0;
        $rows = [];
        foreach ($rs as $r) {
            $status = TiLicencjeManager::statusOf($r);
            if ($status === 'revoked') $revoked++; else $count[(string)$r['billing_form']] = ($count[(string)$r['billing_form']] ?? 0) + 1;
            $rows[] = [self::pl((string)$r['issued']), self::formLabel((string)$r['billing_form']), (string)$r['billing_ref'], (string)$r['client'],
                       (string)$r['software'], self::pl((string)$r['expires_at']), TiLicencjeManager::STATUSES[$status] ?? $status];
        }
        $sum = [];
        foreach (TiLicencjeManager::BILLING_FORMS as $code => $meta) $sum[] = $code . ': ' . $count[$code];
        $sum[] = 'bez formy: ' . $count[''];
        return [
            'title'    => self::REPORTS['rozliczenia'][0],
            'subtitle' => 'Wystawione od ' . self::pl($from) . ' do ' . self::pl($to) . ' · ' . implode(' · ', $sum) . ' · unieważnione: ' . $revoked . ' (poza podsumowaniem) · pozycji: ' . count($rows),
            'headers'  => ['Wystawiona', 'Forma rozliczenia', 'Numer / kod', 'Kursant', 'Oprogramowanie', 'Ważna do', 'Status'],
            'rows'     => $rows, 'num' => [], 'dec' => [], 'sheet' => 'Rozliczenia licencji',
        ];
    }

    private static function instructorLoad(string $ym): array
    {
        require_once dirname(__DIR__, 2) . '/ti_scheduler/logic/schedulerReports.php';   // SC_REP_DUR, sc_limits() — te same definicje co w raportach planisty
        ti_sched_migrate();
        $by = [];
        foreach (db_all(
            "SELECT COALESCE(s.instructor_id, c.instructor_id) AS iid, COALESCE(NULLIF(u.name, ''), '(brak prowadzącego)') AS iname, s.course_id,
                    CASE WHEN s.status IN ('held','individual_change','remote_material') THEN 'held' ELSE 'planned' END AS kind, " . SC_REP_DUR . " AS dur
               FROM k30_ti_sessions s
               JOIN k30_ti_courses c ON c.id = s.course_id
               LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)
              WHERE strftime('%Y-%m', s.lesson_date) = ? AND s.draft_id IS NULL
                AND s.status IN ('held','individual_change','remote_material','planned','reserved')", [$ym]) as $r) {
            $iid = (int)$r['iid'];
            $by[$iid] ??= ['name' => (string)$r['iname'], 'groups' => [], 'held' => 0, 'planned' => 0, 'min' => 0];
            $by[$iid]['groups'][(int)$r['course_id']] = true;
            $by[$iid][$r['kind']]++;
            $by[$iid]['min'] += max(0, (int)$r['dur']);
        }
        uasort($by, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $rows = []; $lessons = 0; $minutes = 0;
        foreach ($by as $iid => $x) {
            $limit = $iid > 0 ? (int)(sc_limits($iid)['max_month_min'] ?? 0) : 0;
            $usage = $limit > 0 ? round($x['min'] / $limit * 100, 1) : null;
            $rows[] = [$x['name'], count($x['groups']), $x['held'], $x['planned'], round($x['min'] / 60, 1),
                       $limit > 0 ? round($limit / 60, 1) : null, $usage,
                       $limit <= 0 ? 'bez limitu' : ($x['min'] > $limit ? 'Ponad limit' : 'W limicie')];
            $lessons += $x['held'] + $x['planned']; $minutes += $x['min'];
        }
        return [
            'title'    => self::REPORTS['obciazenie'][0],
            'subtitle' => 'Miesiąc ' . $ym . ' · plan oficjalny (bez szkiców i odwołanych), zastępstwa liczone prowadzącemu lekcji · razem '
                          . $lessons . ' lekcji, ' . number_format($minutes / 60, 1, ',', '') . ' h · prowadzących: ' . count($rows),
            'headers'  => ['Prowadzący', 'Grupy', 'Lekcje odbyte', 'Lekcje zaplanowane', 'Godziny razem', 'Limit miesięczny (h)', 'Wykorzystanie limitu (%)', 'Limit'],
            'rows'     => $rows, 'num' => [1, 2, 3, 4, 5, 6], 'dec' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1, 6 => 1], 'sheet' => 'Obciążenie instruktorów',
        ];
    }

    // ── Audyt ────────────────────────────────────────────────────────────────

    /** Wpis audytu eksportu (lista zawiera dane osobowe). Bez treści wierszy — tylko raport, format, liczba pozycji i filtry. */
    public static function auditExport(int $userId, string $key, string $format, int $rowCount, array $filters): void
    {
        audit_log('analytics.export', ['report' => $key, 'format' => $format, 'rows' => $rowCount, 'filters' => $filters], $userId);
    }
}
