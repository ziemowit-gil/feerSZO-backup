<?php
/**
 * api/msg.php — AJAX endpoint komunikatora
 *
 * GET  ?action=unread                           → {ok, count}
 * GET  ?action=threads                          → {ok, threads[]}
 * GET  ?action=thread&ctx_type=&ctx_id=&since_id= → {ok, messages[], ctx_info, contract_type}
 * POST ?action=send  (_csrf, ctx_type, ctx_id, contract_type, body) → {ok, message}
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/approval.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ── Auth ─────────────────────────────────────────────────────────────────
$_au = current_user();
if (!$_au || !can_edit()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// ── Helper: label + URL dla wątku ────────────────────────────────────────
function _msg_ctx_info(string $ctx_type, int $ctx_id, string $contract_type): array
{
    $def = ['label' => '#' . $ctx_id, 'sublabel' => '', 'type_badge' => '', 'url' => '#'];
    if ($ctx_type === 'contract') {
        $table = 'umowy_' . $contract_type;
        try {
            $row = db_one("SELECT numer_umowy, imie_nazwisko FROM {$table} WHERE id=?", [$ctx_id]);
            return [
                'label'      => $row['numer_umowy'] ?? '#' . $ctx_id,
                'sublabel'   => $row['imie_nazwisko'] ?? '',
                'type_badge' => CONTRACT_TYPES[$contract_type] ?? $contract_type,
                'url'        => APP_URL . '/contracts/' . $contract_type . '/view.php?id=' . $ctx_id,
            ];
        } catch (\Throwable $e) { return $def; }
    }
    if ($ctx_type === 'onboarding') {
        try {
            $row = db_one("SELECT imie_nazwisko, email FROM onboarding_volunteers WHERE id=?", [$ctx_id]);
            return [
                'label'      => $row['imie_nazwisko'] ?? '#' . $ctx_id,
                'sublabel'   => $row['email'] ?? '',
                'type_badge' => 'Zgłoszenie',
                'url'        => APP_URL . '/admin/onboarding_view.php?id=' . $ctx_id,
            ];
        } catch (\Throwable $e) { return $def; }
    }
    return $def;
}

// ═════════════════════════════════════════════════════════════════════════
// action = unread
// ═════════════════════════════════════════════════════════════════════════
if ($action === 'unread') {
    echo json_encode(['ok' => true, 'count' => msg_unread_admin()]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// action = threads
// ═════════════════════════════════════════════════════════════════════════
if ($action === 'threads') {
    try {
        $threads = db_all("
            SELECT m.context_type,
                   m.context_id,
                   m.contract_type,
                   MAX(m.created_at)  AS last_at,
                   COUNT(*)           AS total,
                   SUM(CASE WHEN m.sender_type='user' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread_admin,
                   (SELECT body FROM messages m2
                    WHERE m2.context_type = m.context_type
                      AND m2.context_id   = m.context_id
                    ORDER BY m2.created_at DESC LIMIT 1) AS last_body,
                   (SELECT sender_name FROM messages m2
                    WHERE m2.context_type = m.context_type
                      AND m2.context_id   = m.context_id
                    ORDER BY m2.created_at DESC LIMIT 1) AS last_sender
            FROM messages m
            GROUP BY m.context_type, m.context_id
            ORDER BY last_at DESC
            LIMIT 60
        ");
    } catch (\Throwable $e) {
        $threads = [];
    }

    foreach ($threads as &$t) {
        $info = _msg_ctx_info($t['context_type'], (int) $t['context_id'], $t['contract_type']);
        $t    = array_merge($t, $info);
        $t['last_body_preview'] = mb_substr(strip_tags($t['last_body'] ?? ''), 0, 90);
        unset($t['last_body']);
    }
    unset($t);

    echo json_encode(['ok' => true, 'threads' => $threads]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// action = thread
// ═════════════════════════════════════════════════════════════════════════
if ($action === 'thread') {
    $ctx_type = $_GET['ctx_type'] ?? '';
    $ctx_id   = (int) ($_GET['ctx_id'] ?? 0);
    $since_id = (int) ($_GET['since_id'] ?? 0);

    if (!$ctx_type || !$ctx_id) {
        echo json_encode(['ok' => false, 'error' => 'Missing params']);
        exit;
    }

    msg_mark_read($ctx_type, $ctx_id, 'admin');

    try {
        if ($since_id > 0) {
            $msgs = db_all(
                "SELECT * FROM messages
                 WHERE context_type=? AND context_id=? AND id>?
                 ORDER BY created_at ASC",
                [$ctx_type, $ctx_id, $since_id]
            );
        } else {
            $msgs = db_all(
                "SELECT * FROM messages
                 WHERE context_type=? AND context_id=?
                 ORDER BY created_at ASC",
                [$ctx_type, $ctx_id]
            );
        }
    } catch (\Throwable $e) {
        $msgs = [];
    }

    $contract_type = $msgs[0]['contract_type'] ?? '';
    if (!$contract_type && $ctx_type === 'contract') {
        try {
            $r = db_one(
                "SELECT contract_type FROM messages WHERE context_type=? AND context_id=? LIMIT 1",
                [$ctx_type, $ctx_id]
            );
            $contract_type = $r['contract_type'] ?? '';
        } catch (\Throwable $e) {}
    }

    $ctx_info = _msg_ctx_info($ctx_type, $ctx_id, $contract_type);

    echo json_encode([
        'ok'            => true,
        'messages'      => $msgs,
        'ctx_info'      => $ctx_info,
        'contract_type' => $contract_type,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// action = send  (POST)
// ═════════════════════════════════════════════════════════════════════════
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Błąd CSRF — odśwież stronę']);
        exit;
    }

    $ctx_type      = $_POST['ctx_type']      ?? '';
    $ctx_id        = (int) ($_POST['ctx_id'] ?? 0);
    $contract_type = $_POST['contract_type'] ?? '';
    $body          = trim($_POST['body']     ?? '');
    $type_id       = ((int)($_POST['type_id'] ?? 0)) ?: null;

    if (!$ctx_type || !$ctx_id || $body === '') {
        echo json_encode(['ok' => false, 'error' => 'Brak wymaganych danych']);
        exit;
    }

    $new_id = pmsg_send(
        $ctx_type, $ctx_id, $contract_type,
        'admin', (int) $_au['id'], $_au['name'],
        $body, 'admin', '', $type_id
    );

    // Powiadomienie email dla użytkownika
    try {
        msg_send_notify($ctx_type, $ctx_id, $contract_type, 'to_user', $body);
    } catch (\Throwable $e) {}

    $msg = null;
    try {
        $msg = db_one("SELECT * FROM messages WHERE id=?", [$new_id]);
    } catch (\Throwable $e) {}

    echo json_encode(['ok' => true, 'message' => $msg]);
    exit;
}

// ── Fallback ──────────────────────────────────────────────────────────────
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
