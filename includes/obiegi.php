<?php
/**
 * includes/obiegi.php — Micro-BPM: uniwersalne obiegi (procesy) z automatycznym
 * przekazywaniem i zatwierdzaniem.
 *
 * Model:
 *   obieg_definitions      — typ obiegu (np. „Wniosek urlopowy", „Zakup")
 *   obieg_def_steps        — kroki definicji; każdy krok przypisany do ROLI
 *   obieg_requests         — konkretny wniosek (instancja obiegu)
 *   obieg_actions          — dziennik decyzji/przekazań (audyt)
 *
 * Zasada działania: wniosek startuje na kroku 1. Zatwierdzenie kroku
 * automatycznie przekazuje wniosek do kolejnego kroku (do roli tego kroku)
 * i powiadamia jej członków. Po ostatnim kroku wniosek jest zatwierdzony.
 * Odrzucenie na dowolnym kroku kończy obieg (odrzucony).
 *
 * Uprawnienia: krok jest przypisany do roli — działać na kroku może dowolny
 * aktywny użytkownik tej roli (oraz administrator). Zob. [[project_user_modules]].
 *
 * Wymaga: db.php, functions.php, auth.php, permissions.php, mail_queue.php.
 * Samonaprawa schematu na starcie (wzorzec *_schema.php).
 */

// ── Stałe / metadane statusów ─────────────────────────────────────────────────

const OBIEG_STATUSES = [
    'w_toku'      => ['label' => 'W toku',       'class' => 'warning'],
    'zatwierdzony'=> ['label' => 'Zatwierdzony', 'class' => 'success'],
    'odrzucony'   => ['label' => 'Odrzucony',    'class' => 'danger'],
    'wycofany'    => ['label' => 'Wycofany',     'class' => 'secondary'],
];

function obieg_status_badge(string $status): string {
    $s = OBIEG_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

// ── Inicjalizacja / samonaprawa schematu ──────────────────────────────────────

function obiegi_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS obieg_definitions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        icon        TEXT    NOT NULL DEFAULT 'bi-diagram-2',
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS obieg_def_steps (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        definition_id INTEGER NOT NULL REFERENCES obieg_definitions(id) ON DELETE CASCADE,
        step_order    INTEGER NOT NULL DEFAULT 1,
        name          TEXT    NOT NULL,
        role_name     TEXT    NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS obieg_requests (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        definition_id     INTEGER NOT NULL,
        title             TEXT    NOT NULL,
        body              TEXT    NOT NULL DEFAULT '',
        submitted_by      INTEGER NOT NULL,
        submitted_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        current_step_order INTEGER NOT NULL DEFAULT 1,
        status            TEXT    NOT NULL DEFAULT 'w_toku',
        completed_at      TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS obieg_actions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id  INTEGER NOT NULL REFERENCES obieg_requests(id) ON DELETE CASCADE,
        step_order  INTEGER NOT NULL DEFAULT 0,
        step_name   TEXT    NOT NULL DEFAULT '',
        role_name   TEXT    NOT NULL DEFAULT '',
        action      TEXT    NOT NULL,
        actor_id    INTEGER,
        note        TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    // Pliki wniosku — wgrane bezpośrednio (source='upload') lub wskazane
    // z repozytorium koszulki EZD (source='ezd', ezd_zalacznik_id).
    $db->exec("CREATE TABLE IF NOT EXISTS obieg_files (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id       INTEGER NOT NULL REFERENCES obieg_requests(id) ON DELETE CASCADE,
        source           TEXT    NOT NULL DEFAULT 'upload',
        path             TEXT    NOT NULL DEFAULT '',
        original_name    TEXT    NOT NULL DEFAULT '',
        mime_type        TEXT    NOT NULL DEFAULT '',
        file_size        INTEGER NOT NULL DEFAULT 0,
        ezd_zalacznik_id INTEGER,
        uploaded_by      INTEGER,
        created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Samonaprawa schematu — kolumny dodane po pierwszym wdrożeniu.
    foreach ([
        "ALTER TABLE obieg_def_steps ADD COLUMN assignee_type TEXT NOT NULL DEFAULT 'role'",
        "ALTER TABLE obieg_def_steps ADD COLUMN user_id INTEGER",
        "ALTER TABLE obieg_requests ADD COLUMN ezd_sprawa_id INTEGER",
    ] as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) {}
    }

    $db->exec("CREATE INDEX IF NOT EXISTS idx_obieg_req_status ON obieg_requests(status, current_step_order)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_obieg_act_req ON obieg_actions(request_id, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_obieg_files_req ON obieg_files(request_id, id)");
}

// ── Definicje (typy obiegów) ──────────────────────────────────────────────────

function obiegi_definitions(bool $active_only = false): array {
    obiegi_init();
    $where = $active_only ? "WHERE d.is_active = 1" : "";
    return db_all(
        "SELECT d.*,
                (SELECT COUNT(*) FROM obieg_def_steps s WHERE s.definition_id = d.id) AS step_count
         FROM obieg_definitions d {$where} ORDER BY d.name COLLATE NOCASE"
    );
}

function obiegi_definition(int $id): ?array {
    obiegi_init();
    return db_one("SELECT * FROM obieg_definitions WHERE id = ?", [$id]);
}

function obiegi_def_steps(int $definition_id): array {
    obiegi_init();
    return db_all(
        "SELECT * FROM obieg_def_steps WHERE definition_id = ? ORDER BY step_order, id",
        [$definition_id]
    );
}

/** Krok danej definicji o podanej pozycji (1-based). */
function obiegi_step_at(int $definition_id, int $step_order): ?array {
    $steps = obiegi_def_steps($definition_id);
    return $steps[$step_order - 1] ?? null;
}

function obiegi_def_create(string $name, string $desc, string $icon, int $by): int {
    obiegi_init();
    return db_insert('obieg_definitions', [
        'name'        => $name,
        'description' => $desc,
        'icon'        => $icon ?: 'bi-diagram-2',
        'is_active'   => 1,
        'created_by'  => $by,
    ]);
}

function obiegi_def_update(int $id, string $name, string $desc, string $icon, int $is_active): void {
    obiegi_init();
    db()->prepare(
        "UPDATE obieg_definitions SET name=?, description=?, icon=?, is_active=? WHERE id=?"
    )->execute([$name, $desc, $icon ?: 'bi-diagram-2', $is_active ? 1 : 0, $id]);
}

function obiegi_def_delete(int $id): void {
    obiegi_init();
    db()->prepare("DELETE FROM obieg_definitions WHERE id=?")->execute([$id]);
    db()->prepare("DELETE FROM obieg_def_steps WHERE definition_id=?")->execute([$id]);
}

/**
 * Zapisuje komplet kroków definicji (zastępuje istniejące).
 * $steps = [['name'=>..., 'assignee_type'=>'role'|'user', 'role_name'=>..., 'user_id'=>...], ...].
 * Krok przypisany do roli wymaga role_name; przypisany do osoby wymaga user_id.
 */
function obiegi_def_save_steps(int $definition_id, array $steps): void {
    obiegi_init();
    $db = db();
    $db->prepare("DELETE FROM obieg_def_steps WHERE definition_id=?")->execute([$definition_id]);
    $ins = $db->prepare(
        "INSERT INTO obieg_def_steps (definition_id, step_order, name, assignee_type, role_name, user_id)
         VALUES (?,?,?,?,?,?)"
    );
    $order = 1;
    foreach ($steps as $s) {
        $name = trim((string)($s['name'] ?? ''));
        $type = ($s['assignee_type'] ?? 'role') === 'user' ? 'user' : 'role';
        $role = trim((string)($s['role_name'] ?? ''));
        $uid  = (int)($s['user_id'] ?? 0);
        if ($name === '') continue;
        if ($type === 'user') {
            if ($uid <= 0) continue;
            $ins->execute([$definition_id, $order++, $name, 'user', '', $uid]);
        } else {
            if ($role === '') continue;
            $ins->execute([$definition_id, $order++, $name, 'role', $role, null]);
        }
    }
}

// ── Wnioski (instancje) ───────────────────────────────────────────────────────

function obiegi_request_get(int $id): ?array {
    obiegi_init();
    return db_one(
        "SELECT r.*, d.name AS def_name, d.icon AS def_icon,
                u.name AS submitter_name, u.email AS submitter_email
         FROM obieg_requests r
         JOIN obieg_definitions d ON d.id = r.definition_id
         LEFT JOIN users u ON u.id = r.submitted_by
         WHERE r.id = ?",
        [$id]
    );
}

function obiegi_request_actions(int $request_id): array {
    obiegi_init();
    return db_all(
        "SELECT a.*, u.name AS actor_name
         FROM obieg_actions a LEFT JOIN users u ON u.id = a.actor_id
         WHERE a.request_id = ? ORDER BY a.id",
        [$request_id]
    );
}

/** Bieżący krok wniosku (definicyjny) lub null, gdy poza zakresem. */
function obiegi_current_step(array $request): ?array {
    if ($request['status'] !== 'w_toku') return null;
    return obiegi_step_at((int)$request['definition_id'], (int)$request['current_step_order']);
}

/**
 * Uruchamia nowy obieg dla definicji. Loguje złożenie i powiadamia adresata kroku 1.
 * Opcjonalnie wiąże wniosek z koszulką EZD ($ezd_sprawa_id).
 * Zwraca id wniosku. Rzuca wyjątek, gdy definicja nie ma kroków.
 */
function obiegi_request_start(int $definition_id, string $title, string $body, int $by, ?int $ezd_sprawa_id = null): int {
    obiegi_init();
    $def = obiegi_definition($definition_id);
    if (!$def || !$def['is_active']) throw new \RuntimeException('Nieprawidłowy typ obiegu.');
    $steps = obiegi_def_steps($definition_id);
    if (!$steps) throw new \RuntimeException('Ten obieg nie ma zdefiniowanych kroków.');

    $rid = db_insert('obieg_requests', [
        'definition_id'      => $definition_id,
        'title'              => $title,
        'body'               => $body,
        'submitted_by'       => $by,
        'current_step_order' => 1,
        'status'             => 'w_toku',
        'ezd_sprawa_id'      => $ezd_sprawa_id ?: null,
    ]);

    $first = $steps[0];
    obiegi_log($rid, 0, '', '', 'submit', $by, '');
    obiegi_notify_step($rid, $first, $def, $title);
    return $rid;
}

/** Czy użytkownik może działać na bieżącym kroku wniosku. */
function obiegi_can_act(array $request, array $user): bool {
    if ($request['status'] !== 'w_toku') return false;
    $step = obiegi_current_step($request);
    if (!$step) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    if (($step['assignee_type'] ?? 'role') === 'user') {
        return (int)($user['id'] ?? 0) === (int)($step['user_id'] ?? 0);
    }
    return ($user['role'] ?? '') === $step['role_name'];
}

/** Czytelna etykieta adresata kroku (osoba lub rola). */
function obiegi_step_assignee_label(array $step): string {
    if (($step['assignee_type'] ?? 'role') === 'user') {
        $u = db_one("SELECT name, email FROM users WHERE id=?", [(int)($step['user_id'] ?? 0)]);
        return $u ? ('👤 ' . ($u['name'] ?: $u['email'])) : '👤 (nieznana osoba)';
    }
    $r = db_one("SELECT display_name FROM roles WHERE name=?", [(string)($step['role_name'] ?? '')]);
    return ($r['display_name'] ?? '') ?: (string)($step['role_name'] ?? '');
}

/**
 * Zatwierdza bieżący krok. Jeśli są kolejne kroki — automatycznie przekazuje
 * (podbija current_step_order i powiadamia następną rolę). Po ostatnim kroku
 * oznacza wniosek jako zatwierdzony i powiadamia wnioskodawcę.
 */
function obiegi_approve(int $request_id, int $actor_id, string $note = ''): void {
    obiegi_init();
    $req = obiegi_request_get($request_id);
    if (!$req || $req['status'] !== 'w_toku') throw new \RuntimeException('Wniosek nie jest w toku.');

    $steps   = obiegi_def_steps((int)$req['definition_id']);
    $curOrd  = (int)$req['current_step_order'];
    $current = $steps[$curOrd - 1] ?? null;
    if (!$current) throw new \RuntimeException('Nie znaleziono bieżącego kroku.');

    obiegi_log($request_id, $curOrd, $current['name'], $current['role_name'], 'approve', $actor_id, $note);

    $next = $steps[$curOrd] ?? null;
    if ($next) {
        // Automatyczne przekazanie do kolejnego kroku
        db()->prepare("UPDATE obieg_requests SET current_step_order=? WHERE id=?")
            ->execute([$curOrd + 1, $request_id]);
        obiegi_log($request_id, $curOrd + 1, $next['name'], $next['role_name'], 'forward', $actor_id, '');
        $def = obiegi_definition((int)$req['definition_id']);
        obiegi_notify_step($request_id, $next, $def, $req['title']);
    } else {
        // Ostatni krok — zatwierdzony
        db()->prepare("UPDATE obieg_requests SET status='zatwierdzony', completed_at=datetime('now') WHERE id=?")
            ->execute([$request_id]);
        obiegi_notify_submitter($req, 'zatwierdzony', $note);
    }
}

/** Odrzuca wniosek na bieżącym kroku — kończy obieg. */
function obiegi_reject(int $request_id, int $actor_id, string $note = ''): void {
    obiegi_init();
    $req = obiegi_request_get($request_id);
    if (!$req || $req['status'] !== 'w_toku') throw new \RuntimeException('Wniosek nie jest w toku.');

    $curOrd  = (int)$req['current_step_order'];
    $current = obiegi_step_at((int)$req['definition_id'], $curOrd);
    obiegi_log($request_id, $curOrd, $current['name'] ?? '', $current['role_name'] ?? '', 'reject', $actor_id, $note);
    db()->prepare("UPDATE obieg_requests SET status='odrzucony', completed_at=datetime('now') WHERE id=?")
        ->execute([$request_id]);
    obiegi_notify_submitter($req, 'odrzucony', $note);
}

/** Wnioskodawca wycofuje własny wniosek (tylko gdy w toku). */
function obiegi_withdraw(int $request_id, int $actor_id): void {
    obiegi_init();
    $req = obiegi_request_get($request_id);
    if (!$req || $req['status'] !== 'w_toku') throw new \RuntimeException('Wniosek nie jest w toku.');
    if ((int)$req['submitted_by'] !== $actor_id) throw new \RuntimeException('Tylko wnioskodawca może wycofać wniosek.');
    obiegi_log($request_id, (int)$req['current_step_order'], '', '', 'withdraw', $actor_id, '');
    db()->prepare("UPDATE obieg_requests SET status='wycofany', completed_at=datetime('now') WHERE id=?")
        ->execute([$request_id]);
}

function obiegi_log(int $request_id, int $step_order, string $step_name, string $role_name, string $action, ?int $actor_id, string $note): void {
    db_insert('obieg_actions', [
        'request_id' => $request_id,
        'step_order' => $step_order,
        'step_name'  => $step_name,
        'role_name'  => $role_name,
        'action'     => $action,
        'actor_id'   => $actor_id ?: null,
        'note'       => $note,
    ]);
}

// ── Listy dla użytkownika ─────────────────────────────────────────────────────

/** Wnioski oczekujące na decyzję danego użytkownika (jego rola = rola bieżącego kroku). */
function obiegi_inbox(array $user): array {
    obiegi_init();
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        // Admin widzi wszystkie w toku
        return db_all(
            "SELECT r.*, d.name AS def_name, d.icon AS def_icon, u.name AS submitter_name
             FROM obieg_requests r
             JOIN obieg_definitions d ON d.id = r.definition_id
             LEFT JOIN users u ON u.id = r.submitted_by
             WHERE r.status = 'w_toku' ORDER BY r.submitted_at DESC"
        );
    }
    return db_all(
        "SELECT r.*, d.name AS def_name, d.icon AS def_icon, u.name AS submitter_name
         FROM obieg_requests r
         JOIN obieg_definitions d ON d.id = r.definition_id
         LEFT JOIN users u ON u.id = r.submitted_by
         JOIN obieg_def_steps s ON s.definition_id = r.definition_id AND s.step_order = r.current_step_order
         WHERE r.status = 'w_toku'
           AND ( (COALESCE(s.assignee_type,'role')='role' AND s.role_name = ?)
              OR (s.assignee_type='user' AND s.user_id = ?) )
         ORDER BY r.submitted_at DESC",
        [$role, (int)($user['id'] ?? 0)]
    );
}

/** Wnioski złożone przez użytkownika. */
function obiegi_my_requests(int $user_id): array {
    obiegi_init();
    return db_all(
        "SELECT r.*, d.name AS def_name, d.icon AS def_icon
         FROM obieg_requests r
         JOIN obieg_definitions d ON d.id = r.definition_id
         WHERE r.submitted_by = ? ORDER BY r.submitted_at DESC",
        [$user_id]
    );
}

/** Liczba wniosków czekających na decyzję użytkownika (badge w menu). */
function obiegi_inbox_count(array $user): int {
    try {
        $role = (string)($user['role'] ?? '');
        if ($role === 'admin') {
            $r = db_one("SELECT COUNT(*) c FROM obieg_requests WHERE status='w_toku'");
        } else {
            $r = db_one(
                "SELECT COUNT(*) c FROM obieg_requests r
                 JOIN obieg_def_steps s ON s.definition_id = r.definition_id AND s.step_order = r.current_step_order
                 WHERE r.status='w_toku'
                   AND ( (COALESCE(s.assignee_type,'role')='role' AND s.role_name = ?)
                      OR (s.assignee_type='user' AND s.user_id = ?) )",
                [$role, (int)($user['id'] ?? 0)]
            );
        }
        return (int)($r['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

// ── Powiadomienia ─────────────────────────────────────────────────────────────

/** Aktywni użytkownicy danej roli. */
function obiegi_role_users(string $role_name): array {
    obiegi_init();
    return db_all(
        "SELECT id, name, email FROM users
         WHERE role = ? AND is_active = 1 AND user_status = 'active' AND email <> ''",
        [$role_name]
    );
}

/** Adresaci kroku do powiadomienia: członkowie roli albo wskazana osoba. */
function obiegi_step_recipients(array $step): array {
    if (($step['assignee_type'] ?? 'role') === 'user') {
        $u = db_one(
            "SELECT id, name, email FROM users WHERE id=? AND is_active=1 AND user_status='active' AND email<>''",
            [(int)($step['user_id'] ?? 0)]
        );
        return $u ? [$u] : [];
    }
    return obiegi_role_users((string)($step['role_name'] ?? ''));
}

/** Powiadamia mailem adresata danego kroku, że czeka na niego decyzja. */
function obiegi_notify_step(int $request_id, array $step, ?array $def, string $title): void {
    if (!function_exists('mail_queue_add')) return;
    $users = obiegi_step_recipients($step);
    if (!$users) return;
    $url  = rtrim(APP_URL, '/') . '/obiegi/view.php?id=' . $request_id;
    $dname = $def['name'] ?? 'Obieg';
    $subject = 'Do zatwierdzenia: ' . $title . ' (' . $dname . ')';
    $html = '<p>Oczekuje na Twoją decyzję wniosek w obiegu <strong>' . h($dname) . '</strong>.</p>'
          . '<p><strong>Wniosek:</strong> ' . h($title) . '<br>'
          . '<strong>Krok:</strong> ' . h($step['name']) . '</p>'
          . '<p><a href="' . h($url) . '">Otwórz wniosek i podejmij decyzję</a></p>';
    foreach ($users as $u) {
        try {
            mail_queue_add($u['email'], $u['name'] ?: $u['email'], $subject, $html, '', 'obieg', $request_id);
        } catch (\Throwable $e) {}
    }
}

/** Powiadamia wnioskodawcę o zakończeniu obiegu. */
function obiegi_notify_submitter(array $req, string $status, string $note): void {
    if (!function_exists('mail_queue_add')) return;
    $email = (string)($req['submitter_email'] ?? '');
    if ($email === '') return;
    $url = rtrim(APP_URL, '/') . '/obiegi/view.php?id=' . (int)$req['id'];
    $lbl = OBIEG_STATUSES[$status]['label'] ?? $status;
    $subject = 'Twój wniosek został ' . mb_strtolower($lbl) . ': ' . $req['title'];
    $html = '<p>Twój wniosek <strong>' . h($req['title']) . '</strong> (' . h($req['def_name'] ?? '') . ') '
          . 'otrzymał status: <strong>' . h($lbl) . '</strong>.</p>';
    if (trim($note) !== '') {
        $html .= '<p><strong>Uwagi:</strong><br>' . nl2br(h($note)) . '</p>';
    }
    $html .= '<p><a href="' . h($url) . '">Zobacz szczegóły</a></p>';
    try {
        mail_queue_add($email, $req['submitter_name'] ?: $email, $subject, $html, '', 'obieg', (int)$req['id']);
    } catch (\Throwable $e) {}
}

// ── Pliki wniosku ─────────────────────────────────────────────────────────────

const OBIEG_UPLOAD_EXTS = ['pdf','jpg','jpeg','png','docx','xlsx','doc','xls','odt','txt'];

function obiegi_files(int $request_id): array {
    obiegi_init();
    return db_all(
        "SELECT f.*, u.name AS uploader FROM obieg_files f
         LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE f.request_id = ? ORDER BY f.id",
        [$request_id]
    );
}

function obiegi_file_get(int $id): ?array {
    obiegi_init();
    return db_one("SELECT * FROM obieg_files WHERE id = ?", [$id]);
}

/**
 * Wgrywa plik z $_FILES[$field] jako załącznik wniosku (source='upload').
 * Zwraca id rekordu lub null. Waliduje rozszerzenie i rozmiar (≤20 MB).
 */
function obiegi_add_upload(int $request_id, string $field, int $user_id): ?int {
    obiegi_init();
    if (empty($_FILES[$field]['tmp_name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $f    = $_FILES[$field];
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, OBIEG_UPLOAD_EXTS, true)) throw new \RuntimeException('Niedozwolony typ pliku: .' . $ext);
    if ($f['size'] > 20 * 1024 * 1024) throw new \RuntimeException('Plik jest za duży (maks. 20 MB).');

    $rel = 'obiegi/' . $request_id;
    $dir = UPLOAD_DIR . $rel . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) throw new \RuntimeException('Nie udało się zapisać pliku.');

    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . $stored) ?: '') : '';
    return db_insert('obieg_files', [
        'request_id'    => $request_id,
        'source'        => 'upload',
        'path'          => $rel . '/' . $stored,
        'original_name' => mb_substr($f['name'], 0, 255),
        'mime_type'     => $mime,
        'file_size'     => (int)($f['size'] ?? filesize($dir . $stored) ?: 0),
        'uploaded_by'   => $user_id,
    ]);
}

/**
 * Dołącza do wniosku istniejący plik z repozytorium koszulki EZD (source='ezd').
 * Wymaga modułu EZD; kopiuje metadane z ezd_zalaczniki. Zwraca id lub null.
 */
function obiegi_add_ezd_file(int $request_id, int $zalacznik_id, int $user_id): ?int {
    obiegi_init();
    if (!function_exists('ezd_zal_get')) {
        @require_once __DIR__ . '/ezd.php';
    }
    if (!function_exists('ezd_zal_get')) return null;
    $z = ezd_zal_get($zalacznik_id);
    if (!$z) return null;
    // unikaj duplikatu tego samego pliku
    $dup = db_one("SELECT id FROM obieg_files WHERE request_id=? AND source='ezd' AND ezd_zalacznik_id=?", [$request_id, $zalacznik_id]);
    if ($dup) return (int)$dup['id'];
    return db_insert('obieg_files', [
        'request_id'       => $request_id,
        'source'           => 'ezd',
        'path'             => '',
        'original_name'    => mb_substr((string)$z['original_name'], 0, 255),
        'mime_type'        => (string)($z['mime_type'] ?? ''),
        'file_size'        => (int)($z['file_size'] ?? 0),
        'ezd_zalacznik_id' => $zalacznik_id,
        'uploaded_by'      => $user_id,
    ]);
}

function obiegi_file_delete(int $id): void {
    obiegi_init();
    $f = obiegi_file_get($id);
    if (!$f) return;
    if ($f['source'] === 'upload' && $f['path']) {
        $abs = UPLOAD_DIR . $f['path'];
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare("DELETE FROM obieg_files WHERE id=?")->execute([$id]);
}

/** Powiązana koszulka EZD wniosku (lub null). */
function obiegi_request_sprawa(array $request): ?array {
    $sid = (int)($request['ezd_sprawa_id'] ?? 0);
    if ($sid <= 0) return null;
    if (!function_exists('ezd_sprawa_get')) {
        @require_once __DIR__ . '/ezd.php';
    }
    if (!function_exists('ezd_sprawa_get')) return null;
    try { return ezd_sprawa_get($sid); } catch (\Throwable $e) { return null; }
}

/** Etykieta akcji dziennika (dla osi czasu). */
function obiegi_action_label(string $action): array {
    return [
        'submit'   => ['Złożenie wniosku',        'bi-send',          'primary'],
        'forward'  => ['Przekazano do kroku',     'bi-arrow-right-circle', 'info'],
        'approve'  => ['Zatwierdzenie kroku',     'bi-check-circle',  'success'],
        'reject'   => ['Odrzucenie',              'bi-x-circle',      'danger'],
        'withdraw' => ['Wycofanie przez wnioskodawcę', 'bi-arrow-counterclockwise', 'secondary'],
    ][$action] ?? [$action, 'bi-dot', 'secondary'];
}
