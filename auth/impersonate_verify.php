<?php
/**
 * auth/impersonate_verify.php — Krok 2: admin wpisuje kod, który przekazał mu
 * właściciel konta. Po zgodności wchodzi w kontekst tego użytkownika
 * (includes/context.php, ctx_enter_user) i trafia do jego panelu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/context.php';
require_once dirname(__DIR__) . '/includes/impersonation.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_admin()) {
    header('Location: ' . APP_URL . '/portal.php'); exit;
}
csrf_check();

$pending = $_SESSION['imp_pending'] ?? null;
$back = (is_array($pending) && !empty($pending['type']) && !empty($pending['id']))
    ? contract_url($pending['type'], (int)$pending['id'])
    : APP_URL . '/portal.php';

if (!is_array($pending) || empty($pending['request_id'])) {
    flash_set('error', 'Brak aktywnego żądania wejścia na konto.');
    header('Location: ' . $back); exit;
}

$result = impersonation_verify_and_enter((int)$pending['request_id'], $_POST['code'] ?? '');

if ($result['ok']) {
    unset($_SESSION['imp_pending']);
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

flash_set('error', $result['error']);
header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'imp=1');
exit;
