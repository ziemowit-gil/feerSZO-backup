<?php
/**
 * api/payu_webhook.php — Odbiorca powiadomień PayU (notifyUrl).
 *
 * Konfiguracja w panelu PayU (punkt konfiguracji → adres powiadomień):
 *   URL: https://twoja-domena/api/payu_webhook.php
 * Drugi klucz (MD5) → Ustawienia → Integracje → Płatności / PayU (md5_key).
 *
 * PayU wysyła JSON {order:{orderId, extOrderId, status, ...}} z nagłówkiem
 * OpenPayU-Signature. Odpowiadamy 200, by PayU nie ponawiało.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/payu.php';

payu_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? '';

if (!payu_verify_notification($raw, $sig)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($raw, true) ?: [];
$order = $event['order'] ?? [];
$order_id = (string)($order['orderId'] ?? '');
$ext_id   = (string)($order['extOrderId'] ?? '');
$status   = (string)($order['status'] ?? '');

// Znajdź płatność po extOrderId, a w razie braku po orderId
$row = null;
if ($ext_id !== '')   $row = db_one("SELECT id FROM payu_payments WHERE ext_order_id=?", [$ext_id]);
if (!$row && $order_id !== '') $row = db_one("SELECT id FROM payu_payments WHERE order_id=?", [$order_id]);

if ($row && $status !== '') {
    payu_apply_order_status((int)$row['id'], $status);
}

http_response_code(200);
echo 'OK';
