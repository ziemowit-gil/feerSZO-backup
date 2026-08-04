#!/usr/bin/env php
<?php
/**
 * cron/tasks_recurring.php — Auto-tworzenie kolejnej instancji powtarzającego się zadania
 *
 * Uruchamiaj codziennie rano, np.:
 *   0 6 * * * php /path/to/cron/tasks_recurring.php
 *
 * Logika:
 *   - Znajdź ukończone zadania z recurrence != null
 *   - Sprawdź czy minął czas na kolejną instancję
 *   - Utwórz kopię z przesuniętym terminem
 *   - Skopiuj tagi i przypisania
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/task_notify.php';

$now  = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$created = 0; $skipped = 0;

// Pobierz zadania ukończone z powtarzalnością, bez już istniejącej kopii
$recurring = db_all(
    "SELECT t.*
     FROM tasks t
     WHERE t.recurrence IS NOT NULL
       AND t.recurrence != 'none'
       AND t.completed_at IS NOT NULL
       AND t.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM tasks t2
           WHERE t2.recurrence_parent_id = t.id
             AND t2.deleted_at IS NULL
             AND t2.completed_at IS NULL
       )
     ORDER BY t.completed_at"
);

foreach ($recurring as $t) {
    // Sprawdź czy data zakończenia powtarzania nie minęła
    if ($t['recurrence_end_date'] && $t['recurrence_end_date'] < $today) {
        $skipped++;
        continue;
    }

    // Oblicz nowy due_date
    $base_due = $t['due_date'] ?: $today;
    $new_due  = match($t['recurrence']) {
        'daily'   => date('Y-m-d', strtotime($base_due . ' +1 day')),
        'weekly'  => date('Y-m-d', strtotime($base_due . ' +1 week')),
        'monthly' => date('Y-m-d', strtotime($base_due . ' +1 month')),
        'yearly'  => date('Y-m-d', strtotime($base_due . ' +1 year')),
        default   => null,
    };

    if (!$new_due) { $skipped++; continue; }

    // Nowy start_date (jeśli był)
    $new_start = null;
    if ($t['start_date'] && $t['due_date']) {
        $span = strtotime($t['due_date']) - strtotime($t['start_date']);
        $new_start = date('Y-m-d', strtotime($new_due) - $span);
    }

    // Utwórz kopię
    $new_id = db_insert('tasks', [
        'workspace_id'          => $t['workspace_id'],
        'list_id'               => $t['list_id'],
        'title'                 => $t['title'],
        'description'           => $t['description'],
        'priority'              => $t['priority'],
        'start_date'            => $new_start,
        'due_date'              => $new_due,
        'estimated_hours'       => $t['estimated_hours'],
        'recurrence'            => $t['recurrence'],
        'recurrence_end_date'   => $t['recurrence_end_date'],
        'recurrence_parent_id'  => $t['id'],
        'created_by'            => $t['created_by'],
        'created_at'            => $now,
        'updated_at'            => $now,
        'position'              => (float)(db_one(
            "SELECT COALESCE(MAX(position),0)+1 AS p FROM tasks WHERE list_id=?",
            [$t['list_id']]
        )['p'] ?? 1),
    ]);

    // Skopiuj tagi
    $tags = db_all("SELECT tag_id FROM task_task_tags WHERE task_id=?", [$t['id']]);
    foreach ($tags as $tag) {
        try {
            db()->prepare("INSERT OR IGNORE INTO task_task_tags (task_id,tag_id) VALUES (?,?)")
                ->execute([$new_id, $tag['tag_id']]);
        } catch (\Throwable $e) {}
    }

    // Skopiuj przypisania
    $assigns = db_all("SELECT user_id, assigned_by FROM task_assignments WHERE task_id=?", [$t['id']]);
    foreach ($assigns as $a) {
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO task_assignments (task_id,user_id,assigned_by,assigned_at) VALUES (?,?,?,?)"
            )->execute([$new_id, $a['user_id'], $a['assigned_by'], $now]);
        } catch (\Throwable $e) {}
    }

    task_log($new_id, (int)$t['created_by'], 'created', null,
        'Auto: ' . $t['recurrence'] . ' (parent #' . $t['id'] . ')');

    // Powiadom przypisanych o nowej instancji zadania cyklicznego
    try {
        task_notify_created($new_id, (int)$t['created_by']);
    } catch (\Throwable $e) {
        error_log('[tasks_recurring] notify failed for #' . $new_id . ': ' . $e->getMessage());
    }

    $created++;
    echo "[OK] Utworzono zadanie #{$new_id} \"{$t['title']}\" → termin: $new_due\n";
}

echo "\n✓ Cykl: +{$created} nowych, {$skipped} pominiętych.\n";
