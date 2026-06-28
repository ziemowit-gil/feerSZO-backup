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
