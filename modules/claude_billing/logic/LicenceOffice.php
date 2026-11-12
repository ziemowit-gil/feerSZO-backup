<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/LicenceOffice.php — konto Office uczestnika w tenancie EDU (polecenie 2026-11-10).
 *
 * Login konta Office jest zawsze taki sam: pierwsza litera imienia, kropka, nazwisko @edu.feer.org.pl (Jan Kowalski → j.kowalski@edu.feer.org.pl;
 * polskie znaki bez ogonków, nazwisko dwuczłonowe z łącznikiem). Gdy konto o tym loginie istnieje w tenancie EDU i należy do tej osoby
 * (ta sama nazwa wyświetlana), wiążemy je z uczestnikiem. Gdy go nie ma, zakładamy je: login idzie e-mailem, hasło tymczasowe SMS-em.
 * Konto o tym loginie należące do kogoś innego (inna nazwa albo przypisane w SZO innemu uczestnikowi): zakładamy z cyfrą (j.kowalski2…).
 *
 * Wynik trzymamy w license_office_accounts (stan, data sprawdzenia) i w k30_ti_student_accounts.ms_upn / ms_user_id (reszta TI widzi to konto).
 * Brak odpowiedzi tenanta (błąd, brak uprawnień) nigdy nie kończy się założeniem konta — tylko 404 znaczy „nie ma”.
 * Konto wirtualne (testowe): tylko symulacja, bez Microsoft Graph. Testy: LicenceOffice::$graph podmienia klienta Graph.
 */
require_once __DIR__ . '/claudeBilling.php';

final class LicenceOffice
{
    public const STATE = ['exists' => 'jest w tenancie', 'created' => 'założone', 'missing' => 'nie ma w tenancie', 'error' => 'nie udało się sprawdzić',
                          'unknown' => 'tenant EDU nieskonfigurowany', 'sim' => 'symulacja (konto testowe)'];
    /** Plany Microsoft 365 Education (porównanie w Panelu Licencji). A1 jest domyślny i w cenie; A3 i A5 to płatne produkty (ClaudeBilling::PRODUCTS office_a3 / office_a5). */
    public const PLANS = [
        'A1' => ['name' => 'Office 365 A1', 'product' => null, 'desktop' => false,
                 'features' => ['Word, Excel, PowerPoint i OneNote w przeglądarce i na telefonie', 'Outlook (poczta), Teams i OneDrive', 'bez aplikacji stacjonarnych na komputerze']],
        'A3' => ['name' => 'Microsoft 365 A3', 'product' => 'office_a3', 'desktop' => true,
                 'features' => ['wszystko z A1', 'aplikacje stacjonarne Office na komputerze: Word, Excel, PowerPoint, Outlook, OneNote', 'zarządzanie urządzeniami i dodatkowe zabezpieczenia']],
        'A5' => ['name' => 'Microsoft 365 A5', 'product' => 'office_a5', 'desktop' => true,
                 'features' => ['wszystko z A3', 'zaawansowana ochrona przed zagrożeniami', 'zaawansowana zgodność z przepisami i analityka']],
    ];
    public const PLANS_SOURCE = 'https://www.microsoft.com/pl-pl/education/products/microsoft-365/compare-microsoft-365-education-plans';

    /** Czy panele pokazują pocztę Office (Outlook) kont EDU — wyłącznik administratora, ti_edu_mail_enabled(). */
    public static function mailEnabled(): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
        return ti_edu_mail_enabled();
    }

    /**
     * Claude for Nonprofit przez weryfikację Goodstack: produkt ją przewiduje, link jest ustawiony i poczta EDU jest włączona
     * (Goodstack potwierdza adres @edu linkiem w e-mailu — przy wyłączonej poczcie ten sposób znika, polecenie 2026-11-12).
     */
    public static function goodstackOn(array $P, array $s): bool
    {
        return !empty($P['goodstack']) && ($s['goodstack_url'] ?? '') !== '' && self::mailEnabled();
    }

    /** Licencja (SKU) Office A3/A5 w tenancie edukacyjnym — przy aktywacji abonamentu. @return array{ok:bool,text:string} */
    public static function assignSku(int $cid, string $sku, string $label, string $by = 'system'): array
    {
        if ($sku === '') return ['ok' => false, 'text' => 'Licencji ' . $label . ' w tenancie nie nadano automatycznie (brak SKU w ustawieniach) — nadaj ją ręcznie w Microsoft 365.'];
        if (ClaudeBilling::isVirtual($cid)) return ['ok' => true, 'text' => 'Symulacja: licencja ' . $label . ' nadana (konto testowe).'];
        $r = self::get($cid);
        $upn = ClaudeBilling::eduAccount($cid);
        if (!$upn) return ['ok' => false, 'text' => 'Brak konta Office — licencji ' . $label . ' nie nadano.'];
        try {
            $g = self::graph();
            $gid = (string)($r['graph_id'] ?? '');
            if ($gid === '' || str_starts_with($gid, 'SIM-')) { $u = $g->get_user_by_id($upn); $gid = (string)($u['id'] ?? ''); }
            if ($gid === '') throw new RuntimeException('nie znaleziono konta ' . $upn . ' w tenancie');
            $g->assign_license($gid, $sku);
            ClaudeBilling::event(null, null, 'office', 'Nadano licencję ' . $label . ' kontu ' . $upn . ' w tenancie edukacyjnym.', $by);
            return ['ok' => true, 'text' => 'Licencja ' . $label . ' nadana kontu ' . $upn . '.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => 'Nie nadano licencji ' . $label . ' (' . $e->getMessage() . ') — nadaj ją ręcznie w Microsoft 365.'];
        }
    }

    /** Klient Graph dla testów (obiekt z get_user_by_id, last_status, create_user, assign_license). */
    public static ?object $graph = null;
    /** Ostatnie hasło tymczasowe (do pokazania kadrze, gdy SMS nie wyszedł). */
    public static string $lastPassword = '';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('licence_office_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS license_office_accounts (
            client_id INTEGER PRIMARY KEY,
            upn TEXT NOT NULL DEFAULT '', graph_id TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT 'missing',
            note TEXT NOT NULL DEFAULT '', checked_at DATETIME, created_at DATETIME, created_by TEXT NOT NULL DEFAULT '')");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_lic_office_upn ON license_office_accounts(upn)");
    }

    /** Domena kont Office — zawsze edu.feer.org.pl (polecenie 2026-11-10), niezależnie od ustawień tenanta. */
    public static function domain(): string
    {
        return ClaudeBilling::EDU_DOMAIN;
    }

    /** Login z imienia i nazwiska: j.kowalski@edu.feer.org.pl. Pusto, gdy brak imienia albo nazwiska. */
    public static function expectedUpn(string $name, ?string $domain = null): string
    {
        $clean = self::ascii($name);
        $clean = strtolower(preg_replace('/[^A-Za-z\s-]/', '', $clean));
        $parts = array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: [], fn($p) => trim($p, '-') !== ''));
        if (count($parts) < 2) return '';
        $first = substr(trim($parts[0], '-'), 0, 1);
        $last = trim(preg_replace('/-+/', '-', (string)end($parts)), '-');
        return $first !== '' && $last !== '' ? $first . '.' . $last . '@' . ($domain ?? self::domain()) : '';
    }

    /** Bez polskich znaków i innych diakrytyków (Żółć → Zolc). */
    private static function ascii(string $s): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'];
        $s = strtr(trim($s), $map);
        if (function_exists('iconv') && preg_match('/[^\x20-\x7E]/', $s)) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if (is_string($t) && $t !== '') $s = $t; }
        return $s;
    }

    public static function ready(): bool
    {
        if (self::$graph) return true;
        require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
        return function_exists('m365_edu_ready') && m365_edu_ready();
    }

    private static function graph(): object
    {
        if (self::$graph) return self::$graph;
        require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
        return m365_edu();
    }

    public static function get(int $cid): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM license_office_accounts WHERE client_id=?", [$cid]) ?: null;
    }

    /** Konto Office uczestnika, które działa (sprawdzone w tenancie albo założone przez SZO) — albo null. */
    public static function account(int $cid): ?string
    {
        $r = self::get($cid);
        return $r && in_array($r['state'], ['exists', 'created', 'sim'], true) && $r['upn'] !== '' && ($r['state'] !== 'sim' || ClaudeBilling::isVirtual($cid)) ? (string)$r['upn'] : null;
    }

    /** Krótki opis stanu dla kadry i uczestnika. @return array{upn:string,state:string,label:string,checked:string,note:string} */
    public static function summary(int $cid): array
    {
        $r = self::get($cid);
        $c = ClaudeBilling::client($cid) ?? ['name' => ''];
        $known = ClaudeBilling::eduAccount($cid);
        $upn = (string)($r['upn'] ?? '') ?: ((string)$known ?: self::expectedUpn((string)$c['name']));
        $state = $r ? (string)$r['state'] : ($known ? 'exists' : '');
        return ['upn' => $upn, 'state' => $state, 'label' => self::STATE[$state] ?? ($known ? 'w kartotece SZO' : 'nie sprawdzono'),
                'checked' => $r && $r['checked_at'] ? date('d.m.Y H:i', strtotime((string)$r['checked_at'])) : '', 'note' => (string)($r['note'] ?? ''),
                'ok' => in_array($state, ['exists', 'created', 'sim'], true)];
    }

    private static function save(int $cid, array $row): void
    {
        self::migrate();
        $row = ['client_id' => $cid, 'checked_at' => date('Y-m-d H:i:s')] + $row;
        $cols = array_keys($row);
        db_exec("INSERT INTO license_office_accounts (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")
                 ON CONFLICT(client_id) DO UPDATE SET " . implode(', ', array_map(fn($k) => "$k=excluded.$k", array_diff($cols, ['client_id']))), array_values($row));
    }

    /** Wiąże konto z kontem uczestnika TI (ms_upn / ms_user_id), jeśli tam jeszcze pusto. */
    private static function linkStudent(int $cid, string $upn, string $graphId): void
    {
        try {
            $acc = db_one("SELECT id, ms_user_id, ms_upn FROM k30_ti_student_accounts WHERE client_id=? AND is_active=1 ORDER BY id DESC LIMIT 1", [$cid]);
            if ($acc && trim((string)$acc['ms_upn']) === '') {
                db_exec("UPDATE k30_ti_student_accounts SET ms_upn=?, ms_user_id=?, ms_created_at=COALESCE(ms_created_at, datetime('now')), updated_at=datetime('now') WHERE id=?", [$upn, $graphId, (int)$acc['id']]);
            }
        } catch (\Throwable $e) { error_log('licence office link: ' . $e->getMessage()); }
    }

    /** Czy login jest w SZO przypisany innemu uczestnikowi. */
    private static function takenByOther(int $cid, string $upn): bool
    {
        self::migrate();
        $u = strtolower($upn);
        if (db_one("SELECT 1 FROM license_office_accounts WHERE LOWER(upn)=? AND client_id<>? AND state IN ('exists','created')", [$u, $cid])) return true;
        try { if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE LOWER(COALESCE(ms_upn,''))=? AND client_id<>?", [$u, $cid])) return true; } catch (\Throwable $e) {}
        return false;
    }

    private static function norm(string $s): string
    {
        $w = preg_split('/[\s-]+/', strtolower(preg_replace('/[^A-Za-z\s-]/', '', self::ascii($s)))) ?: [];
        $w = array_values(array_filter($w, fn($x) => $x !== ''));
        sort($w);   // „Kowalski Jan” = „Jan Kowalski”
        return implode(' ', $w);
    }

    /** Konto w tenancie po loginie: ['state' => exists|missing|error, 'id', 'display']. Tylko 404 znaczy „nie ma”. */
    private static function lookup(string $upn): array
    {
        try {
            $g = self::graph();
            $u = $g->get_user_by_id($upn);
            if (!empty($u['id'])) return ['state' => 'exists', 'id' => (string)$u['id'], 'display' => (string)($u['displayName'] ?? '')];
            return ['state' => (int)$g->last_status() === 404 ? 'missing' : 'error', 'id' => '', 'display' => '',
                    'error' => (string)($u['error']['message'] ?? ('HTTP ' . (int)$g->last_status()))];
        } catch (\Throwable $e) { return ['state' => 'error', 'id' => '', 'display' => '', 'error' => $e->getMessage()]; }
    }

    /**
     * Sprawdza konto w tenancie EDU (bez zakładania). Konto tej osoby wiąże z uczestnikiem.
     * @return array{state:string,upn:string,text:string,free?:string}
     */
    public static function check(int $cid, string $by = 'system'): array
    {
        try { return self::checkInner($cid, $by); }
        catch (\Throwable $e) {   // sprawdzenie konta nigdy nie przerywa wniosku ani strony
            error_log('licence office check #' . $cid . ': ' . $e->getMessage());
            return ['state' => 'error', 'upn' => '', 'text' => 'Nie udało się sprawdzić konta Office: ' . $e->getMessage()];
        }
    }

    private static function checkInner(int $cid, string $by): array
    {
        self::migrate();
        $c = ClaudeBilling::client($cid);
        if (!$c) return ['state' => 'error', 'upn' => '', 'text' => 'Nie ma takiego uczestnika.'];
        $name = trim((string)$c['name']);
        $expected = self::expectedUpn($name);
        if (ClaudeBilling::isVirtual($cid)) {
            $r = self::get($cid);
            return ['state' => $r ? (string)$r['state'] : 'missing', 'upn' => (string)($r['upn'] ?? '') ?: $expected, 'text' => 'Konto testowe: tenant EDU nie jest sprawdzany (symulacja).'];
        }
        if ($expected === '') { self::save($cid, ['state' => 'error', 'note' => 'brak imienia albo nazwiska w kartotece']); return ['state' => 'error', 'upn' => '', 'text' => 'W kartotece brakuje imienia albo nazwiska, więc nie da się ustalić loginu.']; }
        if (!self::ready()) return ['state' => 'unknown', 'upn' => $expected, 'text' => 'Tenant EDU nie jest skonfigurowany (Dydaktyka › Microsoft 365), więc konta nie da się sprawdzić.'];

        // konto już znane w SZO (Ewidencja, sprawa AM7) — sprawdzamy, czy nadal jest w tenancie
        $known = ClaudeBilling::eduAccount($cid);
        if ($known && strcasecmp($known, $expected) !== 0) {
            $k = self::lookup($known);
            if ($k['state'] === 'exists') { self::save($cid, ['upn' => $known, 'graph_id' => $k['id'], 'state' => 'exists', 'note' => 'konto z kartoteki SZO']); return ['state' => 'exists', 'upn' => $known, 'text' => 'Konto ' . $known . ' jest w tenancie EDU.']; }
            if ($k['state'] === 'error') return ['state' => 'error', 'upn' => $known, 'text' => 'Tenant EDU nie odpowiedział: ' . ($k['error'] ?? '') . '.'];
        }
        $l = self::lookup($expected);
        if ($l['state'] === 'error') { self::save($cid, ['upn' => $expected, 'state' => 'error', 'note' => mb_substr((string)($l['error'] ?? ''), 0, 300)]); return ['state' => 'error', 'upn' => $expected, 'text' => 'Tenant EDU nie odpowiedział: ' . ($l['error'] ?? '') . '. Konta nie zakładamy, dopóki nie wiadomo, czy już jest.']; }
        if ($l['state'] === 'exists') {
            $mine = self::norm($l['display']) === self::norm($name) && !self::takenByOther($cid, $expected);
            if ($mine) {
                self::save($cid, ['upn' => $expected, 'graph_id' => $l['id'], 'state' => 'exists', 'note' => 'konto znalezione w tenancie']);
                self::linkStudent($cid, $expected, $l['id']);
                ClaudeBilling::event(null, null, 'office', 'Konto Office ' . $expected . ' (' . $name . ') jest w tenancie EDU — powiązane z uczestnikiem.', $by);
                return ['state' => 'exists', 'upn' => $expected, 'text' => 'Konto ' . $expected . ' jest w tenancie EDU.'];
            }
            $free = self::freeVariant($cid, $expected);
            self::save($cid, ['upn' => $free, 'graph_id' => '', 'state' => 'missing', 'note' => 'login ' . $expected . ' ma ktoś inny (' . ($l['display'] ?: 'inna osoba') . ')']);
            return ['state' => 'missing', 'upn' => $free, 'free' => $free, 'text' => 'Login ' . $expected . ' ma w tenancie ktoś inny (' . ($l['display'] ?: 'inna osoba') . '). Konto tej osoby założymy jako ' . $free . '.'];
        }
        if (self::takenByOther($cid, $expected)) {
            $free = self::freeVariant($cid, $expected);
            self::save($cid, ['upn' => $free, 'state' => 'missing', 'note' => 'login ' . $expected . ' przypisany w SZO innemu uczestnikowi']);
            return ['state' => 'missing', 'upn' => $free, 'text' => 'Login ' . $expected . ' jest w SZO przypisany innemu uczestnikowi. Konto założymy jako ' . $free . '.'];
        }
        self::save($cid, ['upn' => $expected, 'graph_id' => '', 'state' => 'missing', 'note' => 'brak konta w tenancie']);
        return ['state' => 'missing', 'upn' => $expected, 'text' => 'W tenancie EDU nie ma konta ' . $expected . '.'];
    }

    /** j.kowalski2…99 — pierwszy wolny w tenancie i w SZO (tylko przy 404). */
    private static function freeVariant(int $cid, string $upn): string
    {
        [$local, $dom] = explode('@', $upn, 2);
        for ($i = 2; $i <= 99; $i++) {
            $try = $local . $i . '@' . $dom;
            if (self::takenByOther($cid, $try)) continue;
            $l = self::lookup($try);
            if ($l['state'] === 'missing') return $try;
            if ($l['state'] === 'error') throw new RuntimeException('Tenant EDU nie odpowiedział przy sprawdzaniu loginu ' . $try . '.');
        }
        throw new RuntimeException('Wszystkie warianty loginu ' . $local . '2–99 są zajęte.');
    }

    /**
     * Sprawdza konto, a gdy go nie ma, zakłada je w tenancie EDU (login e-mailem, hasło SMS-em).
     * @return array{ok:bool,state:string,upn:string,text:string,created:bool,password?:string}
     */
    public static function ensure(int $cid, array $actor): array
    {
        $by = (string)($actor['name'] ?? 'system');
        $chk = self::check($cid, $by);
        if (in_array($chk['state'], ['exists', 'created'], true)) return $chk + ['ok' => true, 'created' => false];
        $c = ClaudeBilling::client($cid) ?? ['name' => '', 'email' => '', 'phone' => ''];
        $name = trim((string)$c['name']);
        if (ClaudeBilling::isVirtual($cid)) {
            $upn = self::expectedUpn($name) ?: 'test.uczestnik@' . self::domain();
            self::save($cid, ['upn' => $upn, 'graph_id' => 'SIM-' . $cid, 'state' => 'sim', 'note' => 'symulacja: konto testowe', 'created_at' => date('Y-m-d H:i:s'), 'created_by' => $by]);
            ClaudeBilling::event(null, null, 'office', 'Symulacja: konto Office ' . $upn . ' dla konta testowego ' . $name . ' (bez Microsoft 365).', $by);
            return ['ok' => true, 'state' => 'sim', 'upn' => $upn, 'text' => 'Konto testowe: symulacja założenia konta ' . $upn . ' (Microsoft 365 nietknięty).', 'created' => true];
        }
        if ($chk['state'] !== 'missing') return $chk + ['ok' => false, 'created' => false];
        $upn = $chk['upn'];
        $pass = M365Graph::generate_password();
        try {
            $g = self::graph();
            $u = $g->create_user($upn, $name, $pass, true);
            $gid = (string)($u['id'] ?? '');
            if ($gid === '') throw new RuntimeException('Microsoft 365 nie zwrócił identyfikatora konta.');
        } catch (\Throwable $e) {
            self::save($cid, ['upn' => $upn, 'state' => 'error', 'note' => mb_substr('nie założono: ' . $e->getMessage(), 0, 300)]);
            ClaudeBilling::event(null, null, 'office', 'Nie założono konta Office ' . $upn . ' (' . $name . '): ' . $e->getMessage(), $by);
            return ['ok' => false, 'state' => 'error', 'upn' => $upn, 'text' => 'Nie udało się założyć konta ' . $upn . ': ' . $e->getMessage(), 'created' => false];
        }
        $lic = '';
        $sku = function_exists('ti_m365_setting') ? trim((string)ti_m365_setting('default_sku')) : '';
        if ($sku !== '') { try { $g->assign_license($gid, $sku); $lic = ' z licencją'; } catch (\Throwable $e) { $lic = ' (licencji nie nadano: ' . $e->getMessage() . ')'; } }
        self::save($cid, ['upn' => $upn, 'graph_id' => $gid, 'state' => 'created', 'note' => 'założone przez SZO' . $lic, 'created_at' => date('Y-m-d H:i:s'), 'created_by' => $by]);
        self::linkStudent($cid, $upn, $gid);
        $sent = self::deliver($c, $upn, $pass);
        ClaudeBilling::event(null, null, 'office', 'Założono konto Office ' . $upn . ' dla ' . $name . $lic . '. ' . $sent['text'], $by);
        self::$lastPassword = $sent['sms'] ? '' : $pass;
        $out = ['ok' => true, 'state' => 'created', 'upn' => $upn, 'created' => true, 'text' => 'Założono konto ' . $upn . '. ' . $sent['text']];
        if (!$sent['sms']) $out['password'] = $pass;
        return $out;
    }

    /** Login e-mailem, hasło SMS-em (rodzaj access_data). @return array{email:bool,sms:bool,text:string} */
    private static function deliver(array $c, string $upn, string $pass): array
    {
        $org = defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER';
        $email = trim((string)($c['email'] ?? ''));
        $phone = trim((string)($c['phone'] ?? ''));
        $okE = false; $okS = false;
        if ($phone !== '') {   // najpierw SMS z hasłem — treść e-maila zależy od tego, czy wyszedł
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            $GLOBALS['__sms_quiet_bypass'] = true;   // hasło do świeżo założonego konta wychodzi o każdej porze (jak kod jednorazowy)
            try { $okS = !in_array(sms_send($phone, $org . ': haslo tymczasowe do konta ' . $upn . ': ' . $pass . ' (zmienisz je przy pierwszym logowaniu)', 'access_data'), ['', 'odlozony'], true); } catch (\Throwable $e) {}
            unset($GLOBALS['__sms_quiet_bypass']);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            $hint = rawurlencode($upn);
            $html = '<p>Dzień dobry,</p>'
                . '<p>założyliśmy Ci konto Microsoft 365 w ' . h($org) . '. Zalogujesz się nim do Worda, Excela' . (self::mailEnabled() ? ', Outlooka' : '') . ' i Teams.</p>'
                . '<p>Twój login: <b>' . h($upn) . '</b></p>'
                . '<p>' . ($okS ? 'Hasło tymczasowe przyszło osobno SMS-em.' : 'Hasło tymczasowe przekaże Ci biuro.') . ' Przy pierwszym logowaniu Microsoft poprosi o nowe hasło.</p>'
                . '<p>Logowanie: <a href="https://www.office.com/?login_hint=' . h($hint) . '">office.com</a></p>'
                . '<p>Jeśli to pomyłka, odpisz na tę wiadomość.</p>';
            try { mail_queue_add($email, (string)($c['name'] ?? ''), 'Twoje konto Microsoft 365: ' . $upn, $html, '', 'licencje_office', (int)($c['id'] ?? 0), '', true); $okE = true; } catch (\Throwable $e) {}
        }
        $t = [];
        $t[] = $okE ? 'Login poszedł e-mailem.' : 'E-mail z loginem nie wyszedł.';
        $t[] = $okS ? 'Hasło poszło SMS-em.' : 'SMS z hasłem nie wyszedł — przekaż hasło osobiście.';
        return ['email' => $okE, 'sms' => $okS, 'text' => implode(' ', $t)];
    }
}
