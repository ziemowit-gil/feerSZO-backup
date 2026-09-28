<?php
/**
 * Skrypt cron: Podsumowanie dnia — moduł Zadań.
 * Wysyła jeden e-mail ze zdarzeniami z task_digest_queue użytkownikom, którzy w ustawieniach
 * powiadomień wybrali „Podsumowanie dzienne” (task_notification_prefs.notify_digest = 1).
 * Uruchamiany przez dispatcher (modules/cron_dispatcher/logic/registry.php) po południu.
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

echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_digest\n";
[$sent, $errs] = task_notify_send_digests();
echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłane podsumowania: {$sent}, błędy: {$errs}\n";
