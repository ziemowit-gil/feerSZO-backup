<?php
/**
 * crm/api/inbox_assistant.php — asystent AI dla wybranej wiadomości w Skrzynce.
 *
 * POST JSON: { _csrf, id }   → { ok, intent, summary, escalate, missing, reply, … }
 *
 * Endpoint jest sesyjny (nie tokenowy): asystent kosztuje pieniądze i widzi
 * treść korespondencji, więc woła go zalogowany pracownik z prawem zapisu
 * w CRM, a nie zewnętrzny system.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_inbox_assistant.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']); exit;
}
if (!(is_admin() || can_write('crm'))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnień.']); exit;
}
crm_migrate();

$body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
if (($body['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']); exit;
}

$id  = (int)($body['id'] ?? 0);
$msg = $id ? db_one("SELECT * FROM crm_communications WHERE id=?", [$id]) : null;
if (!$msg) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Nie znaleziono wiadomości.']); exit;
}

$res = crm_inbox_assist($msg);
$res['intents'] = CRM_ASSIST_INTENTS[$res['intent']] ?? CRM_ASSIST_INTENTS['inne'];

echo json_encode($res, JSON_UNESCAPED_UNICODE);
