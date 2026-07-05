<?php
/**
 * ezd/office_online_pull.php — ściąga aktualną treść pliku edytowanego
 * w Office Online i zapisuje jako nową wersję albo zastępuje oryginał
 * (wybór użytkownika, patrz $_POST['mode']).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$ref = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/ezd/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $ref); exit; }
csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$mode = $_POST['mode'] ?? 'version';
$r    = ezd_office_online_pull($id, (int)current_user()['id'], $mode);

if ($r['ok']) {
    $msg = $r['mode'] === 'replace'
        ? 'Zastąpiono oryginalny plik zmianami z Office Online.'
        : 'Zapisano zmiany z Office Online jako nową wersję pliku.';
    flash_set('success', $msg);
} else {
    flash_set('danger', $r['error']);
}

header('Location: ' . $ref); exit;
