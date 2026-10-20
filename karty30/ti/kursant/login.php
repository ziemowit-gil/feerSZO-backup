<?php
/**
 * karty30/ti/kursant/login.php — stary adres logowania uczestnika (student_require() → „login.php”).
 * Logowanie jest jedno dla całego TI: karty30/ti/login.php (na hostach ti. i kurs. — „/login.php”). Przekierowanie, żeby zapisane linki działały.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: ' . tiPanelUrl('ti', 'login.php') . ($qs !== '' ? '?' . $qs : ''), true, $_SERVER['REQUEST_METHOD'] === 'POST' ? 307 : 302);
exit;
