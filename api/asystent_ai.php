<?php
/**
 * api/asystent_ai.php — endpoint JSON dla asystenta AI (procedury i dokumentacja).
 *
 * POST JSON: { _csrf, history: [ {role, text}, ... ] }
 *   history — cała rozmowa (ostatni wpis = bieżące pytanie użytkownika).
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
if (!module_enabled('procedures_enabled')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Moduł procedur jest wyłączony.']);
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

$res = asai_run($history);

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
