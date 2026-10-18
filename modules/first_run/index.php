<?php
/**
 * Kreator pierwszego logowania — strona samodzielna (bez header.php, żeby nie było pętli bramki).
 * Kroki i bramka: lib/firstRun.php; obsługa formularzy: logic/firstRunActions.php; widok: view/wizard.php.
 * ?step=<klucz> — krok; ?admin=1 — ustawienia kreatora (administrator).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once __DIR__ . '/lib/firstRun.php';

require_login();
firstRunMigrate();
$user  = current_user();
$uid   = (int)$user['id'];
$panel = function_exists('panel_volunteer_shell') && panel_volunteer_shell($user);
$home  = APP_URL . ($panel ? '/panel/index.php' : '/index.php');
$next  = firstRunSafeNext((string)($_GET['next'] ?? $_POST['next'] ?? ''), '');
$back  = $next !== '' ? $next : $home;
$SELF  = APP_URL . FIRST_RUN_URL;
$url   = fn(array $q = []) => $SELF . '?' . http_build_query($q + ($next !== '' ? ['next' => $next] : []));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require __DIR__ . '/logic/firstRunActions.php';
    exit;
}

$steps = firstRunSteps($user, $panel);
$keys  = array_merge(['welcome'], array_keys($steps), ['done']);
$step  = in_array($_GET['step'] ?? '', $keys, true) ? $_GET['step'] : 'welcome';
$isAdminView = isset($_GET['admin']) && is_admin();
$msg = $_SESSION['first_run_msg'] ?? null; unset($_SESSION['first_run_msg']);

$ctx = ['phone_pending' => $_SESSION['first_run_phone'] ?? '', 'u' => db_one("SELECT * FROM users WHERE id=?", [$uid]) ?: $user];
if ($step === 'stopka') {
    require_once dirname(__DIR__) . '/stopka/logic/stopka.php';
    $ctx['stopka'] = stopkaProfile($ctx['u']);
}
require __DIR__ . '/view/wizard.php';
