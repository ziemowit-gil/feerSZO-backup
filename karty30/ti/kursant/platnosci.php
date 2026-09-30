<?php
/**
 * karty30/ti/kursant/platnosci.php — przejście z panelu kursanta/rodzica do portalu płatności (/platnosci)
 * jednym kliknięciem (bez osobnego linku). Rodzic/opiekun loguje się do rozliczeń dziecka; małoletni kursant sam
 * nie zarządza płatnościami (jak dotąd — prowadzi je opiekun).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/payment_portal/logic/paymentPortal.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$parent = parent_current();
if ($parent) {
    $pid = (int)$parent['client_id']; $via = 'panel rodzica';
} else {
    $student = student_current_via_api_token() ?? student_require();
    $acc = db_one("SELECT is_minor FROM k30_ti_student_accounts WHERE id=?", [(int)$student['id']]);
    if (!empty($acc['is_minor'])) { http_response_code(403); exit('Rozliczenia małoletnich prowadzi opiekun — zaloguj się do panelu rodzica.'); }
    $pid = (int)$student['client_id']; $via = 'panel kursanta';
}
if (!pp_portal_login_participant($pid, $via)) { http_response_code(403); exit('Portal płatności jest dla tego konta niedostępny. Skontaktuj się z biurem.'); }
header('Location: ' . rtrim(APP_URL, '/') . '/platnosci/');
exit;
