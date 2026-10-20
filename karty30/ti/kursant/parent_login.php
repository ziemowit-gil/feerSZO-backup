<?php
/**
 * karty30/ti/kursant/parent_login.php — dawny handler logowania rodzica/opiekuna hasłem.
 * Rodzic loguje się we wspólnym logowaniu TI (karty30/ti/login.php) — login rodzica rozpoznawany automatycznie.
 * GET → strona logowania, POST → 307 (dane formularza: login, password).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
header('Location: ' . tiPanelUrl('ti', 'login.php') . ($post ? '' : '?tab=rodzic'), true, $post ? 307 : 302);
exit;
