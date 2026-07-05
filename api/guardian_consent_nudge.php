<?php
/**
 * AJAX: odłóż przypomnienie o zgodzie przedstawiciela ustawowego do następnego
 * logowania (sesja). Sam formularz zgody znajduje się na panel/zgody.php.
 * POST action=snooze {_csrf}
 * Response: {ok: true} | {ok: false, error: string}
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$_user = current_user();
if (!$_user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

csrf_check();

if (($_POST['action'] ?? '') === 'snooze') {
    $_SESSION['gc_consent_nudge_snoozed'] = true;
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
