<?php
/**
 * Raport weryfikacji — łączy oryginalny PDF z kartą obiegu i strumieniuje do przeglądarki.
 * Nie zapisuje na serwerze.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
kdok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = kdok_get($id);
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }

$history  = kdok_get_history($id);
$pdf      = kdok_build_report_pdf($doc, $history);
$filename = 'raport_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $doc['number']) . '.pdf';

$pdf->Output('I', $filename); // inline — otwiera w przeglądarce
