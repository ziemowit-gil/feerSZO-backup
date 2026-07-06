<?php
/**
 * API: Mini centrum powiadomień modułu Zadań
 * GET  → { ok, unread, latest: [{id, title, body, url, is_read, created_at}] }
 * POST { action:'mark_read', id }  → oznacz jedno jako przeczytane
 * POST { action:'mark_all' }       → oznacz wszystkie (type='task') jako przeczytane
 * Tylko powiadomienia type='task' z ogólnej tabeli `notifications`.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int)(current_user()['id'] ?? 0);
notif_migrate();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $body['action'] ?? '';

        if ($action === 'mark_read') {
            $id = (int)($body['id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=? AND type='task'")
                    ->execute([$id, $uid]);
            }
        } elseif ($action === 'mark_all') {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND type='task'")
                ->execute([$uid]);
        }
    }

    $unread = (int)(db_one(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND type='task' AND is_read=0",
        [$uid]
    )['c'] ?? 0);

    $latest = db_all(
        "SELECT id, title, body, url, is_read, created_at
         FROM notifications WHERE user_id=? AND type='task'
         ORDER BY created_at DESC LIMIT 8",
        [$uid]
    );

    echo json_encode(['ok' => true, 'unread' => $unread, 'latest' => $latest], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
