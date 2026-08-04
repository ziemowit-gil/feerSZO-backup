#!/usr/bin/env php
<?php
/**
 * cron/tasks_notification_worker.php — Przetwarza kolejkę task_notifications_queue
 *
 * Uruchamiany przez dispatcher.php co 2 minuty.
 * Można też uruchomić ręcznie: php cron/tasks_notification_worker.php [--limit=50]
 *
 * Zamknięcie pętli asynchronicznej:
 *   zadanie zapisane → kolejka → ten worker → wysyłka (e-mail/sms/inapp)
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/task_notification_service.php';

$limit = 50;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(200, (int)$m[1]));
    }
}

try {
    $svc   = new TaskNotificationService(db());
    $stats = $svc->processQueue($limit);

    $msg = sprintf(
        "[tasks_notif_worker] processed=%d sent=%d skipped=%d failed=%d\n",
        $stats['processed'], $stats['sent'], $stats['skipped'], $stats['failed']
    );
    echo $msg;

    if ($stats['failed'] > 0) {
        error_log('[NOTIF_WORKER] ' . $stats['failed'] . ' failed items — sprawdź task_notification_errors');
    }

} catch (\Throwable $e) {
    $err = '[tasks_notif_worker] FATAL: ' . $e->getMessage();
    echo $err . "\n";
    error_log($err);
    exit(1);
}
