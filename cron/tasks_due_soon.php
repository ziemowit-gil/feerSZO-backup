<?php
/**
 * Skrypt cron: przypomnienie ~1 h przed GODZINĄ terminu zadania (tasks.due_time).
 * Dispatcher co 15 min; okno: termin dziś, w ciągu najbliższych 60 min (i jeszcze nie minął).
 * Dedup w task_notification_log (event due_soon, ref = zadanie, raz dziennie).
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/tasks.php';
require_once $base_dir . '/includes/task_notify.php';

task_extras_schema_heal();

$now  = date('H:i');
$soon = date('H:i', strtotime('+60 minutes'));
// Po 23:00 okno przechodzi przez północ — wtedy bierzemy do końca dnia
if ($soon < $now) $soon = '23:59';

$tasks = db_all(
    "SELECT id, title, due_time FROM tasks
     WHERE due_date = ? AND due_time IS NOT NULL AND due_time > ? AND due_time <= ?
       AND completed_at IS NULL AND deleted_at IS NULL",
    [date('Y-m-d'), $now, $soon]
);

echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_due_soon ({$now}–{$soon}), zadań: " . count($tasks) . "\n";
foreach ($tasks as $t) {
    try {
        task_notify_due_soon((int)$t['id']);
        echo "  ✓ #{$t['id']} {$t['due_time']} {$t['title']}\n";
    } catch (\Throwable $e) {
        echo "  ✗ #{$t['id']}: " . $e->getMessage() . "\n";
    }
}
