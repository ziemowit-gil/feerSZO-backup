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
 *
 * Eksport XLSX dla kierownika (currentParticipants / suggestLogin): aktualni uczestnicy z loginem
 * pierwszaliteraimienia.nazwisko@stud.feer.org.pl (wpisany w SZO albo proponowany, zbieżności z cyfrą).
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
    /** Kategoria Helpdesku zgłoszeń kont poczty kursanta (prefiks numeru MIG-ST — includes/helpdesk.php). */
    public const HD_CATEGORY = 'it_poczta_kursanta';
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
                  'password_cleared_at' => 'DATETIME', 'password_cleared_why' => "TEXT NOT NULL DEFAULT ''",
                  'helpdesk_ticket_id' => 'INTEGER'] as $col => $def) {
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
    public static function save(int $cid, string $login, string $password, string $by, int $byUserId = 0): string
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
        $what = 'Poczta ' . $login . ': ' . ($was ? 'zmieniono' : 'dodano') . ($password !== '' ? ' (z hasłem startowym ważnym do ' . date('d.m.Y', strtotime($exp)) . ')' : '') . ', ' . $by . '.';
        self::log($cid, 'stud_mail_save', $what);
        self::helpdesk($cid, $what, $byUserId, $by);   // nowe konto → nowe zgłoszenie MIG-ST (zgłaszający = kadra); zmiana → notatka
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
        self::helpdesk($cid, $t);
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

    /**
     * Dane logowania do uczestnika (polecenie 2026-11-13): HASŁO SMS-em (rodzaj access_data), a e-mailem TEKSTOWYM (bez HTML)
     * login, adres logowania https://titan.sdata.net.pl/ i ustawienia programu pocztowego (IMAP/SMTP). Hasła w e-mailu nie ma.
     * $plainPassword — hasło świeżo wpisane w formularzu; '' = odszyfrowane z bazy (ponowne wysłanie). Numer: telefon z kartoteki,
     * a gdy go brak — telefon opiekuna z konta TI; e-mail tak samo. @return string komunikat dla kadry
     */
    public static function sendLoginData(int $cid, string $by, int $byUserId = 0, string $plainPassword = ''): string
    {
        $acc = self::get($cid);
        if (!$acc) throw new InvalidArgumentException('Ten uczestnik nie ma wpisanej poczty.');
        $cl = db_one("SELECT name, email, phone FROM k30_clients WHERE id=?", [$cid]) ?: [];
        $sa = db_one("SELECT guardian_email, guardian_phone FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$cid]) ?: [];
        $to = trim((string)($cl['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || self::isStudDomain($to)) $to = trim((string)($sa['guardian_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || self::isStudDomain($to)) $to = '';
        $phone = trim((string)($cl['phone'] ?? ''));
        if (preg_replace('/\D/', '', $phone) === '') $phone = trim((string)($sa['guardian_phone'] ?? ''));
        $hasPhone = strlen((string)preg_replace('/\D/', '', $phone)) >= 9;
        if ($to === '' && !$hasPhone) throw new InvalidArgumentException('Uczestnik nie ma w kartotece ani e-maila, ani telefonu — uzupełnij dane kontaktowe.');

        // 1. SMS z hasłem (najpierw — treść e-maila mówi, czy hasło wyszło)
        $smsInfo = '';
        $smsSent = false;
        if ($acc['hasPassword']) {
            $pw = $plainPassword !== '' ? $plainPassword : self::storedPassword($cid);
            if ($pw === '') $smsInfo = 'hasła nie da się odczytać — wpisz je ponownie';
            elseif (!$hasPhone) $smsInfo = 'brak telefonu — hasło uczestnik odsłoni w panelu kodem przysłanym e-mailem';
            else {
                // Cisza nocna (22–7, inc/smsQuietHours): odłożony SMS leżałby do rana w sms_deferred z hasłem otwartym tekstem.
                // Wysyłka ręczna kadry ($byUserId) wychodzi zawsze, jak inne ręczne wysyłki kierownika/administratora;
                // bez wskazanej osoby (CLI, CRON) w nocy SMS-a z hasłem nie wysyłamy wcale.
                require_once dirname(__DIR__, 3) . '/inc/smsQuietHours/smsQuietHours.php';
                if ($byUserId <= 0 && !smsQuietAllowed('access_data')) {
                    $smsInfo = 'SMS z hasłem NIE wyszedł: cisza nocna (22:00–7:00), a hasła nie odkładamy do kolejki — wyślij dane ponownie po 7:00';
                } else {
                    $txt = 'FEER: poczta kursanta ' . $acc['login'] . "\nHaslo: " . $pw . "\nHaslo obowiazuje od " . date('d.m.y H:i:s')
                         . "\nLogowanie: " . preg_replace('#^https?://|/$#', '', self::WEBMAIL);
                    $prov = '';
                    try {
                        require_once dirname(__DIR__, 3) . '/includes/sms.php';
                        if ($byUserId > 0) $GLOBALS['__sms_quiet_bypass'] = true;
                        $prov = sms_send($phone, function_exists('sms_ascii') ? sms_ascii($txt) : $txt, 'access_data');
                    } catch (\Throwable $e) { error_log('poczta stud sms: ' . $e->getMessage()); }
                    finally { unset($GLOBALS['__sms_quiet_bypass']); }
                    $smsSent = $prov !== '' && $prov !== 'odlozony';
                    $smsInfo = $smsSent ? 'hasło SMS-em na ' . self::maskPhone($phone) : 'SMS z hasłem NIE wyszedł (bramka SMS) — uczestnik odsłoni je w panelu';
                }
            }
        }

        // 2. E-mail tekstowy z loginem i adresem logowania
        $mailInfo = 'brak e-maila w kartotece';
        if ($to !== '') {
            [$first] = self::splitName((string)($cl['name'] ?? ''));
            $host = preg_replace('#^https?://|/$#', '', self::WEBMAIL);
            $l = [];
            $l[] = 'Dzień dobry' . ($first !== '' ? ' ' . $first : '') . ',';
            $l[] = '';
            $l[] = 'masz w Fundacji pocztę kursanta. Oto dane do logowania:';
            $l[] = '';
            $l[] = 'Login (adres e-mail): ' . $acc['login'];
            if (!$acc['hasPassword']) $l[] = 'Hasło: przekaże je biuro Fundacji.';
            elseif ($smsSent) $l[] = 'Hasło: wysłaliśmy je SMS-em na numer ' . self::maskPhone($phone) . '.';
            else $l[] = 'Hasło: zobaczysz je w panelu kursanta, w zakładce „Poczta kursanta”, po wpisaniu kodu jednorazowego.';
            $l[] = '';
            $l[] = 'Pocztę otworzysz w przeglądarce:';
            $l[] = self::WEBMAIL;
            $l[] = '';
            $l[] = 'Albo przez program pocztowy (np. Thunderbird, Outlook, poczta w telefonie) — wtedy podaj poniższe dane:';
            $l[] = 'Adres serwera poczty przychodzącej i wychodzącej: ' . $host;
            $l[] = 'Nazwa użytkownika: ' . $acc['login'];
            $l[] = 'Poczta przychodząca (IMAP): szyfrowanie SSL/TLS — tak, port 993';
            $l[] = 'Poczta wychodząca (SMTP): szyfrowanie SSL/TLS — tak, port 465';
            $l[] = '';
            if ($acc['hasPassword']) {
                $l[] = 'To hasło startowe. Po pierwszym logowaniu ustaw własne hasło. W panelu kursanta hasło startowe czeka do '
                     . date('d.m.Y', strtotime((string)$acc['password_expires_at'])) . ', potem znika.';
                $l[] = '';
            }
            $l[] = 'Nikomu nie podawaj hasła ani kodów z SMS-ów. Pracownicy Fundacji nigdy o nie nie proszą.';
            $l[] = 'Jeśli to nie Ty, zignoruj tę wiadomość.';
            $l[] = '';
            $l[] = 'Pozdrawiamy';
            $l[] = defined('ORG_NAME') ? ORG_NAME : 'Fundacja';
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            $id = mail_queue_add($to, (string)($cl['name'] ?? ''), 'Poczta kursanta — dane do logowania', '', implode("\n", $l), 'stud_mail_login', $cid);
            $mailInfo = $id > 0 ? 'e-mail z loginem na ' . (function_exists('fcMask') ? fcMask($to, 'email') : $to) : 'e-mail zablokowany (konto testowe)';
        }

        $summary = 'Dane logowania do poczty ' . $acc['login'] . ': ' . $mailInfo . ($smsInfo !== '' ? '; ' . $smsInfo : '') . ' (' . $by . ').';
        self::log($cid, 'stud_mail_sent', $summary);
        self::helpdesk($cid, $summary, $byUserId, $by);
        return 'Wysłano: ' . $mailInfo . ($smsInfo !== '' ? '; ' . $smsInfo : '') . '.';
    }

    /**
     * Zgłoszenie w Helpdesku dla konta poczty kursanta (kategoria „Poczta kursanta”, prefiks MIG-ST; polecenie 2026-11-13).
     * Każde konto ma jedno zgłoszenie, zakładane przy pierwszym zapisie przez kadrę — zgłaszający = kierownik / administrator,
     * który wpisał konto. Kolejne zdarzenia (hasło, wysyłka, odsłonięcie, potwierdzenie, wygaśnięcie) to notatki wewnętrzne.
     * Bez $byUserId (zdarzenia uczestnika, CRON) zgłoszenia nie zakładamy — tylko dopisujemy. Nigdy nie przerywa wołającego.
     */
    public static function helpdesk(int $cid, string $note, int $byUserId = 0, string $byName = ''): ?int
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            helpdesk_migrate();
            self::migrate();
            $row = db_one("SELECT login, helpdesk_ticket_id FROM stud_mail_accounts WHERE client_id=?", [$cid]);
            if (!$row) return null;
            $tid = (int)($row['helpdesk_ticket_id'] ?? 0);
            if ($tid > 0) {   // po scaleniu w Helpdesku — zgłoszenie docelowe
                $t = db_one("SELECT id, merged_into FROM helpdesk_tickets WHERE id=?", [$tid]);
                $guard = 0;
                while ($t && !empty($t['merged_into']) && $guard++ < 5) $t = db_one("SELECT id, merged_into FROM helpdesk_tickets WHERE id=?", [(int)$t['merged_into']]);
                $tid = $t ? (int)$t['id'] : 0;
            }
            if ($tid <= 0) {
                if ($byUserId <= 0) return null;
                $u = db_one("SELECT id, name, email FROM users WHERE id=?", [$byUserId]);
                if (!$u) return null;
                $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$cid]) ?: [];
                $desc = "Konto poczty kursanta w domenie @" . self::DOMAIN . " (dostawca " . self::PROVIDER . ").\n\n"
                      . 'Kursant: ' . trim((string)($cl['name'] ?? '')) . ' (nr ' . $cid . ")\n"
                      . 'Login: ' . $row['login'] . "\n"
                      . 'Logowanie: ' . self::WEBMAIL . "\n"
                      . 'Wpisał(a): ' . ($byName !== '' ? $byName : (string)$u['name']) . "\n\n"
                      . 'Hasła nie zapisujemy w zgłoszeniu. Przebieg (hasło startowe, wysyłka danych, odsłonięcie, zmiana hasła) dopisujemy tutaj.';
                $tid = hd_ticket_quick_create(['id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)$u['email']],
                    'Poczta kursanta: ' . $row['login'], self::hdPlain($desc), self::HD_CATEGORY, 'normalny', 'poczta_kursanta');
                db_exec("UPDATE stud_mail_accounts SET helpdesk_ticket_id=? WHERE client_id=?", [$tid, $cid]);
            }
            db_insert('helpdesk_messages', ['ticket_id' => $tid, 'user_id' => $byUserId > 0 ? $byUserId : null,
                'user_name' => $byName !== '' ? $byName : 'Poczta kursanta (system)', 'body' => self::hdPlain($note), 'is_internal' => 1]);
            db_exec("UPDATE helpdesk_tickets SET updated_at=? WHERE id=?", [date('Y-m-d H:i:s'), $tid]);
            return $tid;
        } catch (\Throwable $e) {
            error_log('poczta stud helpdesk ' . $cid . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Numer zgłoszenia Helpdesku konta (MIG-ST-…) albo ''. */
    public static function ticketNumber(?array $acc): string
    {
        $tid = (int)($acc['helpdesk_ticket_id'] ?? 0);
        if ($tid <= 0) return '';
        try { return (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$tid])['number'] ?? ''); } catch (\Throwable $e) { return ''; }
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

    // ── Eksport XLSX dla kierownika: aktualni uczestnicy i loginy pierwszaliteraimienia.nazwisko@stud.feer.org.pl ──

    /**
     * Imię i nazwisko z pola k30_clients.name („Jan Kowalski”, „Anna Maria Nowak-Kowalska”, „Jan Kowalski (TEST)”):
     * pierwsze słowo = imię, ostatnie = nazwisko. @return array{0:string,1:string}
     */
    public static function splitName(string $name): array
    {
        $n = trim((string)preg_replace(['/\([^)]*\)/u', '/\s+/u'], ['', ' '], $name));
        $parts = $n === '' ? [] : explode(' ', $n);
        if (count($parts) < 2) return [$parts[0] ?? '', ''];
        return [$parts[0], $parts[count($parts) - 1]];
    }

    /** Login „j.kowalski” (bez domeny) albo '' gdy brak imienia lub nazwiska. Polskie litery bez ogonków, tylko a-z 0-9 i „-”. */
    public static function suggestLocal(string $name): string
    {
        [$first, $last] = self::splitName($name);
        $ascii = fn(string $s): string => trim((string)preg_replace(['/[^a-z0-9-]+/', '/-{2,}/'], ['', '-'], mb_strtolower(strtr($s, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'a', 'Ö' => 'o', 'Ü' => 'u', 'é' => 'e', 'É' => 'e', 'á' => 'a', 'Á' => 'a',
        ]))), '-');
        $f = mb_substr($ascii($first), 0, 1);
        $l = $ascii($last);
        return ($f === '' || $l === '') ? '' : $f . '.' . $l;
    }

    /**
     * Aktualni uczestnicy: aktywny zapis w aktywnej, niezamkniętej grupie; bez kont wirtualnych (testowych).
     * @return list<array{client_id:int,name:string,groups:string,email:string,phone:string}>
     */
    public static function currentParticipants(): array
    {
        $today = date('Y-m-d');
        $rows = db_all(
            "SELECT cl.id AS client_id, cl.name, cl.email, cl.phone,
                    GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(c.group_code),''), c.name)) AS groups
               FROM k30_ti_enrollments e
               JOIN k30_ti_courses c ON c.id = e.course_id
               JOIN k30_clients cl   ON cl.id = e.client_id
              WHERE e.status = 'active'
                AND (e.end_date IS NULL OR e.end_date = '' OR e.end_date >= ?)
                AND COALESCE(c.is_active, 1) = 1 AND c.closed_at IS NULL
                AND COALESCE(c.status, 'active') = 'active'
                AND NOT EXISTS (SELECT 1 FROM k30_ti_student_accounts sa WHERE sa.client_id = cl.id AND COALESCE(sa.is_virtual, 0) = 1)
              GROUP BY cl.id, cl.name, cl.email, cl.phone",
            [$today]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['client_id' => (int)$r['client_id'], 'name' => trim((string)$r['name']), 'groups' => (string)($r['groups'] ?? ''),
                      'email' => trim((string)($r['email'] ?? '')), 'phone' => trim((string)($r['phone'] ?? ''))];
        }
        usort($out, function ($a, $b) {
            [$af, $al] = self::splitName($a['name']); [$bf, $bl] = self::splitName($b['name']);
            return strcoll(mb_strtolower($al . ' ' . $af), mb_strtolower($bl . ' ' . $bf));
        });
        return $out;
    }

    /**
     * Wiersze arkusza: login wpisany w SZO zostaje; reszta dostaje propozycję j.kowalski@stud.feer.org.pl,
     * a przy zbieżności (z wpisanymi albo w obrębie listy) kolejną cyfrę: j.kowalski2…
     * @return list<array{client_id:int,first:string,last:string,groups:string,email:string,phone:string,login:string,status:string}>
     */
    public static function exportRows(): array
    {
        self::migrate();
        $taken = [];
        $byClient = [];
        foreach (db_all("SELECT client_id, login FROM stud_mail_accounts WHERE login <> ''") as $a) {
            $taken[mb_strtolower((string)$a['login'])] = true;
            $byClient[(int)$a['client_id']] = (string)$a['login'];
        }
        $rows = [];
        foreach (self::currentParticipants() as $p) {
            [$first, $last] = self::splitName($p['name']);
            $status = '';
            if (isset($byClient[$p['client_id']])) {
                $login = $byClient[$p['client_id']];
                $status = 'wpisany w SZO';
            } elseif (($local = self::suggestLocal($p['name'])) === '') {
                $login = '';
                $status = 'brak imienia lub nazwiska w kartotece — uzupełnij ręcznie';
            } else {
                $login = $local . '@' . self::DOMAIN;
                for ($i = 2; isset($taken[$login]); $i++) $login = $local . $i . '@' . self::DOMAIN;
                $status = $login === $local . '@' . self::DOMAIN ? 'propozycja' : 'propozycja — zbieżność, dodano cyfrę';
                $taken[$login] = true;
            }
            $rows[] = ['client_id' => $p['client_id'], 'first' => $first, 'last' => $last, 'groups' => $p['groups'],
                       'email' => $p['email'], 'phone' => $p['phone'], 'login' => $login, 'status' => $status];
        }
        return $rows;
    }

    /** Wysyła plik XLSX (kończy żądanie). $by — kto pobrał (stopka arkusza i dziennik). */
    public static function exportXlsx(string $by): void
    {
        require_once dirname(__DIR__, 3) . '/includes/xlsx.php';
        $rows = self::exportRows();
        self::audit('stud_mail.export', ['wierszy' => count($rows), 'kto' => $by]);
        while (ob_get_level() > 0) ob_end_clean();
        $x = new XlsxWriter();
        $x->addSheet('Poczta kursanta');
        $x->writeRow(['Poczta kursanta — aktualni kursanci i loginy @' . self::DOMAIN], ['header', 'text']);
        $x->writeRow(['Aktywny zapis w aktywnej grupie, bez kont testowych. Login: pierwsza litera imienia, kropka, nazwisko (bez polskich znaków). Haseł w pliku nie ma.'], ['text']);
        $x->writeRow([]);
        $x->writeRow(['Lp.', 'Nr kursanta', 'Nazwisko', 'Imię', 'Grupy', 'Login poczty', 'Status loginu', 'E-mail kontaktowy', 'Telefon'], ['header', 'text']);
        foreach ($rows as $i => $r) {
            $x->writeRow([(string)($i + 1), (string)$r['client_id'], $r['last'], $r['first'], $r['groups'], $r['login'], $r['status'], $r['email'], $r['phone']], ['text']);
        }
        if (!$rows) $x->writeRow(['Brak aktualnych kursantów.'], ['text']);
        $x->writeRow([]);
        $x->writeRow(['Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by], ['text']);
        $x->output('poczta_kursanta_loginy_' . date('Ymd_Hi') . '.xlsx');
    }

    // ── Wewnętrzne ──

    /** Hasło startowe odszyfrowane z bazy (ponowna wysyłka SMS-em przez kadrę) albo ''. */
    private static function storedPassword(int $cid): string
    {
        $r = db_one("SELECT password_enc FROM stud_mail_accounts WHERE client_id=?", [$cid]);
        if (!$r || (string)$r['password_enc'] === '') return '';
        return (string)(fcDecrypt((string)$r['password_enc'], self::CTX) ?? '');
    }

    private static function isStudDomain(string $email): bool
    {
        return strtolower(substr($email, (int)strrpos($email, '@') + 1)) === self::DOMAIN;
    }

    private static function maskPhone(string $phone): string
    {
        $d = (string)preg_replace('/\D/', '', $phone);
        return strlen($d) >= 3 ? '••• ••• ' . substr($d, -3) : '•••';
    }

    /** Czysty tekst do Helpdesku — helpdesk/_detail.php traktuje treść z „<” jako HTML. */
    private static function hdPlain(string $s): string
    {
        return str_replace(['<', '>'], ['‹', '›'], trim($s));
    }

    /** Usuwa hasło startowe z bazy (zostaje login), z powodem w dzienniku. */
    private static function clear(int $cid, string $why, string $action): void
    {
        db_exec("UPDATE stud_mail_accounts SET password_enc='', password_cleared_at=?, password_cleared_why=? WHERE client_id=? AND password_enc<>''",
                [date('Y-m-d H:i:s'), mb_substr($why, 0, 200), $cid]);
        self::log($cid, $action, 'Hasło startowe do poczty usunięte z panelu: ' . $why . '.');
        self::helpdesk($cid, 'Hasło startowe usunięte z panelu: ' . $why . '.');
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
