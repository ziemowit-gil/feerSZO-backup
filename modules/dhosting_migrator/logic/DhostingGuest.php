<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingGuest.php — wejście na /migracjapoczty bez logowania do SZO i dopisanie
 * brakującego numeru komórkowego do SZO (polecenie 2026-11-13).
 *
 * Zestaw danych: 1) prywatny e-mail albo PESEL, 2) adres w domenie feer.org.pl (login Microsoft 365, którego skrzynkę
 * przenosimy), 3) kod SMS na numer z SZO. Osobę szukamy w umowach (wolontariat, zlecenie, dzieło: m365_login + PESEL
 * albo e-mail z umowy) i w kontach SZO (users: m365_login/e-mail + alt_email/e-mail/rpts_pesel).
 *
 * Brak numeru komórkowego: osoba podaje numer i dostaje na niego kod SMS, a gdy znamy jej prywatny e-mail — także
 * drugi kod e-mailem (żeby ktoś, kto zna tylko PESEL albo e-mail i adres, nie podpiął swojego telefonu i nie dostał hasła
 * do cudzej skrzynki). Po potwierdzeniu numer trafia do SZO: users.phone_number (dotychczasowy numer stacjonarny →
 * alt_phone_number), a bez konta SZO — telefon w umowie wolontariackiej. Zalogowany bez numeru dopisuje go kodem SMS.
 *
 * Sesja gościa: 30 min, tylko ta jedna skrzynka (login Microsoft = potwierdzony adres). Limity: 10 prób / IP / h
 * i 5 / adres / h (login_attempts), kod ważny 10 min, 5 błędnych kodów = od nowa, najwyżej 3 wysyłki kodu.
 * Komunikat przy braku dopasowania jest zawsze ten sam (nie zdradza, która dana się nie zgadza).
 */
require_once __DIR__ . '/DhostingMigrator.php';
require_once dirname(__DIR__, 3) . '/modules/field_crypto/lib/fcDb.php';

final class DhostingGuest
{
    public const TTL = 1800;
    private const CODE_TTL = 600;
    private const CODE_FAILS = 5;
    private const MAX_SENDS = 3;
    private const RATE_IP = 10;
    private const RATE_ADDR = 5;
    private const K_FLOW  = 'dhmGuestFlow';   // w trakcie: osoba, etap, skróty kodów
    private const K_AUTH  = 'dhmGuest';       // po potwierdzeniu
    private const K_PHONE = 'dhmPhoneFlow';   // zalogowany dopisuje numer
    /** Umowy z loginem Microsoft 365: tabela => czy ma kolumnę telefon. */
    private const CONTRACTS = ['umowy_wolontariat' => true, 'umowy_zlecenie' => false, 'umowy_dzielo' => false];
    public const NO_MATCH = 'Nie znaleźliśmy osoby z takim zestawem danych. Sprawdź, czy wpisujesz prywatny e-mail albo PESEL '
        . 'podany w umowie z Fundacją i swój adres w domenie feer.org.pl. Jeśli dalej się nie udaje, napisz do Helpdesku IT.';

    // ── Sesja gościa ──────────────────────────────────────────────────────────────────────────

    /** Potwierdzony gość (user_id, name, address, phone, m365_id, via, until) albo null. */
    public static function current(): ?array
    {
        $g = $_SESSION[self::K_AUTH] ?? null;
        if (!is_array($g) || (int)($g['until'] ?? 0) < time()) { unset($_SESSION[self::K_AUTH]); return null; }
        return $g;
    }

    public static function end(): void
    {
        unset($_SESSION[self::K_AUTH], $_SESSION[self::K_FLOW]);
    }

    /** Etap wejścia: ident | phone | code (+ dane do widoku, bez kodów). */
    public static function flow(): array
    {
        $f = is_array($_SESSION[self::K_FLOW] ?? null) ? $_SESSION[self::K_FLOW] : ['step' => 'ident'];
        if (($f['step'] ?? '') !== 'ident' && empty($f['p'])) $f = ['step' => 'ident'];
        return [
            'step'      => (string)$f['step'],
            'address'   => (string)($f['p']['address'] ?? ''),
            'phoneMask' => !empty($f['phone']) ? DhostingMigrator::maskPhone((string)$f['phone']) : '',
            'mailMask'  => !empty($f['mail']) ? self::maskEmail((string)$f['p']['private_email']) : '',
            'privMask'  => !empty($f['p']['private_email']) ? self::maskEmail((string)$f['p']['private_email']) : '',
            'sends'     => (int)($f['sends'] ?? 0),
        ];
    }

    public static function restart(): void
    {
        unset($_SESSION[self::K_FLOW]);
    }

    /** Wiersz „users” dla kontrolera: konto SZO osoby (świeżo z bazy) albo zastępczy z umowy; login Microsoft = potwierdzony adres. */
    public static function userRow(array $g): array
    {
        $row = !empty($g['user_id'])
            ? db_one("SELECT id, name, email, phone_number, microsoft_id, m365_login FROM users WHERE id=?", [(int)$g['user_id']])
            : null;
        $row = $row ?: ['id' => 0, 'name' => (string)$g['name'], 'email' => (string)$g['address'], 'phone_number' => '',
                        'microsoft_id' => (string)($g['m365_id'] ?? '')];
        $row['m365_login'] = (string)$g['address'];
        if (!sms_is_mobile((string)($row['phone_number'] ?? ''))) $row['phone_number'] = (string)$g['phone'];
        return $row;
    }

    // ── Krok 1: dane ──────────────────────────────────────────────────────────────────────────

    public static function start(string $ident, string $address): void
    {
        if (!DhostingConfig::selfEnabled()) throw new RuntimeException('Migracja poczty jest teraz wyłączona. Skontaktuj się z administratorem.');
        $ident = trim($ident);
        $address = strtolower(trim($address));
        if ($ident === '' || $address === '') throw new RuntimeException('Wypełnij oba pola.');
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || DhostingConfig::mailboxFor($address) === null) {
            throw new RuntimeException('W drugim polu wpisz swój adres służbowy w domenie feer.org.pl, np. imie.nazwisko@feer.org.pl.');
        }
        if (!self::rateOk($address)) throw new RuntimeException('Za dużo prób. Spróbuj ponownie za godzinę albo napisz do Helpdesku IT.');
        self::rateHit($address);

        $p = self::find($ident, $address);
        if (!$p) {
            self::audit('dhosting.guest_no_match', ['address' => $address]);
            throw new RuntimeException(self::NO_MATCH);
        }
        $flow = ['step' => 'phone', 'p' => $p, 'sends' => 0, 'newPhone' => false];
        if ($p['phone'] !== '') {
            $flow['step'] = 'code';
            $flow['phone'] = $p['phone'];
            self::sendCodes($flow);
        }
        $_SESSION[self::K_FLOW] = $flow;
    }

    /** Krok 2 (brak numeru w SZO): numer komórkowy osoby. */
    public static function setPhone(string $phone): void
    {
        $flow = $_SESSION[self::K_FLOW] ?? null;
        if (!is_array($flow) || ($flow['step'] ?? '') !== 'phone' || empty($flow['p'])) throw new RuntimeException('Zacznij od początku — wpisz swoje dane.');
        $phone = self::cleanPhone($phone);
        if (!sms_is_mobile($phone)) throw new RuntimeException('To nie wygląda na numer komórkowy. Wpisz 9 cyfr, np. 600 100 200.');
        $flow['phone'] = $phone;
        $flow['newPhone'] = true;
        $flow['step'] = 'code';
        self::sendCodes($flow);
        $_SESSION[self::K_FLOW] = $flow;
    }

    public static function resend(): void
    {
        $flow = $_SESSION[self::K_FLOW] ?? null;
        if (!is_array($flow) || ($flow['step'] ?? '') !== 'code') throw new RuntimeException('Zacznij od początku — wpisz swoje dane.');
        self::sendCodes($flow);
        $_SESSION[self::K_FLOW] = $flow;
    }

    /** Krok 3: kod SMS (i kod z e-maila, gdy numer jest nowy). Zwraca komunikat powitalny. */
    public static function verify(string $smsCode, string $mailCode): string
    {
        $flow = $_SESSION[self::K_FLOW] ?? null;
        if (!is_array($flow) || ($flow['step'] ?? '') !== 'code' || empty($flow['p'])) throw new RuntimeException('Zacznij od początku — wpisz swoje dane.');
        if ((int)$flow['exp'] < time()) throw new RuntimeException('Kod wygasł. Kliknij „Wyślij kod ponownie”.');
        $salt = (string)($flow['salt'] ?? '');
        $ok = hash_equals((string)$flow['sms'], self::hash($smsCode, $salt))
            && (empty($flow['mail']) || hash_equals((string)$flow['mail'], self::hash($mailCode, $salt)));
        if (!$ok) {
            $flow['fails'] = (int)$flow['fails'] + 1;
            if ($flow['fails'] >= self::CODE_FAILS) {
                self::restart();
                self::audit('dhosting.guest_code_blocked', ['address' => $flow['p']['address']]);
                throw new RuntimeException('Za dużo błędnych kodów. Zacznij od początku.');
            }
            $_SESSION[self::K_FLOW] = $flow;
            throw new RuntimeException('Kod się nie zgadza. Sprawdź i wpisz ponownie (zostało prób: ' . (self::CODE_FAILS - $flow['fails']) . ').');
        }
        $p = $flow['p'];
        $saved = '';
        if (!empty($flow['newPhone'])) $saved = self::savePhone((int)$p['user_id'], $p['contract'] ?? null, (string)$flow['phone']);
        unset($_SESSION[self::K_FLOW]);
        if (function_exists('session_regenerate_id')) @session_regenerate_id(true);
        $_SESSION[self::K_AUTH] = [
            'user_id' => (int)$p['user_id'], 'name' => (string)$p['name'], 'address' => (string)$p['address'],
            'phone' => (string)$flow['phone'], 'm365_id' => (string)$p['m365_id'], 'via' => (string)$p['via'], 'until' => time() + self::TTL,
        ];
        self::audit('dhosting.guest_login', ['address' => $p['address'], 'via' => $p['via'], 'new_phone' => !empty($flow['newPhone'])]);
        DhostingHelpdesk::event((string)DhostingConfig::mailboxFor((string)$p['address']),
            'Wejście na stronę migracji bez logowania do SZO: ' . ($p['via'] === 'pesel' ? 'PESEL' : 'prywatny e-mail') . ' + adres służbowy + kod SMS'
            . (!empty($flow['mail']) ? ' + kod z prywatnego e-maila' : '') . '.'
            . ($saved !== '' ? ' ' . $saved : ''),
            ['user_id' => (int)$p['user_id'], 'name' => (string)$p['name'], 'login' => (string)$p['address']]);
        return 'Potwierdzone. Możesz przenieść swoją pocztę' . ($saved !== '' ? ' — numer telefonu zapisaliśmy w SZO' : '') . '.';
    }

    // ── Zalogowany bez numeru komórkowego ─────────────────────────────────────────────────────

    /** Stan dopisywania numeru (do widoku): null albo ['phoneMask' => …]. */
    public static function phoneFlow(int $userId): ?array
    {
        $f = $_SESSION[self::K_PHONE] ?? null;
        if (!is_array($f) || (int)$f['user_id'] !== $userId) return null;
        return ['phoneMask' => DhostingMigrator::maskPhone((string)$f['phone'])];
    }

    public static function phoneSend(array $u, string $phone): void
    {
        $phone = self::cleanPhone($phone);
        if (!sms_is_mobile($phone)) throw new RuntimeException('To nie wygląda na numer komórkowy. Wpisz 9 cyfr, np. 600 100 200.');
        $f = $_SESSION[self::K_PHONE] ?? null;
        $sends = is_array($f) && (int)$f['user_id'] === (int)$u['id'] ? (int)$f['sends'] : 0;
        if ($sends >= self::MAX_SENDS) throw new RuntimeException('Wysłaliśmy już ' . self::MAX_SENDS . ' kody. Spróbuj później albo napisz do Helpdesku IT.');
        $code = self::newCode();
        self::sms($phone, $code);
        $salt = bin2hex(random_bytes(8));
        $_SESSION[self::K_PHONE] = ['user_id' => (int)$u['id'], 'phone' => $phone, 'salt' => $salt, 'code' => self::hash($code, $salt),
                                    'exp' => time() + self::CODE_TTL, 'fails' => 0, 'sends' => $sends + 1];
    }

    public static function phoneVerify(array $u, string $code): string
    {
        $f = $_SESSION[self::K_PHONE] ?? null;
        if (!is_array($f) || (int)$f['user_id'] !== (int)$u['id']) throw new RuntimeException('Najpierw podaj numer telefonu.');
        if ((int)$f['exp'] < time()) { unset($_SESSION[self::K_PHONE]); throw new RuntimeException('Kod wygasł. Podaj numer jeszcze raz.'); }
        if (!hash_equals((string)$f['code'], self::hash($code, (string)($f['salt'] ?? '')))) {
            $f['fails'] = (int)$f['fails'] + 1;
            if ($f['fails'] >= self::CODE_FAILS) { unset($_SESSION[self::K_PHONE]); throw new RuntimeException('Za dużo błędnych kodów. Podaj numer jeszcze raz.'); }
            $_SESSION[self::K_PHONE] = $f;
            throw new RuntimeException('Kod się nie zgadza (zostało prób: ' . (self::CODE_FAILS - $f['fails']) . ').');
        }
        unset($_SESSION[self::K_PHONE]);
        $note = self::savePhone((int)$u['id'], null, (string)$f['phone']);
        $login = DhostingMigrator::loginForUser($u);
        $box = $login !== '' ? DhostingConfig::mailboxFor($login) : null;
        if ($box) DhostingHelpdesk::event($box, 'Użytkownik (zalogowany) potwierdził kodem SMS nowy numer telefonu. ' . $note,
            ['user_id' => (int)$u['id'], 'name' => (string)($u['name'] ?? ''), 'login' => $login]);
        return 'Zapisaliśmy numer ' . DhostingMigrator::maskPhone((string)$f['phone']) . ' w SZO. Teraz możesz założyć skrzynkę.';
    }

    public static function phoneCancel(): void
    {
        unset($_SESSION[self::K_PHONE]);
    }

    // ── Dopasowanie osoby ─────────────────────────────────────────────────────────────────────

    /**
     * Osoba z tym adresem służbowym, której PESEL albo prywatny e-mail się zgadza — albo null.
     * Zwraca: user_id (0 = bez konta SZO), name, address, m365_id, phone (komórkowy albo ''), private_email, via, contract.
     */
    public static function find(string $ident, string $address): ?array
    {
        $address = strtolower(trim($address));
        $digits = preg_replace('/\D/', '', $ident);
        $isPesel = !str_contains($ident, '@') && strlen($digits) === 11;
        $email = $isPesel ? '' : strtolower(trim($ident));
        if (!$isPesel) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('W pierwszym polu wpisz prywatny e-mail albo PESEL (11 cyfr).');
            if ($email === $address || DhostingConfig::mailboxFor($email) !== null) {
                throw new RuntimeException('W pierwszym polu wpisz prywatny e-mail (nie służbowy) albo PESEL.');
            }
        }

        // 1) Umowy z tym loginem Microsoft 365
        $person = null;
        foreach (self::CONTRACTS as $t => $hasPhone) {
            $cols = 'id, imie_nazwisko, email, m365_user_id' . ($hasPhone ? ', telefon' : '');
            try {
                if ($isPesel) {
                    [$w, $pp] = fcMatchPesel($t, 'pesel', $digits);
                    $r = db_one("SELECT {$cols} FROM {$t} WHERE LOWER(TRIM(m365_login))=? AND {$w} ORDER BY id DESC LIMIT 1", [$address, ...$pp]);
                } else {
                    $r = db_one("SELECT {$cols} FROM {$t} WHERE LOWER(TRIM(m365_login))=? AND LOWER(TRIM(email))=? ORDER BY id DESC LIMIT 1", [$address, $email]);
                }
            } catch (\Throwable $e) {
                error_log('[dhosting] guest find ' . $t . ': ' . $e->getMessage());
                $r = null;
            }
            if ($r) {
                $person = ['name' => (string)$r['imie_nazwisko'], 'contract' => ['table' => $t, 'id' => (int)$r['id']],
                           'phone' => $hasPhone ? (string)($r['telefon'] ?? '') : '', 'm365_id' => (string)($r['m365_user_id'] ?? ''),
                           'email' => (string)($r['email'] ?? '')];
                break;
            }
        }

        // 2) Konto SZO z tym adresem (gdy umowa już potwierdziła osobę — to samo konto; inaczej e-mail/PESEL w koncie)
        $users = db_all("SELECT id, name, email, alt_email, phone_number, microsoft_id FROM users
                         WHERE is_active=1 AND (LOWER(TRIM(m365_login))=? OR LOWER(TRIM(email))=?) ORDER BY id", [$address, $address]);
        if (!$users && $person && $person['m365_id'] !== '') {
            $users = db_all("SELECT id, name, email, alt_email, phone_number, microsoft_id FROM users WHERE is_active=1 AND microsoft_id=?", [$person['m365_id']]);
        }
        $user = null;
        foreach ($users as $u) {
            if ($person) { $user = $u; break; }
            if ($isPesel) {
                [$w, $pp] = fcMatchPesel('users', 'rpts_pesel', $digits);
                try { if (db_one("SELECT id FROM users WHERE id=? AND {$w}", [(int)$u['id'], ...$pp])) { $user = $u; break; } }
                catch (\Throwable $e) { error_log('[dhosting] guest find users: ' . $e->getMessage()); }
            } else {
                foreach ([(string)$u['alt_email'], (string)$u['email']] as $e) {
                    if ($e !== '' && strtolower(trim($e)) === $email) { $user = $u; break 2; }
                }
            }
        }
        if (!$person && !$user) return null;

        // Prywatny e-mail (na drugi kod przy nowym numerze): podany albo z umowy/konta — nigdy adres w przenoszonej domenie
        $priv = $email;
        if ($priv === '') {
            foreach ([(string)($person['email'] ?? ''), (string)($user['alt_email'] ?? ''), (string)($user['email'] ?? '')] as $e) {
                $e = strtolower(trim($e));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && DhostingConfig::mailboxFor($e) === null) { $priv = $e; break; }
            }
        }
        $phone = '';
        foreach ([(string)($user['phone_number'] ?? ''), (string)($person['phone'] ?? '')] as $ph) {
            if ($ph !== '' && sms_is_mobile($ph)) { $phone = $ph; break; }
        }
        return [
            'user_id' => $user ? (int)$user['id'] : 0,
            'name' => (string)(($user['name'] ?? '') ?: ($person['name'] ?? '')),
            'address' => $address,
            'm365_id' => (string)(($user['microsoft_id'] ?? '') ?: ($person['m365_id'] ?? '')),
            'phone' => $phone,
            'private_email' => $priv,
            'via' => $isPesel ? 'pesel' : 'email',
            'contract' => $person['contract'] ?? null,
        ];
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────────────────────

    private static function sendCodes(array &$flow): void
    {
        if ((int)($flow['sends'] ?? 0) >= self::MAX_SENDS) {
            throw new RuntimeException('Wysłaliśmy już ' . self::MAX_SENDS . ' kody. Zacznij od początku za chwilę albo napisz do Helpdesku IT.');
        }
        $code = self::newCode();
        self::sms((string)$flow['phone'], $code);
        $flow['salt'] = bin2hex(random_bytes(8));
        $flow['sms'] = self::hash($code, $flow['salt']);
        $flow['mail'] = null;
        $priv = (string)($flow['p']['private_email'] ?? '');
        if (!empty($flow['newPhone']) && $priv !== '') {
            $mc = self::newCode();
            self::mailCode($priv, (string)$flow['p']['name'], $mc);
            $flow['mail'] = self::hash($mc, $flow['salt']);
        }
        $flow['exp'] = time() + self::CODE_TTL;
        $flow['fails'] = 0;
        $flow['sends'] = (int)($flow['sends'] ?? 0) + 1;
    }

    private static function sms(string $phone, string $code): void
    {
        $org = function_exists('org_setting') ? (org_setting('org_short_name') ?: 'FEER') : 'FEER';
        $prev = $GLOBALS['__sms_quiet_bypass'] ?? null;
        $GLOBALS['__sms_quiet_bypass'] = true;   // kod na prośbę osoby — także w godzinach ciszy
        try {
            sms_send($phone, $org . ': kod potwierdzenia migracji poczty: ' . $code . '. Wazny 10 min. Nie podawaj go nikomu.', 'recovery_code');
        } catch (\Throwable $e) {
            throw new RuntimeException('Nie udało się wysłać SMS-a z kodem. Spróbuj za chwilę albo napisz do Helpdesku IT.');
        } finally {
            if ($prev === null) unset($GLOBALS['__sms_quiet_bypass']); else $GLOBALS['__sms_quiet_bypass'] = $prev;
        }
    }

    private static function mailCode(string $email, string $name, string $code): void
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $c = htmlspecialchars($code, ENT_QUOTES);
        $html = '<p>Dzień dobry,</p><p>ktoś (mamy nadzieję, że Ty) dodaje numer telefonu do migracji poczty w Fundacji. '
              . 'Kod potwierdzenia:</p><p style="font-size:24px;font-weight:700;letter-spacing:4px">' . $c . '</p>'
              . '<p>Kod jest ważny 10 minut. Jeśli to nie Ty — nie podawaj go nikomu i napisz do Helpdesku IT.</p>';
        $text = "Kod potwierdzenia migracji poczty: {$code}\nWażny 10 minut. Jeśli to nie Ty — nie podawaj go nikomu i napisz do Helpdesku IT.";
        $id = mail_queue_add($email, $name, 'Kod potwierdzenia migracji poczty', $html, $text, 'dhosting_guest', null, '', true);
        if (!$id) throw new RuntimeException('Nie udało się wysłać e-maila z kodem. Napisz do Helpdesku IT.');
    }

    /** Zapis numeru w SZO; zwraca opis dla notatki Helpdesku. */
    private static function savePhone(int $userId, ?array $contract, string $phone): string
    {
        $mask = DhostingMigrator::maskPhone($phone);
        if ($userId > 0) {
            $u = db_one("SELECT phone_number, alt_phone_number FROM users WHERE id=?", [$userId]);
            $old = (string)($u['phone_number'] ?? '');
            $set = ['phone_number' => $phone];
            if ($old !== '' && !sms_is_mobile($old) && (string)($u['alt_phone_number'] ?? '') === '') $set['alt_phone_number'] = $old;
            try { db_one("SELECT phone_verified_at FROM users LIMIT 1"); $set['phone_verified_at'] = date('Y-m-d H:i:s'); } catch (\Throwable $e) {}
            $sql = implode(', ', array_map(fn($k) => $k . '=?', array_keys($set)));
            db()->prepare("UPDATE users SET {$sql} WHERE id=?")->execute([...array_values($set), $userId]);
            self::audit('dhosting.phone_saved', ['user_id' => $userId, 'phone' => $mask]);
            return 'Numer ' . $mask . ' zapisany w koncie SZO' . (isset($set['alt_phone_number']) ? ' (dotychczasowy numer stacjonarny przeniesiony do numeru dodatkowego)' : '') . '.';
        }
        if ($contract && ($contract['table'] ?? '') === 'umowy_wolontariat') {
            db()->prepare("UPDATE umowy_wolontariat SET telefon=? WHERE id=?")->execute([$phone, (int)$contract['id']]);
            self::audit('dhosting.phone_saved', ['contract' => 'umowy_wolontariat#' . (int)$contract['id'], 'phone' => $mask]);
            return 'Numer ' . $mask . ' zapisany w umowie wolontariackiej (osoba bez konta SZO).';
        }
        return 'Numer ' . $mask . ' potwierdzony, ale osoba nie ma konta SZO ani umowy wolontariackiej — DOPISZ GO RĘCZNIE.';
    }

    private static function cleanPhone(string $phone): string
    {
        return (string)preg_replace('/[^\d+]/', '', trim($phone));
    }

    private static function newCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Skrót kodu z losową solą zapisaną w sesji (nie zależy od identyfikatora sesji). */
    private static function hash(string $code, string $salt): string
    {
        return hash('sha256', $salt . '|' . preg_replace('/\D/', '', $code));
    }

    public static function maskEmail(string $e): string
    {
        $at = strrpos($e, '@');
        if ($at === false || $at < 1) return '***';
        return mb_substr($e, 0, 1) . '***' . substr($e, $at);
    }

    private static function rateOk(string $address): bool
    {
        self::rateTable();
        $since = date('Y-m-d H:i:s', time() - 3600);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $c = fn(string $id) => (int)(db_one("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at>?", [$id, $since])['c'] ?? 0);
        return $c('DHMGUEST:ip:' . $ip) < self::RATE_IP && $c('DHMGUEST:addr:' . $address) < self::RATE_ADDR;
    }

    private static function rateHit(string $address): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $now = date('Y-m-d H:i:s');
        try {
            $q = db()->prepare("INSERT INTO login_attempts (identifier, ip, created_at) VALUES (?, ?, ?)");
            $q->execute(['DHMGUEST:ip:' . $ip, $ip, $now]);
            $q->execute(['DHMGUEST:addr:' . $address, $ip, $now]);
        } catch (\Throwable $e) { error_log('[dhosting] guest rate: ' . $e->getMessage()); }
    }

    private static function rateTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL,
                ip TEXT NOT NULL DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        } catch (\Throwable $e) {}
    }

    private static function audit(string $event, array $data): void
    {
        try { if (function_exists('audit_log')) audit_log($event, $data); } catch (\Throwable $e) {}
    }
}
