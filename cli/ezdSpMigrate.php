<?php
/**
 * cli/ezdSpMigrate.php — migracja plików EZD z SharePointa do chmury Nextcloud (konto ezdmigracja) i na FTP, ze ścieżkami
 * jak w bibliotece SharePoint (modules/ezdStorage/lib/EzdSpMigrator.php). Polecenie 2026-11-14: „zrób też migrator EZD
 * z sharepointa zachowując ścieżki”. To samo robi co 10 minut agent CRON ezd_store_sync i przycisk na ekranie kopii.
 *
 * Użycie (w katalogu aplikacji):
 *   php cli/ezdSpMigrate.php --status
 *   php cli/ezdSpMigrate.php --run [--limit=200] [--wszystkie]   # --wszystkie: także pliki po 5 nieudanych próbach
 *   php cli/ezdSpMigrate.php --wylacz | --wlacz                    # wyłączona: pliki idą zwykłą kopią z dysku
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/ezd.php';
require_once $base . '/modules/ezdStorage/lib/EzdSpMigrator.php';

$opt = cli_getopt('', ['status', 'run', 'wylacz', 'wlacz', 'limit:', 'wszystkie']);
$opt = is_array($opt) ? $opt : [];

if (isset($opt['wylacz']) || isset($opt['wlacz'])) {
    EzdSpMigrator::setEnabled(isset($opt['wlacz']));
    echo 'Migracja z SharePointa: ' . (isset($opt['wlacz']) ? 'włączona.' : 'wyłączona — pliki idą zwykłą kopią z dysku.') . "\n";
    cli_exit(0);
}

if (isset($opt['run'])) {
    @set_time_limit(0);
    $limit = max(1, (int)($opt['limit'] ?? 200));
    $r = EzdSpMigrator::run($limit, 3000, isset($opt['wszystkie']));
    echo 'Pliki: ' . $r['files'] . ', przeniesione: ' . $r['ok'] . ', starsze wersje (pójdą z dysku): ' . $r['older']
       . ', brak na SharePoincie: ' . $r['missing'] . ', błędy: ' . $r['errors'] . ', zostało: ' . $r['left'] . "\n";
    if ($r['stopped'] !== '') echo 'Zatrzymano: ' . $r['stopped'] . "\n";
    cli_exit($r['errors'] || ($r['stopped'] !== '' && $r['left']) ? 1 : 0);
}

// --status (domyślnie)
$s = EzdSpMigrator::stats();
echo 'Migracja z SharePointa: ' . ($s['enabled'] ? 'włączona' : 'WYŁĄCZONA') . '; Microsoft 365: ' . ($s['configured'] ? 'skonfigurowany' : 'brak')
   . ($s['paused'] ? '; przerwa do ' . date('H:i:s', $s['paused']) : '') . "\n";
echo 'Folder bazowy SZO na SharePoincie: ' . (EzdSpMigrator::base() ?: '(korzeń biblioteki)') . ' → chmura: ' . EzdStorage::ncRoot() . '/…, FTP: '
   . (EzdStorage::ftpConfigured() ? EzdStorage::ftpSettings()['dir'] . '/…' : '(wyłączony)') . "\n";
echo "Pliki EZD z kopią na SharePoincie: {$s['all']}; przeniesione: {$s['moved']}; starsze wersje: {$s['older']}; "
   . "brak na SharePoincie: {$s['missing']}; z błędem: {$s['errors']}; czeka: {$s['left']}\n";
cli_exit(0);
