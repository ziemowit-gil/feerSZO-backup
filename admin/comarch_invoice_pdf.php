<?php
/**
 * admin/comarch_invoice_pdf.php — pobiera PDF faktury z Comarch Betterfly.
 *   ?id=N  (&custom=<customPrintId>)  → PDF inline
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/comarch_betterfly.php';

require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Brak id faktury.'); }
if (!comarch_configured()) { http_response_code(400); die('Integracja Comarch nie jest skonfigurowana.'); }

$custom = !empty($_GET['custom']) ? (int)$_GET['custom'] : null;
$pdf = comarch_invoice_pdf($id, $custom);
if ($pdf === null) { http_response_code(502); die('Nie udało się pobrać PDF faktury z Comarch Betterfly.'); }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="comarch_faktura_' . $id . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
