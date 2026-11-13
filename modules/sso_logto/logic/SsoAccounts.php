<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/SsoAccounts.php — konta logowania jednokrotnego (Logto) i chmury plików powiązane z osobami SZO.
 *
 * Zasada haseł (polecenie 2026-11-13): konto w chmurze ma TO SAMO hasło co konto w Logto. SZO zna hasło tylko w chwili,
 * gdy je ustawia (użytkownik wpisuje je w Tożsamości albo administrator dostaje wygenerowane), więc każde ustawienie hasła
 * przez SZO idzie naraz do Logto i do chmury. Zmiana hasła bezpośrednio w Logto (np. „Nie pamiętam hasła”) nie dociera
 * do chmury — webhook zapisuje wtedy logto_pwd_reset_at, a Tożsamość podpowiada ponowne ustawienie hasła w SZO.
 * Hasło do poczty jest odrębne (skrzynki dhosting, moduł dhosting_migrator) — nie ruszamy go tutaj.
 *
 * Tabela sso_accounts (klucz: e-mail osoby w SZO, małymi literami):
 *   logto_id / logto_username — konto w Logto; cloud_uid — konto w chmurze (ten sam login co username w Logto);
 *   logto_pwd_at — ostatnie ustawienie hasła przez SZO; last_sign_in_at — ostatnie logowanie (webhook PostSignIn).
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';
require_once __DIR__ . '/CloudFilesCopy.php';

final class SsoAccounts
{
    /** Podmiana wysyłki e-maila w testach: fn($to, $name, $subject, $html, $text): bool. */
    public static ?\Closure $sendMail = null;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('sso_accounts_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS sso_accounts (
            email VARCHAR(190) NOT NULL PRIMARY KEY,
            user_id INTEGER NULL,
            name VARCHAR(190) NOT NULL DEFAULT '',
            logto_id VARCHAR(64) NULL,
            logto_username VARCHAR(128) NULL,
            logto_created_at VARCHAR(19) NULL,
            logto_by VARCHAR(190) NOT NULL DEFAULT '',
            logto_pwd_at VARCHAR(19) NULL,
            logto_pwd_reset_at VARCHAR(19) NULL,
            cloud_uid VARCHAR(128) NULL,
            cloud_created_at VARCHAR(19) NULL,
            cloud_by VARCHAR(190) NOT NULL DEFAULT '',
            last_sign_in_at VARCHAR(19) NULL,
            updated_at VARCHAR(19) NULL)");
        try { db()->exec("CREATE INDEX idx_sso_accounts_logto ON sso_accounts(logto_id)"); } catch (\Throwable $e) {}
        db()->exec("CREATE TABLE IF NOT EXISTS sso_webhook_log (
            delivery_id VARCHAR(128) NOT NULL PRIMARY KEY,
            event VARCHAR(64) NOT NULL DEFAULT '',
            logto_id VARCHAR(64) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at VARCHAR(19) NULL)");
    }

    public static function get(string $email): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM sso_accounts WHERE email=?", [strtolower(trim($email))]);
        return $r ?: null;
    }

    public static function byLogtoId(string $id): ?array
    {
        self::migrate();
        $r = $id !== '' ? db_one("SELECT * FROM sso_accounts WHERE logto_id=?", [$id]) : null;
        return $r ?: null;
    }

    private static function upsert(string $email, array $fields): void
    {
        self::migrate();
        $email = strtolower(trim($email));
        $fields['updated_at'] = date('Y-m-d H:i:s');
        if (self::get($email)) {
            $set = implode(', ', array_map(fn($k) => $k . '=?', array_keys($fields)));
            db()->prepare("UPDATE sso_accounts SET {$set} WHERE email=?")->execute([...array_values($fields), $email]);
        } else {
            $fields = ['email' => $email] + $fields;
            $cols = implode(', ', array_keys($fields));
            $qs = implode(', ', array_fill(0, count($fields), '?'));
            db()->prepare("INSERT INTO sso_accounts ({$cols}) VALUES ({$qs})")->execute(array_values($fields));
        }
    }

    /** Login (username w Logto = login w chmurze) z loginu organizacji albo e-maila: małe litery ASCII, cyfry, „_”. */
    /**
     * Login w chmurze w postaci imie.nazwisko (polecenie 2026-11-13). Logto nie dopuszcza kropki w nazwie użytkownika (stąd „_” w usernameBase),
     * więc login w chmurze NIE jest już nazwą z Logto. Kolejność:
     *   1) login organizacji (konto Microsoft) albo e-mail w domenie organizacji — część przed @ (ziemowit.gil; skrzynki wspólne: edukacja);
     *   2) imię i nazwisko osoby: pierwszy i ostatni wyraz, bez dopisku po „|” (adres prywatny: jan1985@gmail.com → jan.kowalski);
     *   3) oczyszczona część adresu przed @.
     * Znaki: a-z, 0-9, kropka i myślnik. Kolizje (login innej osoby) rozwiązuje cloudFreeUid() numerem na końcu.
     */
    public static function cloudUidBase(string $name, string $login, string $email): string
    {
        $clean = function (string $s): string {
            $s = strtr(mb_strtolower(trim($s)), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
            $c = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s;
            $c = (string)preg_replace('/[^a-z0-9.\-]+/', '.', strtolower($c));
            return trim((string)preg_replace('/\.{2,}/', '.', $c), '.-');
        };
        $local = fn(string $src) => $clean(($at = strpos($src, '@')) === false ? $src : substr($src, 0, $at));
        $org = self::orgDomains();
        $inOrg = fn(string $addr) => ($at = strrpos($addr, '@')) !== false && in_array(strtolower(substr($addr, $at + 1)), $org, true);
        foreach ([$login, $email] as $src) {
            $src = trim($src);
            if ($src === '' || ($src === trim($email) && !$inOrg($src))) continue;   // e-mail prywatny nie wyznacza loginu
            $l = $local($src);
            if ($l !== '' && preg_match('/^[a-z]/', $l)) return substr($l, 0, 60);
        }
        $n = (string)preg_replace('/\(.*?\)/', ' ', explode('|', $name)[0]);
        $words = array_values(array_filter(array_map($clean, preg_split('/\s+/', trim($n)) ?: []), fn($w) => (bool)preg_match('/^[a-z]+(-[a-z]+)*$/', $w)));
        if (count($words) >= 2) return substr($words[0] . '.' . $words[count($words) - 1], 0, 60);
        $l = $local(trim($login) !== '' ? $login : $email);
        if ($l === '') $l = 'uzytkownik';
        if (!preg_match('/^[a-z]/', $l)) $l = 'u.' . $l;
        return substr($l, 0, 60);
    }

    /** Domeny organizacji (logowanie Microsoft 365 i przenoszone domeny poczty) — małymi literami. */
    private static function orgDomains(): array
    {
        static $d = null;
        if ($d !== null) return $d;
        $d = [];
        if (function_exists('m365_setting')) { $m = strtolower(trim((string)m365_setting('m365_domain'))); if ($m !== '') $d[] = $m; }
        try {
            require_once dirname(__DIR__, 2) . '/dhosting_migrator/lib/DhostingConfig.php';
            foreach (DhostingConfig::domains() as $src => $dst) { $d[] = strtolower((string)$src); $d[] = strtolower((string)$dst); }
        } catch (\Throwable $e) {}
        if (!$d) $d[] = 'feer.org.pl';
        return $d = array_values(array_unique(array_filter($d)));
    }

    /**
     * Wolny login w chmurze dla osoby: $base, potem $base2, $base3… Konto o tym loginie i tym samym e-mailu (albo bez e-maila) uznajemy
     * za konto tej osoby. Zwraca [uid, istniejące-dane|null]. $skip — login, którego nie bierzemy (np. stary z „_”).
     */
    private static function cloudFreeUid(CloudApi $cloud, string $base, string $email, string $skip = ''): array
    {
        for ($i = 1; $i <= 99; $i++) {
            $uid = $base . ($i > 1 ? (string)$i : '');
            if ($uid === $skip) continue;
            $info = $cloud->user($uid);
            if ($info === null) return [$uid, null];
            $theirs = strtolower(trim((string)($info['email'] ?? '')));
            if ($theirs === '' || $theirs === $email) return [$uid, $info];
        }
        throw new RuntimeException('nie udało się dobrać wolnego loginu w chmurze dla ' . $email . ' (' . $base . ').');
    }

    public static function usernameBase(string $login, string $email): string
    {
        $src = trim($login) !== '' ? trim($login) : trim($email);
        $at = strpos($src, '@');
        $local = mb_strtolower($at === false ? $src : substr($src, 0, $at));
        $local = strtr($local, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        $conv = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $local) : $local;
        $u = trim((string)preg_replace('/[^a-z0-9_]+/', '_', strtolower((string)$conv)), '_');
        if ($u === '') $u = 'uzytkownik';
        if (!preg_match('/^[a-z_]/', $u)) $u = 'u_' . $u;
        return substr($u, 0, 60);
    }

    /**
     * Zakłada konto w Logto (albo dowiązuje istniejące o tym samym e-mailu) i ustawia hasło; opcjonalnie konto w chmurze
     * z tym samym hasłem i kopiowanie plików z OneDrive.
     *
     * $person: email (wymagany), name, login (login organizacji → nazwa użytkownika), m365 (konto Microsoft — źródło plików), user_id.
     * $opt: password (null = wygeneruj), cloud (bool), files (bool), by (kto), via ('self' | 'admin' | 'migrator').
     * Zwraca: username, password (tylko wygenerowane), logto ('created'|'linked'), cloud ('created'|'linked'|'synced'|'' |'błąd: …'), job (id|null).
     */
    public static function provision(array $person, array $opt): array
    {
        $email = strtolower(trim((string)($person['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Osoba nie ma poprawnego adresu e-mail w SZO.');
        $name = trim((string)($person['name'] ?? ''));
        $by = (string)($opt['by'] ?? '');
        $generated = !isset($opt['password']) || $opt['password'] === null || $opt['password'] === '';
        $password = $generated ? SsoConfig::generatePassword() : (string)$opt['password'];
        $now = date('Y-m-d H:i:s');

        $row = self::get($email);
        if (!SsoConfig::logtoConfigured()) {
            // Logto jeszcze nieskonfigurowane (np. brak aplikacji M2M) — zakładamy samo konto w chmurze (polecenie 2026-11-13: przycisk
            // w migratorze ma działać i bez Logto). Konto Logto dostanie to samo nowe hasło przy następnym nadaniu (provision / setPassword).
            $username = (string)($row['logto_username'] ?? '') !== '' ? (string)$row['logto_username']
                : ((string)($row['cloud_uid'] ?? '') !== '' ? (string)$row['cloud_uid'] : self::usernameBase((string)($person['login'] ?? ''), $email));
            self::upsert($email, array_filter(['user_id' => isset($person['user_id']) ? (int)$person['user_id'] : null, 'name' => $name], fn($v) => $v !== null));
            $out = ['email' => $email, 'username' => $username, 'password' => $generated ? $password : null, 'logto' => 'pominięte', 'cloud' => '', 'job' => null];
            $out = self::provisionCloud($person, $opt, $out, $email, $username, $name, $password, $by);
            if (!empty($out['cloud_uid'])) $out['username'] = $out['cloud_uid'];   // bez Logto jedynym loginem jest login w chmurze (imie.nazwisko)
            return $out;
        }
        $api = SsoConfig::logto();
        $lu = null;
        if ($row && !empty($row['logto_id'])) $lu = $api->get((string)$row['logto_id']);
        if (!$lu) $lu = $api->findByEmail($email);

        if ($lu) {
            $api->setPassword((string)$lu['id'], $password);
            $logtoState = 'linked';
        } else {
            $base = self::usernameBase((string)($person['login'] ?? ''), $email);
            $username = $base;
            for ($i = 2; $api->findByUsername($username) !== null; $i++) {
                if ($i > 99) throw new RuntimeException('Nie udało się dobrać wolnej nazwy użytkownika w Logto dla ' . $email . '.');
                $username = $base . $i;
            }
            $lu = $api->create(['username' => $username, 'primaryEmail' => $email, 'name' => $name, 'password' => $password]);
            $logtoState = 'created';
        }
        $username = (string)($lu['username'] ?? '') !== '' ? (string)$lu['username'] : self::usernameBase((string)($person['login'] ?? ''), $email);
        self::upsert($email, array_filter([
            'user_id' => isset($person['user_id']) ? (int)$person['user_id'] : null,
            'name' => $name,
            'logto_id' => (string)$lu['id'],
            'logto_username' => $username,
            'logto_created_at' => $logtoState === 'created' ? $now : ($row['logto_created_at'] ?? $now),
            'logto_by' => $by,
            'logto_pwd_at' => $now,
            'logto_pwd_reset_at' => '',
        ], fn($v) => $v !== null));

        $out = ['email' => $email, 'username' => $username, 'password' => $generated ? $password : null, 'logto' => $logtoState, 'cloud' => '', 'job' => null];
        self::audit('sso.logto_' . $logtoState, ['email' => $email, 'username' => $username, 'via' => $opt['via'] ?? '']);
        return self::provisionCloud($person, $opt, $out, $email, $username, $name, $password, $by);
    }

    /** Część „chmura + pliki” z provision(): konto w chmurze z tym samym hasłem i (na życzenie) zadanie kopiowania plików z OneDrive. */
    private static function provisionCloud(array $person, array $opt, array $out, string $email, string $username, string $name, string $password, string $by): array
    {
        // Chmura: zakładamy na życzenie; istniejące konto w chmurze zawsze dostaje to samo hasło (zasada „jedno hasło”)
        $row = self::get($email);
        $wantCloud = !empty($opt['cloud']) || !empty($row['cloud_uid']);
        if ($wantCloud && SsoConfig::cloudConfigured()) {
            try {
                $out['cloud'] = self::cloudEnsure($email, $username, $name, $password, !empty($opt['cloud']), $by, (string)($person['login'] ?? ''));
            } catch (\Throwable $e) {
                $out['cloud'] = 'błąd: ' . $e->getMessage();
            }
        }
        if (!empty($opt['files']) && in_array($out['cloud'], ['created', 'linked', 'synced'], true)) {
            $src = trim((string)($person['m365'] ?? ''));
            if ($src !== '' && GraphDrive::configured()) {
                $out['job'] = CloudFilesCopy::queue((string)self::get($email)['cloud_uid'], $src, $password, $email, $by);
            }
        }
        $out['cloud_uid'] = (string)(self::get($email)['cloud_uid'] ?? '');
        if (in_array($out['cloud'], ['created', 'linked', 'synced'], true)) {
            try { $out['mail'] = self::cloudMail($email, $name, (string)self::get($email)['cloud_uid'], $out); }
            catch (\Throwable $e) { $out['mail'] = 'błąd'; error_log('[sso] e-mail o chmurze ' . $email . ': ' . $e->getMessage()); }
        }
        return $out;
    }

    /**
     * E-mail po założeniu konta w chmurze albo ustawieniu mu hasła (polecenie 2026-11-13): adres chmury, login, skąd hasło (bez hasła —
     * hasła idą SMS-em albo osoba ustawiła je sama), opis: groupware + miejsce na pliki, zamiennik OneDrive, aplikacje na komputer
     * i telefon, pliki z OneDrive w folderze „OneDrive”. Zwraca 'wysłany' albo 'nie wyszedł'.
     */
    private static function cloudMail(string $email, string $name, string $uid, array $out): string
    {
        $url = SsoConfig::cloudUrl();
        $label = SsoConfig::cloudLabel();
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
        $new = $out['cloud'] === 'created';
        $logto = ($out['logto'] ?? '') !== 'pominięte';
        $quota = trim((string)SsoConfig::cloudQuota());
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $title = $new ? 'Twoje konto w chmurze Fundacji — ' . $host : 'Nowe hasło do chmury Fundacji — ' . $host;
        $pass = $out['password'] !== null
            ? 'Hasło przekazujemy osobno — SMS-em albo przez administratora. Nigdy nie wysyłamy go e-mailem.'
            : 'Hasło: to, które właśnie ustawiłeś(-aś).';
        $same = $logto ? 'To samo hasło działa w logowaniu jednokrotnym. Jest inne niż hasło do poczty — celowo, ze względów bezpieczeństwa.'
                       : 'Jest inne niż hasło do poczty — celowo, ze względów bezpieczeństwa.';
        $what = [
            'Pliki i foldery w jednym miejscu, dostępne z komputera, telefonu i przeglądarki' . ($quota !== '' ? ' (masz ' . $quota . ' miejsca)' : '') . '.',
            'Udostępnianie plików i folderów innym osobom z Fundacji albo linkiem — także osobom spoza Fundacji.',
            'Wspólna praca nad dokumentami i folderami zespołu, wersje plików i kosz, z którego odzyskasz usunięte pliki.',
            'Aplikacja ' . $label . ' na komputer (Windows, macOS, Linux) i telefon (Android, iOS) synchronizuje pliki tak jak OneDrive.',
        ];
        if (!empty($out['job'])) $what[] = 'Twoje pliki z OneDrive kopiujemy w tle do folderu „OneDrive” w chmurze — nic nie musisz robić.';
        $html = '<p>Dzień dobry' . ($name !== '' ? ', ' . $e($name) : '') . ',</p>'
              . '<p>' . ($new ? 'założyliśmy Ci konto' : 'ustawiliśmy nowe hasło do Twojego konta') . ' w chmurze Fundacji: <a href="' . $e($url) . '">' . $e($host) . '</a>.</p>'
              . '<p><strong>Czym jest chmura?</strong> To ' . $e($label) . ' — narzędzie do pracy grupowej (groupware) i miejsce na pliki. Zastępuje OneDrive z Microsoft 365:</p><ul>';
        foreach ($what as $w) $html .= '<li>' . $e($w) . '</li>';
        $html .= '</ul><p><strong>Logowanie:</strong> <a href="' . $e($url) . '">' . $e($url) . '</a><br>Login: <b>' . $e($uid) . '</b> (albo adres ' . $e($email) . ')<br>'
               . $e($pass) . '<br>' . $e($same) . '</p>'
               . ($label === 'Nextcloud' ? '<p>Aplikacje na komputer i telefon: <a href="https://nextcloud.com/install/">nextcloud.com/install</a> — przy pierwszym uruchomieniu wpisz adres ' . $e($host) . '.</p>' : '')
               . '<p style="color:#555">Nikt z Fundacji nie poprosi Cię o hasło — jeśli ktoś o nie prosi, to próba oszustwa.</p>';
        $text = ($new ? 'Założyliśmy Ci konto' : 'Ustawiliśmy nowe hasło do Twojego konta') . ' w chmurze Fundacji: ' . $url . "\n\n"
              . 'Czym jest chmura? To ' . $label . ' — narzędzie do pracy grupowej (groupware) i miejsce na pliki. Zastępuje OneDrive z Microsoft 365:' . "\n- " . implode("\n- ", $what)
              . "\n\nLogowanie: " . $url . "\nLogin: " . $uid . ' (albo adres ' . $email . ")\n" . $pass . "\n" . $same
              . ($label === 'Nextcloud' ? "\n\nAplikacje na komputer i telefon: https://nextcloud.com/install/ — przy pierwszym uruchomieniu wpisz adres " . $host . '.' : '')
              . "\n\nNikt z Fundacji nie poprosi Cię o hasło.";
        $send = self::$sendMail ?? (function (string $to, string $nm, string $subj, string $h, string $t): bool {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            return (bool)mail_queue_add($to, $nm, $subj, $h, $t, 'sso_cloud_account');
        });
        return $send($email, $name, $title, $html, $text) ? 'wysłany' : 'nie wyszedł';
    }

    /**
     * Konto w chmurze z hasłem z Logto: zakłada (gdy $create) albo dowiązuje istniejące i ustawia mu to samo hasło.
     * Nowe konto dostaje login imie.nazwisko (cloudUidBase + wolny numer), nie nazwę z Logto (ta ma „_” zamiast kropki).
     */
    private static function cloudEnsure(string $email, string $username, string $name, string $password, bool $create, string $by, string $login = ''): string
    {
        $cloud = SsoConfig::cloud();
        $row = self::get($email);
        if ((string)($row['cloud_uid'] ?? '') !== '') {
            $uid = (string)$row['cloud_uid'];
            $info = $cloud->user($uid);
        } else {
            [$uid, $info] = self::cloudFreeUid($cloud, self::cloudUidBase($name, $login, $email), $email);
        }
        if ($info !== null) {
            $cloud->setPassword($uid, $password);
            $state = empty($row['cloud_uid']) ? 'linked' : 'synced';
            self::upsert($email, ['cloud_uid' => $uid] + (empty($row['cloud_uid']) ? ['cloud_created_at' => date('Y-m-d H:i:s'), 'cloud_by' => $by] : []));
        } elseif ($create) {
            $cloud->createUser($uid, $password, $name, $email, SsoConfig::cloudGroup(), SsoConfig::cloudQuota());
            $state = 'created';
            self::upsert($email, ['cloud_uid' => $uid, 'cloud_created_at' => date('Y-m-d H:i:s'), 'cloud_by' => $by]);
        } else {
            // konto w chmurze zniknęło (usunięte ręcznie) — odpinamy, żeby SZO nie udawał, że istnieje
            self::upsert($email, ['cloud_uid' => '']);
            return 'usunięte w chmurze';
        }
        self::audit('sso.cloud_' . $state, ['email' => $email, 'uid' => $uid]);
        return $state;
    }

    // ── Zmiana loginów w chmurze z „_” na imie.nazwisko (polecenie 2026-11-13) ─────────────────────────────────

    /** Podmiana wysyłek w testach: fn($to, $name, $subject, $html, $text): bool oraz fn($phone, $message): bool. */
    public static ?\Closure $sendSms = null;

    /** Konta w chmurze z „_” w loginie (dawny login z nazwy Logto): [['email','name','old','new','phone'], …] — tylko gdy nowy login jest inny. */
    public static function cloudUnderscoreList(): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT email, name, user_id, cloud_uid FROM sso_accounts WHERE COALESCE(cloud_uid,'') <> '' ORDER BY name") as $r) {
            $old = (string)$r['cloud_uid'];
            if (!str_contains($old, '_')) continue;
            $p = self::cloudPerson((string)$r['email']);
            $new = self::cloudUidBase($p['name'], $p['login'], (string)$r['email']);
            if ($new === $old) continue;
            $out[] = ['email' => (string)$r['email'], 'name' => (string)($p['name'] ?: $r['name']), 'old' => $old, 'new' => $new, 'phone' => $p['phone']];
        }
        return $out;
    }

    /**
     * Dane osoby do loginu w chmurze: konto SZO / umowa (personContact), nadpisane niepustymi polami $person (np. z karty umowy).
     * Jedno źródło dla propozycji na karcie umowy, listy na /ssoadmin i samej zmiany loginu. [name, login, phone].
     */
    public static function cloudPerson(string $email, ?array $person = null): array
    {
        $row = self::get(strtolower(trim($email)));
        $p = self::personContact($email, isset($row['user_id']) ? (int)$row['user_id'] : null);
        foreach (['name', 'login', 'phone'] as $k) { if (trim((string)($person[$k] ?? '')) !== '') $p[$k] = trim((string)$person[$k]); }
        if ($p['name'] === '') $p['name'] = (string)($row['name'] ?? '');
        return $p;
    }

    /** Proponowany login w chmurze (imie.nazwisko) dla osoby — to samo, co dostanie przy zmianie, o ile login nie jest zajęty. */
    public static function cloudUidProposal(string $email, ?array $person = null): string
    {
        $p = self::cloudPerson($email, $person);
        return self::cloudUidBase($p['name'], $p['login'], $email);
    }

    /** Imię i nazwisko, login organizacji i telefon osoby: konto SZO (user_id albo e-mail), potem najnowsza umowa z tym e-mailem. */
    private static function personContact(string $email, ?int $userId): array
    {
        $u = $userId ? db_one("SELECT name, m365_login, phone_number FROM users WHERE id=?", [$userId]) : null;
        $u = $u ?: db_one("SELECT name, m365_login, phone_number FROM users WHERE LOWER(email)=?", [strtolower($email)]);
        $p = ['name' => trim((string)($u['name'] ?? '')), 'login' => trim((string)($u['m365_login'] ?? '')), 'phone' => trim((string)($u['phone_number'] ?? ''))];
        if ($p['phone'] === '' || $p['name'] === '') {
            foreach (['wolontariat', 'zlecenie', 'dzielo'] as $t) {
                try { $c = db_one("SELECT imie_nazwisko, telefon, m365_login FROM umowy_{$t} WHERE LOWER(email)=? ORDER BY id DESC LIMIT 1", [strtolower($email)]); }
                catch (\Throwable $e) { $c = null; }
                if (!$c) continue;
                if ($p['name'] === '') $p['name'] = trim((string)($c['imie_nazwisko'] ?? ''));
                if ($p['phone'] === '') $p['phone'] = trim((string)($c['telefon'] ?? ''));
                if ($p['login'] === '') $p['login'] = trim((string)($c['m365_login'] ?? ''));
                break;
            }
        }
        return $p;
    }

    /**
     * Zmiana loginu w chmurze z „…_…” na imie.nazwisko. Loginu w Nextcloud nie da się zmienić, więc: nowe konto z NOWYM hasłem
     * (to samo hasło dostaje Logto — zasada jednego hasła), stare konto WYŁĄCZONE (pliki zostają, nic nie kasujemy), kopiowanie plików
     * z OneDrive od nowa na nowe konto, jeśli było zlecone dla starego. Potem SMS (login + hasło) i e-mail (bez hasła).
     * $person (opcjonalnie, np. z umowy: name, login, phone) ma pierwszeństwo przed danymi z konta SZO — wtedy nowy login zgadza się
     * z propozycją pokazaną na karcie umowy. Zwraca ['email','name','old','new','password','logto','old_disabled','files','sms','mail','error'].
     */
    public static function renameCloudLogin(string $email, string $by, ?array $person = null): array
    {
        $email = strtolower(trim($email));
        $row = self::get($email);
        $old = (string)($row['cloud_uid'] ?? '');
        $p = self::cloudPerson($email, $person);
        $name = $p['name'];
        $res = ['email' => $email, 'name' => $name, 'old' => $old, 'new' => '', 'password' => null, 'logto' => '', 'old_disabled' => '', 'files' => '', 'sms' => '', 'mail' => '', 'error' => ''];
        if ($old === '' || !str_contains($old, '_')) { $res['error'] = 'login w chmurze nie ma „_” — bez zmian'; return $res; }
        if (!SsoConfig::cloudConfigured()) { $res['error'] = 'chmura nie jest skonfigurowana'; return $res; }
        $cloud = SsoConfig::cloud();
        $password = SsoConfig::generatePassword();
        try {
            [$new, $info] = self::cloudFreeUid($cloud, self::cloudUidBase($name, $p['login'], $email), $email, $old);
            if ($info !== null) $cloud->setPassword($new, $password);
            else $cloud->createUser($new, $password, $name, $email, SsoConfig::cloudGroup(), SsoConfig::cloudQuota());
        } catch (\Throwable $e) {
            $res['error'] = 'nie założono nowego konta w chmurze: ' . $e->getMessage();
            return $res;
        }
        $res['new'] = $new;
        $res['password'] = $password;
        $now = date('Y-m-d H:i:s');
        self::upsert($email, ['cloud_uid' => $new, 'cloud_created_at' => $now, 'cloud_by' => $by]);
        // Logto: to samo nowe hasło (jedno hasło do logowania jednokrotnego i chmury)
        if (!empty($row['logto_id']) && SsoConfig::logtoConfigured()) {
            try { SsoConfig::logto()->setPassword((string)$row['logto_id'], $password); self::upsert($email, ['logto_pwd_at' => $now, 'logto_pwd_reset_at' => '']); $res['logto'] = 'to samo hasło'; }
            catch (\Throwable $e) { $res['logto'] = 'błąd: ' . $e->getMessage(); }
        }
        // stare konto: wyłączamy (pliki zostają u administratora chmury)
        try { $cloud->disableUser($old); $res['old_disabled'] = 'wyłączone'; } catch (\Throwable $e) { $res['old_disabled'] = 'błąd: ' . $e->getMessage(); }
        // pliki z OneDrive: od nowa na nowe konto, gdy były zlecone dla starego
        try {
            CloudFilesCopy::migrate();
            $last = db_one("SELECT source FROM sso_files_jobs WHERE cloud_uid=? ORDER BY id DESC LIMIT 1", [$old]);
            db()->prepare("UPDATE sso_files_jobs SET status='cancelled', password_enc=NULL, updated_at=? WHERE cloud_uid=? AND status IN ('queued','running','waiting')")->execute([$now, $old]);
            if ($last && (string)$last['source'] !== '' && GraphDrive::configured()) $res['files'] = 'zadanie #' . CloudFilesCopy::queue($new, (string)$last['source'], $password, $email, $by);
        } catch (\Throwable $e) { $res['files'] = 'błąd: ' . $e->getMessage(); }
        self::audit('sso.cloud_rename', ['email' => $email, 'old' => $old, 'new' => $new, 'by' => $by]);
        $res['sms'] = self::renameSms($p['phone'], $old, $new, $password, $res['logto'] === 'to samo hasło');
        $res['mail'] = self::renameMail($email, $name, $old, $new, $res['logto'] === 'to samo hasło', $p['phone'] !== '' && $res['sms'] === 'wysłano', $res['files'] !== '' && !str_starts_with($res['files'], 'błąd'));
        return $res;
    }

    private static function renameSms(string $phone, string $old, string $new, string $password, bool $logto): string
    {
        if ($phone === '') return 'brak numeru';
        try {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            if (!function_exists('sms_is_mobile') || !sms_is_mobile($phone)) return 'nie komórka';
            $host = preg_replace('~^https?://~', '', SsoConfig::cloudUrl());
            $msg = SsoConfig::smsPrefix() . ': zmienilismy login w chmurze plikow. Nowy login: ' . $new . ' Haslo: ' . $password
                 . ' (obowiazuje od ' . date('d.m.y H:i:s') . ').' . ($logto ? ' To samo haslo do logowania jednokrotnego.' : '') . ' Strona: ' . $host;
            $msg = function_exists('sms_ascii') ? sms_ascii($msg) : $msg;
            if (self::$sendSms) return (self::$sendSms)($phone, $msg) ? 'wysłano' : 'nie wyszedł';
            if (function_exists('sms_channel_ready') && !sms_channel_ready()) return 'brak dostawcy SMS';
            sms_send($phone, $msg, 'access_data');
            return 'wysłano';
        } catch (\Throwable $e) {
            return 'błąd SMS';
        }
    }

    private static function renameMail(string $email, string $name, string $old, string $new, bool $logto, bool $smsOk, bool $files): string
    {
        $url = SsoConfig::cloudUrl();
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
        $e = fn(string $x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
        $title = 'Zmieniliśmy Twój login w chmurze Fundacji';
        $lines = [
            'Nowy login: ' . $new . ' (dotąd: ' . $old . '). Możesz też logować się adresem ' . $email . '.',
            $smsOk ? 'Nowe hasło wysłaliśmy SMS-em. Hasła nie wysyłamy e-mailem.' : 'Nowe hasło przekaże Ci administrator. Hasła nie wysyłamy e-mailem.',
        ];
        if ($logto) $lines[] = 'To samo nowe hasło działa w logowaniu jednokrotnym. Stare hasło przestało działać.';
        $lines[] = 'Stare konto (' . $old . ') wyłączyliśmy. Jeśli wgrałeś(-aś) tam pliki, nie zginęły: napisz do administratora, a przeniesiemy je.';
        if ($files) $lines[] = 'Pliki z OneDrive kopiujemy jeszcze raz, tym razem na nowe konto. Nic nie musisz robić.';
        $lines[] = 'W aplikacji na komputerze i telefonie wyloguj się i zaloguj ponownie nowym loginem.';
        $html = '<p>Dzień dobry' . ($name !== '' ? ', ' . $e($name) : '') . ',</p>'
              . '<p>zmieniliśmy login do Twojego konta w chmurze Fundacji (<a href="' . $e($url) . '">' . $e($host) . '</a>). Loginy mają teraz postać imie.nazwisko.</p><ul>';
        foreach ($lines as $l) $html .= '<li>' . $e($l) . '</li>';
        $html .= '</ul><p>Logowanie: <a href="' . $e($url) . '">' . $e($url) . '</a></p>'
               . '<p style="color:#555">Nikt z Fundacji nie poprosi Cię o hasło. Jeśli ktoś o nie prosi, to próba oszustwa.</p>';
        $text = 'Zmieniliśmy login do Twojego konta w chmurze Fundacji (' . $url . '). Loginy mają teraz postać imie.nazwisko.' . "\n\n- "
              . implode("\n- ", $lines) . "\n\nLogowanie: " . $url . "\nNikt z Fundacji nie poprosi Cię o hasło.";
        try {
            $send = self::$sendMail ?? (function (string $to, string $nm, string $subj, string $h, string $t): bool {
                require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                return (bool)mail_queue_add($to, $nm, $subj, $h, $t, 'sso_cloud_rename');
            });
            return $send($email, $name, $title, $html, $text) ? 'wysłany' : 'nie wyszedł';
        } catch (\Throwable $ex) {
            error_log('[sso] e-mail o zmianie loginu ' . $email . ': ' . $ex->getMessage());
            return 'błąd';
        }
    }

    /** Nowe hasło (użytkownik w Tożsamości albo administrator) — Logto i chmura naraz. */
    public static function setPassword(string $email, ?string $password, string $by): array
    {
        $row = self::get($email);
        if (!$row || empty($row['logto_id'])) throw new RuntimeException('Ta osoba nie ma jeszcze konta logowania jednokrotnego.');
        $generated = $password === null || $password === '';
        $password = $generated ? SsoConfig::generatePassword() : $password;
        SsoConfig::logto()->setPassword((string)$row['logto_id'], $password);
        self::upsert($email, ['logto_pwd_at' => date('Y-m-d H:i:s'), 'logto_pwd_reset_at' => '']);
        $cloud = '';
        if (!empty($row['cloud_uid']) && SsoConfig::cloudConfigured()) {
            try { $cloud = self::cloudEnsure($email, (string)$row['logto_username'], (string)$row['name'], $password, false, $by); }
            catch (\Throwable $e) { $cloud = 'błąd: ' . $e->getMessage(); }
        }
        self::audit('sso.password', ['email' => $email, 'by' => $by, 'cloud' => $cloud]);
        return ['email' => $email, 'username' => (string)$row['logto_username'], 'password' => $generated ? $password : null, 'logto' => 'password', 'cloud' => $cloud, 'job' => null];
    }

    // ── Webhook Logto ──────────────────────────────────────────────────────────────────────

    /** Konto założone/zmienione poza SZO (konsola Logto, rejestracja): dowiązanie po e-mailu. Zwraca e-mail albo ''. */
    public static function linkFromLogto(array $u): string
    {
        $id = (string)($u['id'] ?? '');
        $email = strtolower(trim((string)($u['primaryEmail'] ?? '')));
        if ($id === '') return '';
        $row = self::byLogtoId($id);
        if ($row) {
            self::upsert((string)$row['email'], array_filter(['logto_username' => (string)($u['username'] ?? '')], fn($v) => $v !== ''));
            return (string)$row['email'];
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
        $user = db_one("SELECT id, name FROM users WHERE LOWER(email)=?", [$email]);
        $cur = self::get($email);
        if ($cur && !empty($cur['logto_id']) && $cur['logto_id'] !== $id) return '';   // e-mail ma już inne konto — nie przepinamy po cichu
        self::upsert($email, array_filter([
            'user_id' => $user ? (int)$user['id'] : null,
            'name' => (string)($u['name'] ?? ($user['name'] ?? '')),
            'logto_id' => $id,
            'logto_username' => (string)($u['username'] ?? ''),
            'logto_created_at' => $cur['logto_created_at'] ?? date('Y-m-d H:i:s'),
            'logto_by' => 'Logto',
        ], fn($v) => $v !== null));
        return $email;
    }

    public static function touchSignIn(string $logtoId): string
    {
        $row = self::byLogtoId($logtoId);
        if (!$row) return '';
        self::upsert((string)$row['email'], ['last_sign_in_at' => date('Y-m-d H:i:s')]);
        return (string)$row['email'];
    }

    public static function markPasswordResetInLogto(string $logtoId): string
    {
        $row = self::byLogtoId($logtoId);
        if (!$row) return '';
        self::upsert((string)$row['email'], ['logto_pwd_reset_at' => date('Y-m-d H:i:s')]);
        return (string)$row['email'];
    }

    public static function unlinkLogto(string $logtoId): string
    {
        $row = self::byLogtoId($logtoId);
        if (!$row) return '';
        self::upsert((string)$row['email'], ['logto_id' => '', 'logto_username' => '']);
        return (string)$row['email'];
    }

    /** Dziennik webhooka; false = to doręczenie już było (Logto ponawia z tym samym deliveryId). */
    public static function logDelivery(string $deliveryId, string $event, string $logtoId, string $email, string $note): bool
    {
        self::migrate();
        if ($deliveryId === '') $deliveryId = 'brak-' . bin2hex(random_bytes(8));
        if (db_one("SELECT delivery_id FROM sso_webhook_log WHERE delivery_id=?", [$deliveryId])) return false;
        db()->prepare("INSERT INTO sso_webhook_log (delivery_id, event, logto_id, email, note, created_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$deliveryId, mb_substr($event, 0, 64), mb_substr($logtoId, 0, 64), mb_substr($email, 0, 190), mb_substr($note, 0, 255), date('Y-m-d H:i:s')]);
        return true;
    }

    public static function recentDeliveries(int $limit = 10): array
    {
        self::migrate();
        return db_all("SELECT * FROM sso_webhook_log ORDER BY created_at DESC LIMIT " . max(1, $limit)) ?: [];
    }

    private static function audit(string $action, array $data): void
    {
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($action, $data);
        } catch (\Throwable $e) { error_log('[sso] audit_log: ' . $e->getMessage()); }
    }
}
