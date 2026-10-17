<?php
/**
 * modules/ti_plan_public/logic/planPublicManager.php — Publiczne linki do planów grup TI: logika.
 *
 * Token = 24 znaki [a-z0-9] (≈124 bity), jeden na grupę. Adres publiczny:
 *   <APP_URL>/karty30/ti/dydaktyk/planPubliczny.php?g=<token>
 * a na subdomenie ti.* krótko:  https://ti.../plan/<token>  (reguła w .htaccess).
 *
 * Dane dla strony publicznej (tiPlanPublicData) celowo pomijają: kursantów, PESEL,
 * notatki lekcji, linki Zoom, rezerwacje (status reserved) i wersje robocze (draft);
 * prowadzący TYLKO inicjałami (tiPlanPublicInitials) — pełne nazwisko zostaje w wydrukach wewnętrznych.
 */
require_once __DIR__ . '/planPublicSchema.php';
require_once dirname(__DIR__, 3) . '/includes/ti_planner_ext.php';
require_once dirname(__DIR__, 3) . '/includes/ti_room_reports.php';
require_once dirname(__DIR__, 3) . '/includes/ti_reschedule.php';

const TI_PLAN_PUBLIC_WEEKS = [4, 8, 12, 16, 26, 52];

/** Wiersz linku dla grupy (null = nigdy nie publikowano). */
function tiPlanPublicGet(int $courseId): ?array {
    tiPlanPublicMigrate();
    return db_one("SELECT * FROM k30_ti_plan_public_links WHERE course_id=?", [$courseId]) ?: null;
}

/** Linki dla wielu grup naraz: [course_id => wiersz]. */
function tiPlanPublicMap(array $courseIds): array {
    tiPlanPublicMigrate();
    $ids = array_values(array_unique(array_map('intval', $courseIds)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_plan_public_links WHERE course_id IN ($in)", $ids) as $r) $out[(int)$r['course_id']] = $r;
    return $out;
}

function tiPlanPublicToken(): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $t = '';
    for ($i = 0; $i < 24; $i++) $t .= $alphabet[random_int(0, 35)];
    return $t;
}

/** Włącza publikację (tworzy link, jeśli go nie ma; istniejący token zostaje). */
function tiPlanPublicEnable(int $courseId, int $uid): array {
    tiPlanPublicMigrate();
    $row = tiPlanPublicGet($courseId);
    if ($row) {
        db()->prepare("UPDATE k30_ti_plan_public_links SET is_enabled=1, updated_at=datetime('now') WHERE course_id=?")->execute([$courseId]);
    } else {
        db()->prepare("INSERT INTO k30_ti_plan_public_links(course_id, token, is_enabled, created_by) VALUES(?,?,1,?)")
            ->execute([$courseId, tiPlanPublicToken(), $uid]);
    }
    return tiPlanPublicGet($courseId);
}

/** Wyłącza publikację — adres przestaje działać, ale token zostaje (ponowne włączenie = ten sam link). */
function tiPlanPublicDisable(int $courseId): void {
    tiPlanPublicMigrate();
    db()->prepare("UPDATE k30_ti_plan_public_links SET is_enabled=0, updated_at=datetime('now') WHERE course_id=?")->execute([$courseId]);
}

/** Nowy token — stary adres przestaje działać natychmiast. */
function tiPlanPublicRegenerate(int $courseId, int $uid): array {
    tiPlanPublicMigrate();
    if (!tiPlanPublicGet($courseId)) return tiPlanPublicEnable($courseId, $uid);
    db()->prepare("UPDATE k30_ti_plan_public_links SET token=?, views=0, last_view_at=NULL, updated_at=datetime('now') WHERE course_id=?")
        ->execute([tiPlanPublicToken(), $courseId]);
    return tiPlanPublicGet($courseId);
}

/** Ustawienia widoku publicznego (zakres, kontakty, lista, komunikat). */
function tiPlanPublicUpdateOptions(int $courseId, array $in): void {
    tiPlanPublicMigrate();
    $weeks = (int)($in['weeks'] ?? 12);
    if (!in_array($weeks, TI_PLAN_PUBLIC_WEEKS, true)) $weeks = 12;
    db()->prepare("UPDATE k30_ti_plan_public_links
                      SET weeks=?, show_contacts=?, show_list=?, in_directory=?, note=?, updated_at=datetime('now')
                    WHERE course_id=?")
        ->execute([$weeks, !empty($in['show_contacts']) ? 1 : 0, !empty($in['show_list']) ? 1 : 0, !empty($in['in_directory']) ? 1 : 0,
                   mb_substr(trim((string)($in['note'] ?? '')), 0, 500), $courseId]);
}

/** Wiersz linku po tokenie — tylko włączone i tylko dla istniejącej grupy. */
function tiPlanPublicFindByToken(string $token): ?array {
    tiPlanPublicMigrate();
    if (!preg_match('/^[a-z0-9]{24}$/', $token)) return null;
    $row = db_one("SELECT l.*, c.name AS course_name, c.status AS course_status
                     FROM k30_ti_plan_public_links l JOIN k30_ti_courses c ON c.id=l.course_id
                    WHERE l.token=? AND l.is_enabled=1", [$token]);
    return $row ?: null;
}

/** Licznik wyświetleń (bez IP — tylko ile razy i kiedy ostatnio). */
function tiPlanPublicTouch(int $linkId): void {
    try {
        db()->prepare("UPDATE k30_ti_plan_public_links SET views=views+1, last_view_at=datetime('now') WHERE id=?")->execute([$linkId]);
    } catch (\Throwable $e) {}
}

/** Pełny adres publiczny linku (techniczny, działa z każdej domeny). */
function tiPlanPublicUrl(array $row): string {
    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
    return $base . '/karty30/ti/dydaktyk/planPubliczny.php?g=' . rawurlencode((string)$row['token']);
}

/** Krótki adres na subdomenie ti.* / szo.* / kurs*.* (gdy panel działa na takim hoście), inaczej null. */
function tiPlanPublicShortUrl(array $row): ?string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^(ti|szo|kurs)[^.]*\./i', $host)) return null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    return $scheme . '://' . $host . '/plan/' . (string)$row['token'];
}

/** Inicjały prowadzącego na stronę publiczną: „Maria Szczerbowska” → „M. S.”; placeholdery (VCT/DUU) bez zmian. */
function tiPlanPublicInitials(string $name): string {
    $name = trim($name);
    if ($name === '' || $name === '—') return $name;
    $parts = preg_split('/[\s-]+/u', $name) ?: [];
    if (count($parts) === 1 && mb_strlen($name) <= 4 && mb_strtoupper($name) === $name) return $name;   // VCT, DUU
    $ini = [];
    foreach ($parts as $w) { $w = (string)preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $w); if ($w !== '') $ini[] = mb_strtoupper(mb_substr($w, 0, 1)) . '.'; }
    return implode(' ', $ini);
}

/**
 * Dane strony publicznej: siatka tygodnia (ti_librus_grid — jak wydruk „Siatka”)
 * oraz lista nadchodzących terminów bez danych wrażliwych.
 */
function tiPlanPublicData(array $link): array {
    ti_planner_ext_migrate();
    k30_ti_reschedule_migrate();
    $courseId = (int)$link['course_id'];
    $weeks    = (int)$link['weeks'] ?: 12;
    $grid     = ti_librus_grid($courseId, $weeks, true);

    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime("+{$weeks} weeks"));
    $rows = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.status, s.lesson_method, s.date_flag, s.meeting_url, c.default_meeting_url,
                u.name AS instr_name, r.name AS room_name, r.location AS room_location, r.short_label AS room_short_label,
                c.location AS course_location, c.is_online AS course_online
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
          WHERE s.course_id=? AND s.lesson_date BETWEEN ? AND ?
            AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved')
          ORDER BY s.lesson_date, s.time_from",
        [$courseId, $from, $to]
    );
    $methods = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'online', 'zdalna_inne' => 'online'];
    $list = [];
    foreach ($rows as $r) {
        $room = ti_room_label($r['room_name'] !== null ? ['name' => $r['room_name'], 'location' => $r['room_location'], 'short_label' => $r['room_short_label']] : null, (string)($r['course_location'] ?? ''));
        $method = $methods[(string)$r['lesson_method']] ?? ((string)$r['lesson_method'] === '' ? ((int)$r['course_online'] === 1 ? 'online' : 'stacjonarnie') : '');
        $list[] = [
            'date'      => (string)$r['lesson_date'],
            'dow'       => TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['lesson_date']))] ?? '',
            'time'      => $r['time_from'] !== null && $r['time_from'] !== '' ? substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) : '',
            'method'    => $method,
            'instr'     => tiPlanPublicInitials((string)($r['instr_name'] ?? '')),
            'room'      => $room !== '—' && $method !== 'online' ? $room : '',   // zajęcia online — bez sali
            'cancelled' => (string)$r['status'] === 'cancelled',
            'flag'      => (string)($r['date_flag'] ?? ''),
            // link do zajęć online (własny link terminu, a gdy go brak — stały link grupy, np. Zoom)
            'url'       => $method === 'online' ? (trim((string)$r['meeting_url']) ?: trim((string)$r['default_meeting_url'])) : '',
        ];
    }
    // Strona publiczna: prowadzący tylko inicjałami (siatka, nagłówek, kontakty)
    foreach ($grid['grid'] ?? [] as $tk => $row) foreach ($row as $d => $cell) if ($cell) $grid['grid'][$tk][$d]['instructor'] = tiPlanPublicInitials((string)($cell['instructor'] ?? ''));
    foreach ($grid['instructors'] ?? [] as $i => $ins) $grid['instructors'][$i]['name'] = tiPlanPublicInitials((string)$ins['name']);
    if (!empty($grid['course'])) $grid['course']['instructor_name'] = tiPlanPublicInitials((string)($grid['course']['instructor_name'] ?? ''));
    return ['grid' => $grid, 'list' => $list, 'from' => $from, 'to' => $to, 'weeks' => $weeks];
}
