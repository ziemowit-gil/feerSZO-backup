<?php
/**
 * karty30/ti/terms_admin.php — PRZENIESIONE.
 *
 * Regulaminy TI (treść, odbiorcy, akceptacje, pominięcie/zdalna akceptacja, podpisane skany) są w panelu dydaktyka:
 * karty30/ti/dydaktyk/regulaminy.php. Z konta SZO wchodzi się tam przez mostek office_enter.php (parametr ?type= zostaje).
 * Dawny kod: historia gita.
 */
$t = (isset($_GET['type']) && preg_match('/^[a-z0-9_]{1,40}$/', (string)$_GET['type'])) ? '?type=' . $_GET['type'] : '';
header('Location: dydaktyk/office_enter.php?next=' . rawurlencode('regulaminy.php' . $t));
exit;
