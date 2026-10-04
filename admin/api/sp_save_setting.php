<?php
/**
 * AJAX: Zapis pojedynczego ustawienia SharePoint (klucze sp_*).
 *
 * POST { key: 'sp_backup_folder', value: 'Backup', _csrf }
 *   → { ok, error?, locked? }
 * Zapis idzie przez SpConnection::save(): witryna / biblioteka / folder bazowy bywają zablokowane po połączeniu (patrz tamże).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
require_once dirname(dirname(__DIR__)) . '/modules/sp_connection/logic/spConnection.php';

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

auth_start();
$sess = (string)($_SESSION['csrf'] ?? '');
if ($sess === '' || !hash_equals($sess, (string)($body['_csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Błąd CSRF — odśwież stronę i spróbuj ponownie.']);
    exit;
}

if (!in_array($key, SpConnection::SAVEABLE_KEYS, true)) {
    echo json_encode(['ok' => false, 'error' => 'Niedozwolony klucz.']);
    exit;
}

echo json_encode(SpConnection::save([$key => (string)$value], (int)(current_user()['id'] ?? 0)));
