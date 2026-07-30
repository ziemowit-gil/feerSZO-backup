#!/usr/bin/env php
<?php
/**
 * cron/dispatcher.php — Główny dyspozytor agentów CRON.
 *
 * Jeden wpis w crontab, uruchamiany co minutę:
 *   * * * * * php /var/www/umowy/cron/dispatcher.php >> /var/log/umowy_cron.log 2>&1
 *
 * Każdy agent ma własny interwał (sekundy). Dyspozytor sprawdza plik blokady
 * z czasem ostatniego uruchomienia i wywołuje agenta jeśli interwał minął.
 * Agenci uruchamiani asynchronicznie (nie blokują dyspozytora).
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';

// ── Rejestr agentów ───────────────────────────────────────────────────────
// interval: minimalna przerwa między uruchomieniami (sekundy)
// schedule: opcjonalne ograniczenie godzinowe [H_start, H_end] — tylko w tym zakresie
$AGENTS = [
    'mail_queue' => [
        'file'     => __DIR__ . '/mail_queue.php',
        'interval' => 300,          // co 5 min
    ],
    'tasks_reminder' => [
        'file'     => __DIR__ . '/tasks_due_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'tasks_recurring' => [
        'file'     => __DIR__ . '/tasks_recurring.php',
        'interval' => 86400,
        'schedule' => [6, 8],
    ],
    'sync_m365' => [
        'file'     => __DIR__ . '/sync_m365.php',
        'interval' => 900,          // co 15 min
    ],
    'kdok_cleanup' => [
        'file'     => __DIR__ . '/kdok_cleanup.php',
        'interval' => 604800,       // raz w tygodniu
        'schedule' => [2, 4],       // między 2:00 a 4:00
    ],
    'contract_auto_complete' => [
        'file'     => __DIR__ . '/contract_auto_complete.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [4, 6],       // między 4:00 a 6:00
    ],
    'campaign_send' => [
        'file'     => __DIR__ . '/campaign_send.php',
        'interval' => 60,           // co minutę
    ],
    'bulk_email' => [
        'file'     => __DIR__ . '/bulk_email_process.php',
        'interval' => 60,           // co minutę
    ],
    'contract_expiry' => [
        'file'     => __DIR__ . '/contract_expiry_reminder.php',
        'interval' => 86400,
        'schedule' => [8, 10],
    ],
    'volunteer_account_reminder' => [
        'file'     => __DIR__ . '/volunteer_account_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [8, 10],      // między 8:00 a 10:00
    ],
    'never_logged_in_reminder' => [
        'file'     => __DIR__ . '/agents/never_logged_in_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [9, 11],      // między 9:00 a 11:00
    ],
    'zus_reminder' => [
        'file'     => __DIR__ . '/zus_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [8, 10],      // między 8:00 a 10:00
    ],
    'ezd_reminder' => [
        'file'     => __DIR__ . '/ezd_deadline_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'process_m365_queue' => [
        'file'     => __DIR__ . '/process_m365_queue.php',
        'interval' => 300,          // co 5 min
    ],
    'sync_m365_reverse' => [
        'file'     => __DIR__ . '/sync_m365_reverse.php',
        'interval' => 3600,         // co godzinę
        'schedule' => [1, 23],
    ],
    'k30_m365_expire' => [
        'file'     => __DIR__ . '/k30_m365_expire.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [2, 5],       // między 2:00 a 5:00
    ],
    'k30_ti_billing_autoissue' => [
        'file'     => __DIR__ . '/k30_ti_billing_autoissue.php',
        'interval' => 86400,        // raz dziennie (skrypt działa tylko w ostatni dzień miesiąca)
        'schedule' => [21, 23],     // wieczorem ostatniego dnia miesiąca
    ],
    'backup' => [
        'file'     => __DIR__ . '/agents/backup.php',
        'interval' => 14400,        // co 4h — backup przyrostowy (lokalny)
    ],
    'sp_backup_incremental' => [
        'file'     => __DIR__ . '/agents/sp_backup_incremental.php',
        'interval' => 21600,        // co 6h — backup przyrostowy na SharePoint
    ],
    'sp_backup_full' => [
        'file'     => __DIR__ . '/agents/sp_backup_full.php',
        'interval' => 86400,        // raz dziennie — pełny backup systemu na SharePoint
        'schedule' => [1, 3],       // w nocy
    ],
    'sync_crm_volunteers' => [
        'file'     => __DIR__ . '/sync_crm_volunteers.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [3, 5],       // między 3:00 a 5:00
    ],
    'outlook_calendar_sync' => [
        'file'     => __DIR__ . '/outlook_calendar_sync.php',
        'interval' => 3600,         // co godzinę
        'schedule' => [6, 23],      // w godzinach pracy
    ],
    'sync_users_to_test' => [
        'file'     => __DIR__ . '/sync_users_to_test.php',
        'interval' => 3600,         // co godzinę
    ],
    'crm_inbox_watch' => [
        'file'     => __DIR__ . '/crm_inbox_watch.php',
        'interval' => 600,          // co 10 min
        'schedule' => [6, 23],      // w godzinach pracy
    ],
    'poczta_dispatch' => [
        'file'     => __DIR__ . '/poczta_dispatch.php',
        'interval' => 600,          // co 10 min — generuje zadania skanowania skrzynek
        'schedule' => [6, 23],      // w godzinach pracy
    ],
    'poczta_worker' => [
        'file'     => __DIR__ . '/poczta_worker.php',
        'interval' => 60,           // co minutę — konsumuje kolejkę RabbitMQ „poczta_skanowanie"
        'schedule' => [6, 23],
    ],
];

$lock_dir = sys_get_temp_dir();
$now      = time();
$hour     = (int)date('G');

foreach ($AGENTS as $name => $cfg) {
    $agent_file = $cfg['file'];

    if (!file_exists($agent_file)) {
        echo "[SKIP] {$name}: plik nie istnieje ({$agent_file})\n";
        continue;
    }

    // Sprawdź okno godzinowe
    if (isset($cfg['schedule'])) {
        [$h_from, $h_to] = $cfg['schedule'];
        if ($hour < $h_from || $hour >= $h_to) {
            continue;
        }
    }

    // Sprawdź interwał
    $lock = $lock_dir . '/umowy_cron_' . $name . '.last';
    $last = file_exists($lock) ? (int)file_get_contents($lock) : 0;
    if ($now - $last < $cfg['interval']) {
        continue;
    }

    // Zapisz czas uruchomienia PRZED startem (zapobiega podwójnemu uruchomieniu)
    file_put_contents($lock, $now);

    $php  = PHP_BINARY ?: 'php';
    $cmd  = escapeshellarg($php) . ' ' . escapeshellarg($agent_file);
    $log  = defined('LOG_PATH') ? LOG_PATH . '/cron_' . $name . '.log' : '/dev/null';

    // Uruchom asynchronicznie — nie blokuj dyspozytora
    exec("{$cmd} >> " . escapeshellarg($log) . " 2>&1 &");

    echo "[RUN] {$name} @ " . date('H:i:s') . "\n";
}
