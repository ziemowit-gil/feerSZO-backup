<?php
declare(strict_types=1);

/**
 * modules/ti_poczta_stud/logic/PocztaStud.php — „Poczta kursanta” w domenie stud.feer.org.pl (polecenie 2026-11-13).
 *
 * Osobna usługa sdata.net.pl, niezależna od Microsoft 365 (kont EDU i wyłącznika poczty Office) — w interfejsie nie wspominamy o M365.
 * Webmail: https://titan.sdata.net.pl/.
 * System nie zakłada skrzynek: login i hasło wpisuje administrator — w Panelu Licencji (pulpit Administratora Licencji)
 * albo w Ewidencji Uczestników panelu TI (kierownik). Oba miejsca zapisują to samo konto (jedno na uczestnika, po client_id).
 *
 * Hasło trzymamy zaszyfrowane (fcEncrypt, kontekst stud_mail_accounts.password_enc) — kadra go nie odczytuje, tylko nadpisuje.
 * Uczestnik widzi login i link do webmaila; hasło odsłania kodem jednorazowym (SMS, potem e-mail — LicencjeKod),
 * w panelu kursanta i w Panelu Licencji. Odsłonięcie zapisuje revealed_at.
 */
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';

final class PocztaStud
{
    public const DOMAIN  = 'stud.feer.org.pl';
    public const WEBMAIL = 'https://titan.sdata.net.pl/';
    public const PROVIDER = 'sdata.net.pl';
    private const CTX = 'stud_mail_accounts.password_enc';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('poczta_stud_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS stud_mail_accounts (
            client_id INTEGER PRIMARY KEY,
            login TEXT NOT NULL DEFAULT '', password_enc TEXT NOT NULL DEFAULT '',
            password_set_at DATETIME, updated_at DATETIME, updated_by TEXT NOT NULL DEFAULT '', revealed_at DATETIME)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_stud_mail_login ON stud_mail_accounts(login)");
    }

    /** Login z pola formularza: „jan.kowalski” albo „jan.kowalski@stud.feer.org.pl” → pełny adres małymi literami. Wyjątek przy innej domenie. */
    public static function normalizeLogin(string $in): string
    {
        $l = mb_strtolower(trim($in));
        if ($l === '') throw new InvalidArgumentException('Wpisz login poczty.');
        if (!str_contains($l, '@')) $l .= '@' . self::DOMAIN;
        if (!filter_var($l, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Login poczty musi być adresem e-mail, np. jan.kowalski@' . self::DOMAIN . '.');
        if (substr($l, strrpos($l, '@') + 1) !== self::DOMAIN) throw new InvalidArgumentException('Login poczty musi być w domenie @' . self::DOMAIN . '.');
        return $l;
    }

    /** Konto uczestnika bez hasła: login, czy hasło jest wpisane, daty, kto zmienił. null = brak konta. */
    public static function get(int $cid): ?array
    {
        if ($cid <= 0) return null;
        self::migrate();
        $r = db_one("SELECT client_id, login, password_enc, password_set_at, updated_at, updated_by, revealed_at FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if (!$r || trim((string)$r['login']) === '') return null;
        $r['hasPassword'] = (string)$r['password_enc'] !== '';
        unset($r['password_enc']);
        return $r;
    }

    /**
     * Zapis konta przez kadrę. Puste hasło = bez zmiany hasła (przy nowym koncie: konto bez hasła, uczestnik zobaczy „hasło przekaże biuro”).
     * @return string komunikat dla kadry
     */
    public static function save(int $cid, string $login, string $password, string $by): string
    {
        if ($cid <= 0) throw new InvalidArgumentException('Nie wskazano uczestnika.');
        self::migrate();
        $login = self::normalizeLogin($login);
        $password = trim($password);
        if ($password !== '' && mb_strlen($password) > 200) throw new InvalidArgumentException('Hasło jest za długie.');
        $other = db_one("SELECT client_id FROM stud_mail_accounts WHERE login=? AND client_id<>?", [$login, $cid]);
        if ($other) throw new InvalidArgumentException('Adres ' . $login . ' ma już inny uczestnik (nr ' . (int)$other['client_id'] . ').');
        $now = date('Y-m-d H:i:s');
        $by = mb_substr(trim($by) !== '' ? trim($by) : 'kadra', 0, 150);
        $was = db_one("SELECT client_id, login FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if ($was) {
            if ($password !== '') db_exec("UPDATE stud_mail_accounts SET login=?, password_enc=?, password_set_at=?, updated_at=?, updated_by=? WHERE client_id=?",
                                          [$login, (string)fcEncrypt($password, self::CTX), $now, $now, $by, $cid]);
            else db_exec("UPDATE stud_mail_accounts SET login=?, updated_at=?, updated_by=? WHERE client_id=?", [$login, $now, $by, $cid]);
        } else {
            db_exec("INSERT INTO stud_mail_accounts (client_id, login, password_enc, password_set_at, updated_at, updated_by) VALUES (?,?,?,?,?,?)",
                    [$cid, $login, $password !== '' ? (string)fcEncrypt($password, self::CTX) : '', $password !== '' ? $now : null, $now, $by]);
        }
        self::log($cid, 'stud_mail_save', 'Poczta ' . $login . ': ' . ($was ? 'zmieniono' : 'dodano') . ($password !== '' ? ' (z hasłem)' : '') . ', ' . $by . '.');
        return 'Zapisano pocztę ' . $login . ($password !== '' ? ' z hasłem.' : ($was ? ', hasło bez zmian.' : ' bez hasła.'));
    }

    /** Odłączenie konta od uczestnika (skrzynki w sdata.net.pl nie usuwa). */
    public static function remove(int $cid, string $by): string
    {
        $r = self::get($cid);
        if (!$r) return 'Ten uczestnik nie ma wpisanej poczty.';
        db_exec("DELETE FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        self::log($cid, 'stud_mail_remove', 'Usunięto wpis poczty ' . $r['login'] . ', ' . $by . '.');
        return 'Usunięto wpis poczty ' . $r['login'] . '. Skrzynka w ' . self::PROVIDER . ' zostaje bez zmian.';
    }

    /** Hasło do pokazania uczestnikowi PO potwierdzeniu kodem (wołający to sprawdza). '' = brak hasła albo nie da się odszyfrować. */
    public static function revealPassword(int $cid, string $via): string
    {
        self::migrate();
        $r = db_one("SELECT login, password_enc FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if (!$r || (string)$r['password_enc'] === '') return '';
        $p = (string)(fcDecrypt((string)$r['password_enc'], self::CTX) ?? '');
        if ($p === '') return '';
        db_exec("UPDATE stud_mail_accounts SET revealed_at=? WHERE client_id=?", [date('Y-m-d H:i:s'), $cid]);
        self::log($cid, 'stud_mail_reveal', 'Uczestnik odsłonił hasło do poczty ' . $r['login'] . ' (' . $via . ').');
        return $p;
    }

    /** Wpis w dzienniku konta TI uczestnika (Aktywność konta), jeśli ma konto w panelu. */
    private static function log(int $cid, string $action, string $text): void
    {
        try {
            if (!function_exists('ti_account_log')) require_once dirname(__DIR__, 3) . '/includes/ti_messages.php';
            $sid = (int)(db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$cid])['id'] ?? 0);
            if ($sid > 0 && function_exists('ti_account_log')) ti_account_log($sid, $action, $text);
        } catch (\Throwable $e) { error_log('poczta stud log: ' . $e->getMessage()); }
    }
}
