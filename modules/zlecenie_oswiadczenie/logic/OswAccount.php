<?php
declare(strict_types=1);

/**
 * modules/zlecenie_oswiadczenie/logic/OswAccount.php — konto oświadczeń (publiczny kreator).
 *
 * Konto NIE jest kontem SZO (tabela zlec_osw_accounts, klucz sesji zlec_osw_acc w sesji SZO, bez $_SESSION['user']),
 * więc z założenia nie otwiera żadnego innego modułu — widzi wyłącznie własne, potwierdzone oświadczenia
 * (podgląd, ponowny wydruk, status, ponowny skan po odrzuceniu).
 *
 * Kolejność: publiczny kreator → wydruk → przesłanie skanu → onScanUploaded(): konto zakładane (albo znajdowane po e-mailu)
 * z danymi z oświadczenia i e-mail z linkiem „Ustaw hasło” / „Potwierdź” (token 7 dni, w bazie tylko skrót SHA-256).
 * Pakiet przypina się do konta dopiero po kliknięciu linku — kto poda cudzy e-mail, nie dopisze niczego do cudzego konta.
 * Logowanie: 5 błędnych prób → blokada 15 min; sesja wygasa po 2 h bezczynności. Limity prób z IP: zlec_osw_hits.
 *
 * Konto SZO (2026-10-20): po potwierdzeniu powstaje też lokalne konto SZO (users, rola viewer, portal_scope='osw_only',
 * to samo hasło) — loguje się zwykłą stroną logowania SZO, ale require_login() wpuszcza je tylko do /zlecenie/konto
 * (panel ograniczony do oświadczeń i danych, z belką „konto służy do podglądu oświadczenia”). Istniejącego konta SZO
 * z tym samym e-mailem nie przejmujemy — oświadczenie tylko się do niego przypina. Pracownik Fundacji przepina konto na
 * służbowe Microsoft 365 (linkWorkAccount): e-mail @feer.org.pl, logowanie już tylko przez M365, pełny panel.
 */
require_once __DIR__ . '/OswService.php';

final class OswAccount
{
    public const IDLE = 7200;
    public const PASS_MIN = 10;
    public const TOKEN_TTL = 7 * 86400;
    public const RESET_TTL = 7200;
    /** Limity prób z jednego IP: akcja => [okno w sekundach, maks.]. */
    public const LIMITS = ['start' => [3600, 6], 'start_day' => [86400, 20], 'login' => [900, 20], 'reset' => [3600, 5], 'upload' => [3600, 30]];

    // ── Limity ────────────────────────────────────────────────────────────────

    /** Czy z tego IP można wykonać akcję (i odnotuj próbę). */
    public static function hit(string $action, string $ip, bool $record = true): bool
    {
        OswService::init();
        $ipHash = hash('sha256', $ip . '|' . (defined('APP_SECRET') ? APP_SECRET : __FILE__));
        foreach (self::LIMITS as $key => [$win, $max]) {
            if ($key !== $action && $key !== $action . '_day') continue;
            $n = (int)(db_one("SELECT COUNT(*) AS n FROM zlec_osw_hits WHERE action = ? AND ip_hash = ? AND at > ?",
                [$action, $ipHash, date('Y-m-d H:i:s', time() - $win)])['n'] ?? 0);
            if ($n >= $max) return false;
        }
        if ($record) {
            db_exec("INSERT INTO zlec_osw_hits (ip_hash, action, at) VALUES (?, ?, ?)", [$ipHash, $action, date('Y-m-d H:i:s')]);
            if (random_int(1, 50) === 1) db_exec("DELETE FROM zlec_osw_hits WHERE at < ?", [date('Y-m-d H:i:s', time() - 2 * 86400)]);
        }
        return true;
    }

    // ── Odczyt ────────────────────────────────────────────────────────────────

    public static function get(int $id): ?array
    {
        OswService::init();
        return db_one("SELECT * FROM zlec_osw_accounts WHERE id = ?", [$id]) ?: null;
    }

    public static function byEmail(string $email): ?array
    {
        OswService::init();
        return db_one("SELECT * FROM zlec_osw_accounts WHERE email = ?", [mb_strtolower(trim($email))]) ?: null;
    }

    /** Potwierdzone oświadczenia konta (najnowsze pierwsze). */
    public static function pakiety(int $accId): array
    {
        $rows = db_all("SELECT * FROM zlec_osw WHERE account_id = ? AND account_confirmed_at IS NOT NULL ORDER BY id DESC", [$accId]);
        foreach ($rows as &$r) $r['_docs'] = OswService::docs((int)$r['id']);
        return $rows;
    }

    // ── Założenie konta po skanie ─────────────────────────────────────────────

    /**
     * Po przesłaniu skanu z publicznego kreatora: konto (nowe albo istniejące po e-mailu) + e-mail z linkiem.
     * Zwraca adres, na który wysłano link, albo null (pakiet już przypięty / bez e-maila / link wysłany przed chwilą).
     */
    public static function onScanUploaded(array $o): ?string
    {
        if (($o['source'] ?? '') !== 'public' || !empty($o['account_confirmed_at'])) return null;
        $a = OswService::answers($o);
        $email = mb_strtolower(trim((string)($a['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        if (!empty($o['account_mail_at']) && strtotime((string)$o['account_mail_at']) > time() - 600) return $email;   // bez zasypywania skrzynki
        $acc = self::byEmail($email);
        if (!$acc) {
            $id = (int)db_insert('zlec_osw_accounts', self::profile($a) + ['email' => $email, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $acc = (array)self::get($id);
        }
        $token = self::issueToken((int)$acc['id'], 'attach', (int)$o['id'], self::TOKEN_TTL);
        db_exec("UPDATE zlec_osw SET account_id = ?, account_mail_at = ?, updated_at = ? WHERE id = ?", [(int)$acc['id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$o['id']]);
        self::mail($acc, empty($acc['password_hash']) ? 'setup' : 'attach', self::url('/konto/potwierdz', ['t' => $token]));
        return $email;
    }

    /** Dane konta z oświadczenia (imię, nazwisko, PESEL, data urodzenia, adres, telefon). */
    private static function profile(array $a): array
    {
        $p = preg_split('/\s+/u', trim((string)($a['nazwisko_imiona'] ?? ''))) ?: [];
        return ['last_name' => (string)($p[0] ?? ''), 'first_name' => implode(' ', array_slice($p, 1)),
                'pesel' => (string)($a['pesel'] ?? '') ?: null, 'birth_date' => (string)($a['data_urodzenia'] ?? '') ?: null,
                'address' => OswForm::addressLine($a), 'phone' => (string)($a['telefon'] ?? '') ?: null];
    }

    private static function issueToken(int $accId, string $purpose, ?int $oswId, int $ttl): string
    {
        $t = bin2hex(random_bytes(24));
        db_exec("UPDATE zlec_osw_accounts SET token_hash = ?, token_purpose = ?, token_osw_id = ?, token_expires_at = ?, updated_at = ? WHERE id = ?",
            [hash('sha256', $t), $purpose, $oswId, date('Y-m-d H:i:s', time() + $ttl), date('Y-m-d H:i:s'), $accId]);
        return $t;
    }

    public static function byToken(string $t): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
        OswService::init();
        return db_one("SELECT * FROM zlec_osw_accounts WHERE token_hash = ? AND token_expires_at > ?", [hash('sha256', $t), date('Y-m-d H:i:s')]) ?: null;
    }

    /**
     * Link z e-maila: konto bez hasła — ustawia hasło; potem przypina pakiet, uzupełnia dane konta z oświadczenia.
     * Zwraca błąd albo ''. Konto bez hasła po ustawieniu jest od razu zalogowane; konto z hasłem — loguje się zwykle.
     */
    public static function confirm(string $token, string $pass = '', string $pass2 = ''): string
    {
        $acc = self::byToken($token);
        if (!$acc || !in_array($acc['token_purpose'], ['attach', 'reset'], true)) return 'Link wygasł albo był już użyty. Zaloguj się albo użyj „Nie pamiętam hasła”.';
        $needPass = empty($acc['password_hash']) || $acc['token_purpose'] === 'reset';
        if ($needPass) {
            if (mb_strlen($pass) < self::PASS_MIN) return 'Hasło musi mieć co najmniej ' . self::PASS_MIN . ' znaków.';
            if ($pass !== $pass2) return 'Hasła nie są takie same.';
            if (strcasecmp($pass, (string)$acc['email']) === 0) return 'Hasło nie może być adresem e-mail.';
        }
        $now = date('Y-m-d H:i:s');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $set = "token_hash = NULL, token_purpose = NULL, token_osw_id = NULL, token_expires_at = NULL, failed_logins = 0, locked_until = NULL,
                    email_verified_at = COALESCE(email_verified_at, ?), updated_at = ?";
            $par = [$now, $now];
            if ($needPass) { $set .= ", password_hash = ?"; $par[] = password_hash($pass, PASSWORD_DEFAULT); }
            if ($acc['token_purpose'] === 'attach' && ($o = OswService::byId((int)$acc['token_osw_id']))) {
                db_exec("UPDATE zlec_osw SET account_id = ?, account_confirmed_at = COALESCE(account_confirmed_at, ?), updated_at = ? WHERE id = ?",
                    [(int)$acc['id'], $now, $now, (int)$o['id']]);
                foreach (self::profile(OswService::answers($o)) as $k => $v) {   // dane konta = ostatnie potwierdzone oświadczenie
                    if ($v !== null && $v !== '') { $set .= ", {$k} = ?"; $par[] = $v; }
                }
            }
            $par[] = (int)$acc['id'];
            db_exec("UPDATE zlec_osw_accounts SET {$set} WHERE id = ?", $par);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $acc = (array)self::get((int)$acc['id']);
        $user = self::ensureUser($acc);
        if ($needPass) {
            self::startSession((int)$acc['id']);
            if ($user && ($user['portal_scope'] ?? '') === self::SCOPE) self::loginSzo($user);
        }
        return '';
    }

    // ── Konto SZO ─────────────────────────────────────────────────────────────

    public const SCOPE = 'osw_only';
    public const WORK_DOMAIN = '@feer.org.pl';

    /**
     * Lokalne konto SZO dla konta oświadczeń (tworzy przy pierwszym wywołaniu; hasło = hasło konta oświadczeń).
     * Istniejące konto SZO z tym e-mailem tylko przypina (bez zmiany hasła i zakresu). Zwraca wiersz users albo null.
     */
    public static function ensureUser(array $acc): ?array
    {
        if (empty($acc['email'])) return null;
        if (!empty($acc['user_id']) && ($u = db_one("SELECT * FROM users WHERE id = ?", [(int)$acc['user_id']]))) {
            if (($u['portal_scope'] ?? '') === self::SCOPE && !empty($acc['password_hash']) && $u['password'] !== $acc['password_hash']) {
                db_exec("UPDATE users SET password = ? WHERE id = ?", [$acc['password_hash'], (int)$u['id']]);
                $u['password'] = $acc['password_hash'];
            }
            return $u;
        }
        $u = db_one("SELECT * FROM users WHERE LOWER(email) = ?", [mb_strtolower((string)$acc['email'])]);
        if (!$u) {
            if (empty($acc['password_hash'])) return null;
            $name = trim(($acc['first_name'] ?? '') . ' ' . ($acc['last_name'] ?? '')) ?: (string)$acc['email'];
            $id = (int)db_insert('users', [
                'name' => $name, 'first_name' => $acc['first_name'] ?: null, 'last_name' => $acc['last_name'] ?: null,
                'email' => mb_strtolower((string)$acc['email']), 'password' => $acc['password_hash'], 'role' => 'viewer',
                'is_active' => 1, 'portal_scope' => self::SCOPE, 'phone_number' => $acc['phone'] ?: null,
                'must_change_password' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $u = db_one("SELECT * FROM users WHERE id = ?", [$id]);
            try { require_once dirname(__DIR__, 3) . '/includes/auth_security.php'; authlog_write($id, 'account_created', (string)$acc['email'], 'Konto lokalne do podglądu oświadczenia zleceniobiorcy'); } catch (\Throwable $e) {}
        }
        if ($u) db_exec("UPDATE zlec_osw_accounts SET user_id = ?, updated_at = ? WHERE id = ?", [(int)$u['id'], date('Y-m-d H:i:s'), (int)$acc['id']]);
        return $u ?: null;
    }

    /** Sesja SZO dla konta oświadczeń (zakres osw_only — require_login wpuszcza tylko do /zlecenie/konto). */
    private static function loginSzo(array $user): void
    {
        if ((int)($user['is_active'] ?? 0) !== 1) return;
        $cur = current_user();
        if ($cur && (int)$cur['id'] === (int)$user['id']) return;
        login_user($user);
        $_SESSION['zlec_osw_acc'] = ['id' => (int)(db_one("SELECT id FROM zlec_osw_accounts WHERE user_id = ?", [(int)$user['id']])['id'] ?? 0), 'at' => time()];
    }

    /** Czy konto ma jeszcze zakres „tylko oświadczenia” (nieprzepięte na Microsoft 365). */
    public static function isLimited(array $acc): bool
    {
        if (empty($acc['user_id'])) return true;
        $u = db_one("SELECT portal_scope FROM users WHERE id = ?", [(int)$acc['user_id']]);
        return !$u || ($u['portal_scope'] ?? '') === self::SCOPE;
    }

    /**
     * Przepięcie na konto służbowe Microsoft 365 (pracownik Fundacji). Gdy w SZO jest już konto z tym e-mailem —
     * oświadczenia przechodzą do niego, a lokalne konto „tylko oświadczenia” zostaje wyłączone. Inaczej lokalne konto
     * dostaje e-mail służbowy (logowanie wyłącznie przez Microsoft 365, hasło lokalne usunięte) i pełny zakres panelu.
     * @return string błąd albo ''
     */
    public static function linkWorkAccount(int $accId, string $workEmail, int $byUserId): string
    {
        $acc = self::get($accId);
        if (!$acc) return 'Nie znaleziono konta oświadczeń.';
        $work = mb_strtolower(trim($workEmail));
        if (!filter_var($work, FILTER_VALIDATE_EMAIL) || !str_ends_with($work, self::WORK_DOMAIN)) {
            return 'Podaj adres konta służbowego w domenie ' . self::WORK_DOMAIN . '.';
        }
        $local = self::ensureUser($acc);
        $existing = db_one("SELECT * FROM users WHERE LOWER(email) = ? OR LOWER(m365_login) = ?", [$work, $work]);
        $now = date('Y-m-d H:i:s');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($existing && (!$local || (int)$existing['id'] !== (int)$local['id'])) {
                db_exec("UPDATE zlec_osw_accounts SET user_id = ? WHERE id = ?", [(int)$existing['id'], $accId]);
                if ($local && ($local['portal_scope'] ?? '') === self::SCOPE) {
                    db_exec("UPDATE users SET is_active = 0 WHERE id = ?", [(int)$local['id']]);
                }
                $target = (int)$existing['id'];
            } elseif ($local) {
                db_exec("UPDATE users SET email = ?, m365_login = ?, password = NULL, portal_scope = NULL, must_change_password = 0 WHERE id = ?",
                    [$work, $work, (int)$local['id']]);
                $target = (int)$local['id'];
            } else {
                $pdo->rollBack();
                return 'Konto nie ma jeszcze potwierdzonego dostępu (link z e-maila nie został użyty).';
            }
            db_exec("UPDATE zlec_osw_accounts SET work_email = ?, work_linked_at = ?, work_linked_by = ?, updated_at = ? WHERE id = ?",
                [$work, $now, $byUserId ?: null, $now, $accId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        try {
            require_once dirname(__DIR__, 3) . '/includes/auth_security.php';
            authlog_write($target, 'account_linked_m365', $work, 'Konto oświadczeń ' . $acc['email'] . ' przepięte na konto służbowe przez #' . $byUserId);
        } catch (\Throwable $e) {}
        return '';
    }

    // ── Logowanie ─────────────────────────────────────────────────────────────

    /** Zwraca błąd albo ''. Komunikat nie zdradza, czy konto istnieje. */
    public static function login(string $email, string $pass, string $ip): string
    {
        if (!self::hit('login', $ip)) return 'Zbyt wiele prób logowania z tego adresu. Spróbuj ponownie za kilkanaście minut.';
        $acc = self::byEmail($email);
        $bad = 'Nieprawidłowy e-mail lub hasło.';
        if (!$acc || empty($acc['password_hash'])) { password_verify($pass, password_hash('brak-konta', PASSWORD_DEFAULT)); return $bad; }
        if (!empty($acc['work_email'])) return 'To konto przepięto na konto służbowe ' . $acc['work_email'] . ' — zaloguj się w SZO przez Microsoft 365.';
        if ($acc['locked_until'] && $acc['locked_until'] > date('Y-m-d H:i:s')) return 'Zbyt wiele nieudanych prób. Spróbuj ponownie za kilkanaście minut.';
        if (!password_verify($pass, (string)$acc['password_hash'])) {
            $f = (int)$acc['failed_logins'] + 1;
            db_exec("UPDATE zlec_osw_accounts SET failed_logins = ?, locked_until = ? WHERE id = ?",
                [$f >= 5 ? 0 : $f, $f >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, (int)$acc['id']]);
            return $bad;
        }
        if (password_needs_rehash((string)$acc['password_hash'], PASSWORD_DEFAULT)) {
            db_exec("UPDATE zlec_osw_accounts SET password_hash = ? WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), (int)$acc['id']]);
        }
        self::startSession((int)$acc['id']);
        $user = self::ensureUser((array)self::get((int)$acc['id']));
        if ($user && ($user['portal_scope'] ?? '') === self::SCOPE) self::loginSzo($user);
        return '';
    }

    private static function startSession(int $id): void
    {
        auth_start();
        session_regenerate_id(true);
        $_SESSION['zlec_osw_acc'] = ['id' => $id, 'at' => time()];
        db_exec("UPDATE zlec_osw_accounts SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
    }

    /** Zalogowane konto oświadczeń albo null. */
    public static function current(): ?array
    {
        auth_start();
        $u = current_user();
        if ($u) {
            OswService::init();
            $acc = db_one("SELECT * FROM zlec_osw_accounts WHERE user_id = ? ORDER BY id LIMIT 1", [(int)$u['id']]);
            if ($acc) return $acc;
        }
        $s = $_SESSION['zlec_osw_acc'] ?? null;
        if (!$s) return null;
        if (time() - (int)$s['at'] > self::IDLE) { unset($_SESSION['zlec_osw_acc']); return null; }
        $_SESSION['zlec_osw_acc']['at'] = time();
        return self::get((int)$s['id']);
    }

    public static function logout(): void
    {
        auth_start();
        unset($_SESSION['zlec_osw_acc']);
        if (($u = current_user()) && ($u['portal_scope'] ?? '') === self::SCOPE) logout_user();
        session_regenerate_id(true);
    }

    /** „Nie pamiętam hasła” — link resetu (2 h), jeśli konto ma hasło; odpowiedź dla użytkownika zawsze ta sama. */
    public static function requestReset(string $email, string $ip): void
    {
        if (!self::hit('reset', $ip)) return;
        $acc = self::byEmail($email);
        if (!$acc || empty($acc['password_hash'])) return;
        $t = self::issueToken((int)$acc['id'], 'reset', null, self::RESET_TTL);
        self::mail($acc, 'reset', self::url('/konto/potwierdz', ['t' => $t]));
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    public static function url(string $path, array $q = []): string
    {
        return OswService::url($path, $q);
    }

    /** E-mail z linkiem (setup — nowe konto, attach — dodanie oświadczenia do konta z hasłem, reset — nowe hasło). */
    private static function mail(array $acc, string $kind, string $url): void
    {
        $org = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
        [$subject, $lead, $btn, $ttl] = match ($kind) {
            'setup'  => ['Twoje konto z oświadczeniem — ustaw hasło', 'otrzymaliśmy skan Twojego oświadczenia. Założyliśmy dla Ciebie <strong>konto</strong>, na którym zobaczysz oświadczenie, jego status i ponownie je wydrukujesz.', 'Ustaw hasło', '7 dni'],
            'attach' => ['Dodaj oświadczenie do swojego konta', 'otrzymaliśmy skan oświadczenia złożonego z tym adresem e-mail. Potwierdź, że to Ty — oświadczenie pojawi się na Twoim koncie.', 'Potwierdzam — dodaj do konta', '7 dni'],
            default  => ['Nowe hasło do konta z oświadczeniem', 'poproszono o ustawienie nowego hasła do Twojego konta z oświadczeniem.', 'Ustaw nowe hasło', '2 godziny'],
        };
        $body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#111827">'
              . '<div style="background:#0f172a;color:#fff;padding:16px 20px;font-size:18px;font-weight:bold">' . h($subject) . '</div>'
              . '<div style="border:1px solid #cbd5e1;border-top:0;padding:20px;line-height:1.55"><p>Dzień dobry,</p><p>' . $lead . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . h($url) . '" style="background:#1d4ed8;color:#fff;padding:12px 22px;text-decoration:none;font-weight:bold">' . h($btn) . '</a></p>'
              . '<p style="font-size:13px;color:#475569">Login: ' . h((string)$acc['email']) . '. Link jest ważny ' . $ttl . ' i działa jeden raz. Jeśli przycisk nie działa, skopiuj adres do przeglądarki:<br>' . h($url) . '</p>'
              . '<p style="font-size:13px;color:#475569">Jeśli to nie Ty, zignoruj tę wiadomość — bez kliknięcia linku nic się nie zmieni.</p></div>'
              . '<p style="font-size:12px;color:#64748b">' . h($org) . ' · wiadomość wysłana automatycznie.</p></div>';
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add((string)$acc['email'], trim(($acc['first_name'] ?? '') . ' ' . ($acc['last_name'] ?? '')), $subject, $body, '', 'zlec_osw_account', (int)$acc['id']);
        } catch (\Throwable $e) { error_log('[zlecenie_oswiadczenie] mail konta: ' . $e->getMessage()); }
    }
}
