<?php
/**
 * includes/ti_leaves.php — Urlopy / nieobecności prowadzących TI.
 *
 * Modularny rejestr zakresów dat niedostępności kadry (urlop, chorobowe, inne),
 * z helperami do dashboardu (trwające / nadchodzące) oraz sprawdzania kolizji
 * przy planowaniu lekcji.
 */

/** Rodzaje nieobecności (kod => etykieta). */
const TI_LEAVE_TYPES = [
    'urlop'      => 'Urlop',
    'chorobowe'  => 'Chorobowe (L4)',
    'okolicznosc'=> 'Okolicznościowy',
    'inne'       => 'Inne',
];

function ti_leave_type_label(string $code): string {
    return TI_LEAVE_TYPES[$code] ?? $code;
}

function ti_leaves_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('ti_leaves_migrate', __FILE__)) return;   // raz na wersję pliku (includes/db.php)
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_leaves (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date_from     DATE    NOT NULL,
        date_to       DATE    NOT NULL,
        type          TEXT    NOT NULL DEFAULT 'urlop',
        note          TEXT    NOT NULL DEFAULT '',
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_leaves_instr ON k30_ti_instructor_leaves(instructor_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_leaves_dates ON k30_ti_instructor_leaves(date_from, date_to)");
    // Śledzenie powiadomień kursantów (dokładane bezpiecznie do istniejących baz)
    foreach ([
        "ALTER TABLE k30_ti_instructor_leaves ADD COLUMN notified_at   DATETIME",
        "ALTER TABLE k30_ti_instructor_leaves ADD COLUMN notified_hash TEXT NOT NULL DEFAULT ''",
        // Nieobecność godzinowa: puste = cały dzień; w przeciwnym razie okno HH:MM–HH:MM w każdym dniu zakresu
        "ALTER TABLE k30_ti_instructor_leaves ADD COLUMN time_from TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_instructor_leaves ADD COLUMN time_to   TEXT NOT NULL DEFAULT ''",
    ] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
}

/** Pełna lista urlopów (z nazwą prowadzącego). */
function ti_leaves_all(): array {
    ti_leaves_migrate();
    return db_all(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         ORDER BY l.date_from DESC, l.id DESC"
    );
}

function ti_leave_get(int $id): ?array {
    ti_leaves_migrate();
    return db_one("SELECT * FROM k30_ti_instructor_leaves WHERE id=?", [$id]) ?: null;
}

/** Urlopy trwające w danym dniu (domyślnie dziś). */
function ti_leaves_current(string $day = ''): array {
    ti_leaves_migrate();
    $day = $day !== '' ? $day : date('Y-m-d');
    return db_all(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         WHERE l.date_from <= ? AND l.date_to >= ?
         ORDER BY l.date_to ASC, instructor_name",
        [$day, $day]
    );
}

/** Urlopy nadchodzące w najbliższych N dniach (zaczynające się po dziś). */
function ti_leaves_upcoming(int $days = 30): array {
    ti_leaves_migrate();
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+{$days} days"));
    return db_all(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         WHERE l.date_from > ? AND l.date_from <= ?
         ORDER BY l.date_from ASC, instructor_name",
        [$today, $until]
    );
}

/** Czy nieobecność jest godzinowa (okno w ciągu dnia), a nie całodniowa? */
function ti_leave_is_hourly(array $l): bool {
    return trim((string)($l['time_from'] ?? '')) !== '' && trim((string)($l['time_to'] ?? '')) !== '';
}

/** Etykieta godzin nieobecności: „10:00–12:00” albo „cały dzień”. */
function ti_leave_hours_label(array $l): string {
    return ti_leave_is_hourly($l)
        ? substr((string)$l['time_from'], 0, 5) . '–' . substr((string)$l['time_to'], 0, 5)
        : 'cały dzień';
}

/** Czytelny zakres dat (+ godziny dla nieobecności godzinowej), np. „12.09.2026, 10:00–12:00”. */
function ti_leave_when_label(array $l): string {
    $r = date('d.m.Y', strtotime((string)$l['date_from']));
    if ((string)$l['date_to'] !== (string)$l['date_from']) $r .= ' – ' . date('d.m.Y', strtotime((string)$l['date_to']));
    return ti_leave_is_hourly($l) ? $r . ', ' . ti_leave_hours_label($l) : $r;
}

/** HH:MM → minuty (niezależne od karty30.php, żeby moduł działał samodzielnie). */
function _ti_leave_min(string $hm): int {
    return (int)substr($hm, 0, 2) * 60 + (int)substr($hm, 3, 2);
}

/**
 * Czy nieobecność $l obejmuje podany dzień i (gdy podano godziny) nakłada się na przedział $tf–$tt.
 * Nieobecność całodniowa obejmuje każdy przedział; godzinowa — tylko nakładający się. Bez godzin
 * w zapytaniu ($tf === '') godzinowa NIE liczy się jako nieobecność całego dnia.
 */
function ti_leave_covers(array $l, string $day, string $tf = '', string $tt = ''): bool {
    if ($day < (string)$l['date_from'] || $day > (string)$l['date_to']) return false;
    if (!ti_leave_is_hourly($l)) return true;
    if ($tf === '') return false;
    $a1 = _ti_leave_min((string)$l['time_from']); $a2 = _ti_leave_min((string)$l['time_to']);
    $b1 = _ti_leave_min($tf);                     $b2 = $tt !== '' ? _ti_leave_min($tt) : $b1 + 1;
    return $b1 < $a2 && $b2 > $a1;
}

/**
 * Czy prowadzący jest nieobecny w danym dniu (i opcjonalnie godzinach)? Zwraca wiersz urlopu lub null.
 * Przydatne do ostrzeżeń przy planowaniu lekcji. Z godzinami $tf/$tt uwzględnia nieobecności godzinowe.
 */
function ti_instructor_on_leave(int $instructor_id, string $day, string $tf = '', string $tt = ''): ?array {
    ti_leaves_migrate();
    if (!$instructor_id || $day === '') return null;
    $rows = db_all(
        "SELECT * FROM k30_ti_instructor_leaves
         WHERE instructor_id=? AND date_from <= ? AND date_to >= ?
         ORDER BY date_to DESC",
        [$instructor_id, $day, $day]
    );
    foreach ($rows as $l) if (ti_leave_covers($l, $day, $tf, $tt)) return $l;
    return null;
}

/**
 * Urlopy prowadzących, którzy uczą danego kursanta (aktywne zapisy) —
 * trwające dziś + nadchodzące w ciągu N dni. Do bannera w panelu kursanta.
 * Zwraca też listę nazw kursów, na które wpływa nieobecność (course_names).
 */
function ti_leaves_for_client(int $client_id, int $days = 30): array {
    ti_leaves_migrate();
    if (!$client_id) return [];
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+{$days} days"));
    return db_all(
        "SELECT l.id, l.instructor_id, l.date_from, l.date_to, l.time_from, l.time_to, l.type, l.note,
                COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name,
                GROUP_CONCAT(DISTINCT c.name) AS course_names
         FROM k30_ti_instructor_leaves l
         JOIN users u ON u.id=l.instructor_id
         JOIN k30_ti_courses c ON c.instructor_id=l.instructor_id
         JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
         WHERE l.date_to >= ? AND l.date_from <= ?
         GROUP BY l.id
         ORDER BY l.date_from ASC, instructor_name",
        [$client_id, $today, $until]
    );
}

/**
 * Własne nieobecności prowadzącego: trwające dziś + nadchodzące w ciągu N dni.
 * Do komunikatu (bannera) w panelu dydaktyka — przypomnienie o zaplanowanym urlopie.
 */
function ti_leaves_for_instructor(int $instructor_id, int $days = 60): array {
    ti_leaves_migrate();
    if (!$instructor_id) return [];
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+{$days} days"));
    return db_all(
        "SELECT * FROM k30_ti_instructor_leaves
         WHERE instructor_id=? AND date_to >= ? AND date_from <= ?
         ORDER BY date_from ASC",
        [$instructor_id, $today, $until]
    );
}

/** Aktywni kursanci uczeni przez danego prowadzącego (adresaci powiadomień o urlopie). */
function ti_leave_affected_students(int $instructor_id): array {
    ti_leaves_migrate();
    if (!$instructor_id) return [];
    return db_all(
        "SELECT DISTINCT a.id, a.is_minor, a.guardian_email, cl.name, cl.email
         FROM k30_ti_courses c
         JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.status='active'
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE c.instructor_id=?",
        [$instructor_id]
    );
}

/**
 * Lekcje prowadzącego (zaplanowane / zarezerwowane, jeszcze nieodbyte), które kolidują z nieobecnością:
 * w zakresie dat urlopu, a dla nieobecności godzinowej — tylko nakładające się na okno godzin.
 * Prowadzący lekcji = s.instructor_id (zastępstwo) albo prowadzący kursu. Od dziś wzwyż.
 */
function ti_leave_conflicts(array $l): array {
    ti_leaves_migrate();
    $iid  = (int)($l['instructor_id'] ?? 0);
    $from = max((string)($l['date_from'] ?? ''), date('Y-m-d'));
    $to   = (string)($l['date_to'] ?? '');
    if (!$iid || $from === '' || $to === '' || $to < $from) return [];
    $rows = db_all(
        "SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, s.status, c.name AS course_name
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
          WHERE COALESCE(s.instructor_id,c.instructor_id)=CAST(? AS INTEGER)
            AND s.lesson_date BETWEEN ? AND ?
            AND s.draft_id IS NULL AND s.status IN ('planned','reserved')
          ORDER BY s.lesson_date, s.time_from",
        [$iid, $from, $to]
    );
    return array_values(array_filter($rows, fn($r) => ti_leave_covers($l, (string)$r['lesson_date'], (string)$r['time_from'], (string)$r['time_to'])));
}

/** Krótka data lekcji do SMS: „12.09 10:00”. */
function _ti_leave_short_when(array $s): string {
    $w = date('d.m', strtotime((string)$s['lesson_date']));
    if (trim((string)($s['time_from'] ?? '')) !== '') $w .= ' ' . substr((string)$s['time_from'], 0, 5);
    return $w;
}

/** Lista terminów do SMS: do 3 terminów, reszta jako „+N”. */
function _ti_leave_whens(array $sessions): string {
    $w = array_map('_ti_leave_short_when', $sessions);
    $out = implode(', ', array_slice($w, 0, 3));
    return count($w) > 3 ? $out . ' +' . (count($w) - 3) : $out;
}

/** SMS z szablonu (kind = klucz szablonu) do uczestników kursu; zwraca liczbę wysłanych. Szablon wyłączony → 0. */
function ti_leave_sms_course(int $course_id, string $tpl_key, array $vars): int {
    if (!function_exists('ti_lesson_sms_notify')) return 0;
    if (!function_exists('sms_tpl_render')) require_once __DIR__ . '/sms_templates.php';
    $t = sms_tpl_render($tpl_key, $vars);
    if (empty($t['enabled']) || trim((string)$t['message']) === '') return 0;
    // Własna treść szablonu może nie mieć {{contact}} — prośbę o kontakt dopisujemy wtedy na końcu
    if (!empty($vars['contact']) && function_exists('sms_tpl_get') && strpos((string)(sms_tpl_get($tpl_key)['message'] ?? ''), '{{contact}}') === false) {
        $t['message'] = rtrim((string)$t['message']) . $vars['contact'];
    }
    // Rodzaj SMS musi być literałem z katalogu (cli/smsKindsLint.php) — stąd switch zamiast zmiennej
    try {
        switch ($tpl_key) {
            case 'ti_absence_notice':    return ti_lesson_sms_notify($course_id, (string)$t['message'], 'ti_absence_notice');
            case 'ti_absence_cancelled': return ti_lesson_sms_notify($course_id, (string)$t['message'], 'ti_absence_cancelled');
            case 'ti_substitute_notify': return ti_lesson_sms_notify($course_id, (string)$t['message'], 'ti_substitute_notify');
        }
    } catch (\Throwable $e) { /* błąd SMS nie blokuje odwołania/zastępstwa */ }
    return 0;
}

/** Aktywni uczestnicy kursu → adresy e-mail (kursant + opiekun małoletniego), bez duplikatów. */
function _ti_leave_course_emails(int $course_id): array {
    if (!function_exists('_k30_ti_client_emails')) require_once __DIR__ . '/ti_reschedule.php';
    $out = [];
    foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]) as $e) {
        foreach (_k30_ti_client_emails((int)$e['client_id']) as $addr => $nm) $out[$addr] = $nm;
    }
    return $out;
}

/**
 * Odwołuje lekcje z powodu nieobecności prowadzącego i powiadamia uczestników: e-mail (kursant + opiekun)
 * oraz SMS z szablonu ti_absence_cancelled (tylko ci, którzy włączyli SMS o lekcjach). Powiadomienia są
 * zbiorcze per kurs — kursant z kilkoma odwołanymi lekcjami dostaje jeden e-mail i jeden SMS.
 * $ask_contact = prośba do uczestników o kontakt w sprawie ustalenia nowego terminu (e-mail + SMS).
 * Lekcje już odbyte / odwołane są pomijane. Pozostali prowadzący lekcji dostają e-mail (bez $exclude_uid).
 * @return array{cancelled:int, emails:int, sms:int, skipped:int}
 */
function ti_absence_cancel_sessions(array $session_ids, string $reason, string $by_label, int $exclude_uid = 0, bool $email = true, bool $sms = true, bool $ask_contact = false): array {
    $res = ['cancelled' => 0, 'emails' => 0, 'sms' => 0, 'skipped' => 0];
    $reason = trim($reason) !== '' ? trim($reason) : 'Nieobecność prowadzącego';
    $by_course = []; $done = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $session_ids)))) as $sid) {
        $s = db_one("SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, s.status, c.name AS course_name
                       FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$sid]);
        if (!$s || !in_array((string)$s['status'], ['planned', 'reserved'], true)) { $res['skipped']++; continue; }
        k30_ti_cancel_session($sid, $reason, 'doradca', $by_label, ['sms' => false]);
        $res['cancelled']++; $done[] = $sid;
        $by_course[(int)$s['course_id']]['name'] = (string)$s['course_name'];
        $by_course[(int)$s['course_id']]['sessions'][] = $s;
    }
    if (!$done) return $res;
    k30_ti_notify_instructors_cancelled($done, $reason, $exclude_uid);

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    $org = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
    $msg_url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=wiadomosci';
    foreach ($by_course as $cid => $c) {
        $crs = htmlspecialchars($c['name'], ENT_QUOTES);
        if ($email && function_exists('mail_queue_add')) {
            $items = array_map(fn($x) => htmlspecialchars(date('d.m.Y', strtotime((string)$x['lesson_date']))
                . (trim((string)$x['time_from']) !== '' ? ', ' . substr((string)$x['time_from'], 0, 5) . '–' . substr((string)$x['time_to'], 0, 5) : ''), ENT_QUOTES), $c['sessions']);
            $html = '<p>Dzień dobry,</p><p>Z powodu nieobecności prowadzącego odwołano ' . (count($items) === 1 ? 'zajęcia' : 'zajęcia w terminach') . ' <strong>' . $crs . '</strong>:</p>'
                  . '<ul><li>' . implode('</li><li>', $items) . '</li></ul>'
                  . '<p style="color:#555">Powód: ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
                  . '<p>Odwołane zajęcia nie są wliczane do rozliczenia. '
                  . ($ask_contact
                      ? '<strong>Prosimy o kontakt w celu ustalenia nowego terminu</strong> — napisz do nas w panelu kursanta (<a href="' . htmlspecialchars($msg_url, ENT_QUOTES) . '">zakładka Wiadomości</a>) lub odpowiedz na tę wiadomość.</p>'
                      : 'O nowym terminie poinformujemy osobno.</p>')
                  . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Otwórz panel kursanta</a></p>'
                  . '<p style="color:#888;font-size:12px">Wiadomość automatyczna z systemu ' . htmlspecialchars($org, ENT_QUOTES) . '.</p>';
            foreach (_ti_leave_course_emails($cid) as $addr => $nm) {
                try { mail_queue_add($addr, (string)$nm, "{$org}: odwołane zajęcia — " . $c['name'], $html, '', 'ti_absence_cancelled', (int)$c['sessions'][0]['id'], '', false); $res['emails']++; }
                catch (\Throwable $e) {}
            }
        }
        if ($sms) $res['sms'] += ti_leave_sms_course($cid, 'ti_absence_cancelled', ['course' => $c['name'], 'when' => _ti_leave_whens($c['sessions']),
            'contact' => $ask_contact ? ' Prosimy o kontakt w sprawie nowego terminu.' : '']);
    }
    return $res;
}

/**
 * Powiadamia uczestników lekcji o zastępstwie (zmiana prowadzącego): e-mail + SMS (szablon ti_substitute_notify).
 * @return array{emails:int, sms:int}
 */
function ti_substitute_notify_students(int $session_id, int $substitute_id, bool $email = true, bool $sms = true): array {
    $res = ['emails' => 0, 'sms' => 0];
    $s = db_one("SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, c.name AS course_name
                   FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    $u = db_one("SELECT COALESCE(NULLIF(TRIM(first_name||' '||last_name),''), name) AS n FROM users WHERE id=?", [$substitute_id]);
    if (!$s || !$u) return $res;
    $who  = (string)$u['n'];
    $when = date('d.m.Y', strtotime((string)$s['lesson_date'])) . (trim((string)$s['time_from']) !== '' ? ' o ' . substr((string)$s['time_from'], 0, 5) : '');
    if ($email) {
        if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
        if (function_exists('mail_queue_add')) {
            $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
            $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
            $html = '<p>Dzień dobry,</p><p>Zajęcia <strong>' . htmlspecialchars((string)$s['course_name'], ENT_QUOTES) . '</strong> w terminie <strong>' . htmlspecialchars($when, ENT_QUOTES)
                  . '</strong> odbędą się bez zmiany terminu, ale poprowadzi je <strong>' . htmlspecialchars($who, ENT_QUOTES) . '</strong> (zastępstwo).</p>'
                  . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Otwórz panel kursanta</a></p>';
            foreach (_ti_leave_course_emails((int)$s['course_id']) as $addr => $nm) {
                try { mail_queue_add($addr, (string)$nm, "{$org}: zastępstwo na zajęciach — " . $s['course_name'], $html, '', 'ti_substitute', $session_id, '', false); $res['emails']++; }
                catch (\Throwable $e) {}
            }
        }
    }
    if ($sms) $res['sms'] = ti_leave_sms_course((int)$s['course_id'], 'ti_substitute_notify', ['course' => (string)$s['course_name'], 'when' => $when, 'instructor' => $who]);
    return $res;
}

/**
 * Powiadom kursantów prowadzącego o zaplanowanej nieobecności.
 *  • e-mail (kolejka) — do wszystkich aktywnych kursantów prowadzącego (+ opiekunów małoletnich),
 *  • SMS (szablon ti_absence_notice, $sms) — tylko do uczestników kursów, w których przypadają lekcje
 *    kolidujące z nieobecnością (i tylko tych, którzy włączyli SMS o lekcjach).
 * Idempotentne: nie wysyła ponownie, dopóki zakres/godziny/rodzaj się nie zmienią (chyba że $force).
 * Pomija nieobecności już zakończone. Zwraca liczbę wysłanych e-maili; liczbę SMS-ów — przez $sms_sent.
 */
function ti_leaves_notify(int $leave_id, bool $force = false, bool $sms = true, ?int &$sms_sent = null): int {
    ti_leaves_migrate();
    $sms_sent = 0;
    $l = db_one(
        "SELECT l.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
         FROM k30_ti_instructor_leaves l JOIN users u ON u.id=l.instructor_id WHERE l.id=?",
        [$leave_id]
    );
    if (!$l) return 0;
    if ($l['date_to'] < date('Y-m-d')) return 0;                 // urlop już za nami
    // Skrót zmienia się tylko dla nieobecności godzinowych — hash dotychczasowych (całodniowych) pozostaje taki sam
    $hash = md5($l['instructor_id'] . '|' . $l['date_from'] . '|' . $l['date_to'] . '|' . $l['type']
        . (ti_leave_is_hourly($l) ? '|' . $l['time_from'] . '|' . $l['time_to'] : ''));
    if (!$force && ($l['notified_hash'] ?? '') === $hash) return 0; // już powiadomiono o tym zakresie

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $url   = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php';
    $range = ti_leave_when_label($l);
    $typeLabel = ti_leave_type_label($l['type']);

    $sent = 0;
    foreach (ti_leave_affected_students((int)$l['instructor_id']) as $s) {
        // Adresaci: główny e-mail kursanta + e-mail opiekuna (gdy małoletni)
        $emails  = [];
        $primary = trim((string)($s['email'] ?? ''));
        if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$s['name'];
        $gemail  = trim((string)($s['guardian_email'] ?? ''));
        if (!empty($s['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$s['name'];
        if (!$emails || !function_exists('mail_queue_add')) continue;

        $html = "<p>Dzień dobry,</p>"
              . "<p>Informujemy, że prowadzący <strong>" . htmlspecialchars((string)$l['instructor_name'], ENT_QUOTES) . "</strong> "
              . "ma zaplanowaną nieobecność (" . htmlspecialchars($typeLabel, ENT_QUOTES) . ") w terminie <strong>" . htmlspecialchars($range, ENT_QUOTES) . "</strong>.</p>"
              . "<p>W tym czasie zajęcia mogą zostać odwołane lub przełożone. Aktualny harmonogram i ewentualne zmiany znajdziesz w panelu kursanta.</p>"
              . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Otwórz panel kursanta</a></p>"
              . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";
        foreach ($emails as $addr => $nm) {
            try { mail_queue_add($addr, $nm, "{$org}: nieobecność prowadzącego ({$range})", $html, '', 'ti_leave', (int)$l['id'], '', false); $sent++; }
            catch (\Throwable $e) {}
        }
    }
    if ($sms) {
        $by_course = [];
        foreach (ti_leave_conflicts($l) as $c) $by_course[(int)$c['course_id']][] = $c;
        foreach ($by_course as $cid => $list) {
            $sms_sent += ti_leave_sms_course($cid, 'ti_absence_notice', [
                // ASCII w SMS (półpauza wyrzuca wiadomość z GSM-7 → 70 znaków zamiast 160)
                'instructor' => (string)$l['instructor_name'], 'when' => str_replace('–', '-', $range), 'course' => (string)$list[0]['course_name'],
            ]);
        }
    }
    db()->prepare("UPDATE k30_ti_instructor_leaves SET notified_at=datetime('now'), notified_hash=? WHERE id=?")
       ->execute([$hash, $leave_id]);
    return $sent;
}
