<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/logic/NoweHasloAuth.php — kto zmienia hasło na /nowehaslo i kody SMS (polecenie 2026-11-13).
 *
 * Dwie drogi wejścia:
 *  • zalogowany do SZO — osoba z konta (prawdziwy użytkownik, nie kontekst administratora); numer komórkowy z konta;
 *  • bez logowania — jak DhostingGuest: prywatny e-mail albo PESEL + adres służbowy (umowy i konta SZO, DhostingGuest::find),
 *    potem kod SMS na numer z SZO. Odpowiedź jest zawsze ta sama („jeśli dane się zgadzają, wysłaliśmy kod”), a przy braku
 *    dopasowania albo numeru zapisujemy atrapę kodu bez SMS-a — strona nie zdradza, czy konto istnieje.
 *
 * Każda zmiana hasła wymaga ŚWIEŻEGO kodu SMS: kod jest jednorazowy (zużywa go NoweHaslo::change), następna zmiana = nowy kod.
 * Kod: 6 cyfr, ważny 10 min, 5 błędnych prób = koniec (gość zaczyna od nowa), ponowna wysyłka po 60 s.
 * Limity (login_attempts, NoweHasloLimit): wejście gościa 10 / IP / h i 5 / adres / h; SMS-y z kodem 5 / osobę / h;
 * gość najwyżej 3 kody w jednym wejściu. Kody w sesji tylko jako skrót SHA-256 z losową solą.
 * Sesja gościa: 30 min od potwierdzenia kodem, tylko ta jedna osoba.
 */
require_once dirname(__DIR__, 2) . '/dhosting_migrator/logic/DhostingGuest.php';
require_once dirname(__DIR__) . '/lib/NoweHasloLimit.php';

final class NoweHasloAuth
{
    public const TTL = 1800;
    public const CODE_TTL = 600;
    public const CODE_FAILS = 5;
    public const RESEND_AFTER = 60;
    private const GUEST_SENDS = 3;
    private const SMS_PER_HOUR = 5;
    private const RATE_IP = 10;
    private const RATE_ADDR = 5;
    private const K_GUEST = 'nhGuest';
    private const K_CODE  = 'nhCode';
    private const K_ADDR  = 'nhAddr';

    /** Atrapa w testach: fn(string $phone, string $message, string $kind): bool. */
    public static ?\Closure $sendSms = null;

    // ── Kto ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Stan wejścia:
     *  mode: 'user' (zalogowany) | 'guest' (bez logowania, po wpisaniu danych) | 'none' (nic — formularz danych);
     *  confirmed: czy osoba jest potwierdzona (zalogowany zawsze; gość po pierwszym dobrym kodzie);
     *  p: osoba (patrz person()) albo null (gość bez dopasowania — atrapa).
     */
    public static function state(): array
    {
        if (function_exists('current_user') && current_user()) {
            unset($_SESSION[self::K_GUEST]);
            return ['mode' => 'user', 'confirmed' => true, 'p' => self::userPerson()];
        }
        $g = $_SESSION[self::K_GUEST] ?? null;
        if (!is_array($g) || (int)($g['until'] ?? 0) < time()) {
            unset($_SESSION[self::K_GUEST]);
            return ['mode' => 'none', 'confirmed' => false, 'p' => null];
        }
        return ['mode' => 'guest', 'confirmed' => !empty($g['confirmed']), 'p' => is_array($g['p'] ?? null) ? $g['p'] : null];
    }

    /**
     * Osoba zalogowana: key (klucz do limitów i Helpdesku), user_id, name, email (konto SZO = wspólny login), address (adres
     * służbowy / login Microsoft albo ''), mailbox (?string), phone (komórkowy albo ''), contacts (adresy do e-maila o zmianie), via.
     */
    private static function userPerson(): array
    {
        $me = function_exists('ctx_real_user') ? (ctx_real_user() ?: current_user()) : current_user();
        $id = (int)($me['id'] ?? 0);
        $u = db_one("SELECT id, name, email, alt_email, phone_number, microsoft_id, m365_login FROM users WHERE id=?", [$id])
            ?: ['id' => $id, 'name' => (string)($me['name'] ?? ''), 'email' => (string)($me['email'] ?? ''), 'alt_email' => '', 'phone_number' => ''];
        // Adres służbowy (login Microsoft) — raz na sesję, bo bez m365_login sięga do Microsoft Graph
        $cache = $_SESSION[self::K_ADDR] ?? null;
        if (is_array($cache) && (int)$cache['id'] === $id) {
            $address = (string)$cache['address'];
        } else {
            try { $address = DhostingMigrator::loginForUser($u); } catch (\Throwable $e) { $address = ''; }
            $_SESSION[self::K_ADDR] = ['id' => $id, 'address' => $address];
        }
        $phone = (string)($u['phone_number'] ?? '');
        return self::person($id, (string)$u['name'], (string)$u['email'], $address, sms_is_mobile($phone) ? $phone : '',
                            [(string)$u['email'], (string)($u['alt_email'] ?? '')], 'szo');
    }

    private static function person(int $userId, string $name, string $email, string $address, string $phone, array $contacts, string $via): array
    {
        $email = strtolower(trim($email));
        $address = strtolower(trim($address));
        $box = $address !== '' ? DhostingConfig::mailboxFor($address) : null;
        $cs = [];
        foreach ($contacts as $c) {
            $c = strtolower(trim((string)$c));
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL) && !in_array($c, $cs, true)) $cs[] = $c;
        }
        return ['key' => $email !== '' ? $email : $address, 'user_id' => $userId, 'name' => $name, 'email' => $email,
                'address' => $address, 'mailbox' => $box, 'phone' => $phone, 'contacts' => $cs, 'via' => $via];
    }

    // ── Gość: dane ────────────────────────────────────────────────────────────────────────────

    /** Krok 1 bez logowania: prywatny e-mail albo PESEL + adres służbowy. Zawsze kończy się „wysłaliśmy kod, jeśli…”. */
    public static function guestStart(string $ident, string $address): void
    {
        $ident = trim($ident);
        $address = strtolower(trim($address));
        if ($ident === '' || $address === '') throw new RuntimeException('Wypełnij oba pola.');
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || DhostingConfig::mailboxFor($address) === null) {
            throw new RuntimeException('W drugim polu wpisz swój adres służbowy w domenie feer.org.pl, np. imie.nazwisko@feer.org.pl.');
        }
        $ip = NoweHasloLimit::ip();
        if (NoweHasloLimit::count('ip:' . $ip, 3600) >= self::RATE_IP || NoweHasloLimit::count('addr:' . $address, 3600) >= self::RATE_ADDR) {
            throw new RuntimeException('Za dużo prób. Spróbuj ponownie za godzinę albo napisz do Helpdesku IT.');
        }
        NoweHasloLimit::hit('ip:' . $ip);
        NoweHasloLimit::hit('addr:' . $address);

        $f = DhostingGuest::find($ident, $address);   // zły format pola → RuntimeException z podpowiedzią (nie zdradza konta)
        $p = null;
        if ($f && $f['phone'] !== '') {
            $email = '';
            if ((int)$f['user_id'] > 0) $email = (string)(db_one("SELECT email FROM users WHERE id=?", [(int)$f['user_id']])['email'] ?? '');
            if ($email === '' && !empty($f['contract'])) {
                try { $email = (string)(db_one("SELECT email FROM {$f['contract']['table']} WHERE id=?", [(int)$f['contract']['id']])['email'] ?? ''); }
                catch (\Throwable $e) { $email = ''; }
            }
            $p = self::person((int)$f['user_id'], (string)$f['name'], $email, $address, (string)$f['phone'],
                              [$email, (string)$f['private_email']], (string)$f['via']);
        } else {
            self::audit($f ? 'nowehaslo.guest_no_phone' : 'nowehaslo.guest_no_match', ['address' => $address]);
        }
        unset($_SESSION[self::K_CODE]);
        $_SESSION[self::K_GUEST] = ['p' => $p, 'address' => $address, 'confirmed' => false, 'sends' => 0, 'until' => time() + self::CODE_TTL * 2];
        self::sendCode();
    }

    public static function guestEnd(): void
    {
        unset($_SESSION[self::K_GUEST], $_SESSION[self::K_CODE]);
    }

    // ── Kod SMS ───────────────────────────────────────────────────────────────────────────────

    /** Wysyła (albo dla atrapy — udaje, że wysyła) kod do bieżącej osoby. */
    public static function sendCode(): void
    {
        $st = self::state();
        if ($st['mode'] === 'none') throw new RuntimeException('Zacznij od początku — wpisz swoje dane.');
        $p = $st['p'];
        if ($st['mode'] === 'user' && (!$p || $p['phone'] === '')) {
            throw new RuntimeException('W Twoim koncie SZO nie ma numeru komórkowego, więc nie możemy wysłać kodu. Napisz do Helpdesku IT.');
        }
        $who = self::who($st);
        $old = $_SESSION[self::K_CODE] ?? null;
        if (is_array($old) && $old['who'] === $who && time() - (int)$old['sentAt'] < self::RESEND_AFTER) {
            throw new RuntimeException('Kod wysłaliśmy przed chwilą. Nowy możesz zamówić za ' . (self::RESEND_AFTER - (time() - (int)$old['sentAt'])) . ' s.');
        }
        if ($st['mode'] === 'guest') {
            $g = $_SESSION[self::K_GUEST];
            if ((int)$g['sends'] >= self::GUEST_SENDS) throw new RuntimeException('Wysłaliśmy już ' . self::GUEST_SENDS . ' kody. Zacznij od początku za chwilę albo napisz do Helpdesku IT.');
            $_SESSION[self::K_GUEST]['sends'] = (int)$g['sends'] + 1;
        }
        $limitKey = 'sms:' . ($p['key'] ?? ('g:' . ($_SESSION[self::K_GUEST]['address'] ?? '')));
        if (NoweHasloLimit::count($limitKey, 3600) >= self::SMS_PER_HOUR) {
            throw new RuntimeException('Wysłaliśmy już kilka kodów w ostatniej godzinie. Spróbuj później albo napisz do Helpdesku IT.');
        }
        NoweHasloLimit::hit($limitKey);
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if ($p) self::sms($p['phone'], $code);       // atrapa (brak dopasowania) — bez SMS-a, kod nie istnieje dla nikogo
        $salt = bin2hex(random_bytes(8));
        $_SESSION[self::K_CODE] = ['who' => $who, 'salt' => $salt, 'hash' => $p ? self::hash($code, $salt) : bin2hex(random_bytes(32)),
                                   'exp' => time() + self::CODE_TTL, 'sentAt' => time(), 'fails' => 0];
        if ($st['mode'] === 'guest') $_SESSION[self::K_GUEST]['until'] = max((int)$_SESSION[self::K_GUEST]['until'], time() + self::CODE_TTL + 60);
    }

    /** Stan kodu do widoku: null albo ['exp' => ts, 'canResendIn' => s]. */
    public static function codeInfo(): ?array
    {
        $c = $_SESSION[self::K_CODE] ?? null;
        $st = self::state();
        if (!is_array($c) || $st['mode'] === 'none' || $c['who'] !== self::who($st) || (int)$c['exp'] < time()) return null;
        return ['exp' => (int)$c['exp'], 'canResendIn' => max(0, self::RESEND_AFTER - (time() - (int)$c['sentAt']))];
    }

    /**
     * Sprawdza kod. Gość po dobrym kodzie jest potwierdzony na TTL. Zwraca osobę (null tylko dla atrapy — tu nie dochodzi).
     * Kod zużywa dopiero consumeCode() po udanej zmianie: gdy zmiana się nie uda (np. hasło za proste), osoba poprawia hasło
     * i wysyła formularz z tym samym kodem (ważny 10 min). Błędny kod: komunikat z liczbą prób; po 5 — koniec (gość od nowa).
     */
    public static function checkCode(string $code): ?array
    {
        $st = self::state();
        $c = $_SESSION[self::K_CODE] ?? null;
        if ($st['mode'] === 'none') throw new RuntimeException('Zacznij od początku — wpisz swoje dane.');
        if (!is_array($c) || $c['who'] !== self::who($st)) throw new RuntimeException('Najpierw zamów kod SMS.');
        if ((int)$c['exp'] < time()) { unset($_SESSION[self::K_CODE]); throw new RuntimeException('Kod wygasł. Zamów nowy.'); }
        $digits = (string)preg_replace('/\D/', '', $code);
        if ($digits === '' || !hash_equals((string)$c['hash'], self::hash($digits, (string)$c['salt']))) {
            $c['fails'] = (int)$c['fails'] + 1;
            if ($c['fails'] >= self::CODE_FAILS) {
                unset($_SESSION[self::K_CODE]);
                self::audit('nowehaslo.code_blocked', ['who' => $c['who']]);
                if ($st['mode'] === 'guest') { self::guestEnd(); throw new RuntimeException('Za dużo błędnych kodów. Zacznij od początku.'); }
                throw new RuntimeException('Za dużo błędnych kodów. Zamów nowy kod.');
            }
            $_SESSION[self::K_CODE] = $c;
            throw new RuntimeException('Kod się nie zgadza. Sprawdź i wpisz ponownie (zostało prób: ' . (self::CODE_FAILS - $c['fails']) . ').');
        }
        if ((int)$c['fails'] > 0) { $c['fails'] = 0; $_SESSION[self::K_CODE] = $c; }
        if ($st['mode'] === 'guest') {
            if (empty($_SESSION[self::K_GUEST]['confirmed'])) {
                if (function_exists('session_regenerate_id')) @session_regenerate_id(true);
                self::audit('nowehaslo.guest_login', ['address' => $st['p']['address'] ?? '', 'via' => $st['p']['via'] ?? '']);
            }
            $_SESSION[self::K_GUEST]['confirmed'] = true;
            $_SESSION[self::K_GUEST]['until'] = time() + self::TTL;
        }
        return $st['p'];
    }

    /** Po udanej zmianie: kod przestaje działać (każda kolejna zmiana = nowy kod SMS). */
    public static function consumeCode(): void
    {
        unset($_SESSION[self::K_CODE]);
    }

    /** Nieudana zmiana z dobrym kodem (np. hasło odrzucone): po 5 takich próbach kod przestaje działać. */
    public static function changeFailed(): void
    {
        $c = $_SESSION[self::K_CODE] ?? null;
        if (!is_array($c)) return;
        $c['tries'] = (int)($c['tries'] ?? 0) + 1;
        if ($c['tries'] >= self::CODE_FAILS) unset($_SESSION[self::K_CODE]); else $_SESSION[self::K_CODE] = $c;
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────────────────────

    private static function who(array $st): string
    {
        return $st['mode'] === 'user' ? 'u:' . (int)($st['p']['user_id'] ?? 0) : 'g:' . (string)($_SESSION[self::K_GUEST]['address'] ?? '');
    }

    private static function sms(string $phone, string $code): void
    {
        $org = function_exists('org_setting') ? (org_setting('org_short_name') ?: 'FEER') : 'FEER';
        $msg = $org . ': kod do zmiany hasla: ' . $code . '. Wazny 10 min. Nie podawaj go nikomu - nikt z Fundacji o niego nie prosi.';
        if (self::$sendSms) {
            if (!(self::$sendSms)($phone, $msg, 'authorization_code')) throw new RuntimeException('Nie udało się wysłać SMS-a z kodem. Spróbuj za chwilę albo napisz do Helpdesku IT.');
            return;
        }
        $prev = $GLOBALS['__sms_quiet_bypass'] ?? null;
        $GLOBALS['__sms_quiet_bypass'] = true;   // kod na prośbę osoby — także w godzinach ciszy
        try {
            sms_send($phone, $msg, 'authorization_code');
        } catch (\Throwable $e) {
            error_log('[nowehaslo] SMS z kodem: ' . $e->getMessage());
            throw new RuntimeException('Nie udało się wysłać SMS-a z kodem. Spróbuj za chwilę albo napisz do Helpdesku IT.');
        } finally {
            if ($prev === null) unset($GLOBALS['__sms_quiet_bypass']); else $GLOBALS['__sms_quiet_bypass'] = $prev;
        }
    }

    private static function hash(string $code, string $salt): string
    {
        return hash('sha256', $salt . '|' . preg_replace('/\D/', '', $code));
    }

    private static function audit(string $event, array $data): void
    {
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($event, $data);
        } catch (\Throwable $e) {}
    }
}
