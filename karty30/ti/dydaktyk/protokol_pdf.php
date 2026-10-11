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

// ?dziennik=1&course=&ym= — dziennik zajęć grupy za miesiąc protokołu, bez danych osobowych.
// Prowadzący — tylko własnej grupy; wersję z danymi osobowymi drukuje kierownik (dziennik_pdf.php?dane=1).
if (!empty($_GET['dziennik'])) {
    require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pdf/logic/dziennik.php';
    $cid    = (int)($_GET['course'] ?? 0);
    $course = $cid ? k30_ti_course_get($cid) : null;
    if (!$course) { http_response_code(404); exit('Nie znaleziono grupy.'); }
    if (!dyd_owns_course($uid, $cid)) { http_response_code(403); exit('Brak dostępu do tej grupy.'); }
    $ym  = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['ym'] ?? '')) ? (string)$_GET['ym'] : '';
    $pdf = ti_dziennik_pdf($cid, ['personal' => false, 'ym' => $ym]);
    if ($pdf === null) { http_response_code(500); exit('Nie udało się wygenerować dziennika.'); }
    ti_print_log_add('dziennik_pdf', 'Dziennik zajęć' . ($ym !== '' ? ' za ' . $ym : '') . ' — ' . $course['name'], $cid, 0, ['dane' => 0, 'ym' => $ym], $me);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . ti_dziennik_filename($course, $ym) . '"');
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

// ?ewidencja=1&course=&ym= — e-ewidencja godzin do umowy zlecenie prowadzącego (podgląd przed zgodą na przepisanie).
if (!empty($_GET['ewidencja'])) {
    $cid = (int)($_GET['course'] ?? 0);
    $ym  = (string)($_GET['ym'] ?? '');
    $pr  = ($cid && preg_match('/^\d{4}-\d{2}$/', $ym)) ? ti_protocol_month_stub($cid, $ym) : null;
    if (!$pr) { http_response_code(404); exit('Protokół nie istnieje.'); }
    if (!dyd_owns_course($uid, (int)$pr['course_id'])) { http_response_code(403); exit('Brak dostępu do tego protokołu.'); }
    // &umowa=ID — wybór, gdy pasuje kilka umów (tylko spośród umów tego prowadzącego).
    $umowa = null;
    // Po zgodzie: zawsze umowa, do której przepisano godziny (dokument bez znaku wodnego).
    ti_protocol_ew_migrate();
    $_ew = !empty($pr['id']) ? db_one("SELECT ew_umowa_id, ew_zgoda_at FROM k30_ti_protocols WHERE id = ?", [(int)$pr['id']]) : null;
    $_zgoda = !empty($_ew['ew_zgoda_at']) && !empty($_ew['ew_umowa_id']);
    if ($_zgoda) $umowa = db_one("SELECT * FROM umowy_zlecenie WHERE id = ?", [(int)$_ew['ew_umowa_id']]);
    elseif (!empty($_GET['umowa'])) {
        foreach (ti_protocol_zlecenie_umowy($pr) as $_u) if ((int)$_u['id'] === (int)$_GET['umowa']) $umowa = $_u;
    }
    $umowa ??= ti_protocol_zlecenie_umowa($pr);
    if (!$umowa) { http_response_code(404); exit('Nie wybrano umowy zlecenie (albo prowadzący nie ma jej w systemie).'); }
    $pdf = ti_protocol_ewidencja_pdf($pr, $umowa, !$_zgoda);
    if ($pdf === null) { http_response_code(500); exit('Nie udało się wygenerować e-ewidencji.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="e-ewidencja-' . $cid . '-' . $ym . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// ?preview=1&course=&ym= — podgląd protokołu, który powstanie po zatwierdzeniu miesiąca
// (ze znakiem wodnym „PODGLĄD”, otwierany w przeglądarce, bez zakładania protokołu).
if (!empty($_GET['preview'])) {
    $cid = (int)($_GET['course'] ?? 0);
    $ym  = (string)($_GET['ym'] ?? '');
    $pr  = ($cid && preg_match('/^\d{4}-\d{2}$/', $ym)) ? ti_protocol_month_stub($cid, $ym) : null;
    if (!$pr) { http_response_code(404); exit('Protokół nie istnieje.'); }
    if (!dyd_owns_course($uid, (int)$pr['course_id'])) { http_response_code(403); exit('Brak dostępu do tego protokołu.'); }
    $pdf = ti_protocol_pdf($pr, true);
    if ($pdf === null) { http_response_code(500); exit('Nie udało się wygenerować podglądu protokołu.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="podglad-' . ti_protocol_pdf_filename($pr) . '"');
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
