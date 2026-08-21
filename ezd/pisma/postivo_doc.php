<?php
/**
 * Pobiera dokument Postivo.pl dla pisma EZD.
 *
 * GET ?id={pismo_id}&type=epo_pdf|dispatch_cert
 *
 * Typy:
 *   epo_pdf      — Elektroniczne Potwierdzenie Odbioru (zwrotka) PDF
 *   epo_xml      — EPO w XML
 *   dispatch_cert — certyfikat nadania PDF
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';

require_login();
ezd_require_access();

$pismo_id = (int)($_GET['id']   ?? 0);
$type     = preg_replace('/[^a-z_]/', '', $_GET['type'] ?? 'epo_pdf');

if (!$pismo_id) { http_response_code(400); die('Brak ID pisma.'); }

$pismo = db_one("SELECT * FROM ezd_pisma WHERE id=?", [$pismo_id]);
if (!$pismo) { http_response_code(404); die('Nie znaleziono pisma.'); }

$sprawa = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$pismo['sprawa_id']]);
$access = $sprawa ? ezd_sprawa_access($sprawa, current_user()['id']) : null;
if (!$access) { http_response_code(403); die('Brak dostępu.'); }

$postivo_id = $pismo['postivo_job_id'] ?? '';
if (!$postivo_id) { http_response_code(400); die('Pismo nie ma identyfikatora Postivo.pl.'); }

if (postivo_setting('postivo_enabled') !== '1') {
    http_response_code(403); die('Integracja Postivo.pl jest wyłączona.');
}

try {
    $client  = new PostivoClient();
    $content = $client->get_document($postivo_id, $type);

    if ($content === null) {
        http_response_code(404);
        die('Dokument nie jest jeszcze dostępny. Spróbuj ponownie po dostarczeniu przesyłki.');
    }

    $mime = match($type) {
        'epo_xml' => 'application/xml',
        default   => 'application/pdf',
    };
    $ext = match($type) {
        'epo_xml' => 'xml',
        default   => 'pdf',
    };
    $label = match($type) {
        'epo_pdf'       => 'EPO',
        'epo_xml'       => 'EPO',
        'dispatch_cert' => 'cert-nadania',
        'envelope'      => 'koperta',
        default         => $type,
    };
    $filename = 'postivo_' . $label . '_' . $pismo['sygnatura'] . '.' . $ext;
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-cache');
    echo $content;
    exit;

} catch (RuntimeException $e) {
    flash_set('error', 'Błąd pobierania dokumentu Postivo.pl: ' . $e->getMessage());
    header('Location: ' . APP_URL . '/ezd/pisma/view.php?id=' . $pismo_id);
    exit;
}
