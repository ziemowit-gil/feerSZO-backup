<?php
/**
 * karty30/ti/dydaktyk/api_helpdesk.php — API zgłoszeń Helpdesk z panelu dydaktyka.
 *
 * Uwierzytelnianie sesją panelu (k30_dydaktyk) — patrz api_protocols.php dla
 * uzasadnienia (przygotowanie pod przyszłe wydzielenie panelu jako osobnej
 * aplikacji). Zgłoszenie ląduje w ISTNIEJĄCYM module Helpdesk (helpdesk_tickets),
 * ze znacznikiem source='dydaktyk' — ten sam panel administracyjny (helpdesk/admin.php)
 * je obsługuje, bez osobnego systemu.
 *
 * POST ?action=create  {title, description, category?, priority?}
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpdesk.php';

header('Content-Type: application/json; charset=utf-8');

function api_helpdesk_json(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$me = dyd_current();
if (!$me) { api_helpdesk_json(['error' => 'Brak sesji panelu dydaktyka.'], 401); }

$action = (string)($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($action === 'create' && $method === 'POST') {
    dyd_token_check();
    $raw = json_decode(file_get_contents('php://input') ?: '', true);
    $in  = is_array($raw) ? $raw : $_POST;

    $title       = trim((string)($in['title'] ?? ''));
    $description = trim((string)($in['description'] ?? ''));
    $category    = (string)($in['category'] ?? 'it_inne');
    $priority    = (string)($in['priority'] ?? 'normalny');

    if ($title === '' || $description === '') {
        api_helpdesk_json(['error' => 'Temat i opis są wymagane.'], 422);
    }

    $ticket_id = hd_ticket_quick_create(
        ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? ''), 'email' => (string)($me['email'] ?? '')],
        $title, $description, $category, $priority, 'dydaktyk'
    );
    api_helpdesk_json(['data' => ['ticket_id' => $ticket_id]], 201);
}

api_helpdesk_json(['error' => 'Nieznana akcja lub zła metoda.'], 404);
