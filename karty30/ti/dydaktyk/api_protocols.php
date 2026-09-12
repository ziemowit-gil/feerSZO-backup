<?php
/**
 * karty30/ti/dydaktyk/api_protocols.php — API protokołów miesięcznych (panel dydaktyka).
 *
 * Uwierzytelnianie sesją panelu (k30_dydaktyk), nie kluczem API — to wywołanie
 * "w imieniu" zalogowanego prowadzącego, nie integracja zewnętrzna (do tego
 * służy api/v1/karty30.php z Bearer). Przygotowane pod przyszłe wydzielenie
 * panelu dydaktyka jako osobnej aplikacji: protokoly_moje.php woła ten
 * endpoint przez HTTP (ti_protocols_api_call()) i dopiero gdy się nie uda,
 * spada bezpośrednio na funkcje z includes/ti_protocols.php (ta sama baza).
 *
 * GET  ?action=pending                                    → lista miesięcy do zamknięcia
 * POST ?action=approve  {course_id, year_month}            → zatwierdza protokół miesiąca
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';

header('Content-Type: application/json; charset=utf-8');

function api_protocols_json(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$me = dyd_current();
if (!$me) { api_protocols_json(['error' => 'Brak sesji panelu dydaktyka.'], 401); }
$uid = (int)$me['user_id'];
karty30_migrate();
ti_protocols_migrate();

$action = (string)($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($action === 'pending' && $method === 'GET') {
    api_protocols_json(['data' => ti_protocol_pending_months_for_instructor($uid)]);
}

if ($action === 'approve' && $method === 'POST') {
    dyd_token_check(); // ten sam token co formularze panelu — żądanie idzie z jego JS/strony
    $raw = json_decode(file_get_contents('php://input') ?: '', true);
    $in  = is_array($raw) ? $raw : $_POST;
    $cid = (int)($in['course_id'] ?? 0);
    $ym  = (string)($in['year_month'] ?? '');
    if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || !dyd_owns_course($uid, $cid)) {
        api_protocols_json(['error' => 'Nieprawidłowe dane protokołu.'], 422);
    }
    try {
        $prot = ti_protocol_get_or_create_for_month($cid, $ym);
        ti_protocol_approve((int)$prot['id'], $uid, (string)($me['name'] ?? ''));
        api_protocols_json(['data' => ['course_id' => $cid, 'year_month' => $ym, 'status' => 'approved']]);
    } catch (\Throwable $e) {
        api_protocols_json(['error' => $e->getMessage()], 422);
    }
}

api_protocols_json(['error' => 'Nieznana akcja lub zła metoda.'], 404);
