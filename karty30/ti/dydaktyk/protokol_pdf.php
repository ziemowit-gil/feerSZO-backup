<?php
/**
 * karty30/ti/dydaktyk/protokol_pdf.php — wydruk protokołu zajęć do PDF.
 * Parametr: ?id=<protocol_id>. Dostęp tylko do protokołu własnego kursu
 * (pracownik D3 / admin — do każdego).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

karty30_migrate();
ti_protocols_migrate();

$me = dyd_require();
$uid = (int)$me['user_id'];
$id = (int)($_GET['id'] ?? 0);

// ?hours=1 — sama lista godzin (data — liczba godzin) do sprawdzenia przed
// zatwierdzeniem/podpisem; działa też dla miesiąca bez założonego protokołu
// (?course=&ym=), bez zakładania go.
if (!empty($_GET['hours'])) {
    $cid = (int)($_GET['course'] ?? 0);
    $ym  = (string)($_GET['ym'] ?? '');
    $pr  = $id ? ti_protocol_get($id)
         : (($cid && preg_match('/^\d{4}-\d{2}$/', $ym)) ? ti_protocol_month_stub($cid, $ym) : null);
    if (!$pr) { http_response_code(404); exit('Protokół nie istnieje.'); }
    if (!dyd_owns_course($uid, (int)$pr['course_id'])) { http_response_code(403); exit('Brak dostępu do tego protokołu.'); }
    $pdf = ti_protocol_hours_list_pdf($pr);
    if ($pdf === null) { http_response_code(500); exit('Nie udało się wygenerować PDF listy godzin.'); }
    ti_print_log_add('protokol_godziny_pdf', 'Lista godzin: ' . (string)($pr['course_name'] ?? ''), (int)$pr['course_id'], 0, [], $me);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . ti_protocol_hours_list_filename($pr) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$pr = $id ? ti_protocol_get($id) : null;
if (!$pr) { http_response_code(404); exit('Protokół nie istnieje.'); }

if (!dyd_owns_course($uid, (int)$pr['course_id'])) { http_response_code(403); exit('Brak dostępu do tego protokołu.'); }

$pdf = ti_protocol_pdf($pr);
if ($pdf === null) {
    flash_set('danger', 'Nie udało się wygenerować PDF protokołu.');
    header('Location: index.php?course=' . (int)$pr['course_id'] . '&tab=protokol&protocol=' . $id);
    exit;
}

ti_print_log_add('protokol_pdf', 'Protokół zajęć #' . $id, (int)$pr['course_id'], 0, [], $me);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . ti_protocol_pdf_filename($pr) . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
