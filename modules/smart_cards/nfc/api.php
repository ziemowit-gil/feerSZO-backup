<?php
/**
 * modules/smart_cards/nfc/api.php — backend JSON Programatora NFC.
 * Tylko POST z tokenem CSRF sesji, tylko admin/editor. Akcje (_action):
 *   list      karty czekające na zaprogramowanie
 *   prepare   card_id, uid        → token + URL do zapisania w NDEF
 *   confirm   card_id, uid, token, locked → oznaczenie jako zaprogramowanej
 *   identify  uid, token          → karta, autentyczność, strefy
 *   access    uid, zone_id        → decyzja wejścia (zapis w audit_logs)
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/smart_cards.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function nfc_out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$me = current_user();
if (!$me || !in_array($me['role'] ?? '', ['admin', 'editor'], true)) nfc_out(['ok' => false, 'error' => 'Brak uprawnień — zaloguj się ponownie.'], 403);
if (!module_enabled('smart_cards_enabled')) nfc_out(['ok' => false, 'error' => 'Moduł Karty dostępu jest wyłączony.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') nfc_out(['ok' => false, 'error' => 'Dozwolony tylko POST.'], 405);
if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) nfc_out(['ok' => false, 'error' => 'Sesja wygasła — odśwież stronę.'], 403);
if (function_exists('system_block_writes')) system_block_writes();

$svc = new SmartCardService($me);
$cardOut = function (array $c) use ($svc): array {
    $zones = $svc->cardZones($c);
    return [
        'id'         => (int)$c['id'],
        'holder'     => (string)($c['user_name'] ?? ''),
        'email'      => (string)($c['user_email'] ?? ''),
        'uid'        => (string)$c['card_uid'],
        'uid_source' => (string)($c['uid_source'] ?? ''),
        'status'     => (string)$c['status'],
        'status_label' => SCARD_STATUSES[$c['status']]['label'] ?? $c['status'],
        'expires'    => date('d.m.Y', strtotime($c['expires_at'])),
        'expires_short' => date('m/y', strtotime($c['expires_at'])),
        'template'   => (string)($c['template_name'] ?? ''),
        'design'     => scard_design($c['design_config'] ?? null),
        'programmed' => !empty($c['programmed_at']),
        'zones'      => array_map(fn($z) => ['name' => $z['zone_name'], 'level' => (int)$z['security_level'], 'effective' => $z['effective']], $zones),
        'url'        => APP_URL . '/modules/smart_cards/card.php?id=' . (int)$c['id'],
    ];
};

try {
    switch ((string)($_POST['_action'] ?? '')) {
        case 'list':
            nfc_out(['ok' => true, 'cards' => array_map(fn($c) => [
                'id' => (int)$c['id'], 'holder' => (string)$c['user_name'], 'email' => (string)$c['user_email'],
                'uid' => (string)$c['card_uid'], 'uid_source' => (string)$c['uid_source'], 'template' => (string)$c['template_name'],
                'design' => scard_design($c['design_config']), 'expires_short' => date('m/y', strtotime($c['expires_at'])),
            ], $svc->toProgram())]);

        case 'prepare':
            $r = $svc->prepareProgramming((int)($_POST['card_id'] ?? 0), (string)($_POST['uid'] ?? ''));
            nfc_out(['ok' => true] + $r);

        case 'confirm':
            $svc->confirmProgramming((int)($_POST['card_id'] ?? 0), (string)($_POST['uid'] ?? ''), (string)($_POST['token'] ?? ''), !empty($_POST['locked']));
            nfc_out(['ok' => true]);

        case 'identify':
            $r = $svc->identifyTag((string)($_POST['uid'] ?? ''), (string)($_POST['token'] ?? ''));
            audit_log('smart_cards.nfc_identified', array_filter([
                'card_id' => isset($r['card']['id']) ? (int)$r['card']['id'] : null,
                'card_uid' => (string)($_POST['uid'] ?? ''),
                'authentic' => $r['authentic'] === null ? 'n/d' : ($r['authentic'] ? 'tak' : 'NIE'),
            ], fn($v) => $v !== null && $v !== ''));
            nfc_out(['ok' => true, 'authentic' => $r['authentic'], 'message' => $r['message'], 'card' => $r['card'] ? $cardOut($r['card']) : null]);

        case 'access':
            $r = $svc->checkAccess((string)($_POST['uid'] ?? ''), (int)($_POST['zone_id'] ?? 0));
            nfc_out(['ok' => true, 'granted' => $r['granted'], 'reason' => $r['reason']]);

        default:
            nfc_out(['ok' => false, 'error' => 'Nieznana operacja.'], 400);
    }
} catch (SmartCardException $e) {
    nfc_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('[smart_cards/nfc/api] ' . $e->getMessage());
    nfc_out(['ok' => false, 'error' => 'Błąd serwera — nic nie zostało zapisane.'], 500);
}
