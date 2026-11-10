<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjePanelAuth.php — logowanie do Panelu Licencji (kursant.feer.org.pl/licencje/), niezależnego od panelu
 * uczestnika TI (polecenie 2026-11-10): własne wejście /licencje/index.php, własna sesja szo_licencje, login „l” + 8 cyfr i hasło (modules/license_login).
 * Panel nie czyta sesji panelu TI. Przejście z panelu uczestnika TI idzie przez jednorazowy link (?wejscie=…, ważny 2 minuty, handoffCreate/handoffConsume).
 * Dane logowania (login + hasło, e-mail i SMS) wysyła kadra z panelu Płatne licencje › Uczestnicy (LicencjePanelAccess).
 */
final class LicencjePanelAuth
{
    private const KEY = 'lp_user';
    private const TTL = 7200;   // 2 h bezczynności

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() === 'szo_licencje') return;
            session_write_close();
        }
        session_name('szo_licencje');
        // Po sesji panelu TI (k30_student) PHP pamięta jej identyfikator i session_start() użyłby go zamiast ciasteczka szo_licencje —
        // panel licencji pisałby do pliku sesji TI, a po zalogowaniu loginem „l…” następne wejście trafiało znowu na logowanie. Własny identyfikator:
        $own = (string)($_COOKIE['szo_licencje'] ?? '');
        session_id(preg_match('/^[A-Za-z0-9,-]{22,256}$/', $own) ? $own : session_create_id());
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                                   'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        session_start();
    }

    /** Zalogowana osoba: ['cid' => klient, 'via' => skąd, 'sid' => konto TI albo null] albo null. */
    public static function user(): ?array
    {
        self::start();
        $u = $_SESSION[self::KEY] ?? null;
        if (!$u || time() - (int)($u['ts'] ?? 0) > self::TTL) { unset($_SESSION[self::KEY]); return null; }
        $_SESSION[self::KEY]['ts'] = time();
        return $_SESSION[self::KEY];
    }

    public static function login(int $clientId, string $via, ?int $studentAccountId = null): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION[self::KEY] = ['cid' => $clientId, 'via' => $via, 'sid' => $studentAccountId, 'ts' => time()];
        $_SESSION['lp_csrf'] = bin2hex(random_bytes(16));
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION[self::KEY], $_SESSION['lp_csrf']);
        session_regenerate_id(true);
    }

    public static function token(): string
    {
        self::start();
        return $_SESSION['lp_csrf'] ??= bin2hex(random_bytes(16));
    }

    public static function check(): void
    {
        self::start();
        if (!hash_equals((string)($_SESSION['lp_csrf'] ?? ''), (string)($_POST['_token'] ?? ''))) {
            http_response_code(403);
            exit('Sesja wygasła — odśwież stronę i spróbuj ponownie.');
        }
    }

    // ── Adres panelu i przejście z panelu uczestnika TI ─────────────────────────

    /** Ścieżka panelu na tym hoście (zawsze /licencje/ — katalog licencje/ w korzeniu, na ti*. i kurs*. wyłączony z przepisywania na panel kursanta). */
    public const PATH = '/licencje/';

    /** Adres panelu do e-maili i SMS-ów: ustawienie licencje_panel_url albo https://kursant.feer.org.pl/licencje/ (lokalnie: APP_URL). */
    public static function publicUrl(): string
    {
        $u = trim((string)(function_exists('org_setting') ? org_setting('licencje_panel_url') : ''));
        if (preg_match('#^https://#i', $u)) return rtrim($u, '/') . '/';
        $app = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $app)) return $app . self::PATH;
        return 'https://kursant.feer.org.pl' . self::PATH;
    }

    private static function handoffMigrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS license_panel_handoffs (
            token_hash TEXT PRIMARY KEY, client_id INTEGER NOT NULL, student_id INTEGER, expires_at DATETIME NOT NULL, used_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        // wejście administratora (rola SZO „Administrator Licencji”): kind='admin', user_id / user_name z SZO
        foreach (["kind TEXT NOT NULL DEFAULT 'ti'", "user_id INTEGER", "user_name TEXT NOT NULL DEFAULT ''", "via TEXT NOT NULL DEFAULT ''"] as $col) {
            try { db()->exec("ALTER TABLE license_panel_handoffs ADD COLUMN $col"); } catch (\Throwable $e) {}
        }
    }

    /** Jednorazowy link wejścia z panelu uczestnika TI (2 minuty). Zwraca token do ?wejscie=. */
    public static function handoffCreate(int $clientId, ?int $studentId): string
    {
        self::handoffMigrate();
        try { db_exec("DELETE FROM license_panel_handoffs WHERE expires_at < datetime('now','-1 day')"); } catch (\Throwable $e) {}
        $t = bin2hex(random_bytes(24));
        db_insert('license_panel_handoffs', ['token_hash' => hash('sha256', $t), 'client_id' => $clientId, 'student_id' => $studentId, 'expires_at' => date('Y-m-d H:i:s', time() + 120)]);
        return $t;
    }

    /** Zużywa link wejścia i loguje osobę w panelu licencji. false — link zły, zużyty albo przeterminowany. */
    public static function handoffConsume(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return false;
        self::handoffMigrate();
        $h = hash('sha256', $token);
        $r = db_one("SELECT * FROM license_panel_handoffs WHERE token_hash=? AND used_at IS NULL AND expires_at >= ? AND kind='ti'", [$h, date('Y-m-d H:i:s')]);
        if (!$r) return false;
        $st = db()->prepare("UPDATE license_panel_handoffs SET used_at=datetime('now') WHERE token_hash=? AND used_at IS NULL");
        $st->execute([$h]);
        if ($st->rowCount() !== 1) return false;   // równoległe użycie tego samego linku
        self::login((int)$r['client_id'], 'panel uczestnika TI', $r['student_id'] !== null ? (int)$r['student_id'] : null);
        return true;
    }

    // ── Administrator Licencji: logowanie przez SZO albo Microsoft 365 (polecenie 2026-11-10) ──
    // licencje/sso.php działa na hoście SZO (APP_URL): wymaga logowania SZO (z jego 2FA i kluczami; Microsoft 365 przez auth/ms365.php),
    // sprawdza uprawnienie „licencje_admin” (albo rolę admin) i wraca do panelu jednorazowym linkiem ?wejscie_admin=… (2 minuty).

    private const ADMIN_KEY = 'lp_admin';

    /** Adres startu logowania administratora: $via = 'szo' | 'ms365'. */
    public static function adminLoginUrl(string $via): string
    {
        $app = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $sso = $app . self::PATH . 'sso.php';
        return $via === 'ms365' ? $app . '/auth/ms365.php?redirect=' . rawurlencode($sso . '?via=ms365') : $sso . '?via=szo';
    }

    public static function adminHandoffCreate(int $userId, string $name, string $via): string
    {
        self::handoffMigrate();
        $t = bin2hex(random_bytes(24));
        db_insert('license_panel_handoffs', ['token_hash' => hash('sha256', $t), 'client_id' => 0, 'kind' => 'admin', 'user_id' => $userId, 'user_name' => mb_substr($name, 0, 150),
                                             'via' => $via, 'expires_at' => date('Y-m-d H:i:s', time() + 120)]);
        return $t;
    }

    public static function adminHandoffConsume(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return false;
        self::handoffMigrate();
        $h = hash('sha256', $token);
        $r = db_one("SELECT * FROM license_panel_handoffs WHERE token_hash=? AND used_at IS NULL AND expires_at >= ? AND kind='admin'", [$h, date('Y-m-d H:i:s')]);
        if (!$r) return false;
        $st = db()->prepare("UPDATE license_panel_handoffs SET used_at=datetime('now') WHERE token_hash=? AND used_at IS NULL");
        $st->execute([$h]);
        if ($st->rowCount() !== 1) return false;
        self::start();
        session_regenerate_id(true);
        $_SESSION[self::ADMIN_KEY] = ['uid' => (int)$r['user_id'], 'name' => (string)$r['user_name'], 'via' => (string)$r['via'], 'ts' => time()];
        $_SESSION['lp_csrf'] ??= bin2hex(random_bytes(16));
        return true;
    }

    /** Zalogowany Administrator Licencji: ['uid','name','via'] albo null (2 h bezczynności). */
    public static function admin(): ?array
    {
        self::start();
        $a = $_SESSION[self::ADMIN_KEY] ?? null;
        if (!$a || time() - (int)($a['ts'] ?? 0) > self::TTL) { unset($_SESSION[self::ADMIN_KEY]); return null; }
        $_SESSION[self::ADMIN_KEY]['ts'] = time();
        return $_SESSION[self::ADMIN_KEY];
    }

    public static function adminLogout(): void
    {
        self::start();
        unset($_SESSION[self::ADMIN_KEY]);
        session_regenerate_id(true);
    }

    /** Limit prób jak przy logowaniu TI: działa tylko przy ustawieniu ti_login_throttle = 1 (domyślnie wyłączony). */
    public static function throttled(string $login): bool
    {
        if (trim((string)org_setting('ti_login_throttle')) !== '1') return false;
        try {
            $n = db_one("SELECT COUNT(*) n FROM login_attempts WHERE identifier=? AND created_at > ?", ['lic:' . strtolower(trim($login)), date('Y-m-d H:i:s', time() - 900)]);
            return (int)($n['n'] ?? 0) >= 5;
        } catch (\Throwable $e) { return false; }
    }

    public static function recordFail(string $login): void
    {
        try { db()->prepare("INSERT INTO login_attempts (identifier, ip, created_at) VALUES (?, ?, ?)")->execute(['lic:' . strtolower(trim($login)), (string)($_SERVER['REMOTE_ADDR'] ?? ''), date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
    }
}
