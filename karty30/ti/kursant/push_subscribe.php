<?php
/**
 * Endpoint zapisu/usunięcia subskrypcji Web Push dla kursanta.
 * POST JSON: { "action": "subscribe"|"unsubscribe", "subscription": {...} }
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$student = student_require();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($body['action'] ?? '');

if ($action === 'subscribe') {
    $sub = isset($body['subscription']) ? json_encode($body['subscription']) : null;
    if ($sub && strlen($sub) < 4000) {
        db_exec("UPDATE k30_ti_student_accounts SET push_subscription=? WHERE id=?",
            [$sub, (int)$student['id']]);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Brak danych subskrypcji.']);
    }
} elseif ($action === 'unsubscribe') {
    db_exec("UPDATE k30_ti_student_accounts SET push_subscription=NULL WHERE id=?",
        [(int)$student['id']]);
    echo json_encode(['ok' => true]);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
}
