<?php
/**
 * workspaces/api.php — AJAX endpoint modułu Koszulek.
 *
 * GET  ?action=download&id=N&_csrf=TOKEN  → proxy pobierania z SP
 * POST JSON {action, ...}                 → operacje mutacji
 *
 * Akcje POST: create_folder, upload_file, delete_file, delete_folder,
 *             link_task, unlink_task, files_for_task
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/workspaces.php';

require_login();

// ── Proxy pobierania i podglądu (GET) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_GET['action'] ?? '', ['download','preview'])) {
    $token = $_GET['_csrf'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        echo 'Nieprawidłowy token CSRF.';
        exit;
    }
    if (!ws_available()) {                                  // bez chmury plików nie ma skąd pobrać pliku
        http_response_code(503);
        echo 'Pobieranie i podgląd plików są zablokowane. ' . ws_unavailable_reason() . ' Poproś administratora o podłączenie chmury plików.';
        exit;
    }
    $file_id = (int)($_GET['id'] ?? 0);
    $inline  = ($_GET['action'] === 'preview');
    $_wf     = ws_get_file($file_id);
    if ($_wf && !ws_can_file('download', $_wf)) {   // pliki zadań — wszyscy w obszarze; foldery wspólne — wg macierzy
        http_response_code(403);
        echo 'Brak uprawnienia do pobierania plików w tym obszarze.';
        exit;
    }
    ws_proxy_download($file_id, current_user()['id'] ?? null, $inline);
    exit;
}

// ── Obsługa POST ──────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// Multipart upload (upload_file) — dane z $_POST, plik z $_FILES
if (!empty($_FILES['file'])) {
    $action = $_POST['action'] ?? '';
    $csrf   = $_POST['_csrf'] ?? '';
} else {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $csrf   = $body['_csrf']  ?? '';
}

if (!hash_equals(csrf_token(), $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']);
    exit;
}

$user    = current_user();
$user_id = (int)($user['id'] ?? 0);

// Akcje na plikach i folderach wymagają chmury plików (powiązania plik–zadanie i listy to czysta baza — działają zawsze)
if (in_array($action, ['create_folder', 'upload_file', 'delete_file', 'delete_folder', 'ensure_task_folder'], true) && !ws_available()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'blocked' => true,
        'error' => 'Operacje na plikach i folderach są zablokowane. ' . ws_unavailable_reason() . ' Poproś administratora o podłączenie chmury plików.']);
    exit;
}

$resp = match($action) {

    // ── Utwórz folder ─────────────────────────────────────────────────────────
    'create_folder' => (function () use ($body, $user_id): array {
        $ws_id = (int)($body['workspace_id'] ?? 0);
        $name  = trim($body['name'] ?? '');
        $desc  = trim($body['description'] ?? '');

        if (!$ws_id || !$name) {
            return ['ok' => false, 'error' => 'Brak wymaganego parametru workspace_id lub name.'];
        }
        ws_require_perm('create_folder', $ws_id);
        return ws_create_folder($ws_id, $name, $desc, $user_id);
    })(),

    // ── Upload pliku ──────────────────────────────────────────────────────────
    'upload_file' => (function () use ($user_id): array {
        $folder_id = (int)($_POST['folder_id'] ?? 0);
        if (!$folder_id) return ['ok' => false, 'file' => null, 'error' => 'Brak folder_id.'];

        $folder = ws_get_folder($folder_id);
        if (!$folder) return ['ok' => false, 'file' => null, 'error' => 'Nie znaleziono folderu.'];

        ws_require_perm('upload', (int)$folder['workspace_id']);

        if (empty($_FILES['file'])) return ['ok' => false, 'file' => null, 'error' => 'Brak pliku w żądaniu.'];

        $result = ws_upload_file($folder_id, $_FILES['file'], $user_id);
        if ($result['ok'] && isset($result['file'])) {
            $f = $result['file'];
            $result['file'] = [
                'id'          => $f['id'],
                'name'        => $f['name'],
                'file_size'   => $f['file_size'],
                'size_label'  => ws_format_size((int)$f['file_size']),
                'web_url'     => $f['web_url'],
            ];
        }
        return $result;
    })(),

    // ── Usuń plik ─────────────────────────────────────────────────────────────
    'delete_file' => (function () use ($body): array {
        $id = (int)($body['id'] ?? 0);
        if (!$id) return ['ok' => false, 'error' => 'Brak id.'];

        $file = ws_get_file($id);
        if (!$file) return ['ok' => false, 'error' => 'Nie znaleziono pliku.'];

        ws_require_perm('delete_file', (int)$file['workspace_id'], $file);
        return ws_delete_file($id, is_admin());
    })(),

    // ── Usuń folder ───────────────────────────────────────────────────────────
    'delete_folder' => (function () use ($body): array {
        $id = (int)($body['id'] ?? 0);
        if (!$id) return ['ok' => false, 'error' => 'Brak id.'];

        $folder = ws_get_folder($id);
        if (!$folder) return ['ok' => false, 'error' => 'Nie znaleziono folderu.'];

        ws_require_perm('delete_folder', (int)$folder['workspace_id']);
        return ws_delete_folder($id);
    })(),

    // ── Przypisz plik do zadania ──────────────────────────────────────────────
    'link_task' => (function () use ($body, $user_id): array {
        $file_id = (int)($body['file_id'] ?? 0);
        $task_id = (int)($body['task_id'] ?? 0);
        if (!$file_id || !$task_id) return ['ok' => false, 'error' => 'Brak file_id lub task_id.'];

        $file = ws_get_file($file_id);
        if (!$file) return ['ok' => false, 'error' => 'Nie znaleziono pliku.'];

        ws_require_perm('link', (int)$file['workspace_id']);
        return ws_link_file_to_task($file_id, $task_id, $user_id);
    })(),

    // ── Usuń powiązanie plik–zadanie ──────────────────────────────────────────
    'unlink_task' => (function () use ($body): array {
        $file_id = (int)($body['file_id'] ?? 0);
        $task_id = (int)($body['task_id'] ?? 0);
        if (!$file_id || !$task_id) return ['ok' => false, 'error' => 'Brak file_id lub task_id.'];

        $file = ws_get_file($file_id);
        if (!$file) return ['ok' => false, 'error' => 'Nie znaleziono pliku.'];

        ws_require_perm('link', (int)$file['workspace_id']);
        return ws_unlink_file_from_task($file_id, $task_id);
    })(),

    // ── Zadania powiązane z plikiem ───────────────────────────────────────────
    'tasks_for_file' => (function () use ($body): array {
        $file_id = (int)($body['file_id'] ?? 0);
        if (!$file_id) return ['ok' => false, 'error' => 'Brak file_id.', 'tasks' => []];

        $file = ws_get_file($file_id);
        if (!$file) return ['ok' => false, 'error' => 'Nie znaleziono pliku.', 'tasks' => []];

        if (!ws_can_file('view', $file)) ws_require_perm('view', (int)$file['workspace_id']);

        $tasks = ws_tasks_for_file($file_id);
        return ['ok' => true, 'tasks' => $tasks];
    })(),

    // ── Pliki powiązane z zadaniem ────────────────────────────────────────────
    'files_for_task' => (function () use ($body): array {
        $task_id = (int)($body['task_id'] ?? 0);
        if (!$task_id) return ['ok' => false, 'error' => 'Brak task_id.', 'files' => []];

        $task = db_one("SELECT id, workspace_id FROM tasks WHERE id = ?", [$task_id]);
        if (!$task) return ['ok' => false, 'error' => 'Nie znaleziono zadania.', 'files' => []];

        ws_require_access((int)$task['workspace_id']);   // pliki zadania widzi każdy z dostępem do obszaru

        $files = ws_files_for_task($task_id);
        foreach ($files as &$f) {
            $f['size_label']   = ws_format_size((int)$f['file_size']);
            $f['download_url'] = APP_URL . '/workspaces/api.php?action=download&id=' . $f['id'] . '&_csrf=' . urlencode(csrf_token());
            unset($f['drive_id'], $f['remote_item_id']); // nie ujawniaj wewnętrznych identyfikatorów
        }
        return ['ok' => true, 'files' => $files];
    })(),

    // ── Folder zadania: istniejący albo nowy (pierwsze wgranie z okna zadania) ─
    'ensure_task_folder' => (function () use ($body, $user_id): array {
        $task_id = (int)($body['task_id'] ?? 0);
        $task    = $task_id ? db_one("SELECT id, workspace_id FROM tasks WHERE id = ? AND deleted_at IS NULL", [$task_id]) : null;
        if (!$task) return ['ok' => false, 'error' => 'Nie znaleziono zadania.'];
        ws_require_perm('upload', (int)$task['workspace_id']);
        $folder = ws_cloud_create_task_folder($task_id, $user_id);   // sama zakłada też foldery nadrzędne w chmurze (obszar/Zadania)
        if (!$folder) return ['ok' => false, 'error' => 'Nie udało się utworzyć folderu zadania w chmurze.'];
        return ['ok' => true, 'folder' => ['id' => (int)$folder['id'], 'name' => $folder['name']]];
    })(),

    // ── Pliki jednego folderu (przeglądanie folderów w oknie zadania) ─────────
    'folder_files' => (function () use ($body): array {
        $folder_id = (int)($body['folder_id'] ?? 0);
        $q         = mb_strtolower(trim((string)($body['q'] ?? '')));
        $folder    = $folder_id ? ws_get_folder($folder_id) : null;
        if (!$folder) return ['ok' => false, 'files' => [], 'error' => 'Nie znaleziono folderu.'];
        // Folder zadania — każdy z dostępem do obszaru; folder wspólny — wg macierzy
        if (empty($folder['task_id'])) ws_require_perm('view', (int)$folder['workspace_id']);
        else ws_require_access((int)$folder['workspace_id']);
        $out = [];
        foreach (ws_list_files($folder_id) as $f) {
            if ($q !== '' && !str_contains(mb_strtolower($f['name'] . ' ' . $f['original_name']), $q)) continue;
            $out[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'folder_name' => $folder['name'],
                      'size_label' => ws_format_size((int)$f['file_size'])];
        }
        return ['ok' => true, 'files' => $out];
    })(),

    // ── Lista plików w workspace (do pickera w detail.php zadania) ────────────
    'list_ws_files' => (function () use ($body): array {
        $ws_id = (int)($body['workspace_id'] ?? 0);
        $q     = trim($body['q'] ?? '');
        if (!$ws_id) return ['ok' => false, 'files' => [], 'error' => 'Brak workspace_id.'];

        ws_require_perm('view', $ws_id);

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $rows = db_all(
            "SELECT f.id, f.name, f.original_name, f.file_size, f.folder_id, fld.name AS folder_name
             FROM ws_files f
             JOIN ws_folders fld ON fld.id = f.folder_id
             WHERE fld.workspace_id = ? AND f.deleted_at IS NULL
               AND (? = '%%' OR f.name LIKE ? OR f.original_name LIKE ?)
             ORDER BY f.created_at DESC
             LIMIT 60",
            [$ws_id, $like, $like, $like]
        );
        foreach ($rows as &$r) {
            $r['size_label'] = ws_format_size((int)$r['file_size']);
            unset($r['folder_id']);
        }
        return ['ok' => true, 'files' => $rows];
    })(),

    // ── Inicjalizacja folderów zadań dla workspace ────────────────────────
    'init_ws_folders' => (function () use ($body, $user_id): array {
        $ws_id = (int)($body['workspace_id'] ?? 0);
        if (!$ws_id) return ['ok' => false, 'error' => 'Brak workspace_id.'];
        ws_require_perm('task_folders', $ws_id);
        ws_cloud_init_workspace($ws_id);
        $created = ws_init_task_folders($ws_id, $user_id);
        $total   = (int)(db_one(
            "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",
            [$ws_id]
        )['n'] ?? 0);
        return ['ok' => true, 'created' => $created, 'total' => $total];
    })(),

    // ── Lista folderów w workspace (do pickerów w overview) ───────────────
    'list_folders' => (function () use ($body): array {
        $ws_id = (int)($body['workspace_id'] ?? 0);
        if (!$ws_id) return ['ok' => false, 'folders' => [], 'error' => 'Brak workspace_id.'];
        ws_require_perm('view', $ws_id);
        $folders = ws_list_folders($ws_id);
        return ['ok' => true, 'folders' => array_map(
            fn($f) => ['id' => (int)$f['id'], 'name' => $f['name']],
            $folders
        )];
    })(),

    default => ['ok' => false, 'error' => "Nieznana akcja: {$action}"]
};

echo json_encode($resp);
