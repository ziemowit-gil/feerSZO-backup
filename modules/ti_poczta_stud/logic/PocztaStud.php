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
 * Zabezpieczenie hasła pierwszego logowania (2026-11-13, „zabezpiecz to”):
 *   • w bazie tylko zaszyfrowane (fcEncrypt, kontekst stud_mail_accounts.password_enc) — kadra go nie odczytuje, tylko nadpisuje;
 *   • uczestnik odsłania je kodem jednorazowym (SMS, potem e-mail — LicencjeKod), najwyżej MAX_REVEALS razy;
 *   • hasło startowe żyje TTL_DAYS dni — potem znika z bazy; znika też, gdy uczestnik potwierdzi, że ustawił własne (confirmChanged);
 *   • po kodzie hasło czeka na wyświetlenie w sesji ZASZYFROWANE (sessionPut / sessionTake), najwyżej 5 minut, i jest pokazywane raz;
 *   • zapis, usunięcie, odsłonięcie i wyczyszczenie trafiają do dziennika konta uczestnika i do audit_logs (bez hasła).
 */
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';

final class PocztaStud
{
    public const DOMAIN  = 'stud.feer.org.pl';
    public const WEBMAIL = 'https://titan.sdata.net.pl/';
    public const PROVIDER = 'sdata.net.pl';
    /** Ile dni hasło startowe czeka w panelu na odebranie. */
    public const TTL_DAYS = 14;
    /** Ile razy uczestnik może odsłonić hasło startowe (każde odsłonięcie po kodzie). */
    public const MAX_REVEALS = 5;
    /** Najkrótsze hasło startowe, które przyjmujemy od kadry. */
    public const MIN_PASSWORD = 8;
    private const CTX = 'stud_mail_accounts.password_enc';
    private const CTX_SESSION = 'stud_mail_accounts.session';
    private const SESSION_TTL = 300;

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
        $have = [];
        try {
            $have = DB_TYPE === 'sqlite'
                ? array_column(db()->query("PRAGMA table_info(stud_mail_accounts)")->fetchAll(PDO::FETCH_ASSOC), 'name')
                : db()->query("SHOW COLUMNS FROM stud_mail_accounts")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {}
        foreach (['password_expires_at' => 'DATETIME', 'reveal_count' => 'INTEGER NOT NULL DEFAULT 0',
                  'password_cleared_at' => 'DATETIME', 'password_cleared_why' => "TEXT NOT NULL DEFAULT ''"] as $col => $def) {
            if (!in_array($col, $have, true)) {
                try { db()->exec("ALTER TABLE stud_mail_accounts ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
            }
        }
        // Hasła wpisane przed wprowadzeniem ważności: liczymy ją od dnia wpisania.
        try {
            foreach (db_all("SELECT client_id, password_set_at, updated_at FROM stud_mail_accounts WHERE password_enc <> '' AND password_expires_at IS NULL") as $r) {
                $from = strtotime((string)($r['password_set_at'] ?: $r['updated_at'] ?: 'now')) ?: time();
                db_exec("UPDATE stud_mail_accounts SET password_expires_at=? WHERE client_id=?", [date('Y-m-d H:i:s', $from + self::TTL_DAYS * 86400), (int)$r['client_id']]);
            }
        } catch (\Throwable $e) {}
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

    /**
     * Konto uczestnika bez hasła. null = brak konta. Hasło po terminie ważności jest tu usuwane z bazy.
     * Pola: login, hasPassword, canReveal, revealsLeft, blockReason, password_expires_at, reveal_count,
     *       password_cleared_at, password_cleared_why, updated_at, updated_by, revealed_at.
     */
    public static function get(int $cid): ?array
    {
        if ($cid <= 0) return null;
        self::migrate();
        $r = db_one("SELECT * FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if (!$r || trim((string)$r['login']) === '') return null;
        if ((string)$r['password_enc'] !== '' && !empty($r['password_expires_at']) && strtotime((string)$r['password_expires_at']) < time()) {
            self::clear($cid, 'minął termin ważności (' . self::TTL_DAYS . ' dni)', 'stud_mail_expired');
            $r = db_one("SELECT * FROM stud_mail_accounts WHERE client_id=?", [$cid]) ?: $r;
        }
        $r['hasPassword'] = (string)$r['password_enc'] !== '';
        unset($r['password_enc']);
        $r['reveal_count'] = (int)($r['reveal_count'] ?? 0);
        $r['revealsLeft'] = max(0, self::MAX_REVEALS - $r['reveal_count']);
        $r['blockReason'] = !$r['hasPassword'] ? '' : ($r['revealsLeft'] === 0
            ? 'Hasło startowe pokazaliśmy już ' . self::MAX_REVEALS . ' razy. Jeśli go nie masz, napisz do biura przez Pomoc — wpiszemy nowe.'
            : '');
        $r['canReveal'] = $r['hasPassword'] && $r['blockReason'] === '';
        return $r;
    }

    /**
     * Zapis konta przez kadrę. Puste hasło = bez zmiany hasła (przy nowym koncie: konto bez hasła, uczestnik zobaczy „hasło przekaże biuro”).
     * Nowe hasło zeruje licznik odsłonięć i liczy od nowa termin ważności.
     * @return string komunikat dla kadry
     */
    public static function save(int $cid, string $login, string $password, string $by): string
    {
        if ($cid <= 0) throw new InvalidArgumentException('Nie wskazano uczestnika.');
        self::migrate();
        $login = self::normalizeLogin($login);
        $password = trim($password);
        if ($password !== '' && mb_strlen($password) > 200) throw new InvalidArgumentException('Hasło jest za długie.');
        if ($password !== '' && mb_strlen($password) < self::MIN_PASSWORD) throw new InvalidArgumentException('Hasło startowe musi mieć co najmniej ' . self::MIN_PASSWORD . ' znaków.');
        $other = db_one("SELECT client_id FROM stud_mail_accounts WHERE login=? AND client_id<>?", [$login, $cid]);
        if ($other) throw new InvalidArgumentException('Adres ' . $login . ' ma już inny uczestnik (nr ' . (int)$other['client_id'] . ').');
        $now = date('Y-m-d H:i:s');
        $exp = date('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400);
        $by = mb_substr(trim($by) !== '' ? trim($by) : 'kadra', 0, 150);
        $was = db_one("SELECT client_id, login FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if ($was) {
            if ($password !== '') db_exec("UPDATE stud_mail_accounts SET login=?, password_enc=?, password_set_at=?, password_expires_at=?, reveal_count=0, revealed_at=NULL,
                                                  password_cleared_at=NULL, password_cleared_why='', updated_at=?, updated_by=? WHERE client_id=?",
                                          [$login, (string)fcEncrypt($password, self::CTX), $now, $exp, $now, $by, $cid]);
            else db_exec("UPDATE stud_mail_accounts SET login=?, updated_at=?, updated_by=? WHERE client_id=?", [$login, $now, $by, $cid]);
        } else {
            db_exec("INSERT INTO stud_mail_accounts (client_id, login, password_enc, password_set_at, password_expires_at, updated_at, updated_by) VALUES (?,?,?,?,?,?,?)",
                    [$cid, $login, $password !== '' ? (string)fcEncrypt($password, self::CTX) : '', $password !== '' ? $now : null, $password !== '' ? $exp : null, $now, $by]);
        }
        self::log($cid, 'stud_mail_save', 'Poczta ' . $login . ': ' . ($was ? 'zmieniono' : 'dodano') . ($password !== '' ? ' (z hasłem startowym ważnym do ' . date('d.m.Y', strtotime($exp)) . ')' : '') . ', ' . $by . '.');
        return 'Zapisano pocztę ' . $login . ($password !== ''
            ? ' z hasłem startowym. Uczestnik odsłoni je kodem SMS do ' . date('d.m.Y', strtotime($exp)) . ' (najwyżej ' . self::MAX_REVEALS . ' razy).'
            : ($was ? ', hasło bez zmian.' : ' bez hasła.'));
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

    /**
     * Hasło do pokazania uczestnikowi PO potwierdzeniu kodem (wołający to sprawdza). '' = brak hasła, wygasło, wyczerpany limit
     * albo nie da się odszyfrować — przed wysłaniem kodu sprawdź get()['canReveal'].
     */
    public static function revealPassword(int $cid, string $via): string
    {
        $acc = self::get($cid);   // także czyści hasło po terminie
        if (!$acc || !$acc['canReveal']) return '';
        $r = db_one("SELECT login, password_enc FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if (!$r || (string)$r['password_enc'] === '') return '';
        $p = (string)(fcDecrypt((string)$r['password_enc'], self::CTX) ?? '');
        if ($p === '') return '';
        db_exec("UPDATE stud_mail_accounts SET revealed_at=?, reveal_count=COALESCE(reveal_count,0)+1 WHERE client_id=?", [date('Y-m-d H:i:s'), $cid]);
        $t = 'Uczestnik odsłonił hasło startowe do poczty ' . $r['login'] . ' (' . $via . ', ' . ($acc['reveal_count'] + 1) . '/' . self::MAX_REVEALS . ').';
        self::log($cid, 'stud_mail_reveal', $t);
        return $p;
    }

    /** Uczestnik potwierdza, że zalogował się i ustawił własne hasło — hasło startowe znika z bazy. */
    public static function confirmChanged(int $cid, string $via): string
    {
        $acc = self::get($cid);
        if (!$acc || !$acc['hasPassword']) return 'W panelu nie ma już hasła startowego.';
        self::clear($cid, 'uczestnik potwierdził zmianę hasła (' . $via . ')', 'stud_mail_confirmed');
        return 'Gotowe. Usunęliśmy hasło startowe z panelu. Do poczty logujesz się teraz własnym hasłem.';
    }

    /** Opis stanu hasła dla kadry (Ewidencja Uczestników, Panel Licencji). */
    public static function staffStatus(?array $acc): string
    {
        if (!$acc) return '';
        if ($acc['hasPassword']) {
            $t = 'Hasło startowe czeka w panelu do ' . date('d.m.Y', strtotime((string)$acc['password_expires_at']));
            $t .= $acc['reveal_count'] > 0
                ? '; uczestnik odsłonił je ' . $acc['reveal_count'] . '/' . self::MAX_REVEALS . ' (ostatnio ' . date('d.m.Y H:i', strtotime((string)$acc['revealed_at'])) . ')'
                : '; uczestnik jeszcze go nie odsłonił';
            return $t . '.';
        }
        if (!empty($acc['password_cleared_at'])) {
            return 'Hasło startowe usunięte z panelu ' . date('d.m.Y H:i', strtotime((string)$acc['password_cleared_at'])) . ': ' . $acc['password_cleared_why'] . '.';
        }
        return 'Bez hasła startowego.';
    }

    // ── Hasło w sesji między potwierdzeniem kodu a wyświetleniem (tylko zaszyfrowane, 5 minut, jeden odczyt) ──

    /** Wartość do zapisania w $_SESSION zamiast jawnego hasła. */
    public static function sessionPut(string $password): array
    {
        return ['c' => (string)fcEncrypt($password, self::CTX_SESSION), 'at' => time()];
    }

    /** Hasło z wartości sessionPut ('' = brak albo minęło 5 minut). Wołający usuwa wpis z sesji. */
    public static function sessionTake(mixed $slot): string
    {
        if (!is_array($slot) || empty($slot['c']) || time() - (int)($slot['at'] ?? 0) >= self::SESSION_TTL) return '';
        return (string)(fcDecrypt((string)$slot['c'], self::CTX_SESSION) ?? '');
    }

    /** Nagłówki dla strony, która może pokazać hasło: bez zapisu w pamięci podręcznej przeglądarki i pośredników. */
    public static function noStoreHeaders(): void
    {
        if (headers_sent()) return;
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
    }

    // ── Wewnętrzne ──

    /** Usuwa hasło startowe z bazy (zostaje login), z powodem w dzienniku. */
    private static function clear(int $cid, string $why, string $action): void
    {
        db_exec("UPDATE stud_mail_accounts SET password_enc='', password_cleared_at=?, password_cleared_why=? WHERE client_id=? AND password_enc<>''",
                [date('Y-m-d H:i:s'), mb_substr($why, 0, 200), $cid]);
        self::log($cid, $action, 'Hasło startowe do poczty usunięte z panelu: ' . $why . '.');
    }

    /** Wpis w dzienniku konta TI uczestnika (Aktywność konta), jeśli ma konto w panelu, oraz w audit_logs. */
    private static function log(int $cid, string $action, string $text): void
    {
        try {
            if (!function_exists('ti_account_log')) require_once dirname(__DIR__, 3) . '/includes/ti_messages.php';
            $sid = (int)(db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$cid])['id'] ?? 0);
            if ($sid > 0 && function_exists('ti_account_log')) ti_account_log($sid, $action, $text);
        } catch (\Throwable $e) { error_log('poczta stud log: ' . $e->getMessage()); }
        self::audit(str_replace('stud_mail_', 'stud_mail.', $action), ['kursant' => $cid, 'opis' => $text]);
    }

    private static function audit(string $action, array $details): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($action, $details);
        } catch (\Throwable $e) { error_log('poczta stud audit: ' . $e->getMessage()); }
    }
}
