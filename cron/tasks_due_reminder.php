<?php
/**
 * Skrypt cron: Przypomnienia o terminach zadań.
 * Uruchamiaj raz dziennie, np. o 8:00:
 *   0 8 * * * php /var/www/html/cron/tasks_due_reminder.php
 *
 * Wysyła email do przypisanych użytkowników gdy:
 *  – zadanie ma termin jutro (notify_due_1day)
 *  – zadanie ma termin dzisiaj (notify_due_today)
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/task_notify.php';

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$sent  = 0;
$skip  = 0;
$errs  = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_due_reminder\n";

// ── Zadania z terminem dzisiaj ────────────────────────────────────────────
$tasks_today = db_all(
    "SELECT id, title FROM tasks
     WHERE due_date=? AND completed_at IS NULL AND deleted_at IS NULL",
    [$today]
);
foreach ($tasks_today as $t) {
    try {
        task_notify_due((int)$t['id'], 'due_today');
        echo "  ✓ due_today task #{$t['id']}: {$t['title']}\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "  ✗ due_today task #{$t['id']}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

// ── Zadania z terminem jutro ──────────────────────────────────────────────
$tasks_tomorrow = db_all(
    "SELECT id, title FROM tasks
     WHERE due_date=? AND completed_at IS NULL AND deleted_at IS NULL",
    [$tomorrow]
);
foreach ($tasks_tomorrow as $t) {
    try {
        task_notify_due((int)$t['id'], 'due_1day');
        echo "  ✓ due_1day  task #{$t['id']}: {$t['title']}\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "  ✗ due_1day  task #{$t['id']}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

// ── Sprzątanie starych logów (> 90 dni) ───────────────────────────────────
try {
    db()->exec("DELETE FROM task_notification_log WHERE sent_at < datetime('now','-90 days')");
} catch (\Throwable $e) {}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Przetworzono: " . (count($tasks_today) + count($tasks_tomorrow))
   . " zadań, błędy: $errs\n";
