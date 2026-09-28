<?php
/**
 * Skrypt cron: odpowiedzi e-mailem na powiadomienia o zadaniach → komentarze.
 * Działa tylko, gdy admin włączył opcję (settings.tasks_mail_reply_enabled) — patrz
 * task_mail_reply_ingest() w includes/task_notify.php. Dispatcher co 5 min.
 *   php cron/tasks_mail_reply.php [--dry-run]
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
require_once $base_dir . '/includes/m365.php';

$dry = in_array('--dry-run', $argv ?? [], true);
echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_mail_reply" . ($dry ? ' (dry-run)' : '') . "\n";
$s = task_mail_reply_ingest($dry);
echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wiadomości: {$s['seen']}, komentarze: {$s['added']}, pominięte: {$s['skipped']}"
   . ($s['error'] !== '' ? ", uwaga: {$s['error']}" : '') . "\n";
