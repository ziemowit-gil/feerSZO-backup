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

// ── Manifest integralności (SHA-256) ────────────────────────────────────────

function backup_manifest_path(): string { return backup_root() . '/manifest.json'; }

function backup_manifest_load(): array {
    $p = backup_manifest_path();
    if (!is_file($p)) return [];
    $j = json_decode((string)file_get_contents($p), true);
    return is_array($j) ? $j : [];
}

function backup_manifest_save(array $m): void {
    @file_put_contents(backup_manifest_path(), json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function backup_sha256(string $path): string {
    return is_file($path) ? (hash_file('sha256', $path) ?: '') : '';
}

/** Ścieżka względna pliku wobec katalogu backups/ (lub null poza nim). */
function backup_rel(string $full): ?string {
    $root = realpath(backup_root());
    $real = realpath($full);
    if (!$root || !$real || !str_starts_with($real, $root . '/')) return null;
    return ltrim(substr($real, strlen($root)), '/');
}

/** Zapisuje/aktualizuje wpis manifestu dla pliku. $extra np. ['db_integrity'=>'ok']. */
function backup_manifest_record(string $full, array $extra = []): void {
    $rel = backup_rel($full);
    if ($rel === null || !is_file($full)) return;
    $m = backup_manifest_load();
    $m[$rel] = array_merge([
        'sha256'      => backup_sha256($full),
        'size'        => filesize($full) ?: 0,
        'mtime'       => filemtime($full) ?: 0,
        'kind'        => backup_kind(basename($full)),
        'encrypted'   => backup_is_encrypted(basename($full)),
        'recorded_at' => date('Y-m-d H:i:s'),
    ], $extra);
    backup_manifest_save($m);
}

/** Usuwa z manifestu wpisy o nieistniejących plikach. Zwraca liczbę usuniętych. */
function backup_manifest_prune(): int {
    $root = rtrim(backup_root(), '/');
    $m = backup_manifest_load();
    $before = count($m);
    foreach (array_keys($m) as $rel) {
        if (!is_file($root . '/' . $rel)) unset($m[$rel]);
    }
    if (count($m) !== $before) backup_manifest_save($m);
    return $before - count($m);
}

/**
 * Weryfikuje kopie: porównuje SHA-256 z manifestem; dla baz (gdy $deep i jest
 * możliwość odczytu) uruchamia PRAGMA integrity_check.
 * Statusy: 'ok' | 'changed' (suma się nie zgadza) | 'unmanifested' (brak wpisu)
 *          | 'missing' (wpis bez pliku) | 'integrity_fail' | 'unreadable'.
 * @return array{items:array<int,array>,problems:int,checked:int}
 */
function backup_verify(bool $deep = true): array {
    $root  = rtrim(backup_root(), '/');
    $man   = backup_manifest_load();
    $items = [];
    $seen  = [];
    $problems = 0;

    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $kind = backup_kind($file->getFilename());
            if ($kind === null) continue;
            $rel  = backup_rel($file->getPathname());
            if ($rel === null) continue;
            $seen[$rel] = true;

            $sha = backup_sha256($file->getPathname());
            $entry = $man[$rel] ?? null;
            $status = 'ok'; $detail = '';

            if (!$entry) {
                $status = 'unmanifested';
                $detail = 'Brak wpisu w manifeście (kopia sprzed weryfikacji lub dodana ręcznie).';
            } elseif (($entry['sha256'] ?? '') === '') {
                $status = 'unmanifested'; $detail = 'Manifest bez sumy kontrolnej.';
            } elseif ($sha !== $entry['sha256']) {
                $status = 'changed';
                $detail = 'Suma SHA-256 różni się od zapisanej — plik uszkodzony lub zmieniony.';
            }

            // Głęboka kontrola baz (integrity_check) — deszyfruje, jeśli trzeba i jest klucz.
            if ($deep && $kind === 'db' && $status !== 'changed') {
                $src = $file->getPathname(); $tmp = null;
                if (backup_is_encrypted($file->getFilename())) {
                    $tmp = backup_decrypt_file($src);
                    $src = $tmp ?: $src;
                }
                if ($tmp === null && backup_is_encrypted($file->getFilename())) {
                    $status = 'unreadable'; $detail = 'Zaszyfrowana — brak klucza do kontroli integralności.';
                } else {
                    try {
                        $pdo = new PDO('sqlite:' . $src);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $r = $pdo->query('PRAGMA integrity_check')->fetch(PDO::FETCH_NUM);
                        $pdo = null;
                        if (!$r || strtolower((string)$r[0]) !== 'ok') {
                            $status = 'integrity_fail'; $detail = 'PRAGMA integrity_check: ' . ($r[0] ?? '—');
                        }
                    } catch (\Throwable $e) {
                        $status = 'integrity_fail'; $detail = 'Błąd otwarcia bazy: ' . $e->getMessage();
                    }
                    if ($tmp) @unlink($tmp);
                }
            }

            if (!in_array($status, ['ok', 'unmanifested'], true)) $problems++;
            $sz = filesize($file->getPathname()) ?: 0;
            $items[] = [
                'rel' => $rel, 'kind' => $kind, 'status' => $status, 'detail' => $detail,
                'size_h' => $sz > 1048576 ? round($sz/1048576, 1) . ' MB' : round($sz/1024) . ' KB',
                'sha' => $sha,
            ];
        }
    }

    // Wpisy w manifeście bez pliku (usunięte poza rotacją) = 'missing'.
    foreach ($man as $rel => $e) {
        if (!empty($seen[$rel])) continue;
        $problems++;
        $items[] = ['rel' => $rel, 'kind' => $e['kind'] ?? null, 'status' => 'missing',
                    'detail' => 'Plik z manifestu nie istnieje.', 'size_h' => '—', 'sha' => ''];
    }

    usort($items, fn($a, $b) => strcmp($b['rel'], $a['rel']));
    return ['items' => $items, 'problems' => $problems, 'checked' => count($items)];
}

// ── Stan / RPO ──────────────────────────────────────────────────────────────

/** Znacznik ostatniego udanego przebiegu agenta (unix ts) lub 0. */
function backup_last_ok_ts(): int {
    $p = backup_root() . '/.last_run_ok';
    return is_file($p) ? (int)file_get_contents($p) : 0;
}

function backup_mark_run_ok(): void {
    @file_put_contents(backup_root() . '/.last_run_ok', (string)time());
}

/** Maksymalny dopuszczalny wiek ostatniego udanego backupu (godziny) — konfigurowalny. */
function backup_max_age_hours(): int {
    $h = (int)(function_exists('org_setting') ? org_setting('backup_alert_max_hours') : 0);
    return $h > 0 ? $h : 8;
}

/** Czy najnowszy udany backup jest przeterminowany względem progu RPO. */
function backup_is_stale(): bool {
    $last = backup_last_ok_ts();
    if ($last === 0) return false; // brak historii — nie alarmujemy fałszywie
    return (time() - $last) > backup_max_age_hours() * 3600;
}

// ── Alerty do administratorów ────────────────────────────────────────────────

/** Aktywni administratorzy z adresem e-mail. */
function backup_admins(): array {
    try {
        return db_all("SELECT id, name, email FROM users WHERE role='admin' AND is_active=1");
    } catch (\Throwable $e) { return []; }
}

/**
 * Wysyła alert do administratorów (dzwonek + e-mail). $dedupKey + $minIntervalH
 * ograniczają częstotliwość (nie spamujemy tym samym alertem).
 */
function backup_alert(string $title, string $bodyText, string $dedupKey = '', int $minIntervalH = 6): bool {
    // Deduplikacja przez settings.
    if ($dedupKey !== '') {
        $k = 'backup_alert_' . $dedupKey;
        try {
            $prev = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
            if ($prev && (time() - (int)$prev['value']) < $minIntervalH * 3600) return false;
            org_setting_set($k, (string)time());
        } catch (\Throwable $e) {}
    }

    $admins = backup_admins();
    if (!$admins) return false;

    // Zależności ładowane leniwie (agent/cron ich nie wciąga domyślnie).
    if (!function_exists('notif_create') && is_file(__DIR__ . '/notifications.php')) { require_once __DIR__ . '/notifications.php'; }
    if (!function_exists('mail_queue_add') && is_file(__DIR__ . '/mail_queue.php')) { require_once __DIR__ . '/mail_queue.php'; }

    $url = (defined('APP_URL') ? APP_URL : '') . '/admin/backups.php';

    // Dzwonek in-app.
    if (function_exists('notif_create')) {
        if (function_exists('notif_migrate')) { try { notif_migrate(); } catch (\Throwable $e) {} }
        foreach ($admins as $a) {
            try { notif_create((int)$a['id'], 'backup', $title, mb_substr($bodyText, 0, 200), $url); } catch (\Throwable $e) {}
        }
    }

    // E-mail.
    if (function_exists('mail_queue_add')) {
        $org  = defined('ORG_NAME') ? ORG_NAME : 'System';
        $body = nl2br(htmlspecialchars($bodyText));
        $link = htmlspecialchars($url);
        $html = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#dc3545;padding:18px 22px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.05rem">⚠ Kopie zapasowe — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:22px;border-radius:0 0 10px 10px">
  <p style="font-weight:600">{$title}</p>
  <div style="border-left:3px solid #dc3545;background:#fff5f5;border-radius:4px;padding:10px 14px;margin:14px 0;font-size:.92em">{$body}</div>
  <div style="margin:20px 0"><a href="{$link}" style="background:#dc3545;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600">Otwórz panel kopii →</a></div>
</div></body></html>
HTML;
        foreach ($admins as $a) {
            if (!empty($a['email'])) { try { mail_queue_add($a['email'], $a['name'] ?? '', '⚠ ' . $title, $html); } catch (\Throwable $e) {} }
        }
    }
    return true;
}
