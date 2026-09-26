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
require_once dirname(__DIR__) . '/includes/db.php';
// Rejestr agentów + nadpisania z panelu admina (admin/cron_dispatcher.php).
require_once dirname(__DIR__) . '/modules/cron_dispatcher/logic/cron_dispatcher.php';

$AGENTS = cron_dispatcher_effective(cron_dispatcher_registry());
cron_dispatcher_heartbeat();

$lock_dir = sys_get_temp_dir();
$now      = time();
$hour     = (int)date('G');

foreach ($AGENTS as $name => $cfg) {
    $agent_file = $cfg['file'];

    if (!file_exists($agent_file)) {
        echo "[SKIP] {$name}: plik nie istnieje ({$agent_file})\n";
        continue;
    }

    // „Uruchom teraz” z panelu — pomija okno godzinowe i interwał (także dla wyłączonego).
    $manual = !empty($cfg['run_requested']);

    // Wyłączony w panelu admina
    if (!$manual && empty($cfg['enabled'])) {
        continue;
    }

    // Sprawdź okno godzinowe
    if (!$manual && isset($cfg['schedule'])) {
        [$h_from, $h_to] = $cfg['schedule'];
        if ($hour < $h_from || $hour >= $h_to) {
            continue;
        }
    }

    // Sprawdź interwał — może być liczbą (sekundy) albo nazwą funkcji
    // zwracającej liczbę dynamicznie (patrz sync_m365 / okres ochronny).
    $interval = is_callable($cfg['interval']) ? (int)call_user_func($cfg['interval']) : (int)$cfg['interval'];
    $lock = $lock_dir . '/umowy_cron_' . $name . '.last';
    $last = file_exists($lock) ? (int)file_get_contents($lock) : 0;
    if (!$manual && $now - $last < $interval) {
        continue;
    }

    // Zapisz czas uruchomienia PRZED startem (zapobiega podwójnemu uruchomieniu)
    file_put_contents($lock, $now);

    $php  = PHP_BINARY ?: 'php';
    $cmd  = escapeshellarg($php) . ' ' . escapeshellarg($agent_file);
    // Argumenty agenta (np. --apply dla skryptów z trybem podglądu) — każdy osobno
    // przez escapeshellarg, żeby wpis w rejestrze nie mógł doklejać poleceń powłoki.
    foreach (preg_split('/\s+/', trim((string)($cfg['args'] ?? ''))) as $arg) {
        if ($arg !== '') $cmd .= ' ' . escapeshellarg($arg);
    }
    $log  = defined('LOG_PATH') ? LOG_PATH . '/cron_' . $name . '.log' : '/dev/null';

    // Uruchom asynchronicznie — nie blokuj dyspozytora
    exec("{$cmd} >> " . escapeshellarg($log) . " 2>&1 &");

    cron_dispatcher_mark_run($name, $manual ? 'manual' : 'auto');
    echo "[RUN] {$name} @ " . date('H:i:s') . ($manual ? ' (ręcznie z panelu)' : '') . "\n";
}
