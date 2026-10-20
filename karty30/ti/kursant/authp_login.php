<?php
/**
 * karty30/ti/kursant/authp_login.php — dawny handler logowania osoby upoważnionej.
 * Osoba upoważniona loguje się we wspólnym logowaniu TI (karty30/ti/login.php) — login rozpoznawany automatycznie.
 * GET → strona logowania, POST → 307 (dane formularza: login, password).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
header('Location: ' . tiPanelUrl('ti', 'login.php') . ($post ? '' : '?tab=up'), true, $post ? 307 : 302);
exit;
