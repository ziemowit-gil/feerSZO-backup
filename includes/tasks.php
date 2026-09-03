<?php
/**
 * Moduł Zadań — funkcje pomocnicze
 * Require: db.php, auth.php, functions.php
 */

// ── Priorytety ─────────────────────────────────────────────────────────────

const TASK_PRIORITIES = [
    1 => ['label' => 'Niski',     'class' => 'secondary', 'icon' => 'bi-arrow-down',                'dot' => '#94a3b8'],
    2 => ['label' => 'Normalny',  'class' => 'primary',   'icon' => 'bi-dash',                       'dot' => '#3b82f6'],
    3 => ['label' => 'Wysoki',    'class' => 'warning',   'icon' => 'bi-arrow-up',                   'dot' => '#f59e0b'],
    4 => ['label' => 'Krytyczny', 'class' => 'danger',    'icon' => 'bi-exclamation-triangle-fill',  'dot' => '#dc2626'],
];

/** Kolorowa kropka priorytetu (bez tekstu) — kolory zgodne z pv_tasks_panel.php i tasks/index.php. */
function task_priority_badge(int $p): string {
    $d = TASK_PRIORITIES[$p] ?? TASK_PRIORITIES[2];
    return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
         . $d['dot'] . ';vertical-align:middle" title="Priorytet: ' . h($d['label'])
         . '" aria-label="Priorytet: ' . h($d['label']) . '"></span>';
}

// ── Kontrola dostępu ───────────────────────────────────────────────────────

/**
 * Zwraca rolę użytkownika w obszarze: 'admin' | 'editor' | 'viewer' | null (brak dostępu).
 * Administratorzy systemu zawsze mają rolę 'admin' we wszystkich obszarach.
 */
function task_workspace_role(int $workspace_id, ?int $user_id = null): ?string {
    if (!$user_id) {
        $u = current_user();
        $user_id = (int)($u['id'] ?? 0);
    }
    if (!$user_id) return null;

    // Admin systemu → zawsze pełny dostęp, pomija visible_roles/edit_roles
    $user = db_one("SELECT role FROM users WHERE id=?", [$user_id]);
    $sys_role = $user['role'] ?? '';
    if ($sys_role === 'admin') return 'admin';

    // Wczytaj konfigurację obszaru raz
    $ws = db_one(
        "SELECT created_by, visible_roles, edit_roles FROM task_workspaces WHERE id=?",
        [$workspace_id]
    );

    // Sprawdź visible_roles — jeśli ustawione, rola systemowa musi być na liście
    $visible_roles = $ws ? (json_decode($ws['visible_roles'] ?? '', true) ?: []) : [];
    if ($visible_roles && !in_array($sys_role, $visible_roles, true)) return null;

    // Sprawdź wpis w task_workspace_members
    $m = db_one(
        "SELECT role FROM task_workspace_members WHERE workspace_id=? AND user_id=?",
        [$workspace_id, $user_id]
    );
    $member_role = $m ? $m['role'] : null;

    // Twórca obszaru (created_by) → automatycznie lider (admin obszaru)
    if (!$member_role && $ws && (int)$ws['created_by'] === $user_id) {
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO task_workspace_members
                 (workspace_id, user_id, role, added_by, added_at)
                 VALUES (?, ?, 'admin', ?, datetime('now','localtime'))"
            )->execute([$workspace_id, $user_id, $user_id]);
        } catch (\Throwable $e) {}
        $member_role = 'admin';
    }

    // Zespoły — dostęp DODATKOWY obok bezpośredniego przypisania (uzupełnia, nie zastępuje).
    // Jeśli zespół użytkownika ma wyższą rolę w obszarze niż jego przypisanie indywidualne,
    // wygrywa wyższa (np. member wprost + admin przez zespół → admin).
    $member_role = task_ws_role_max($member_role, task_workspace_team_role($workspace_id, $user_id));

    if (!$member_role) return null;

    // Sprawdź edit_roles — jeśli ustawione i rola systemowa jej nie ma → viewer
    $edit_roles = $ws ? (json_decode($ws['edit_roles'] ?? '', true) ?: []) : [];
    if ($edit_roles && in_array($member_role, ['admin', 'editor'], true)
        && !in_array($sys_role, $edit_roles, true)) {
        return 'viewer';
    }

    return $member_role;
}

/**
 * Czy bieżący użytkownik jest przypisany do zadania (bezpośrednio lub przez kogoś).
 */
function task_is_assigned(int $task_id, ?int $user_id = null): bool {
    if (!$user_id) {
        $u = current_user();
        $user_id = (int)($u['id'] ?? 0);
    }
    if (!$user_id) return false;
    return (bool)db_one(
        "SELECT 1 FROM task_assignments WHERE task_id=? AND user_id=?",
        [$task_id, $user_id]
    );
}

// ── Role obszaru roboczego ─────────────────────────────────────────────────
// admin   — pełny dostęp: tworzenie/usuwanie/konfiguracja
// editor  — tworzenie i edycja zadań
// member  — zarządzanie własnymi zadaniami (done, podzadania, czas, komentarze)
// viewer  — tylko odczyt + zapis na swoich (termin, godziny)
const TASK_WS_ROLES = ['admin', 'editor', 'member', 'viewer'];

function task_require_workspace_access(int $workspace_id, array $allowed_roles = ['admin','editor','member','viewer']): void {
    require_login();
    $role = task_workspace_role($workspace_id);
    if (!$role || !in_array($role, $allowed_roles, true)) {
        if (php_sapi_name() !== 'cli' && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            task_api_error('Brak dostępu do tego obszaru.', 403);
        }
        http_response_code(403);
        echo '<div class="alert alert-danger m-4">Brak dostępu do tego obszaru.</div>';
        exit;
    }
}

// ── API helpers ────────────────────────────────────────────────────────────

function task_api_json(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function task_api_error(string $message, int $code = 400): never {
    task_api_json(['ok' => false, 'error' => $message], $code);
}

function task_api_ok(mixed $data = null): never {
    task_api_json(['ok' => true, 'data' => $data]);
}

function task_parse_json_body(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function task_csrf_check(array $body = []): void {
    auth_start();
    $token = $body['_csrf']
          ?? $_POST['_csrf']
          ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        task_api_error('Nieprawidłowy token CSRF.', 403);
    }
}

// ── Historia zdarzeń ───────────────────────────────────────────────────────

function task_log(int $task_id, int $user_id, string $event, ?string $from = null, ?string $to = null, ?array $meta = null): void {
    try {
        db()->prepare(
            "INSERT INTO task_history (task_id, user_id, event_type, from_value, to_value, metadata)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $task_id, $user_id, $event, $from, $to,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (\Throwable $e) { /* nie blokuj głównej operacji */ }
}

/**
 * Samonaprawa schematu — kolumny weryfikacji wykonania (potwierdzenie/odrzucenie)
 * mogły nie zostać jeszcze zmigrowane na tej instancji.
 */
function task_review_schema_heal(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        "ALTER TABLE tasks ADD COLUMN confirmed_at TEXT DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN confirmed_by INTEGER DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN rejected_at TEXT DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN rejected_by INTEGER DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN rejection_reason TEXT DEFAULT NULL",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
}

// ── Ruch zadania ───────────────────────────────────────────────────────────

function task_move(int $task_id, int $new_list_id, float $new_pos, int $user_id): array {
    $task = db_one(
        "SELECT t.*, tl.name AS list_name, tl.is_done_state
         FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
         WHERE t.id=? AND t.deleted_at IS NULL",
        [$task_id]
    );
    if (!$task) throw new \RuntimeException('Zadanie nie istnieje.');

    $new_list = db_one("SELECT * FROM task_lists WHERE id=?", [$new_list_id]);
    if (!$new_list) throw new \RuntimeException('Lista nie istnieje.');

    $old_list_id = (int)$task['list_id'];
    $now         = date('Y-m-d H:i:s');
    $pdo         = db();

    $pdo->beginTransaction();
    try {
        // Oblicz completed_at na podstawie is_done_state docelowej kolumny
        $completed_at = $new_list['is_done_state'] ? $now : null;

        $pdo->prepare(
            "UPDATE tasks SET list_id=?, position=?, completed_at=?, updated_at=? WHERE id=?"
        )->execute([$new_list_id, $new_pos, $completed_at, $now, $task_id]);

        // Zamknij otwarty rekord czasu w poprzedniej kolumnie
        $open = db_one(
            "SELECT id, entered_at FROM task_list_time WHERE task_id=? AND exited_at IS NULL",
            [$task_id]
        );
        if ($open) {
            $secs = max(0, strtotime($now) - strtotime($open['entered_at']));
            $pdo->prepare(
                "UPDATE task_list_time SET exited_at=?, duration_seconds=? WHERE id=?"
            )->execute([$now, $secs, $open['id']]);
        }

        // Otwórz nowy rekord czasu
        $pdo->prepare(
            "INSERT INTO task_list_time (task_id, list_id, list_name, entered_at)
             VALUES (?, ?, ?, ?)"
        )->execute([$task_id, $new_list_id, $new_list['name'], $now]);

        // Zapisz zdarzenie w historii
        if ($old_list_id !== $new_list_id) {
            task_log($task_id, $user_id, 'moved',
                $task['list_name'], $new_list['name'],
                ['from_list_id' => $old_list_id, 'to_list_id' => $new_list_id]
            );
        }

        // Jeśli lista jest stanem "ukończone" — zapisz zdarzenie completed
        if ($new_list['is_done_state'] && !$task['completed_at']) {
            task_log($task_id, $user_id, 'completed', null, $new_list['name']);
        }
        // Jeśli wychodzi z "ukończone" → reopen
        if (!$new_list['is_done_state'] && $task['completed_at']) {
            task_log($task_id, $user_id, 'reopened', $task['list_name'], $new_list['name']);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return db_one("SELECT * FROM tasks WHERE id=?", [$task_id]);
}

/**
 * Przenumeruje pozycje kart w kolumnie po ruchu (wysyłana tablica ID w nowej kolejności).
 */
function task_reorder_list(int $list_id, array $task_ids): void {
    $pos = 1;
    $stmt = db()->prepare("UPDATE tasks SET position=?, updated_at=? WHERE id=? AND list_id=?");
    $now  = date('Y-m-d H:i:s');
    foreach ($task_ids as $tid) {
        $stmt->execute([$pos, $now, (int)$tid, $list_id]);
        $pos++;
    }
}

// ── Dane tablicy ───────────────────────────────────────────────────────────

function task_board_data(int $workspace_id): array {
    $lists = db_all(
        "SELECT * FROM task_lists WHERE workspace_id=? ORDER BY position, id",
        [$workspace_id]
    );

    $tasks = db_all(
        "SELECT t.*,
                u.name AS creator_name
         FROM tasks t
         LEFT JOIN users u ON u.id = t.created_by
         WHERE t.workspace_id=? AND t.deleted_at IS NULL
         ORDER BY t.list_id, t.position, t.id",
        [$workspace_id]
    );

    // Tagi per zadanie
    $tags_map = [];
    if ($tasks) {
        $ids  = implode(',', array_map(fn($t) => (int)$t['id'], $tasks));
        $rows = db_all(
            "SELECT ttt.task_id, tt.id, tt.name, tt.color, tt.text_color
             FROM task_task_tags ttt
             JOIN task_tags tt ON tt.id = ttt.tag_id
             WHERE ttt.task_id IN ($ids)
             ORDER BY tt.name"
        );
        foreach ($rows as $r) {
            $tags_map[$r['task_id']][] = $r;
        }
    }

    // Assignees per zadanie
    $assign_map = [];
    if ($tasks) {
        $ids  = implode(',', array_map(fn($t) => (int)$t['id'], $tasks));
        $rows = db_all(
            "SELECT ta.task_id, u.id, u.name, u.email
             FROM task_assignments ta
             JOIN users u ON u.id = ta.user_id
             WHERE ta.task_id IN ($ids)
             ORDER BY u.name"
        );
        foreach ($rows as $r) {
            $assign_map[$r['task_id']][] = $r;
        }
    }

    // Liczba podzadań per zadanie
    $st_map = [];
    if ($tasks) {
        $ids  = implode(',', array_map(fn($t) => (int)$t['id'], $tasks));
        try {
            $rows = db_all(
                "SELECT task_id, COUNT(*) AS total, SUM(is_done) AS done
                 FROM task_subtasks WHERE task_id IN ($ids) GROUP BY task_id"
            );
            foreach ($rows as $r) {
                $st_map[$r['task_id']] = ['total' => (int)$r['total'], 'done' => (int)$r['done']];
            }
        } catch (\Throwable $e) { /* tabela może jeszcze nie istnieć */ }
    }

    // Złóż dane
    $tasks_by_list = [];
    foreach ($tasks as $t) {
        $t['tags']      = $tags_map[$t['id']] ?? [];
        $t['assignees'] = $assign_map[$t['id']] ?? [];
        $t['st_total']  = $st_map[$t['id']]['total'] ?? 0;
        $t['st_done']   = $st_map[$t['id']]['done']  ?? 0;
        $tasks_by_list[$t['list_id']][] = $t;
    }

    foreach ($lists as &$l) {
        $l['tasks'] = $tasks_by_list[$l['id']] ?? [];
    }
    unset($l);

    return $lists;
}

// ── Dostępne obszary dla użytkownika ──────────────────────────────────────

function task_user_workspaces(?int $user_id = null): array {
    if (!$user_id) {
        $u = current_user();
        $user_id = (int)($u['id'] ?? 0);
    }
    if (!$user_id) return [];

    $u = db_one("SELECT role FROM users WHERE id=?", [$user_id]);
    if (($u['role'] ?? '') === 'admin') {
        // Admin widzi wszystkie aktywne obszary
        return db_all(
            "SELECT tw.*, COUNT(t.id) AS task_count
             FROM task_workspaces tw
             LEFT JOIN tasks t ON t.workspace_id = tw.id AND t.deleted_at IS NULL
             WHERE tw.is_active=1
             GROUP BY tw.id
             ORDER BY tw.name",
            []
        );
    }

    // Dostęp bezpośredni (task_workspace_members) LUB przez zespół (task_workspace_teams)
    task_teams_migrate();
    $rows = db_all(
        "SELECT tw.*, COUNT(t.id) AS task_count
         FROM task_workspaces tw
         LEFT JOIN tasks t ON t.workspace_id = tw.id AND t.deleted_at IS NULL
         WHERE tw.is_active=1 AND tw.id IN (
             SELECT workspace_id FROM task_workspace_members WHERE user_id=?
             UNION
             SELECT wt.workspace_id FROM task_workspace_teams wt
             JOIN task_team_members tm ON tm.team_id = wt.team_id
             WHERE tm.user_id=?
         )
         GROUP BY tw.id
         ORDER BY tw.name",
        [$user_id, $user_id]
    );
    foreach ($rows as &$r) {
        $r['my_role'] = task_workspace_role((int)$r['id'], $user_id);
    }
    unset($r);
    return $rows;
}

// ── Zespoły ──────────────────────────────────────────────────────────────
// Grupa użytkowników, niezależna od jednostek organizacyjnych (org_units).
// Zespół można przypisać do obszaru roboczego (task_workspace_teams) — wszyscy
// jego członkowie dostają wtedy rolę w tym obszarze, DODATKOWO do ewentualnego
// bezpośredniego przypisania pojedynczych osób (task_workspace_members).

function task_teams_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_teams (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        VARCHAR(100) NOT NULL,
        description TEXT         NOT NULL DEFAULT '',
        color       VARCHAR(7)   NOT NULL DEFAULT '#2563eb',
        icon        VARCHAR(50)  NOT NULL DEFAULT 'bi-people-fill',
        is_active   INTEGER      NOT NULL DEFAULT 1,
        created_by  INTEGER,
        created_at  DATETIME     NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at  DATETIME     NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_team_members (
        team_id  INTEGER  NOT NULL REFERENCES task_teams(id) ON DELETE CASCADE,
        user_id  INTEGER  NOT NULL,
        added_by INTEGER,
        added_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (team_id, user_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_workspace_teams (
        workspace_id INTEGER     NOT NULL REFERENCES task_workspaces(id) ON DELETE CASCADE,
        team_id      INTEGER     NOT NULL REFERENCES task_teams(id) ON DELETE CASCADE,
        role         VARCHAR(20) NOT NULL DEFAULT 'member',
        added_by     INTEGER,
        added_at     DATETIME    NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (workspace_id, team_id)
    )");
}

/** Najwyższa z dwóch ról wg hierarchii admin>editor>member>viewer (null-owe pomijane). */
function task_ws_role_max(?string $a, ?string $b): ?string {
    $rank = ['admin' => 4, 'editor' => 3, 'member' => 2, 'viewer' => 1];
    $ra = $a !== null ? ($rank[$a] ?? 0) : 0;
    $rb = $b !== null ? ($rank[$b] ?? 0) : 0;
    if ($ra === 0 && $rb === 0) return null;
    return $ra >= $rb ? $a : $b;
}

/** Najwyższa rola nadana użytkownikowi w obszarze przez przynależność do zespołu(ów). */
function task_workspace_team_role(int $workspace_id, int $user_id): ?string {
    task_teams_migrate();
    try {
        $rows = db_all(
            "SELECT wt.role FROM task_workspace_teams wt
             JOIN task_team_members tm ON tm.team_id = wt.team_id
             JOIN task_teams t ON t.id = wt.team_id AND t.is_active = 1
             WHERE wt.workspace_id = ? AND tm.user_id = ?",
            [$workspace_id, $user_id]
        );
    } catch (\Throwable $e) { return null; }
    $best = null;
    foreach ($rows as $r) $best = task_ws_role_max($best, $r['role']);
    return $best;
}

/** Wszystkie zespoły (aktywne domyślnie), z liczbą członków i obszarów. */
function task_get_teams(bool $active_only = true): array {
    task_teams_migrate();
    return db_all(
        "SELECT tt.*,
                (SELECT COUNT(*) FROM task_team_members WHERE team_id = tt.id) AS member_count,
                (SELECT COUNT(*) FROM task_workspace_teams WHERE team_id = tt.id) AS workspace_count
         FROM task_teams tt"
        . ($active_only ? " WHERE tt.is_active = 1" : "")
        . " ORDER BY tt.name"
    );
}

/** Zespoły, do których należy dany użytkownik. */
function task_user_teams(int $user_id): array {
    task_teams_migrate();
    return db_all(
        "SELECT tt.* FROM task_teams tt
         JOIN task_team_members tm ON tm.team_id = tt.id
         WHERE tm.user_id = ? AND tt.is_active = 1
         ORDER BY tt.name",
        [$user_id]
    );
}

// ── Inicjalizacja rekordu czasu dla nowego zadania ─────────────────────────

function task_start_time_tracking(int $task_id, int $list_id, string $list_name): void {
    try {
        db()->prepare(
            "INSERT INTO task_list_time (task_id, list_id, list_name, entered_at)
             VALUES (?, ?, ?, datetime('now','localtime'))"
        )->execute([$task_id, $list_id, $list_name]);
    } catch (\Throwable $e) {}
}

// ── Awatar inicjałowy ──────────────────────────────────────────────────────

function task_avatar_initials(string $name, string $bg = '#2563eb', string $color = '#fff'): string {
    $parts = array_filter(explode(' ', trim($name)));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    $initials = $initials ?: '?';
    return '<span class="tk-av" style="background:' . h($bg) . ';color:' . h($color) . '" title="' . h($name) . '">' . h($initials) . '</span>';
}

// ── Obszary (Areas) ────────────────────────────────────────────────────────

function task_areas_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_areas (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       VARCHAR(100) NOT NULL,
        color      VARCHAR(7)   NOT NULL DEFAULT '#6c757d',
        icon       VARCHAR(50)  NOT NULL DEFAULT 'bi-layers',
        is_active  INTEGER      NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at DATETIME     NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at DATETIME     NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN area_id INTEGER REFERENCES task_areas(id)"); }
    catch (\Throwable $e) {}
    // v8: przypisanie do jednostki org
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL"); }
    catch (\Throwable $e) {}
    // v8: widoczność i edycja obszaru per rola systemowa
    try { $pdo->exec("ALTER TABLE task_workspaces ADD COLUMN visible_roles TEXT NOT NULL DEFAULT ''"); }
    catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_workspaces ADD COLUMN edit_roles TEXT NOT NULL DEFAULT ''"); }
    catch (\Throwable $e) {}
    // v9: powiadomienia per obszar per użytkownik
    try { $pdo->exec("ALTER TABLE task_workspace_members ADD COLUMN notify_email INTEGER NOT NULL DEFAULT 1"); }
    catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_workspace_members ADD COLUMN notify_sms INTEGER NOT NULL DEFAULT 0"); }
    catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_workspace_members ADD COLUMN notify_push INTEGER NOT NULL DEFAULT 0"); }
    catch (\Throwable $e) {}
    // v15: archiwizacja zadań ukończonych (7 dni po completed_at — patrz cron/tasks_archive.php)
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN archived_at TEXT DEFAULT NULL"); }
    catch (\Throwable $e) {}
    // v16: backfill — każdy przypisany do zadania musi być w task_workspace_members
    try {
        $pdo->exec("
            INSERT OR IGNORE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at)
            SELECT DISTINCT t.workspace_id, ta.user_id, 'member', ta.assigned_by, datetime('now','localtime')
            FROM task_assignments ta
            JOIN tasks t ON t.id = ta.task_id AND t.deleted_at IS NULL
        ");
    } catch (\Throwable $e) {}
}

function task_get_areas(): array {
    task_areas_migrate();
    return db_all("SELECT * FROM task_areas WHERE is_active=1 ORDER BY name");
}

function task_area_badge(?int $area_id): string {
    if (!$area_id) return '';
    $a = db_one("SELECT name, color, icon FROM task_areas WHERE id=?", [$area_id]);
    if (!$a) return '';
    $color = h($a['color']);
    return '<span class="badge" style="background:' . $color . ';font-size:.72em">'
         . '<i class="bi ' . h($a['icon']) . ' me-1"></i>' . h($a['name']) . '</span>';
}

// ── Jednostka org badge ────────────────────────────────────────────────────

function task_unit_badge(?int $unit_id): string {
    if (!$unit_id) return '';
    try {
        $u = db_one("SELECT name, short_name FROM org_units WHERE id=?", [$unit_id]);
    } catch (\Throwable $e) { return ''; }
    if (!$u) return '';
    $label = $u['short_name'] ?: $u['name'];
    return '<span class="badge" style="background:#ede9fe;color:#6d28d9;font-size:.68em" title="Jednostka: ' . h($u['name']) . '">'
         . '<i class="bi bi-diagram-3 me-1"></i>' . h($label) . '</span>';
}

// ── Due date badge ─────────────────────────────────────────────────────────

function task_due_badge(?string $due_date, ?string $completed_at): string {
    if (!$due_date) return '';
    $days = (int) floor((strtotime($due_date) - strtotime(date('Y-m-d'))) / 86400);
    if ($completed_at) {
        $cls = 'bg-success'; $icon = 'bi-check-circle';
    } elseif ($days < 0) {
        $cls = 'bg-danger';  $icon = 'bi-exclamation-circle';
    } elseif ($days === 0) {
        $cls = 'bg-warning text-dark'; $icon = 'bi-clock';
    } elseif ($days <= 3) {
        $cls = 'bg-warning text-dark'; $icon = 'bi-clock';
    } else {
        $cls = 'bg-light text-secondary border'; $icon = 'bi-calendar3';
    }
    return '<span class="badge ' . $cls . ' task-due-badge">'
         . '<i class="bi ' . $icon . ' me-1"></i>'
         . date_pl($due_date) . '</span>';
}

// ── Uprawnienia per-pole (widoczność/edycja) dla ról obszaru ────────────────
// admin/editor obszaru mają zawsze pełny dostęp — ograniczenia poniżej
// dotyczą wyłącznie ról member/viewer. Zastępuje dawny twardo zakodowany
// zestaw pól edytowalnych przez member/viewer w tasks/api/task.php.

const TASK_GOVERNED_FIELDS = [
    'title'               => 'Tytuł',
    'description'         => 'Opis',
    'priority'            => 'Priorytet',
    'start_date'          => 'Data rozpoczęcia',
    'due_date'            => 'Termin',
    'estimated_hours'     => 'Szacowane godziny',
    'recurrence'          => 'Cykliczność',
    'recurrence_end_date' => 'Data zakończenia cykliczności',
    'claimable'           => 'Możliwość przejęcia (claimable)',
    'area_id'             => 'Obszar zadania',
    'unit_id'             => 'Jednostka organizacyjna',
    // Nie są kolumnami tabeli tasks — osobne podzasoby (task_comments/task_files),
    // ale rządzą się tą samą logiką widoczności/edycji, więc reużywają ten sam
    // mechanizm (klucz "pola" = nazwa zasobu, nie kolumna).
    'comments'            => 'Komentarze (dodawanie)',
    'files'               => 'Załączniki (dodawanie/usuwanie plików)',
];

function task_field_perms_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_field_perms (
        field_key     TEXT PRIMARY KEY,
        visible_roles TEXT NOT NULL DEFAULT '',
        edit_roles    TEXT NOT NULL DEFAULT ''
    )");
    // Zasiej wartości startowe zgodne z dotychczasowym zachowaniem sprzed
    // wprowadzenia tego mechanizmu — INSERT OR IGNORE per klucz (nie tylko
    // przy pustej tabeli!), żeby nowe pozycje dodane do TASK_GOVERNED_FIELDS
    // w przyszłości (np. 'comments'/'files' dołączone później) też się zasiały
    // na środowiskach, gdzie tabela już istnieje — bez nadpisywania tego, co
    // admin już skonfigurował dla istniejących kluczy (OR IGNORE + PRIMARY KEY).
    // 'comments' było dostępne bez ograniczeń dla każdej roli obszaru —
    // zachowane jako punkt startowy. 'files' NIE jest zasiane jako edytowalne:
    // dotychczasowa logika w tasks/api/upload.php była niespójna (member był
    // całkowicie wykluczony, viewer wpuszczany tylko gdy przypisany) —
    // bezpieczniej zacząć od "brak dostępu" i zostawić decyzję adminowi.
    $legacy_editable = ['due_date', 'estimated_hours', 'recurrence', 'recurrence_end_date', 'comments'];
    $stmt = $pdo->prepare(
        "INSERT OR IGNORE INTO task_field_perms (field_key, visible_roles, edit_roles) VALUES (?, '', ?)"
    );
    foreach (array_keys(TASK_GOVERNED_FIELDS) as $key) {
        $stmt->execute([$key, in_array($key, $legacy_editable, true) ? '["member","viewer"]' : '']);
    }
}

/** Ładuje uprawnienia pól z cache (jeden SELECT na żądanie). */
function _task_field_perms(): array {
    task_field_perms_migrate();
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    try {
        foreach (db_all("SELECT field_key, visible_roles, edit_roles FROM task_field_perms") as $r) {
            $p[$r['field_key']] = $r;
        }
    } catch (\Throwable $e) {}
    return $p;
}

/**
 * Czy dana rola OBSZARU (workspace) widzi pole $field_key.
 * admin/editor — zawsze tak. Puste visible_roles = widoczne dla wszystkich
 * (bezpieczny wariant domyślny — nic się nie chowa, dopóki admin nie skonfiguruje).
 */
function task_field_visible(string $field_key, ?string $ws_role = null): bool {
    if (in_array($ws_role, ['admin', 'editor'], true)) return true;
    $fd = _task_field_perms()[$field_key] ?? null;
    if (!$fd) return true;
    $roles_json = $fd['visible_roles'] ?? '';
    if ($roles_json === '' || $roles_json === '[]') return true;
    $allowed = json_decode($roles_json, true);
    if (!is_array($allowed) || empty($allowed)) return true;
    return in_array($ws_role, $allowed, true);
}

/**
 * Czy dana rola OBSZARU może EDYTOWAĆ pole $field_key.
 * admin/editor — zawsze tak. Puste edit_roles = NIEedytowalne przez member/
 * viewer (odwrotny domyślny wariant niż widoczność — bezpieczniej nie
 * przyznawać nowych uprawnień do zapisu bez jawnej decyzji admina).
 */
function task_field_editable(string $field_key, ?string $ws_role = null): bool {
    if (in_array($ws_role, ['admin', 'editor'], true)) return true;
    if (!task_field_visible($field_key, $ws_role)) return false;
    $fd = _task_field_perms()[$field_key] ?? null;
    if (!$fd) return false;
    $roles_json = $fd['edit_roles'] ?? '';
    if ($roles_json === '' || $roles_json === '[]') return false;
    $allowed = json_decode($roles_json, true);
    if (!is_array($allowed) || empty($allowed)) return false;
    return in_array($ws_role, $allowed, true);
}

// ── Szablony zadań (checklisty wielokrotnego użytku) ────────────────────────
// Szablon jest globalny (jak task_areas/task_tags) — może być zastosowany
// w dowolnym obszarze roboczym przez jego lidera. Zastosowanie tworzy realne
// zadania w wybranej kolumnie (kopia, nie odwołanie — edycja szablonu później
// nie zmienia już utworzonych zadań).

function task_templates_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        VARCHAR(150) NOT NULL,
        description TEXT         NOT NULL DEFAULT '',
        is_active   INTEGER      NOT NULL DEFAULT 1,
        created_by  INTEGER,
        created_at  DATETIME     NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at  DATETIME     NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_template_items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER      NOT NULL REFERENCES task_templates(id) ON DELETE CASCADE,
        title       VARCHAR(255) NOT NULL,
        description TEXT         NOT NULL DEFAULT '',
        priority    INTEGER      NOT NULL DEFAULT 2,
        position    INTEGER      NOT NULL DEFAULT 0
    )");
}

/** Wszystkie szablony (aktywne domyślnie), z liczbą pozycji. */
function task_get_templates(bool $active_only = true): array {
    task_templates_migrate();
    return db_all(
        "SELECT tt.*,
                (SELECT COUNT(*) FROM task_template_items WHERE template_id = tt.id) AS item_count
         FROM task_templates tt"
        . ($active_only ? " WHERE tt.is_active = 1" : "")
        . " ORDER BY tt.name"
    );
}

function task_get_template_items(int $template_id): array {
    task_templates_migrate();
    return db_all(
        "SELECT * FROM task_template_items WHERE template_id = ? ORDER BY position, id",
        [$template_id]
    );
}

/**
 * Tworzy realne zadania w $list_id (obszar $workspace_id) na podstawie pozycji
 * szablonu. Zwraca ID utworzonych zadań. Rzuca RuntimeException gdy lista
 * nie istnieje w tym obszarze.
 */
function task_apply_template(int $template_id, int $workspace_id, int $list_id, int $user_id): array {
    task_templates_migrate();
    $items = task_get_template_items($template_id);
    if (!$items) return [];

    $list = db_one("SELECT * FROM task_lists WHERE id=? AND workspace_id=?", [$list_id, $workspace_id]);
    if (!$list) throw new \RuntimeException('Lista nie istnieje w tym obszarze.');

    $pos = (float)(db_one(
        "SELECT MAX(position) AS m FROM tasks WHERE list_id=? AND deleted_at IS NULL", [$list_id]
    )['m'] ?? 0);
    $now = date('Y-m-d H:i:s');
    $created_ids = [];

    foreach ($items as $item) {
        $pos += 1;
        $id = db_insert('tasks', [
            'workspace_id' => $workspace_id,
            'list_id'      => $list_id,
            'title'        => $item['title'],
            'description'  => $item['description'],
            'position'     => $pos,
            'priority'     => max(1, min(4, (int)$item['priority'])),
            'created_by'   => $user_id,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        task_start_time_tracking($id, $list_id, $list['name']);
        task_log($id, $user_id, 'created', null, $list['name'], ['from_template' => $template_id]);
        $created_ids[] = $id;
    }

    try {
        require_once __DIR__ . '/task_notify.php';
        foreach ($created_ids as $id) task_notify_created($id, $user_id);
    } catch (\Throwable $e) {}

    return $created_ids;
}
