<?php
/**
 * modules/ti_consultant_access/logic/consultantAccess.php — samodzielne logowanie konsultantów do panelu dydaktyka
 * (tylko tryb Konsultacje).
 *
 * 1. Login zastępczy = numer 3-cyfrowy nadany przez system — konsultant z kadry (zwykle Microsoft 365) sam ustawia hasło
 *    (dydaktyk/kontoZastepcze.php). Na wspólnej stronie logowania TI (karty30/ti/login.php, TiLogin) wpisuje je
 *    zamiast Microsoft 365 (osobna strona karty30/ti/konsultant.php), BEZ kodu TOTP (decyzja użytkownika), i dostaje sesję z flagą `alt_konsultacje`:
 *    tylko tryb Konsultacje (dyd_modes_available), bez uprawnień kierownika. Karty zapisują się na jego users.id.
 *    Aliasy uczestników nie mogą być samymi 3 cyframi (zarezerwowane).
 * 2. Konsultant zewnętrzny — kierownik wysyła zaproszenie (dydaktyk/konsultacjeUstawienia.php): link z tokenem
 *    (INVITE_DAYS dni, w bazie tylko skrót SHA-256). Osoba otwiera dydaktyk/zaproszenieKonsultanta.php, sama ustawia
 *    hasło; powstaje konto panelowe `users` (rola dydaktyk_ti, k30_consultant=1, tryb tylko Konsultacje — TiWorkModes),
 *    logowanie e-mailem i hasłem, przy pierwszym wejściu konfiguracja kodu TOTP.
 * Tabele: ti_consultant_alt_logins, ti_consultant_invites. Zdarzenia w audit_log (ti.consultant_*).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

final class TiConsultantAccess
{
    public const INVITE_DAYS = 7;
    public const MIN_PASS    = 10;
    private const DUMMY_HASH = '$2y$12$tWwRjLC/FTTiKrcKYDYrBOloKveMHi1D9Jo.YwzzitQ9yYy9sxfQS';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_consultant_access', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS ti_consultant_alt_logins (
            user_id       INTEGER PRIMARY KEY,
            login         TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_active     INTEGER NOT NULL DEFAULT 1,
            created_at    DATETIME,
            updated_at    DATETIME,
            last_login_at DATETIME,
            disabled_by   INTEGER
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS ti_consultant_invites (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            name       TEXT NOT NULL,
            email      TEXT NOT NULL,
            created_by INTEGER,
            created_at DATETIME,
            expires_at DATETIME,
            used_at    DATETIME,
            user_id    INTEGER,
            revoked_at DATETIME
        )");
    }

    private static function audit(string $ev, array $data, ?int $uid): void
    {
        if (function_exists('audit_log')) { try { audit_log('ti.consultant_' . $ev, $data, $uid); } catch (\Throwable $e) {} }
    }

    public static function normalize(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    /** Login zastępczy konsultanta — numer 3-cyfrowy. */
    public static function isAltLogin(string $login): bool
    {
        $l = self::normalize($login);
        return (bool)preg_match('/^\d{3}$/', $l);
    }

    // ── Login zastępczy ─────────────────────────────────────────────────────────

    public static function altFor(int $uid): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM ti_consultant_alt_logins WHERE user_id = ?", [$uid]) ?: null;
    }

    /** Czy login zajmuje ktoś inny (login zastępczy albo dowolny login panelu kursanta, rodzica, osoby upoważnionej). */
    public static function loginTaken(string $login, int $exceptUid): bool
    {
        self::migrate();
        if (db_one("SELECT 1 FROM ti_consultant_alt_logins WHERE login = ? AND user_id <> ?", [$login, $exceptUid])) return true;
        $q = [
            "SELECT 1 FROM k30_ti_student_accounts WHERE lower(login) = ? OR lower(login_alias) = ?",
            "SELECT 1 FROM k30_ti_student_accounts WHERE lower(parent_login) = ?",
            "SELECT 1 FROM k30_ti_authorized_persons WHERE lower(login) = ?",
        ];
        foreach ($q as $sql) {
            try { if (db_one($sql, array_fill(0, substr_count($sql, '?'), $login))) return true; } catch (\Throwable $e) {}
        }
        return false;
    }

    /** Nowy wolny numer 3-cyfrowy (100–999) dla loginu zastępczego; '' gdy pula wyczerpana. */
    public static function newNumber(int $uid): string
    {
        $used = array_column(db_all("SELECT login FROM ti_consultant_alt_logins WHERE user_id <> ?", [$uid]), 'login');
        $free = array_values(array_diff(array_map('strval', range(100, 999)), $used));
        for ($i = 0; $i < 40 && $free; $i++) {
            $n = $free[random_int(0, count($free) - 1)];
            if (!self::loginTaken($n, $uid)) return $n;
        }
        return '';
    }

    /**
     * Ustawia hasło loginu zastępczego. Numer (3 cyfry) nadaje system przy pierwszym zapisie (albo gdy $newNumber).
     * @return string '' = OK, inaczej komunikat
     */
    public static function altSave(int $uid, string $pass, string $pass2, bool $newNumber = false): string
    {
        self::migrate();
        $cur = self::altFor($uid);
        $login = ($cur && !$newNumber && self::isAltLogin((string)$cur['login'])) ? (string)$cur['login'] : self::newNumber($uid);
        if ($login === '') return 'Brak wolnych numerów — napisz do administratora.';
        if ($pass === '' && $cur) {
            db()->prepare("UPDATE ti_consultant_alt_logins SET login = ?, is_active = 1, disabled_by = NULL, updated_at = ? WHERE user_id = ?")
                ->execute([$login, date('Y-m-d H:i:s'), $uid]);
            self::audit('alt_login_set', ['login' => $login, 'password' => false], $uid);
            return '';
        }
        if (mb_strlen($pass) < self::MIN_PASS) return 'Hasło musi mieć co najmniej ' . self::MIN_PASS . ' znaków.';
        if ($pass !== $pass2) return 'Hasła różnią się.';
        $now = date('Y-m-d H:i:s');
        db()->prepare("INSERT INTO ti_consultant_alt_logins (user_id, login, password_hash, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)
                       ON CONFLICT(user_id) DO UPDATE SET login = excluded.login, password_hash = excluded.password_hash, is_active = 1,
                                                          disabled_by = NULL, updated_at = excluded.updated_at")
            ->execute([$uid, $login, password_hash($pass, PASSWORD_BCRYPT), $now, $now]);
        self::audit('alt_login_set', ['login' => $login, 'password' => true], $uid);
        return '';
    }

    public static function altDisable(int $uid, int $by): void
    {
        self::migrate();
        db()->prepare("UPDATE ti_consultant_alt_logins SET is_active = 0, disabled_by = ?, updated_at = ? WHERE user_id = ?")
            ->execute([$by ?: null, date('Y-m-d H:i:s'), $uid]);
        self::audit('alt_login_disabled', ['user_id' => $uid], $by ?: null);
    }

    /**
     * Logowanie loginem zastępczym: profil panelu z flagą alt_konsultacje albo null (zły login/hasło, konto nieaktywne,
     * brak trybu Konsultacje). Hasło sprawdzane zawsze (podobny czas odpowiedzi dla nieznanego loginu).
     */
    public static function altAuthenticate(string $login, string $pass): ?array
    {
        self::migrate();
        $row = db_one("SELECT a.*, u.id AS uid FROM ti_consultant_alt_logins a JOIN users u ON u.id = a.user_id
                       WHERE a.login = ? AND a.is_active = 1 AND u.is_active = 1", [self::normalize($login)]);
        if (!$row || $pass === '' || !password_verify($pass, (string)$row['password_hash'])) {
            if (!$row) password_verify($pass, self::DUMMY_HASH);
            return null;
        }
        $u = db_one("SELECT * FROM users WHERE id = ?", [(int)$row['uid']]);
        if (!$u || (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time())) return null;
        $profile = function_exists('dyd_profile_from_user') ? dyd_profile_from_user($u) : null;
        if (!$profile) return null;
        require_once dirname(__DIR__, 2) . '/ti_work_modes/logic/workModes.php';
        if (!in_array('konsultacje', TiWorkModes::available((int)$u['id'], !empty($profile['is_staff']), (string)($profile['panel_role'] ?? '')), true)) return null;
        $profile['alt_konsultacje'] = true;   // tylko tryb Konsultacje (dyd_modes_available)
        $profile['alt_staff'] = !empty($profile['is_staff']);   // tryby pracy liczone wg pierwotnej roli
        $profile['is_staff'] = false;         // bez uprawnień kierownika — logowanie zastępcze
        db()->prepare("UPDATE ti_consultant_alt_logins SET last_login_at = ? WHERE user_id = ?")->execute([date('Y-m-d H:i:s'), (int)$u['id']]);
        self::audit('alt_login_used', ['login' => $row['login']], (int)$u['id']);
        return $profile;
    }

    /** Wszystkie loginy zastępcze (widok kierownika). */
    public static function altList(): array
    {
        self::migrate();
        return db_all("SELECT a.user_id, a.login, a.is_active, a.updated_at, a.last_login_at, u.name, u.email
                         FROM ti_consultant_alt_logins a JOIN users u ON u.id = a.user_id ORDER BY u.name");
    }

    // ── Zaproszenia konsultantów zewnętrznych ───────────────────────────────────

    /** @return array{ok:bool,error?:string,url?:string,id?:int} */
    public static function inviteCreate(string $name, string $email, int $by, string $baseUrl): array
    {
        self::migrate();
        $name = trim(mb_substr($name, 0, 120));
        $email = strtolower(trim($email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Podaj imię i nazwisko oraz poprawny e-mail.'];
        if (str_ends_with($email, '@feer.org.pl')) return ['ok' => false, 'error' => 'Osoby z adresem @feer.org.pl logują się przez Microsoft 365 — nadaj im tryb Konsultacje w „Zespół i role”, a login zastępczy ustawią sobie same.'];
        if (db_one("SELECT 1 FROM users WHERE lower(email) = ?", [$email])) return ['ok' => false, 'error' => 'Konto z tym e-mailem już istnieje — nadaj mu tryb Konsultacje w „Zespół i role”.'];
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $now = time();
        $id = (int)db_insert('ti_consultant_invites', [
            'token_hash' => hash('sha256', $token), 'name' => $name, 'email' => $email, 'created_by' => $by ?: null,
            'created_at' => date('Y-m-d H:i:s', $now), 'expires_at' => date('Y-m-d H:i:s', $now + self::INVITE_DAYS * 86400),
        ]);
        self::audit('invite_created', ['invite_id' => $id, 'email' => $email], $by ?: null);
        return ['ok' => true, 'id' => $id, 'url' => rtrim($baseUrl, '/') . '/zaproszenieKonsultanta.php?t=' . $token];
    }

    /** E-mail z linkiem zaproszenia (kolejka poczty, nadawca systemowy). */
    public static function inviteMail(string $email, string $name, string $url, string $byName): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $org = defined('ORG_NAME') ? ORG_NAME : 'Fundacja';
        $text = implode("\n", [
            'Dzień dobry ' . $name . ',', '',
            ($byName !== '' ? $byName . ' zaprasza' : 'Zapraszamy') . ' Cię do panelu konsultacji (' . $org . ') jako konsultant(kę).', '',
            'Załóż konto — ustaw hasło pod tym linkiem (ważny ' . self::INVITE_DAYS . ' dni, jednorazowy):', $url, '',
            'Loginem będzie ten adres e-mail. Przy pierwszym logowaniu skonfigurujesz kod z aplikacji uwierzytelniającej.', '',
            '-- ', 'Wiadomość wysłana automatycznie, prosimy na nią nie odpowiadać.',
        ]);
        try { mail_queue_add($email, $name, 'Zaproszenie do panelu konsultacji — ' . $org, '', $text, 'ti_consultant_invite', null); return true; }
        catch (\Throwable $e) { error_log('[ti_consultant_access] invite mail: ' . $e->getMessage()); return false; }
    }

    /** Ważne (nieużyte, niecofnięte, nieprzeterminowane) zaproszenie dla tokenu z linku. */
    public static function inviteFind(string $token): ?array
    {
        self::migrate();
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) return null;
        $r = db_one("SELECT * FROM ti_consultant_invites WHERE token_hash = ?", [hash('sha256', $token)]);
        if (!$r || $r['used_at'] || $r['revoked_at'] || strtotime((string)$r['expires_at']) < time()) return null;
        return $r;
    }

    /** Konsultant sam zakłada konto z zaproszenia. @return array{ok:bool,error?:string,user_id?:int} */
    public static function inviteAccept(array $inv, string $pass, string $pass2): array
    {
        if (mb_strlen($pass) < self::MIN_PASS) return ['ok' => false, 'error' => 'Hasło musi mieć co najmniej ' . self::MIN_PASS . ' znaków.'];
        if ($pass !== $pass2) return ['ok' => false, 'error' => 'Hasła różnią się.'];
        if (db_one("SELECT 1 FROM users WHERE lower(email) = ?", [strtolower((string)$inv['email'])])) return ['ok' => false, 'error' => 'Konto z tym e-mailem już istnieje — zaloguj się.'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $uid = (int)db_insert('users', [
                'name' => $inv['name'], 'email' => strtolower((string)$inv['email']), 'password' => password_hash($pass, PASSWORD_BCRYPT),
                'role' => 'dydaktyk_ti', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            try { db()->prepare("UPDATE users SET k30_consultant = 1 WHERE id = ?")->execute([$uid]); } catch (\Throwable $e) {}
            require_once dirname(__DIR__, 2) . '/ti_work_modes/logic/workModes.php';
            TiWorkModes::set($uid, false, true, (int)($inv['created_by'] ?? 0));
            db()->prepare("UPDATE ti_consultant_invites SET used_at = ?, user_id = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $uid, (int)$inv['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[ti_consultant_access] invite accept: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Nie udało się założyć konta. Spróbuj ponownie albo napisz do osoby, która Cię zaprosiła.'];
        }
        self::audit('invite_accepted', ['invite_id' => (int)$inv['id']], $uid);
        self::accountCreatedMail($inv);
        return ['ok' => true, 'user_id' => $uid];
    }

    /** Po założeniu konta: informacja do konsultanta, DW do administratorów (aktywne konta z rolą admin) i zapraszającego. */
    public static function accountCreatedMail(array $inv): void
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $org = defined('ORG_NAME') ? ORG_NAME : 'Fundacja';
        $cc = array_column(db_all("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email LIKE '%@%' AND email NOT LIKE '%@local'"), 'email');
        $by = (int)($inv['created_by'] ?? 0) ? db_one("SELECT email FROM users WHERE id = ?", [(int)$inv['created_by']]) : null;
        if ($by && filter_var($by['email'], FILTER_VALIDATE_EMAIL)) $cc[] = $by['email'];
        $cc = array_values(array_unique(array_filter(array_map('strtolower', $cc), fn($e) => $e !== strtolower((string)$inv['email']))));
        $login = function_exists('tiPanelUrl') ? tiPanelUrl('ti', 'konsultant.php') : rtrim(APP_URL, '/') . '/karty30/ti/konsultant.php';   // strona logowania konsultantów
        $text = implode("\n", [
            'Dzień dobry ' . $inv['name'] . ',', '',
            'Twoje konto konsultanta w panelu konsultacji (' . $org . ') zostało założone.', '',
            'Login: ' . $inv['email'], 'Logowanie: ' . $login,
            'Przy pierwszym logowaniu skonfigurujesz kod z aplikacji uwierzytelniającej. Dostęp obejmuje tylko Konsultacje.', '',
            '-- ', 'Wiadomość wysłana automatycznie, prosimy na nią nie odpowiadać.',
        ]);
        try { mail_queue_add((string)$inv['email'], (string)$inv['name'], 'Konto konsultanta założone — ' . $org, '', $text, 'ti_consultant_account', null, '', false, [], '', $cc); }
        catch (\Throwable $e) { error_log('[ti_consultant_access] account mail: ' . $e->getMessage()); }
    }

    public static function invites(): array
    {
        self::migrate();
        return db_all("SELECT i.*, u.name AS by_name FROM ti_consultant_invites i LEFT JOIN users u ON u.id = i.created_by
                        ORDER BY i.id DESC LIMIT 50");
    }

    public static function inviteRevoke(int $id, int $by): void
    {
        self::migrate();
        db()->prepare("UPDATE ti_consultant_invites SET revoked_at = ? WHERE id = ? AND used_at IS NULL")->execute([date('Y-m-d H:i:s'), $id]);
        self::audit('invite_revoked', ['invite_id' => $id], $by ?: null);
    }
}
