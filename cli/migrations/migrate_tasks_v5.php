<?php
/**
 * Migracja v5: Tabele powiadomień email do modułu Zadań
 * Idempotentna — można uruchamiać wielokrotnie.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$log = [];

function t5(string $sql, string $label): void {
    global $log;
    try { db()->exec($sql); $log[] = ['ok', $label]; }
    catch (\Throwable $e) { $log[] = ['err', $label . ' — ' . $e->getMessage()]; }
}

// ── Preferencje powiadomień per użytkownik ────────────────────────────────
t5("CREATE TABLE IF NOT EXISTS task_notification_prefs (
    user_id          INTEGER PRIMARY KEY,
    notify_assigned  INTEGER NOT NULL DEFAULT 1,  -- przypisanie do zadania
    notify_mentioned INTEGER NOT NULL DEFAULT 1,  -- @wzmianka w komentarzu
    notify_comment   INTEGER NOT NULL DEFAULT 0,  -- nowy komentarz w zadaniu
    notify_due_1day  INTEGER NOT NULL DEFAULT 1,  -- dzień przed terminem
    notify_due_today INTEGER NOT NULL DEFAULT 1,  -- w dniu terminu
    notify_sms       INTEGER NOT NULL DEFAULT 0,  -- powiadomienia SMS
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)", 'Tabela task_notification_prefs');

// ── Dziennik wysłanych powiadomień (dedupl. + audyt) ─────────────────────
t5("CREATE TABLE IF NOT EXISTS task_notification_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    event_type TEXT    NOT NULL,  -- assigned|comment|mention|due_1day|due_today
    ref_id     INTEGER NOT NULL,  -- comment_id lub task_id
    sent_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)", 'Tabela task_notification_log');

t5("CREATE UNIQUE INDEX IF NOT EXISTS idx_notif_log_dedup
    ON task_notification_log(user_id, event_type, ref_id, date(sent_at))",
   'Indeks dedupl. task_notification_log');

t5("CREATE INDEX IF NOT EXISTS idx_notif_log_sent
    ON task_notification_log(sent_at)", 'Indeks task_notification_log.sent_at');

$is_cli = php_sapi_name() === 'cli';
if ($is_cli) { foreach ($log as [$s,$m]) echo ($s==='ok'?'✓':'✗')." $m\n"; exit; }
?>
<!DOCTYPE html>
<html lang="pl"><head><meta charset="UTF-8"><title>Migracja v5</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4"><div class="container" style="max-width:640px">
<h5 class="mb-3">Migracja v5 — Powiadomienia email</h5>
<ul class="list-group mb-3">
<?php foreach ($log as [$s,$m]): ?>
<li class="list-group-item list-group-item-<?= $s==='ok'?'success':'danger' ?> py-2 small">
  <?= $s==='ok'?'✓':'✗' ?> <?= htmlspecialchars($m) ?>
</li>
<?php endforeach; ?>
</ul>
<?php if (!array_filter($log, fn($l)=>$l[0]==='err')): ?>
<div class="alert alert-success">Gotowe — <a href="<?= APP_URL ?>/tasks/index.php">wróć do zadań</a></div>
<?php endif; ?>
</div></body></html>
