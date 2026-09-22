<?php
/**
 * includes/backup.php — wspólna logika kopii zapasowych:
 *   • szyfrowanie archiwów AES-256-CBC (opcjonalne, klucz z konfiguracji),
 *   • rozpoznawanie typu kopii (db / uploads / certs), także zaszyfrowanych,
 *   • przywracanie bazy i archiwów z panelu (z kopią bezpieczeństwa).
 *
 * Używane przez cron/agents/backup.php (szyfrowanie + certs + rotacja) oraz
 * admin/backups.php (status szyfrowania, przywracanie).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** Katalog główny aplikacji (BASE_DIR/includes → BASE_DIR). */
function backup_base_dir(): string {
    return rtrim(dirname(__DIR__), '/');
}

function backup_root(): string {
    return backup_base_dir() . '/backups';
}

// ── Szyfrowanie ─────────────────────────────────────────────────────────────

/**
 * Klucz szyfrowania kopii. Priorytet: stała BACKUP_ENCRYPT_KEY (config.php),
 * następnie ustawienie `backup_encrypt_key`. Pusty = szyfrowanie wyłączone.
 */
function backup_encrypt_key(): string {
    if (defined('BACKUP_ENCRYPT_KEY') && BACKUP_ENCRYPT_KEY !== '') return (string)BACKUP_ENCRYPT_KEY;
    return function_exists('org_setting') ? trim(org_setting('backup_encrypt_key')) : '';
}

function backup_encryption_enabled(): bool {
    return backup_encrypt_key() !== '' && backup_openssl_available();
}

function backup_openssl_available(): bool {
    static $r = null;
    if ($r !== null) return $r;
    $out = []; $code = 1;
    @exec('openssl version 2>/dev/null', $out, $code);
    return $r = ($code === 0);
}

/**
 * Szyfruje plik do <src>.enc (AES-256-CBC, PBKDF2). Zwraca ścieżkę .enc lub null.
 * Domyślnie usuwa plaintext po udanym zaszyfrowaniu.
 */
function backup_encrypt_file(string $src, ?string $key = null, bool $removePlain = true): ?string {
    $key = $key ?? backup_encrypt_key();
    if ($key === '' || !is_file($src) || !backup_openssl_available()) return null;
    $dst = $src . '.enc';
    $cmd = 'openssl enc -aes-256-cbc -pbkdf2 -salt'
         . ' -in '   . escapeshellarg($src)
         . ' -out '  . escapeshellarg($dst)
         . ' -pass ' . escapeshellarg('pass:' . $key) . ' 2>&1';
    $out = []; $ret = 1; exec($cmd, $out, $ret);
    if ($ret !== 0 || !is_file($dst) || filesize($dst) === 0) { @unlink($dst); return null; }
    if ($removePlain) @unlink($src);
    return $dst;
}

/** Deszyfruje .enc do pliku tymczasowego. Zwraca ścieżkę temp lub null. */
function backup_decrypt_file(string $enc, ?string $key = null): ?string {
    $key = $key ?? backup_encrypt_key();
    if ($key === '' || !is_file($enc) || !backup_openssl_available()) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'bak_dec_');
    $cmd = 'openssl enc -d -aes-256-cbc -pbkdf2'
         . ' -in '   . escapeshellarg($enc)
         . ' -out '  . escapeshellarg($tmp)
         . ' -pass ' . escapeshellarg('pass:' . $key) . ' 2>&1';
    $out = []; $ret = 1; exec($cmd, $out, $ret);
    if ($ret !== 0) { @unlink($tmp); return null; }
    return $tmp;
}

function backup_is_encrypted(string $name): bool {
    return str_ends_with(strtolower($name), '.enc');
}

/** Generuje losowy, mocny klucz szyfrowania (hex, 64 znaki = 256 bit). */
function backup_generate_key(): string {
    return bin2hex(random_bytes(32));
}

// ── Rozpoznawanie typu kopii ────────────────────────────────────────────────

/** @return 'db'|'uploads'|'certs'|null — rozpoznaje też warianty .enc. */
function backup_kind(string $name): ?string {
    $n = strtolower(basename($name));
    if (str_ends_with($n, '.enc')) $n = substr($n, 0, -4);
    if (str_ends_with($n, '.db')) return 'db';
    if (str_starts_with($n, 'uploads_') && str_ends_with($n, '.tar.gz')) return 'uploads';
    if (str_starts_with($n, 'certs_')   && str_ends_with($n, '.tar.gz')) return 'certs';
    return null;
}

// ── Przywracanie ────────────────────────────────────────────────────────────

/**
 * Przywraca bazę SQLite z pliku kopii (.db lub .db.enc). Waliduje integralność
 * (PRAGMA integrity_check) PRZED podmianą i robi kopię bezpieczeństwa bieżącej bazy.
 * @return array{ok:bool,msg:string,safety?:string}
 */
function backup_restore_db(string $full): array {
    if (!is_file($full)) return ['ok' => false, 'msg' => 'Plik kopii nie istnieje.'];

    $src = $full; $tmp = null;
    if (backup_is_encrypted($full)) {
        $tmp = backup_decrypt_file($full);
        if (!$tmp) return ['ok' => false, 'msg' => 'Nie udało się odszyfrować kopii (błędny klucz?).'];
        $src = $tmp;
    }

    // Walidacja: poprawny, spójny plik SQLite.
    try {
        $chk = new PDO('sqlite:' . $src);
        $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $chk->query('PRAGMA integrity_check')->fetch(PDO::FETCH_NUM);
        $chk = null;
        if (!$row || strtolower((string)$row[0]) !== 'ok') {
            if ($tmp) @unlink($tmp);
            return ['ok' => false, 'msg' => 'Kopia nie przeszła kontroli integralności (integrity_check) — przerwano.'];
        }
    } catch (\Throwable $e) {
        if ($tmp) @unlink($tmp);
        return ['ok' => false, 'msg' => 'Nieprawidłowy plik bazy: ' . $e->getMessage()];
    }

    // Kopia bezpieczeństwa bieżącej bazy PRZED podmianą.
    $dbp    = DB_PATH;
    $safety = backup_root() . '/' . date('Y-m') . '/umowy_pre-restore_' . date('Ymd_His') . '.db';
    @mkdir(dirname($safety), 0750, true);
    try {
        $pdo = new PDO('sqlite:' . $dbp);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($safety));
        $pdo = null;
    } catch (\Throwable $e) { /* brak bieżącej bazy — kontynuujemy */ }

    if (!@copy($src, $dbp)) {
        if ($tmp) @unlink($tmp);
        return ['ok' => false, 'msg' => 'Nie udało się nadpisać bazy docelowej (uprawnienia?).'];
    }
    if ($tmp) @unlink($tmp);

    return [
        'ok'     => true,
        'msg'    => 'Baza przywrócona. Kopia sprzed przywrócenia: ' . basename($safety),
        'safety' => $safety,
    ];
}

/**
 * Przywraca archiwum (uploads/certs) — rozpakowuje do katalogu głównego aplikacji.
 * Archiwa zawierają prefiks katalogu (uploads/ lub certs/), więc nadpisują właściwe pliki.
 * @return array{ok:bool,msg:string}
 */
function backup_restore_archive(string $full): array {
    if (!is_file($full)) return ['ok' => false, 'msg' => 'Plik kopii nie istnieje.'];

    $src = $full; $tmp = null;
    if (backup_is_encrypted($full)) {
        $tmp = backup_decrypt_file($full);
        if (!$tmp) return ['ok' => false, 'msg' => 'Nie udało się odszyfrować kopii (błędny klucz?).'];
        // tar wymaga rozpoznania po zawartości, nie po rozszerzeniu — kopiujemy z .tar.gz
        $tgz = $tmp . '.tar.gz';
        @rename($tmp, $tgz);
        $src = $tgz; $tmp = $tgz;
    }

    $dest = backup_base_dir();
    $cmd  = 'tar -xzf ' . escapeshellarg($src) . ' -C ' . escapeshellarg($dest) . ' 2>&1';
    $out = []; $ret = 1; exec($cmd, $out, $ret);
    if ($tmp) @unlink($tmp);

    if ($ret !== 0) return ['ok' => false, 'msg' => 'Rozpakowanie nie powiodło się: ' . implode(' ', $out)];
    return ['ok' => true, 'msg' => 'Archiwum rozpakowane — pliki z kopii nadpisały bieżące.'];
}
