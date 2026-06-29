<?php
/**
 * extforms/konsultacjeADNGO/export_pdf.php
 * Zbiorczy eksport kart doradztwa do jednego pliku PDF.
 */
$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/consultations.php';

cc_migrate();
auth_start();

$id     = (int)($_GET['id'] ?? 0);
$pub_ok = $id > 0 && in_array($id, $_SESSION['cc_pub_pdf'] ?? [], true);

if ($pub_ok) {
    $c = cc_get($id);
    if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }
    cc_render_pdf_file($c, 'D');
    exit;
}

require_login();
if (is_viewer()) { http_response_code(403); exit('Brak dostępu.'); }
require_module_enabled('dostepnosc_ngo_enabled', 'Moduł Dostępność NGO');

if ($id > 0) {
    $c = cc_get($id);
    if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }
    cc_render_pdf_file($c, 'D');
    exit;
}

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to']   ?? '');
if ($from !== '' && !cc_valid_date($from)) $from = '';
if ($to   !== '' && !cc_valid_date($to))   $to   = '';

$rows = cc_list($from, $to);
if (!$rows) {
    flash_set('warning', 'Brak kart do wyeksportowania w wybranym zakresie.');
    header('Location: ' . APP_URL . '/extforms/konsultacjeADNGO/admin.php');
    exit;
}

$label = ($from !== '' || $to !== '')
    ? ($from !== '' ? $from : 'poczatek') . '_do_' . ($to !== '' ? $to : date('Y-m-d'))
    : 'wszystkie';

cc_render_pdf_bulk($rows, 'D', 'karty-konsultacyjne_' . $label . '.pdf');
exit;
