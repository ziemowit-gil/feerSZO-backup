<?php
/**
 * karty30/consultations/add.php — PRZENIESIONE (nowa karta (client_id / schedule_id zachowane)).
 *
 * Karty konsultacji prowadzi się teraz w panelu dydaktyka (karty30/ti/dydaktyk/konsultacje.php, konsultacja.php,
 * konsultacjaTermin.php; logika: modules/ti_consultations). Z konta SZO wchodzi się tam przez mostek office_enter.php.
 * Dawny kod: historia gita (commit przed przeniesieniem).
 */
$id = (int)($_GET['id'] ?? 0);
$cl = (int)($_GET['client_id'] ?? 0);
$sc = (int)($_GET['schedule_id'] ?? 0);
header('Location: ../ti/dydaktyk/office_enter.php?next=' . rawurlencode('konsultacja.php?nowa=1' . ($cl ? '&client_id=' . $cl : '') . ($sc ? '&schedule_id=' . $sc : '')));
exit;
