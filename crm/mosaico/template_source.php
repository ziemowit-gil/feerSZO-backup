<?php
/**
 * crm/mosaico/template_source.php — serwuje zapisany HTML szablonu jako punkt
 * startowy do ponownej edycji w Mosaico (edytor ładuje dowolny URL jako "master
 * template" — dokładnie tak samo jak własne templates/versafix-1/... z dystrybucji).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

$id  = (int)($_GET['id'] ?? 0);
$tpl = $id ? db_one("SELECT body, source FROM crm_templates WHERE id=?", [$id]) : null;

if (!$tpl || $tpl['source'] !== 'mosaico' || $tpl['body'] === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $tpl['body'];
