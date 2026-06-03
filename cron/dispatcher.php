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
    'bulk_email' => [
        'file'     => __DIR__ . '/bulk_email_process.php',
        'interval' => 60,           // co minutę
    ],
    'contract_expiry' => [
        'file'     => __DIR__ . '/contract_expiry_reminder.php',
        'interval' => 86400,
        'schedule' => [8, 10],
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
    'backup' => [
        'file'     => __DIR__ . '/agents/backup.php',
        'interval' => 86400,
        'schedule' => [1, 3],
    ],
    'sync_crm_volunteers' => [
        'file'     => __DIR__ . '/sync_crm_volunteers.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [3, 5],       // między 3:00 a 5:00
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
