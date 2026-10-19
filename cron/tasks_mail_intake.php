<?php
/**
 * Skrzynka obszaru: e-maile przychodzące → zadania (tasks/lib/tasksMail.php).
 * Dispatcher co 5 min; czyta wiadomości już zapisane przez skanowanie Poczty (crm_communications) —
 * nie łączy się z Microsoft Graph poza dociąganiem załączników, których skan nie pobrał.
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
    echo "  zadania: {$s['tasks']}, komentarze: {$s['comments']}, pominięte: {$s['skipped']}, potwierdzenia: {$s['acks']}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
