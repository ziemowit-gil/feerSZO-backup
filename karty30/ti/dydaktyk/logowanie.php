<?php
/**
 * karty30/ti/dydaktyk/logowanie.php — dawna samodzielna strona logowania panelu dydaktyka.
 * Kadra loguje się we wspólnym logowaniu TI (karty30/ti/login.php): e-mail @feer.org.pl → Microsoft 365,
 * inne konta panelowe (zespol.php) → e-mail + hasło + TOTP. Stary adres (w e-mailach o nadaniu roli) przekierowuje.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
$e = (int)($_GET['e'] ?? 0);
header('Location: ' . tiPanelUrl('ti', 'login.php') . '?tab=dydaktyk' . ($e > 0 ? '&e=' . $e : ''), true, 302);
exit;
