<?php
/**
 * modules/redis_cache/logic/redisCache.php — opcjonalny cache w Redisie.
 *
 * Konfiguracja: panel admina → „Redis (cache)” (admin/redis.php), ustawienia
 * organizacji redis_* (osobno dla każdego tenanta — każdy ma swoją bazę settings).
 *
 * Zasady:
 *   • Redis jest OPCJONALNY: wyłączony, nieosiągalny albo błędny = system działa
 *     dokładnie jak bez niego (szo_cache_remember() po prostu liczy wartość).
 *     Żadna funkcja nie rzuca wyjątku na zewnątrz.
 *   • Po pierwszej porażce połączenia w danym żądaniu dalsze próby są pomijane
 *     (bez 20 × timeout na stronie). Timeout połączenia 0,25 s.
 *   • Klient: rozszerzenie phpredis, a gdy go nie ma (typowe na hostingu
 *     współdzielonym, np. MyDevil) — wbudowany mini-klient RESP po gnieździe
 *     unix albo TCP (GET/SET/DEL/SCAN/PING/INFO/AUTH/SELECT).
 *   • Klucze z prefiksem (domyślnie szo:<tenant>:), wartości jako JSON.
 */

const SZO_REDIS_SETTINGS = ['redis_enabled', 'redis_socket', 'redis_host', 'redis_port', 'redis_password', 'redis_db', 'redis_prefix'];

/** Konfiguracja z ustawień organizacji. */
function szo_redis_config(): array {
    $g = fn(string $k) => function_exists('org_setting') ? trim((string)org_setting($k)) : '';
    $tenant = defined('TENANT_SLUG') && TENANT_SLUG !== '' ? TENANT_SLUG : 'main';
    return [
        'enabled'  => $g('redis_enabled') === '1',
        'socket'   => preg_replace('#^unix://#i', '', $g('redis_socket')),
        'host'     => $g('redis_host') ?: '127.0.0.1',
        'port'     => (int)($g('redis_port') ?: 6379),
        'password' => $g('redis_password'),
        'db'       => (int)($g('redis_db') ?: 0),
        'prefix'   => $g('redis_prefix') ?: ('szo:' . $tenant . ':'),
    ];
}

/** Minimalny klient RESP (gdy brak rozszerzenia phpredis). */
final class SzoRespClient {
    /** @var resource */
    private $s;
    public function __construct(string $address, float $timeout) {
        $s = @stream_socket_client($address, $errno, $errstr, $timeout);
        if (!$s) throw new \RuntimeException("Brak połączenia z Redisem ($address): $errstr");
        stream_set_timeout($s, 1);
        $this->s = $s;
    }
    public function cmd(string ...$a) {
        $out = '*' . count($a) . "\r\n";
        foreach ($a as $x) $out .= '$' . strlen($x) . "\r\n" . $x . "\r\n";
        if (@fwrite($this->s, $out) === false) throw new \RuntimeException('Zapis do Redisa nieudany');
        return $this->read();
    }
    private function read() {
        $line = fgets($this->s);
        if ($line === false) throw new \RuntimeException('Redis nie odpowiedział');
        $t = $line[0]; $v = substr($line, 1, -2);
        switch ($t) {
            case '+': return $v;
            case '-': throw new \RuntimeException('Redis: ' . $v);
            case ':': return (int)$v;
            case '$':
                $n = (int)$v; if ($n < 0) return null;
                $buf = '';
                while (strlen($buf) < $n + 2) { $c = fread($this->s, $n + 2 - strlen($buf)); if ($c === false || $c === '') break; $buf .= $c; }
                return substr($buf, 0, $n);
            case '*':
                $n = (int)$v; if ($n < 0) return null;
                $r = []; for ($i = 0; $i < $n; $i++) $r[] = $this->read(); return $r;
        }
        throw new \RuntimeException('Nieznana odpowiedź Redisa');
    }
    public function close(): void { if (is_resource($this->s)) fclose($this->s); }
}

/**
 * Połączenie (raz na żądanie) albo null. $force — pomiń wyłącznik i blokadę po
 * porażce (test z panelu admina). $cfg — nadpisanie konfiguracji (test formularza).
 */
function szo_redis(bool $force = false, ?array $cfg = null, ?string &$error = null) {
    static $conn = null, $failed = false;
    if (!$force && $conn !== null) return $conn;
    $cfg = $cfg ?? szo_redis_config();
    if (!$force && (!$cfg['enabled'] || $failed)) return null;
    try {
        if (extension_loaded('redis')) {
            $r = new \Redis();
            $ok = $cfg['socket'] !== '' ? @$r->connect($cfg['socket'], 0, 0.25) : @$r->connect($cfg['host'], $cfg['port'], 0.25);
            if (!$ok) throw new \RuntimeException('Brak połączenia z Redisem');
            if ($cfg['password'] !== '') $r->auth($cfg['password']);
            if ($cfg['db']) $r->select($cfg['db']);
            $c = ['type' => 'phpredis', 'r' => $r, 'prefix' => $cfg['prefix']];
        } else {
            $addr = $cfg['socket'] !== '' ? 'unix://' . $cfg['socket'] : 'tcp://' . $cfg['host'] . ':' . $cfg['port'];
            $r = new SzoRespClient($addr, 0.25);
            if ($cfg['password'] !== '') $r->cmd('AUTH', $cfg['password']);
            if ($cfg['db']) $r->cmd('SELECT', (string)$cfg['db']);
            $c = ['type' => 'resp', 'r' => $r, 'prefix' => $cfg['prefix']];
        }
        if (!$force) $conn = $c;
        return $c;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        if ($cfg['socket'] !== '' && ($why = _szo_redis_socket_problem($cfg['socket']))) $error = $why;
        if (!$force) $failed = true;
        return null;
    }
}

/**
 * Czytelna przyczyna, gdy połączenie po gnieździe się nie udało. Liczone
 * dopiero po porażce (open_basedir może zafałszować file_exists — wtedy null
 * i zostaje oryginalny komunikat). Na FreeBSD (MyDevil) „Socket operation on
 * non-socket” = ścieżka istnieje, ale to katalog albo zwykły plik.
 */
function _szo_redis_socket_problem(string $p): ?string {
    $dir = dirname($p);
    if (!@file_exists($p)) {
        if (!@is_dir($dir)) return "Katalog {$dir} nie istnieje albo PHP nie ma do niego dostępu — sprawdź ścieżkę gniazda.";
        return "Brak gniazda {$p} — Redis nie działa (uruchom go: cli/mydevil_redis_setup.sh albo screen redis-server redis.conf) "
             . "albo w redis.conf linia unixsocket wskazuje inną ścieżkę.";
    }
    if (@is_dir($p)) return "{$p} to katalog, a nie gniazdo — podaj pełną ścieżkę pliku, np. " . rtrim($p, '/') . '/redis.sock.';
    $t = @filetype($p);
    if ($t !== false && $t !== 'socket') {
        return "{$p} to zwykły plik ({$t}), a nie gniazdo Redisa — wpisz ścieżkę z linii unixsocket w redis.conf "
             . "(np. /usr/home/LOGIN/domains/DOMENA/redis.sock), nie plik konfiguracyjny.";
    }
    if ($t === 'socket') return "Gniazdo {$p} istnieje, ale Redis nie przyjmuje połączeń — prawdopodobnie został po zatrzymanym serwerze; uruchom Redis ponownie.";
    return null;
}

/** Wykonuje polecenie niezależnie od klienta. */
function _szo_redis_cmd(array $c, string $cmd, array $args = []) {
    if ($c['type'] === 'phpredis') return $c['r']->rawCommand($cmd, ...$args);
    return $c['r']->cmd($cmd, ...array_map('strval', $args));
}

function szo_cache_get(string $key, &$hit = null) {
    $hit = false;
    $c = szo_redis(); if (!$c) return null;
    try {
        $v = _szo_redis_cmd($c, 'GET', [$c['prefix'] . $key]);
        if ($v === null || $v === false) return null;
        $hit = true;
        return json_decode((string)$v, true);
    } catch (\Throwable $e) { return null; }
}

function szo_cache_set(string $key, $value, int $ttl = 300): bool {
    $c = szo_redis(); if (!$c) return false;
    try {
        _szo_redis_cmd($c, 'SET', [$c['prefix'] . $key, json_encode($value, JSON_UNESCAPED_UNICODE), 'EX', (string)max(1, $ttl)]);
        return true;
    } catch (\Throwable $e) { return false; }
}

function szo_cache_del(string $key): void {
    $c = szo_redis(); if (!$c) return;
    try { _szo_redis_cmd($c, 'DEL', [$c['prefix'] . $key]); } catch (\Throwable $e) {}
}

/** Wartość z cache albo policzona przez $fn (i zapisana na $ttl sekund). */
function szo_cache_remember(string $key, int $ttl, callable $fn) {
    $v = szo_cache_get($key, $hit);
    if ($hit) return $v;
    $v = $fn();
    szo_cache_set($key, $v, $ttl);
    return $v;
}

/** Usuwa klucze z prefiksem tej instalacji (SCAN, bez KEYS). Zwraca liczbę usuniętych. */
function szo_cache_flush(?array $conn = null): int {
    $c = $conn ?? szo_redis(); if (!$c) return 0;
    $n = 0; $cursor = '0';
    try {
        do {
            $res = _szo_redis_cmd($c, 'SCAN', [$cursor, 'MATCH', $c['prefix'] . '*', 'COUNT', '500']);
            $cursor = (string)($res[0] ?? '0');
            foreach ((array)($res[1] ?? []) as $k) { _szo_redis_cmd($c, 'DEL', [$k]); $n++; }
        } while ($cursor !== '0');
    } catch (\Throwable $e) {}
    return $n;
}

/** Stan dla panelu admina: [ok, klient, wersja, pamięć, klucze, błąd, czas ms]. */
function szo_redis_status(?array $cfg = null): array {
    $t0 = microtime(true);
    $c = szo_redis(true, $cfg, $err);
    if (!$c) return ['ok' => false, 'error' => $err ?: 'Brak połączenia', 'client' => extension_loaded('redis') ? 'phpredis' : 'wbudowany (RESP)'];
    try {
        $pong = _szo_redis_cmd($c, 'PING');
        $info = (string)_szo_redis_cmd($c, 'INFO', ['server']) . (string)_szo_redis_cmd($c, 'INFO', ['memory']);
        preg_match('/redis_version:([^\r\n]+)/', $info, $v);
        preg_match('/used_memory_human:([^\r\n]+)/', $info, $m);
        $keys = 0; $cursor = '0';
        do {
            $res = _szo_redis_cmd($c, 'SCAN', [$cursor, 'MATCH', $c['prefix'] . '*', 'COUNT', '500']);
            $cursor = (string)($res[0] ?? '0'); $keys += count((array)($res[1] ?? []));
        } while ($cursor !== '0' && $keys < 100000);
        return ['ok' => in_array($pong, ['PONG', true, '+PONG'], true), 'client' => $c['type'] === 'phpredis' ? 'phpredis' : 'wbudowany (RESP)',
                'version' => trim($v[1] ?? '?'), 'memory' => trim($m[1] ?? '?'), 'keys' => $keys, 'prefix' => $c['prefix'],
                'ms' => (int)round((microtime(true) - $t0) * 1000)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'client' => $c['type']];
    }
}
