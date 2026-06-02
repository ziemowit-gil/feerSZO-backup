<?php
/**
 * licensemanager/download.php — Pobieranie pliku certyfikatu.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
lm_require_login();

$id   = (int)($_GET['id'] ?? 0);
$file = $_GET['file'] ?? '';

if (!$id || !in_array($file, ['crt','sig','key'], true)) {
    http_response_code(400); exit('Nieprawidłowe parametry.');
}

$lic = lm_one("SELECT * FROM licenses WHERE id=?", [$id]);
if (!$lic) { http_response_code(404); exit('Nie znaleziono.'); }

$map = ['crt' => ['cert_pem', 'app.crt', 'application/x-pem-file'],
        'sig' => ['cert_sig', 'app.sig', 'text/plain'],
        'key' => ['cert_key', 'app.key', 'application/x-pem-file']];

[$field, $filename, $mime] = $map[$file];

if (empty($lic[$field])) { http_response_code(404); exit('Plik nie istnieje.'); }

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $lic[$field];
