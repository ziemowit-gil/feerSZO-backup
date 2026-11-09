<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjePanelAuth.php — logowanie do OSOBNEGO panelu zarządzania licencjami (kursant.feer.org.pl/licencje),
 * wzorem Portalu Kontrahenta: własna, izolowana sesja (szo_licencje), login „l” + 8 cyfr i hasło (modules/license_login).
 * Uczestnik zalogowany w panelu TI wchodzi bez ponownego logowania (przejęcie tożsamości z sesji k30_student przy wejściu).
 * Konto w panelu licencji zakłada kadra w panelu dydaktyka (Płatne licencje › Konto w panelu licencji).
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
