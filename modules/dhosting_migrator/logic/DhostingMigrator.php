<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingMigrator.php — migrator kont Microsoft 365 → skrzynki pocztowe dhosting.pl.
 *
 * Dwie drogi (polecenie 2026-11-13):
 *  • administrator: lista kont z Microsoft 365 (Graph) obok stanu na dhosting → zaznacza konta → system zakłada skrzynki
 *    z TYM SAMYM loginem (domena wg mapy w ustawieniach) i wygenerowanym hasłem; hasła widzi raz, po zapisie,
 *    i może je od razu wysłać SMS-em na numer z konta SZO;
 *  • użytkownik po zalogowaniu: zakłada swoją skrzynkę (albo dostaje nowe hasło do istniejącej) — hasło idzie SMS-em
 *    na numer z jego konta, na ekranie go nie pokazujemy.
 *
 * Haseł nie zapisujemy w bazie — Microsoft Graph nie pozwala ich odczytać, więc „kopiowanie” konta to zawsze nowe hasło.
 * Tabela dhosting_mailboxes to rejestr: kto, kiedy, kim i czy poszedł SMS (numer zamaskowany).
 */
require_once dirname(__DIR__) . '/lib/DhostingConfig.php';
require_once dirname(__DIR__) . '/lib/DhostingGraphSource.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';

final class DhostingMigrator
{
    /** Ile kont naraz w jednym żądaniu admina (każde = 1–2 wywołania API). */
    public const BATCH_MAX = 40;
    /** Odstęp między samodzielnymi akcjami użytkownika (zakładanie / nowe hasło). */
    public const SELF_COOLDOWN = 600;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('dhosting_migrator_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_mailboxes (
            mailbox VARCHAR(190) PRIMARY KEY,
            user_id INTEGER NULL,
            m365_id VARCHAR(64) NOT NULL DEFAULT '',
            source_login VARCHAR(190) NOT NULL DEFAULT '',
            display_name VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT '',
            via VARCHAR(10) NOT NULL DEFAULT '',
            created_at VARCHAR(19) NULL,
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            password_set_at VARCHAR(19) NULL,
            sms_at VARCHAR(19) NULL,
            sms_to VARCHAR(32) NOT NULL DEFAULT '',
            last_action_at VARCHAR(19) NULL,
            last_error TEXT NULL)");
        try { db()->exec("CREATE INDEX idx_dhosting_mailboxes_user ON dhosting_mailboxes(user_id)"); } catch (\Throwable $e) { /* jest */ }
    }

    /** Rejestr: mailbox => wiersz. */
    public static function registry(): array
    {
        self::migrate();
        $out = [];
        foreach (db()->query("SELECT * FROM dhosting_mailboxes")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['mailbox']] = $r;
        return $out;
    }

    public static function registryRow(string $mailbox): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_mailboxes WHERE mailbox=?", [strtolower($mailbox)]);
        return $r ?: null;
    }

    private static function record(string $mailbox, array $data): void
    {
        self::migrate();
        $mailbox = strtolower($mailbox);
        $now = date('Y-m-d H:i:s');
        $data['last_action_at'] = $now;
        if (self::registryRow($mailbox)) {
            $sets = implode(', ', array_map(fn($k) => $k . '=?', array_keys($data)));
            db()->prepare("UPDATE dhosting_mailboxes SET {$sets} WHERE mailbox=?")->execute([...array_values($data), $mailbox]);
        } else {
            $data = ['mailbox' => $mailbox] + $data;
            $cols = implode(', ', array_keys($data));
            $qs   = implode(', ', array_fill(0, count($data), '?'));
            db()->prepare("INSERT INTO dhosting_mailboxes ({$cols}) VALUES ({$qs})")->execute(array_values($data));
        }
    }

    // ── Źródło: konta Microsoft 365 ────────────────────────────────────────────────────────────

    /**
     * Konta z Microsoft 365 w obsługiwanych domenach, połączone z kontami SZO (telefon) i stanem na dhosting.
     * Zwraca ['accounts' => [...], 'errors' => [...], 'domains' => [domena => liczba skrzynek na dhosting|null]].
     * $graphUsers: lista kont podana z zewnątrz (test na atrapie) — null = pobierz z Microsoft 365.
     */
    public static function adminOverview(?array $graphUsers = null): array
    {
        $errors = [];
        $domains = DhostingConfig::domains();
        if (!$domains) $errors[] = 'Nie ustawiono domen — wpisz w konfiguracji API, które domeny Microsoft 365 przenosimy na dhosting.';

        if ($graphUsers === null) {
            $graphUsers = [];
            if ($domains) {
                try { $graphUsers = DhostingGraphSource::users(array_keys($domains)); }
                catch (\Throwable $e) { $errors[] = 'Microsoft 365: ' . $e->getMessage(); }
            }
        }

        // Stan na dhosting — jedna lista na domenę docelową.
        $onDhosting = [];
        $domainCounts = [];
        $api = DhostingConfig::api();
        foreach (array_unique(array_values($domains)) as $dst) {
            if (!$api->configured()) { $domainCounts[$dst] = null; continue; }
            try {
                $list = $api->getMailboxList($dst);
                $domainCounts[$dst] = count($list);
                foreach ($list as $m) $onDhosting[$m] = true;
            } catch (\Throwable $e) {
                $domainCounts[$dst] = null;
                $errors[] = 'dhosting (' . $dst . '): ' . $e->getMessage();
            }
        }
        if (!$api->configured()) $errors[] = 'Brak danych API dhosting — uzupełnij konfigurację API, żeby widzieć stan skrzynek i zakładać nowe.';

        $local = self::localUsersIndex();
        $reg = self::registry();
        $accounts = [];
        foreach ($graphUsers as $g) {
            $login = strtolower((string)$g['userPrincipalName']);
            $box = DhostingConfig::mailboxFor($login);
            if ($box === null) continue;
            $u = $local['ms'][(string)$g['id']] ?? $local['login'][$login] ?? null;
            $phone = (string)($u['phone_number'] ?? '');
            $accounts[] = [
                'm365_id'   => (string)$g['id'],
                'login'     => $login,
                'name'      => (string)($g['displayName'] ?? $login),
                'enabled'   => (bool)($g['accountEnabled'] ?? true),
                'mailbox'   => $box,
                'user_id'   => $u ? (int)$u['id'] : null,
                'phone'     => $phone,
                'mobile'    => $phone !== '' && sms_is_mobile($phone),
                'exists'    => isset($onDhosting[$box]),
                'registry'  => $reg[$box] ?? null,
            ];
        }
        usort($accounts, fn($a, $b) => strcoll(mb_strtolower($a['name']), mb_strtolower($b['name'])));
        return ['accounts' => $accounts, 'errors' => $errors, 'domains' => $domainCounts];
    }

    /** Indeks kont SZO po microsoft_id oraz po loginie (m365_login, e-mail). */
    private static function localUsersIndex(): array
    {
        $idx = ['ms' => [], 'login' => []];
        $rows = db()->query("SELECT id, email, phone_number, microsoft_id, m365_login FROM users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!empty($r['microsoft_id'])) $idx['ms'][(string)$r['microsoft_id']] = $r;
            foreach (['m365_login', 'email'] as $k) {
                $l = strtolower(trim((string)($r[$k] ?? '')));
                if ($l !== '' && !isset($idx['login'][$l])) $idx['login'][$l] = $r;
            }
        }
        return $idx;
    }

    // ── Administrator: zakładanie skrzynek ─────────────────────────────────────────────────────

    /**
     * Zakłada skrzynki dla wybranych kont Microsoft 365 (po id z Graph).
     * $resetExisting: dla skrzynek, które już są na dhosting, ustaw nowe hasło (inaczej pomiń).
     * $sendSms: wyślij hasło SMS-em na numer z konta (tylko numery komórkowe).
     * Zwraca listę wyników: mailbox, name, password (tylko gdy ustawiono), status ok|exists|error, msg, sms.
     */
    public static function adminCreate(array $m365Ids, bool $resetExisting, bool $sendSms, string $by, ?array $graphUsers = null): array
    {
        $want = array_flip(array_map('strval', $m365Ids));
        $overview = self::adminOverview($graphUsers);
        $api = DhostingConfig::api();
        if (!$api->configured()) throw new RuntimeException('Brak danych API dhosting — uzupełnij ustawienia.');

        $results = [];
        foreach ($overview['accounts'] as $a) {
            if (!isset($want[$a['m365_id']])) continue;
            if (count($results) >= self::BATCH_MAX) break;
            $res = ['mailbox' => $a['mailbox'], 'name' => $a['name'], 'password' => null, 'status' => 'error', 'msg' => '', 'sms' => ''];
            $password = DhostingConfig::generatePassword();
            $base = ['user_id' => $a['user_id'], 'm365_id' => $a['m365_id'], 'source_login' => $a['login'], 'display_name' => $a['name']];
            try {
                if ($a['exists']) {
                    if (!$resetExisting) {
                        $res['status'] = 'exists';
                        $res['msg'] = 'Skrzynka już jest na dhosting — pominięta (bez zmiany hasła).';
                        $results[] = $res;
                        continue;
                    }
                    $api->changeMailboxPassword($a['mailbox'], $password);
                    $res['msg'] = 'Skrzynka już była — ustawiono nowe hasło.';
                } else {
                    $api->createMailbox($a['mailbox'], $password, $a['mobile'] ? $a['phone'] : null, DhostingConfig::quota(),
                        'Migracja z Microsoft 365 (' . $a['login'] . ')');
                    $res['msg'] = 'Założono skrzynkę.';
                }
                $res['status'] = 'ok';
                $res['password'] = $password;
                self::record($a['mailbox'], $base + [
                    'status' => 'created', 'via' => 'admin', 'created_by' => $by,
                    'password_set_at' => date('Y-m-d H:i:s'), 'last_error' => null,
                ] + ($a['exists'] ? [] : ['created_at' => date('Y-m-d H:i:s')]));
            } catch (\Throwable $e) {
                $res['msg'] = $e->getMessage();
                self::record($a['mailbox'], $base + ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
                $results[] = $res;
                continue;
            }

            if ($sendSms) {
                if (!$a['mobile']) {
                    $res['sms'] = 'brak numeru komórkowego w koncie';
                } else {
                    try {
                        self::sendPasswordSms($a['phone'], $a['mailbox'], $password, false);
                        $res['sms'] = 'wysłano na ' . self::maskPhone($a['phone']);
                        self::record($a['mailbox'], ['sms_at' => date('Y-m-d H:i:s'), 'sms_to' => self::maskPhone($a['phone'])]);
                    } catch (\Throwable $e) {
                        $res['sms'] = 'błąd SMS: ' . $e->getMessage();
                    }
                }
            }
            $results[] = $res;
        }
        return $results;
    }

    // ── Użytkownik: własna skrzynka ────────────────────────────────────────────────────────────

    /**
     * Stan dla zalogowanego użytkownika: login Microsoft, adres skrzynki, telefon, czy skrzynka istnieje, co blokuje akcję.
     * $u = wiersz users (id, email, phone_number, microsoft_id, m365_login).
     */
    public static function selfState(array $u): array
    {
        $login = self::loginForUser($u);
        $box = $login !== '' ? DhostingConfig::mailboxFor($login) : null;
        $phone = (string)($u['phone_number'] ?? '');
        $st = [
            'login' => $login, 'mailbox' => $box, 'phone' => $phone, 'phoneMasked' => $phone !== '' ? self::maskPhone($phone) : '',
            'mobile' => $phone !== '' && sms_is_mobile($phone), 'exists' => null, 'problem' => '', 'wait' => 0,
            'registry' => $box ? self::registryRow($box) : null,
        ];
        if (!DhostingConfig::selfEnabled()) { $st['problem'] = 'Zakładanie skrzynek przez użytkowników jest wyłączone. Skontaktuj się z administratorem.'; return $st; }
        if ($login === '') { $st['problem'] = 'Twoje konto nie jest połączone z kontem Microsoft 365, więc nie wiemy, jaki login ma mieć skrzynka. Zaloguj się przez Microsoft albo poproś administratora.'; return $st; }
        if ($box === null) { $st['problem'] = 'Login ' . $login . ' jest w domenie, której nie przenosimy na dhosting.'; return $st; }

        $api = DhostingConfig::api();
        if (!$api->configured()) { $st['problem'] = 'Usługa nie jest jeszcze skonfigurowana. Skontaktuj się z administratorem.'; return $st; }
        try {
            $st['exists'] = in_array($box, $api->getMailboxList(substr($box, strrpos($box, '@') + 1)), true);
        } catch (\Throwable $e) {
            $st['problem'] = 'Nie udało się sprawdzić stanu skrzynki na dhosting. Spróbuj za chwilę. (' . $e->getMessage() . ')';
            return $st;
        }
        if (!$st['mobile']) { $st['problem'] = 'Hasło wysyłamy SMS-em, a w Twoim koncie nie ma numeru komórkowego. Dodaj go w Ustawieniach konta.'; return $st; }

        $last = (string)($st['registry']['last_action_at'] ?? '');
        if ($last !== '' && ($st['registry']['via'] ?? '') === 'self') {
            $left = self::SELF_COOLDOWN - (time() - (int)strtotime($last));
            if ($left > 0) $st['wait'] = $left;
        }
        return $st;
    }

    /** Zakłada skrzynkę użytkownika albo ustawia nowe hasło do istniejącej; hasło tylko SMS-em. Zwraca komunikat dla użytkownika. */
    public static function selfProvision(array $u, string $by): string
    {
        $st = self::selfState($u);
        if ($st['problem'] !== '') throw new RuntimeException($st['problem']);
        if ($st['wait'] > 0) throw new RuntimeException('Odczekaj jeszcze ' . (int)ceil($st['wait'] / 60) . ' min przed kolejną próbą.');

        $api = DhostingConfig::api();
        $password = DhostingConfig::generatePassword();
        $box = (string)$st['mailbox'];
        $base = ['user_id' => (int)$u['id'], 'm365_id' => (string)($u['microsoft_id'] ?? ''), 'source_login' => $st['login'],
                 'display_name' => (string)($u['name'] ?? ''), 'via' => 'self', 'created_by' => $by];
        try {
            if ($st['exists']) {
                $api->changeMailboxPassword($box, $password);
                try { $api->changeMailboxPhoneNumber($box, $st['phone']); } catch (\Throwable $e) { /* ten sam numer albo chwilowo zajęta — bez znaczenia */ }
            } else {
                $api->createMailbox($box, $password, $st['phone'], DhostingConfig::quota(), 'Założona przez użytkownika (' . $st['login'] . ')');
            }
        } catch (\Throwable $e) {
            self::record($box, $base + ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new RuntimeException('dhosting nie przyjął zmiany: ' . $e->getMessage());
        }
        self::record($box, $base + ['status' => 'created', 'password_set_at' => date('Y-m-d H:i:s'), 'last_error' => null]
            + ($st['exists'] ? [] : ['created_at' => date('Y-m-d H:i:s')]));

        try {
            self::sendPasswordSms($st['phone'], $box, $password, true);
            self::record($box, ['sms_at' => date('Y-m-d H:i:s'), 'sms_to' => $st['phoneMasked']]);
        } catch (\Throwable $e) {
            // Skrzynka jest, ale SMS nie doszedł — hasła nigdzie nie zapisaliśmy, więc użytkownik musi poprosić o kolejne.
            self::record($box, ['last_error' => 'SMS: ' . mb_substr($e->getMessage(), 0, 450)]);
            throw new RuntimeException(($st['exists'] ? 'Hasło zmieniono' : 'Skrzynkę założono') . ', ale SMS z hasłem nie wyszedł (' . $e->getMessage()
                . '). Za ' . (int)ceil(self::SELF_COOLDOWN / 60) . ' min poproś o nowe hasło albo skontaktuj się z administratorem.');
        }
        return ($st['exists'] ? 'Ustawiliśmy nowe hasło do skrzynki ' : 'Założyliśmy skrzynkę ') . $box
            . '. Hasło wysłaliśmy SMS-em na numer ' . $st['phoneMasked'] . '.';
    }

    /** Login Microsoft użytkownika: m365_login, a gdy go brak — e-mail konta (jeśli jest w obsługiwanej domenie), na końcu Graph po microsoft_id. */
    public static function loginForUser(array $u): string
    {
        foreach ([(string)($u['m365_login'] ?? ''), (string)($u['email'] ?? '')] as $l) {
            $l = strtolower(trim($l));
            if ($l !== '' && DhostingConfig::mailboxFor($l) !== null) return $l;
        }
        $ms = (string)($u['microsoft_id'] ?? '');
        if ($ms !== '') {
            try { return strtolower((string)(DhostingGraphSource::user($ms)['userPrincipalName'] ?? '')); } catch (\Throwable $e) { return ''; }
        }
        return '';
    }

    // ── SMS ────────────────────────────────────────────────────────────────────────────────────

    /** SMS z hasłem (rodzaj access_data). $bypassQuiet: użytkownik sam poprosił — wychodzi też w nocy, jak kod. */
    public static function sendPasswordSms(string $phone, string $mailbox, string $password, bool $bypassQuiet): void
    {
        $web = DhostingConfig::webmail();
        $msg = 'FEER: poczta ' . $mailbox . "\nHaslo: " . $password . ($web !== '' ? "\nLogowanie: " . $web : '');
        $prev = $GLOBALS['__sms_quiet_bypass'] ?? null;
        if ($bypassQuiet) $GLOBALS['__sms_quiet_bypass'] = true;
        try {
            sms_send($phone, $msg, 'access_data');
        } finally {
            if ($bypassQuiet) { if ($prev === null) unset($GLOBALS['__sms_quiet_bypass']); else $GLOBALS['__sms_quiet_bypass'] = $prev; }
        }
    }

    public static function maskPhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        return strlen($d) >= 3 ? '••• ••• ' . substr($d, -3) : '•••';
    }
}
