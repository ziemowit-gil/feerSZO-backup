<?php
/**
 * karty30/ti/dydaktyk/grupaUproszczonaPdf.php — PDF z loginami i hasłami kont założonych przy tworzeniu grupy uproszczonej.
 * Tylko POST z tokenem panelu, tylko kierownik. Hasła nie są w bazie — PDF powstaje z danych w sesji
 * (grupaUproszczona.php zostawia je na 30 minut po utworzeniu grupy). Wydruk trafia do audytu (bez haseł).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_simple_groups/logic/simpleGroups.php';

$me = dyd_require();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Wydruk wymaga przycisku na stronie grupy.'); }
dyd_token_check();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak dostępu.'); }

$cid  = (int)($_POST['course_id'] ?? 0);
$data = $_SESSION['gu_creds_pdf'][$cid] ?? null;
if (!$data || time() - (int)$data['ts'] > 1800) {
    unset($_SESSION['gu_creds_pdf'][$cid]);
    http_response_code(410);
    exit('Hasła nie są już dostępne (minęło 30 minut albo sesja wygasła). Nowe hasła nadasz w Ewidencji Uczestników („Resetuj hasło”).');
}
try {
    $pdf = TiSimpleGroups::credentialsPdf($cid, $data['rows']);
} catch (\Throwable $e) {
    error_log('grupaUproszczonaPdf: ' . $e->getMessage());
    http_response_code(500); exit('Nie udało się przygotować PDF.');
}
try {
    require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
    audit_log('ti.simple_group.credentials_pdf', ['course_id' => $cid, 'accounts' => count($data['rows'])], (int)($me['real_user_id'] ?? $me['user_id']));
} catch (\Throwable $e) {}
$name = (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid])['name'] ?? 'grupa');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="loginy-' . preg_replace('/[^A-Za-z0-9.]/', '', $name) . '.pdf"');
header('Cache-Control: no-store, private');
echo $pdf;
