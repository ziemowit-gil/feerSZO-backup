<?php
/**
 * chatbot/ai.php — Publiczny endpoint JSON dla asystenta AI (link /chatbot/{token}).
 *
 * Bez logowania. Autoryzacja = ważny, aktywny token publiczny (chatbot_public_token).
 * Ta sama logika agenta co api/asystent_ai.php, ale bramkowana tokenem zamiast sesji.
 *
 * POST JSON: { token, history: [ {role, text}, ... ] }
 * Response:  { ok, answer, sources:[...], trace:[...] } | { ok:false, error }
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = (string)($body['token'] ?? '');

if (!asai_public_token_valid($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Link jest nieaktywny lub wygasł. Skontaktuj się z administratorem.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Prosty limit szybkości per sesja (ochrona kosztów) ───────────────────────
$now  = time();
$win  = $_SESSION['chatbot_rl'] ?? ['start' => $now, 'count' => 0];
if ($now - ($win['start'] ?? $now) > 60) $win = ['start' => $now, 'count' => 0];
$win['count']++;
$_SESSION['chatbot_rl'] = $win;
if ($win['count'] > 15) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Zbyt wiele pytań w krótkim czasie. Odczekaj chwilę i spróbuj ponownie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Historia rozmowy — sanityzacja + limit długości ──────────────────────────
$history = [];
foreach ((array)($body['history'] ?? []) as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $text = trim((string)($m['text'] ?? ''));
    if ($text === '') continue;
    $history[] = ['role' => $role, 'text' => mb_substr($text, 0, 4000)];
}
if (count($history) > 10) $history = array_slice($history, -10);

if (!$history || end($history)['role'] !== 'user') {
    echo json_encode(['ok' => false, 'error' => 'Zadaj pytanie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = asai_run($history, 6, ['mode' => 'public']);

if (empty($res['ok'])) {
    echo json_encode([
        'ok'    => false,
        'error' => $res['error'] ?? 'Nieznany błąd.',
        'trace' => $res['trace'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Audyt (best-effort) — pytanie z linku publicznego trafia do dziennika.
try {
    $last_q = end($history)['text'] ?? '';
    if (function_exists('log_system_action')) {
        log_system_action(0, 'chatbot_public_query', mb_substr($last_q, 0, 300));
    }
} catch (\Throwable $e) {}

echo json_encode([
    'ok'      => true,
    'answer'  => $res['answer'] ?? '',
    'sources' => $res['sources'] ?? [],
    'trace'   => $res['trace'] ?? [],
], JSON_UNESCAPED_UNICODE);
