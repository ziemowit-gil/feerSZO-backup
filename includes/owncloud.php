<?php
/**
 * includes/owncloud.php — Integracja z ownCloud (WebDAV) jako magazyn plików
 * dla materiałów TI i załączników zadań domowych (zastępuje lokalny dysk).
 *
 * Konfiguracja w settings (prefix owncloud_): url, username, password,
 * base_folder. Wywołania przez stream_context — bez zależności curl,
 * spójnie z includes/payu.php i includes/stripe.php.
 *
 * Pliki wgrane PRZED włączeniem tej integracji zostają na lokalnym dysku —
 * k30_ti_homework_send_file()/k30_ti_material_send_file() najpierw sprawdzają
 * lokalną ścieżkę, dopiero potem ownCloud, więc migracja jest nieprzerywająca.
 */

// ── Ustawienia ────────────────────────────────────────────────────────────────
function owncloud_setting(string $key, string $default = ''): string {
    static $cache = [];
    $fk = 'owncloud_' . $key;
    if (!array_key_exists($fk, $cache)) {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$fk]);
            $cache[$fk] = $r['value'] ?? $default;
        } catch (\Throwable $e) { $cache[$fk] = $default; }
    }
    return $cache[$fk] !== '' ? $cache[$fk] : $default;
}

function owncloud_save_setting(string $key, string $value): void {
    $fk = 'owncloud_' . $key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $fk]);
        else     db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?)")->execute([$fk, $value]);
    }
}

function owncloud_configured(): bool {
    return owncloud_setting('url') !== '' && owncloud_setting('username') !== '' && owncloud_setting('password') !== '';
}

function owncloud_enabled(): bool {
    return owncloud_setting('enabled') === '1' && owncloud_configured();
}

/** Bieżąca konfiguracja jako tablica (do przekazania do owncloud_request() itp.). */
function owncloud_config(): array {
    return [
        'url'         => rtrim(owncloud_setting('url'), '/'),
        'username'    => owncloud_setting('username'),
        'password'    => owncloud_setting('password'),
        'base_folder' => trim(owncloud_setting('base_folder', 'feerszo-pliki-lekcji'), '/') ?: 'feerszo-pliki-lekcji',
    ];
}

// ── Migracja ustawień (wartości domyślne) ──────────────────────────────────────
function owncloud_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $defaults = [
        'owncloud_enabled'     => '0',
        'owncloud_url'         => '',
        'owncloud_username'    => '',
        'owncloud_password'    => '',
        'owncloud_base_folder' => 'feerszo-pliki-lekcji',
    ];
    foreach ($defaults as $key => $val) {
        try {
            $exists = db_one("SELECT id FROM settings WHERE key_=?", [$key]);
            if (!$exists) db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $val]);
        } catch (\Throwable $e) {}
    }
}

/** Pełny URL WebDAV dla ścieżki względnej katalogu bazowego. */
function _owncloud_url(array $cfg, string $remote_path): string {
    $path = trim($cfg['base_folder'] . '/' . ltrim($remote_path, '/'), '/');
    $segments = array_map('rawurlencode', explode('/', $path));
    return $cfg['url'] . '/remote.php/dav/files/' . rawurlencode($cfg['username']) . '/' . implode('/', $segments);
}

/**
 * Surowe żądanie WebDAV przez stream_context (bez curl).
 * Zwraca ['ok'=>bool, 'http'=>int, 'body'=>string, 'err'=>string].
 */
function owncloud_request(array $cfg, string $method, string $url, string $body = '', array $extra_headers = []): array {
    if ($cfg['username'] === '' || $cfg['password'] === '') {
        return ['ok' => false, 'http' => 0, 'body' => '', 'err' => 'Brak danych logowania ownCloud.'];
    }
    $auth = base64_encode($cfg['username'] . ':' . $cfg['password']);
    $headers = array_merge(["Authorization: Basic {$auth}"], $extra_headers);

    $ctx = stream_context_create(['http' => [
        'method'         => $method,
        'header'         => implode("\r\n", $headers) . "\r\n",
        'content'        => $body,
        'ignore_errors'  => true,
        'timeout'        => 60,
        'follow_location'=> 0,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $code = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        $code = (int)$m[1];
    }
    if ($raw === false) {
        return ['ok' => false, 'http' => $code, 'body' => '', 'err' => error_get_last()['message'] ?? 'Błąd połączenia.'];
    }
    return ['ok' => $code >= 200 && $code < 300, 'http' => $code, 'body' => $raw, 'err' => ''];
}

/** Tworzy katalog bazowy (MKCOL) — 405 "już istnieje" traktowane jako sukces. */
function owncloud_ensure_base_folder(array $cfg): bool {
    $url = $cfg['url'] . '/remote.php/dav/files/' . rawurlencode($cfg['username']) . '/' . rawurlencode($cfg['base_folder']);
    $r = owncloud_request($cfg, 'MKCOL', $url);
    return $r['ok'] || $r['http'] === 405;
}

/** Wgrywa plik pod $remote_path (względem katalogu bazowego). */
function owncloud_put(string $remote_path, string $local_file_path): array {
    $cfg = owncloud_config();
    if (!owncloud_configured()) return ['ok' => false, 'msg' => 'Integracja ownCloud nie jest skonfigurowana.'];
    $data = @file_get_contents($local_file_path);
    if ($data === false) return ['ok' => false, 'msg' => 'Nie udało się odczytać pliku lokalnego do wysłania.'];
    owncloud_ensure_base_folder($cfg);
    $r = owncloud_request($cfg, 'PUT', _owncloud_url($cfg, $remote_path), $data, ['Content-Type: application/octet-stream']);
    if ($r['ok']) return ['ok' => true, 'msg' => "Wgrano do ownCloud ({$r['http']})."];
    return ['ok' => false, 'msg' => $r['err'] !== '' ? $r['err'] : "Błąd HTTP {$r['http']} przy wgrywaniu do ownCloud."];
}

/** Pobiera zawartość pliku z $remote_path. Zwraca null przy błędzie. */
function owncloud_get(string $remote_path): ?string {
    $cfg = owncloud_config();
    if (!owncloud_configured()) return null;
    $r = owncloud_request($cfg, 'GET', _owncloud_url($cfg, $remote_path));
    return $r['ok'] ? $r['body'] : null;
}

/** Usuwa plik pod $remote_path. 404 traktowane jako sukces (już nie ma czego usuwać). */
function owncloud_delete(string $remote_path): bool {
    $cfg = owncloud_config();
    if (!owncloud_configured()) return false;
    $r = owncloud_request($cfg, 'DELETE', _owncloud_url($cfg, $remote_path));
    return $r['ok'] || $r['http'] === 404;
}

/** Test połączenia — PROPFIND na katalogu głównym użytkownika. Przyjmuje $cfg z formularza (przed zapisem). */
function owncloud_test_connection(array $cfg): array {
    if ($cfg['url'] === '' || $cfg['username'] === '' || $cfg['password'] === '') {
        return ['ok' => false, 'msg' => 'Uzupełnij adres URL, login i hasło.'];
    }
    $cfg['base_folder'] = $cfg['base_folder'] ?: 'feerszo-pliki-lekcji';
    $url = rtrim($cfg['url'], '/') . '/remote.php/dav/files/' . rawurlencode($cfg['username']) . '/';
    $r = owncloud_request($cfg, 'PROPFIND', $url, '', ['Depth: 0']);
    if ($r['ok'] || $r['http'] === 207) {
        owncloud_ensure_base_folder($cfg);
        return ['ok' => true, 'msg' => "Połączono jako „{$cfg['username']}”. Katalog „{$cfg['base_folder']}” gotowy."];
    }
    if ($r['http'] === 401) return ['ok' => false, 'msg' => 'Błędny login lub hasło (401).'];
    return ['ok' => false, 'msg' => 'Błąd połączenia' . ($r['http'] ? " (HTTP {$r['http']})" : '') . ': ' . ($r['err'] ?: 'sprawdź adres URL.')];
}
