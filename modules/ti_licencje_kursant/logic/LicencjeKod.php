<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeKod.php — dane kontaktowe i kody jednorazowe panelu licencji (polecenie 2026-11-09).
 *
 * • Pierwsze wejście do panelu licencji: kto nie ma w kartotece e-maila albo numeru telefonu, musi je podać; numer potwierdza kodem z SMS-a.
 *   Dopiero potem panel pozwala na operacje. Kto ma oba — przechodzi od razu (zapis „dane z kartoteki”).
 * • Każda operacja (włączenie licencji, rezygnacja, dane do faktury, konto @edu, hasło, decyzja o zmianie ceny) wymaga kodu:
 *   SMS na numer z kartoteki; gdy SMS nie wyjdzie — e-mail. Kod: 6 cyfr, ważny 10 minut, 5 prób, ponowna wysyłka po 60 s.
 * • Konto wirtualne (testowe, is_virtual) — nic nie wychodzi, kod pokazujemy na ekranie.
 * Kody trzymamy w sesji panelu (szo_licencje) jako skrót SHA-256 — nigdy otwartym tekstem.
 */
final class LicencjeKod
{
    public const TTL = 600;
    public const TRIES = 5;
    public const RESEND = 60;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('license_panel_contacts_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS license_panel_contacts (client_id INTEGER PRIMARY KEY, email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
            confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, via TEXT NOT NULL DEFAULT '')");
    }

    public static function validEmail(string $e): bool { return (bool)filter_var(trim($e), FILTER_VALIDATE_EMAIL); }

    /** Polski numer komórkowy (9 cyfr, opcjonalnie +48) albo zagraniczny w formacie +XX… (8–15 cyfr). */
    public static function validPhone(string $p): bool
    {
        $t = trim($p);
        $d = preg_replace('/\D/', '', $t);
        if (str_starts_with($t, '+') && !str_starts_with($d, '48')) return strlen($d) >= 8 && strlen($d) <= 15;
        if (strlen($d) === 11 && str_starts_with($d, '48')) $d = substr($d, 2);
        return strlen($d) === 9 && preg_match('/^[4-8]/', $d) === 1;
    }

    public static function normPhone(string $p): string
    {
        $t = trim($p);
        $d = preg_replace('/\D/', '', $t);
        if (strlen($d) === 11 && str_starts_with($d, '48')) $d = substr($d, 2);
        if (strlen($d) === 9) return substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6);
        return '+' . $d;
    }

    /** Numer zamaskowany do komunikatu: „*** *** 789”. */
    public static function mask(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        return $d === '' ? '' : '*** *** ' . substr($d, -3);
    }

    public static function maskEmail(string $e): string
    {
        [$u, $dom] = array_pad(explode('@', trim($e), 2), 2, '');
        return $dom === '' ? '' : mb_substr($u, 0, 2) . str_repeat('•', max(1, mb_strlen($u) - 2)) . '@' . $dom;
    }

    /** E-mail i telefon z kartoteki. @return array{email:string,phone:string,confirmed:?string} */
    public static function contact(int $cid): array
    {
        self::migrate();
        $c = db_one("SELECT email, phone FROM k30_clients WHERE id=?", [$cid]) ?: [];
        $r = db_one("SELECT confirmed_at FROM license_panel_contacts WHERE client_id=?", [$cid]);
        return ['email' => trim((string)($c['email'] ?? '')), 'phone' => trim((string)($c['phone'] ?? '')), 'confirmed' => $r['confirmed_at'] ?? null];
    }

    /** Czy przed operacjami trzeba uzupełnić dane (brak e-maila albo telefonu i jeszcze nie potwierdzono). Komplet z kartoteki zapisuje się sam. */
    public static function needsContact(int $cid): bool
    {
        $c = self::contact($cid);
        $ok = self::validEmail($c['email']) && self::validPhone($c['phone']);
        if ($c['confirmed'] !== null) return !$ok;   // potwierdzone, ale ktoś później wyczyścił kartotekę — znowu prosimy
        if (!$ok) return true;
        self::confirm($cid, $c['email'], $c['phone'], 'dane z kartoteki');
        return false;
    }

    public static function confirm(int $cid, string $email, string $phone, string $via): void
    {
        self::migrate();
        db_exec("UPDATE k30_clients SET email=?, phone=? WHERE id=?", [trim($email), self::normPhone($phone), $cid]);
        db_exec("INSERT INTO license_panel_contacts (client_id, email, phone, confirmed_at, via) VALUES (?,?,?,datetime('now'),?)
                 ON CONFLICT(client_id) DO UPDATE SET email=excluded.email, phone=excluded.phone, confirmed_at=excluded.confirmed_at, via=excluded.via",
            [$cid, trim($email), self::normPhone($phone), $via]);
        if (function_exists('ti_virtual_contacts')) ti_virtual_contacts(true);
    }

    /** Konto wirtualne (testowe) — kodów nie wysyłamy, pokazujemy je na ekranie. */
    public static function isVirtual(int $cid): bool
    {
        try { return (bool)db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=? AND COALESCE(is_virtual,0)=1", [$cid]); } catch (\Throwable $e) { return false; }
    }

    /**
     * Wysyła kod do celu $purpose (np. 'op', 'contact', 'price:12'). $phone / $email — dokąd; najpierw SMS, potem e-mail.
     * @return array{ok:bool,channel:string,to:string,shown:?string,error:string}
     */
    public static function send(string $purpose, int $cid, string $phone, string $email, string $what): array
    {
        $prev = $_SESSION['lk_otp'][$purpose] ?? null;
        if ($prev && time() - (int)$prev['sent'] < self::RESEND && ($prev['what'] ?? '') === $what) {
            return ['ok' => true, 'channel' => $prev['channel'], 'to' => $prev['to'], 'shown' => $prev['shown'] ?? null, 'error' => '', 'again' => true];
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $slot = ['hash' => hash('sha256', $purpose . '|' . $code), 'exp' => time() + self::TTL, 'tries' => 0, 'sent' => time(), 'what' => $what, 'channel' => '', 'to' => '', 'shown' => null];
        $txt = 'FEER panel licencji: kod ' . $code . ' potwierdza: ' . $what . '. Wazny 10 min. Nikomu go nie podawaj.';
        if (self::isVirtual($cid)) {
            $slot['channel'] = 'ekran'; $slot['shown'] = $code;
        } else {
            if (self::validPhone($phone)) {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/sms.php';
                    $GLOBALS['__sms_quiet_bypass'] = true;   // kod jednorazowy wychodzi o każdej porze
                    $prov = sms_send($phone, function_exists('sms_ascii') ? sms_ascii($txt) : $txt, 'authorization_code');
                    unset($GLOBALS['__sms_quiet_bypass']);
                    if ($prov !== '') { $slot['channel'] = 'sms'; $slot['to'] = self::mask($phone); }
                } catch (\Throwable $e) { unset($GLOBALS['__sms_quiet_bypass']); error_log('lk otp sms: ' . $e->getMessage()); }
            }
            if ($slot['channel'] === '' && self::validEmail($email)) {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                    mail_queue_add(trim($email), '', 'Kod do panelu licencji: ' . $code,
                        '<p>Dzień dobry,</p><p>Twój kod: <b style="font-size:20px;letter-spacing:3px">' . $code . '</b></p><p>Potwierdza: ' . h($what) . '. Kod jest ważny 10 minut. Nikomu go nie podawaj. Pracownicy Fundacji nigdy o niego nie proszą.</p>'
                        . '<p style="color:#555;font-size:13px">Jeśli to nie Ty, zignoruj tę wiadomość i zmień hasło do panelu licencji.</p>',
                        '', 'license_panel_code', $cid, '', true);
                    $slot['channel'] = 'email'; $slot['to'] = self::maskEmail($email);
                } catch (\Throwable $e) { error_log('lk otp mail: ' . $e->getMessage()); }
            }
            if ($slot['channel'] === '') return ['ok' => false, 'channel' => '', 'to' => '', 'shown' => null, 'error' => 'Nie udało się wysłać kodu ani SMS-em, ani e-mailem. Spróbuj za kilka minut albo napisz do biura.'];
        }
        $_SESSION['lk_otp'][$purpose] = $slot;
        return ['ok' => true, 'channel' => $slot['channel'], 'to' => $slot['to'], 'shown' => $slot['shown'], 'error' => '', 'again' => false];
    }

    /** Sprawdza kod; poprawny — zużywa go. @return string '' = OK, inaczej komunikat błędu */
    public static function verify(string $purpose, string $code): string
    {
        $s = $_SESSION['lk_otp'][$purpose] ?? null;
        if (!$s) return 'Kod wygasł albo nie został wysłany. Wyślij nowy.';
        if (time() > (int)$s['exp']) { unset($_SESSION['lk_otp'][$purpose]); return 'Kod wygasł (ważny 10 minut). Wyślij nowy.'; }
        if ((int)$s['tries'] >= self::TRIES) { unset($_SESSION['lk_otp'][$purpose]); return 'Za dużo błędnych prób. Wyślij nowy kod.'; }
        $code = preg_replace('/\D/', '', $code);
        if (!hash_equals((string)$s['hash'], hash('sha256', $purpose . '|' . $code))) {
            $_SESSION['lk_otp'][$purpose]['tries'] = (int)$s['tries'] + 1;
            $left = self::TRIES - (int)$s['tries'] - 1;
            return 'Nieprawidłowy kod.' . ($left > 0 ? ' Zostały próby: ' . $left . '.' : ' Wyślij nowy kod.');
        }
        unset($_SESSION['lk_otp'][$purpose]);
        return '';
    }

    public static function pending(string $purpose): ?array
    {
        return $_SESSION['lk_otp'][$purpose] ?? null;
    }
}
