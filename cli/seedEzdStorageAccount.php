<?php
/**
 * cli/seedEzdStorageAccount.php — konto techniczne „ezdmigracja” w chmurze plików (Nextcloud) na kopie dokumentów EZD
 * (modules/ezdStorage). Polecenie 2026-11-14: „zróbmy usera ezdmigracja i przepiszmy zapisywanie dokumentów na sharepoint
 * na przypisywanie na FTP i NC, a jak niedostępne tylko na nc na użytkowniku ezdmigracja”.
 *
 * Konto istnieje tylko w chmurze. W SZO są wyłącznie ustawienia ezd_store_nc_user i ezd_store_nc_pass (hasło aplikacji,
 * fcEncrypt), bez rekordu w users. Zakłada je konto API SZO (SsoConfig::cloud() — administrator chmury) przez OCS.
 * Hasła nigdzie nie wypisujemy.
 *
 * Użycie (w katalogu aplikacji):
 *   php cli/seedEzdStorageAccount.php --status            # chmura, konto, FTP, liczby kopii
 *   php cli/seedEzdStorageAccount.php --create [--uid=ezdmigracja]
 *   php cli/seedEzdStorageAccount.php --test              # zapis/odczyt/usunięcie pliku testowego w chmurze i na FTP
 *   php cli/seedEzdStorageAccount.php --forget            # SZO przestaje wysyłać kopie do chmury (konto i pliki w chmurze zostają)
 * To samo z ekranu: Ustawienia Wirtualnego biurka → Kopie dokumentów (FTP + Nextcloud).
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
require_once $base . '/modules/ezdStorage/lib/EzdStorage.php';

$opt = cli_getopt('', ['status', 'create', 'test', 'forget', 'uid:']);
$opt = is_array($opt) ? $opt : [];

if (isset($opt['create'])) {
    $r = EzdStorage::createAccount((string)($opt['uid'] ?? EzdStorage::NC_UID));
    echo $r['msg'] . "\n";
    cli_exit($r['ok'] ? 0 : 1);
}

if (isset($opt['forget'])) {
    EzdStorage::forgetAccount();
    echo "Odłączono konto chmury od kopii EZD. Konto i pliki w chmurze zostały bez zmian.\n";
    cli_exit(0);
}

if (isset($opt['test'])) {
    $fail = 0;
    foreach (['Chmura' => fn() => EzdStorage::testNc(), 'FTP' => fn() => EzdStorage::testFtp()] as $label => $fn) {
        try { echo $label . ': ' . $fn() . "\n"; }
        catch (\Throwable $e) { echo $label . ': BŁĄD — ' . $e->getMessage() . "\n"; $fail++; }
    }
    cli_exit($fail ? 1 : 0);
}

// --status (domyślnie)
$url = SsoConfig::cloudUrl();
echo 'Chmura: ' . ($url !== '' ? $url : '(nie skonfigurowana w /ssoadmin)') . "\n";
echo 'Konto na kopie EZD: ' . (EzdStorage::ncConfigured() ? EzdStorage::ncUser() . ', folder ' . EzdStorage::ncRoot() : '(brak — uruchom --create)')
   . (EzdStorage::paused('nc') ? ' — PRZERWA do ' . date('H:i:s', EzdStorage::pausedUntil('nc')) : '') . "\n";
$f = EzdStorage::ftpSettings();
echo 'FTP: ' . (EzdStorage::ftpConfigured() ? $f['user'] . '@' . $f['host'] . ':' . $f['port'] . ' ' . $f['dir'] . ' (' . $f['tls'] . ')' : ($f['host'] !== '' ? 'wyłączony' : 'nie skonfigurowany'))
   . (EzdStorage::paused('ftp') ? ' — PRZERWA do ' . date('H:i:s', EzdStorage::pausedUntil('ftp')) : '') . "\n";
$s = EzdStorage::stats();
echo "Pliki EZD: {$s['total']}; kopie w chmurze: {$s['nc']}, na FTP: {$s['ftp']}; błędy: chmura {$s['nc_err']}, FTP {$s['ftp_err']}; brak na dysku: {$s['missing']}\n";
cli_exit(0);
