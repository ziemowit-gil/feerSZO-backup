<?php
/**
 * karty30/ti/email_templates.php — PRZENIESIONE.
 *
 * Szablony e-mail modułu Dydaktyka (TI) redaguje kierownik, więc edytor stoi w panelu dydaktyka:
 * karty30/ti/dydaktyk/szablonyEmail.php. Z konta SZO wchodzi się tam przez mostek office_enter.php
 * (admin trafia od razu na ekran). Parametr ?key=… zostaje zachowany. Wszystkie maile systemowe
 * (poza TI) nadal edytuje administrator w admin/email_templates.php.
 */
$key = (isset($_GET['key']) && preg_match('/^[A-Za-z0-9_]{1,60}$/', (string)$_GET['key'])) ? '?key=' . $_GET['key'] : '';
header('Location: dydaktyk/office_enter.php?next=' . rawurlencode('szablonyEmail.php' . $key));
exit;
