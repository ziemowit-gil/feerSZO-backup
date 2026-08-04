<?php
/**
 * API: Diagnostyka systemu powiadomień zadań (tylko admin)
 *
 * GET  ?task_id=X&event=created  → runPostSaveTest() dla konkretnego zadania
 * GET  ?overview=1               → statystyki kolejki + błędy z ostatnich 24h
 * POST { action:'retry', queue_id:N }  → ręczny reset failed→pending
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/task_notification_service.php';

require_login();

$u = current_user();
if (!($u['is_admin'] ?? false)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Tylko administrator']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$svc = new TaskNotificationService(db());

// ── POST: retry ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'retry') {
        $qId = (int)($body['queue_id'] ?? 0);
        if (!$qId) { echo json_encode(['ok' => false, 'error' => 'Brak queue_id']); exit; }

        db()->prepare(
            "UPDATE task_notifications_queue SET status='pending', attempts=0, error_msg=NULL WHERE id=? AND status='failed'"
        )->execute([$qId]);

        echo json_encode(['ok' => true, 'msg' => 'Reset do pending: #' . $qId]);
        exit;
    }

    if ($action === 'process_now') {
        $stats = $svc->processQueue((int)($body['limit'] ?? 20));
        echo json_encode(['ok' => true, 'stats' => $stats]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Nieznana akcja']);
    exit;
}

// ── GET: overview ────────────────────────────────────────────────────────────
if (!empty($_GET['overview'])) {
    try {
        $queue_stats = db_all(
            "SELECT status, COUNT(*) AS cnt FROM task_notifications_queue GROUP BY status"
        );
        $recent_errors = db_all(
            "SELECT tne.*, t.title AS task_title, u.name AS user_name
             FROM task_notification_errors tne
             LEFT JOIN tasks t ON t.id = tne.task_id
             LEFT JOIN users u ON u.id = tne.user_id
             WHERE tne.created_at >= datetime('now','-24 hours','localtime')
             ORDER BY tne.created_at DESC LIMIT 20"
        );
        $failed_items = db_all(
            "SELECT tnq.*, t.title AS task_title, u.name AS user_name
             FROM task_notifications_queue tnq
             LEFT JOIN tasks t ON t.id = tnq.task_id
             LEFT JOIN users u ON u.id = tnq.user_id
             WHERE tnq.status = 'failed'
             ORDER BY tnq.created_at DESC LIMIT 20"
        );

        echo json_encode([
            'ok'            => true,
            'queue_stats'   => $queue_stats,
            'recent_errors' => $recent_errors,
            'failed_items'  => $failed_items,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── GET: test konkretnego zadania ────────────────────────────────────────────
$taskId    = (int)($_GET['task_id'] ?? 0);
$eventType = preg_replace('/[^a-z0-9_]/', '', $_GET['event'] ?? 'created');

if (!$taskId) {
    echo json_encode(['ok' => false, 'error' => 'Podaj task_id i opcjonalnie event']);
    exit;
}

try {
    $diag = $svc->runPostSaveTest($taskId, $eventType);
    echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
