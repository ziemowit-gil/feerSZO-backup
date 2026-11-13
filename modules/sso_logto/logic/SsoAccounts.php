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
            return self::provisionCloud($person, $opt, $out, $email, $username, $name, $password, $by);
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
                $out['cloud'] = self::cloudEnsure($email, $username, $name, $password, !empty($opt['cloud']), $by);
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

    /** Konto w chmurze z hasłem z Logto: zakłada (gdy $create) albo dowiązuje istniejące i ustawia mu to samo hasło. */
    private static function cloudEnsure(string $email, string $username, string $name, string $password, bool $create, string $by): string
    {
        $cloud = SsoConfig::cloud();
        $row = self::get($email);
        $uid = (string)($row['cloud_uid'] ?? '') !== '' ? (string)$row['cloud_uid'] : strtolower($username);
        $info = $cloud->user($uid);
        if ($info !== null) {
            $theirs = strtolower(trim((string)($info['email'] ?? '')));
            if (empty($row['cloud_uid']) && $theirs !== '' && $theirs !== $email) {
                throw new RuntimeException('login „' . $uid . '” w chmurze należy do innego adresu (' . $theirs . ').');
            }
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
