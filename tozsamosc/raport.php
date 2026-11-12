<?php
/**
 * tozsamosc/raport.php — „Raport bezpieczeństwa kont” w menu Administracja Systemu Tożsamości.
 *
 * Cienkie wejście do routera modułu modules/account_security (domyślna trasa /raport — strona; /raport.csv — eksport),
 * żeby strona miała adres w /tozsamosc/ i podświetlenie w menu. Logika: logic/AccountSecurityReportController.php.
 */
require_once dirname(__DIR__) . '/config.php';
if (!isset($_GET['_route'])) $_GET['_route'] = '/raport';
require dirname(__DIR__) . '/modules/account_security/router.php';
