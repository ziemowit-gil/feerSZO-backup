<?php
/**
 * karty30/ti/dydaktyk/yk_protocol.php — zatwierdzenie protokołu kluczem sprzętowym (YubiKey, WebAuthn).
 * Alternatywa dla certyfikatu .p12 + SMS. Wyzwanie to skrót treści protokołu (patrz ti_protocol_yubikey_begin).
 *   GET  ?action=begin&course_id=&year_month=    → opcje dla navigator.credentials.get()
 *   POST ?action=complete  (JSON: course_id, year_month, response) → zatwierdza protokół
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_protocols_migrate();

header('Content-Type: application/json; charset=utf-8');
function yk_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$in     = $method === 'POST' ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : [];

$cid = (int)($_GET['course_id'] ?? ($in['course_id'] ?? 0));
$ym  = (string)($_GET['year_month'] ?? ($in['year_month'] ?? ''));
if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || $ym > date('Y-m')
    || !ti_protocol_can_approve($uid, $cid, dyd_is_staff())) {
    yk_out(['error' => 'Nie można zatwierdzić tego protokołu.'], 403);
}
$prot = ti_protocol_get_or_create_for_month($cid, $ym);

if ($action === 'begin' && $method === 'GET') {
    yk_out(['data' => ti_protocol_yubikey_begin($uid, $prot)]);
}

if ($action === 'complete' && $method === 'POST') {
    dyd_token_check();
    try {
        ti_protocol_approve_yubikey((int)$prot['id'], $uid, $me_name, (array)($in['response'] ?? []));
        yk_out(['data' => ['ok' => true]]);
    } catch (\Throwable $e) {
        yk_out(['error' => $e->getMessage()], 422);
    }
}

yk_out(['error' => 'Nieznana akcja.'], 404);
