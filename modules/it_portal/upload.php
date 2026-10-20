<?php
/**
 * modules/it_portal/upload.php — AJAX: wgranie zrzutu ekranu do edytora podstron (admin/editor).
 * POST file + _csrf → JSON {file, url} albo {error}.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/itPortal.php';

require_role('admin', 'editor');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST']); exit; }
csrf_check();
try {
    $name = itPortalImageStore($_FILES['file'] ?? []);
    echo json_encode(['file' => $name, 'url' => itPortalImageUrl($name)]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
