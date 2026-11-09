<?php
declare(strict_types=1);

/**
 * modules/license_login/logic/licenseLogin.php — login do licencji uczestnika w formacie „l” + 8 cyfr (np. l40381927), polecenie 2026-11-09.
 *
 * Uczestnik generuje go raz w panelu /licencje (jeden na osobę, nie zmienia się) — razem z hasłem: to login do OSOBNEGO panelu
 * zarządzania licencjami (kursant.feer.org.pl/licencje, własna sesja; polecenie 2026-11-09). Używają go też:
 *   • konta Office w sprawach AM7 (Am7TenantAccount::create — zamiast loginu z imienia i nazwiska, gdy wolny w tenancie),
 *   • zgłoszenia Helpdesk o włączenie Claude / NordVPN (proponowany login konta),
 *   • wniosek ANT1 (adres e-mail w domenie edu.feer.org.pl — podpowiedź).
 * Unikalność: tabela license_logins (UNIQUE) + loginy kont uczestników i licencji w SZO. Losowanie z random_int (bez kolejnych numerów).
 */
final class LicenseLogin
{
    public const PATTERN = '/^l\d{8}$/';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('license_login_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS license_logins (
            client_id INTEGER PRIMARY KEY,
            login TEXT NOT NULL UNIQUE,
            created_by TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        foreach (["password_hash TEXT NOT NULL DEFAULT ''", "last_login DATETIME", "is_active INTEGER NOT NULL DEFAULT 1"] as $col) {   // logowanie do panelu licencji
            try { db()->exec("ALTER TABLE license_logins ADD COLUMN $col"); } catch (\Throwable $e) {}
        }
    }

    /** Losowe hasło (12 znaków, bez mylących liter i cyfr). */
    public static function randomPassword(): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 12; $i++) $p .= $abc[random_int(0, strlen($abc) - 1)];
        return $p;
    }

    public static function setPassword(int $clientId, string $pass): void
    {
        self::migrate();
        db_exec("UPDATE license_logins SET password_hash=? WHERE client_id=?", [password_hash($pass, PASSWORD_DEFAULT), $clientId]);
    }

    /** Nowe losowe hasło do panelu licencji (zwracane raz — do pokazania osobie). */
    public static function issuePassword(int $clientId): string
    {
        $p = self::randomPassword();
        self::setPassword($clientId, $p);
        return $p;
    }

    public static function hasPassword(int $clientId): bool
    {
        self::migrate();
        return (string)(db_one("SELECT password_hash FROM license_logins WHERE client_id=?", [$clientId])['password_hash'] ?? '') !== '';
    }

    /** Logowanie do panelu licencji: login l + 8 cyfr i hasło → client_id albo null. */
    public static function verify(string $login, string $pass): ?int
    {
        self::migrate();
        $login = strtolower(trim($login));
        if (str_contains($login, '@')) $login = explode('@', $login, 2)[0];   // ktoś wpisał l…@edu.feer.org.pl
        $r = preg_match(self::PATTERN, $login) ? db_one("SELECT client_id, password_hash FROM license_logins WHERE login=? AND is_active=1", [$login]) : null;
        $hash = (string)($r['password_hash'] ?? '');
        $ok = $hash !== '' && password_verify($pass, $hash);
        if (!$r || $hash === '') password_verify($pass, '$2y$10$usesomesillystringforsalt.Q0k1Yc8cRgnn3v4QmF8yS6PfU8J5y');   // wyrównanie czasu
        if (!$ok) return null;
        db_exec("UPDATE license_logins SET last_login=datetime('now') WHERE client_id=?", [(int)$r['client_id']]);
        return (int)$r['client_id'];
    }

    public static function get(int $clientId): ?string
    {
        self::migrate();
        $r = db_one("SELECT login FROM license_logins WHERE client_id=?", [$clientId]);
        return $r ? (string)$r['login'] : null;
    }

    /** Czy login (część przed „@”) jest już zajęty gdziekolwiek w SZO. */
    public static function taken(string $local): bool
    {
        self::migrate();
        if (db_one("SELECT 1 FROM license_logins WHERE login=?", [$local])) return true;
        $like = $local . '@%';
        try {
            if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE LOWER(login)=? OR LOWER(login) LIKE ? OR LOWER(COALESCE(ms_upn,'')) LIKE ? OR LOWER(COALESCE(feer_ms_upn,'')) LIKE ?", [$local, $like, $like, $like])) return true;
        } catch (\Throwable $e) {
            if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE LOWER(login)=? OR LOWER(login) LIKE ? OR LOWER(COALESCE(ms_upn,'')) LIKE ?", [$local, $like, $like])) return true;
        }
        try { if (db_one("SELECT 1 FROM k30_ti_client_licenses WHERE LOWER(login)=? OR LOWER(login) LIKE ?", [$local, $like])) return true; } catch (\Throwable $e) {}
        try { if (db_one("SELECT 1 FROM licenses WHERE LOWER(tenant_upn) LIKE ?", [$like])) return true; } catch (\Throwable $e) {}
        return false;
    }

    /** Generuje (albo zwraca istniejący) login „l” + 8 cyfr dla uczestnika. */
    public static function generate(int $clientId, string $by): string
    {
        if ($have = self::get($clientId)) return $have;
        for ($i = 0; $i < 50; $i++) {
            $local = 'l' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            if (self::taken($local)) continue;
            try {
                db_insert('license_logins', ['client_id' => $clientId, 'login' => $local, 'created_by' => mb_substr($by, 0, 120)]);
                return $local;
            } catch (\Throwable $e) {
                if ($have = self::get($clientId)) return $have;   // równoległe kliknięcie
            }
        }
        throw new RuntimeException('Nie udało się wylosować wolnego loginu — spróbuj ponownie.');
    }
}
