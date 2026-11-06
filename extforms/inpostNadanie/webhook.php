<?php
/**
 * extforms/inpostNadanie/webhook.php — odbiór webhooków statusu przesyłek InPost ShipX.
 * Adres do wpisania w panelu InPost: /extforms/inpostNadanie/webhook.php?s=<sekret z ustawień>
 * Aktualizuje status/numer w inpost_shipments. Bez sekretu lub z błędnym → 403.
 */
$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/modules/inpost_shipx/logic/inpostShipx.php';
inpostMigrate();
header('Content-Type: application/json; charset=utf-8');

$secret = inpostSetting('webhook_secret');
if ($secret === '' || !hash_equals($secret, (string)($_GET['s'] ?? ''))) { http_response_code(403); exit('{"ok":false}'); }

$j = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($j)) { http_response_code(400); exit('{"ok":false}'); }
// Struktura zdarzeń bywa zagnieżdżona — szukamy pól rekurencyjnie.
$found = [];
array_walk_recursive($j, function ($v, $k) use (&$found) {
    if (in_array($k, ['shipment_id', 'id', 'tracking_number', 'status'], true) && !isset($found[$k]) && is_scalar($v)) $found[$k] = (string)$v;
});
$sid = $found['shipment_id'] ?? ''; $trk = $found['tracking_number'] ?? ''; $st = $found['status'] ?? '';
if ($st !== '' && ($sid !== '' || $trk !== '')) {
    db()->prepare("UPDATE inpost_shipments SET status=?, tracking_number=COALESCE(NULLIF(?,''),tracking_number), updated_at=datetime('now','localtime')
                   WHERE (?<>'' AND shipx_id=?) OR (?<>'' AND tracking_number=?)")
      ->execute([$st, $trk, $sid, $sid, $trk, $trk]);
}
echo '{"ok":true}';
