<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';

/**
 * modules/cloudDav/lib/NcPim.php — zapis kalendarzy i kontaktów do Nextcloud po stronie serwera (bez haseł użytkowników).
 *
 * Działa tylko, gdy SZO i chmura są na tym samym serwerze (dziś MyDevil, konto feer):
 *   - wydarzenia: occ calendar:import <uid> <uri> <plik> --supersede (Nextcloud 35; ten sam UID = nadpisanie);
 *   - kalendarz z nazwą, książka adresowa i karty vCard: modules/cloudDav/nc/szoNcPim.php uruchamiany PHP Nextclouda.
 * Ustawienia (settings, opcjonalne): cloud_occ_path (domyślnie ~/nextcloud/bin/occ), cloud_nc_php (/usr/local/bin/php84
 * albo bieżące PHP), cloud_nc_root (~/domains/{host chmury}/public_html). Testy: stałe PIM_OCC, PIM_NC_PHP, PIM_NC_ROOT
 * albo NcPim::$exec (atrapa uruchamiania).
 */
final class NcPim
{
    /** Atrapa w testach: fn(array $argv, int $timeout): array{0:int,1:string,2:string}. */
    public static ?\Closure $exec = null;

    public static function home(): string
    {
        $h = (string)getenv('HOME');
        if ($h === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) $h = (string)(posix_getpwuid(posix_geteuid())['dir'] ?? '');
        return rtrim($h, '/');
    }

    public static function occ(): string
    {
        if (defined('PIM_OCC')) return (string)PIM_OCC;
        $s = function_exists('org_setting') ? trim(org_setting('cloud_occ_path')) : '';
        return $s !== '' ? str_replace('~', self::home(), $s) : self::home() . '/nextcloud/bin/occ';
    }

    public static function php(): string
    {
        if (defined('PIM_NC_PHP')) return (string)PIM_NC_PHP;
        $s = function_exists('org_setting') ? trim(org_setting('cloud_nc_php')) : '';
        if ($s !== '') return $s;
        return is_executable('/usr/local/bin/php84') ? '/usr/local/bin/php84' : PHP_BINARY;
    }

    public static function root(): string
    {
        if (defined('PIM_NC_ROOT')) return (string)PIM_NC_ROOT;
        $s = function_exists('org_setting') ? trim(org_setting('cloud_nc_root')) : '';
        if ($s !== '') return rtrim(str_replace('~', self::home(), $s), '/');
        $host = (string)parse_url(SsoConfig::cloudUrl(), PHP_URL_HOST);
        return self::home() . '/domains/' . ($host !== '' ? $host : 'nc.feer.org.pl') . '/public_html';
    }

    public static function script(): string
    {
        return dirname(__DIR__) . '/nc/szoNcPim.php';
    }

    /** Czy narzędzia chmury są na tym serwerze. */
    public static function available(): bool
    {
        if (self::$exec) return true;
        return is_file(self::occ()) && is_executable(self::occ()) && is_file(self::root() . '/lib/base.php');
    }

    /** Powód niedostępności (dla administratora). */
    public static function diagnose(): string
    {
        if (!is_file(self::occ())) return 'Brak occ w ' . self::occ() . ' (ustawienie cloud_occ_path).';
        if (!is_executable(self::occ())) return 'occ w ' . self::occ() . ' nie jest wykonywalny.';
        if (!is_file(self::root() . '/lib/base.php')) return 'Brak Nextclouda w ' . self::root() . ' (ustawienie cloud_nc_root).';
        return '';
    }

    /** @return array{id:int, created:bool} */
    public static function ensureCalendar(string $uid, string $uri, string $name, string $color = ''): array
    {
        $j = self::tool(['ensure-calendar', $uid, $uri, $name, $color]);
        return ['id' => (int)($j['id'] ?? 0), 'created' => !empty($j['created'])];
    }

    /** @return array{id:int, created:bool} */
    public static function ensureAddressbook(string $uid, string $uri, string $name): array
    {
        $j = self::tool(['ensure-addressbook', $uid, $uri, $name]);
        return ['id' => (int)($j['id'] ?? 0), 'created' => !empty($j['created'])];
    }

    /** @return array{created:int, updated:int, failed:int, errors:list<string>} */
    public static function importVcards(string $uid, string $uri, string $file): array
    {
        $j = self::tool(['import-vcards', $uid, $uri, $file], 1800);
        return ['created' => (int)($j['created'] ?? 0), 'updated' => (int)($j['updated'] ?? 0), 'failed' => (int)($j['failed'] ?? 0), 'errors' => (array)($j['errors'] ?? [])];
    }

    /**
     * occ calendar:import z nadpisywaniem (--supersede), błędne pozycje pomija (--errors=0, --validation=1).
     * @return array{ok:bool, summary:string}
     */
    public static function importIcs(string $uid, string $uri, string $file): array
    {
        [$code, $out, $err] = self::run([self::occ(), 'calendar:import', $uid, $uri, $file, '--supersede', '--errors=0', '--validation=1', '--no-ansi', '--no-interaction'], 1800);
        $text = trim(preg_replace('/\e\[[0-9;]*m/', '', $out . "\n" . $err) ?? '');
        if ($code !== 0) throw new RuntimeException('occ calendar:import (kod ' . $code . '): ' . mb_substr($text, 0, 400));
        return ['ok' => true, 'summary' => mb_substr($text, 0, 1000)];
    }

    private static function tool(array $args, int $timeout = 300): array
    {
        [$code, $out, $err] = self::run(array_merge([self::php(), '-d', 'apc.enable_cli=1', '-d', 'memory_limit=1G', self::script(), self::root()], $args), $timeout);
        $line = '';
        foreach (array_reverse(preg_split('/\r?\n/', trim($out)) ?: []) as $l) if (str_starts_with(trim($l), '{')) { $line = trim($l); break; }
        $j = $line !== '' ? json_decode($line, true) : null;
        if (!is_array($j)) throw new RuntimeException('narzędzie chmury (kod ' . $code . '): ' . mb_substr(trim($out . ' ' . $err), 0, 400));
        if (empty($j['ok'])) throw new RuntimeException('narzędzie chmury: ' . (string)($j['error'] ?? 'błąd'));
        return $j;
    }

    /** @return array{0:int,1:string,2:string} [kod wyjścia, stdout, stderr] — bez powłoki (tablica argumentów). */
    private static function run(array $argv, int $timeout): array
    {
        if (self::$exec) return (self::$exec)($argv, $timeout);
        $p = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) throw new RuntimeException('nie udało się uruchomić ' . basename((string)$argv[0]));
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $end = time() + $timeout;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) break;
            if (time() > $end) { proc_terminate($p); throw new RuntimeException(basename((string)$argv[0]) . ': przekroczony czas ' . $timeout . ' s'); }
            usleep(100000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = (int)$st['exitcode'];
        proc_close($p);
        return [$code, $out, $err];
    }
}
