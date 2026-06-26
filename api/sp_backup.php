<?php
/**
 * api/sp_backup.php — ręczny backup bazy SQLite → SharePoint.
 * POST _csrf — wymaga is_admin().
 * Odpowiada JSON {ok, sp_path, web_url, size_h} lub {error}.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

header('Content-Type: application/json; charset=utf-8');

function je(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') je('Metoda niedozwolona', 405);
if (!current_user()) je('Brak sesji', 401);
if (!is_admin()) je('Tylko administrator może wykonać backup.', 403);

auth_start();
if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) je('Błąd CSRF', 403);

$result = sp_backup_db();

if ($result['ok']) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
