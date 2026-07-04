<?php
/**
 * ezd/office_online.php — otwiera załącznik EZD (.doc/.docx) w Office Word Online.
 * Wysyła plik na skonfigurowaną witrynę SharePoint (jeśli jeszcze nie wysłano)
 * i przekierowuje na adres zwrócony przez Microsoft Graph API.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$id  = (int)($_GET['id'] ?? 0);
$ref = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/ezd/index.php');

$r = ezd_office_online_url($id, (int)current_user()['id']);
if (!$r['ok']) {
    flash_set('danger', $r['error']);
    header('Location: ' . $ref); exit;
}

header('Location: ' . $r['url']); exit;
