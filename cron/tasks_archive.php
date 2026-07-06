<?php
/**
 * Skrypt cron: Archiwizacja ukończonych zadań.
 * Uruchamiaj raz dziennie, np. o 3:10:
 *   10 3 * * * php /var/www/html/cron/tasks_archive.php
 *
 * Zadania ukończone (completed_at) więcej niż 7 dni temu są oznaczane
 * jako zarchiwizowane (archived_at) — znikają z tablicy/tabeli zadań,
 * ale pozostają dostępne w Archiwum zadań (tasks/archive.php) i można
 * je stamtąd przywrócić.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/tasks.php';

task_areas_migrate(); // self-heal: zapewnia istnienie kolumny archived_at

echo "[" . date('Y-m-d H:i:s') . "] Start: tasks_archive\n";

$now = date('Y-m-d H:i:s');

$to_archive = db_all(
    "SELECT id, title, created_by FROM tasks
     WHERE completed_at IS NOT NULL
       AND completed_at <= datetime('now','-7 days')
       AND archived_at IS NULL
       AND deleted_at IS NULL"
);

$done = 0;
$errs = 0;

foreach ($to_archive as $t) {
    try {
        db()->prepare("UPDATE tasks SET archived_at=? WHERE id=?")->execute([$now, (int)$t['id']]);
        task_log((int)$t['id'], (int)$t['created_by'], 'archived');
        echo "  ✓ zarchiwizowano #{$t['id']}: {$t['title']}\n";
        $done++;
    } catch (\Throwable $e) {
        echo "  ✗ #{$t['id']}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Zarchiwizowano: $done, błędy: $errs\n";
