<?php
/**
 * ezd/office_online_pull.php — ściąga aktualną treść pliku edytowanego
 * w Word Online i zapisuje jako nową wersję załącznika EZD.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$ref = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/ezd/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $ref); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$r  = ezd_office_online_pull($id, (int)current_user()['id']);
flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Zapisano zmiany z Word Online jako nową wersję pliku.' : $r['error']);

header('Location: ' . $ref); exit;
