<?php
/**
 * includes/workspaces.php — Serwis modułu Koszulek (Workspace).
 *
 * Koszulki to kontenery plików powiązane z obszarami roboczymi zadań
 * (task_workspaces). Pliki fizycznie przechowywane są na SharePoint;
 * lokalna baza trzyma wyłącznie metadane, uprawnienia i relacje z zadaniami.
 *
 * Wymagania SP: sp_enabled=1, sp_site_url, sp_library (lub sp_workspaces_drive_id).
 * ACL: delegowane do task_workspace_role() — te same role co moduł Zadania.
 */

require_once __DIR__ . '/m365.php';
require_once __DIR__ . '/tasks.php';

// ── Auto-migracja schematu ────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    // ws_folders — koszulki per workspace + metadane SP
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_folders (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id     INTEGER NOT NULL REFERENCES task_workspaces(id) ON DELETE CASCADE,
        name             TEXT    NOT NULL,
        description      TEXT    NOT NULL DEFAULT '',
        provider         TEXT    NOT NULL DEFAULT 'sharepoint',
        remote_folder_id TEXT,
        remote_path      TEXT    NOT NULL DEFAULT '',
        drive_id         TEXT    NOT NULL DEFAULT '',
        created_by       INTEGER REFERENCES users(id),
        created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // ws_files — metadane plików (bajty są w SP)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_files (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_id       INTEGER NOT NULL REFERENCES ws_folders(id) ON DELETE CASCADE,
        workspace_id    INTEGER NOT NULL,
        name            TEXT    NOT NULL,
        original_name   TEXT    NOT NULL DEFAULT '',
        mime_type       TEXT    NOT NULL DEFAULT '',
        file_size       INTEGER NOT NULL DEFAULT 0,
        provider        TEXT    NOT NULL DEFAULT 'sharepoint',
        remote_item_id  TEXT,
        remote_path     TEXT    NOT NULL DEFAULT '',
        drive_id        TEXT    NOT NULL DEFAULT '',
        web_url         TEXT    NOT NULL DEFAULT '',
        uploaded_by     INTEGER REFERENCES users(id),
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        deleted_at      TEXT
    )");

    // ws_task_files — relacja wiele-do-wielu: zadanie ↔ plik
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_task_files (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id   INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
        file_id   INTEGER NOT NULL REFERENCES ws_files(id) ON DELETE CASCADE,
        linked_by INTEGER REFERENCES users(id),
        linked_at TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(task_id, file_id)
    )");

    // Przyrostowe kolumny (bezpieczne ALTER TABLE)
    foreach ([
        "ALTER TABLE ws_folders ADD COLUMN site_id TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE ws_files   ADD COLUMN description TEXT NOT NULL DEFAULT ''",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }
})();

// ── Konfiguracja SP ───────────────────────────────────────────────────────────

/**
 * Zwraca drive_id dla modułu Koszulek.
 * Pierwszeństwo: dedykowane ustawienie sp_workspaces_drive_id,
 * fallback: sp_library → sp_drive_id(sp_site_id(sp_site_url)).
 */
function ws_sp_drive_id(): string {
    static $cache = null;
    if ($cache !== null) return $cache;

    $override = m365_setting('sp_workspaces_drive_id');
    if ($override !== '') {
        return $cache = $override;
    }

    $site_url = m365_setting('sp_site_url');
    if ($site_url === '') {
        throw new \RuntimeException('Brak skonfigurowanego URL witryny SharePoint (sp_site_url).');
    }
    $graph    = new M365Graph();
    $site_id  = $graph->sp_site_id($site_url);
    $library  = m365_setting('sp_library');
    $cache    = $graph->sp_drive_id($site_id, $library);
    return $cache;
}

/**
 * Zwraca folder główny (root path) dla koszulek w SP.
 * Domyślnie "Koszulki", konfigurowalne przez sp_workspaces_root_folder.
 */
function ws_sp_root_folder(): string {
    $v = m365_setting('sp_workspaces_root_folder');
    return $v !== '' ? trim($v, '/') : 'Koszulki';
}

/**
 * Sprawdza, czy moduł Koszulek jest dostępny (SP skonfigurowany).
 */
function ws_available(): bool {
    return m365_setting('sp_enabled') === '1' && m365_setting('sp_site_url') !== '';
}

// ── ACL — delegowane do task_workspace_role ───────────────────────────────────

/**
 * Rola użytkownika w workspace (deleguje do task_workspace_role).
 * Zwraca: 'admin'|'editor'|'member'|'viewer'|null (brak dostępu).
 */
function ws_user_role(int $workspace_id, ?int $user_id = null): ?string {
    return task_workspace_role($workspace_id, $user_id);
}

/**
 * Czy użytkownik może wgrywać pliki (minimum: member).
 */
function ws_can_upload(int $workspace_id, ?int $user_id = null): bool {
    $role = ws_user_role($workspace_id, $user_id);
    return in_array($role, ['admin', 'editor', 'member'], true);
}

/**
 * Czy użytkownik może zarządzać folderami i usuwać pliki (minimum: editor).
 */
function ws_can_manage(int $workspace_id, ?int $user_id = null): bool {
    $role = ws_user_role($workspace_id, $user_id);
    return in_array($role, ['admin', 'editor'], true);
}

/**
 * Wymusza dostęp (min. viewer). HTTP 403 + JSON jeśli brak.
 */
function ws_require_access(int $workspace_id, string $min_role = 'viewer'): void {
    $order = ['viewer' => 1, 'member' => 2, 'editor' => 3, 'admin' => 4];
    $role  = ws_user_role($workspace_id);
    if ($role === null || ($order[$role] ?? 0) < ($order[$min_role] ?? 1)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Brak uprawnień do tego obszaru roboczego.']);
        } else {
            http_response_code(403);
            echo '<p>Brak uprawnień.</p>';
        }
        exit;
    }
}

// ── Operacje folderów ─────────────────────────────────────────────────────────

/**
 * Bezpieczna nazwa folderu SP — ASCII + wybrane znaki.
 */
function ws_sanitize_folder_name(string $name): string {
    // Zamień polskie znaki, usuń znaki niedozwolone w SP
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
    $name = strtr($name, $map);
    // SP nie pozwala na: " * : < > ? / \ | #
    $name = preg_replace('/["*:<>?\/\\\\|#]+/', '-', $name);
    $name = trim($name, '. ');
    return $name ?: 'Folder';
}

/**
 * Tworzy koszulkę (folder) dla danego workspace.
 * Tworzy katalog w SP: {root}/{workspace_slug}/{sanitized_name}
 *
 * @param  int    $workspace_id  ID obszaru roboczego (task_workspaces.id)
 * @param  string $name          Nazwa koszulki (wyświetlana)
 * @param  string $description   Opis (opcjonalny)
 * @param  int|null $user_id     Tworzący (null = aktualny)
 * @return array  ['ok'=>bool, 'folder'=>row|null, 'error'=>string]
 */
function ws_create_folder(int $workspace_id, string $name, string $description = '', ?int $user_id = null): array {
    if (!ws_available()) {
        return ['ok' => false, 'folder' => null, 'error' => 'SharePoint nie jest skonfigurowany.'];
    }

    $workspace = db_one("SELECT id, name, slug FROM task_workspaces WHERE id = ?", [$workspace_id]);
    if (!$workspace) {
        return ['ok' => false, 'folder' => null, 'error' => 'Nie znaleziono obszaru roboczego.'];
    }

    $user_id = $user_id ?? (current_user()['id'] ?? null);

    try {
        $graph    = new M365Graph();
        $drive_id = ws_sp_drive_id();
        $root     = ws_sp_root_folder();

        // Zapewnij katalog roota i katalogu workspace w SP
        $ws_slug     = ws_sanitize_folder_name($workspace['name']);
        $folder_slug = ws_sanitize_folder_name($name);

        $graph->sp_create_folder($drive_id, '',         $root);
        $graph->sp_create_folder($drive_id, $root,      $ws_slug);
        $sp_item = $graph->sp_create_folder($drive_id, "{$root}/{$ws_slug}", $folder_slug);

        $remote_path = "{$root}/{$ws_slug}/{$folder_slug}";

        $id = db_insert('ws_folders', [
            'workspace_id'     => $workspace_id,
            'name'             => trim($name),
            'description'      => trim($description),
            'provider'         => 'sharepoint',
            'remote_folder_id' => $sp_item['id'],
            'remote_path'      => $remote_path,
            'drive_id'         => $drive_id,
            'created_by'       => $user_id,
        ]);

        $folder = db_one("SELECT * FROM ws_folders WHERE id = ?", [$id]);
        return ['ok' => true, 'folder' => $folder, 'error' => ''];

    } catch (\Throwable $e) {
        return ['ok' => false, 'folder' => null, 'error' => $e->getMessage()];
    }
}

function ws_get_folder(int $folder_id): ?array {
    return db_one("SELECT * FROM ws_folders WHERE id = ?", [$folder_id]);
}

function ws_list_folders(int $workspace_id): array {
    return db_all("SELECT * FROM ws_folders WHERE workspace_id = ? ORDER BY name", [$workspace_id]);
}

function ws_delete_folder(int $folder_id): array {
    $folder = ws_get_folder($folder_id);
    if (!$folder) return ['ok' => false, 'error' => 'Nie znaleziono folderu.'];

    // Soft-delete plików (nie usuwamy z SP — dane historyczne)
    db()->prepare("UPDATE ws_files SET deleted_at = datetime('now') WHERE folder_id = ? AND deleted_at IS NULL")
        ->execute([$folder_id]);
    db()->prepare("DELETE FROM ws_folders WHERE id = ?")->execute([$folder_id]);

    return ['ok' => true, 'error' => ''];
}

// ── Operacje plików ───────────────────────────────────────────────────────────

/**
 * Wgrywa plik do koszulki (SP + metadane w DB).
 *
 * @param  int   $folder_id   ID koszulki (ws_folders.id)
 * @param  array $file_upload Jeden wpis z $_FILES['file'] lub ['name','tmp_name','type','size','error']
 * @param  int|null $user_id
 * @return array ['ok'=>bool, 'file'=>row|null, 'error'=>string]
 */
function ws_upload_file(int $folder_id, array $file_upload, ?int $user_id = null): array {
    $folder = ws_get_folder($folder_id);
    if (!$folder) return ['ok' => false, 'file' => null, 'error' => 'Nie znaleziono folderu.'];
    if (!ws_available()) return ['ok' => false, 'file' => null, 'error' => 'SharePoint nie jest skonfigurowany.'];

    $user_id = $user_id ?? (current_user()['id'] ?? null);

    // Walidacja pliku
    $upload_err   = (int)($file_upload['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp_path     = $file_upload['tmp_name'] ?? '';
    $orig_name    = basename($file_upload['name'] ?? '');
    $file_size    = (int)($file_upload['size'] ?? 0);
    $mime         = $file_upload['type'] ?? 'application/octet-stream';

    if ($upload_err !== UPLOAD_ERR_OK || !$tmp_path || !is_uploaded_file($tmp_path)) {
        return ['ok' => false, 'file' => null, 'error' => 'Błąd przesyłania pliku (kod ' . $upload_err . ').'];
    }
    if ($file_size > 500 * 1024 * 1024) {
        return ['ok' => false, 'file' => null, 'error' => 'Plik przekracza limit 500 MB.'];
    }
    $allowed_ext = ['pdf','docx','doc','xlsx','xls','pptx','ppt','odt','ods','odp',
                    'txt','csv','jpg','jpeg','png','gif','webp','zip','7z','tar','gz',
                    'msg','eml','mp4','mov','avi','mkv'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return ['ok' => false, 'file' => null, 'error' => "Typ pliku .{$ext} nie jest dozwolony."];
    }

    // Unikalna nazwa w SP (zachowaj oryginalną, dodaj timestamp jeśli kolizja)
    $sp_filename = ws_sanitize_folder_name(pathinfo($orig_name, PATHINFO_FILENAME))
                 . '_' . date('YmdHis')
                 . '.' . $ext;

    try {
        $graph    = new M365Graph();
        $drive_id = $folder['drive_id'] ?: ws_sp_drive_id();
        $sp_item  = $graph->sp_upload_to_folder($drive_id, $folder['remote_folder_id'], $sp_filename, $tmp_path);

        if (empty($sp_item['id'])) {
            return ['ok' => false, 'file' => null, 'error' => 'SharePoint nie zwrócił ID po wgraniu pliku.'];
        }

        $id = db_insert('ws_files', [
            'folder_id'      => $folder_id,
            'workspace_id'   => $folder['workspace_id'],
            'name'           => $orig_name,
            'original_name'  => $orig_name,
            'mime_type'      => $mime,
            'file_size'      => $sp_item['size'] ?? $file_size,
            'provider'       => 'sharepoint',
            'remote_item_id' => $sp_item['id'],
            'remote_path'    => $folder['remote_path'] . '/' . $sp_filename,
            'drive_id'       => $drive_id,
            'web_url'        => $sp_item['webUrl'] ?? '',
            'uploaded_by'    => $user_id,
        ]);

        $file = db_one("SELECT * FROM ws_files WHERE id = ?", [$id]);
        return ['ok' => true, 'file' => $file, 'error' => ''];

    } catch (\Throwable $e) {
        return ['ok' => false, 'file' => null, 'error' => $e->getMessage()];
    }
}

function ws_get_file(int $file_id): ?array {
    return db_one("SELECT * FROM ws_files WHERE id = ? AND deleted_at IS NULL", [$file_id]);
}

function ws_list_files(int $folder_id): array {
    return db_all(
        "SELECT f.*, u.name AS uploader_name
         FROM ws_files f
         LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE f.folder_id = ? AND f.deleted_at IS NULL
         ORDER BY f.created_at DESC",
        [$folder_id]
    );
}

function ws_delete_file(int $file_id, bool $hard_delete_sp = false): array {
    $file = ws_get_file($file_id);
    if (!$file) return ['ok' => false, 'error' => 'Nie znaleziono pliku.'];

    // Usuń powiązania z zadaniami
    db()->prepare("DELETE FROM ws_task_files WHERE file_id = ?")->execute([$file_id]);

    // Soft delete w DB
    db()->prepare("UPDATE ws_files SET deleted_at = datetime('now') WHERE id = ?")->execute([$file_id]);

    if ($hard_delete_sp && $file['remote_item_id'] && ws_available()) {
        try {
            $graph    = new M365Graph();
            $drive_id = $file['drive_id'] ?: ws_sp_drive_id();
            $graph->sp_delete_item($drive_id, $file['remote_item_id']);
        } catch (\Throwable $e) {
            // non-fatal — lokalny soft-delete wystarczy
        }
    }

    return ['ok' => true, 'error' => ''];
}

// ── Powiązania plików z zadaniami ─────────────────────────────────────────────

/**
 * Przypisuje plik do zadania.
 * Weryfikuje, że plik i zadanie należą do tego samego workspace.
 */
function ws_link_file_to_task(int $file_id, int $task_id, ?int $user_id = null): array {
    $file = ws_get_file($file_id);
    if (!$file) return ['ok' => false, 'error' => 'Nie znaleziono pliku.'];

    $task = db_one("SELECT id, workspace_id FROM tasks WHERE id = ? AND deleted_at IS NULL", [$task_id]);
    if (!$task) return ['ok' => false, 'error' => 'Nie znaleziono zadania.'];

    if ((int)$task['workspace_id'] !== (int)$file['workspace_id']) {
        return ['ok' => false, 'error' => 'Plik i zadanie należą do różnych obszarów roboczych.'];
    }

    $user_id = $user_id ?? (current_user()['id'] ?? null);

    try {
        db()->prepare(
            "INSERT OR IGNORE INTO ws_task_files (task_id, file_id, linked_by) VALUES (?, ?, ?)"
        )->execute([$task_id, $file_id, $user_id]);
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function ws_unlink_file_from_task(int $file_id, int $task_id): array {
    db()->prepare("DELETE FROM ws_task_files WHERE file_id = ? AND task_id = ?")->execute([$file_id, $task_id]);
    return ['ok' => true, 'error' => ''];
}

/**
 * Zwraca pliki powiązane z zadaniem (z metadanymi).
 */
function ws_files_for_task(int $task_id): array {
    return db_all(
        "SELECT f.*, tf.linked_at, u.name AS uploader_name
         FROM ws_task_files tf
         JOIN ws_files f ON f.id = tf.file_id
         LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE tf.task_id = ? AND f.deleted_at IS NULL
         ORDER BY tf.linked_at DESC",
        [$task_id]
    );
}

/**
 * Zwraca zadania powiązane z plikiem.
 */
function ws_tasks_for_file(int $file_id): array {
    return db_all(
        "SELECT t.id, t.title, t.workspace_id, tl.name AS list_name, tf.linked_at
         FROM ws_task_files tf
         JOIN tasks t ON t.id = tf.task_id
         LEFT JOIN task_lists tl ON tl.id = t.list_id
         WHERE tf.file_id = ? AND t.deleted_at IS NULL
         ORDER BY tf.linked_at DESC",
        [$file_id]
    );
}

// ── Proxy / bezpieczne pobieranie ─────────────────────────────────────────────

/**
 * Weryfikuje uprawnienia i strumieniuje plik z SharePoint do klienta HTTP.
 * NIGDY nie należy dawać użytkownikom bezpośredniego URL do SP.
 *
 * @param  int      $file_id  ws_files.id
 * @param  int|null $user_id  Pobierający (null = aktualny)
 */
function ws_proxy_download(int $file_id, ?int $user_id = null, bool $inline = false): void {
    $file = ws_get_file($file_id);
    if (!$file) {
        http_response_code(404);
        echo 'Plik nie został znaleziony.';
        exit;
    }

    // Weryfikacja dostępu przez workspace
    $role = ws_user_role((int)$file['workspace_id'], $user_id);
    if ($role === null) {
        http_response_code(403);
        echo 'Brak uprawnień do tego pliku.';
        exit;
    }

    if (!ws_available()) {
        http_response_code(503);
        echo 'Usługa SharePoint niedostępna.';
        exit;
    }

    if (!$file['remote_item_id']) {
        http_response_code(404);
        echo 'Plik nie ma przypisanego ID w SharePoint.';
        exit;
    }

    try {
        $graph    = new M365Graph();
        $drive_id = $file['drive_id'] ?: ws_sp_drive_id();
        $bytes    = $graph->sp_download_file($drive_id, $file['remote_item_id']);

        $filename = $file['original_name'] ?: $file['name'] ?: 'plik';
        $mime     = $file['mime_type'] ?: 'application/octet-stream';

        $disp = $inline ? 'inline' : 'attachment';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . $disp . '; filename="' . addslashes($filename) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        echo $bytes;
        exit;

    } catch (\Throwable $e) {
        http_response_code(502);
        echo 'Błąd pobierania z SharePoint: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

// ── Listowanie workspaces per użytkownik ──────────────────────────────────────

/**
 * Zwraca workspace'y, do których bieżący użytkownik ma dostęp (z rolą),
 * razem z licznikiem folderów i plików.
 */
function ws_list_user_workspaces(?int $user_id = null): array {
    $user_id = $user_id ?? (current_user()['id'] ?? 0);
    if (!$user_id) return [];

    $uid = (int)$user_id;

    // Admin systemowy widzi wszystko
    if (is_admin()) {
        return db_all(
            "SELECT tw.id, tw.name, tw.color, tw.icon, tw.slug, 'admin' AS ws_role,
                    (SELECT COUNT(*) FROM ws_folders wf WHERE wf.workspace_id = tw.id) AS folder_count,
                    (SELECT COUNT(*) FROM ws_files f
                       JOIN ws_folders wf2 ON wf2.id = f.folder_id
                      WHERE wf2.workspace_id = tw.id AND f.deleted_at IS NULL) AS file_count
             FROM task_workspaces tw
             WHERE tw.is_active = 1
             ORDER BY tw.name"
        );
    }

    return db_all(
        "SELECT tw.id, tw.name, tw.color, tw.icon, tw.slug, twm.role AS ws_role,
                (SELECT COUNT(*) FROM ws_folders wf WHERE wf.workspace_id = tw.id) AS folder_count,
                (SELECT COUNT(*) FROM ws_files f
                   JOIN ws_folders wf2 ON wf2.id = f.folder_id
                  WHERE wf2.workspace_id = tw.id AND f.deleted_at IS NULL) AS file_count
         FROM task_workspace_members twm
         JOIN task_workspaces tw ON tw.id = twm.workspace_id
         WHERE twm.user_id = ? AND tw.is_active = 1
         ORDER BY tw.name",
        [$uid]
    );
}

/**
 * Formatuje rozmiar pliku czytelnie dla użytkownika.
 */
function ws_format_size(int $bytes): string {
    if ($bytes < 1024)           return $bytes . ' B';
    if ($bytes < 1024 * 1024)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 ** 3)      return round($bytes / 1024 / 1024, 1) . ' MB';
    return round($bytes / 1024 ** 3, 2) . ' GB';
}
