<?php
/**
 * modules/ti_login/logic/TiLogin.php — jedno logowanie do TI: system rozpoznaje rodzaj konta po loginie.
 *
 * Reguły rozpoznania (login + hasło z jednego formularza, karty30/ti/login.php):
 *  - login z „@”  → kadra (panel dydaktyka: prowadzący, konsultant, kierownik, analityka) — konto SZO `users`.
 *      · adres w domenie Microsoft 365 organizacji (@feer.org.pl) → TYLKO logowanie Microsoft 365 (bez sprawdzania
 *        hasła; reguła po samej domenie, więc nie zdradza, czy konto istnieje),
 *      · inny e-mail (konta panelowe z zespol.php) → hasło SZO + obowiązkowe TOTP (totp_gate.php).
 *  - login = 3 cyfry → login zastępczy konsultanta — TYLKO na osobnej stronie karty30/ti/konsultant.php
 *      (TiLogin::altUrl); tu tylko podpowiedź z adresem. Samo hasło (bez TOTP), sesja tylko w trybie Konsultacje.
 *  - pozostałe loginy bez „@” → konta panelu kursanta (k30_ti_student_accounts / k30_ti_authorized_persons):
 *      · uczestnik: `login` albo `login_alias`,
 *      · rodzic / opiekun: `parent_login` (zwykle „…-r”),
 *      · osoba upoważniona: `k30_ti_authorized_persons.login` (zwykle „…-up”).
 *    Loginy i aliasy nie mogą zawierać „@” (walidacja aliasu w panelu), więc oba światy się nie mieszają.
 *  - Kolizja (ten sam login i to samo hasło pasują do kilku kont) → ekran wyboru panelu, dopiero po poprawnym haśle.
 *  - Uczestnik bez pierwszego logowania / do ponownej autoryzacji (ti_student_needs_reauth) → kod SMS (kursant_kod.php),
 *    jak dotąd — gdy hasło nie pasuje do żadnego innego konta.
 *
 * Zabezpieczenia: limit prób (login_attempts, prefiks „ti:” — 5 nieudanych prób na login i 30 na IP w 15 min, także
 * wysyłki kodu SMS), jednakowy komunikat błędu bez względu na to, czy login istnieje (i podobny czas odpowiedzi),
 * blokada dostępu przez opiekuna pokazywana dopiero po poprawnym haśle, konta nieaktywne = zwykły błąd.
 * Po zalogowaniu działają dotychczasowe bramki paneli: TOTP / wybór roli (dydaktyk), wybór grupy, regulaminy,
 * obrazek bezpieczeństwa, wymuszona zmiana hasła, IP logowania (student_login_user).
 *
 * Sesje: kursant / rodzic / upoważniony — sesja „k30_student” (pliki), kadra — „k30_dydaktyk” (baza). W jednym żądaniu
 * otwieramy tylko jedną z nich (rozdział po „@”), bo dyd_start() przestawia handler sesji na bazę.
 */

require_once dirname(__DIR__, 3) . '/includes/auth_security.php';

final class TiLogin
{
    public const STUDENT = 'kursant';
    public const PARENT  = 'rodzic';
    public const AUTHP   = 'upowazniony';
    public const STAFF   = 'kadra';

    /** Domena kont Microsoft 365 organizacji — te logują się tylko przez Microsoft (jak account_is_office_only()). */
    public const OFFICE_DOMAIN = '@feer.org.pl';

    private const MAX_PER_LOGIN = 5;
    private const MAX_PER_IP    = 30;
    private const WINDOW_SEC    = 900;
    private const CHOICE_KEY    = 'k30_ti_login_choice';
    private const CHOICE_TTL    = 300;
    /** Hash do wyrównania czasu odpowiedzi, gdy login nie istnieje (password_verify i tak liczy bcrypt). */
    private const DUMMY_HASH    = '$2y$12$tWwRjLC/FTTiKrcKYDYrBOloKveMHi1D9Jo.YwzzitQ9yYy9sxfQS';

    /** Opis paneli dla ekranu wyboru: [tytuł, opis, ikona]. */
    public const LABELS = [
        self::STUDENT => ['Panel uczestnika', 'Moje zajęcia, plan, zadania i rozliczenia', 'bi-pc-display'],
        self::PARENT  => ['Panel rodzica / opiekuna', 'Rozliczenia, frekwencja i dostęp dziecka', 'bi-people'],
        self::AUTHP   => ['Osoba upoważniona', 'Sprawy uczestnika, który dał mi dostęp', 'bi-person-check'],
        self::STAFF   => ['Panel prowadzącego', 'Lekcje, dziennik, kierownik', 'bi-easel2'],
    ];

    public const ERROR = 'Nieprawidłowy login lub hasło.';
    public const ERROR_THROTTLE = 'Za dużo prób logowania. Spróbuj ponownie za kilkanaście minut.';

    /** Adres wspólnej strony logowania TI (na ti.* / kurs.* — „/login.php”). */
    public static function url(array $query = []): string
    {
        $q = array_filter($query, fn($v) => $v !== '' && $v !== null);
        return tiPanelUrl('ti', 'login.php') . ($q ? '?' . http_build_query($q) : '');
    }

    /** Adres logowania Microsoft 365 dla kadry (mostek office_enter.php zakłada sesję panelu dydaktyka). */
    public static function officeUrl(): string
    {
        $app = rtrim(APP_URL, '/');
        return $app . '/auth/ms365.php?redirect=' . urlencode($app . '/karty30/ti/dydaktyk/office_enter.php');
    }

    public static function normalize(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    public static function isStaffLogin(string $login): bool
    {
        return str_contains($login, '@');
    }

    public static function isOfficeLogin(string $login): bool
    {
        $l = self::normalize($login);
        return $l !== 'serwis@local' && str_ends_with($l, self::OFFICE_DOMAIN);
    }

    // ── Limit prób ──────────────────────────────────────────────────────────────

    private static function ip(): string
    {
        return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** Czy logowanie tym loginem / z tego IP jest chwilowo zablokowane (za dużo nieudanych prób). */
    public static function throttled(string $login): bool
    {
        _auth_security_init();
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SEC);
        $byLogin = db_one("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at > ?",
                          ['ti:' . self::normalize($login), $since]);
        $byIp    = db_one("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at > ?",
                          ['tiip:' . self::ip(), $since]);
        return (int)($byLogin['c'] ?? 0) >= self::MAX_PER_LOGIN || (int)($byIp['c'] ?? 0) >= self::MAX_PER_IP;
    }

    /** Zapisuje nieudaną próbę (albo wysłany kod SMS) dla loginu i IP. */
    public static function recordAttempt(string $login): void
    {
        _auth_security_init();
        $ip = self::ip();
        $st = db()->prepare("INSERT INTO login_attempts (identifier, ip, created_at) VALUES (?, ?, ?)");
        $now = date('Y-m-d H:i:s');
        $st->execute(['ti:' . self::normalize($login), $ip, $now]);
        $st->execute(['tiip:' . $ip, $ip, $now]);
    }

    public static function clearAttempts(string $login): void
    {
        _auth_security_init();
        db()->prepare("DELETE FROM login_attempts WHERE identifier=?")->execute(['ti:' . self::normalize($login)]);
    }

    // ── Rozpoznanie ─────────────────────────────────────────────────────────────

    /**
     * Konta panelu kursanta pasujące do loginu i hasła.
     * Zwraca ['matches' => [['type','id','blocked'?]…], 'reauth' => wiersz uczestnika do kodu SMS albo null].
     * Hasło sprawdzane zawsze co najmniej raz (podobny czas odpowiedzi dla nieznanego loginu).
     */
    public static function resolvePanelAccounts(string $login, string $pass): array
    {
        $raw = trim($login);
        $low = self::normalize($login);
        $matches = [];
        $reauth = null;
        $checked = false;

        // Uczestnik: login albo własny alias (loginy uczestników bywają z wielkimi literami — porównanie jak dotąd).
        $stu = $raw === '' ? [] : db_all(
            "SELECT * FROM k30_ti_student_accounts
              WHERE (login = ? OR login = ? OR (login_alias != '' AND login_alias = ?)) AND is_active = 1",
            [$raw, $low, $low]);
        foreach ($stu as $acc) {
            $ok = $pass !== '' && !empty($acc['password_hash']) && password_verify($pass, (string)$acc['password_hash']);
            $checked = $checked || $pass !== '';
            if (self::needsReauth($acc)) {
                // Pierwsze logowanie / ponowna autoryzacja: kod SMS zamiast hasła (decyzja modułu Tożsamość).
                if (empty($acc['child_access_blocked']) && $reauth === null) $reauth = $acc;
                continue;
            }
            if ($ok) $matches[] = ['type' => self::STUDENT, 'id' => (int)$acc['id'], 'blocked' => !empty($acc['child_access_blocked'])];
            elseif (function_exists('ti_account_log')) ti_account_log((int)$acc['id'], 'login_failed', 'Nieudana próba (wspólne logowanie TI)');
        }

        // Rodzic / opiekun: parent_login (zapisywany małymi literami).
        if ($low !== '' && $pass !== '') {
            foreach (db_all("SELECT id, parent_password_hash FROM k30_ti_student_accounts
                              WHERE parent_login = ? AND parent_login != '' AND is_active = 1", [$low]) as $acc) {
                $checked = true;
                if (!empty($acc['parent_password_hash']) && password_verify($pass, (string)$acc['parent_password_hash'])) {
                    $matches[] = ['type' => self::PARENT, 'id' => (int)$acc['id']];
                }
            }
            // Osoba upoważniona (tylko pełnoletni uczestnicy, dostęp nieodwołany).
            try {
                $rows = db_all("SELECT p.id, p.password_hash, a.is_minor FROM k30_ti_authorized_persons p
                                  JOIN k30_ti_student_accounts a ON a.id = p.student_account_id
                                 WHERE p.login = ? AND p.is_active = 1 AND p.revoked_at IS NULL", [$low]);
            } catch (\Throwable $e) { $rows = []; }
            foreach ($rows as $row) {
                $checked = true;
                if (empty($row['is_minor']) && !empty($row['password_hash']) && password_verify($pass, (string)$row['password_hash'])) {
                    $matches[] = ['type' => self::AUTHP, 'id' => (int)$row['id']];
                }
            }
        }
        if (!$checked) password_verify($pass, self::DUMMY_HASH);   // wyrównanie czasu
        return ['matches' => $matches, 'reauth' => $reauth];
    }

    public static function needsReauth(array $acc): bool
    {
        if (!function_exists('ti_student_needs_reauth')) return false;
        if (function_exists('ti_reauth_migrate')) ti_reauth_migrate();
        return ti_student_needs_reauth($acc);
    }

    // ── Wybór przy kolizji ──────────────────────────────────────────────────────

    /** Zapamiętuje pasujące konta do wyboru (sesja kursanta, 5 min) i zwraca jednorazowy klucz formularza. */
    public static function stashChoice(string $login, array $matches): string
    {
        student_start();
        $nonce = bin2hex(random_bytes(16));
        $_SESSION[self::CHOICE_KEY] = ['login' => $login, 'items' => array_values($matches), 'ts' => time(), 'nonce' => $nonce];
        return $nonce;
    }

    /** Konta czekające na wybór albo null (brak / upłynął czas). */
    public static function pendingChoice(): ?array
    {
        student_start();
        $c = $_SESSION[self::CHOICE_KEY] ?? null;
        if (!$c || time() - (int)($c['ts'] ?? 0) > self::CHOICE_TTL) { unset($_SESSION[self::CHOICE_KEY]); return null; }
        return $c;
    }

    public static function clearChoice(): void
    {
        student_start();
        unset($_SESSION[self::CHOICE_KEY]);
    }

    // ── Zakończenie logowania ───────────────────────────────────────────────────

    /**
     * Zakłada sesję wybranego panelu i zwraca adres, pod który przekierować.
     * Konto zablokowane przez opiekuna → null (strona pokaże komunikat — hasło było poprawne).
     */
    public static function completePanel(array $match, string $login): ?string
    {
        $id = (int)$match['id'];
        switch ($match['type']) {
            case self::STUDENT:
                $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id = ? AND is_active = 1", [$id]);
                if (!$acc || !empty($acc['child_access_blocked'])) return null;
                student_login_user($acc, 'password', 'login: ' . $login . ' (wspólne logowanie TI)');
                $sec = function_exists('ti_sec_image_required') ? db_one("SELECT sec_chosen FROM k30_ti_student_accounts WHERE id = ?", [$id]) : null;
                return tiPanelUrl('kursant', ($sec !== null && empty($sec['sec_chosen']) && ti_sec_image_required($id)) ? 'sec_choose.php' : 'index.php');

            case self::PARENT:
                $acc = db_one("SELECT id, parent_must_change FROM k30_ti_student_accounts WHERE id = ? AND is_active = 1", [$id]);
                if (!$acc) return null;
                db()->prepare("UPDATE k30_ti_student_accounts SET parent_last_login = datetime('now') WHERE id = ?")->execute([$id]);
                if (!parent_login_for_student($id, ['auth' => 'password', 'must_change' => !empty($acc['parent_must_change']) ? 1 : 0])) return null;
                return tiPanelUrl('kursant', 'parent.php');

            case self::AUTHP:
                $row = db_one("SELECT p.*, a.client_id, a.is_minor FROM k30_ti_authorized_persons p
                                 JOIN k30_ti_student_accounts a ON a.id = p.student_account_id
                                WHERE p.id = ? AND p.is_active = 1 AND p.revoked_at IS NULL", [$id]);
                if (!$row || !empty($row['is_minor'])) return null;
                authp_start_session($row);
                return tiPanelUrl('kursant', 'authorized_person.php');
        }
        return null;
    }

    /**
     * Kadra hasłem (konta spoza domeny Microsoft 365): weryfikacja SZO + uprawnienia dydaktyka, potem obowiązkowe TOTP.
     * Zwraca adres bramki TOTP albo null (zły e-mail/hasło albo konto bez dostępu do panelu — ten sam komunikat).
     */
    public static function completeStaff(string $email, string $pass): ?string
    {
        if (self::isOfficeLogin($email) || $pass === '') return null;
        $u = db_one("SELECT * FROM users WHERE email = ? AND is_active = 1", [trim($email)]);
        if (!$u || empty($u['password']) || !password_verify($pass, (string)$u['password'])) {
            if (!$u) password_verify($pass, self::DUMMY_HASH);
            return null;
        }
        if (account_is_office_only($u)) return null;   // administracja/koordynatorzy — tylko Microsoft 365
        if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) return null;
        $profile = dyd_profile_from_user($u);
        if (!$profile) return null;
        try { db()->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([(int)$u['id']]); } catch (\Throwable $e) {}
        // Powrót na stronę, z której przyszło logowanie (np. pełny plan zajęć: ciasteczko k30_dyd_next, 15 min).
        $next = (string)($_COOKIE['k30_dyd_next'] ?? '');
        if ($next !== '') setcookie('k30_dyd_next', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        dyd_2fa_stash($profile, $next);
        return tiPanelUrl('dydaktyk', 'totp_gate.php');
    }

    /** Osobna strona logowania loginem zastępczym konsultanta (na ti.* / kurs.* „/konsultant.php”). */
    public static function altUrl(): string
    {
        return tiPanelUrl('ti', 'konsultant.php');
    }

    /** Login zastępczy konsultanta (numer 3-cyfrowy). */
    public static function isAltConsultantLogin(string $login): bool
    {
        require_once dirname(__DIR__, 2) . '/ti_consultant_access/logic/consultantAccess.php';
        return TiConsultantAccess::isAltLogin($login);
    }

    /**
     * Konsultant loginem zastępczym: samo hasło (bez kodu TOTP — decyzja użytkownika 2026-10-20), sesja od razu
     * tylko w trybie Konsultacje. Zwraca adres startu Konsultacji albo null (ten sam komunikat dla złego loginu
     * i hasła). Wymaga dydaktyk/auth.php.
     */
    public static function completeAltConsultant(string $login, string $pass): ?string
    {
        require_once dirname(__DIR__, 2) . '/ti_consultant_access/logic/consultantAccess.php';
        $profile = TiConsultantAccess::altAuthenticate($login, $pass);
        if (!$profile) return null;
        $profile['totp_bypass'] = 'login zastępczy konsultanta';   // dyd_require() nie pyta o TOTP w tej sesji
        dyd_login_user($profile);
        $_SESSION['k30_dyd_ctx'] = ['mode' => 'konsultacje'];
        return tiPanelUrl('dydaktyk', 'konsultacjeStart.php');
    }

    /** Pierwsze logowanie uczestnika: kod SMS i strona wpisania kodu albo null (brak telefonu / wysyłka nieudana). */
    public static function startReauth(array $acc): ?string
    {
        if (!ti_student_otp_send((int)$acc['id'], (int)$acc['client_id'])) return null;
        student_start();
        $_SESSION['k30_ti_student_otp'] = ['id' => (int)$acc['id'], 'ts' => time()];
        return rtrim(APP_URL, '/') . '/tozsamosc/kursant_kod.php';
    }
}
