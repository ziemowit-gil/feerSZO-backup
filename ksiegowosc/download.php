<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
kdok_migrate();

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'orig'; // orig | final

$doc = kdok_get($id);
if (!$doc) { http_response_code(404); die('Nie znaleziono.'); }

if ($type === 'final') {
    if (!$doc['generated']) { http_response_code(404); die('Brak wygenerowanego PDF.'); }
    $rel_path  = $doc['generated']['file_path'];
    $expected_hash = $doc['generated']['file_sha256'];
    $dl_name   = 'final_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $doc['number']) . '.pdf';
} else {
    if (!$doc['file_path']) { http_response_code(404); die('Brak pliku.'); }
    $rel_path  = $doc['file_path'];
    $expected_hash = $doc['file_sha256'];
    $dl_name   = 'orig_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $doc['number']) . '.pdf';
}

$abs_path = UPLOAD_DIR . ltrim($rel_path, '/');

if (!is_file($abs_path)) {
    http_response_code(404);
    die('Plik nie istnieje na serwerze.');
}

// Weryfikacja sumy kontrolnej
$actual_hash = hash_file('sha256', $abs_path);
if ($expected_hash && $actual_hash !== $expected_hash) {
    http_response_code(500);
    die('BŁĄD INTEGRALNOŚCI: Suma kontrolna pliku nie zgadza się. Plik mógł zostać zmodyfikowany.');
}

kdok_log($id, 'Pobrano plik', ($type === 'final' ? 'Finalny PDF' : 'Oryginał'));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $dl_name . '"');
header('Content-Length: ' . filesize($abs_path));
header('X-Content-SHA256: ' . $actual_hash);
header('Cache-Control: private, no-cache');
readfile($abs_path);
exit;
