<?php
/**
 * ksiegowosc/bulk_accept.php — masowa akceptacja dokumentów (EOD Dokumentów Księgowych, KDOK).
 *
 * Dla każdego wskazanego dokumentu zatwierdza (status "ok") wszystkie kroki
 * (meryt/formal/zatwierdza), do których zalogowany użytkownik ma uprawnienia
 * i które nie zostały jeszcze rozstrzygnięte — jedna weryfikacja kluczem
 * WebAuthn autoryzuje cały pakiet zamiast osobnej autoryzacji na krok.
 */
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/kdok_archive.php';
require_once __DIR__ . '/../includes/webauthn.php';

require_login();
webauthn_migrate();
kdok_migrate();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$_POST['_csrf'] = $body['_csrf'] ?? '';
try {
    csrf_check();
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF']);
    exit;
}

$user = current_user();

if (!kdok_has_role('meryt') && !kdok_has_role('formal') && !kdok_has_role('zatwierdza')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Brak uprawnień do akceptacji dokumentów.']);
    exit;
}

$auth = kdok_auth_verify((int)$user['id'], $body['ikaks'] ?? '', $body['ikaks_reason'] ?? '');
if (!$auth['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $auth['error']]);
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $body['ids'] ?? []))));
if (!$ids) {
    echo json_encode(['ok' => false, 'message' => 'Nie zaznaczono żadnego dokumentu.']);
    exit;
}

$count   = 0;
$skipped = 0;

foreach ($ids as $doc_id) {
    $doc = kdok_get($doc_id);
    if (!$doc || in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) {
        $skipped++;
        continue;
    }

    $acted = false;
    foreach (array_keys(KDOK_STEPS) as $step_key) {
        if (!kdok_has_role($step_key)) continue;
        $existing = $doc['steps'][$step_key] ?? null;
        if ($existing && in_array($existing['status'], ['ok', 'uwagi', 'odrzucono'], true)) continue;

        kdok_decide_step($doc, $step_key, 'ok', (int)$user['id'], '', $auth);
        $acted = true;
        $doc = kdok_get($doc_id);
        if (in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) break;
    }
    if ($acted) $count++; else $skipped++;
}

echo json_encode(['ok' => true, 'count' => $count, 'skipped' => $skipped]);
