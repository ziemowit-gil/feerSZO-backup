<?php
/**
 * api/sendly_webhook.php — raporty doręczeń Sendly (webhook typu NOTIFICATION: message_id + DELIVERED / ERROR).
 *
 * Adres (z kluczem) pokazują Ustawienia SMS → Sendly; wkleja się go w panelu Sendly: Bramka SMS → Ustawienia usługi →
 * raporty doręczeń. ERROR → ten sam SMS od razu przez innego operatora (modules/sms_delivery). Odpowiedź 200 kończy
 * ponawianie po stronie Sendly (także dla nieznanego message_id); zły klucz → 404 (Sendly przestaje ponawiać).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/modules/sms_delivery/logic/sendlyDelivery.php';

header('Content-Type: application/json; charset=utf-8');
$key = (string)org_setting('sms_sendly_webhook_key');
if ($key === '' || !hash_equals($key, (string)($_GET['k'] ?? ''))) { http_response_code(404); echo '{"ok":false}'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;
// Pojedynczy raport albo lista ({"type":"NOTIFICATION",...} / {"data":{...}} / [{...},{...}])
$items = isset($in[0]) && is_array($in[0]) ? $in : [isset($in['data']) && is_array($in['data']) ? $in['data'] + $in : $in];

$res = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $type = strtoupper((string)($it['type'] ?? 'NOTIFICATION'));
    if ($type !== 'NOTIFICATION') { $res[] = 'ignored:' . $type; continue; }
    $mid = (string)($it['message_id'] ?? $it['messageId'] ?? $it['id'] ?? '');
    $st  = (string)($it['status'] ?? $it['state'] ?? '');
    if ($mid === '' || $st === '') { $res[] = 'incomplete'; continue; }
    try {
        $res[] = sendlyHandleReport($mid, $st, mb_substr((string)($it['error'] ?? $it['description'] ?? $it['reason'] ?? ''), 0, 120));
    } catch (\Throwable $e) {
        error_log('[SMS] sendly webhook: ' . $e->getMessage());
        $res[] = 'error';
    }
}
echo json_encode(['ok' => true, 'result' => $res]);
