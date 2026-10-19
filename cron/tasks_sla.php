<?php
/**
 * Skrypt cron: eskalacje SLA zadań (tasks/lib/tasksSla.php).
 * Dispatcher co godzinę; zadanie po terminie (albo po czasie realizacji z zasad obszaru) → liderzy obszaru,
 * po kolejnym czasie → adresat poziomu 2. Każdy poziom raz na zadanie (task_sla_escalations).
 * Ręcznie: php cron/tasks_sla.php [--dry-run]
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/tasks/lib/tasksSla.php';

$dry = in_array('--dry-run', $argv, true);
echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_sla" . ($dry ? ' (dry-run)' : '') . "\n";
try {
    foreach (tasksSlaRun(null, $dry) as $e) {
        echo "  ✓ #{$e['task_id']} poziom {$e['level']} → " . implode(',', $e['to']) . "\n";
    }
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
