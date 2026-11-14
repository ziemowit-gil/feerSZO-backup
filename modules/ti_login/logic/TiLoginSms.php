<?php
/**
 * modules/ti_login/logic/TiLoginSms.php — TYMCZASOWE logowanie do TI kodem SMS po samym loginie (polecenie 2026-11-14:
 * „Logowanie dla kursantów dydaktyki tymczasowo kodem SMS po wpisaniu loginu”, „to samo z prowadzącymi mającymi umowę”).
 *
 * Kto dostaje kod:
 *  - uczestnik (k30_ti_student_accounts: login, alias albo numer) — na telefon z karty klienta (k30_clients.phone),
 *  - prowadzący i inna kadra panelu dydaktyka (konto SZO `users` z dostępem do panelu, dyd_profile_from_user) z umową
 *    (wolontariat / zlecenie / dzieło, umowa trwająca albo zakończona najwyżej 2 dni temu — DhostingContractMail::keepsActive)
 *    — na komórkowy z konta SZO (users.phone_number), a gdy go brak, z umowy wolontariackiej (telefon).
 *  Administratorzy (account_is_office_only) logują się dalej przez Microsoft 365. Rodzic, opiekun i osoba upoważniona — hasłem.
 *
 * Przebieg: karty30/ti/login.php → start() (zawsze ta sama odpowiedź: „jeśli login jest poprawny, wysłaliśmy kod”; dla
 * nieznanego loginu powstaje wiersz-atrapa bez SMS-a) → ciasteczko z losowym tokenem → widok kodu → verify() → sesja
 * panelu. Kod: 6 cyfr, 10 minut, 5 prób, jednorazowy, w bazie tylko hash. Limity wysyłek: 3 na login i 10 na IP w 15 minut.
 * Kadra po kodzie SMS nie przechodzi bramki TOTP (totp_bypass w sesji i w tokenie „zapamiętaj mnie”, dydaktyk/auth.php).
 *
 * Wyłączenie: ustawienie ti_login_sms = 0 (tabela settings). Bez wpisu albo z inną wartością — włączone.
 */

require_once dirname(__DIR__, 3) . '/includes/sms.php';

final class TiLoginSms
{
    public const COOKIE = 'ti_sms_login';
    public const KIND_STUDENT = 'student';
    public const KIND_STAFF   = 'staff';
    private const KIND_NONE   = 'none';
    private const TTL         = 600;
    private const MAX_TRIES   = 5;
    private const MAX_PER_LOGIN = 3;
    private const MAX_PER_IP    = 10;
    private const WINDOW_SEC    = 900;

    public static function enabled(): bool
    {
        $v = function_exists('org_setting') ? (string)org_setting('ti_login_sms')
            : (string)(db_one("SELECT value FROM settings WHERE key_='ti_login_sms'")['value'] ?? '');
        return trim($v) !== '0';
    }

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS ti_login_sms (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash  TEXT NOT NULL UNIQUE,
            kind        TEXT NOT NULL,
            ref_id      INTEGER NOT NULL DEFAULT 0,
            login       TEXT NOT NULL,
            code_hash   TEXT NOT NULL,
            ip          TEXT,
            sent        INTEGER NOT NULL DEFAULT 0,
            attempts    INTEGER NOT NULL DEFAULT 0,
            expires_at  DATETIME NOT NULL,
            used_at     DATETIME,
            created_at  DATETIME NOT NULL
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_login_sms_login ON ti_login_sms(login, created_at)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_login_sms_ip ON ti_login_sms(ip, created_at)");
    }

    /** Adres e-mail administratora, który loguje się tylko przez Microsoft 365 (wtedy bez kodu SMS). */
    public static function isOfficeAdmin(string $login): bool
    {
        $u = db_one("SELECT id, email, role FROM users WHERE LOWER(email) = ? AND is_active = 1", [TiLogin::normalize($login)]);
        return $u && function_exists('account_is_office_only') && account_is_office_only($u);
    }

    /**
     * Wysyła kod (gdy login należy do uczestnika albo prowadzącego z umową) i ustawia ciasteczko z tokenem.
     * Zwraca 'ok' (także dla nieznanego loginu), 'throttled' (za dużo kodów) albo 'failed' (SMS nie wyszedł).
     */
    public static function start(string $login): string
    {
        self::migrate();
        $norm = TiLogin::normalize($login);
        $ip   = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SEC);
        $byLogin = (int)(db_one("SELECT COUNT(*) AS c FROM ti_login_sms WHERE login = ? AND created_at > ?", [$norm, $since])['c'] ?? 0);
        $byIp    = (int)(db_one("SELECT COUNT(*) AS c FROM ti_login_sms WHERE ip = ? AND created_at > ?", [$ip, $since])['c'] ?? 0);
        if ($byLogin >= self::MAX_PER_LOGIN || $byIp >= self::MAX_PER_IP) return 'throttled';

        [$kind, $refId, $phone] = TiLogin::isStaffLogin($login) ? self::staffFor($norm) : self::studentFor($login);

        $code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = bin2hex(random_bytes(24));
        db()->prepare("INSERT INTO ti_login_sms (token_hash, kind, ref_id, login, code_hash, ip, sent, expires_at, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")
            ->execute([hash('sha256', $token), $kind, $refId, $norm, password_hash($code, PASSWORD_DEFAULT), $ip,
                       date('Y-m-d H:i:s', time() + self::TTL), date('Y-m-d H:i:s')]);
        $rowId = (int)db()->lastInsertId();
        self::setCookie($token);

        if ($kind === self::KIND_NONE) return 'ok';   // atrapa: ten sam widok kodu, SMS nie wychodzi
        try {
            $sentBy = sms_send($phone, 'FEER: kod do logowania w panelu TI: ' . $code . ' (wazny 10 min). Nie udostepniaj go nikomu.', 'login_code');
        } catch (\Throwable $e) {
            error_log('[ti_login_sms] SMS: ' . $e->getMessage());
            $sentBy = '';
        }
        if ($sentBy === '') return 'failed';
        db()->prepare("UPDATE ti_login_sms SET sent = 1 WHERE id = ?")->execute([$rowId]);
        if ($kind === self::KIND_STUDENT && function_exists('ti_account_log')) {
            ti_account_log($refId, 'login_code_sent', 'Wysłano kod SMS do logowania (wspólne logowanie TI)');
        }
        return 'ok';
    }

    /** Ponowna wysyłka dla loginu z bieżącego kroku kodu ('ok' | 'throttled' | 'failed' | 'expired'). */
    public static function resend(): string
    {
        $p = self::pending();
        return $p ? self::start((string)$p['login']) : 'expired';
    }

    /** Wiersz bieżącego kroku kodu (z ciasteczka) albo null: brak, wygasł, zużyty albo wyczerpane próby. */
    public static function pending(): ?array
    {
        $token = (string)($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        self::migrate();
        $row = db_one("SELECT * FROM ti_login_sms WHERE token_hash = ?", [hash('sha256', $token)]);
        if (!$row || !empty($row['used_at']) || (int)$row['attempts'] >= self::MAX_TRIES
            || strtotime((string)$row['expires_at']) < time()) return null;
        return $row;
    }

    /**
     * Sprawdza kod. Zwraca ['kind', 'ref_id', 'login'] po poprawnym kodzie (kod zużyty, ciasteczko usunięte) albo null.
     * Każda próba liczy się do limitu; po 5 błędnych kod przestaje działać.
     */
    public static function verify(string $code): ?array
    {
        $row = self::pending();
        if (!$row) return null;
        db()->prepare("UPDATE ti_login_sms SET attempts = attempts + 1 WHERE id = ?")->execute([(int)$row['id']]);
        $code = (string)preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || !password_verify($code, (string)$row['code_hash']) || $row['kind'] === self::KIND_NONE) return null;
        db()->prepare("UPDATE ti_login_sms SET used_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
        self::clear();
        return ['kind' => (string)$row['kind'], 'ref_id' => (int)$row['ref_id'], 'login' => (string)$row['login']];
    }

    public static function clear(): void
    {
        self::setCookie('', -3600);
        unset($_COOKIE[self::COOKIE]);
    }

    // ── Rozpoznanie ─────────────────────────────────────────────────────────────

    /** Uczestnik: [kind, id konta, telefon z karty klienta]; bez konta albo bez telefonu → atrapa. */
    private static function studentFor(string $login): array
    {
        $raw = trim($login);
        $low = TiLogin::normalize($login);
        $rows = $raw === '' ? [] : db_all(
            "SELECT a.id, c.phone FROM k30_ti_student_accounts a LEFT JOIN k30_clients c ON c.id = a.client_id
              WHERE (a.login = ? OR a.login = ? OR (a.login_alias != '' AND a.login_alias = ?) OR (a.student_no != '' AND a.student_no = ?))
                AND a.is_active = 1 ORDER BY a.id",
            [$raw, $low, $low, $raw]);
        foreach ($rows as $r) {
            if (preg_replace('/\D/', '', (string)$r['phone']) !== '') return [self::KIND_STUDENT, (int)$r['id'], (string)$r['phone']];
        }
        return [self::KIND_NONE, 0, ''];
    }

    /** Prowadzący z umową: [kind, id konta SZO, telefon komórkowy]; inaczej atrapa. */
    private static function staffFor(string $email): array
    {
        $none = [self::KIND_NONE, 0, ''];
        $u = db_one("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1", [$email]);
        if (!$u || (function_exists('account_is_office_only') && account_is_office_only($u))) return $none;
        if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) return $none;
        if (!function_exists('dyd_profile_from_user')) require_once dirname(__DIR__, 3) . '/karty30/ti/dydaktyk/auth.php';
        if (!dyd_profile_from_user($u)) return $none;
        $contracts = self::contractsOf($u);
        if (!$contracts) return $none;
        foreach ([(string)($u['phone_number'] ?? ''), ...array_map(fn($c) => (string)($c['telefon'] ?? ''), $contracts)] as $ph) {
            if (trim($ph) !== '' && sms_is_mobile($ph)) return [self::KIND_STAFF, (int)$u['id'], trim($ph)];
        }
        return $none;
    }

    /**
     * Trwające umowy osoby (wolontariat / zlecenie / dzieło) po adresie e-mail konta SZO, loginie Microsoft 365
     * albo prywatnym e-mailu z umowy; najnowsze najpierw.
     */
    public static function contractsOf(array $u): array
    {
        require_once dirname(__DIR__, 3) . '/modules/dhosting_migrator/logic/DhostingContractMail.php';
        $keys = array_values(array_unique(array_filter(array_map(fn($v) => mb_strtolower(trim((string)$v)),
            [$u['email'] ?? '', $u['m365_login'] ?? '', $u['alt_email'] ?? '']))));
        if (!$keys) return [];
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $out = [];
        foreach (DhostingContractMail::TABLES as $type => $table) {
            try {
                $rows = db_all("SELECT * FROM {$table} WHERE LOWER(TRIM(COALESCE(m365_login,''))) IN ({$ph})
                                   OR LOWER(TRIM(COALESCE(email,''))) IN ({$ph}) ORDER BY id DESC", [...$keys, ...$keys]);
            } catch (\Throwable $e) { $rows = []; }
            foreach ($rows as $r) {
                if (DhostingContractMail::keepsActive($r)) $out[] = $r + ['_type' => $type];
            }
        }
        return $out;
    }

    private static function setCookie(string $value, int $ttl = self::TTL): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $value, ['expires' => time() + $ttl, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
        }
        if ($value !== '') $_COOKIE[self::COOKIE] = $value;
    }
}
