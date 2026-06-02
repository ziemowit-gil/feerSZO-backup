<?php
/**
 * licensemanager/api.php — REST API dla instalacji.
 *
 * GET  ?action=check&url=INSTALL_URL&app_key=APP_KEY
 *      → { valid, status, expires_at, org_name, days_left }
 *
 * GET  ?action=cert&url=INSTALL_URL&app_key=APP_KEY
 *      → { cert_pem, cert_sig } lub 404
 *
 * POST ?action=ping  body: { url, app_key }
 *      → { ok } — aktualizuje last_ping_at
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// Prosta ochrona rate-limit (IP-based, bardzo basic)
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function api_json(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── check — sprawdź status licencji ─────────────────────────────────────────
if ($action === 'check') {
    $url     = rtrim(trim($_GET['url'] ?? ''), '/');
    $app_key = trim($_GET['app_key'] ?? '');

    if (!$url) api_json(['valid' => false, 'error' => 'Missing url'], 400);

    $lic = lm_one("SELECT * FROM licenses WHERE install_url=?", [$url]);
    if (!$lic) api_json(['valid' => false, 'error' => 'License not found', 'status' => 'unknown'], 404);

    // Opcjonalna weryfikacja APP_KEY
    if ($app_key && $lic['app_key'] && !hash_equals($lic['app_key'], $app_key)) {
        api_json(['valid' => false, 'error' => 'APP_KEY mismatch', 'status' => 'unauthorized'], 403);
    }

    $days_left = (int)ceil((strtotime($lic['expires_at']) - time()) / 86400);
    $valid     = in_array($lic['status'], ['active', 'trial'], true) && $days_left > 0;

    // Aktualizuj ping
    lm_exec("UPDATE licenses SET last_ping_at=datetime('now') WHERE id=?", [$lic['id']]);

    api_json([
        'valid'      => $valid,
        'status'     => $lic['status'],
        'org_name'   => $lic['org_name'],
        'expires_at' => $lic['expires_at'],
        'days_left'  => $days_left,
        'has_cert'   => !empty($lic['cert_pem']),
    ]);
}

// ── cert — pobierz certyfikat dla instalacji ──────────────────────────────────
if ($action === 'cert') {
    $url     = rtrim(trim($_GET['url'] ?? ''), '/');
    $app_key = trim($_GET['app_key'] ?? '');

    $lic = lm_one("SELECT * FROM licenses WHERE install_url=?", [$url]);
    if (!$lic || empty($lic['cert_pem'])) api_json(['error' => 'Certificate not found'], 404);

    if ($app_key && $lic['app_key'] && !hash_equals($lic['app_key'], $app_key)) {
        api_json(['error' => 'APP_KEY mismatch'], 403);
    }

    lm_exec("UPDATE licenses SET last_ping_at=datetime('now') WHERE id=?", [$lic['id']]);
    lm_log((int)$lic['id'], 'cert_fetched', "IP: {$ip}");

    api_json([
        'cert_pem' => $lic['cert_pem'],
        'cert_sig' => $lic['cert_sig'],
        'org_name' => $lic['org_name'],
        'expires_at'=> $lic['expires_at'],
    ]);
}

// ── ping — heartbeat ──────────────────────────────────────────────────────────
if ($action === 'ping') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $url  = rtrim(trim($body['url'] ?? $_POST['url'] ?? ''), '/');
    if (!$url) api_json(['ok' => false, 'error' => 'Missing url'], 400);
    $lic  = lm_one("SELECT id FROM licenses WHERE install_url=?", [$url]);
    if (!$lic) api_json(['ok' => false, 'error' => 'Not found'], 404);
    lm_exec("UPDATE licenses SET last_ping_at=datetime('now') WHERE id=?", [$lic['id']]);
    api_json(['ok' => true]);
}

api_json(['error' => 'Unknown action'], 400);
