<?php
/**
 * Skrypt cron: Synchronizacja zadań do osobistych kont Nozbe użytkowników.
 * Uruchamiaj co kilkanaście minut — zarejestrowany w cron/dispatcher.php
 * (patrz $AGENTS['tasks_nozbe_sync']), NIE bezpośrednio w docker/crontab.
 *
 * Dla każdego użytkownika z aktywną konfiguracją (task_nozbe_tokens) wypycha
 * jego przypisane, aktywne zadania do jego WŁASNEGO Nozbe (task_me — osobisty
 * Inbox domyślnie). Jednostronnie: SZO → Nozbe. Zob. includes/task_nozbe.php.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/tasks.php';
require_once $base_dir . '/includes/task_nozbe.php';

echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_nozbe_sync\n";

$user_ids = task_nozbe_active_user_ids();
if (!$user_ids) {
    echo "  Brak użytkowników z aktywnym połączeniem Nozbe.\n";
    exit(0);
}

$totalPushed = $totalUpdated = $totalCompleted = $totalErrors = 0;

foreach ($user_ids as $uid) {
    $r = task_nozbe_sync_user($uid);
    if ($r['ok']) {
        echo "  ✓ user #{$uid}: pushed={$r['pushed']} updated={$r['updated']} completed={$r['completed']}\n";
        $totalPushed    += $r['pushed'];
        $totalUpdated   += $r['updated'];
        $totalCompleted += $r['completed'];
    } else {
        echo "  ✗ user #{$uid}: {$r['error']}\n";
        $totalErrors++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec: użytkownicy=" . count($user_ids)
   . " pushed={$totalPushed} updated={$totalUpdated} completed={$totalCompleted} błędy={$totalErrors}\n";
