<?php
/**
 * api/redmine_webhook.php — odbiorca powiadomień z pluginu Redmine (redmine_szo_sync).
 *
 * Plugin po zmianie zagadnienia wysyła tu POST z {issue_id} i podpisem HMAC-SHA256
 * (nagłówek X-SZO-Signature). To tylko SYGNAŁ — właściwy stan pobieramy z Redmine
 * przez API (hd_redmine_pull_ticket), więc treść żądania nie jest zaufanym źródłem
 * danych. Dzięki temu sync jest natychmiastowy (obok crona).
 *
 * Konfiguracja: Ustawienia → Redmine → „Sekret webhooka" (redmine_webhook_secret);
 * ten sam sekret wpisujesz w ustawieniach pluginu w Redmine.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$secret = trim(org_setting('redmine_webhook_secret'));
$raw    = file_get_contents('php://input');
$sig    = $_SERVER['HTTP_X_SZO_SIGNATURE'] ?? '';

if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_configured']);
    exit;
}
$expected = hash_hmac('sha256', $raw, $secret);
if (!is_string($sig) || !hash_equals($expected, $sig)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$data = json_decode($raw, true);
$iid  = (int)($data['issue_id'] ?? 0);
if ($iid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_issue_id']);
    exit;
}

helpdesk_migrate();
$ticket = db_one("SELECT * FROM helpdesk_tickets WHERE redmine_issue_id=?", [$iid]);
if (!$ticket) {
    // Nie nasze zagadnienie — odpowiadamy 200, żeby plugin nie ponawiał.
    echo json_encode(['ok' => true, 'skipped' => 'unknown_issue']);
    exit;
}

$changed = false;
try {
    $changed = hd_redmine_pull_ticket($ticket);
} catch (\Throwable $e) {
    error_log('[redmine] webhook pull #' . $ticket['id'] . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'sync_failed']);
    exit;
}

echo json_encode(['ok' => true, 'changed' => $changed]);
exit;
