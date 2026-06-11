<?php
/**
 * cert-salt.php — HTTP endpoint zwracający salt certyfikatu instalacji
 *
 * Używany przez zewnętrzne serwisy do weryfikacji, że instalacja jest aktywna
 * i certyfikat jest ważny.
 *
 * Auth: GET ?_token=<hmac-sha256(APP_KEY, 'cert-verify')>
 *
 * Jak wygenerować token (PHP):
 *   echo hash_hmac('sha256', 'cert-verify', APP_KEY);
 *
 * Jak wygenerować token (bash na serwerze):
 *   docker exec feer-app php -r "
 *     require '/var/www/html/config.php';
 *     echo hash_hmac('sha256', 'cert-verify', APP_KEY) . PHP_EOL;
 *   "
 *
 * Response 200 (cert ważny):
 *   {"valid":true,"expires":"2026-07-11","days_left":30,"salt":"abc...","org":"...","krs":"..."}
 *
 * Response 401: brak / zły token
 * Response 503: brak certyfikatu lub wygasł
 */

if (!defined('APP_INSTALLED')) {
    define('APP_INSTALLED', true);
}
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Robots-Tag: noindex, nofollow');

// ── Wyłącz bezpośrednie wyświetlanie błędów PHP ────────────────────────────────
ini_set('display_errors', '0');

// ── Weryfikacja tokenu ─────────────────────────────────────────────────────────
// Token = HMAC-SHA256 klucza 'cert-verify' podpisanego APP_KEY.
// Caller musi znać APP_KEY żeby obliczyć poprawny token.
$expected = hash_hmac('sha256', 'cert-verify', APP_KEY);
$given    = $_GET['_token'] ?? $_SERVER['HTTP_X_CERT_TOKEN'] ?? '';

if (!hash_equals($expected, $given)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'valid' => false], JSON_THROW_ON_ERROR);
    exit;
}

// ── Odczyt plików certyfikatu ──────────────────────────────────────────────────
$certs_dir = __DIR__ . '/certs';
$crt_file  = $certs_dir . '/app.crt';
$sig_file  = $certs_dir . '/app.sig';
$salt_file = $certs_dir . '/app.salt';

if (!file_exists($crt_file)) {
    http_response_code(503);
    echo json_encode(['error' => 'No certificate found', 'valid' => false], JSON_THROW_ON_ERROR);
    exit;
}

$pem    = file_get_contents($crt_file);
$parsed = openssl_x509_parse($pem);

if (!$parsed) {
    http_response_code(503);
    echo json_encode(['error' => 'Certificate parse error', 'valid' => false], JSON_THROW_ON_ERROR);
    exit;
}

// ── Ważność certyfikatu ────────────────────────────────────────────────────────
$valid_from = $parsed['validFrom_time_t'];
$valid_to   = $parsed['validTo_time_t'];
$days_left  = (int) ceil(($valid_to - time()) / 86400);
$expired    = $days_left <= 0;

// ── Weryfikacja HMAC ───────────────────────────────────────────────────────────
$hmac_ok = false;
if (file_exists($sig_file)) {
    $stored  = trim(file_get_contents($sig_file));
    $expect  = hash_hmac('sha256', $pem, APP_KEY);
    $hmac_ok = hash_equals($expect, $stored);
}

$valid = !$expired && $hmac_ok;

// ── Salt ───────────────────────────────────────────────────────────────────────
$salt = file_exists($salt_file) ? trim(file_get_contents($salt_file)) : null;

// ── Dane z certyfikatu ────────────────────────────────────────────────────────
$krs = preg_replace('/^KRS:/', '', $parsed['subject']['serialNumber'] ?? '');
$org = $parsed['subject']['CN'] ?? '';

// ── Odpowiedź ─────────────────────────────────────────────────────────────────
http_response_code($valid ? 200 : 503);

echo json_encode([
    'valid'      => $valid,
    'expires'    => date('Y-m-d', $valid_to),
    'issued'     => date('Y-m-d', $valid_from),
    'days_left'  => $days_left,
    'hmac_ok'    => $hmac_ok,
    'salt'       => $salt,
    'org'        => $org,
    'krs'        => $krs,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
