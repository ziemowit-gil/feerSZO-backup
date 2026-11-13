<?php
/**
 * modules/backup_remote/lib/backupRemote.php — drugi cel kopii poza serwerem, obok SharePointa:
 * zdalny serwer przez SFTP (phpseclib3 z vendor/, czysty PHP) albo rsync po SSH (programy rsync + ssh).
 *
 * Co trafia na serwer (<ścieżka>/incremental/RRRR-MM/… i <ścieżka>/full/RRRR-MM/…):
 *   • „incremental” — kopie zrobione przez lokalnego agenta (cron/agents/backup.php) w backups/RRRR-MM/
 *     (już zaszyfrowane, jeśli szyfrowanie kopii jest włączone), których jeszcze nie wysłano
 *     (stan: backups/.remote_sent.json); tylko nowsze niż ostatnia pełna kopia na serwerze;
 *   • „full” — pełna kopia budowana od nowa (baza VACUUM INTO + gzip, cały uploads/, certs/), co noc
 *     albo co 7 dni (backup_remote_full_every), żeby na serwerze był kompletny punkt startowy.
 *     Pierwsza wysyłka po włączeniu celu jest zawsze pełna.
 *
 * Niezależność od SharePointa: osobni agenci CRON (backup_remote, backup_remote_full), osobne znaczniki
 * i stan — błąd jednego celu nie zatrzymuje drugiego. Kodu SharePointa (includes/m365.php) nie dotyka.
 *
 * Stan: backups/.remote_status.json (ostatnie przebiegi: kanał, OK/błąd, pliki, bajty, czas trwania,
 * ostatni test połączenia) + znacznik ostatniej udanej wysyłki backups/.last_remote_ok (jak .last_sp_ok).
 *
 * Klucz hosta: przypinany przy pierwszym połączeniu (TOFU) w ustawieniu backup_remote_hostkey i sprawdzany
 * przy każdym kolejnym PRZED logowaniem (hasło nie trafi do podstawionego serwera). Dla rsync z przypiętego
 * klucza powstaje tymczasowy known_hosts, a ssh działa ze StrictHostKeyChecking=yes.
 *
 * Hasło do serwera: settings.backup_remote_password, zaszyfrowane fcEncrypt (DATA_KEY z config.local.php).
 * rsync z hasłem: SSH_ASKPASS + SSH_ASKPASS_REQUIRE=force (OpenSSH ≥ 8.4); zalecany klucz.
 *
 * Testy na kopii: BACKUP_ROOT (katalog kopii), DB_PATH, UPLOAD_DIR i BACKUP_CERTS_DIR (katalog certs).
 */

require_once dirname(__DIR__, 3) . '/includes/backup.php';
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';

const BACKUP_REMOTE_PW_CTX   = 'settings.backup_remote_password';
const BACKUP_REMOTE_HISTORY  = 30;
const BACKUP_REMOTE_TESTFILE = '.feerszo-test';

// ── Konfiguracja ────────────────────────────────────────────────────────────

/** Ustawienia celu zdalnego (tabela settings, klucze backup_remote_*). Hasło zostaje zaszyfrowane (password_enc). */
function backupRemoteConfig(): array {
    $s = function (string $k, string $d = ''): string {
        $v = org_setting('backup_remote_' . $k);
        return $v !== '' ? $v : $d;
    };
    $port = (int)$s('port', '22');
    return [
        'enabled'        => $s('enabled') === '1',
        'method'         => $s('method') === 'rsync' ? 'rsync' : 'sftp',
        'host'           => $s('host'),
        'port'           => ($port >= 1 && $port <= 65535) ? $port : 22,
        'user'           => $s('user'),
        'path'           => $s('path'),
        'auth'           => $s('auth') === 'password' ? 'password' : 'key',
        'key_path'       => $s('key_path'),
        'password'       => '',
        'password_enc'   => $s('password'),
        'full_every'     => (int)$s('full_every', '1') === 7 ? 7 : 1,
        'retention_days' => max(0, (int)$s('retention_days', '90')),
        'hostkey'        => $s('hostkey'),
    ];
}

/** Hasło do serwera: wpisane w formularzu (test przed zapisem) albo odszyfrowane z ustawień. */
function backupRemotePassword(array $cfg): string {
    if (($cfg['password'] ?? '') !== '') return (string)$cfg['password'];
    $enc = (string)($cfg['password_enc'] ?? '');
    return $enc === '' ? '' : (string)(fcDecrypt($enc, BACKUP_REMOTE_PW_CTX) ?? '');
}

/**
 * Zapisuje ustawienia. Puste hasło w $cfg['password'] = bez zmiany zapisanego; przy kluczu hasło jest czyszczone.
 * Zmiana hosta lub portu kasuje przypięty klucz hosta (nowy serwer → nowe przypięcie przy pierwszym połączeniu).
 */
function backupRemoteSaveConfig(array $cfg): void {
    $cur = backupRemoteConfig();
    $set = fn(string $k, string $v) => org_setting_set('backup_remote_' . $k, $v);
    $set('enabled', $cfg['enabled'] ? '1' : '0');
    $set('method', $cfg['method']);
    $set('host', $cfg['host']);
    $set('port', (string)$cfg['port']);
    $set('user', $cfg['user']);
    $set('path', $cfg['path']);
    $set('auth', $cfg['auth']);
    $set('key_path', $cfg['key_path']);
    $set('full_every', (string)$cfg['full_every']);
    $set('retention_days', (string)$cfg['retention_days']);
    if ($cfg['auth'] === 'key') {
        $set('password', '');
    } elseif (($cfg['password'] ?? '') !== '') {
        $set('password', (string)fcEncrypt($cfg['password'], BACKUP_REMOTE_PW_CTX));
    }
    if (strtolower($cur['host']) !== strtolower($cfg['host']) || (int)$cur['port'] !== (int)$cfg['port']) {
        $set('hostkey', '');
    }
}

/**
 * Sprawdza ustawienia przed połączeniem. Zwraca [pole => komunikat]; pusta tablica = poprawne.
 * Host, użytkownik i ścieżka trafiają do poleceń ssh/rsync — stąd ścisłe wzorce (bez spacji i znaków powłoki).
 */
function backupRemoteValidate(array $c): array {
    $e = [];
    $host = (string)$c['host'];
    if ($host === '') {
        $e['host'] = 'Podaj adres serwera.';
    } elseif (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $host)
              && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $e['host'] = 'Podaj samą nazwę hosta albo adres IP — bez protokołu (sftp://), portu i ścieżki.';
    }
    if ((int)$c['port'] < 1 || (int)$c['port'] > 65535) $e['port'] = 'Port musi być liczbą od 1 do 65535.';
    if ($c['user'] === '') {
        $e['user'] = 'Podaj nazwę użytkownika na serwerze.';
    } elseif (!preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]{0,63}$/', (string)$c['user'])) {
        $e['user'] = 'Nazwa użytkownika może zawierać tylko litery, cyfry, kropkę, podkreślenie i myślnik.';
    }
    $path = (string)$c['path'];
    if ($path === '' || trim($path, '/') === '') {
        $e['path'] = 'Podaj katalog docelowy na serwerze (np. kopie/feerszo).';
    } elseif (!preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $path) || preg_match('#(^|/)\.\.(/|$)#', $path)) {
        $e['path'] = 'Ścieżka może zawierać tylko litery, cyfry oraz . _ - / (bez spacji i bez „..”).';
    }
    if ($c['auth'] === 'key') {
        $kp = (string)$c['key_path'];
        if ($kp === '') {
            $e['key_path'] = 'Podaj ścieżkę do klucza prywatnego albo wygeneruj nowy klucz.';
        } elseif (!is_file($kp) || !is_readable($kp)) {
            $e['key_path'] = 'Plik klucza nie istnieje albo serwer WWW/CRON nie może go odczytać.';
        } elseif ($c['method'] === 'rsync' && (fileperms($kp) & 0077)) {
            $e['key_path'] = 'Klucz ma zbyt szerokie uprawnienia — ssh go odrzuci. Ustaw chmod 600.';
        }
    } elseif (backupRemotePassword($c) === '') {
        $e['password'] = 'Podaj hasło do serwera.';
    }
    if ($c['method'] === 'rsync' && !backupRemoteTools()['rsync']) {
        $e['method'] = 'Na tym serwerze nie ma programu rsync albo ssh — wybierz SFTP.';
    }
    return $e;
}

// ── Narzędzia systemowe ─────────────────────────────────────────────────────

/** Pełna ścieżka programu (PATH i typowe katalogi — CRON i Apache mają często ubogi PATH) albo null. */
function backupRemoteBin(string $name): ?string {
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    $dirs = array_merge(explode(PATH_SEPARATOR, (string)getenv('PATH')), ['/usr/bin', '/bin', '/usr/local/bin', '/opt/homebrew/bin']);
    foreach (array_unique($dirs) as $d) {
        if ($d === '') continue;
        $p = rtrim($d, '/') . '/' . $name;
        if (@is_file($p) && @is_executable($p)) return $cache[$name] = $p;
    }
    return $cache[$name] = null;
}

/** Dostępność metod na tym serwerze. */
function backupRemoteTools(): array {
    $rsync = backupRemoteBin('rsync');
    $ssh   = backupRemoteBin('ssh');
    return [
        'sftp'      => class_exists('\\phpseclib3\\Net\\SFTP') || is_file(backup_base_dir() . '/vendor/phpseclib/phpseclib/phpseclib/Net/SFTP.php'),
        'rsync'     => $rsync !== null && $ssh !== null,
        'rsync_bin' => $rsync,
        'ssh_bin'   => $ssh,
    ];
}

function backupRemoteVendor(): void {
    if (!class_exists('\\phpseclib3\\Net\\SFTP')) {
        $a = backup_base_dir() . '/vendor/autoload.php';
        if (is_file($a)) require_once $a;
    }
    if (!class_exists('\\phpseclib3\\Net\\SFTP')) {
        throw new RuntimeException('Brak biblioteki phpseclib w vendor/ — połączenie SSH niedostępne.');
    }
}

/** Katalog certs/ (klucze prywatne). BACKUP_CERTS_DIR — tylko do testów. */
function backupRemoteCertsDir(): string {
    return defined('BACKUP_CERTS_DIR') ? rtrim((string)BACKUP_CERTS_DIR, '/') : backup_base_dir() . '/certs';
}

// ── Klucz hosta (TOFU) ──────────────────────────────────────────────────────

/** Typ klucza zapisany w blobie SSH (pierwsze pole: długość + nazwa typu). */
function backupRemoteKeyType(string $blob): string {
    if (strlen($blob) < 5) return '';
    $n = unpack('N', substr($blob, 0, 4))[1];
    return ($n > 0 && $n < 64 && strlen($blob) >= 4 + $n) ? substr($blob, 4, $n) : '';
}

/** „algorytm base64” z phpseclib → „typ base64” jak w known_hosts (rsa-sha2-512 → ssh-rsa). */
function backupRemoteHostKeyLine(string $raw): string {
    $parts = preg_split('/\s+/', trim($raw));
    $b64   = (string)($parts[1] ?? '');
    $blob  = base64_decode($b64, true);
    if ($blob === false || $blob === '') return '';
    $type = backupRemoteKeyType($blob) ?: (string)($parts[0] ?? '');
    return $type . ' ' . $b64;
}

/** Odcisk w formacie OpenSSH: SHA256:… (bez dopełnienia =). */
function backupRemoteFingerprint(string $line): string {
    $parts = preg_split('/\s+/', trim($line));
    $blob  = base64_decode((string)($parts[1] ?? ''), true);
    if (!$blob) return '';
    return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}

/** Przypięty klucz hosta, o ile dotyczy bieżącego hosta i portu. */
function backupRemotePinned(array $cfg): ?array {
    $p = json_decode((string)($cfg['hostkey'] ?? ''), true);
    if (!is_array($p) || empty($p['key'])) return null;
    if (strtolower((string)($p['host'] ?? '')) !== strtolower((string)$cfg['host']) || (int)($p['port'] ?? 0) !== (int)$cfg['port']) return null;
    return $p;
}

/**
 * Porównuje klucz hosta przedstawiony przez serwer z przypiętym; bez przypięcia — przypina (TOFU).
 * Rzuca wyjątek przy niezgodności (możliwy podstawiony serwer). Zwraca ['line','fp','new'].
 */
function backupRemoteCheckHostKey(array &$cfg, string $raw): array {
    $line = backupRemoteHostKeyLine($raw);
    if ($line === '') throw new RuntimeException('Serwer nie przedstawił poprawnego klucza hosta SSH.');
    $fp  = backupRemoteFingerprint($line);
    $pin = backupRemotePinned($cfg);
    if ($pin) {
        if (hash_equals((string)$pin['key'], $line)) return ['line' => $line, 'fp' => $fp, 'new' => false];
        throw new RuntimeException('Klucz hosta serwera ' . $cfg['host'] . ' jest inny niż zapamiętany (było '
            . backupRemoteFingerprint((string)$pin['key']) . ', jest ' . $fp . '). Połączenie przerwane — to może być podstawiony serwer. '
            . 'Jeśli serwer celowo wymieniono, użyj „Zapomnij klucz hosta” w ustawieniach.');
    }
    $json = (string)json_encode(['host' => $cfg['host'], 'port' => (int)$cfg['port'], 'key' => $line, 'ts' => time()], JSON_UNESCAPED_SLASHES);
    org_setting_set('backup_remote_hostkey', $json);
    $cfg['hostkey'] = $json;
    return ['line' => $line, 'fp' => $fp, 'new' => true];
}

// ── Połączenie SFTP (phpseclib) ─────────────────────────────────────────────

/** Łączy się i sprawdza klucz hosta, jeszcze bez logowania. Zwraca [SFTP, ['line','fp','new']]. */
function backupRemoteSftpConnect(array &$cfg): array {
    backupRemoteVendor();
    $where = $cfg['host'] . ':' . $cfg['port'];
    try {
        $sftp = new \phpseclib3\Net\SFTP($cfg['host'], (int)$cfg['port'], 20);
        $raw  = $sftp->getServerPublicHostKey();
    } catch (\Throwable $e) {
        throw new RuntimeException('Brak połączenia z ' . $where . ': ' . backupRemoteShortErr($e->getMessage()));
    }
    if (!$raw) throw new RuntimeException('Brak połączenia SSH z ' . $where . ' (serwer nie odpowiada albo to nie jest serwer SSH).');
    return [$sftp, backupRemoteCheckHostKey($cfg, (string)$raw)];
}

/** Dane logowania dla phpseclib: obiekt klucza prywatnego albo hasło. */
function backupRemoteCredential(array $cfg) {
    if ($cfg['auth'] === 'password') {
        $pw = backupRemotePassword($cfg);
        if ($pw === '') throw new RuntimeException('Brak hasła do serwera (albo nie da się go odszyfrować — zmieniono DATA_KEY?).');
        return $pw;
    }
    return backupRemoteLoadKey((string)$cfg['key_path']);
}

function backupRemoteLoadKey(string $path) {
    backupRemoteVendor();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Plik klucza prywatnego nie istnieje albo nie można go odczytać: ' . $path);
    }
    try {
        return \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey((string)file_get_contents($path));
    } catch (\Throwable $e) {
        throw new RuntimeException('Nie można wczytać klucza prywatnego ' . basename($path) . ' — jest chroniony hasłem albo w nieobsługiwanym formacie. Użyj klucza bez hasła.');
    }
}

function backupRemoteSftpLogin(\phpseclib3\Net\SFTP $sftp, array $cfg): void {
    if (!$sftp->login((string)$cfg['user'], backupRemoteCredential($cfg))) {
        throw new RuntimeException('Logowanie nieudane: serwer odrzucił użytkownika „' . $cfg['user'] . '” z podanym '
            . ($cfg['auth'] === 'key' ? 'kluczem (czy klucz publiczny jest w ~/.ssh/authorized_keys?)' : 'hasłem') . '.');
    }
    $sftp->setTimeout(120);
}

/** Połączenie + klucz hosta + logowanie. */
function backupRemoteSftp(array &$cfg): \phpseclib3\Net\SFTP {
    [$sftp] = backupRemoteSftpConnect($cfg);
    backupRemoteSftpLogin($sftp, $cfg);
    return $sftp;
}

/** Tworzy katalog na serwerze (rekurencyjnie), jeśli go nie ma. */
function backupRemoteSftpMkdir(\phpseclib3\Net\SFTP $sftp, string $dir): void {
    if ($dir === '' || $dir === '.' || $sftp->is_dir($dir)) return;
    if (!$sftp->mkdir($dir, -1, true) && !$sftp->is_dir($dir)) {
        throw new RuntimeException('Nie można utworzyć katalogu ' . $dir . ' na serwerze: ' . ($sftp->getLastSFTPError() ?: 'brak uprawnień'));
    }
}

/**
 * Wysyła pliki przez SFTP: najpierw do <plik>.part, sprawdza rozmiar, potem zmienia nazwę
 * (przerwana wysyłka nie zostawia pliku udającego kompletny).
 */
function backupRemoteSendSftp(array &$cfg, array $items): array {
    $sftp  = backupRemoteSftp($cfg);
    $base  = rtrim((string)$cfg['path'], '/');
    $made  = [];
    $bytes = 0;
    try {
        foreach ($items as $it) {
            $remote = $base . '/' . ltrim($it['rel'], '/');
            $dir    = dirname($remote);
            if (!isset($made[$dir])) { backupRemoteSftpMkdir($sftp, $dir); $made[$dir] = true; }
            $size = (int)filesize($it['local']);
            $part = $remote . '.part';
            if (!$sftp->put($part, $it['local'], \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                throw new RuntimeException('Wysyłka ' . basename($remote) . ' nie powiodła się: ' . ($sftp->getLastSFTPError() ?: 'błąd zapisu na serwerze'));
            }
            $rsz = $sftp->filesize($part);
            if ($rsz === false || (int)$rsz !== $size) {
                $sftp->delete($part, false);
                throw new RuntimeException('Po wysłaniu ' . basename($remote) . ' rozmiar na serwerze się nie zgadza (' . (int)$rsz . ' zamiast ' . $size . ' B).');
            }
            if ($sftp->file_exists($remote)) $sftp->delete($remote, false);
            if (!$sftp->rename($part, $remote)) {
                throw new RuntimeException('Nie można zmienić nazwy ' . basename($part) . ' na serwerze: ' . ($sftp->getLastSFTPError() ?: 'nieznany błąd'));
            }
            $bytes += $size;
        }
    } finally {
        $sftp->disconnect();
    }
    return ['files' => count($items), 'bytes' => $bytes];
}

// ── rsync po SSH ────────────────────────────────────────────────────────────

/** Sprawdza/przypina klucz hosta przez phpseclib (bez logowania) — z niego powstaje known_hosts dla ssh. */
function backupRemoteHostKeyProbe(array &$cfg): array {
    backupRemoteVendor();
    $where = $cfg['host'] . ':' . $cfg['port'];
    try {
        $ssh = new \phpseclib3\Net\SSH2($cfg['host'], (int)$cfg['port'], 20);
        $raw = $ssh->getServerPublicHostKey();
        $ssh->disconnect();
    } catch (\Throwable $e) {
        throw new RuntimeException('Brak połączenia z ' . $where . ': ' . backupRemoteShortErr($e->getMessage()));
    }
    if (!$raw) throw new RuntimeException('Brak połączenia SSH z ' . $where . ' (serwer nie odpowiada albo to nie jest serwer SSH).');
    return backupRemoteCheckHostKey($cfg, (string)$raw);
}

/** Host w składni rsync (IPv6 w nawiasach). */
function backupRemoteRsyncHost(string $host): string {
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
}

/**
 * Przygotowuje w $tmp: known_hosts z przypiętym kluczem, skrypt-opakowanie ssh (rsync -e) i — przy haśle —
 * skrypt SSH_ASKPASS. Zwraca ['wrapper' => ścieżka, 'env' => środowisko procesu].
 */
function backupRemoteSshSetup(array $cfg, string $tmp, string $hostKeyLine): array {
    $tools = backupRemoteTools();
    $host  = strtolower((string)$cfg['host']);
    $hostField = (int)$cfg['port'] === 22 ? $host : '[' . $host . ']:' . (int)$cfg['port'];
    $kh = $tmp . '/known_hosts';
    file_put_contents($kh, $hostField . ' ' . $hostKeyLine . "\n");

    $args = [
        $tools['ssh_bin'], '-F', '/dev/null', '-p', (string)(int)$cfg['port'],
        '-o', 'UserKnownHostsFile=' . $kh, '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=4', '-o', 'LogLevel=ERROR',
    ];
    $env = getenv();
    unset($env['SSH_AUTH_SOCK']); // tylko wskazany klucz/hasło, nie klucze z agenta użytkownika
    if ($cfg['auth'] === 'key') {
        array_push($args, '-i', (string)$cfg['key_path'], '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'PreferredAuthentications=publickey');
    } else {
        $pw = backupRemotePassword($cfg);
        if ($pw === '') throw new RuntimeException('Brak hasła do serwera.');
        $askpass = $tmp . '/askpass.sh';
        file_put_contents($askpass, "#!/bin/sh\nprintf '%s\\n' \"\$FEER_BACKUP_SSH_PASS\"\n");
        chmod($askpass, 0700);
        array_push($args, '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'PubkeyAuthentication=no', '-o', 'NumberOfPasswordPrompts=1');
        $env['SSH_ASKPASS'] = $askpass;
        $env['SSH_ASKPASS_REQUIRE'] = 'force';
        $env['DISPLAY'] = $env['DISPLAY'] ?? ':0';
        $env['FEER_BACKUP_SSH_PASS'] = $pw;
    }
    $wrapper = $tmp . '/ssh.sh';
    file_put_contents($wrapper, "#!/bin/sh\nexec " . implode(' ', array_map('escapeshellarg', $args)) . " \"\$@\"\n");
    chmod($wrapper, 0700);
    return ['wrapper' => $wrapper, 'env' => $env];
}

/** Uruchamia program bez powłoki; stdout/stderr przez pliki (bez ryzyka zakleszczenia na potokach). */
function backupRemoteRun(array $cmd, ?array $env = null): array {
    $out = tempnam(sys_get_temp_dir(), 'brout_');
    $err = tempnam(sys_get_temp_dir(), 'brerr_');
    $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, $env);
    if (!is_resource($p)) {
        @unlink($out); @unlink($err);
        return ['code' => -1, 'out' => '', 'err' => 'Nie można uruchomić programu ' . basename((string)$cmd[0]) . '.'];
    }
    $code = proc_close($p);
    $o = trim((string)@file_get_contents($out));
    $e = trim((string)@file_get_contents($err));
    @unlink($out); @unlink($err);
    return ['code' => $code, 'out' => $o, 'err' => $e];
}

/**
 * Wysyła pliki przez rsync: drzewo dowiązań (symlinki) w katalogu tymczasowym odwzorowuje układ na serwerze,
 * rsync -L przesyła zawartość plików. Katalog docelowy tworzony przez ssh mkdir -p (jeśli konto ma powłokę).
 */
function backupRemoteSendRsync(array &$cfg, array $items): array {
    $tools = backupRemoteTools();
    if (!$tools['rsync']) throw new RuntimeException('Brak programu rsync lub ssh na tym serwerze — wybierz metodę SFTP.');
    $hk  = backupRemoteHostKeyProbe($cfg);
    $tmp = backupRemoteTmpDir('rsync');
    try {
        $ssh   = backupRemoteSshSetup($cfg, $tmp, $hk['line']);
        $stage = $tmp . '/stage';
        $bytes = 0;
        foreach ($items as $it) {
            $dst = $stage . '/' . ltrim($it['rel'], '/');
            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0700, true)) throw new RuntimeException('Nie można przygotować katalogu tymczasowego.');
            if (!@symlink($it['local'], $dst)) throw new RuntimeException('Nie można przygotować pliku do wysyłki: ' . basename($it['local']));
            $bytes += (int)filesize($it['local']);
        }
        $base = rtrim((string)$cfg['path'], '/');
        $mk = backupRemoteRun([$ssh['wrapper'], $cfg['user'] . '@' . $cfg['host'], 'mkdir -p -- ' . escapeshellarg($base)], $ssh['env']);
        $r  = backupRemoteRun([$tools['rsync_bin'], '-rtL', '--partial', '--timeout=600', '-e', $ssh['wrapper'],
            $stage . '/', $cfg['user'] . '@' . backupRemoteRsyncHost((string)$cfg['host']) . ':' . $base . '/'], $ssh['env']);
        if ($r['code'] !== 0) {
            $msg = $r['err'] !== '' ? $r['err'] : ($r['out'] !== '' ? $r['out'] : $mk['err']);
            throw new RuntimeException('rsync zakończył się błędem (kod ' . $r['code'] . '): ' . backupRemoteShortErr($msg));
        }
        return ['files' => count($items), 'bytes' => $bytes];
    } finally {
        backupRemoteRmTree($tmp);
    }
}

// ── Wysyłka (wspólne wejście) ───────────────────────────────────────────────

/**
 * Wysyła pliki wybraną metodą. $items: [['local' => ścieżka, 'rel' => 'incremental/RRRR-MM/plik'], …].
 * Zwraca ['files','bytes']; rzuca wyjątek przy błędzie.
 */
function backupRemoteSend(array &$cfg, array $items): array {
    $err = backupRemoteValidate($cfg);
    if ($err) throw new RuntimeException('Niepełne ustawienia serwera zdalnego: ' . implode(' ', $err));
    if (!$items) return ['files' => 0, 'bytes' => 0];
    return $cfg['method'] === 'rsync' ? backupRemoteSendRsync($cfg, $items) : backupRemoteSendSftp($cfg, $items);
}

// ── Stan wysyłek ────────────────────────────────────────────────────────────

function backupRemoteStatusPath(): string { return backup_root() . '/.remote_status.json'; }
function backupRemoteSentPath(): string   { return backup_root() . '/.remote_sent.json'; }

function backupRemoteJsonLoad(string $path): array {
    if (!is_file($path)) return [];
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function backupRemoteJsonSave(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
        @rename($tmp, $path);
    }
}

/** Stan: runs (najnowsze pierwsze), last_ok, last_full (start ostatniej udanej pełnej), last_check, last_test. */
function backupRemoteStatus(): array {
    return backupRemoteJsonLoad(backupRemoteStatusPath()) + ['runs' => [], 'last_ok' => 0, 'last_full' => 0, 'last_check' => 0, 'last_test' => null];
}

/** Dopisuje przebieg do historii; udana wysyłka z plikami → znacznik .last_remote_ok (jak .last_sp_ok). */
function backupRemoteRecordRun(array $run): void {
    $st = backupRemoteStatus();
    array_unshift($st['runs'], $run);
    $st['runs'] = array_slice($st['runs'], 0, BACKUP_REMOTE_HISTORY);
    if (!empty($run['ok']) && (int)($run['files'] ?? 0) > 0) {
        $st['last_ok'] = (int)$run['ts'];
        @file_put_contents(backup_root() . '/.last_remote_ok', (string)$run['ts']);
    }
    if (!empty($run['ok']) && ($run['channel'] ?? '') === 'full') $st['last_full'] = (int)$run['started'];
    backupRemoteJsonSave(backupRemoteStatusPath(), $st);
}

function backupRemoteStatusSet(string $key, $value): void {
    $st = backupRemoteStatus();
    $st[$key] = $value;
    backupRemoteJsonSave(backupRemoteStatusPath(), $st);
}

/** Znacznik ostatniej udanej wysyłki na serwer zdalny (unix ts) lub 0. */
function backupRemoteLastOkTs(): int {
    $p = backup_root() . '/.last_remote_ok';
    return is_file($p) ? (int)file_get_contents($p) : 0;
}

/** Blokada: jedna wysyłka naraz (CRON przyrostowy, pełny i przycisk w panelu). */
function backupRemoteLock() {
    if (!is_dir(backup_root())) @mkdir(backup_root(), 0750, true);
    $fh = @fopen(backup_root() . '/.remote.lock', 'c');
    if (!$fh) return null;
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return null; }
    return $fh;
}

function backupRemoteUnlock($fh): void {
    if (is_resource($fh)) { flock($fh, LOCK_UN); fclose($fh); }
}

// ── Przebiegi: przyrostowy i pełny ──────────────────────────────────────────

/**
 * Wysyła nowe kopie lokalne (backups/RRRR-MM/*) na serwer. Bez żadnej pełnej kopii na serwerze robi najpierw pełną.
 * Pomija pliki zapisywane w tej chwili (ostatnie 2 min) i — przy włączonym szyfrowaniu — niezaszyfrowane.
 * @return array{ok:bool,skipped?:bool,error?:string,files?:int,bytes?:int,sent?:array,skipped_plain?:int,first_full?:bool}
 */
function backupRemoteRunIncremental(string $trigger = 'cron'): array {
    $cfg = backupRemoteConfig();
    if (!$cfg['enabled']) return ['ok' => false, 'skipped' => true, 'error' => 'Wysyłka na serwer zdalny jest wyłączona.'];
    $st = backupRemoteStatus();
    if ((int)$st['last_full'] === 0) {
        return backupRemoteRunFull($trigger, true) + ['first_full' => true];
    }
    $lock = backupRemoteLock();
    if (!$lock) return ['ok' => false, 'skipped' => true, 'error' => 'Inna wysyłka na serwer zdalny jest w toku.'];

    $t0 = microtime(true);
    $start = time();
    try {
        $since = (int)$st['last_full'];
        $sent  = backupRemoteJsonLoad(backupRemoteSentPath());
        $enc   = backup_encryption_enabled();
        $items = [];
        $plain = 0;
        $seen  = [];
        foreach (glob(backup_root() . '/????-??/*') ?: [] as $fp) {
            if (!is_file($fp) || is_link($fp)) continue;
            $name = basename($fp);
            if (backup_kind($name) === null) continue;
            $rel = basename(dirname($fp)) . '/' . $name;
            $seen[$rel] = true;
            $mt = (int)filemtime($fp);
            if ($mt < $since || $mt > $start - 120) continue;
            $size = (int)filesize($fp);
            if (isset($sent[$rel]) && (int)$sent[$rel]['size'] === $size) continue;
            if ($enc && !backup_is_encrypted($name)) { $plain++; continue; }
            $items[] = ['local' => $fp, 'rel' => 'incremental/' . $rel, 'key' => $rel, 'size' => $size, 'mtime' => $mt];
        }
        // Wpisy po plikach usuniętych lokalnie przez rotację nie są już potrzebne.
        $sent = array_intersect_key($sent, $seen);

        if (!$items) {
            backupRemoteJsonSave(backupRemoteSentPath(), $sent);
            backupRemoteStatusSet('last_check', time());
            return ['ok' => true, 'files' => 0, 'bytes' => 0, 'sent' => [], 'skipped_plain' => $plain];
        }

        $r = backupRemoteSend($cfg, $items);
        foreach ($items as $it) $sent[$it['key']] = ['size' => $it['size'], 'mtime' => $it['mtime'], 'ts' => time()];
        backupRemoteJsonSave(backupRemoteSentPath(), $sent);
        $run = backupRemoteRunEntry('incremental', $cfg, $trigger, $start, $t0, true, $r['files'], $r['bytes'],
            $plain ? 'Pominięto ' . $plain . ' niezaszyfrowanych kopii (szyfrowanie włączone).' : '');
        backupRemoteRecordRun($run);
        backupRemoteStatusSet('last_check', time());
        return ['ok' => true, 'files' => $r['files'], 'bytes' => $r['bytes'], 'sent' => array_column($items, 'rel'), 'skipped_plain' => $plain];
    } catch (\Throwable $e) {
        backupRemoteRecordRun(backupRemoteRunEntry('incremental', $cfg, $trigger, $start, $t0, false, 0, 0, $e->getMessage()));
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        backupRemoteUnlock($lock);
    }
}

/**
 * Pełna kopia (baza, uploads, certs) na serwer — gdy minął ustawiony okres (doba / 7 dni) albo $force.
 * Po udanej wysyłce: retencja na serwerze (błąd retencji to tylko ostrzeżenie).
 */
function backupRemoteRunFull(string $trigger = 'cron', bool $force = false): array {
    $cfg = backupRemoteConfig();
    if (!$cfg['enabled']) return ['ok' => false, 'skipped' => true, 'error' => 'Wysyłka na serwer zdalny jest wyłączona.'];
    $last = (int)backupRemoteStatus()['last_full'];
    if (!$force && $last && (time() - $last) < $cfg['full_every'] * 86400 - 3 * 3600) {
        return ['ok' => true, 'skipped' => true, 'error' => 'Pełna kopia nie jest jeszcze potrzebna (ostatnia: ' . date('d.m.Y H:i', $last) . ').'];
    }
    $lock = backupRemoteLock();
    if (!$lock) return ['ok' => false, 'skipped' => true, 'error' => 'Inna wysyłka na serwer zdalny jest w toku.'];

    $t0 = microtime(true);
    $start = time();
    $tmp = '';
    try {
        $tmp   = backupRemoteTmpDir('full');
        $stamp = date('Ymd_His', $start);
        $month = date('Y-m', $start);
        $items = [];
        foreach (backupRemoteBuildFull($tmp, $stamp) as $f) {
            $items[] = ['local' => $f, 'rel' => 'full/' . $month . '/' . basename($f)];
        }
        if (!$items) throw new RuntimeException('Brak danych do pełnej kopii (nie znaleziono bazy ani katalogów).');
        $r = backupRemoteSend($cfg, $items);
        $warn = '';
        $ret  = null;
        try {
            $ret = backupRemoteRetention($cfg);
        } catch (\Throwable $e) {
            $warn = 'Retencja na serwerze nie powiodła się: ' . backupRemoteShortErr($e->getMessage());
        }
        if ($ret && !empty($ret['deleted'])) $warn = 'Retencja: usunięto ' . (int)$ret['deleted'] . ' starych kopii z serwera.';
        backupRemoteRecordRun(backupRemoteRunEntry('full', $cfg, $trigger, $start, $t0, true, $r['files'], $r['bytes'], $warn));
        return ['ok' => true, 'files' => $r['files'], 'bytes' => $r['bytes'], 'sent' => array_column($items, 'rel'), 'warning' => $warn, 'retention' => $ret];
    } catch (\Throwable $e) {
        backupRemoteRecordRun(backupRemoteRunEntry('full', $cfg, $trigger, $start, $t0, false, 0, 0, $e->getMessage()));
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        if ($tmp !== '') backupRemoteRmTree($tmp);
        backupRemoteUnlock($lock);
    }
}

function backupRemoteRunEntry(string $channel, array $cfg, string $trigger, int $start, float $t0, bool $ok, int $files, int $bytes, string $msg): array {
    return [
        'ts'       => time(),
        'started'  => $start,
        'channel'  => $channel,
        'method'   => $cfg['method'],
        'trigger'  => $trigger,
        'ok'       => $ok,
        'files'    => $files,
        'bytes'    => $bytes,
        'duration' => round(microtime(true) - $t0, 1),
        ($ok ? 'note' : 'error') => mb_substr($msg, 0, 500),
    ];
}

/**
 * Buduje w $dir pełny zestaw: umowy_STAMP.db.gz, uploads_STAMP.tar.gz, certs_STAMP.tar.gz (+ .enc przy szyfrowaniu).
 * Przy włączonym szyfrowaniu nieudane szyfrowanie przerywa wysyłkę — nic nie idzie na zewnątrz jawnym tekstem.
 */
function backupRemoteBuildFull(string $dir, string $stamp): array {
    $base = backup_base_dir();
    $enc  = backup_encryption_enabled();
    $out  = [];

    $db = defined('DB_PATH') ? (string)DB_PATH : $base . '/umowy.db';
    if ((!defined('DB_TYPE') || DB_TYPE === 'sqlite') && is_file($db)) {
        $raw = $dir . '/umowy_' . $stamp . '.db';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($raw));
        $pdo = null;
        $gz = $raw . '.gz';
        $in = fopen($raw, 'rb');
        $zw = gzopen($gz, 'wb6');
        if (!$in || !$zw) throw new RuntimeException('Nie można spakować kopii bazy.');
        while (!feof($in)) gzwrite($zw, (string)fread($in, 1048576));
        fclose($in);
        gzclose($zw);
        @unlink($raw);
        $out[] = backupRemoteSeal($gz, $enc);
    }

    $uploads = defined('UPLOAD_DIR') ? rtrim((string)UPLOAD_DIR, '/') : $base . '/uploads';
    if (is_dir($uploads)) {
        $out[] = backupRemoteSeal(backupRemoteTar($dir . '/uploads_' . $stamp . '.tar.gz', dirname($uploads), basename($uploads)), $enc);
    }
    $certs = backupRemoteCertsDir();
    if (is_dir($certs)) {
        $out[] = backupRemoteSeal(backupRemoteTar($dir . '/certs_' . $stamp . '.tar.gz', dirname($certs), basename($certs)), $enc);
    }
    return $out;
}

function backupRemoteTar(string $dst, string $cwd, string $what): string {
    $r = backupRemoteRun(['tar', '-czf', $dst, '-C', $cwd, $what]);
    if ($r['code'] !== 0) throw new RuntimeException('tar (' . $what . '): ' . backupRemoteShortErr($r['err'] ?: $r['out']));
    return $dst;
}

function backupRemoteSeal(string $path, bool $enc): string {
    if (!$enc) return $path;
    $e = backup_encrypt_file($path, null, true);
    if (!$e) throw new RuntimeException('Nie udało się zaszyfrować ' . basename($path) . ' — kopia nie zostanie wysłana bez szyfrowania.');
    return $e;
}

/** Typ kopii po nazwie, także umowy_*.db.gz i warianty .enc (backup_kind() nie zna .db.gz). */
function backupRemoteKind(string $name): ?string {
    $n = strtolower(basename($name));
    if (str_ends_with($n, '.enc')) $n = substr($n, 0, -4);
    if (str_ends_with($n, '.db.gz')) $n = substr($n, 0, -3);
    return backup_kind($n);
}

/**
 * Retencja na serwerze (przez SFTP, także przy metodzie rsync): w incremental/ i full/ zachowuje min. $keepMin
 * najnowszych kopii każdego typu, starsze niż retention_days usuwa; sprząta też porzucone *.part (> 2 dni).
 */
function backupRemoteRetention(array &$cfg, int $keepMin = 3): array {
    $days = (int)$cfg['retention_days'];
    if ($days <= 0) return ['ok' => true, 'skipped' => true, 'deleted' => 0, 'scanned' => 0];
    $sftp = backupRemoteSftp($cfg);
    $base = rtrim((string)$cfg['path'], '/');
    $now  = time();
    $files = [];
    $dirs  = [];
    $deleted = 0;
    try {
        foreach (['incremental', 'full'] as $chan) {
            $months = $sftp->rawlist($base . '/' . $chan);
            if (!is_array($months)) continue;
            foreach ($months as $mname => $ma) {
                if ((int)($ma['type'] ?? 0) !== 2 || !preg_match('/^\d{4}-\d{2}$/', (string)$mname)) continue;
                $mdir = $base . '/' . $chan . '/' . $mname;
                $list = $sftp->rawlist($mdir);
                if (!is_array($list)) continue;
                $dirs[$mdir] = true;
                foreach ($list as $fname => $fa) {
                    if ((int)($fa['type'] ?? 0) !== 1) continue;
                    $mt = (int)($fa['mtime'] ?? 0);
                    if (str_ends_with((string)$fname, '.part')) {
                        if ($now - $mt > 2 * 86400 && $sftp->delete($mdir . '/' . $fname, false)) $deleted++;
                        continue;
                    }
                    $kind = backupRemoteKind((string)$fname);
                    if ($kind === null) continue; // nie nasze pliki — nie ruszamy
                    $files[$chan . '|' . $kind][] = ['path' => $mdir . '/' . $fname, 'ts' => $mt];
                }
            }
        }
        $scanned = 0;
        foreach ($files as $group) {
            $scanned += count($group);
            usort($group, fn($a, $b) => $b['ts'] <=> $a['ts']);
            foreach (array_slice($group, $keepMin) as $f) {
                if ($now - $f['ts'] > $days * 86400 && $sftp->delete($f['path'], false)) $deleted++;
            }
        }
        foreach (array_keys($dirs) as $d) {
            $left = $sftp->nlist($d);
            if (is_array($left) && !array_diff($left, ['.', '..'])) $sftp->rmdir($d);
        }
    } finally {
        $sftp->disconnect();
    }
    return ['ok' => true, 'deleted' => $deleted, 'scanned' => $scanned];
}

// ── Test połączenia ─────────────────────────────────────────────────────────

/**
 * Test: ustawienia → połączenie i klucz hosta → logowanie → zapis w katalogu docelowym.
 * SFTP zapisuje plik testowy, sprawdza rozmiar i go usuwa; rsync zostawia mały plik .feerszo-test.
 * @return array{ok:bool,steps:list<array{label:string,ok:bool,detail:string}>,fp:string}
 */
function backupRemoteTest(array &$cfg): array {
    $steps = [];
    $add = function (string $label, bool $ok, string $detail = '') use (&$steps): void {
        $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    };
    $fp = '';
    $finish = function (bool $ok) use (&$steps, &$fp): array {
        backupRemoteStatusSet('last_test', ['ts' => time(), 'ok' => $ok, 'detail' => $ok ? $fp : (string)(end($steps)['detail'] ?? '')]);
        return ['ok' => $ok, 'steps' => $steps, 'fp' => $fp];
    };

    $err = backupRemoteValidate($cfg);
    if ($err) { $add('Ustawienia', false, implode(' ', $err)); return $finish(false); }
    $add('Ustawienia', true, strtoupper($cfg['method']) . ', ' . $cfg['user'] . '@' . $cfg['host'] . ':' . $cfg['port'] . ', katalog ' . $cfg['path']);

    $testFile = tempnam(sys_get_temp_dir(), 'brtest_');
    file_put_contents($testFile, 'FEER SZO — test połączenia kopii zapasowych ' . date('c') . "\n");
    $size = (int)filesize($testFile);
    $base = rtrim((string)$cfg['path'], '/');
    try {
        if ($cfg['method'] === 'sftp') {
            try {
                [$sftp, $hk] = backupRemoteSftpConnect($cfg);
            } catch (\Throwable $e) { $add('Połączenie i klucz hosta', false, $e->getMessage()); return $finish(false); }
            $fp = $hk['fp'];
            $add('Połączenie i klucz hosta', true, $hk['fp'] . ($hk['new'] ? ' — zapamiętany przy tym połączeniu' : ' — zgodny z zapamiętanym'));
            try {
                backupRemoteSftpLogin($sftp, $cfg);
            } catch (\Throwable $e) { $add('Logowanie', false, $e->getMessage()); $sftp->disconnect(); return $finish(false); }
            $add('Logowanie', true, $cfg['auth'] === 'key' ? 'kluczem ' . basename((string)$cfg['key_path']) : 'hasłem');
            try {
                backupRemoteSftpMkdir($sftp, $base);
                $remote = $base . '/' . BACKUP_REMOTE_TESTFILE;
                if (!$sftp->put($remote, $testFile, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                    throw new RuntimeException('Zapis pliku testowego nie powiódł się: ' . ($sftp->getLastSFTPError() ?: 'brak uprawnień'));
                }
                $rsz = $sftp->filesize($remote);
                $sftp->delete($remote, false);
                if ((int)$rsz !== $size) throw new RuntimeException('Rozmiar pliku testowego na serwerze się nie zgadza.');
                $free = '';
                $vfs = method_exists($sftp, 'statvfs') ? @$sftp->statvfs($base) : false;
                if (is_array($vfs) && isset($vfs['bavail'], $vfs['frsize'])) $free = ', wolne ok. ' . backupRemoteHumanSize((int)($vfs['bavail'] * $vfs['frsize']));
                $add('Zapis w katalogu docelowym', true, $base . ' — zapis, odczyt rozmiaru i usunięcie pliku testowego' . $free);
            } catch (\Throwable $e) {
                $add('Zapis w katalogu docelowym', false, $e->getMessage());
                $sftp->disconnect();
                return $finish(false);
            }
            $sftp->disconnect();
            return $finish(true);
        }

        $tools = backupRemoteTools();
        $add('Programy rsync i ssh', true, $tools['rsync_bin'] . ', ' . $tools['ssh_bin']);
        try {
            $hk = backupRemoteHostKeyProbe($cfg);
        } catch (\Throwable $e) { $add('Połączenie i klucz hosta', false, $e->getMessage()); return $finish(false); }
        $fp = $hk['fp'];
        $add('Połączenie i klucz hosta', true, $hk['fp'] . ($hk['new'] ? ' — zapamiętany przy tym połączeniu' : ' — zgodny z zapamiętanym'));
        try {
            backupRemoteSendRsync($cfg, [['local' => $testFile, 'rel' => BACKUP_REMOTE_TESTFILE]]);
            $add('Logowanie i zapis przez rsync', true, $base . '/' . BACKUP_REMOTE_TESTFILE . ' (mały plik testowy zostaje na serwerze)');
        } catch (\Throwable $e) {
            $add('Logowanie i zapis przez rsync', false, $e->getMessage());
            return $finish(false);
        }
        if ((int)$cfg['retention_days'] > 0) {
            // Retencja działa przez SFTP — przy rsync sprawdzamy, czy konto to umożliwia (tylko ostrzeżenie).
            try {
                $s = backupRemoteSftp($cfg);
                $s->disconnect();
                $add('SFTP dla retencji', true, 'stare kopie będą usuwane po ' . (int)$cfg['retention_days'] . ' dniach');
            } catch (\Throwable $e) {
                $add('SFTP dla retencji (ostrzeżenie)', true, 'Konto nie pozwala na SFTP — stare kopie trzeba usuwać na serwerze samodzielnie (' . backupRemoteShortErr($e->getMessage()) . ').');
            }
        }
        return $finish(true);
    } finally {
        @unlink($testFile);
    }
}

// ── Klucz SSH aplikacji ─────────────────────────────────────────────────────

function backupRemoteKeyDefaultPath(): string {
    return backupRemoteCertsDir() . '/backup_remote/id_ed25519';
}

/**
 * Generuje parę kluczy Ed25519 w certs/backup_remote/ (prywatny 0600, format OpenSSH — działa i z phpseclib, i z ssh).
 * Poprzedni klucz zostaje jako id_ed25519.old-RRRRMMDD_GGMMSS. Zwraca ['path','public'].
 */
function backupRemoteGenerateKey(): array {
    backupRemoteVendor();
    $path = backupRemoteKeyDefaultPath();
    $dir  = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Nie można utworzyć katalogu ' . $dir . '.');
    $comment = 'feerszo-backup@' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)(gethostname() ?: 'serwer'));
    $key  = \phpseclib3\Crypt\EC::createKey('Ed25519');
    $priv = $key->toString('OpenSSH', ['comment' => $comment]);
    $pub  = $key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]);
    if (is_file($path)) {
        $stamp = date('Ymd_His');
        @rename($path, $path . '.old-' . $stamp);
        if (is_file($path . '.pub')) @rename($path . '.pub', $path . '.pub.old-' . $stamp);
    }
    $old = umask(0077);
    $okPriv = file_put_contents($path, $priv);
    umask($old);
    @chmod($path, 0600);
    if ($okPriv === false) throw new RuntimeException('Nie można zapisać klucza prywatnego w ' . $path . '.');
    @file_put_contents($path . '.pub', trim($pub) . "\n");
    return ['path' => $path, 'public' => trim($pub)];
}

/** Klucz publiczny do authorized_keys dla wskazanego klucza prywatnego ('' gdy nie da się odczytać). */
function backupRemotePublicKey(string $path): string {
    if ($path === '' || !is_file($path) || !is_readable($path)) return '';
    $pubFile = $path . '.pub';
    if (is_readable($pubFile)) {
        $p = trim((string)file_get_contents($pubFile));
        if (preg_match('/^(ssh-|ecdsa-)[A-Za-z0-9@.-]+ [A-Za-z0-9+\/=]+/', $p)) return $p;
    }
    try {
        backupRemoteVendor();
        return (string)\phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey((string)file_get_contents($path))
            ->getPublicKey()->toString('OpenSSH', ['comment' => 'feerszo-backup']);
    } catch (\Throwable $e) {
        return '';
    }
}

// ── Pomocnicze ──────────────────────────────────────────────────────────────

function backupRemoteTmpDir(string $tag): string {
    $d = sys_get_temp_dir() . '/feer_remote_' . $tag . '_' . bin2hex(random_bytes(6));
    if (!@mkdir($d, 0700, true)) throw new RuntimeException('Nie można utworzyć katalogu tymczasowego.');
    return $d;
}

function backupRemoteRmTree(string $dir): void {
    if ($dir === '' || !is_dir($dir) || is_link($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Skraca komunikat błędu do jednej, czytelnej linii (bez ścieżek plików tymczasowych). */
function backupRemoteShortErr(string $msg): string {
    $msg = preg_replace('#' . preg_quote(sys_get_temp_dir(), '#') . '/feer_remote_[A-Za-z0-9_]+#', '…', $msg) ?? $msg;
    $msg = trim(preg_replace('/\s+/', ' ', $msg) ?? $msg);
    return mb_substr($msg, 0, 300);
}

function backupRemoteHumanSize(int $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}

/** Skrót do kafla w „Kondycji kopii”. */
function backupRemoteSummary(): array {
    $cfg = backupRemoteConfig();
    $st  = backupRemoteStatus();
    return [
        'enabled' => $cfg['enabled'],
        'method'  => $cfg['method'],
        'host'    => $cfg['host'],
        'last_ok' => backupRemoteLastOkTs(),
        'last'    => $st['runs'][0] ?? null,
        'last_full' => (int)$st['last_full'],
    ];
}
