<?php
/**
 * api/stripe_webhook.php — Odbiorca zdarzeń Stripe (webhook).
 *
 * Konfiguracja w Stripe: Developers → Webhooks → Add endpoint
 *   URL:    https://twoja-domena/api/stripe_webhook.php
 *   Events: checkout.session.completed, checkout.session.async_payment_succeeded,
 *           checkout.session.expired
 *   Signing secret (whsec_...) → Ustawienia → Płatności / Stripe (webhook_secret)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

stripe_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_webhook($raw, $sig)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($raw, true) ?: [];
$type  = $event['type'] ?? '';
$obj   = $event['data']['object'] ?? [];

/** Znajdź wiersz płatności po metadata.payment_id, a w razie braku po session id. */
function _stripe_resolve_payment(array $obj): ?int {
    $pid = (int)($obj['metadata']['payment_id'] ?? 0);
    if ($pid > 0 && db_one("SELECT id FROM stripe_payments WHERE id=?", [$pid])) return $pid;
    $sid = (string)($obj['id'] ?? '');
    if ($sid !== '') {
        $r = db_one("SELECT id FROM stripe_payments WHERE session_id=?", [$sid]);
        if ($r) return (int)$r['id'];
    }
    return null;
}

switch ($type) {
    case 'checkout.session.completed':
    case 'checkout.session.async_payment_succeeded':
        // Opłacone (dla 'completed' tylko gdy payment_status = paid)
        $paid = ($obj['payment_status'] ?? '') === 'paid' || $type === 'checkout.session.async_payment_succeeded';
        $pid  = _stripe_resolve_payment($obj);
        if ($pid && $paid) {
            // zapisz payment_intent jeśli dostępny
            if (!empty($obj['payment_intent'])) {
                db()->prepare("UPDATE stripe_payments SET payment_intent=? WHERE id=?")
                   ->execute([$obj['payment_intent'], $pid]);
            }
            stripe_mark_paid($pid);
        }
        break;

    case 'checkout.session.expired':
        $pid = _stripe_resolve_payment($obj);
        if ($pid) db()->prepare("UPDATE stripe_payments SET status='expired' WHERE id=? AND status='pending'")->execute([$pid]);
        break;

    case 'checkout.session.async_payment_failed':
        $pid = _stripe_resolve_payment($obj);
        if ($pid) db()->prepare("UPDATE stripe_payments SET status='failed' WHERE id=? AND status='pending'")->execute([$pid]);
        break;
}

http_response_code(200);
echo 'OK';
