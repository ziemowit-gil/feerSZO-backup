#!/usr/bin/env php
<?php
/**
 * cron/agents/warden.php — AccountWarden („Mietek”): codzienny przebieg strażnika nieaktywnych kont (Logto + SZO).
 * Ostrzeżenia e-mail (dzień 0 i 4), SMS (dzień 6), zawieszenie w Logto i blokada w SZO po 7 dniach bez logowania.
 * Uruchamiany przez cron/dispatcher.php raz na dobę (9:00–12:00). Wyłącznik: panel /modules/warden/router.php (Zatrzymaj).
 * Ręcznie: php cron/agents/warden.php            (według trybu z panelu)
 *          php cron/agents/warden.php --preview  (sam podgląd, nic nie wysyła)
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 2) . '/modules/warden/logic/WardenEngine.php';

use Feer\Szo\Warden\WardenEngine;

@set_time_limit(0);
$mode = in_array('--preview', $argv ?? [], true) ? 'preview' : null;
if ($mode === null && WardenEngine::mode() === 'off') { echo "WARDEN: wyłączony.\n"; exit(0); }
try {
    $r = WardenEngine::fromSzo()->run('cron', $mode);
} catch (\Throwable $e) {
    fwrite(STDERR, 'WARDEN: ' . $e->getMessage() . "\n");
    exit(1);
}
echo 'WARDEN (' . $r['mode'] . '): ' . json_encode($r['counts'] ?? [], JSON_UNESCAPED_UNICODE), "\n";
foreach ($r['items'] ?? [] as $i) echo '  ', $i['action'], ' ', $i['email'], ': ', $i['note'], "\n";
if (!empty($r['error'])) { fwrite(STDERR, 'WARDEN: ' . $r['error'] . "\n"); exit(1); }
exit(0);
