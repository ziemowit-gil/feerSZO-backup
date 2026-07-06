#!/usr/bin/env php
<?php
/**
 * cron/poczta_dispatch.php — Generuje zadania skanowania skrzynek (moduł Poczta).
 *
 * Uruchamiany przez cron/dispatcher.php co poczta_scan_interval_min minut.
 * Dla każdej aktywnej skrzynki (poczta_mailboxes, opt-in) publikuje zadanie na
 * kolejkę RabbitMQ „poczta_skanowanie" (jeśli skonfigurowana) — inaczej skanuje
 * synchronicznie (fallback bez kolejki, jak cron/mail_queue.php).
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

if (!module_enabled('poczta_enabled')) {
    echo "[SKIP] " . date('Y-m-d H:i:s') . " Moduł Poczty jest wyłączony.\n";
    exit(0);
}

$graph = new M365Graph();
if (!$graph->is_configured()) {
    echo "[SKIP] " . date('Y-m-d H:i:s') . " Brak konfiguracji Microsoft 365 — pomiń skanowanie skrzynek.\n";
    exit(0);
}

// Okno godzinowe i interwał są konfigurowalne w admin/poczta_settings.php — dispatcher.php
// wywołuje ten skrypt co 10 min jako zewnętrzny limit; tu honorujemy dokładniejsze ustawienia admina.
$hour_from = (int)(org_setting('poczta_scan_hour_from') ?: '6');
$hour_to   = (int)(org_setting('poczta_scan_hour_to') ?: '23');
$hour_now  = (int)date('G');
if ($hour_now < $hour_from || $hour_now >= $hour_to) {
    echo "[SKIP] " . date('Y-m-d H:i:s') . " Poza skonfigurowanym oknem godzinowym ({$hour_from}-{$hour_to}).\n";
    exit(0);
}

$interval_min = max(1, (int)(org_setting('poczta_scan_interval_min') ?: '10'));
$last_run     = org_setting('poczta_last_dispatch_at');
if ($last_run && (time() - strtotime($last_run)) < $interval_min * 60) {
    echo "[SKIP] " . date('Y-m-d H:i:s') . " Interwał ({$interval_min} min) jeszcze nie minął od ostatniego uruchomienia.\n";
    exit(0);
}
org_setting_set('poczta_last_dispatch_at', date('Y-m-d H:i:s'));

$result = PocztaScanService::dispatch_pending();

echo sprintf(
    "[DONE] %s Poczta: zakolejkowano=%d, zeskanowano od razu=%d, błędów=%d\n",
    date('Y-m-d H:i:s'),
    $result['queued'],
    $result['scanned'],
    count($result['errors'])
);
foreach ($result['errors'] as $err) {
    echo "[ERROR] " . date('Y-m-d H:i:s') . " {$err}\n";
}

exit(empty($result['errors']) ? 0 : 1);
