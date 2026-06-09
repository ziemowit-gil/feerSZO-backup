<?php
/**
 * AJAX: Backup bazy SQLite → SharePoint.
 *
 * POST { action: 'backup' }
 *   → { ok, sp_path?, web_url?, size_h?, error? }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak dostępu.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metoda niedozwolona.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? 'backup';

if ($action === 'backup') {
    set_time_limit(120);
    $result = sp_backup_db();
    echo json_encode($result);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
