<?php
/**
 * Skrypt cron: Przypomnienia SMS o zajęciach TI zaplanowanych na jutro.
 * Uruchamiany przez cron/dispatcher.php (raz dziennie, rano).
 *
 * Wysyła SMS do kursantów/opiekunów, którzy mają włączone powiadomienia
 * o zajęciach (te same zgody co przy dodaniu nowej lekcji — reużywa
 * ti_lesson_sms_notify()). Każda lekcja przypominana jest tylko raz
 * (reminder_sent_at w k30_ti_sessions).
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/karty30.php';
require_once $base_dir . '/includes/sms_templates.php';

karty30_migrate();

echo "[" . date('Y-m-d H:i:s') . "] Start: ti_lesson_reminders\n";

$tomorrow = date('Y-m-d', strtotime('+1 day'));

$sessions = db_all(
    "SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, c.name AS course_name
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id = s.course_id
     WHERE s.lesson_date = ? AND s.status = 'planned' AND s.reminder_sent_at IS NULL",
    [$tomorrow]
);

$sent = 0; $skip = 0; $errs = 0;

foreach ($sessions as $s) {
    try {
        $tpl = sms_tpl_render('ti_lesson_reminder', [
            'course' => (string)$s['course_name'],
            'when'   => $s['time_from'] ? ' o ' . substr((string)$s['time_from'], 0, 5) : '',
        ]);
        db()->prepare("UPDATE k30_ti_sessions SET reminder_sent_at = datetime('now') WHERE id = ?")
            ->execute([$s['id']]);
        if (!$tpl['enabled']) { $skip++; continue; }
        $n = ti_lesson_sms_notify((int)$s['course_id'], $tpl['message']);
        if ($n > 0) { $sent++; } else { $skip++; }
    } catch (\Throwable $e) {
        $errs++;
        echo "[" . date('Y-m-d H:i:s') . "] BLAD lekcja #{$s['id']}: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Lekcje jutro: " . count($sessions) . ", z wysłanym SMS: {$sent}, bez odbiorców: {$skip}, błędy: {$errs}\n";
