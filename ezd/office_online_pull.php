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

$ref    = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/ezd/index.php');
$is_ajax = !empty($_POST['_ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $ref); exit; }
if ($is_ajax) {
    if (($_POST['_csrf'] ?? '') !== csrf_token()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Błąd CSRF. Odśwież stronę i spróbuj ponownie.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    csrf_check();
}

$id   = (int)($_POST['id'] ?? 0);
$mode = $_POST['mode'] ?? 'version';
$r    = ezd_office_online_pull($id, (int)current_user()['id'], $mode);

$msg = !$r['ok']
    ? ($r['error'] ?? 'Nie udało się zapisać zmian z Office Online.')
    : (!empty($r['unchanged'])
        ? 'Brak zmian w dokumencie — nic do zapisania.'
        : ($r['mode'] === 'replace'
            ? 'Zastąpiono oryginalny plik zmianami z Office Online.'
            : 'Zapisano zmiany z Office Online jako nową wersję pliku.'));

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => (bool)$r['ok'], 'unchanged' => !empty($r['unchanged']), 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

flash_set($r['ok'] ? 'success' : 'danger', $msg);
header('Location: ' . $ref); exit;
