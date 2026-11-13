<?php
/**
 * Skrzynka obszaru: e-maile przychodzące → zadania (tasks/lib/tasksMail.php).
 * Dispatcher co 5 min; każda aktywna skrzynka obszaru (task_mail_imap) czytana przez IMAP (modules/mailImap).
 * Od 2026-11-13 bez Microsoft Graph i bez modułu Poczta.
 * Ręcznie: php cron/tasks_mail_intake.php [--dry-run]
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/tasks/lib/tasksMail.php';

$dry = in_array('--dry-run', $argv, true);
echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_mail_intake" . ($dry ? ' (dry-run)' : '') . "\n";
try {
    $s = tasksMailIntakeRun($dry);
    echo "  zadania: {$s['tasks']}, komentarze: {$s['comments']}, pominięte: {$s['skipped']}, potwierdzenia: {$s['acks']}, błędy skrzynek: {$s['errors']}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
