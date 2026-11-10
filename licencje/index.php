<?php
/**
 * licencje/index.php — Panel Licencji (kursant.feer.org.pl/licencje/, także szo.feer.org.pl/licencje/): niezależne wejście routera modules/ti_licencje_kursant
 * (polecenie 2026-11-10). Nie korzysta z sesji ani logowania panelu uczestnika TI: własna sesja szo_licencje, login „l” + 8 cyfr i hasło.
 * ?wejscie=… — jednorazowy link z panelu uczestnika TI (LicencjePanelAuth::handoffConsume), potem przekierowanie na czysty adres.
 * Na ti*. i kurs*. katalog jest wyłączony z przepisywania na panel kursanta (.htaccess).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';        // k30_ti_client_licenses (Programy i klucze)
require_once dirname(__DIR__) . '/includes/ti_messages.php';    // ti_account_log (dziennik operacji uczestnika)
require_once dirname(__DIR__) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
LicencjePanelAuth::start();
if (isset($_GET['wejscie']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $ok = LicencjePanelAuth::handoffConsume((string)$_GET['wejscie']);
    if (!$ok) $_SESSION['lk_login_err'] = 'Link wejścia wygasł albo był już użyty. Wejdź jeszcze raz z panelu uczestnika albo zaloguj się loginem i hasłem.';
    header('Location: ' . LicencjePanelAuth::PATH, true, 302);
    exit;
}
if (isset($_GET['wejscie_admin']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {   // Administrator Licencji z SZO / Microsoft 365 (licencje/sso.php)
    $ok = LicencjePanelAuth::adminHandoffConsume((string)$_GET['wejscie_admin']);
    if (!$ok) $_SESSION['lk_login_err'] = 'Link logowania administratora wygasł albo był już użyty. Zaloguj się jeszcze raz.';
    header('Location: ' . LicencjePanelAuth::PATH . ($ok ? '?_route=' . rawurlencode('/admin') : ''), true, 302);
    exit;
}
define('LK_PANEL', true);
require dirname(__DIR__) . '/modules/ti_licencje_kursant/router.php';
