<?php
/**
 * karty30/ti/sms_templates.php — PRZENIESIONE.
 *
 * Szablony SMS modułu Dydaktyka (TI) redaguje kierownik, więc edytor stoi w panelu dydaktyka:
 * karty30/ti/dydaktyk/szablonySms.php. Z konta SZO wchodzi się tam przez mostek office_enter.php
 * (admin trafia od razu na ekran). Parametr ?key=… zostaje zachowany. Plik jest tylko dla starych linków.
 */
$key = (isset($_GET['key']) && preg_match('/^[A-Za-z0-9_]{1,60}$/', (string)$_GET['key'])) ? '?key=' . $_GET['key'] : '';
header('Location: dydaktyk/office_enter.php?next=' . rawurlencode('szablonySms.php' . $key));
exit;
