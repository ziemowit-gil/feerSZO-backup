<?php
/**
 * includes/ti_price_changes.php — planowane zmiany cen zajęć TI (grupowe/indywidualne).
 *
 * Cena kursu (k30_ti_courses.billing_amount) i indywidualny override
 * (k30_ti_enrollments.billing_amount/hourly_rate — kod 9999) to POJEDYNCZE,
 * bezhistoryczne wartości: zmiana nadpisuje je od razu, na zawsze, bez śladu
 * "dlaczego" i bez możliwości zaplanowania jej na przyszłość ani ograniczenia
 * w czasie. Ten moduł tego NIE zastępuje — dodaje WARSTWĘ ponad nim: zapisaną
 * zmianę (kwota albo procent, z zakresem dat i uzasadnieniem), która modyfikuje
 * wynik k30_ti_effective_billing() tylko dla okresów w zakresie dat, i wysyła
 * o tym e-mail do kursanta/opiekuna.
 *
 * Zakres: 'course' (cały kurs — wszyscy kursanci bez własnego override)
 *      albo 'client' (jeden kursant w tym kursie — nadpisuje 'course').
 */

function ti_price_changes_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_price_changes (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            scope         TEXT    NOT NULL,
            course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
            client_id     INTEGER REFERENCES k30_clients(id) ON DELETE CASCADE,
            change_type   TEXT    NOT NULL,
            change_value  REAL    NOT NULL,
            date_from     TEXT    NOT NULL,
            date_to       TEXT,
            reason        TEXT    NOT NULL DEFAULT '',
            email_subject TEXT    NOT NULL DEFAULT '',
            email_body    TEXT    NOT NULL DEFAULT '',
            status        TEXT    NOT NULL DEFAULT 'active',
            notified_at   TEXT,
            notified_count INTEGER NOT NULL DEFAULT 0,
            created_by    INTEGER,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_price_ch_course ON k30_ti_price_changes(course_id, status)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_price_ch_client ON k30_ti_price_changes(client_id, status)");
    } catch (\Throwable $e) {}
    // kind: '' = zmiana ceny, 'lesson' = korekta ceny pojedynczej lekcji (date_from = date_to = dzień lekcji)
    try { db()->exec("ALTER TABLE k30_ti_price_changes ADD COLUMN kind TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
}

const TI_PRICE_CHANGE_TYPES = ['amount' => 'Nowa kwota (zł)', 'percent' => 'Zmiana procentowa (%)'];

/** Etykieta czytelna dla listy/e-maila, np. "+10%" albo "120,00 zł". */
function ti_price_change_value_label(string $type, float $value): string {
    if ($type === 'percent') {
        return ($value > 0 ? '+' : '') . rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') . '%';
    }
    return number_format($value, 2, ',', ' ') . ' zł';
}

/**
 * Zmiana obowiązująca W DANYM DNIU (najpierw indywidualna, potem grupowa;
 * przy kilku — najpóźniej rozpoczęta). Podstawa naliczania: stawka godzinowa
 * wg daty lekcji, ryczałt wg stanu na 1. dzień miesiąca. Cache per żądanie.
 */
function &_ti_price_change_cache(): array { static $c = []; return $c; }

/** Czyści cache ti_price_change_effective_on() — po dodaniu/anulowaniu zmiany. */
function ti_price_change_cache_clear(): void { $c = &_ti_price_change_cache(); $c = []; }

function ti_price_change_effective_on(int $course_id, int $client_id, string $date): ?array {
    $cache = &_ti_price_change_cache();
    $key = $course_id . ':' . $client_id . ':' . $date;
    if (!array_key_exists($key, $cache)) {
        ti_price_changes_migrate();
        $cache[$key] = db_one(
            "SELECT * FROM k30_ti_price_changes
              WHERE status='active' AND course_id=?
                AND date_from <= ? AND (date_to IS NULL OR date_to = '' OR date_to >= ?)
                AND (client_id = ? OR client_id IS NULL)
              ORDER BY (client_id IS NOT NULL) DESC, date_from DESC, id DESC
              LIMIT 1",
            [$course_id, $date, $date, $client_id]
        ) ?: null;
    }
    return $cache[$key];
}

/** Wynik k30_ti_effective_billing() po zmianie ceny obowiązującej w dniu $date. */
function ti_price_eff_on(array $eff, int $course_id, int $client_id, string $date): array {
    return ti_price_change_apply_to_effective($eff, ti_price_change_effective_on($course_id, $client_id, $date));
}

/**
 * Czy lekcja jest online: metoda zdalna_zoom / zdalna_inne; stacjonarna — nie;
 * bez wybranej metody — tryb grupy (k30_ti_courses.is_online). Cache per żądanie.
 */
function ti_session_is_online(string $lesson_method, int $course_id): bool {
    if (in_array($lesson_method, ['zdalna_zoom', 'zdalna_inne'], true)) return true;
    if ($lesson_method === 'stacjonarna') return false;
    static $c = [];
    if (!array_key_exists($course_id, $c)) {
        try { $c[$course_id] = (bool)(db_one("SELECT is_online FROM k30_ti_courses WHERE id=?", [$course_id])['is_online'] ?? 0); }
        catch (\Throwable $e) { $c[$course_id] = false; }
    }
    return $c[$course_id];
}

/**
 * Stawka godzinowa JEDNEJ lekcji: stacjonarna (hourly_rate) albo online
 * (hourly_rate_online, gdy > 0), z nałożoną zmianą ceny z dnia lekcji.
 * Zmiana procentowa działa na obie stawki. Zmiana „nowa kwota” ustawia stawkę
 * stacjonarną, a online przelicza proporcjonalnie (zachowuje różnicę trybów);
 * korekta ceny lekcji (kind='lesson') ustawia wprost podaną kwotę.
 * @return array{rate:float, change_id:int}
 */
function ti_lesson_rate(array $eff, int $course_id, int $client_id, string $date, bool $online): array {
    $base    = (float)($eff['hourly_rate'] ?? 0);
    $on_rate = (float)($eff['hourly_rate_online'] ?? 0);
    $use_on  = $online && $on_rate > 0.005;
    $ch      = ti_price_change_effective_on($course_id, $client_id, $date);
    if (!$ch) return ['rate' => $use_on ? $on_rate : $base, 'change_id' => 0];
    $val = (float)$ch['change_value'];
    if ($ch['change_type'] === 'percent') {
        $r = round(($use_on ? $on_rate : $base) * (1 + $val / 100), 2);
    } elseif (!$use_on || ($ch['kind'] ?? '') === 'lesson') {
        $r = max(0, $val);
    } else {
        $r = $base > 0.005 ? round($on_rate * max(0, $val) / $base, 2) : max(0, $val);
    }
    return ['rate' => $r, 'change_id' => (int)$ch['id']];
}

/**
 * Rozliczenie godzinowe z lekcji o różnych datach: każda lekcja po stawce
 * obowiązującej w jej dniu. $lessons = [['date'=>'Y-m-d','hours'=>float], …].
 * Zwraca parts (stawka → godziny, kolejność chronologiczna), amount, hours,
 * rate (jedna stawka albo średnia ważona, gdy było ich kilka), rate_by_date
 * i price_change_ids.
 */
function ti_price_hourly_breakdown(array $eff, int $course_id, int $client_id, array $lessons): array {
    $parts = []; $by_date = []; $by_sess = []; $memo = []; $ids = []; $amount = 0.0; $hours = 0.0;
    foreach ($lessons as $l) {
        $d = (string)$l['date']; $h = (float)$l['hours'];
        // Tryb lekcji: 'online' w wierszu (kalkulator, raporty); brak = stacjonarna
        $on = !empty($l['online']);
        $k  = $d . ($on ? ':on' : ':st');
        if (!isset($memo[$k])) {
            $lr = ti_lesson_rate($eff, $course_id, $client_id, $d, $on);
            $memo[$k] = $lr['rate'];
            if ($lr['change_id']) $ids[$lr['change_id']] = true;
        }
        $r = $memo[$k];
        if (!isset($by_date[$d]) || !$on) $by_date[$d] = $r;   // dzień → stawka (stacjonarna ma pierwszeństwo)
        if (!empty($l['session_id'])) $by_sess[(int)$l['session_id']] = $r;
        $k = number_format($r, 2, '.', '');
        $parts[$k] = ($parts[$k] ?? 0.0) + $h;
        $amount += $h * $r;
        $hours  += $h;
    }
    $list = [];
    foreach ($parts as $k => $h) $list[] = ['rate' => (float)$k, 'hours' => $h, 'amount' => round($h * (float)$k, 2)];
    $rate = count($list) === 1 ? $list[0]['rate']
          : ($hours > 0 ? round($amount / $hours, 2) : (float)$eff['hourly_rate']);
    if (!$list) $rate = (float)ti_price_eff_on($eff, $course_id, $client_id, date('Y-m-d'))['hourly_rate'];
    return [
        'parts' => $list, 'amount' => round($amount, 2), 'hours' => $hours, 'rate' => $rate,
        'rate_by_date' => $by_date, 'rate_by_session' => $by_sess, 'price_change_ids' => array_keys($ids),
    ];
}

/** Nakłada dopasowaną zmianę na wynik k30_ti_effective_billing() — bez zmiany, gdy $change=null. */
function ti_price_change_apply_to_effective(array $eff, ?array $change): array {
    if (!$change) return $eff;
    $type = (string)$change['change_type'];
    $val  = (float)$change['change_value'];
    if (in_array((int)$eff['model'], [1, 3], true)) {
        $eff['amount'] = $type === 'percent' ? round($eff['amount'] * (1 + $val / 100), 2) : max(0, $val);
    } else {
        $eff['hourly_rate'] = $type === 'percent' ? round($eff['hourly_rate'] * (1 + $val / 100), 2) : max(0, $val);
    }
    $eff['price_change_id'] = (int)$change['id'];
    return $eff;
}

/**
 * Domyślny tekst e-maila — używany jako PUNKT WYJŚCIA w formularzu (JS,
 * przycisk "Wygeneruj/odśwież") i jako SIATKA BEZPIECZEŃSTWA tutaj, w
 * ti_price_change_create(): gdyby temat/treść dotarły puste (np. wywołanie
 * spoza tego formularza), kursant i tak dostanie sensowną wiadomość, nie
 * pustą kopertę z samą stopką.
 */
function ti_price_change_default_email(array $data, string $course_name): array {
    $org    = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
    $range  = date('d.m.Y', strtotime($data['date_from']));
    $range .= !empty($data['date_to']) ? ' – ' . date('d.m.Y', strtotime($data['date_to'])) : ' (bezterminowo)';
    $value_label = ti_price_change_value_label($data['change_type'], (float)$data['change_value']);

    $subject = "{$org}: zmiana ceny zajęć — {$course_name}";
    $body = "Dzień dobry,\n\n"
        . "informujemy o zmianie ceny zajęć „{$course_name}”, obowiązującej od {$range}.\n\n"
        . "Zmiana: {$value_label}\n\n"
        . (trim((string)$data['reason']) !== '' ? "Uzasadnienie: {$data['reason']}\n\n" : '')
        . "Dokładną cenę (przed i po zmianie) znajdziesz pod tą wiadomością. W razie pytań prosimy o kontakt "
        . "z prowadzącym albo z biurem placówki.\n\n"
        . "Pozdrawiamy,\n{$org}";
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Tworzy zaplanowaną zmianę ceny. $data: scope,course_id,client_id,change_type,
 * change_value,date_from,date_to,reason,email_subject,email_body,created_by.
 */
function ti_price_change_create(array $data): int {
    ti_price_changes_migrate();
    $subject = trim((string)($data['email_subject'] ?? ''));
    $body    = trim((string)($data['email_body'] ?? ''));
    if ($subject === '' || $body === '') {
        $course = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$data['course_id']]);
        $draft  = ti_price_change_default_email($data, (string)($course['name'] ?? 'zajęcia'));
        if ($subject === '') $subject = $draft['subject'];
        if ($body === '')    $body    = $draft['body'];
    }
    ti_price_change_cache_clear();
    return db_insert('k30_ti_price_changes', [
        'scope'         => $data['scope'],
        'course_id'     => (int)$data['course_id'],
        'client_id'     => $data['scope'] === 'client' ? (int)$data['client_id'] : null,
        'change_type'   => $data['change_type'],
        'change_value'  => (float)$data['change_value'],
        'date_from'     => $data['date_from'],
        'date_to'       => $data['date_to'] ?: null,
        'reason'        => trim((string)$data['reason']),
        'email_subject' => $subject,
        'email_body'    => $body,
        'created_by'    => $data['created_by'] ?? null,
        'kind'          => (string)($data['kind'] ?? ''),
    ]);
}

/**
 * Domyślna data „zerowania” stawek grupy: pierwszy dzień po ostatnim okresie, w którym wystawiono rozliczenie
 * (pierwszy dzień następnego miesiąca); gdy nic nie wystawiono — jutro.
 */
function ti_course_zero_default_date(int $course_id): string {
    $r = db_one("SELECT year, month FROM k30_ti_billing WHERE COALESCE(course_id,0)=CAST(? AS INTEGER) AND status IN ('issued','paid') ORDER BY year DESC, month DESC LIMIT 1", [$course_id]);
    if (!$r) return date('Y-m-d', strtotime('+1 day'));
    return date('Y-m-d', mktime(0, 0, 0, (int)$r['month'] + 1, 1, (int)$r['year']));
}

/**
 * „Zeruj stawki w grupie”: zmiana ceny grupy na 0 zł od podanej daty (zakres 'course', kwota 0, bez końca) —
 * obowiązuje lekcje od tego dnia, wcześniejsze zostają po starych stawkach. Kursanci z własną zmianą ceny
 * (zakres 'client') zachowują ją. Bez e-maila do kursantów. $rebill: przelicz wystawione, nieopłacone rozliczenia.
 * @return array{id:int, msg:string}|array{error:string}
 */
function ti_course_zero_rates(int $course_id, string $from, string $reason, int $by = 0, bool $rebill = false): array {
    ti_price_changes_migrate();
    if (!db_one("SELECT 1 FROM k30_ti_courses WHERE id=?", [$course_id])) return ['error' => 'Nie znaleziono grupy.'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return ['error' => 'Podaj datę w formacie RRRR-MM-DD.'];
    if (mb_strlen(trim($reason)) < 5) return ['error' => 'Podaj uzasadnienie (min. 5 znaków).'];
    if (db_one("SELECT 1 FROM k30_ti_price_changes WHERE course_id=? AND scope='course' AND status='active' AND change_type='amount' AND change_value=0 AND date_from=? AND COALESCE(date_to,'')=''", [$course_id, $from]))
        return ['error' => 'Zerowanie stawek od ' . $from . ' jest już zapisane dla tej grupy.'];
    $id = ti_price_change_create(['scope' => 'course', 'course_id' => $course_id, 'change_type' => 'amount', 'change_value' => 0,
        'date_from' => $from, 'date_to' => null, 'reason' => trim($reason), 'created_by' => $by ?: null]);
    $msg = 'Stawki w grupie wyzerowane od ' . $from . ' (zmiana ceny #' . $id . ').';
    if ($rebill) { $rb = ti_price_change_rebill($id); $msg .= ' Przeliczone rozliczenia: ' . count($rb['updated'] ?? []) . (!empty($rb['manual']) ? ', do ręcznej korekty: ' . count($rb['manual']) : '') . '.'; }
    if (function_exists('audit_log')) audit_log('pricing.zero_rates', ['course_id' => $course_id, 'price_change_id' => $id, 'from' => $from, 'rebill' => $rebill, 'reason' => trim($reason)], $by ?: null);
    return ['id' => $id, 'msg' => $msg];
}

/**
 * Korekta ceny lekcji (kierownik): stawka godzinowa na JEDEN dzień lekcji —
 * dla jednego kursanta albo całej grupy. Zapis jako zmiana ceny kind='lesson'
 * (ślad, uzasadnienie, anulowanie jak przy zmianach cen), bez e-maila; od razu
 * przelicza wystawione, nieopłacone rozliczenia. Tylko model godzinowy.
 * Uwaga: dwie lekcje tej grupy tego samego dnia dostaną tę samą stawkę.
 * @return array{id:int, msg:string}|array{error:string}
 */
function ti_lesson_price_correction(int $course_id, ?int $client_id, string $date, float $rate, string $reason, ?int $by = null): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['error' => 'Nieprawidłowa data lekcji.'];
    if ($rate < 0) return ['error' => 'Stawka nie może być ujemna.'];
    if (mb_strlen(trim($reason)) < 5) return ['error' => 'Podaj uzasadnienie korekty ceny lekcji.'];
    $id = ti_price_change_create([
        'scope' => $client_id ? 'client' : 'course', 'course_id' => $course_id, 'client_id' => $client_id,
        'change_type' => 'amount', 'change_value' => round($rate, 2), 'date_from' => $date, 'date_to' => $date,
        'reason' => trim($reason), 'email_subject' => '-', 'email_body' => '-', 'created_by' => $by, 'kind' => 'lesson',
    ]);
    $msg = 'Korekta ceny lekcji ' . date('d.m.Y', strtotime($date)) . ': ' . number_format($rate, 2, ',', ' ') . ' zł/h.';
    try { $msg .= ti_price_change_rebill_msg(ti_price_change_rebill($id)); }
    catch (\Throwable $e) { $msg .= ' Nie udało się przeliczyć wystawionych rozliczeń: ' . $e->getMessage(); }
    return ['id' => $id, 'msg' => $msg];
}

function ti_price_change_get(int $id): ?array {
    ti_price_changes_migrate();
    return db_one("SELECT * FROM k30_ti_price_changes WHERE id=?", [$id]);
}

function ti_price_changes_for_course(int $course_id): array {
    ti_price_changes_migrate();
    return db_all(
        "SELECT pc.*, cl.name AS client_name FROM k30_ti_price_changes pc
         LEFT JOIN k30_clients cl ON cl.id = pc.client_id
         WHERE pc.course_id=? ORDER BY pc.created_at DESC",
        [$course_id]
    );
}

function ti_price_change_cancel(int $id): void {
    ti_price_changes_migrate();
    db()->prepare("UPDATE k30_ti_price_changes SET status='cancelled' WHERE id=?")->execute([$id]);
    ti_price_change_cache_clear();
}

/** Odbiorcy powiadomienia: kursant(ci) objęci zmianą + opiekunowie małoletnich. */
function ti_price_change_recipients(array $change): array {
    if ($change['scope'] === 'client') {
        return db_all(
            "SELECT a.client_id, a.is_minor, a.guardian_email, cl.name, cl.email
             FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id=a.client_id
             WHERE a.client_id=? AND a.is_active=1",
            [(int)$change['client_id']]
        );
    }
    // Zasięg 'course': wszyscy aktywni kursanci kursu BEZ własnego override cenowego
    // (kto ma indywidualny model, dostanie osobne powiadomienie przy swojej zmianie).
    return db_all(
        "SELECT DISTINCT a.client_id, a.is_minor, a.guardian_email, cl.name, cl.email
         FROM k30_ti_enrollments e
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active' AND (e.billing_model IS NULL OR e.billing_model=0)",
        [(int)$change['course_id']]
    );
}

/**
 * Cena PRZED/PO dla jednego konkretnego kursanta objętego zmianą — liczona
 * tak samo jak w rozliczeniach (k30_ti_effective_billing() + ta zmiana),
 * więc jest dokładna nawet przy zasięgu 'course' + model godzinowy, gdzie
 * każdy zapis ma własną stawkę.
 */
function ti_price_change_amount_for_client(array $change, int $client_id): ?array {
    require_once __DIR__ . '/karty30.php';
    $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [(int)$change['course_id']]);
    $enr    = db_one("SELECT * FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [(int)$change['course_id'], $client_id]);
    if (!$course || !$enr) return null;

    $before_eff = k30_ti_effective_billing($enr, $course);
    $after_eff  = ti_price_change_apply_to_effective($before_eff, $change);
    $is_hourly  = !in_array((int)$before_eff['model'], [1, 3], true);
    return [
        'before' => $is_hourly ? (float)$before_eff['hourly_rate'] : (float)$before_eff['amount'],
        'after'  => $is_hourly ? (float)$after_eff['hourly_rate']  : (float)$after_eff['amount'],
        'unit'   => $is_hourly ? '/h' : '',
    ];
}

/** "+12,00 zł (+10%)" / "-5,00 zł (-4,2%)" — bez procentu, gdy $before=0 (nie da się policzyć). */
function ti_price_change_diff_label(float $before, float $after, string $unit = ''): string {
    $diff  = $after - $before;
    $label = ($diff >= 0 ? '+' : '') . number_format($diff, 2, ',', ' ') . ' zł' . $unit;
    if (abs($before) > 0.0001) {
        $pct = $diff / $before * 100;
        $label .= ' (' . ($pct >= 0 ? '+' : '') . rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',') . '%)';
    }
    return $label;
}

/**
 * Wysyła e-mail do objętych kursantów/opiekunów. Treść zapisana przy
 * tworzeniu zmiany to "wstęp" (powitanie/uzasadnienie/podpis) — do niego
 * system ZAWSZE dokleja dokładną, przeliczoną PER KURSANT kwotę przed/po,
 * więc liczba w mailu jest poprawna nawet gdy różni się osoba od osoby
 * (zasięg 'course' na modelu godzinowym).
 */
function ti_price_change_notify(int $id): int {
    ti_price_changes_migrate();
    $change = ti_price_change_get($id);
    if (!$change || $change['status'] !== 'active') return 0;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';

    $subject = $change['email_subject'] !== '' ? $change['email_subject'] : 'Zmiana ceny zajęć';
    $body_html = nl2br(htmlspecialchars((string)$change['email_body'], ENT_QUOTES));
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    $sent = 0;
    foreach (ti_price_change_recipients($change) as $s) {
        $amt = ti_price_change_amount_for_client($change, (int)$s['client_id']);
        $price_html = '';
        if ($amt) {
            $before = number_format($amt['before'], 2, ',', ' ') . ' zł' . $amt['unit'];
            $after  = number_format($amt['after'],  2, ',', ' ') . ' zł' . $amt['unit'];
            // Bez escapowania — diff_label() produkuje wyłącznie cyfry/znaki +/-/%/zł, żadnych danych z zewnątrz.
            $diff = ti_price_change_diff_label($amt['before'], $amt['after'], $amt['unit']);
            $price_html = "<p><strong>Dotychczasowa cena:</strong> {$before}<br>"
                        . "<strong>Nowa cena:</strong> {$after}<br>"
                        . "<strong>Zmiana:</strong> {$diff}</p>";
        }
        $html = "<div>{$body_html}</div>{$price_html}"
              . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";

        $emails = [];
        $primary = trim((string)($s['email'] ?? ''));
        if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$s['name'];
        $gemail = trim((string)($s['guardian_email'] ?? ''));
        if (!empty($s['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$s['name'];
        foreach ($emails as $addr => $nm) {
            try { mail_queue_add($addr, $nm, $subject, $html, '', 'ti_price_change', $id, '', false); $sent++; }
            catch (\Throwable $e) {}
        }
    }
    db()->prepare("UPDATE k30_ti_price_changes SET notified_at=datetime('now'), notified_count=notified_count+? WHERE id=?")
        ->execute([$sent, $id]);
    return $sent;
}

/**
 * Po dodaniu/anulowaniu zmiany ceny przelicza JUŻ WYSTAWIONE rozliczenia z jej
 * zakresu dat (bez tego zmiana wstecz działała dopiero po ręcznym ponownym
 * wystawieniu). Przeliczane są tylko rozliczenia nieopłacone i bez faktury —
 * opłacone/zafakturowane wracają w 'manual' do ręcznej korekty, bo zmiana
 * kwoty rozjechałaby się z dokumentem księgowym i przypisanymi wpłatami.
 * Na końcu ponowna alokacja wpłat (ti_billing_recompute) dla dotkniętych kursantów.
 *
 * @return array{updated: list<array>, manual: list<array>}
 */
function ti_price_change_rebill(int $change_id): array {
    require_once __DIR__ . '/karty30.php';
    require_once __DIR__ . '/ti_payments.php';
    $ch = ti_price_change_get($change_id);
    $out = ['updated' => [], 'manual' => []];
    if (!$ch) return $out;

    $ym_from = substr((string)$ch['date_from'], 0, 7);
    $ym_to   = !empty($ch['date_to']) ? substr((string)$ch['date_to'], 0, 7) : date('Y-m');
    $clients = $ch['scope'] === 'client'
        ? [(int)$ch['client_id']]
        : array_map('intval', array_column(db_all("SELECT DISTINCT client_id FROM k30_ti_enrollments WHERE course_id=?", [(int)$ch['course_id']]), 'client_id'));
    if (!$clients) return $out;

    $ph   = implode(',', array_fill(0, count($clients), '?'));
    $rows = db_all(
        "SELECT b.*, cl.name AS client_name FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
          WHERE b.client_id IN ($ph) AND COALESCE(b.course_id,0) IN (0, CAST(? AS INTEGER))
            AND printf('%04d-%02d', b.year, b.month) BETWEEN ? AND ?
            AND b.status != 'cancelled'
          ORDER BY b.year, b.month, b.client_id",
        array_merge($clients, [(int)$ch['course_id'], $ym_from, $ym_to])
    );
    $touched = [];
    foreach ($rows as $b) {
        $label = sprintf('%s %02d/%d', $b['client_name'], (int)$b['month'], (int)$b['year']);
        if (isset($b['manual_amount']) && $b['manual_amount'] !== null) continue;   // kwota ręczna — nie ruszamy
        $calc  = k30_ti_calculate_billing((int)$b['client_id'], (int)$b['month'], (int)$b['year'], (int)$b['course_id']);
        $new   = round((float)$calc['amount'], 2);
        $old   = round((float)$b['amount'], 2);
        if (abs($new - $old) < 0.005) continue;
        $invoiced = trim((string)($b['invoice_no'] ?? '')) !== '' || trim((string)($b['invoice_path'] ?? '')) !== '';
        if ($b['status'] === 'paid' || $invoiced || (float)($b['paid_amount'] ?? 0) > 0.005) {
            $out['manual'][] = ['billing_id' => (int)$b['id'], 'label' => $label, 'old' => $old, 'new' => $new,
                                'why' => $invoiced ? 'wystawiona faktura' : 'rozliczenie (częściowo) opłacone'];
            continue;
        }
        k30_ti_issue_billing((int)$b['client_id'], (int)$b['month'], (int)$b['year'], (string)($b['notes'] ?? ''), (int)$b['course_id']);
        $out['updated'][] = ['billing_id' => (int)$b['id'], 'label' => $label, 'old' => $old, 'new' => $new];
        $touched[(int)$b['client_id']] = true;
    }
    foreach (array_keys($touched) as $cid) ti_billing_recompute($cid);
    return $out;
}

/**
 * „Przelicz ceny” dla miesiąca: wszystkie wystawione rozliczenia okresu (opcjonalnie
 * tylko kursanci grupy $course_id) przeliczone po aktualnych cenach i lekcjach —
 * te same zasady co ti_price_change_rebill(): nieopłacone i bez faktury są
 * przeliczane (korekta, uwagi i tryb dokumentu zostają), opłacone/zafakturowane
 * trafiają do 'manual'. $dry = tylko podgląd, bez zapisu.
 * @return array{updated: list<array>, manual: list<array>}
 */
function ti_price_reprice_month(int $month, int $year, int $course_id = 0, bool $dry = false): array {
    require_once __DIR__ . '/karty30.php';
    require_once __DIR__ . '/ti_payments.php';
    $rows = db_all(
        "SELECT b.*, cl.name AS client_name FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
          WHERE b.month=? AND b.year=? AND b.status IN ('issued','paid')"
        . ($course_id ? " AND b.client_id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=?)" : '') . "
          ORDER BY cl.name, b.course_id",
        $course_id ? [$month, $year, $course_id] : [$month, $year]
    );
    return ti_price_reprice_rows($rows, $dry);
}

/** „Przelicz ceny” dla wszystkich rozliczeń kursanta (wszystkie okresy); $only_id = tylko jedno. */
function ti_price_reprice_client(int $client_id, bool $dry = false, int $only_id = 0): array {
    require_once __DIR__ . '/karty30.php';
    require_once __DIR__ . '/ti_payments.php';
    $rows = db_all(
        "SELECT b.*, cl.name AS client_name FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
          WHERE b.client_id=? AND b.status IN ('issued','paid')" . ($only_id ? " AND b.id=?" : '') . "
          ORDER BY b.year, b.month, b.course_id",
        $only_id ? [$client_id, $only_id] : [$client_id]
    );
    return ti_price_reprice_rows($rows, $dry);
}

/**
 * Wspólny rdzeń „Przelicz ceny”: dla każdego rozliczenia kwota z kalkulatora
 * (aktualne ceny i lekcje). Kwoty ręczne pomijane; opłacone/zafakturowane → manual.
 * @return array{updated: list<array>, manual: list<array>}
 */
function ti_price_reprice_rows(array $rows, bool $dry): array {
    $out = ['updated' => [], 'manual' => []];
    $touched = [];
    foreach ($rows as $b) {
        if (isset($b['manual_amount']) && $b['manual_amount'] !== null) continue;   // kwota ręczna (indywidualne)
        $month = (int)$b['month']; $year = (int)$b['year'];
        $calc  = k30_ti_calculate_billing((int)$b['client_id'], $month, $year, (int)$b['course_id']);
        $new   = round((float)$calc['amount'], 2);
        $old   = round((float)$b['amount'], 2);
        $newh  = round((float)$calc['hours_billed'], 4);
        if (abs($new - $old) < 0.005 && abs($newh - (float)$b['hours_billed']) < 0.0001) continue;
        $label = sprintf('%s %02d/%d', $b['client_name'], $month, $year);
        $invoiced = trim((string)($b['invoice_no'] ?? '')) !== '' || trim((string)($b['invoice_path'] ?? '')) !== '';
        if (!$invoiced) {
            try { $invoiced = (bool)db_one("SELECT 1 FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [(int)$b['id']]); }
            catch (\Throwable $e) {}
        }
        if ($b['status'] === 'paid' || $invoiced || (float)($b['paid_amount'] ?? 0) > 0.005) {
            $out['manual'][] = ['billing_id' => (int)$b['id'], 'label' => $label, 'old' => $old, 'new' => $new,
                                'why' => $invoiced ? 'wystawiona faktura' : 'rozliczenie (częściowo) opłacone'];
            continue;
        }
        if (!$dry) {
            k30_ti_issue_billing((int)$b['client_id'], $month, $year, (string)($b['notes'] ?? ''), (int)$b['course_id']);
            $touched[(int)$b['client_id']] = true;
        }
        $out['updated'][] = ['billing_id' => (int)$b['id'], 'label' => $label, 'old' => $old, 'new' => $new];
    }
    foreach (array_keys($touched) as $cid) ti_billing_recompute($cid);
    return $out;
}

/** Krótki komunikat z wyniku ti_price_change_rebill() dla flash. */
function ti_price_change_rebill_msg(array $r): string {
    $f = fn($x) => $x['label'] . ': ' . number_format($x['old'], 2, ',', ' ') . ' → ' . number_format($x['new'], 2, ',', ' ') . ' zł';
    $msg = '';
    if ($r['updated']) $msg .= ' Przeliczono wystawione rozliczenia (' . count($r['updated']) . '): ' . implode('; ', array_map($f, array_slice($r['updated'], 0, 5))) . '.';
    if ($r['manual'])  $msg .= ' Do ręcznej korekty (' . count($r['manual']) . '): '
        . implode('; ', array_map(fn($x) => $f($x) . ' — ' . $x['why'], array_slice($r['manual'], 0, 5))) . '.';
    return $msg;
}

/**
 * Wyciąg zmian cen (raport/wydruk PDF i XLS, CLI `table`). Filtry:
 * status: 'active' | 'all'; course_id; from/to — zmiany, których zakres dat
 * nachodzi na okres (puste = bez ograniczenia). Wiersz ma pola wyliczone:
 * state (zaplanowana/obowiązuje/zakończona/—), value_label, scope_label, author.
 */
function ti_price_changes_report(array $f = []): array {
    ti_price_changes_migrate();
    $w = []; $p = [];
    if (($f['status'] ?? 'all') === 'active') $w[] = "pc.status='active'";
    if (!empty($f['course_id'])) { $w[] = 'pc.course_id=CAST(? AS INTEGER)'; $p[] = (int)$f['course_id']; }
    if (!empty($f['to']))   { $w[] = 'pc.date_from <= ?'; $p[] = (string)$f['to']; }
    if (!empty($f['from'])) { $w[] = "(pc.date_to IS NULL OR pc.date_to = '' OR pc.date_to >= ?)"; $p[] = (string)$f['from']; }
    $rows = db_all(
        "SELECT pc.*, c.name AS course_name, cl.name AS client_name, u.name AS author
           FROM k30_ti_price_changes pc
           JOIN k30_ti_courses c ON c.id=pc.course_id
           LEFT JOIN k30_clients cl ON cl.id=pc.client_id
           LEFT JOIN users u ON u.id=pc.created_by
         " . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . "
          ORDER BY pc.date_from DESC, pc.id DESC", $p
    );
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['state'] = $r['status'] !== 'active' ? '—'
            : ($r['date_from'] > $today ? 'zaplanowana'
            : (!empty($r['date_to']) && $r['date_to'] < $today ? 'zakończona' : 'obowiązuje'));
        $r['status_label'] = $r['status'] === 'active' ? 'aktywna' : 'anulowana';
        $r['scope_label']  = ($r['scope'] === 'client' ? 'indywidualna' : 'grupa') . (($r['kind'] ?? '') === 'lesson' ? ' · korekta lekcji' : '');
        $r['value_label']  = ti_price_change_value_label((string)$r['change_type'], (float)$r['change_value']);
        $r['author']       = (string)($r['author'] ?? '');
    }
    unset($r);
    return $rows;
}
