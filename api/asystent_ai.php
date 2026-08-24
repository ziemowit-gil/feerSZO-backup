<?php
/**
 * api/asystent_ai.php — endpoint JSON dla asystenta AI (baza wiedzy + funkcje SZO).
 *
 * Rozmowa toczy się w kontekście zalogowanego użytkownika, więc agent ma też
 * narzędzie `moje_dane` (patrz includes/asystent_ai.php). Odpowiednik bez sesji,
 * bramkowany tokenem publicznym, to chatbot/ai.php.
 *
 * POST JSON: { _csrf, history: [ {role, text}, ... ], scope? }
 *   history — cała rozmowa (ostatni wpis = bieżące pytanie użytkownika),
 *   scope   — moduł, z którego pyta użytkownik (informacyjnie, do audytu).
 * Response:  { ok, answer, sources:[...], trace:[...], model } | { ok:false, error }
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}
// Asystent nie jest częścią modułu procedur — odpowiada też o funkcje systemu,
// komunikaty i dane konta. Bramką jest sam klucz API, nie moduł procedur.
if (!asai_enabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Asystent AI nie jest skonfigurowany (brak klucza Anthropic API).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

if (($body['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
    exit;
}

// Historia rozmowy — sanityzacja + limit długości (bezpiecznik na tokeny).
$history = [];
foreach ((array)($body['history'] ?? []) as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $text = trim((string)($m['text'] ?? ''));
    if ($text === '') continue;
    $history[] = ['role' => $role, 'text' => mb_substr($text, 0, 4000)];
}
// Zostaw ostatnie ~10 wpisów.
if (count($history) > 10) $history = array_slice($history, -10);

if (!$history || end($history)['role'] !== 'user') {
    echo json_encode(['ok' => false, 'error' => 'Zadaj pytanie.']);
    exit;
}

$res = asai_run($history, 6, [
    'mode'  => 'session',
    'scope' => preg_replace('/[^a-z0-9_-]/', '', (string)($body['scope'] ?? '')),
]);

if (empty($res['ok'])) {
    echo json_encode([
        'ok'    => false,
        'error' => $res['error'] ?? 'Nieznany błąd.',
        'trace' => $res['trace'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Audyt (best-effort) — pytanie trafia do dziennika systemowego bez treści odpowiedzi.
try {
    $last_q = end($history)['text'] ?? '';
    if (function_exists('log_system_action')) {
        log_system_action((int)current_user()['id'], 'asystent_ai_query', mb_substr($last_q, 0, 300));
    }
} catch (\Throwable $e) {}

echo json_encode([
    'ok'      => true,
    'answer'  => $res['answer'] ?? '',
    'sources' => $res['sources'] ?? [],
    'trace'   => $res['trace'] ?? [],
    'model'   => $res['model'] ?? '',
], JSON_UNESCAPED_UNICODE);
