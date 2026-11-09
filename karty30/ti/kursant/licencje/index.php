<?php
/**
 * karty30/ti/kursant/licencje/index.php — panel zarządzania licencjami (kursant.feer.org.pl/licencje/): wejście routera modules/ti_licencje_kursant.
 * Osobny panel z własnym logowaniem (login „l” + 8 cyfr i hasło, sesja szo_licencje — wzorem Portalu Kontrahenta).
 * Uczestnik zalogowany w panelu TI wchodzi bez ponownego logowania (tożsamość przejmowana przy wejściu).
 * Zastępuje zakładkę index.php?tab=licencje (tam przekierowanie tutaj).
 */
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/db.php';
require_once dirname(__DIR__, 4) . '/includes/functions.php';
require_once dirname(__DIR__, 4) . '/includes/karty30.php';
require_once dirname(__DIR__, 4) . '/includes/ti_messages.php';   // ti_account_log
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 4) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';

// Wejście z panelu uczestnika TI: przejmij tożsamość (bez bramek panelu TI — to osobny panel), potem własna sesja panelu licencji.
$ti = student_current();
$tiUser = $ti ? ['cid' => (int)$ti['client_id'], 'sid' => (int)$ti['id']] : null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
LicencjePanelAuth::start();
if ($tiUser) {
    $lp = LicencjePanelAuth::user();
    if (!$lp || (int)$lp['cid'] !== $tiUser['cid']) LicencjePanelAuth::login($tiUser['cid'], 'panel uczestnika TI', $tiUser['sid']);
}
define('LK_PANEL', true);
require dirname(__DIR__, 4) . '/modules/ti_licencje_kursant/router.php';
