<?php
/**
 * karty30/ti/dydaktyk/login.php — dawne wejście logowania panelu dydaktyka (dyd_require() → „login.php”).
 * Kadra loguje się we wspólnym logowaniu TI (karty30/ti/login.php, modules/ti_login): e-mail @feer.org.pl → Microsoft 365
 * (mostek office_enter.php), inne konta panelowe → e-mail + hasło, potem obowiązkowe TOTP (totp_gate.php).
 * GET → strona logowania (już zalogowany → panel), POST → 307 (pola email/login + password), odmowa Office → e=4.
 */
require_once __DIR__ . '/auth.php';

if (($_GET['office'] ?? '') === 'denied') {
    header('Location: ' . tiPanelUrl('ti', 'login.php') . '?tab=dydaktyk&e=4'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: ' . tiPanelUrl('ti', 'login.php'), true, 307); exit;
}
if (dyd_current()) { header('Location: index.php'); exit; }
header('Location: ' . tiPanelUrl('ti', 'login.php') . '?tab=dydaktyk'); exit;
