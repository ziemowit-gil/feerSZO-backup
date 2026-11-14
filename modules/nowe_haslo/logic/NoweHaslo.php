<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/logic/NoweHaslo.php — zmiana haseł do kont Fundacji na /nowehaslo (polecenie 2026-11-13).
 *
 * Dwa hasła:
 *  • logowanie jednokrotne (Logto) + chmura plików (Nextcloud) — JEDNO wspólne hasło (SsoAccounts::setPassword; bez Logto —
 *    samo konto w chmurze, tryb samej chmury);
 *  • poczta — ZAWSZE INNE hasło (DhostingApi::changeMailboxPassword + DhostingCopy::passwordChanged dla kopiowania w tle).
 * Własne hasło sprawdzamy w drugim systemie (NoweHasloCheck) i odrzucamy takie samo; gdy sprawdzić się nie da — też odrzucamy
 * (osoba może wybrać hasło losowe). Hasło losowe idzie tylko SMS-em, nigdy e-mailem.
 * Login: jeden do SZO, logowania jednokrotnego i chmury — adres e-mail konta (overview()['login']); w chmurze działa też imie.nazwisko.
 *
 * Po każdej zmianie: SMS (losowe hasło albo informacja o zmianie — polecenie „o zmianie poinformujmy SMS-em”), e-mail bez hasła,
 * notatka w Helpdesku (NoweHasloHelpdesk), audit_log. W treściach dla osoby bez nazwy dostawcy poczty.
 * Limit: 6 zmian na osobę na dobę. Każda zmiana wymaga świeżego kodu SMS (kontroler zużywa go przed wywołaniem change()).
 */
require_once __DIR__ . '/NoweHasloAuth.php';
require_once dirname(__DIR__) . '/lib/NoweHasloCheck.php';
require_once dirname(__DIR__) . '/lib/NoweHasloHelpdesk.php';
require_once dirname(__DIR__) . '/lib/NoweHasloLimit.php';
require_once dirname(__DIR__) . '/lib/NoweHasloInitial.php';
require_once dirname(__DIR__, 2) . '/sso_logto/logic/SsoAccounts.php';

final class NoweHaslo
{
    public const DAY_LIMIT = 6;
    public const MIN_LEN = 12;
    public const MAX_LEN = 64;
    /** Od kiedy jedno hasło logowania jednokrotnego działa we wszystkich aplikacjach Fundacji (polecenie 2026-11-13). */
    public const ONE_PASSWORD_FROM = '1 grudnia';

    /** Atrapy w testach: fn($to, $name, $subject, $html, $text): bool oraz fn($phone, $message, $kind): bool. */
    public static ?\Closure $sendMail = null;
    public static ?\Closure $sendSms = null;

    // ── Co osoba ma ───────────────────────────────────────────────────────────────────────────

    /** Konto logowania jednokrotnego / chmury osoby (wiersz sso_accounts) albo null. $link: dowiąż konto z Logto po e-mailu. */
    public static function ssoAccount(array $p, bool $link = false): ?array
    {
        $cands = self::emails($p);
        foreach ($cands as $e) {
            $r = SsoAccounts::get($e);
            if ($r && (!empty($r['logto_id']) || !empty($r['cloud_uid']))) return $r;
        }
        if ($link && SsoConfig::logtoConfigured()) {
            foreach ($cands as $e) {
                try { $lu = SsoConfig::logto()->findByEmail($e); } catch (\Throwable $ex) { $lu = null; }
                if (!$lu) continue;
                $linked = SsoAccounts::linkFromLogto($lu);
                $r = $linked !== '' ? SsoAccounts::get($linked) : null;
                if ($r && !empty($r['logto_id'])) return $r;
            }
        }
        return null;
    }

    /** Do widoku: wspólny login, konto logowania jednokrotnego / chmury, skrzynka. */
    public static function overview(array $p): array
    {
        $acc = null;
        try { $acc = self::ssoAccount($p); } catch (\Throwable $e) { error_log('[nowehaslo] overview: ' . $e->getMessage()); }
        $login = $acc ? (string)$acc['email'] : ((string)$p['email'] !== '' ? (string)$p['email'] : (string)$p['address']);
        return [
            'login'    => $login,
            'sso'      => $acc !== null,
            'logto'    => $acc && !empty($acc['logto_id']),
            'cloud'    => $acc && !empty($acc['cloud_uid']),
            'cloudUid' => $acc ? (string)($acc['cloud_uid'] ?? '') : '',
            'mailbox'  => (string)($p['mailbox'] ?? ''),
        ];
    }

    // ── Zmiana ────────────────────────────────────────────────────────────────────────────────

    /**
     * $target: 'sso' (logowanie jednokrotne + chmura) | 'mail'; $mode: 'own' (własne, $pw1 = $pw2) | 'random' (losowe SMS-em,
     * tylko gdy znamy numer komórkowy — hasło nie idzie e-mailem). $confirm — czym potwierdzono (do notatki w Helpdesku).
     * Zwraca komunikat dla osoby. Wyjątek RuntimeException = nic nie zmieniono (komunikat dla osoby).
     */
    public static function change(array $p, string $target, string $mode, string $pw1, string $pw2, string $by, string $confirm = 'kod SMS'): string
    {
        if (!in_array($target, ['sso', 'mail'], true)) throw new RuntimeException('Wybierz, które hasło zmieniasz.');
        if (!in_array($mode, ['own', 'random'], true)) throw new RuntimeException('Wybierz: własne hasło albo losowe SMS-em.');
        if ($mode === 'random' && (string)($p['phone'] ?? '') === '') {
            throw new RuntimeException('Nie mamy Twojego numeru komórkowego, a hasła nie wysyłamy e-mailem. Ustaw własne hasło.');
        }
        if (NoweHasloLimit::count('chg:' . $p['key'], 86400) >= self::DAY_LIMIT) {
            throw new RuntimeException('Hasło zmieniano dziś już ' . self::DAY_LIMIT . ' razy. Spróbuj jutro albo napisz do Helpdesku IT.');
        }
        $own = $mode === 'own';
        if ($own) self::validate($p, $pw1, $pw2);
        $res = $target === 'sso' ? self::changeSso($p, $own, $pw1, $by) : self::changeMail($p, $own, $pw1, $by);
        NoweHasloLimit::hit('chg:' . $p['key']);
        // Zmiana przez samą osobę (także hasło losowe na jej prośbę) — hasło przestaje być startowe
        // Gdy zmieniła się tylko chmura (Logto nie działało), hasło logowania jednokrotnego nadal jest startowe
        if (empty($res['keepInitial'])) NoweHasloInitial::clear($target === 'sso' ? 'sso' : 'poczta', (string)$res['login']);

        // SMS: losowe hasło albo informacja o zmianie (bez hasła)
        $smsNote = self::smsAfter($p, $res, $own);
        $mailNote = self::mailAfter($p, $res, $own, $smsNote === 'wysłany');
        $how = $own ? 'własne (sprawdzone: ' . ($target === 'sso' ? 'inne niż do poczty' : 'inne niż logowania jednokrotnego i chmury') . ')' : 'losowe';
        $who = ($p['via'] ?? '') === 'szo' ? 'zalogowany do SZO + ' . $confirm
             : 'bez logowania: ' . (($p['via'] ?? '') === 'pesel' ? 'PESEL' : 'prywatny e-mail') . ' + adres służbowy + ' . $confirm;
        NoweHasloHelpdesk::event($p, 'Zmiana hasła na /nowehaslo (' . $who . '): ' . $res['what'] . '. ' . $res['detail']
            . ' Hasło: ' . $how . '. SMS: ' . $smsNote . ((string)$p['phone'] !== '' ? ' (' . DhostingMigrator::maskPhone((string)$p['phone']) . ')' : '') . '. E-mail o zmianie: ' . $mailNote . '.'
            . ($res['warn'] !== '' ? ' DO SPRAWDZENIA: ' . $res['warn'] : ''));
        self::audit('nowehaslo.' . $target, ['key' => $p['key'], 'user_id' => (int)$p['user_id'], 'mode' => $mode, 'by' => $by,
                                            'detail' => $res['detail'], 'sms' => $smsNote, 'mail' => $mailNote, 'warn' => $res['warn']]);

        $msg = 'Gotowe. Zmieniliśmy hasło ' . $res['whatTo'] . '.';
        if (!$own) $msg .= $smsNote === 'wysłany' ? ' Nowe hasło wysłaliśmy SMS-em.' : ' SMS z hasłem nie wyszedł — zamów kod i zmień hasło jeszcze raz albo napisz do Helpdesku IT.';
        if ($res['warn'] !== '') $msg .= ' ' . $res['warnUser'];
        return $msg;
    }

    /** Wymagania dla własnego hasła (te same dla obu systemów). */
    public static function validate(array $p, string $pw1, string $pw2): void
    {
        if ($pw1 === '') throw new RuntimeException('Wpisz nowe hasło.');
        if ($pw1 !== $pw2) throw new RuntimeException('Hasła w obu polach się różnią. Wpisz to samo hasło dwa razy.');
        $len = mb_strlen($pw1);
        if ($len < self::MIN_LEN) throw new RuntimeException('Hasło musi mieć co najmniej ' . self::MIN_LEN . ' znaków.');
        if ($len > self::MAX_LEN) throw new RuntimeException('Hasło może mieć najwyżej ' . self::MAX_LEN . ' znaki.');
        if (trim($pw1) !== $pw1) throw new RuntimeException('Hasło nie może zaczynać się ani kończyć spacją.');
        if (!preg_match('/\p{Ll}/u', $pw1) || !preg_match('/\p{Lu}/u', $pw1) || !preg_match('/\d/', $pw1)) {
            throw new RuntimeException('Hasło musi mieć małe i wielkie litery oraz cyfry.');
        }
        $low = mb_strtolower($pw1);
        $parts = [];
        foreach ([(string)$p['email'], (string)$p['address']] as $a) {
            $l = ($at = strpos($a, '@')) !== false ? substr($a, 0, $at) : $a;
            foreach (preg_split('/[._\-]+/', mb_strtolower($l)) ?: [] as $w) if (mb_strlen($w) >= 4) $parts[] = $w;
        }
        foreach (preg_split('/\s+/', mb_strtolower((string)$p['name'])) ?: [] as $w) if (mb_strlen($w) >= 4) $parts[] = $w;
        foreach (array_unique($parts) as $w) {
            if (str_contains($low, $w)) throw new RuntimeException('Hasło nie może zawierać Twojego imienia, nazwiska ani loginu.');
        }
    }

    /** @return array{what:string,whatTo:string,detail:string,warn:string,warnUser:string,password:string,login:string,target:string,site:string} */
    private static function changeSso(array $p, bool $own, string $pw, string $by): array
    {
        $acc = self::ssoAccount($p, true);
        if (!$acc) throw new RuntimeException('Nie masz jeszcze konta logowania jednokrotnego ani konta w chmurze plików. Napisz do Helpdesku IT — założymy je.');
        if ($own && (string)($p['mailbox'] ?? '') !== '') {
            $same = NoweHasloCheck::sameAsMail((string)$p['mailbox'], $pw);
            if ($same === true) throw new RuntimeException('To hasło jest takie samo jak hasło do poczty. Hasło do poczty musi być inne — wpisz inne hasło.');
            if ($same === null) throw new RuntimeException(self::cantCheck('poczty'));
        }
        if (!$own) $pw = SsoConfig::generatePassword();
        $email = (string)$acc['email'];
        $logtoOn = !empty($acc['logto_id']) && SsoConfig::logtoConfigured();
        $cloudOn = !empty($acc['cloud_uid']) && SsoConfig::cloudConfigured();
        $warn = '';
        $logtoDown = '';   // Logto nie działa (np. HTTP 500 przy tokenie) — wtedy zmieniamy samą chmurę przez OCS (polecenie 2026-11-14)
        if ($logtoOn) {
            try {
                $r = SsoAccounts::setPassword($email, $pw, $by);   // Logto, potem chmura tym samym hasłem; wyjątek = nic nie zmieniono
            } catch (\Throwable $e) {
                $m = $e->getMessage();
                error_log('[nowehaslo] Logto ' . $email . ': ' . $m);
                if (str_contains($m, 'odrzuciło hasło')) throw new RuntimeException($m);
                if (!$cloudOn) {
                    // Przyczyna dla administratora (2026-11-14: „nie przyjęło zmiany” bez wskazówki, a log serwera nie zawsze jest pod ręką)
                    $admin = function_exists('is_admin') && is_admin();
                    throw new RuntimeException('Logowanie jednokrotne nie przyjęło zmiany. Spróbuj za kilka minut albo napisz do Helpdesku IT.'
                        . ($admin ? ' Szczegóły dla administratora: ' . mb_substr($m, 0, 300) : ''));
                }
                $logtoDown = mb_substr($m, 0, 200);
            }
        }
        if ($logtoOn && $logtoDown === '') {
            $c = (string)($r['cloud'] ?? '');
            $cloudOk = in_array($c, ['linked', 'synced'], true);
            if ($cloudOn && !$cloudOk) $warn = 'w chmurze zostało stare hasło (' . ($c !== '' ? $c : 'brak zmiany') . ')';
            $detail = 'logowanie jednokrotne: nowe hasło' . ($cloudOn ? '; chmura: ' . ($cloudOk ? 'to samo nowe hasło' : $c) : '; chmura: brak konta');
            $what = 'logowanie jednokrotne' . ($cloudOn ? ' i chmura plików' : '');
        } elseif ($cloudOn) {
            try {
                SsoConfig::cloud()->setPassword((string)$acc['cloud_uid'], $pw);
            } catch (\Throwable $e) {
                error_log('[nowehaslo] chmura ' . $acc['cloud_uid'] . ': ' . $e->getMessage());
                throw new RuntimeException('Chmura plików nie przyjęła hasła' . ($own ? ' (może być za proste albo pojawiło się w wyciekach haseł). Wpisz inne albo wybierz losowe.' : '. Spróbuj za kilka minut albo napisz do Helpdesku IT.'));
            }
            $detail = 'chmura: nowe hasło (login ' . $acc['cloud_uid'] . ')' . (!empty($acc['logto_id']) ? '; logowanie jednokrotne: nieskonfigurowane w SZO — dostanie hasło przy następnej zmianie' : '');
            $what = 'chmura plików';
            if ($logtoDown !== '') {
                $detail = 'chmura: nowe hasło przez OCS (login ' . $acc['cloud_uid'] . '); logowanie jednokrotne NIEZMIENIONE — Logto nie działało: ' . $logtoDown;
                $warn = 'Logto ma stare hasło (Logto nie działało: ' . $logtoDown . ') — po naprawie Logto osoba musi zmienić hasło jeszcze raz';
            } elseif (!empty($acc['logto_id'])) $warn = 'Logto ma stare hasło (SZO nie ma połączenia z Logto)';
        } else {
            throw new RuntimeException('Logowanie jednokrotne i chmura plików są chwilowo niedostępne. Spróbuj później albo napisz do Helpdesku IT.');
        }
        return ['what' => $what, 'whatTo' => $what === 'chmura plików' ? 'do chmury plików' : 'logowania jednokrotnego' . ($cloudOn ? ' i chmury plików' : ''),
                'detail' => $detail, 'warn' => $warn,
                'warnUser' => $logtoDown !== '' ? 'Logowanie jednokrotne jest chwilowo niedostępne i ma jeszcze stare hasło. Gdy zacznie działać, zmień hasło jeszcze raz — Helpdesk IT już o tym wie.'
                            : ($warn !== '' ? 'W jednym z systemów zmiana się nie udała — Helpdesk IT już o tym wie i to poprawi.' : ''),
                'keepInitial' => $logtoDown !== '',
                'password' => $pw, 'login' => $email, 'target' => 'sso', 'cloudUid' => $cloudOn ? (string)$acc['cloud_uid'] : '',
                'site' => $logtoOn ? SsoConfig::logtoUrl() : SsoConfig::cloudUrl()];
    }

    private static function changeMail(array $p, bool $own, string $pw, string $by): array
    {
        $box = (string)($p['mailbox'] ?? '');
        if ($box === '') throw new RuntimeException('Nie masz skrzynki pocztowej Fundacji. Jeśli to pomyłka, napisz do Helpdesku IT.');
        $api = DhostingConfig::api();
        if (!$api->configured()) throw new RuntimeException('Zmiana hasła do poczty jest chwilowo niedostępna. Napisz do Helpdesku IT.');
        try {
            $exists = in_array($box, $api->getMailboxList(substr($box, strrpos($box, '@') + 1)), true);
        } catch (\Throwable $e) {
            error_log('[nowehaslo] lista skrzynek ' . $box . ': ' . $e->getMessage());
            throw new RuntimeException('Nie udało się sprawdzić Twojej skrzynki. Spróbuj za kilka minut.');
        }
        if (!$exists) throw new RuntimeException('Twoja nowa skrzynka jeszcze nie istnieje. Załóż ją na stronie migracji poczty albo napisz do Helpdesku IT.');
        if ($own) {
            $acc = self::ssoAccount($p, true);
            if ($acc) {
                $same = NoweHasloCheck::sameAsSso($acc, $pw);
                if ($same === true) throw new RuntimeException('To hasło jest takie samo jak hasło logowania jednokrotnego i chmury. Hasło do poczty musi być inne — wpisz inne hasło.');
                if ($same === null) throw new RuntimeException(self::cantCheck('logowania jednokrotnego i chmury'));
            }
        } else {
            $pw = DhostingConfig::generatePassword();
        }
        try {
            $api->changeMailboxPassword($box, $pw);
        } catch (\Throwable $e) {
            error_log('[nowehaslo] poczta ' . $box . ': ' . $e->getMessage());
            DhostingHelpdesk::event($box, 'Zmiana hasła na /nowehaslo nie udała się. Błąd serwera poczty: ' . $e->getMessage(),
                ['user_id' => (int)$p['user_id'], 'name' => (string)$p['name'], 'login' => (string)$p['address']], false);
            throw new RuntimeException('Serwer poczty nie przyjął hasła.' . ($own ? ' Spróbuj dłuższego hasła albo wybierz losowe.' : ' Spróbuj za kilka minut albo napisz do Helpdesku IT.'));
        }
        // Zapisane w CRM hasło do własnej skrzynki (wysyłka „z mojej skrzynki”, modules/userSmtp) przestaje obowiązywać
        try {
            require_once dirname(__DIR__, 2) . '/userSmtp/lib/UserSmtp.php';
            UserSmtp::passwordChanged($box);
        } catch (\Throwable $e) { error_log('[nowehaslo] userSmtp ' . $box . ': ' . $e->getMessage()); }
        $copy = 0;
        try { $copy = DhostingCopy::passwordChanged($box, $pw); } catch (\Throwable $e) { error_log('[nowehaslo] kopiowanie ' . $box . ': ' . $e->getMessage()); }
        return ['what' => 'poczta ' . $box, 'whatTo' => 'do poczty ' . $box,
                'detail' => 'poczta ' . $box . ': nowe hasło' . ($copy > 0 ? ' (przekazane do kopiowania poczty w tle: ' . $copy . ' zad.)' : ''),
                'warn' => '', 'warnUser' => '', 'password' => $pw, 'login' => $box, 'target' => 'mail', 'site' => DhostingConfig::webmailOrDefault()];
    }

    private static function cantCheck(string $other): string
    {
        return NoweHasloCheck::$why === 'busy'
            ? 'Teraz zmienia hasło dużo osób naraz i nie możemy sprawdzić, czy Twoje różni się od hasła ' . $other . '. Spróbuj za 10 minut albo wybierz hasło losowe.'
            : 'Nie możemy teraz sprawdzić, czy Twoje hasło różni się od hasła ' . $other . '. Spróbuj za kilka minut albo wybierz hasło losowe.';
    }

    // ── Powiadomienia ─────────────────────────────────────────────────────────────────────────

    /** SMS po zmianie: losowe hasło (access_data) albo informacja o zmianie (security_alert). Zwraca 'wysłany' | 'nie wyszedł'. */
    private static function smsAfter(array $p, array $res, bool $own): string
    {
        if ((string)($p['phone'] ?? '') === '') return 'brak numeru';
        $org = SsoConfig::smsPrefix();
        $when = date('d.m.y H:i:s');
        $host = preg_replace('~^https?://~', '', (string)$res['site']);
        if (!$own && $res['target'] === 'mail') {
            $msg = DhostingMigrator::passwordSmsText((string)$res['login'], (string)$res['password']);
            $kind = 'access_data';
        } elseif (!$own) {
            // login w chmurze imie.nazwisko jako zapasowy: Nextcloud wpuszcza e-mailem tylko przy unikalnym adresie
            $alt = (string)($res['cloudUid'] ?? '') !== '' ? ' (w chmurze tez ' . $res['cloudUid'] . ')' : '';
            $msg = $org . ': nowe haslo - ' . $res['what'] . '. Login: ' . $res['login'] . $alt . ' Haslo: ' . $res['password']
                 . ' (inne niz do poczty). Obowiazuje od ' . $when . ($host !== '' ? '. Strona: ' . $host : '');
            $kind = 'access_data';
        } else {
            $msg = $org . ': zmieniono haslo - ' . $res['what'] . ' (' . $when . '). Jesli to nie Ty, napisz od razu do Helpdesku IT.';
            $kind = 'security_alert';
        }
        $msg = function_exists('sms_ascii') ? sms_ascii($msg) : $msg;
        try {
            if (self::$sendSms) return (self::$sendSms)((string)$p['phone'], $msg, $kind) ? 'wysłany' : 'nie wyszedł';
            $prev = $GLOBALS['__sms_quiet_bypass'] ?? null;
            $GLOBALS['__sms_quiet_bypass'] = true;   // zmiana na prośbę osoby — SMS od razu, także w nocy
            try { $prov = sms_send((string)$p['phone'], $msg, $kind); }
            finally { if ($prev === null) unset($GLOBALS['__sms_quiet_bypass']); else $GLOBALS['__sms_quiet_bypass'] = $prev; }
            return $prov !== '' ? 'wysłany' : 'nie wyszedł';
        } catch (\Throwable $e) {
            error_log('[nowehaslo] SMS po zmianie: ' . $e->getMessage());
            return 'nie wyszedł';
        }
    }

    /** E-mail „zmieniono hasło” (bez hasła) na adresy osoby. Zwraca opis do notatki. */
    private static function mailAfter(array $p, array $res, bool $own, bool $smsOk): string
    {
        $to = $p['contacts'] ?? [];
        if (!$to) return 'brak adresu';
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $sso = $res['target'] === 'sso';
        $subject = $sso ? 'Zmieniliśmy hasło: ' . $res['what'] : 'Zmieniliśmy hasło do poczty';
        $lines = [
            'Kiedy: ' . date('d.m.Y, H:i') . '.',
            'Login: ' . $res['login'] . '.',
            $own ? 'Hasło ustawiłeś(-aś) sam(a) na stronie zmiany hasła.'
                 : ($smsOk ? 'Nowe hasło wysłaliśmy SMS-em.' : 'SMS z nowym hasłem nie wyszedł — zmień hasło jeszcze raz albo napisz do Helpdesku IT.'),
            'Hasła nigdy nie wysyłamy e-mailem.',
            $sso ? 'Hasło do poczty zostaje bez zmian i musi być inne niż to hasło.'
                 : 'Hasło logowania jednokrotnego i chmury plików zostaje bez zmian i musi być inne niż hasło do poczty.',
        ];
        if ($sso) $lines[] = 'Od ' . self::ONE_PASSWORD_FROM . ' tym samym loginem i hasłem zalogujesz się do wszystkich aplikacji Fundacji. Poczta ma swoje, osobne hasło.';
        $alert = 'Jeśli to nie Ty zmieniałeś(-aś) hasło, napisz od razu do Helpdesku IT. Nikt z Fundacji nie poprosi Cię o hasło.';
        $name = (string)$p['name'];
        $html = '<p>Dzień dobry' . ($name !== '' ? ', ' . $e($name) : '') . ',</p><p>zmieniliśmy hasło: <strong>' . $e((string)$res['what']) . '</strong>.</p><ul>';
        foreach ($lines as $l) $html .= '<li>' . $e($l) . '</li>';
        $html .= '</ul><p><strong>' . $e($alert) . '</strong></p>';
        $text = 'Zmieniliśmy hasło: ' . $res['what'] . ".\n\n- " . implode("\n- ", $lines) . "\n\n" . $alert;
        $send = self::$sendMail ?? (function (string $t, string $nm, string $s, string $h, string $x): bool {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            return (bool)mail_queue_add($t, $nm, $s, $h, $x, 'nowehaslo');
        });
        $ok = [];
        foreach ($to as $addr) {
            try { if ($send($addr, $name, $subject, $html, $text)) $ok[] = DhostingGuest::maskEmail($addr); }
            catch (\Throwable $ex) { error_log('[nowehaslo] e-mail ' . $addr . ': ' . $ex->getMessage()); }
        }
        return $ok ? 'wysłany na ' . implode(', ', $ok) : 'nie wyszedł';
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────────────────────

    /** Adresy osoby do szukania konta logowania jednokrotnego: e-mail konta SZO, adresy kontaktowe, adres służbowy. */
    private static function emails(array $p): array
    {
        $out = [];
        foreach ([(string)($p['email'] ?? ''), ...($p['contacts'] ?? []), (string)($p['address'] ?? '')] as $e) {
            $e = strtolower(trim((string)$e));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) $out[] = $e;
        }
        return $out;
    }

    private static function audit(string $event, array $data): void
    {
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($event, $data);
        } catch (\Throwable $e) { error_log('[nowehaslo] audit: ' . $e->getMessage()); }
    }
}
