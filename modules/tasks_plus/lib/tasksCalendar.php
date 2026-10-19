<?php
/**
 * modules/tasks_plus/lib/tasksCalendar.php — kalendarz terminów zadań i prywatny kanał iCal.
 *
 * Zadanie trafia do kalendarza w dniu terminu (due_date, z godziną due_time, jeśli jest);
 * zadanie bez terminu, ale z datą startu — w dniu startu (oznaczone jako „start”).
 * Kanał iCal: prywatny token per użytkownik (task_ical_tokens), zadania PRZYPISANE do niego,
 * nieukończone. Dostęp sprawdzany przy każdym pobraniu (rola w obszarze, aktywne konto).
 */
require_once __DIR__ . '/tasksPlus.php';

function tasksCalendarMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS task_ical_tokens (
        user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        token      TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        last_used_at TEXT
    )");
}

/**
 * Zadania w zakresie dat [$from, $to] z obszarów użytkownika.
 * $f: ws (0 = wszystkie), mine (tylko przypisane do mnie), done (z ukończonymi).
 * @return array<string, array<int, array>> data Y-m-d => lista zadań
 */
function tasksCalendarItems(int $uid, string $from, string $to, array $f = []): array
{
    task_extras_schema_heal();
    $wss = tasksPlusWorkspaces($uid);
    $wsId = (int)($f['ws'] ?? 0);
    if ($wsId) $wss = array_intersect_key($wss, [$wsId => 1]);
    // Termin ukryty dla roli (uprawnienia pól) → obszar pomijany dla member/viewer
    $wss = array_filter($wss, fn($w) => in_array($w['my_role'], ['admin', 'editor'], true)
        || (task_field_visible('due_date', $w['my_role']) && task_field_visible('start_date', $w['my_role'])));
    if (!$wss) return [];
    $ids = array_keys($wss);

    $sql = "SELECT t.id, t.title, t.priority, t.due_date, t.due_time, t.start_date, t.completed_at, t.workspace_id,
                   tl.name AS list_name,
                   COALESCE(t.due_date, t.start_date) AS cal_date,
                   CASE WHEN t.due_date IS NULL OR t.due_date='' THEN 1 ELSE 0 END AS is_start
            FROM tasks t JOIN task_lists tl ON tl.id=t.list_id
            WHERE t.deleted_at IS NULL AND t.archived_at IS NULL
              AND t.workspace_id IN (" . tasksPlusIn($ids) . ")
              AND COALESCE(NULLIF(t.due_date,''), NULLIF(t.start_date,'')) BETWEEN ? AND ?";
    $params = array_merge($ids, [$from, $to]);
    if (empty($f['done'])) $sql .= " AND t.completed_at IS NULL";
    if (!empty($f['mine'])) {
        $sql .= " AND EXISTS (SELECT 1 FROM task_assignments a WHERE a.task_id=t.id AND a.user_id=?)";
        $params[] = $uid;
    }
    $sql .= " ORDER BY cal_date, CASE WHEN t.due_time IS NULL THEN 1 ELSE 0 END, t.due_time, t.priority DESC, t.title";

    $out = [];
    foreach (db_all($sql, $params) as $t) {
        $t['ws_name'] = $wss[(int)$t['workspace_id']]['name'] ?? '';
        $t['ws_color'] = $wss[(int)$t['workspace_id']]['color'] ?? '#2563eb';
        $t['overdue'] = task_is_overdue($t);
        $out[substr((string)$t['cal_date'], 0, 10)][] = $t;
    }
    return $out;
}

/** Siatka miesiąca: tygodnie (pon–niedz) z datami Y-m-d. */
function tasksCalendarMonthGrid(int $year, int $month): array
{
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $start = $first->modify('-' . ((int)$first->format('N') - 1) . ' days');
    $last = $first->modify('last day of this month');
    $end = $last->modify('+' . (7 - (int)$last->format('N')) . ' days');
    $weeks = [];
    $i = 0;
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day'), $i++) {
        $weeks[intdiv($i, 7)][] = $d->format('Y-m-d');
    }
    return $weeks;
}

function tasksCalendarMonthName(int $m): string
{
    return ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień',
            'Wrzesień', 'Październik', 'Listopad', 'Grudzień'][$m] ?? '';
}

// ── iCal ─────────────────────────────────────────────────────────────────────

function tasksCalendarToken(int $uid, bool $create = false): ?string
{
    tasksCalendarMigrate();
    $row = db_one("SELECT token FROM task_ical_tokens WHERE user_id=?", [$uid]);
    if ($row) return (string)$row['token'];
    return $create ? tasksCalendarRegenerate($uid) : null;
}

function tasksCalendarRegenerate(int $uid): string
{
    tasksCalendarMigrate();
    $token = bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO task_ical_tokens (user_id, token, created_at) VALUES (?, ?, ?)
                   ON CONFLICT(user_id) DO UPDATE SET token=excluded.token, created_at=excluded.created_at, last_used_at=NULL")
        ->execute([$uid, $token, date('Y-m-d H:i:s')]);
    return $token;
}

function tasksCalendarRevoke(int $uid): void
{
    tasksCalendarMigrate();
    db()->prepare("DELETE FROM task_ical_tokens WHERE user_id=?")->execute([$uid]);
}

/** Użytkownik po tokenie kanału (tylko aktywne konto) albo null. */
function tasksCalendarUserByToken(string $token): ?array
{
    tasksCalendarMigrate();
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $u = db_one("SELECT u.id, u.name FROM task_ical_tokens k JOIN users u ON u.id=k.user_id
                 WHERE k.token=? AND u.is_active=1", [$token]);
    if ($u) {
        try { db()->prepare("UPDATE task_ical_tokens SET last_used_at=? WHERE user_id=?")->execute([date('Y-m-d H:i:s'), (int)$u['id']]); }
        catch (\Throwable $e) {}
    }
    return $u ?: null;
}

/** Treść kanału iCal (RFC 5545): zadania przypisane do użytkownika, nieukończone, z terminem. */
function tasksCalendarIcs(int $uid, string $userName): string
{
    task_extras_schema_heal();
    $rows = db_all(
        "SELECT t.id, t.title, t.description, t.priority, t.due_date, t.due_time, t.updated_at, t.workspace_id,
                tl.name AS list_name, w.name AS ws_name
         FROM tasks t
         JOIN task_assignments a ON a.task_id=t.id AND a.user_id=?
         JOIN task_lists tl ON tl.id=t.list_id
         JOIN task_workspaces w ON w.id=t.workspace_id AND w.is_active=1
         WHERE t.deleted_at IS NULL AND t.archived_at IS NULL AND t.completed_at IS NULL
           AND t.due_date IS NOT NULL AND t.due_date<>'' AND t.due_date >= ?
         ORDER BY t.due_date",
        [$uid, date('Y-m-d', strtotime('-60 days'))]
    );
    $roles = [];
    $host = parse_url(APP_URL, PHP_URL_HOST) ?: 'szo.local';
    $stamp = gmdate('Ymd\THis\Z');
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SZO//Zadania//PL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
              'X-WR-CALNAME:' . tasksCalendarIcsText('Zadania — ' . $userName),
              'X-WR-TIMEZONE:' . date_default_timezone_get(), 'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
              'X-PUBLISHED-TTL:PT1H'];
    foreach ($rows as $t) {
        $ws = (int)$t['workspace_id'];
        $roles[$ws] ??= task_workspace_role($ws, $uid);
        if (!$roles[$ws]) continue;
        if (!in_array($roles[$ws], ['admin', 'editor'], true) && !task_field_visible('due_date', $roles[$ws])) continue;
        $time = task_normalize_due_time($t['due_time'] ?? '');
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:zadanie-' . (int)$t['id'] . '@' . $host;
        $lines[] = 'DTSTAMP:' . $stamp;
        if ($time) {
            $start = (new DateTimeImmutable($t['due_date'] . ' ' . $time))->setTimezone(new DateTimeZone('UTC'));
            $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $start->modify('+30 minutes')->format('Ymd\THis\Z');
        } else {
            $d = new DateTimeImmutable($t['due_date']);
            $lines[] = 'DTSTART;VALUE=DATE:' . $d->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $d->modify('+1 day')->format('Ymd');
        }
        $lines[] = 'SUMMARY:' . tasksCalendarIcsText('Termin: ' . $t['title']);
        $desc = 'Obszar: ' . $t['ws_name'] . "\nKolumna: " . $t['list_name'] . "\nPriorytet: "
              . tasksPlusPriorityLabel((int)$t['priority']) . "\n" . tasksPlusTaskUrl((int)$t['id']);
        $lines[] = 'DESCRIPTION:' . tasksCalendarIcsText($desc);
        $lines[] = 'URL:' . tasksPlusTaskUrl((int)$t['id']);
        $lines[] = 'CATEGORIES:' . tasksCalendarIcsText((string)$t['ws_name']);
        $lines[] = 'PRIORITY:' . ([1 => 9, 2 => 5, 3 => 3, 4 => 1][(int)$t['priority']] ?? 5);
        $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('tasksCalendarIcsFold', $lines)) . "\r\n";
}

/** Escapowanie tekstu iCal (\\ ; , i nowe linie). */
function tasksCalendarIcsText(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', ''], $s);
}

/** Zawijanie linii do 75 bajtów bez rozcinania znaków UTF-8. */
function tasksCalendarIcsFold(string $line): string
{
    if (strlen($line) <= 75) return $line;
    $out = '';
    $cur = '';
    $limit = 75;
    foreach (mb_str_split($line) as $ch) {
        if (strlen($cur) + strlen($ch) > $limit) {
            $out .= $cur . "\r\n ";
            $cur = '';
            $limit = 74;
        }
        $cur .= $ch;
    }
    return $out . $cur;
}
