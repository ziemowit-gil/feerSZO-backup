<?php
/**
 * cli/redisCheck.php — sprawdza, czy działa Redis używany przez SZO jako cache
 * (modules/redis_cache, konfiguracja: Panel admina → „Redis (cache)”).
 *
 *   php cli/redisCheck.php                          # ustawienia z bazy SZO
 *   php cli/redisCheck.php --tenant=SLUG            # ustawienia tenanta SaaS
 *   php cli/redisCheck.php --socket=/usr/home/feer/domains/szo.feer.org.pl/redis.sock \
 *                          --password-file=/usr/home/feer/redis/szo.feer.org.pl/password
 *   php cli/redisCheck.php --host=127.0.0.1 --port=6379
 *   php cli/redisCheck.php --json                   # wynik jako JSON (monitoring)
 *   php cli/redisCheck.php --quiet                  # tylko kod wyjścia (cron)
 *
 * Kroki: połączenie (+ AUTH, SELECT) → PING → INFO → zapis/odczyt/usunięcie
 * klucza testowego (z prefiksem instalacji, TTL 30 s) → liczba kluczy SZO.
 * Niczego poza kluczem testowym nie zmienia.
 *
 * Kody wyjścia:
 *   0 — Redis działa (i cache jest włączony w SZO albo podano parametry ręcznie)
 *   1 — Redis nie działa (brak połączenia, złe hasło, błąd zapisu/odczytu)
 *   2 — Redis działa, ale cache jest wyłączony w ustawieniach SZO
 *   3 — błędne wywołanie
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

$opts = getopt('hq', ['help', 'tenant:', 'socket:', 'host:', 'port:', 'db:', 'password-file:', 'prefix:', 'json', 'quiet']);
if ($opts === false) $opts = [];

if (isset($opts['h']) || isset($opts['help'])) {
    echo <<<HELP
Sprawdza, czy działa Redis używany przez SZO (cache).

  php cli/redisCheck.php [opcje]

  --tenant=SLUG           ustawienia tenanta SaaS (tenants/SLUG)
  --socket=ŚCIEŻKA        gniazdo unix (nadpisuje ustawienia z bazy)
  --host=HOST --port=N    połączenie TCP (nadpisuje ustawienia z bazy)
  --db=N                  numer bazy Redisa (0–15)
  --password-file=PLIK    hasło (requirepass) z pliku, np. ~/redis/DOMENA/password
  --prefix=PREFIKS        prefiks kluczy (domyślnie z ustawień: szo:<tenant>:)
  --json                  wynik jako JSON
  -q, --quiet             bez wypisywania, tylko kod wyjścia
  -h, --help              ta pomoc

Kody wyjścia: 0 działa · 1 nie działa · 2 działa, ale cache wyłączony w SZO · 3 błędne wywołanie

HELP;
    exit(0);
}

// Tenant musi być ustawiony przed config.php (tam zapada wybór bazy).
if (isset($opts['tenant'])) {
    $slug = preg_replace('/[^a-z0-9]/', '', strtolower((string)$opts['tenant']));
    if ($slug === '' || !is_file(dirname(__DIR__) . '/tenants/' . $slug . '/tenant.php')) {
        fwrite(STDERR, "Brak tenanta '" . $opts['tenant'] . "' (tenants/$slug/tenant.php).\n");
        exit(3);
    }
    $_SERVER['TENANT_SLUG'] = $slug;
}

define('APP_CLI', true);
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';          // ładuje też modules/redis_cache
require_once $base . '/includes/functions.php';

$json  = isset($opts['json']);
$quiet = isset($opts['q']) || isset($opts['quiet']);
$tty   = !$json && function_exists('posix_isatty') && @posix_isatty(STDOUT);

// ── Konfiguracja: z bazy SZO, opcjonalnie nadpisana z linii poleceń ──────────
$settingsError = null;
try {
    $cfg = szo_redis_config();
} catch (\Throwable $e) {
    // Brak bazy/ustawień — da się sprawdzić Redisa z parametrami podanymi ręcznie.
    $settingsError = $e->getMessage();
    $cfg = ['enabled' => false, 'socket' => '', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'db' => 0,
            'prefix' => 'szo:' . (defined('TENANT_SLUG') && TENANT_SLUG !== '' ? TENANT_SLUG : 'main') . ':'];
}
$manual = isset($opts['socket']) || isset($opts['host']) || isset($opts['port']);
if (isset($opts['socket'])) { $cfg['socket'] = preg_replace('#^unix://#i', '', trim((string)$opts['socket'])); }
if (isset($opts['host']))   { $cfg['host'] = trim((string)$opts['host']); if (!isset($opts['socket'])) $cfg['socket'] = ''; }
if (isset($opts['port']))   { $cfg['port'] = (int)$opts['port']; if (!isset($opts['socket'])) $cfg['socket'] = ''; }
if (isset($opts['db']))     { $cfg['db'] = (int)$opts['db']; }
if (isset($opts['prefix'])) { $cfg['prefix'] = (string)$opts['prefix']; }
if (isset($opts['password-file'])) {
    $pf = (string)$opts['password-file'];
    if (!is_readable($pf)) { fwrite(STDERR, "Nie mogę odczytać pliku hasła: $pf\n"); exit(3); }
    $cfg['password'] = trim((string)file_get_contents($pf));
}
if ($cfg['port'] < 1 || $cfg['port'] > 65535 || $cfg['db'] < 0 || $cfg['db'] > 15) {
    fwrite(STDERR, "Błędny port albo numer bazy.\n"); exit(3);
}

$target = $cfg['socket'] !== '' ? 'unix://' . $cfg['socket'] : 'tcp://' . $cfg['host'] . ':' . $cfg['port'];
$result = [
    'ok'       => false,
    'target'   => $target,
    'source'   => $manual ? 'linia poleceń' : 'ustawienia SZO' . (defined('TENANT_SLUG') && TENANT_SLUG !== '' ? ' (tenant ' . TENANT_SLUG . ')' : ''),
    'enabled'  => $cfg['enabled'],
    'client'   => extension_loaded('redis') ? 'phpredis' : 'wbudowany (RESP)',
    'password' => $cfg['password'] !== '',
    'db'       => $cfg['db'],
    'prefix'   => $cfg['prefix'],
    'checks'   => [],
];
if ($settingsError !== null) $result['settings_error'] = $settingsError;

$check = function (string $name, bool $ok, string $detail) use (&$result): bool {
    $result['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    return $ok;
};
// Podpowiedź do typowych odpowiedzi Redisa przy złym/braku hasła.
$explain = function (string $msg) use ($cfg): string {
    if (stripos($msg, 'NOAUTH') !== false) $msg .= $cfg['password'] === '' ? ' — Redis wymaga hasła: --password-file=…' : ' — hasło nie zostało przyjęte';
    elseif (stripos($msg, 'WRONGPASS') !== false || stripos($msg, 'invalid password') !== false) $msg .= ' — złe hasło (porównaj z requirepass w redis.conf)';
    return $msg;
};

// ── 1. Połączenie ────────────────────────────────────────────────────────────
$t0 = microtime(true);
$c  = szo_redis(true, $cfg, $err);
$connectMs = (microtime(true) - $t0) * 1000;

if (!$c) {
    $check('połączenie', false, $explain($err ?: 'Brak połączenia'));
} else {
    $check('połączenie', true, sprintf('%s, %.1f ms%s', $target, $connectMs, $cfg['password'] !== '' ? ', AUTH ok' : ''));

    try {
        // ── 2. PING (5 razy, średni czas) ────────────────────────────────────
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $t = microtime(true);
            $pong = _szo_redis_cmd($c, 'PING');
            $times[] = (microtime(true) - $t) * 1000;
        }
        $pongOk = in_array($pong, ['PONG', '+PONG', true], true);
        $result['ping_ms'] = round(array_sum($times) / count($times), 2);
        $check('PING', $pongOk, $pongOk ? sprintf('PONG, średnio %.2f ms', $result['ping_ms']) : 'nieoczekiwana odpowiedź: ' . var_export($pong, true));

        // ── 3. INFO ─────────────────────────────────────────────────────────
        $info = '';
        foreach (['server', 'clients', 'memory', 'persistence'] as $sec) {
            $info .= (string)_szo_redis_cmd($c, 'INFO', [$sec]) . "\n";
        }
        $f = function (string $k) use ($info): string {
            return preg_match('/^' . preg_quote($k, '/') . ':([^\r\n]*)/m', $info, $m) ? trim($m[1]) : '';
        };
        $result['info'] = [
            'version'        => $f('redis_version'),
            'uptime_s'       => (int)$f('uptime_in_seconds'),
            'clients'        => (int)$f('connected_clients'),
            'memory'         => $f('used_memory_human'),
            'maxmemory'      => $f('maxmemory_human') ?: '0B',
            'maxmemory_policy' => $f('maxmemory_policy'),
            'rdb_last_save'  => $f('rdb_last_bgsave_status'),
            'aof'            => $f('aof_enabled') === '1',
        ];
        $up = $result['info']['uptime_s'];
        $check('INFO', $result['info']['version'] !== '', sprintf('Redis %s, działa od %s, klientów %d, pamięć %s (limit %s)',
            $result['info']['version'] ?: '?',
            $up >= 86400 ? floor($up / 86400) . ' d ' . gmdate('H:i', $up % 86400) : gmdate('H:i:s', $up),
            $result['info']['clients'], $result['info']['memory'] ?: '?',
            $result['info']['maxmemory'] === '0B' ? 'brak' : $result['info']['maxmemory']));
        // Nieudany zapis RDB = Redis odrzuca zapisy (MISCONF), jeśli stop-writes-on-bgsave-error yes.
        if ($result['info']['rdb_last_save'] !== '' && $result['info']['rdb_last_save'] !== 'ok') {
            $check('zapis na dysk (RDB)', false, 'ostatni zapis: ' . $result['info']['rdb_last_save'] . ' — sprawdź miejsce na dysku i log Redisa');
        }

        // ── 4. Zapis → odczyt → usunięcie klucza testowego ────────────────────
        $key   = $cfg['prefix'] . 'healthcheck:' . bin2hex(random_bytes(6));
        $value = 'ok ' . date('c');
        _szo_redis_cmd($c, 'SET', [$key, $value, 'EX', '30']);
        $got = _szo_redis_cmd($c, 'GET', [$key]);
        $del = (int)_szo_redis_cmd($c, 'DEL', [$key]);
        $rwOk = $got === $value && $del === 1;
        $check('zapis/odczyt', $rwOk, $rwOk ? 'SET, GET i DEL na kluczu testowym' : 'odczytano ' . var_export($got, true) . ', DEL=' . $del);

        // ── 5. Klucze tej instalacji ─────────────────────────────────────────
        $keys = 0; $cursor = '0';
        do {
            $res = _szo_redis_cmd($c, 'SCAN', [$cursor, 'MATCH', $cfg['prefix'] . '*', 'COUNT', '500']);
            $cursor = (string)($res[0] ?? '0');
            $keys += count((array)($res[1] ?? []));
        } while ($cursor !== '0' && $keys < 100000);
        $result['keys'] = $keys;
        $check('klucze SZO', true, $keys . ' z prefiksem ' . $cfg['prefix']);
    } catch (\Throwable $e) {
        $check('polecenie', false, $explain($e->getMessage()));
    }

    if ($c['type'] === 'phpredis') { try { $c['r']->close(); } catch (\Throwable $e) {} }
    else { $c['r']->close(); }
}

$allOk = !in_array(false, array_column($result['checks'], 'ok'), true);
$result['ok'] = $allOk;
$code = !$allOk ? 1 : (!$manual && !$cfg['enabled'] ? 2 : 0);
$result['exit_code'] = $code;

// ── Wynik ────────────────────────────────────────────────────────────────────
if ($quiet) exit($code);

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

$col = fn(string $s, string $c) => $tty ? "\033[{$c}m{$s}\033[0m" : $s;
echo "Redis dla SZO\n";
echo "  adres:   {$target}\n";
echo "  źródło:  {$result['source']}" . ($manual ? '' : ($cfg['enabled'] ? ', cache włączony' : ', cache WYŁĄCZONY')) . "\n";
echo "  klient:  {$result['client']}, hasło: " . ($result['password'] ? 'tak' : 'nie') . ", baza: {$cfg['db']}\n";
if ($settingsError !== null) echo '  ' . $col('uwaga:', '33') . "   nie odczytano ustawień z bazy ({$settingsError})\n";
echo "\n";
foreach ($result['checks'] as $ch) {
    echo '  ' . ($ch['ok'] ? $col('OK  ', '32') : $col('BŁĄD', '31')) . ' ' . $ch['name'] . str_repeat(' ', max(1, 20 - mb_strlen($ch['name']))) . $ch['detail'] . "\n";
}
echo "\n";
if ($code === 0) {
    echo $col('Redis działa.', '32') . "\n";
} elseif ($code === 2) {
    echo $col('Redis działa, ale cache jest wyłączony w SZO', '33') . " — Panel admina → „Redis (cache)” → „Włączony” → Zapisz.\n";
} else {
    echo $col('Redis NIE działa.', '31') . ($cfg['enabled'] ? ' SZO działa dalej, tylko bez cache.' : '') . "\n";
    $authProblem = preg_match('/NOAUTH|WRONGPASS|invalid password/i', implode(' ', array_column($result['checks'], 'detail')));
    if ($authProblem) {
        echo "Hasło na MyDevil: cat ~/redis/DOMENA/password; w SZO wpisz je w Panel admina → „Redis (cache)”.\n";
    } elseif ($cfg['socket'] !== '') {
        echo "Na MyDevil: sh cli/mydevil_redis_setup.sh (uruchamia Redis w screen i dodaje @reboot).\n";
    }
}
exit($code);
