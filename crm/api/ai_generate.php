<?php
/**
 * crm/api/ai_generate.php
 * Generuje treść wiadomości email/SMS przez Claude API (Anthropic).
 * POST JSON: { _csrf, prompt, tone, channel, context }
 * Response JSON: { ok, html, plain, error }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF
if (($body['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']);
    exit;
}

$prompt   = trim($body['prompt']  ?? '');
$tone     = trim($body['tone']    ?? 'profesjonalny');
$channel  = trim($body['channel'] ?? 'email');
$context  = trim($body['context'] ?? '');
$req_model = trim($body['model'] ?? '');

if (!$prompt) {
    echo json_encode(['ok' => false, 'error' => 'Podaj temat lub instrukcję dla asystenta.']);
    exit;
}

$api_key = db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? '';
if (!$api_key) {
    echo json_encode(['ok' => false, 'error' => 'Brak klucza Anthropic API. Skonfiguruj w: Admin → Ustawienia AI.']);
    exit;
}

// ── Buduj system prompt ─────────────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : (db_one("SELECT value FROM settings WHERE key_='org_name'")['value'] ?? 'organizacja');

$is_sms = ($channel === 'sms');
$format_instr = $is_sms
    ? 'Odpowiedz TYLKO treścią SMS — krótki, zwięzły, maksymalnie 160 znaków. Żadnego HTML.'
    : 'Odpowiedz treścią wiadomości email w formacie HTML (tylko blok <body>, bez <html>/<head>). Użyj <p>, <strong>, <ul> jeśli potrzeba. Bez stylów inline.';

$system = <<<SYSTEM
Jesteś asystentem do pisania wiadomości dla organizacji pozarządowej/NGO: {$org_name}.
Piszesz w języku polskim. Ton: {$tone}.
{$format_instr}
Nie dodawaj żadnych komentarzy, wstępów ani wyjaśnień — tylko gotową treść wiadomości.
Wiadomość powinna być gotowa do wysłania, bez placeholderów [IMIĘ] itp.
Możesz używać zmiennych takich jak {imie}, {imie_nazwisko}, {organizacja} — system je zastąpi przed wysyłką.
SYSTEM;

$user_msg = $prompt . ($context ? "\n\nDodatkowy kontekst: " . $context : '');

// ── Wywołaj Claude API ──────────────────────────────────────────────────
$allowed_models = ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6'];
$default_model  = db_one("SELECT value FROM settings WHERE key_='anthropic_model'")['value'] ?: 'claude-haiku-4-5-20251001';
$ai_model       = in_array($req_model, $allowed_models, true) ? $req_model : $default_model;

$request_body = json_encode([
    'model'      => $ai_model,
    'max_tokens' => $is_sms ? 200 : 1024,
    'system'     => $system,
    'messages'   => [
        ['role' => 'user', 'content' => $user_msg],
    ],
]);

$ctx = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'Content-Length: ' . strlen($request_body),
        ]),
        'content'       => $request_body,
        'ignore_errors' => true,
        'timeout'       => 30,
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
if ($resp === false) {
    echo json_encode(['ok' => false, 'error' => 'Błąd połączenia z Anthropic API.']);
    exit;
}

$data = json_decode($resp, true) ?? [];
if (!empty($data['error'])) {
    $msg = $data['error']['message'] ?? 'Nieznany błąd API.';
    echo json_encode(['ok' => false, 'error' => 'Anthropic API: ' . $msg]);
    exit;
}

if (($data['stop_reason'] ?? '') === 'refusal') {
    echo json_encode(['ok' => false, 'error' => 'Model odmówił napisania tej treści — zmień polecenie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Treść ze WSZYSTKICH bloków tekstowych: modele z myśleniem włączonym domyślnie
// stawiają na pierwszej pozycji blok `thinking`, więc content[0]['text'] bywa puste.
$generated = '';
foreach ((array)($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $generated .= (string)($block['text'] ?? '');
}
$generated = trim($generated);
if ($generated === '') {
    echo json_encode(['ok' => false, 'error' => 'API nie zwróciło treści (model ' . $ai_model . '). Spróbuj ponownie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Jeśli email — oczyść HTML i oddziel plain text
if ($is_sms) {
    echo json_encode(['ok' => true, 'plain' => trim($generated), 'html' => '']);
} else {
    // Usuń ewentualny blok <html>/<body> jeśli model go dodał
    $html = preg_replace('#<!DOCTYPE[^>]*>|<html[^>]*>|</html>|<head>.*?</head>|<body[^>]*>|</body>#si', '', $generated);
    $html = trim($html);
    $plain = strip_tags($html);
    echo json_encode(['ok' => true, 'html' => $html, 'plain' => $plain]);
}
