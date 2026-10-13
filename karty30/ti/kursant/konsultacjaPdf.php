<?php
/**
 * karty30/ti/kursant/konsultacjaPdf.php — PDF karty konsultacji dla beneficjenta (panel kursanta, „Moje konsultacje”).
 * Tylko własne karty (client_id konta), bez anulowanych. Wydruk w rejestrze dostępu RODO.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_consultations/logic/consultations.php';
require_once __DIR__ . '/auth.php';

$student = student_require();
$id = (int)($_GET['id'] ?? 0);
$k = $id ? TiConsultations::get($id) : null;
if (!$k || (int)$k['client_id'] !== (int)$student['client_id'] || $k['status'] === 'cancelled') { http_response_code(404); exit('Nie znaleziono karty.'); }
try { k30_log_access('consultation', $id, 'print', 'panel kursanta'); } catch (\Throwable $e) {}
try { $pdf = TiConsultations::pdf([$id]); }
catch (\Throwable $e) { error_log('kursant konsultacjaPdf: ' . $e->getMessage()); http_response_code(500); exit('Nie udało się przygotować PDF.'); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . TiConsultations::pdfName($k) . '"');
header('Cache-Control: no-store, private');
echo $pdf;
