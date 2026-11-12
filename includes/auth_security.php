<?php
/**
 * auth_security.php — Bezpieczeństwo logowania:
 *  - Blokada konta po nieudanych próbach (brute-force)
 *  - Historia logowań (audyt/RODO)
 *  - Wymuś zmianę hasła po resecie przez admina
 *  - Aktywne sesje (przegląd + unieważnianie)
 */

function _auth_security_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // Tabela prób logowania (brute-force)
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier TEXT NOT NULL,
        ip         TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (\Throwable $e) { error_log('[auth_security] login_attempts: ' . $e->getMessage()); }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attempts_ident ON login_attempts(identifier, created_at)");
    } catch (\Throwable $e) {}

    // Tabela historii logowań
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS login_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER DEFAULT NULL,
        email      TEXT NOT NULL DEFAULT '',
        ip         TEXT NOT NULL DEFAULT '',
        user_agent TEXT NOT NULL DEFAULT '',
        action     TEXT NOT NULL DEFAULT 'login',
        detail     TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (\Throwable $e) { error_log('[auth_security] login_log: ' . $e->getMessage()); }

    // Aktywne sesje
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL,
        token       TEXT NOT NULL UNIQUE,
        ip          TEXT NOT NULL DEFAULT '',
        user_agent  TEXT NOT NULL DEFAULT '',
        last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (\Throwable $e) { error_log('[auth_security] user_sessions: ' . $e->getMessage()); }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id)");
    } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_token ON user_sessions(token)");
    } catch (\Throwable $e) {}

    // Kolumna must_change_password w users
    try { $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0"); }
    catch (\Throwable $e) {}

    // Kolumna locked_until w users
    try { $pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL"); }
    catch (\Throwable $e) {}

    // Kolumna allow_local_fallback w users — konto @feer.org.pl, które ustawiło
    // hasło awaryjne przez /auth/convert_account.php, może logować się lokalnie
    // mimo polityki „tylko Office".
    try { $pdo->exec("ALTER TABLE users ADD COLUMN allow_local_fallback INTEGER NOT NULL DEFAULT 0"); }
    catch (\Throwable $e) {}

    // Kolumna guardian_user_id w users — twarde powiązanie konta niepełnoletniego
    // wolontariusza (dziecko) z kontem opiekuna (rodzic). Pozwala rodzicowi wejść
    // w kontekst dziecka (zob. includes/context.php → ctx_enter_child).
    try { $pdo->exec("ALTER TABLE users ADD COLUMN guardian_user_id INTEGER NULL"); }
    catch (\Throwable $e) {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function _auth_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function _auth_ua(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
}

// ── Brute-force ───────────────────────────────────────────────────────────────

const BRUTE_MAX_ATTEMPTS = 5;      // prób zanim blokada
const BRUTE_WINDOW_SEC   = 900;    // okno zliczania (15 min)
const BRUTE_LOCK_SEC     = 900;    // czas blokady (15 min)

/**
 * Sprawdza czy dany email lub IP jest zablokowany.
 * Zwraca null (OK) lub liczbę sekund pozostałych do odblokowania.
 */
// ── Kod rejestracji (PIN od administratora) ──────────────────────────────────
// Samodzielna rejestracja (/user/register.php) wymaga 6-cyfrowego PIN-u, który
// administrator ustawia w „Metody logowania". Bez PIN-u rejestracja jest
// zamknięta — nikt postronny nie założy konta, nawet znając dane z umowy.

/** Zwraca ustawiony PIN rejestracji ('' = brak). */
function register_pin_get(): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['register_pin']);
        return trim((string)($r['value'] ?? ''));
    } catch (\Throwable $e) { return ''; }
}

/** Zapisuje PIN rejestracji ('' kasuje = zamyka rejestrację). */
function register_pin_set(string $pin): void {
    $pin = preg_replace('/\D/', '', $pin) ?? '';
    db()->prepare("INSERT INTO settings (key_, value) VALUES ('register_pin', ?)
                   ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$pin]);
}

/** Losuje nowy 6-cyfrowy PIN i zapisuje go. Zwraca wylosowaną wartość. */
function register_pin_generate(): string {
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    register_pin_set($pin);
    return $pin;
}

/** Czy administrator w ogóle dopuszcza samodzielną rejestrację. */
function register_enabled(): bool {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['register_enabled']);
        return $r === null ? true : (bool)$r['value'];
    } catch (\Throwable $e) { return true; }
}

/** Rejestracja działa tylko, gdy jest włączona I ma ustawiony 6-cyfrowy PIN. */
function register_is_open(): bool {
    return register_enabled() && preg_match('/^\d{6}$/', register_pin_get()) === 1;
}

/** Porównanie PIN-u odporne na pomiar czasu. */
function register_pin_check(string $input): bool {
    $pin = register_pin_get();
    if ($pin === '') return false;
    return hash_equals($pin, trim(preg_replace('/\D/', '', $input) ?? ''));
}

function brute_check(string $email): ?int {
    _auth_security_init();
    $ip = _auth_ip();

    // Blokada na poziomie konta (locked_until w users)
    $user = db_one("SELECT locked_until FROM users WHERE email=?", [$email]);
    if ($user && $user['locked_until']) {
        $remaining = strtotime($user['locked_until']) - time();
        if ($remaining > 0) return $remaining;
        // odblokuj automatycznie
        db()->prepare("UPDATE users SET locked_until=NULL WHERE email=?")->execute([$email]);
    }

    // Blokada na poziomie IP (zbyt wiele prób z jednego IP)
    $since = date('Y-m-d H:i:s', time() - BRUTE_WINDOW_SEC);
    $ip_count = db_one(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at > ?",
        [$ip, $since]
    );
    if ((int)($ip_count['c'] ?? 0) >= BRUTE_MAX_ATTEMPTS * 3) {
        return BRUTE_LOCK_SEC;
    }

    return null;
}

/**
 * Rejestruje nieudaną próbę. Jeśli przekroczono limit — blokuje konto.
 */
function brute_record_fail(string $email): void {
    _auth_security_init();
    $ip    = _auth_ip();
    $since = date('Y-m-d H:i:s', time() - BRUTE_WINDOW_SEC);

    // Zapisz próbę
    db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)")
        ->execute([$email, $ip]);
    db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)")
        ->execute([$ip, $ip]);

    // Zlicz próby dla emaila
    $cnt = db_one(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at > ?",
        [$email, $since]
    );
    if ((int)($cnt['c'] ?? 0) >= BRUTE_MAX_ATTEMPTS) {
        $until = date('Y-m-d H:i:s', time() + BRUTE_LOCK_SEC);
        db()->prepare("UPDATE users SET locked_until=? WHERE email=?")->execute([$until, $email]);
    }
}

/**
 * Czyści próby po udanym logowaniu.
 */
function brute_clear(string $email): void {
    _auth_security_init();
    $ip = _auth_ip();
    db()->prepare("DELETE FROM login_attempts WHERE identifier=? OR identifier=?")
        ->execute([$email, $ip]);
    db()->prepare("UPDATE users SET locked_until=NULL WHERE email=?")->execute([$email]);
}

// ── Historia logowań ──────────────────────────────────────────────────────────

function authlog_write(?int $user_id, string $action, string $email = '', string $detail = ''): void {
    _auth_security_init();
    try {
        db()->prepare(
            "INSERT INTO login_log (user_id, email, ip, user_agent, action, detail)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$user_id, $email, _auth_ip(), _auth_ua(), $action, $detail]);
    } catch (\Throwable $e) {}
    // Powiadomienia bezpieczeństwa (modules/account_security): zmiana hasła, telefonu… — nigdy nie przerywa zapisu dziennika
    if ($user_id) {
        try {
            require_once dirname(__DIR__) . '/modules/account_security/lib/accountSecurityNotify.php';
            accountSecurityNotifyFromLog($user_id, $action, $user_id);
        } catch (\Throwable $e) {}
    }
}

function authlog_user(int $user_id, int $limit = 50): array {
    _auth_security_init();
    return db_all(
        "SELECT * FROM login_log WHERE user_id=? ORDER BY created_at DESC LIMIT ?",
        [$user_id, $limit]
    );
}

function authlog_all(int $limit = 200): array {
    _auth_security_init();
    return db_all(
        "SELECT l.*, u.name AS user_name FROM login_log l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC LIMIT ?",
        [$limit]
    );
}

// ── Wymuś zmianę hasła ────────────────────────────────────────────────────────

function auth_force_password_change(int $user_id): void {
    _auth_security_init();
    db()->prepare("UPDATE users SET must_change_password=1 WHERE id=?")->execute([$user_id]);
}

function auth_clear_force_password(int $user_id): void {
    _auth_security_init();
    db()->prepare("UPDATE users SET must_change_password=0 WHERE id=?")->execute([$user_id]);
}

function auth_must_change_password(?array $user = null): bool {
    _auth_security_init();
    $user = $user ?? current_user();
    if (!$user) return false;
    $row = db_one("SELECT must_change_password FROM users WHERE id=?", [(int)$user['id']]);
    return (bool)($row['must_change_password'] ?? false);
}

// ── Aktywne sesje ─────────────────────────────────────────────────────────────

function session_create_token(int $user_id): string {
    _auth_security_init();
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        "INSERT INTO user_sessions (user_id, token, ip, user_agent)
         VALUES (?, ?, ?, ?)"
    )->execute([$user_id, $token, _auth_ip(), _auth_ua()]);
    // Usuń sesje starsze niż 30 dni
    db()->prepare(
        "DELETE FROM user_sessions WHERE user_id=? AND last_active < datetime('now', '-30 days')"
    )->execute([$user_id]);
    return $token;
}

function session_touch(string $token): void {
    _auth_security_init();
    db()->prepare("UPDATE user_sessions SET last_active=datetime('now') WHERE token=?")
        ->execute([$token]);
}

function session_destroy_token(string $token): void {
    _auth_security_init();
    db()->prepare("DELETE FROM user_sessions WHERE token=?")->execute([$token]);
}

function session_destroy_all(int $user_id, string $except_token = ''): void {
    _auth_security_init();
    if ($except_token) {
        db()->prepare("DELETE FROM user_sessions WHERE user_id=? AND token!=?")
            ->execute([$user_id, $except_token]);
    } else {
        db()->prepare("DELETE FROM user_sessions WHERE user_id=?")->execute([$user_id]);
    }
}

function sessions_for_user(int $user_id): array {
    _auth_security_init();
    return db_all(
        "SELECT * FROM user_sessions WHERE user_id=? ORDER BY last_active DESC",
        [$user_id]
    );
}

/** Liczba aktywnych sesji użytkownika. */
function sessions_count_for_user(int $user_id): int {
    _auth_security_init();
    $r = db_one("SELECT COUNT(*) AS c FROM user_sessions WHERE user_id=?", [$user_id]);
    return (int)($r['c'] ?? 0);
}

/**
 * Czy token sesji nadal istnieje w rejestrze (nie został zdalnie unieważniony)?
 * Pusty token = sesja sprzed wdrożenia mechanizmu — nie wymuszamy wylogowania.
 */
function session_token_alive(string $token): bool {
    if ($token === '') return true;
    _auth_security_init();
    return db_one("SELECT 1 AS x FROM user_sessions WHERE token=?", [$token]) !== null;
}

// ── UA parser — czytelna etykieta urządzenia/przeglądarki ────────────────────

function ua_label(string $ua): string {
    $browser = 'Przeglądarka';
    if (str_contains($ua, 'Firefox'))       $browser = 'Firefox';
    elseif (str_contains($ua, 'Edg'))       $browser = 'Edge';
    elseif (str_contains($ua, 'Chrome'))    $browser = 'Chrome';
    elseif (str_contains($ua, 'Safari'))    $browser = 'Safari';
    elseif (str_contains($ua, 'curl'))      $browser = 'curl';

    $os = '';
    if (str_contains($ua, 'Windows'))       $os = 'Windows';
    elseif (str_contains($ua, 'Mac OS'))    $os = 'macOS';
    elseif (str_contains($ua, 'Linux'))     $os = 'Linux';
    elseif (str_contains($ua, 'Android'))   $os = 'Android';
    elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';

    return $os ? "$browser / $os" : $browser;
}

function authlog_action_label(string $action): array {
    return match($action) {
        'login'         => ['Logowanie',            'success', 'bi-box-arrow-in-right'],
        'logout'        => ['Wylogowanie',           'secondary','bi-box-arrow-right'],
        'login_fail'    => ['Błędne hasło',          'danger',  'bi-x-circle'],
        'login_blocked' => ['Zablokowany dostęp',   'danger',  'bi-shield-x'],
        'login_2fa'     => ['Logowanie 2FA',         'success', 'bi-shield-check'],
        'login_sms'     => ['Logowanie SMS',         'success', 'bi-phone'],
        'login_code'    => ['Logowanie kodem',       'success', 'bi-key'],
        'login_ms'      => ['Logowanie Microsoft',   'primary', 'bi-microsoft'],
        'login_x509'    => ['Logowanie X.509',       'success', 'bi-patch-check-fill'],
        'pwd_changed'   => ['Zmiana hasła',          'warning', 'bi-key-fill'],
        default         => [$action,                 'light',   'bi-circle'],
    };
}

// ── Pierwsze logowanie z nowego urządzenia / adresu IP ───────────────────────
// Po zalogowaniu (login_user) sprawdzamy, czy konto logowało się już z tego adresu IP i z tego urządzenia
// (ciasteczko „szo_dev”). Nowy adres lub urządzenie → sesja dostaje flagę _new_login_pending, a require_login()
// przekierowuje na /auth/nowe_logowanie.php („To ja” / „To nie ja”). Potwierdzenie zapisuje IP i urządzenie jako znane.

function _nl_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS trusted_logins (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            kind       TEXT NOT NULL,              -- ip | device
            value      TEXT NOT NULL,              -- adres IP albo sha256(token urządzenia)
            label      TEXT NOT NULL DEFAULT '',
            geo        TEXT NOT NULL DEFAULT '',
            first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, kind, value)
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
            ip         TEXT PRIMARY KEY,
            country    TEXT NOT NULL DEFAULT '',
            region     TEXT NOT NULL DEFAULT '',
            city       TEXT NOT NULL DEFAULT '',
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) { error_log('[auth_security] trusted_logins: ' . $e->getMessage()); }
}

/** Lokalizacja adresu IP (serwis ipwho.is po HTTPS, wynik w cache — jedno zapytanie na adres). Adresy prywatne → null. */
function auth_ip_geo(string $ip): ?array {
    _nl_init();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    $c = db_one("SELECT * FROM ip_geo_cache WHERE ip=?", [$ip]);
    if ($c && ($c['country'] !== '' || strtotime((string)$c['fetched_at']) > time() - 86400)) {
        return $c['country'] !== '' ? ['country' => $c['country'], 'region' => $c['region'], 'city' => $c['city']] : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,region,city', false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    $row = (is_array($j) && !empty($j['success']))
        ? ['country' => (string)($j['country'] ?? ''), 'region' => (string)($j['region'] ?? ''), 'city' => (string)($j['city'] ?? '')]
        : ['country' => '', 'region' => '', 'city' => ''];
    try {
        db()->prepare("INSERT OR REPLACE INTO ip_geo_cache (ip, country, region, city, fetched_at) VALUES (?,?,?,?,?)")
            ->execute([$ip, $row['country'], $row['region'], $row['city'], date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {}
    return $row['country'] !== '' ? $row : null;
}

function auth_geo_label(?array $g): string {
    if (!$g) return 'nieznana lokalizacja';
    return implode(', ', array_filter([$g['city'] ?? '', $g['region'] ?? '', $g['country'] ?? ''], fn($x) => $x !== ''));
}

/** Krótki opis urządzenia z User-Agenta (przeglądarka + system). */
function auth_device_label(string $ua): string {
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
       : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'przeglądarka'))));
    $o = preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Android/', $ua) ? 'Android'
       : (preg_match('/Mac OS X/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : 'nieznany system'))));
    return $b . ' · ' . $o;
}

/** Wywoływane z login_user(): ustawia $_SESSION['_new_login_pending'], gdy IP lub urządzenie są nowe dla konta. */
function auth_new_login_check(int $uid, string $email = ''): void {
    _nl_init();
    unset($_SESSION['_new_login_pending']);
    if ($email === 'serwis@local') return;                 // break-glass nigdy nie jest blokowane
    $ip  = _auth_ip();
    $ua  = _auth_ua();
    // Urządzenie rozpoznajemy po losowym ciasteczku (zapisywane w DB jako skrót)
    $tok = (string)($_COOKIE['szo_dev'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) $tok = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        setcookie('szo_dev', $tok, ['expires' => time() + 86400 * 400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    }
    $dev = hash('sha256', $tok);

    $hasTrusted = (bool)db_one("SELECT 1 FROM trusted_logins WHERE user_id=? LIMIT 1", [$uid]);
    $histIp = (bool)db_one(
        "SELECT 1 FROM login_log WHERE user_id=? AND ip=? AND action LIKE 'login%' AND action NOT LIKE 'login_blocked%' AND action NOT LIKE 'login_fail%' LIMIT 1", [$uid, $ip]);
    $anyHist = (bool)db_one(
        "SELECT 1 FROM login_log WHERE user_id=? AND action LIKE 'login%' AND action NOT LIKE 'login_blocked%' AND action NOT LIKE 'login_fail%' LIMIT 1", [$uid]);
    $ipKnown  = $histIp || (bool)db_one("SELECT 1 FROM trusted_logins WHERE user_id=? AND kind='ip' AND value=?", [$uid, $ip]);
    $devKnown = (bool)db_one("SELECT 1 FROM trusted_logins WHERE user_id=? AND kind='device' AND value=?", [$uid, $dev]);
    $hasDev   = (bool)db_one("SELECT 1 FROM trusted_logins WHERE user_id=? AND kind='device' LIMIT 1", [$uid]);

    // Konto bez żadnej historii (pierwsze logowanie w ogóle) → zaufaj od razu. Urządzenie sprawdzamy dopiero, gdy konto
    // ma już jakieś zaufane urządzenie (przy wdrożeniu nikt nie dostaje komunikatu tylko dlatego, że brak ciasteczka).
    $isNew = ($hasTrusted || $anyHist) && (!$ipKnown || ($hasDev && !$devKnown));
    $pend = ['ip' => $ip, 'ua' => $ua, 'dev' => $dev, 'at' => date('Y-m-d H:i:s'), 'new_ip' => !$ipKnown, 'new_dev' => ($hasDev && !$devKnown)];
    if ($isNew) {
        $_SESSION['_new_login_pending'] = $pend;
        // Powiadomienie właściciela konta poza ekranem potwierdzenia (który widzi ten, kto się loguje) — modules/account_security
        try {
            require_once dirname(__DIR__) . '/modules/account_security/lib/accountSecurityNotify.php';
            accountSecurityNotify($uid, 'new_login', ['ip' => $ip, 'ua' => $ua, 'new_dev' => $pend['new_dev']]);
        } catch (\Throwable $e) {}
        return;
    }
    auth_trust_login($uid, $pend);
}

/** Zapisuje IP i urządzenie jako znane dla konta. */
function auth_trust_login(int $uid, array $p): void {
    _nl_init();
    $geo = auth_geo_label(auth_ip_geo((string)$p['ip']));
    $st = db()->prepare("INSERT INTO trusted_logins (user_id, kind, value, label, geo) VALUES (?,?,?,?,?)
                         ON CONFLICT(user_id, kind, value) DO UPDATE SET last_seen=CURRENT_TIMESTAMP");
    $st->execute([$uid, 'ip', (string)$p['ip'], '', $geo]);
    $st->execute([$uid, 'device', (string)$p['dev'], auth_device_label((string)$p['ua']), $geo]);
}

/** Bramka: sesja czekająca na potwierdzenie nowego logowania nie wejdzie dalej niż na stronę potwierdzenia. */
function auth_new_login_gate(): void {
    if (empty($_SESSION['_new_login_pending'])) return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['nowe_logowanie.php', 'logout.php'], true)) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        http_response_code(403);
        exit('Potwierdź nowe logowanie na stronie ' . APP_URL . '/auth/nowe_logowanie.php');
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base . '/')) $uri = substr($uri, strlen($base));
    header('Location: ' . APP_URL . '/auth/nowe_logowanie.php?redirect=' . urlencode(APP_URL . $uri));
    exit;
}
