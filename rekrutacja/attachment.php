<?php
/**
 * rekrutacja/attachment.php — Bezpieczny podgląd/pobieranie dokumentu kandydata
 * PDF: inline (podgląd w przeglądarce); DOCX zawsze jako attachment.
 * ?dl=1 wymusza pobranie. Dostęp: operator rekrutacji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
require_module_enabled('rekrutacja_enabled', 'Moduł rekrutacji');
rekr_require_operator();

$file_id = (int)($_GET['id'] ?? 0);
$f = $file_id ? db_one("SELECT * FROM rekr_files WHERE id=?", [$file_id]) : null;
if (!$f) { http_response_code(404); die('Plik nie istnieje.'); }

// stored_path jest generowany serwerowo, ale i tak pilnujemy katalogu (path traversal)
$path = realpath(UPLOAD_DIR . $f['stored_path']);
$base = realpath(UPLOAD_DIR . 'rekrutacja');
if (!$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404); die('Pliku nie znaleziono na dysku.');
}

$force_dl = isset($_GET['dl']) || $f['mime'] !== 'application/pdf';
$disposition = $force_dl ? 'attachment' : 'inline';
$safe_name = str_replace('"', '', $f['original_name']);

header('Content-Type: ' . $f['mime']);
header('Content-Disposition: ' . $disposition . '; filename="' . $safe_name . '"; filename*=UTF-8\'\'' . rawurlencode($f['original_name']));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
readfile($path);
exit;
