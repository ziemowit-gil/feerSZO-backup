<?php
/**
 * konsultacje/zip.php — Eksport kart konsultacyjnych do archiwum ZIP.
 *
 * GET: from, to (Y-m-d, opcjonalne) — zakres dat.
 *
 * Tworzy archiwum z pojedynczymi plikami .txt (czytelny protokół) dla każdej
 * karty z zakresu. Pliki w archiwum nazwane wg schematu:
 *   RRRR-MM-DD_[Nazwa_Organizacji]_ID.txt
 *
 * Dostęp: tylko zalogowani (nie viewer).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

require_login();
if (is_viewer()) { http_response_code(403); exit('Brak dostępu.'); }
require_module_enabled('consultations_enabled', 'Moduł kart konsultacyjnych');

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Rozszerzenie PHP „zip" nie jest dostępne na tym serwerze.');
}

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to']   ?? '');
if ($from !== '' && !cc_valid_date($from)) $from = '';
if ($to   !== '' && !cc_valid_date($to))   $to   = '';

$rows = cc_list($from, $to);
if (!$rows) {
    flash_set('warning', 'Brak kart do wyeksportowania w wybranym zakresie.');
    header('Location: ' . APP_URL . '/konsultacje/admin.php');
    exit;
}

// Plik tymczasowy archiwum.
$tmp = tempnam(sys_get_temp_dir(), 'cc_zip_');
if ($tmp === false) { http_response_code(500); exit('Nie udało się utworzyć pliku tymczasowego.'); }

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Nie udało się utworzyć archiwum ZIP.');
}

$used = [];
foreach ($rows as $c) {
    $base = cc_filename_base($c);          // RRRR-MM-DD_Nazwa_ID
    $name = $base . '.txt';
    // Zabezpieczenie przed kolizją nazw.
    if (isset($used[$name])) { $name = $base . '_' . $c['id'] . '.txt'; }
    $used[$name] = true;

    $zip->addFromString($name, cc_to_text($c));
}

$zip->close();

// Nazwa archiwum: zakres dat lub „wszystkie".
$label = ($from !== '' || $to !== '')
    ? ($from !== '' ? $from : 'poczatek') . '_do_' . ($to !== '' ? $to : date('Y-m-d'))
    : 'wszystkie';
$download = 'karty-konsultacyjne_' . $label . '.zip';

$size = filesize($tmp);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $download . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;
