#!/usr/bin/env php
<?php
/**
 * cron/agents/ezd_store_sync.php — dosyła kopie dokumentów EZD na FTP i do chmury Nextcloud (modules/ezdStorage):
 * pliki, których kopia nie poszła przy zapisie (cel niedostępny, przerwa po awarii), oraz pliki sprzed włączenia kopii
 * (migracja istniejących dokumentów — najnowsze najpierw).
 *
 * Uruchamiany przez cron/dispatcher.php co 10 min, porcja do 200 plików / ~4 min. Pomija cicho (exit 0), gdy żaden cel
 * nie jest skonfigurowany. Plik bez kopii po 5 nieudanych próbach czeka na ręczne ponowienie (--wszystkie albo przycisk
 * w ustawieniach).
 * Ręcznie: php cron/agents/ezd_store_sync.php [--limit=N] [--wszystkie]
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';
require_once dirname(__DIR__, 2) . '/modules/ezdStorage/lib/EzdStorage.php';

if (!EzdStorage::ncConfigured() && !EzdStorage::ftpConfigured()) exit(0);
@set_time_limit(600);
$limit = 200;
$all = false;
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = max(1, (int)$m[1]);
    if ($a === '--wszystkie') $all = true;
}
$r = EzdStorage::syncBatch($limit, 240, $all);
if ($r['files'] || $r['left']) {
    echo 'pliki: ' . $r['files'] . ', do chmury: ' . $r['nc'] . ', na FTP: ' . $r['ftp'] . ', błędy: ' . $r['errors'] . ', zostało: ' . $r['left']
       . (EzdStorage::paused('nc') ? ' (chmura: przerwa)' : '') . (EzdStorage::paused('ftp') ? ' (FTP: przerwa)' : '') . "\n";
}
exit($r['errors'] ? 1 : 0);
