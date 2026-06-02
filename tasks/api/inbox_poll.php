<?php
/**
 * API: Polling nowych wiadomości w skrzynce zadań
 * GET  ?since=TIMESTAMP   — zwraca wiadomości nowsze niż podana data
 * Odpowiedź: { unread, threads: [{task_id, task_title, last_body, last_at, has_unread}] }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid   = (int)(current_user()['id'] ?? 0);
$since = $_GET['since'] ?? '1970-01-01 00:00:00';

// Walidacja formatu daty
if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $since)) {
    $since = '1970-01-01 00:00:00';
}

try {
    // Nowe wiadomości od `since`
    $new_msgs = db_all(
        "SELECT m.context_id AS task_id, m.body, m.created_at, m.sender_id, m.sender_name,
                m.subject, m.is_read, m.recipient_id,
                t.title AS task_title
         FROM messages m
         LEFT JOIN tasks t ON t.id = m.context_id
         WHERE m.context_type = 'task'
           AND (m.recipient_id = ? OR m.sender_id = ?)
           AND m.created_at > ?
         ORDER BY m.created_at ASC",
        [$uid, $uid, $since]
    );

    // Liczba nieprzeczytanych łącznie
    $unread = task_msg_unread($uid);

    // Nowa data "since" — max z nowych wiadomości
    $latest = $since;
    foreach ($new_msgs as $m) {
        if ($m['created_at'] > $latest) $latest = $m['created_at'];
    }

    // Grupuj nowe wiadomości wg task_id
    $by_task = [];
    foreach ($new_msgs as $m) {
        $tid = (int)$m['task_id'];
        $by_task[$tid][] = $m;
    }

    // Dla każdego task_id z nowymi wiadomościami — pobierz pełny wątek
    $updated_threads = [];
    foreach ($by_task as $tid => $msgs) {
        $full = db_all(
            "SELECT m.*, t.title AS task_title
             FROM messages m
             LEFT JOIN tasks t ON t.id = m.context_id
             WHERE m.context_type='task' AND m.context_id=?
               AND (m.recipient_id=? OR m.sender_id=?)
             ORDER BY m.created_at ASC",
            [$tid, $uid, $uid]
        );

        $has_unread = (bool)array_filter($full, fn($mm) =>
            !$mm['is_read'] && (int)$mm['recipient_id'] === $uid
        );

        $last = end($full);
        $updated_threads[] = [
            'task_id'     => $tid,
            'task_title'  => $full[0]['task_title'] ?? 'Zadanie #'.$tid,
            'has_unread'  => $has_unread,
            'last_body'   => $last['body']       ?? '',
            'last_at'     => $last['created_at'] ?? '',
            'last_sender' => $last['sender_name'] ?? '',
            'last_mine'   => (int)($last['sender_id'] ?? 0) === $uid,
            'msgs'        => array_values($full),
        ];
    }

    echo json_encode([
        'ok'              => true,
        'unread'          => $unread,
        'latest'          => $latest,
        'updated_threads' => $updated_threads,
        'new_count'       => count($new_msgs),
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
