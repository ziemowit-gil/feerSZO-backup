<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeDostep.php — dane dostępu wysyłane uczestnikowi przy nadaniu licencji lub usługi (polecenie 2026-11-10).
 * Kto wpisuje: administrator w Panelu Licencji (Uczestnicy › „Wyślij dane dostępu”) albo obsługa przy „Aktywuj dostęp” płatnej licencji
 * (karta wniosku w Helpdesku i ekran Płatne licencje) — pola: login, adres logowania, hasło / klucz, „Jak zacząć”.
 * Uczestnik dostaje e-mail z tymi danymi; hasło / klucz idzie osobno SMS-em (gdy SMS nie wyjdzie — kadra widzi je raz na ekranie, nigdy w e-mailu).
 * Wpis (bez hasła) zostaje w license_access_messages i jest widoczny w Panelu Licencji (Moje konto i strona usługi). Konto testowe: bez wysyłek.
 */
require_once dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php';

final class LicencjeDostep
{
    /** Usługi do wyboru (klucz = produkt w Panelu Licencji, pusty = inna usługa wpisana ręcznie). */
    public const SERVICES = ['office_a3' => 'Microsoft 365 A3', 'office_a5' => 'Microsoft 365 A5', 'claude' => 'Claude', 'nordvpn' => 'NordVPN', 'dysk' => 'Dysk Online'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS license_access_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, product TEXT NOT NULL DEFAULT '', service TEXT NOT NULL DEFAULT '', login TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', had_secret INTEGER NOT NULL DEFAULT 0, email_sent INTEGER NOT NULL DEFAULT 0, sms_sent INTEGER NOT NULL DEFAULT 0,
            created_by TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_lic_access_client ON license_access_messages(client_id, id)");
        $cols = array_column(db_all("PRAGMA table_info(license_access_messages)"), 'name');   // tabela z wczesnej wersji (bez kolumny product)
        if (!in_array('product', $cols, true)) db()->exec("ALTER TABLE license_access_messages ADD COLUMN product TEXT NOT NULL DEFAULT ''");
    }

    public static function forClient(int $cid, string $product = ''): array
    {
        self::migrate();
        return $product === '' ? db_all("SELECT * FROM license_access_messages WHERE client_id=? ORDER BY id DESC LIMIT 30", [$cid])
                               : db_all("SELECT * FROM license_access_messages WHERE client_id=? AND product=? ORDER BY id DESC LIMIT 10", [$cid, $product]);
    }

    /** Podpowiedzi do formularza: login, adres i „Jak zacząć” dla danej usługi (administrator może je zmienić). */
    public static function defaults(string $product, int $cid): array
    {
        $c = db_one("SELECT email FROM k30_clients WHERE id=?", [$cid]) ?: ['email' => ''];
        $edu = (string)(ClaudeBilling::eduAccount($cid) ?? '');
        if ($edu === '') { try { require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceOffice.php'; $edu = (string)(LicenceOffice::summary($cid)['upn'] ?? ''); } catch (\Throwable $e) {} }
        return match ($product) {
            'office_a3', 'office_a5' => ['login' => $edu, 'url' => 'https://www.office.com',
                'note' => "Zaloguj się na www.office.com swoim kontem Office.\nAplikacje Word, Excel, PowerPoint i Outlook na komputer pobierzesz po zalogowaniu, przyciskiem „Zainstaluj aplikacje”."],
            'claude' => ['login' => $edu, 'url' => 'https://claude.ai', 'note' => 'Wejdź na claude.ai i zaloguj się swoim kontem Office. Zobaczysz obszar roboczy Fundacji.'],
            'nordvpn' => ['login' => (string)$c['email'], 'url' => 'https://nordvpn.com/download/', 'note' => 'Pobierz aplikację NordVPN na komputer albo telefon i zaloguj się danymi poniżej.'],
            'dysk' => ['login' => (string)$c['email'], 'url' => '', 'note' => "Zainstaluj program SeaDrive na komputerze albo aplikację Seafile w telefonie.\nW programie wpisz adres serwera, login i hasło. Dysk pojawi się jak zwykły dysk."],
            default => ['login' => '', 'url' => '', 'note' => ''],
        };
    }

    /**
     * Pola formularza (nazwy acc[...]). $bs = szata Bootstrap (Helpdesk, Płatne licencje), inaczej Panel Licencji (m-*).
     * $pick = wybór usługi (formularz ogólny administratora); bez wyboru usługa wynika z $product.
     */
    public static function fieldsHtml(string $idp, string $product, int $cid, bool $bs, bool $pick = false): string
    {
        $d = self::defaults($product, $cid);
        $lab = $bs ? 'form-label small mb-0' : 'm-label';
        $inp = $bs ? 'form-control form-control-sm mb-1' : 'm-input';
        $wrap = fn(string $x) => $bs ? $x : '<div class="tw-mb-3">' . $x . '</div>';
        $f = fn(string $k, string $label, string $type, string $val, string $extra = '') => $wrap('<label class="' . $lab . '" for="' . h($idp . $k) . '">' . h($label) . '</label>'
            . ($type === 'textarea' ? '<textarea class="' . $inp . '" id="' . h($idp . $k) . '" name="acc[' . $k . ']" rows="3" maxlength="2000">' . h($val) . '</textarea>'
                                    : '<input class="' . $inp . '" type="' . $type . '" id="' . h($idp . $k) . '" name="acc[' . $k . ']" value="' . h($val) . '"' . $extra . '>'));
        $out = '';
        if ($pick) {
            $opts = '';
            foreach (self::SERVICES as $k => $n) $opts .= '<option value="' . h($k) . '"' . ($k === $product ? ' selected' : '') . '>' . h($n) . '</option>';
            $opts .= '<option value="">Inna usługa</option>';
            $out .= $wrap('<label class="' . $lab . '" for="' . h($idp . 'product') . '">Usługa</label><select class="' . ($bs ? 'form-select form-select-sm mb-1' : 'm-input') . '" id="' . h($idp . 'product') . '" name="acc[product]" data-acc-pick="' . h($idp) . '">' . $opts . '</select>');
            $out .= $f('service', 'Nazwa usługi (gdy „Inna usługa”)', 'text', '', ' maxlength="120"');
        } else {
            $out .= '<input type="hidden" name="acc[product]" value="' . h($product) . '">';
        }
        $out .= $f('login', 'Login (e-mail albo nazwa użytkownika; klucza tu nie wpisuj)', 'text', $d['login'], ' maxlength="190" autocomplete="off"');
        $out .= $f('url', 'Adres logowania', 'text', $d['url'], ' maxlength="300" inputmode="url"');
        $out .= $f('secret', 'Hasło albo klucz (pójdzie SMS-em, nie e-mailem)', 'text', '', ' maxlength="300" autocomplete="off"');
        $out .= $f('note', 'Jak zacząć (trafi do e-maila)', 'textarea', $d['note']);
        if ($pick) {   // podpowiedzi po zmianie usługi: tylko gdy administrator nie zmienił jeszcze pola
            $all = [];
            foreach (array_keys(self::SERVICES) as $k) $all[$k] = self::defaults($k, $cid);
            $out .= '<script>(function(){var s=document.getElementById(' . json_encode($idp . 'product') . ');if(!s)return;var D=' . json_encode($all, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
                  . 'var last=' . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';s.addEventListener("change",function(){var d=D[s.value]||{login:"",url:"",note:""};'
                  . '["login","url","note"].forEach(function(k){var el=document.getElementById(' . json_encode($idp) . '+k);if(el&&(el.value===""||el.value===(last[k]||"")))el.value=d[k]||"";});last=d;});})();</script>';
        }
        return $out;
    }

    /** Po „Aktywuj dostęp”: gdy zaznaczono „Wyślij dane dostępu”, wysyła e-mail z danymi z formularza. Zwraca dopisek do komunikatu. */
    public static function afterActivate(int $sid, array $post, array $actor): string
    {
        if (empty($post['acc_send']) || !is_array($post['acc'] ?? null)) return '';
        $sub = ClaudeBilling::subscription($sid);
        if (!$sub) return '';
        $in = $post['acc'];
        $in['product'] = (string)$sub['product'];
        try {
            $r = self::send((int)$sub['client_id'], $in, $actor);
            return ' Dane dostępu: ' . $r['text'] . ($r['secret'] !== '' ? ' Hasło / klucz (pokazujemy je tylko teraz): ' . $r['secret'] . '.' : '');
        } catch (\Throwable $e) { return ' Danych dostępu nie wysłano: ' . $e->getMessage(); }
    }

    /**
     * Wysyła dane dostępu. $in: product (klucz z SERVICES albo pusty), service (nazwa, gdy inna usługa), login, url, note, secret.
     * @return array{text:string,secret:string,email:bool,sms:bool}
     */
    public static function send(int $cid, array $in, array $actor): array
    {
        self::migrate();
        $c = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id=?", [$cid]);
        if (!$c) throw new DomainException('Nie ma takiego uczestnika.');
        $product = (string)($in['product'] ?? '');
        if ($product !== '' && !isset(self::SERVICES[$product])) $product = '';
        $service = $product !== '' ? self::SERVICES[$product] : mb_substr(trim((string)($in['service'] ?? '')), 0, 120);
        $login = mb_substr(trim((string)($in['login'] ?? '')), 0, 190);
        $url = trim((string)($in['url'] ?? ''));
        $note = mb_substr(trim(str_replace("\r", '', (string)($in['note'] ?? ''))), 0, 2000);
        $secret = mb_substr(trim((string)($in['secret'] ?? '')), 0, 300);
        if ($service === '') throw new InvalidArgumentException('Wybierz usługę albo wpisz jej nazwę.');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Adres logowania jest nieprawidłowy.');
        if ($login === '' && $url === '' && $note === '' && $secret === '') throw new InvalidArgumentException('Wpisz dane do wysłania: login, adres, hasło albo instrukcję.');
        $virtual = ClaudeBilling::isVirtual($cid);
        $email = trim((string)$c['email']); $phone = trim((string)$c['phone']);
        $okS = false; $okE = false;
        if (!$virtual && $secret !== '' && preg_replace('/\D/', '', $phone) !== '') {   // najpierw SMS z hasłem — e-mail mówi, czy przyszło
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            $txt = 'FEER: ' . sms_ascii($service) . ' - haslo/klucz: ' . $secret . ($login !== '' ? ' (login ' . $login . ')' : '');
            $GLOBALS['__sms_quiet_bypass'] = true;
            try { $okS = !in_array(sms_send($phone, $txt, 'access_data'), ['', 'odlozony'], true); } catch (\Throwable $e) { error_log('licencje dostep sms: ' . $e->getMessage()); }
            unset($GLOBALS['__sms_quiet_bypass']);
        }
        if (!$virtual && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row = fn(string $k, string $v) => '<tr><td style="padding:8px 14px 8px 0;color:#555;vertical-align:top">' . h($k) . '</td><td style="padding:8px 0;font-weight:600">' . $v . '</td></tr>';
            $tbl = '<table style="border-collapse:collapse;font-size:15px;margin:8px 0">'
                . ($login !== '' ? $row('Login', h($login)) : '')
                . ($url !== '' ? $row('Adres logowania', '<a href="' . h($url) . '">' . h($url) . '</a>') : '')
                . ($secret !== '' ? $row('Hasło', $okS ? 'wysłaliśmy je osobno SMS-em' : 'przekażemy je osobno, nie wysyłamy go e-mailem') : '')
                . '</table>';
            $html = '<p>Dzień dobry,</p><p>włączyliśmy Ci usługę <b>' . h($service) . '</b>. Poniżej dane do logowania.</p>' . $tbl
                . ($note !== '' ? '<p style="margin-top:16px"><b>Jak zacząć</b><br>' . nl2br(h($note)) . '</p>' : '')
                . '<p>Te dane, bez hasła, znajdziesz też w Panelu Licencji w zakładce „Moje konto”: <a href="' . h(LicencjePanelAuthUrl::get()) . '">' . h(LicencjePanelAuthUrl::get()) . '</a></p>'
                . '<p>Jeśli coś nie działa albo to pomyłka, odpisz na tę wiadomość.</p>';
            try {
                require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                mail_queue_add($email, (string)$c['name'], 'Dane do logowania: ' . $service, $html, '', 'licencje_dostep', $cid, '', true);
                $okE = true;
            } catch (\Throwable $e) { error_log('licencje dostep mail: ' . $e->getMessage()); }
        }
        db_insert('license_access_messages', ['client_id' => $cid, 'product' => $product, 'service' => $service, 'login' => $login, 'url' => $url, 'note' => $note,
            'had_secret' => $secret !== '' ? 1 : 0, 'email_sent' => $okE ? 1 : 0, 'sms_sent' => $okS ? 1 : 0, 'created_by' => mb_substr((string)($actor['name'] ?? ''), 0, 150)]);
        ClaudeBilling::event(null, null, 'access_data', 'Dane dostępu „' . $service . '” dla ' . $c['name'] . ' (e-mail: ' . ($okE ? 'tak' : 'nie') . ($secret !== '' ? ', SMS z hasłem: ' . ($okS ? 'tak' : 'nie') : '') . ').', (string)($actor['name'] ?? ''));
        $text = $virtual ? 'konto testowe, dane zapisane bez wysyłek.'
              : (($okE ? 'e-mail wysłany.' : 'e-mail nie wyszedł (brak adresu albo błąd).') . ($secret !== '' ? ($okS ? ' Hasło poszło SMS-em.' : ' SMS z hasłem nie wyszedł, przekaż je osobiście.') : ''));
        return ['text' => $text, 'secret' => $secret !== '' && !$okS ? $secret : '', 'email' => $okE, 'sms' => $okS];
    }
}

/** Adres Panelu Licencji do e-maila (bez twardej zależności od sesji panelu). */
final class LicencjePanelAuthUrl
{
    public static function get(): string
    {
        try { require_once __DIR__ . '/LicencjePanelAuth.php'; return LicencjePanelAuth::publicUrl(); } catch (\Throwable $e) { return rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/licencje/'; }
    }
}
