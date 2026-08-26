<?php
/**
 * karty30/ti/syllabus_pdf.php — wydruk sylabusa przedmiotu do PDF.
 * Parametr: ?id=<syllabus_id>
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_syllabus.php';

k30_require_access();
karty30_migrate();
ti_syllabus_migrate();

$id  = (int)($_GET['id'] ?? 0);
$syl = $id ? ti_syllabus_get($id) : null;
if (!$syl) { http_response_code(404); exit('Sylabus nie istnieje.'); }

$pdf = ti_syllabus_pdf($syl);
if ($pdf === null) {
    flash_set('danger', 'Nie udało się wygenerować PDF sylabusa. Sprawdź, czy biblioteka mPDF jest dostępna.');
    header('Location: syllabi.php?id=' . $id);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . ti_syllabus_pdf_filename($syl) . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
