<?php
/**
 * AJAX: Zapis pojedynczego ustawienia SharePoint (klucze sp_*).
 *
 * POST { key: 'sp_backup_folder', value: 'Backup' }
 *   → { ok }
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

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$key   = $body['key']   ?? '';
$value = $body['value'] ?? '';

$allowed = ['sp_backup_folder', 'sp_base_folder', 'sp_library', 'sp_site_url', 'sp_enabled'];
if (!in_array($key, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Niedozwolony klucz.']);
    exit;
}

m365_save_setting($key, trim($value));
echo json_encode(['ok' => true]);
