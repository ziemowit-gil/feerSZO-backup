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
        'owncloud_enabled'          => '0',
        'owncloud_url'              => '',
        'owncloud_username'         => '',
        'owncloud_password'         => '',
        'owncloud_base_folder'      => 'feerszo-pliki-lekcji',
        'owncloud_admin_username'      => '',
        'owncloud_admin_password'      => '',
        'owncloud_student_quota_mb'    => '2048',
        'owncloud_instructor_quota_mb' => '5120',
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

// ══ OCS PROVISIONING API — konta studentów (panel kursanta, „mój dysk") ══════
//
// Osobne, wyżej uprzywilejowane konto (administrator ownCloud) — inne niż
// konto integracyjne WebDAV powyżej, które służy tylko do wgrywania materiałów.
// Provisioning API działa po zwykłym HTTP (Basic Auth), więc aplikacja nie
// potrzebuje dostępu do socketu Dockera / `occ` — tylko sieciowego dostępu do
// instancji ownCloud, tak jak WebDAV.

/** Konfiguracja konta administratora (do OCS Provisioning API). */
function owncloud_admin_config(): array {
    return [
        'url'      => rtrim(owncloud_setting('url'), '/'),
        'username' => owncloud_setting('admin_username'),
        'password' => owncloud_setting('admin_password'),
    ];
}

function owncloud_admin_configured(): bool {
    $cfg = owncloud_admin_config();
    return $cfg['url'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '';
}

/**
 * Wywołanie OCS Provisioning API. Zwraca ujednolicony wynik niezależnie od
 * tego, czy ownCloud odpowiedział poprawnym envelope OCS czy błędem transportu.
 *
 * @param array  $admin_cfg z owncloud_admin_config()
 * @param string $path      np. "cloud/users" lub "cloud/users/jkowalski"
 * @param array  $fields    pola formularza dla POST/PUT (form-urlencoded)
 */
function owncloud_ocs_request(array $admin_cfg, string $method, string $path, array $fields = []): array {
    if ($admin_cfg['username'] === '' || $admin_cfg['password'] === '') {
        return ['ok' => false, 'statuscode' => 0, 'data' => [], 'message' => 'Brak danych administratora ownCloud.'];
    }
    $url  = $admin_cfg['url'] . '/ocs/v1.php/' . ltrim($path, '/') . '?format=json';
    $body = $fields ? http_build_query($fields) : '';
    $headers = ['OCS-APIRequest: true'];
    if ($body !== '') $headers[] = 'Content-Type: application/x-www-form-urlencoded';

    // owncloud_request() wymaga w $cfg 'username'/'password' — konto administratora pełni tu tę rolę.
    $adminAsCfg = ['username' => $admin_cfg['username'], 'password' => $admin_cfg['password']];
    $r = owncloud_request($adminAsCfg, $method, $url, $body, $headers);

    if (!$r['ok'] && $r['http'] === 0) {
        return ['ok' => false, 'statuscode' => 0, 'data' => [], 'message' => $r['err'] ?: 'Błąd połączenia z ownCloud.'];
    }

    $decoded = json_decode($r['body'], true);
    $meta    = $decoded['ocs']['meta'] ?? [];
    $status  = (int)($meta['statuscode'] ?? 0);
    // 100 = sukces w OCS (niezależnie od kodu HTTP, który bywa zawsze 200 nawet dla błędów OCS).
    $message = $meta['message'] ?? '';
    if ($message === '' && $status === 0) {
        // json_decode() nie zwrócił poprawnego envelope OCS — zwykle znaczy, że żądanie
        // w ogóle nie trafiło do Provisioning API (np. HTML zamiast JSON): błędny URL,
        // złe dane administratora (strona logowania zamiast 401 JSON) albo wyłączona
        // aplikacja „Provisioning API” w ownCloud. Kod HTTP jest tu kluczową wskazówką —
        // bez niego poprzednia wersja dawała tylko bezużyteczne „Nieprawidłowa odpowiedź”.
        $message = $r['err'] !== '' ? $r['err']
            : "Nieprawidłowa odpowiedź ownCloud (HTTP {$r['http']}) — sprawdź adres URL, dane administratora"
              . " i czy w ownCloud jest włączona aplikacja „Provisioning API” (occ app:enable provisioning_api).";
    }
    return [
        'ok'         => $status === 100,
        'statuscode' => $status,
        'data'       => $decoded['ocs']['data'] ?? [],
        'message'    => $message,
    ];
}

/** Czy konto $userid już istnieje w ownCloud. */
function owncloud_user_exists(array $admin_cfg, string $userid): bool {
    $r = owncloud_ocs_request($admin_cfg, 'GET', 'cloud/users/' . rawurlencode($userid));
    return $r['ok'];
}

/** Tworzy konto w ownCloud. */
function owncloud_create_user(array $admin_cfg, string $userid, string $password): array {
    $r = owncloud_ocs_request($admin_cfg, 'POST', 'cloud/users', ['userid' => $userid, 'password' => $password]);
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'Konto utworzone.' : ('Błąd tworzenia konta ownCloud: ' . ($r['message'] ?: "kod {$r['statuscode']}"))];
}

/** Ustawia limit miejsca (np. "2048MB", "none" = bez limitu). */
function owncloud_set_quota(array $admin_cfg, string $userid, string $quota): array {
    $r = owncloud_ocs_request($admin_cfg, 'PUT', 'cloud/users/' . rawurlencode($userid), ['key' => 'quota', 'value' => $quota]);
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'Limit ustawiony.' : ('Błąd ustawiania limitu: ' . ($r['message'] ?: "kod {$r['statuscode']}"))];
}

/** Ustawia nowe hasło (do resetu hasła studenta). */
function owncloud_set_password(array $admin_cfg, string $userid, string $password): array {
    $r = owncloud_ocs_request($admin_cfg, 'PUT', 'cloud/users/' . rawurlencode($userid), ['key' => 'password', 'value' => $password]);
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'Hasło zresetowane.' : ('Błąd resetu hasła: ' . ($r['message'] ?: "kod {$r['statuscode']}"))];
}

/**
 * Sanityzuje login do bezpiecznego dla ownCloud zestawu znaków i dokleja
 * sufiks liczbowy przy kolizji nazwy — wzorzec jak M365Graph::unique_login()
 * w includes/m365.php.
 */
function owncloud_unique_username(array $admin_cfg, string $base, string $fallback = 'konto'): string {
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $base);
    $safe = trim($safe, '._-') ?: $fallback;
    if (!owncloud_user_exists($admin_cfg, $safe)) return $safe;
    for ($i = 1; $i <= 99; $i++) {
        $candidate = "{$safe}{$i}";
        if (!owncloud_user_exists($admin_cfg, $candidate)) return $candidate;
    }
    return $safe . bin2hex(random_bytes(2));
}

/**
 * Wysyła dane logowania do konta ownCloud e-mailem i SMS-em — ten sam wzorzec
 * co ti_send_ms_credentials() w includes/ti_online.php (konto szkoleniowe MS).
 * Nigdy nie rzuca wyjątku — wysyłka jest opcjonalna, nie może zablokować
 * utworzenia/resetu konta.
 */
function owncloud_send_credentials(string $email, string $phone, string $name, string $username, string $password, string $url): void {
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (!function_exists('mail_queue_add')) { @require_once __DIR__ . '/mail_queue.php'; }
        if (function_exists('mail_queue_add')) {
            $nameH = htmlspecialchars($name, ENT_QUOTES);
            $urlH  = htmlspecialchars($url, ENT_QUOTES);
            $html = "<p>Cześć {$nameH},</p>"
                  . "<p>Utworzono dla Ciebie konto „Mój dysk” (ownCloud) w {$org}.</p>"
                  . "<table style='border-collapse:collapse;font-size:14px'>"
                  . "<tr><td style='padding:4px 12px;color:#555'>Adres</td><td style='padding:4px 12px'><a href='{$urlH}'>{$urlH}</a></td></tr>"
                  . "<tr><td style='padding:4px 12px;color:#555'>Login</td><td style='padding:4px 12px'><code>" . htmlspecialchars($username, ENT_QUOTES) . "</code></td></tr>"
                  . "<tr><td style='padding:4px 12px;color:#555'>Hasło</td><td style='padding:4px 12px'><code>" . htmlspecialchars($password, ENT_QUOTES) . "</code></td></tr>"
                  . "</table>"
                  . "<p style='color:#888;font-size:12px;margin-top:16px'>Nie udostępniaj tych danych osobom trzecim.</p>";
            try { mail_queue_add($email, $name, "Dane logowania — Mój dysk (ownCloud)", $html, '', 'owncloud', 0, '', true); }
            catch (\Throwable $e) { /* wysyłka nie może blokować operacji */ }
        }
    }

    if ($phone !== '') {
        if (!function_exists('sms_send')) { @require_once __DIR__ . '/sms.php'; }
        if (function_exists('sms_is_enabled') && sms_is_enabled()) {
            try { sms_send($phone, "{$org} - Moj dysk (ownCloud). Login: {$username}, haslo: {$password}"); }
            catch (\Throwable $e) { /* SMS opcjonalny */ }
        }
    }
}

/**
 * Tworzy samoobsługowe konto ownCloud dla kursanta (panel kursanta, zakładka „dysk").
 * Idempotentne — jeśli konto już istnieje (owncloud_username ustawiony), nie tworzy drugiego.
 * Hasło NIE jest zapisywane w bazie — tylko zwrócone do jednorazowego pokazania.
 *
 * @return array{ok:bool,msg:string,username?:string,password?:string,quota_mb?:int,url?:string}
 */
function owncloud_create_student_account(int $student_account_id): array {
    if (!owncloud_enabled() || !owncloud_admin_configured()) {
        return ['ok' => false, 'msg' => 'Integracja ownCloud nie jest skonfigurowana przez administratora.'];
    }

    $account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$student_account_id]);
    if (!$account) return ['ok' => false, 'msg' => 'Nie znaleziono konta kursanta.'];
    if (!empty($account['owncloud_username'])) {
        return ['ok' => false, 'msg' => 'Konto ownCloud już istnieje.'];
    }

    $admin_cfg = owncloud_admin_config();

    // Spójna tożsamość z kontem MS/Moodle (tenant szkoleniowy TI, jak w
    // includes/ti_online.php) — jeśli kursant ma już własne konto MS, login
    // ownCloud pochodzi z tego samego UPN (hasło i tak zostaje osobne, ownCloud
    // nie ma SSO). Jeśli w tenancie istnieje konto UTWORZONE POZA SYSTEMEM
    // (ti_ms_external_upn() — provisioning go celowo nie przejmuje, żeby nie
    // zrobić duplikatu/nadpisania), nie podszywamy się pod ten login —
    // doklejamy „.migracja", żeby jednoznacznie odróżnić konto tymczasowe od
    // loginu „naturalnego" powiązanego z prawdziwym kontem MS.
    $base = $account['login'] ?: ('kursant' . $student_account_id);
    if (!empty($account['ms_upn'])) {
        $base = strtok($account['ms_upn'], '@');
    } elseif (function_exists('ti_ms_external_upn') && ($external_upn = ti_ms_external_upn($student_account_id)) !== null) {
        $base = strtok($external_upn, '@') . '.migracja';
    }

    $username  = owncloud_unique_username($admin_cfg, (string)$base, 'kursant');
    $password  = bin2hex(random_bytes(8)) . 'Aa1!';

    $created = owncloud_create_user($admin_cfg, $username, $password);
    if (!$created['ok']) return ['ok' => false, 'msg' => $created['msg']];

    $quota_mb = (int)(owncloud_setting('student_quota_mb', '2048') ?: '2048');
    owncloud_set_quota($admin_cfg, $username, "{$quota_mb}MB"); // błąd limitu nie unieważnia utworzonego konta

    db()->prepare(
        "UPDATE k30_ti_student_accounts SET owncloud_username=?, owncloud_created_at=CURRENT_TIMESTAMP, owncloud_quota_mb=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
    )->execute([$username, $quota_mb, $student_account_id]);

    $contact = function_exists('ti_student_row') ? ti_student_row($student_account_id) : null;
    owncloud_send_credentials(
        (string)($contact['client_email'] ?? ''), (string)($contact['client_phone'] ?? ''),
        (string)($contact['client_name'] ?? $username), $username, $password, $admin_cfg['url']
    );

    return [
        'ok'       => true,
        'msg'      => 'Konto ownCloud utworzone.',
        'username' => $username,
        'password' => $password,
        'quota_mb' => $quota_mb,
        'url'      => $admin_cfg['url'],
    ];
}

/**
 * Resetuje hasło istniejącego konta ownCloud kursanta — nowe hasło jednorazowe,
 * NIE jest zapisywane (tylko zwrócone do pokazania).
 */
function owncloud_reset_student_password(int $student_account_id): array {
    if (!owncloud_enabled() || !owncloud_admin_configured()) {
        return ['ok' => false, 'msg' => 'Integracja ownCloud nie jest skonfigurowana przez administratora.'];
    }
    $account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$student_account_id]);
    if (!$account || empty($account['owncloud_username'])) {
        return ['ok' => false, 'msg' => 'Nie masz jeszcze konta ownCloud.'];
    }

    $admin_cfg = owncloud_admin_config();
    $password  = bin2hex(random_bytes(8)) . 'Aa1!';
    $r = owncloud_set_password($admin_cfg, $account['owncloud_username'], $password);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg']];

    $contact = function_exists('ti_student_row') ? ti_student_row($student_account_id) : null;
    owncloud_send_credentials(
        (string)($contact['client_email'] ?? ''), (string)($contact['client_phone'] ?? ''),
        (string)($contact['client_name'] ?? $account['owncloud_username']), $account['owncloud_username'], $password, $admin_cfg['url']
    );

    return [
        'ok'       => true,
        'msg'      => 'Hasło zresetowane.',
        'username' => $account['owncloud_username'],
        'password' => $password,
        'quota_mb' => (int)$account['owncloud_quota_mb'],
        'url'      => $admin_cfg['url'],
    ];
}

// ══ OCS PROVISIONING API — konta prowadzących (panel dydaktyka, „mój dysk") ══
//
// Sama mechanika co konta kursantów powyżej. Tożsamość dydaktyka to users.id
// (logowanie danymi SZO, [[project_ti_dydaktyk_panel]]) — konto ownCloud trzymamy
// więc per user_id w k30_ti_instructor_owncloud, niezależnie od istnienia wiersza
// w k30_ti_instructor_accounts (ten dotyczy odrębnego mechanizmu logowania).

/** Konto ownCloud prowadzącego (jeśli już utworzone). */
function owncloud_instructor_account(int $user_id): ?array {
    return db_one("SELECT * FROM k30_ti_instructor_owncloud WHERE user_id=?", [$user_id]) ?: null;
}

/**
 * Tworzy samoobsługowe konto ownCloud dla prowadzącego (panel dydaktyka, zakładka „dysk").
 * Idempotentne — jeśli konto już istnieje, nie tworzy drugiego.
 * Hasło NIE jest zapisywane w bazie — tylko zwrócone do jednorazowego pokazania.
 *
 * @return array{ok:bool,msg:string,username?:string,password?:string,quota_mb?:int,url?:string}
 */
function owncloud_create_instructor_account(int $user_id): array {
    if (!owncloud_enabled() || !owncloud_admin_configured()) {
        return ['ok' => false, 'msg' => 'Integracja ownCloud nie jest skonfigurowana przez administratora.'];
    }
    if (owncloud_instructor_account($user_id)) {
        return ['ok' => false, 'msg' => 'Konto ownCloud już istnieje.'];
    }

    $u = db_one("SELECT name, email, phone_number FROM users WHERE id=?", [$user_id]);
    if (!$u) return ['ok' => false, 'msg' => 'Nie znaleziono konta użytkownika.'];

    $admin_cfg = owncloud_admin_config();
    $base      = $u['email'] !== '' ? strtok($u['email'], '@') : $u['name'];
    $username  = owncloud_unique_username($admin_cfg, (string)$base, 'prowadzacy');
    $password  = bin2hex(random_bytes(8)) . 'Aa1!';

    $created = owncloud_create_user($admin_cfg, $username, $password);
    if (!$created['ok']) return ['ok' => false, 'msg' => $created['msg']];

    $quota_mb = (int)(owncloud_setting('instructor_quota_mb', '5120') ?: '5120');
    owncloud_set_quota($admin_cfg, $username, "{$quota_mb}MB"); // błąd limitu nie unieważnia utworzonego konta

    db()->prepare(
        "INSERT INTO k30_ti_instructor_owncloud(user_id, owncloud_username, owncloud_created_at, owncloud_quota_mb)
         VALUES(?,?,CURRENT_TIMESTAMP,?)"
    )->execute([$user_id, $username, $quota_mb]);

    owncloud_send_credentials(
        (string)$u['email'], (string)($u['phone_number'] ?? ''), (string)($u['name'] ?: $username),
        $username, $password, $admin_cfg['url']
    );

    return [
        'ok'       => true,
        'msg'      => 'Konto ownCloud utworzone.',
        'username' => $username,
        'password' => $password,
        'quota_mb' => $quota_mb,
        'url'      => $admin_cfg['url'],
    ];
}

/**
 * Resetuje hasło istniejącego konta ownCloud prowadzącego — nowe hasło jednorazowe,
 * NIE jest zapisywane (tylko zwrócone do pokazania).
 */
function owncloud_reset_instructor_password(int $user_id): array {
    if (!owncloud_enabled() || !owncloud_admin_configured()) {
        return ['ok' => false, 'msg' => 'Integracja ownCloud nie jest skonfigurowana przez administratora.'];
    }
    $account = owncloud_instructor_account($user_id);
    if (!$account) return ['ok' => false, 'msg' => 'Nie masz jeszcze konta ownCloud.'];

    $admin_cfg = owncloud_admin_config();
    $password  = bin2hex(random_bytes(8)) . 'Aa1!';
    $r = owncloud_set_password($admin_cfg, $account['owncloud_username'], $password);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg']];

    $u = db_one("SELECT name, email, phone_number FROM users WHERE id=?", [$user_id]);
    owncloud_send_credentials(
        (string)($u['email'] ?? ''), (string)($u['phone_number'] ?? ''), (string)($u['name'] ?? $account['owncloud_username']),
        $account['owncloud_username'], $password, $admin_cfg['url']
    );

    return [
        'ok'       => true,
        'msg'      => 'Hasło zresetowane.',
        'username' => $account['owncloud_username'],
        'password' => $password,
        'quota_mb' => (int)$account['owncloud_quota_mb'],
        'url'      => $admin_cfg['url'],
    ];
}

/**
 * Lista plików/folderów w katalogu prowadzącego przez konto admina (WebDAV PROPFIND).
 * Zwraca tablicę: [name, is_dir, size, mime, modified, path]
 */
function owncloud_admin_list_files(string $username, string $path = '/'): array {
    if (!owncloud_admin_configured() || $username === '') return [];
    $admin = owncloud_admin_config();
    $segs  = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
    $enc   = implode('/', array_map('rawurlencode', $segs));
    $dav   = rtrim($admin['url'], '/') . '/remote.php/dav/files/'
           . rawurlencode($username) . '/' . ($enc !== '' ? $enc . '/' : '');
    $body  = '<?xml version="1.0" encoding="UTF-8"?>'
           . '<d:propfind xmlns:d="DAV:"><d:prop>'
           . '<d:displayname/><d:getcontentlength/><d:getlastmodified/><d:resourcetype/><d:getcontenttype/>'
           . '</d:prop></d:propfind>';
    $cfg = ['url' => $admin['url'], 'username' => $admin['username'], 'password' => $admin['password']];
    $r   = owncloud_request($cfg, 'PROPFIND', $dav, $body, ['Depth: 1', 'Content-Type: application/xml']);
    if ($r['http'] !== 207) return [];
    return _owncloud_parse_propfind($r['body'], $username, $path);
}

function _owncloud_parse_propfind(string $xml, string $username, string $parent_path = '/'): array {
    if ($xml === '') return [];
    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if (!$doc) return [];

    $doc->registerXPathNamespace('d', 'DAV:');
    $responses = $doc->xpath('//d:response') ?: [];
    $base_pfx  = '/remote.php/dav/files/' . rawurlencode($username) . '/';
    $par_segs  = array_values(array_filter(explode('/', trim($parent_path, '/')), fn($s) => $s !== ''));
    $items = [];

    foreach ($responses as $resp) {
        $resp->registerXPathNamespace('d', 'DAV:');
        $href_n = $resp->xpath('d:href');
        if (!$href_n) continue;
        $href = (string)$href_n[0];
        $href_path = (strpos($href, 'http') === 0) ? (parse_url($href, PHP_URL_PATH) ?: '') : $href;
        if (strpos($href_path, $base_pfx) !== 0) continue;
        $rel  = rawurldecode(rtrim(substr($href_path, strlen($base_pfx)), '/'));
        $segs = array_values(array_filter(explode('/', $rel), fn($s) => $s !== ''));
        if (count($segs) !== count($par_segs) + 1) continue;

        $prop = $resp->xpath('d:propstat/d:prop');
        if (!$prop) continue;
        $p = $prop[0];
        $p->registerXPathNamespace('d', 'DAV:');
        $is_dir   = !empty($p->xpath('d:resourcetype/d:collection'));
        $size     = (int)(string)($p->xpath('d:getcontentlength')[0] ?? '0');
        $mime     = (string)($p->xpath('d:getcontenttype')[0] ?? '');
        $modified = (string)($p->xpath('d:getlastmodified')[0] ?? '');
        $name     = basename($rel);
        $path_out = '/' . $rel . ($is_dir ? '/' : '');
        $items[]  = ['name'=>$name,'is_dir'=>$is_dir,'size'=>$size,'mime'=>$mime,'modified'=>$modified,'path'=>$path_out];
    }

    usort($items, fn($a, $b) => ($b['is_dir'] <=> $a['is_dir']) ?: strcmp($a['name'], $b['name']));
    return $items;
}

/**
 * Importuje plik z konta prowadzącego (przez admina WebDAV) do magazynu TI.
 * Zwraca ['name'=>..., 'stored'=>...] lub null przy błędzie.
 */
function owncloud_admin_import_file(string $username, string $path, string $prefix = 'mat'): ?array {
    if (!owncloud_admin_configured() || $username === '') return null;
    $admin    = owncloud_admin_config();
    $segs     = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
    $enc_path = implode('/', array_map('rawurlencode', $segs));
    $dav_url  = rtrim($admin['url'], '/') . '/remote.php/dav/files/'
              . rawurlencode($username) . '/' . $enc_path;
    $cfg = ['url' => $admin['url'], 'username' => $admin['username'], 'password' => $admin['password']];
    $r   = owncloud_request($cfg, 'GET', $dav_url, '');
    if (!$r['ok'] || $r['body'] === '') return null;

    $orig_name = basename($path);
    $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed   = function_exists('k30_ti_homework_allowed_ext') ? k30_ti_homework_allowed_ext()
               : ['pdf','doc','docx','odt','rtf','txt','xls','xlsx','ods','csv','ppt','pptx','odp',
                  'png','jpg','jpeg','gif','webp','bmp','svg','zip','7z','rar','gz',
                  'py','java','c','cpp','cs','js','ts','html','css','sql','json','ipynb','md'];
    if ($ext === '' || !in_array($ext, $allowed, true)) return null;

    $stamp  = date('Ymd_His');
    $suffix = bin2hex(random_bytes(4));
    $stored = "{$prefix}_{$stamp}_{$suffix}.{$ext}";
    $dir    = rtrim(UPLOAD_DIR, '/') . '/ti_homework/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (file_put_contents($dir . $stored, $r['body']) === false) return null;

    if (owncloud_enabled()) {
        owncloud_put('ti_homework/' . $stored, $dir . $stored);
    }
    return ['name' => $orig_name, 'stored' => $stored];
}
