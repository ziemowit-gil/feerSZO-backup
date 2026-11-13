#!/usr/bin/env php
<?php
/**
 * cron/agents/ws_cloud_shares.php — udostępnienia folderów obszarów Zadań w chmurze (modules/wsCloud/lib/WsCloud.php):
 * członek obszaru z kontem w chmurze ma udostępniony folder obszaru (viewer — odczyt, pozostali — edycja), osoba spoza
 * obszaru traci udostępnienie. Uzupełnia synchronizację przy zmianie członków (zmiany spoza ekranu ustawień obszaru,
 * nowe konta w chmurze).
 *
 * Uruchamiany przez cron/dispatcher.php co 1 h. Pomija cicho (exit 0), gdy chmura nie jest skonfigurowana.
 * Ręcznie: php cron/agents/ws_cloud_shares.php [--dry-run]
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/wsCloud/lib/WsCloud.php';

if (!WsCloud::available()) exit(0);
@set_time_limit(600);
$dry = in_array('--dry-run', $argv ?? [], true);
$errors = 0;
foreach (WsCloud::syncAll($dry) as $wsId => $r) {
    if ($r['error'] !== '') $errors++;
    if ($r['added'] || $r['updated'] || $r['removed'] || $r['error'] !== '') {
        echo 'obszar ' . $wsId . ': dodane ' . count($r['added']) . ', zmienione ' . count($r['updated']) . ', usunięte ' . count($r['removed'])
           . ($r['error'] !== '' ? ', błąd: ' . $r['error'] : '') . ($dry ? ' (podgląd)' : '') . "\n";
    }
}
exit($errors ? 1 : 0);
