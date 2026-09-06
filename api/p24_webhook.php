<?php
/**
 * api/p24_webhook.php — Odbiorca powiadomień Przelewy24 (urlStatus).
 *
 * Konfiguracja w panelu Przelewy24 (dane transakcji → urlStatus) nie jest
 * potrzebna ręcznie — adres jest przekazywany przy każdej rejestracji
 * transakcji (p24_create_order()):
 *   URL: https://twoja-domena/api/p24_webhook.php
 * Klucz CRC → admin/p24_settings.php.
 *
 * Przelewy24 wysyła JSON {merchantId, posId, sessionId, amount, originAmount,
 * currency, orderId, methodId, statement, sign}. Samo przyjęcie powiadomienia
 * NIE oznacza rozliczonej płatności — wymagane jest jeszcze jawne wywołanie
 * transaction/verify (p24_mark_paid_from_notification() robi to za nas).
 * Odpowiadamy 200, by Przelewy24 nie ponawiało.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/p24.php';

p24_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

if (!p24_verify_notification($body)) {
    http_response_code(401);
    exit('Invalid signature');
}

$session_id = (string)($body['sessionId'] ?? '');
$order_id   = (string)($body['orderId']   ?? '');

$row = $session_id !== '' ? db_one("SELECT id FROM p24_payments WHERE session_id=?", [$session_id]) : null;

if ($row) {
    p24_mark_paid_from_notification((int)$row['id'], $order_id);
}

http_response_code(200);
echo 'OK';
