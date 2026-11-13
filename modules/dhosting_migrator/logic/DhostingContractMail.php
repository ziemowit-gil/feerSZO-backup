<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingContractMail.php — skrzynka pocztowa (nowy serwer poczty) przy umowach
 * (polecenie 2026-11-13).
 *
 *  - Nowa umowa (wolontariat, zlecenie, dzieło; pole „Załóż skrzynkę pocztową”, domyślnie zaznaczone): zakłada skrzynkę
 *    imie.nazwisko@domena (unikalną: serwer poczty, SZO i Microsoft 365) albo — gdy ta sama osoba (PESEL) miała już skrzynkę
 *    — przywraca dawną: odblokowuje i ustawia nowe hasło. Hasło: SMS-em na numer komórkowy z umowy (gdy jest) i raz na ekranie
 *    umowy dla administratora. Konto Microsoft 365 przy umowie jest opcjonalne.
 *  - Administrator w SZO (karta „Skrzynka pocztowa” w umowie): nowe hasło (raz na ekranie, opcjonalnie SMS), zawieszenie,
 *    przywrócenie — POST /migracjaadmin/skrzynka.
 *  - CRON (cron/agents/dhosting_suspend.php, co godzinę): GRACE_DAYS dni po wygaśnięciu umowy — gdy osoba nie ma innej
 *    trwającej umowy z tą skrzynką — skrzynka jest zawieszana (status wyłączony) i dostaje losowe hasło, BEZ powiadamiania
 *    użytkownika. Reguły jak przy wyłączaniu Microsoft 365 (m365_should_be_active): „nie wyłączaj”, bezterminowa, okres
 *    ochronny po rozwiązaniu, umowa jeszcze się nie zaczęła. Obejmuje też skrzynki z migracji (umowa po m365_login).
 *  - Tabela dhosting_contract_boxes: umowa → skrzynka, stan (active | suspended), daty.
 * Ustawienia: dhosting_contract_mail ('0' = nie zakładaj przy umowach), dhosting_contract_suspend ('0' = nie zawieszaj).
 */
require_once __DIR__ . '/DhostingMigrator.php';
require_once dirname(__DIR__, 3) . '/modules/field_crypto/lib/fcDb.php';
if (is_file(dirname(__DIR__, 3) . '/modules/contract_status/lib/contractStatuses.php')) require_once dirname(__DIR__, 3) . '/modules/contract_status/lib/contractStatuses.php';

final class DhostingContractMail
{
    public const GRACE_DAYS = 2;
    /** Rodzaj umowy → tabela (te same, które synchronizuje Microsoft 365). */
    public const TABLES = ['wolontariat' => 'umowy_wolontariat', 'zlecenie' => 'umowy_zlecenie', 'dzielo' => 'umowy_dzielo'];
    private const K_NEW = 'dhContractNewBox';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_contract_boxes (
            id INTEGER PRIMARY KEY,
            contract_type VARCHAR(20) NOT NULL,
            contract_id INTEGER NOT NULL,
            mailbox VARCHAR(190) NOT NULL,
            person VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            created_at VARCHAR(19) NULL,
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            suspended_at VARCHAR(19) NULL,
            restored_at VARCHAR(19) NULL,
            last_error TEXT NULL)");
        try { db()->exec("CREATE INDEX idx_dhcb_mailbox ON dhosting_contract_boxes(mailbox)"); } catch (\Throwable $e) {}
        try { db()->exec("CREATE INDEX idx_dhcb_contract ON dhosting_contract_boxes(contract_type, contract_id)"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE dhosting_contract_boxes ADD COLUMN suspend_reason TEXT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE dhosting_contract_boxes ADD COLUMN suspended_by VARCHAR(190) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    }

    /** Zakładanie skrzynek przy umowach dostępne (API skonfigurowane i nie wyłączone ustawieniem). */
    public static function enabled(): bool
    {
        return DhostingConfig::get('dhosting_contract_mail') !== '0' && DhostingConfig::api()->configured() && self::domain() !== '';
    }

    /** Domena nowych skrzynek: pierwsza domena docelowa z konfiguracji migratora. */
    public static function domain(): string
    {
        $d = DhostingConfig::domains();
        return $d ? (string)reset($d) : '';
    }

    // ── Nowa umowa ────────────────────────────────────────────────────────────────────────────

    /**
     * Zakłada skrzynkę dla umowy albo przywraca dawną skrzynkę tej osoby. $data: wiersz umowy (imie_nazwisko, pesel,
     * telefon, numer_umowy…). $preferLogin: adres do użycia (np. konto Microsoft 365 założone w tym samym zapisie).
     * Zwraca ['mailbox','password','action' => created|restored|linked,'sms']; błąd → wyjątek z komunikatem dla admina.
     */
    public static function provision(string $type, int $id, array $data, string $by, ?string $preferLogin = null): array
    {
        self::migrate();
        if (!isset(self::TABLES[$type])) throw new RuntimeException('Nieobsługiwany rodzaj umowy: ' . $type);
        $api = DhostingConfig::api();
        if (!$api->configured()) throw new RuntimeException('Brak danych API serwera poczty — uzupełnij je w Migratorze poczty.');
        $name = trim((string)($data['imie_nazwisko'] ?? ''));
        $domain = self::domain();

        $box = null;
        if ($preferLogin !== null && $preferLogin !== '') $box = DhostingConfig::mailboxFor($preferLogin);
        $box ??= self::priorMailbox($type, $id, $data);
        $list = $api->getMailboxList($box ? substr($box, strrpos($box, '@') + 1) : $domain);
        $box ??= self::newAddress($name, $domain, $list);
        $exists = in_array($box, $list, true);

        $password = DhostingConfig::generatePassword();
        $phone = (string)($data['telefon'] ?? '');
        $mobile = $phone !== '' && sms_is_mobile($phone);
        if ($exists) {
            $api->changeMailboxPassword($box, $password);
            $api->changeMailboxStatus($box, true);
            try { DhostingCopy::passwordChanged($box, $password); } catch (\Throwable $e) { error_log('[dhosting] haslo dla kopiowania ' . $box . ': ' . $e->getMessage()); }
            $action = 'restored';
        } else {
            $api->createMailbox($box, $password, $mobile ? $phone : null, DhostingConfig::quota(),
                'Umowa ' . $type . ' ' . (string)($data['numer_umowy'] ?? '#' . $id));
            $action = 'created';
        }
        $now = date('Y-m-d H:i:s');
        db()->prepare("INSERT INTO dhosting_contract_boxes (contract_type, contract_id, mailbox, person, status, created_at, created_by) VALUES (?, ?, ?, ?, 'active', ?, ?)")
            ->execute([$type, $id, $box, $name, $now, $by]);
        db()->prepare("UPDATE dhosting_contract_boxes SET status='active', restored_at=?, last_error=NULL WHERE mailbox=? AND status='suspended'")
            ->execute([$now, $box]);

        $sms = '';
        if ($mobile) {
            try { DhostingMigrator::sendPasswordSms($phone, $box, $password, false, $action !== 'created'); $sms = 'wysłano na ' . DhostingMigrator::maskPhone($phone); }
            catch (\Throwable $e) { $sms = 'błąd SMS: ' . $e->getMessage(); }
        }
        $res = ['mailbox' => $box, 'password' => $password, 'action' => $action, 'sms' => $sms];
        self::remember($type, $id, $res);
        self::log($type, $id, ($action === 'created' ? 'Założono skrzynkę pocztową ' : 'Przywrócono dawną skrzynkę pocztową ') . $box
            . ($sms !== '' ? ' (hasło: SMS ' . $sms . ')' : ' (hasło przekazuje administrator)'));
        self::audit('dhosting.contract_box_' . $action, ['type' => $type, 'id' => $id, 'mailbox' => $box]);
        return $res;
    }

    /** Dawna skrzynka tej samej osoby: inne umowy z tym samym PESEL-em (nasza tabela albo login Microsoft w przenoszonej domenie). */
    public static function priorMailbox(string $type, int $id, array $data): ?string
    {
        self::migrate();
        $digits = preg_replace('/\D/', '', (string)($data['pesel'] ?? ''));
        if (strlen($digits) !== 11) return null;
        foreach (self::TABLES as $t => $table) {
            try {
                [$w, $pp] = fcMatchPesel($table, 'pesel', $digits);
                $rows = db_all("SELECT id, m365_login FROM {$table} WHERE {$w} ORDER BY id DESC", $pp);
            } catch (\Throwable $e) { continue; }
            foreach ($rows as $r) {
                if ($t === $type && (int)$r['id'] === $id) continue;
                $b = db_one("SELECT mailbox FROM dhosting_contract_boxes WHERE contract_type=? AND contract_id=? ORDER BY id DESC LIMIT 1", [$t, (int)$r['id']]);
                if ($b) return (string)$b['mailbox'];
                $m = (string)($r['m365_login'] ?? '');
                if ($m !== '' && ($box = DhostingConfig::mailboxFor($m))) return $box;
            }
        }
        return null;
    }

    /** imie.nazwisko@domena, a gdy zajęty (serwer poczty, SZO, Microsoft 365) — imie.nazwisko1…99. */
    public static function newAddress(string $name, string $domain, array $taken): string
    {
        require_once dirname(__DIR__, 3) . '/includes/m365.php';
        $base = M365Graph::generate_login($name, $domain);
        [$local] = explode('@', $base, 2);
        if ($local === '' || $local === '.') throw new RuntimeException('Nie da się utworzyć adresu z imienia i nazwiska „' . $name . '”.');
        $taken = array_flip(array_map('strtolower', $taken));
        $graph = null;
        try { $g = new M365Graph(); if ($g->is_configured()) $graph = $g; } catch (\Throwable $e) {}
        for ($i = 0; $i <= 99; $i++) {
            $cand = $i === 0 ? $base : "{$local}{$i}@{$domain}";
            if (isset($taken[$cand]) || self::knownInSzo($cand)) continue;
            if ($graph && $graph->login_exists($cand)) continue;   // adres osoby, której poczty jeszcze nie przeniesiono
            return $cand;
        }
        throw new RuntimeException('Wszystkie warianty adresu ' . $base . ' (1–99) są zajęte.');
    }

    private static function knownInSzo(string $addr): bool
    {
        if (db_one("SELECT id FROM dhosting_contract_boxes WHERE mailbox=? LIMIT 1", [$addr])) return true;
        if (db_one("SELECT id FROM users WHERE LOWER(TRIM(m365_login))=? OR LOWER(TRIM(email))=? LIMIT 1", [$addr, $addr])) return true;
        foreach (self::TABLES as $table) {
            try { if (db_one("SELECT id FROM {$table} WHERE LOWER(TRIM(m365_login))=? LIMIT 1", [$addr])) return true; } catch (\Throwable $e) {}
        }
        return false;
    }

    // ── Administrator ─────────────────────────────────────────────────────────────────────────

    /** Skrzynka umowy: z naszej tabeli albo (skrzynka z migracji) z loginu Microsoft w przenoszonej domenie. */
    public static function boxForContract(string $type, int $id): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_contract_boxes WHERE contract_type=? AND contract_id=? ORDER BY id DESC LIMIT 1", [$type, $id]);
        if ($r) return $r + ['source' => 'umowa'];
        $table = self::TABLES[$type] ?? null;
        if (!$table) return null;
        $m = (string)(db_one("SELECT m365_login FROM {$table} WHERE id=?", [$id])['m365_login'] ?? '');
        $box = $m !== '' ? DhostingConfig::mailboxFor($m) : null;
        if (!$box) return null;
        $s = db_one("SELECT status, suspended_at FROM dhosting_contract_boxes WHERE mailbox=? ORDER BY id DESC LIMIT 1", [$box]);
        return ['mailbox' => $box, 'status' => $s['status'] ?? 'active', 'suspended_at' => $s['suspended_at'] ?? null, 'source' => 'migracja'];
    }

    /** Nowe hasło (administrator). Hasło zwracane raz; SMS tylko gdy $phone komórkowy i $sms. */
    public static function adminPassword(string $mailbox, string $by, bool $sms, string $phone = '', ?array $contract = null): array
    {
        $api = DhostingConfig::api();
        $password = DhostingConfig::generatePassword();
        $api->changeMailboxPassword($mailbox, $password);
        try { DhostingCopy::passwordChanged($mailbox, $password); } catch (\Throwable $e) { error_log('[dhosting] haslo dla kopiowania ' . $mailbox . ': ' . $e->getMessage()); }   // kopiowanie / dosyłanie dostaje nowe hasło
        $sent = '';
        if ($sms && $phone !== '' && sms_is_mobile($phone)) {
            DhostingMigrator::sendPasswordSms($phone, $mailbox, $password, true, true);   // nowe hasło istniejącej skrzynki — „z przyczyn technicznych”
            $sent = 'wysłano na ' . DhostingMigrator::maskPhone($phone);
        }
        $res = ['mailbox' => $mailbox, 'password' => $password, 'action' => 'password', 'sms' => $sent];
        if ($contract) { self::remember($contract[0], $contract[1], $res); self::log($contract[0], $contract[1], 'Administrator ustawił nowe hasło do skrzynki ' . $mailbox . ($sent ? ' (SMS ' . $sent . ')' : '')); }
        self::audit('dhosting.contract_box_password', ['mailbox' => $mailbox, 'by' => $by, 'sms' => $sent !== '']);
        return $res;
    }

    /** Zawieszenie: wyłączona skrzynka + losowe hasło, bez powiadamiania użytkownika. */
    public static function suspend(string $mailbox, string $reason, string $by): void
    {
        self::migrate();
        $api = DhostingConfig::api();
        $api->changeMailboxPassword($mailbox, DhostingConfig::generatePassword(20));
        $api->changeMailboxStatus($mailbox, false);
        require_once dirname(__DIR__, 2) . '/nowe_haslo/lib/NoweHasloInitial.php';
        NoweHasloInitial::clear('poczta', $mailbox);   // zawieszona skrzynka — nie zachęcamy do zmiany hasła
        $now = date('Y-m-d H:i:s');
        $upd = db()->prepare("UPDATE dhosting_contract_boxes SET status='suspended', suspended_at=?, suspend_reason=?, suspended_by=?, last_error=NULL WHERE mailbox=?");
        $upd->execute([$now, $reason, $by, $mailbox]);
        if ($upd->rowCount() === 0) {   // skrzynka z migracji — zapamiętujemy stan
            $person = '';
            try { $person = (string)(db_one("SELECT display_name FROM dhosting_mailboxes WHERE mailbox=?", [$mailbox])['display_name'] ?? ''); } catch (\Throwable $e) {}
            db()->prepare("INSERT INTO dhosting_contract_boxes (contract_type, contract_id, mailbox, person, status, created_at, created_by, suspended_at, suspend_reason, suspended_by) VALUES ('migracja', 0, ?, ?, 'suspended', ?, ?, ?, ?, ?)")
                ->execute([$mailbox, $person, $now, $by, $now, $reason, $by]);
        }
        // Hasło trzymane na dosyłanie poczty jest już nieaktualne — kasujemy je i przerywamy aktywne kopiowanie
        try {
            DhostingCopy::migrate();
            foreach (db_all("SELECT id FROM dhosting_copy_jobs WHERE mailbox=? AND status IN ('queued','running','waiting')", [$mailbox]) as $j) DhostingCopy::cancel((int)$j['id']);
            db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=NULL WHERE mailbox=?")->execute([$mailbox]);
        } catch (\Throwable $e) { error_log('[dhosting] suspend copy ' . $mailbox . ': ' . $e->getMessage()); }
        self::audit('dhosting.contract_box_suspended', ['mailbox' => $mailbox, 'reason' => $reason, 'by' => $by]);
        DhostingHelpdesk::event($mailbox, 'Skrzynka zawieszona (wyłączona, nowe losowe hasło, bez powiadomienia użytkownika): ' . $reason . ' — ' . $by . '.', [], false);
    }

    /** Przywrócenie przez administratora: włączona skrzynka + nowe hasło (raz na ekranie, opcjonalnie SMS). */
    public static function restore(string $mailbox, string $by, bool $sms, string $phone = '', ?array $contract = null): array
    {
        self::migrate();
        DhostingConfig::api()->changeMailboxStatus($mailbox, true);
        db()->prepare("UPDATE dhosting_contract_boxes SET status='active', restored_at=?, last_error=NULL WHERE mailbox=?")->execute([date('Y-m-d H:i:s'), $mailbox]);
        $res = self::adminPassword($mailbox, $by, $sms, $phone, $contract);
        $res['action'] = 'restored';
        if ($contract) self::remember($contract[0], $contract[1], $res);
        self::audit('dhosting.contract_box_restored', ['mailbox' => $mailbox, 'by' => $by]);
        DhostingHelpdesk::event($mailbox, 'Skrzynka przywrócona przez administratora (' . $by . ').', [], false);
        return $res;
    }

    /**
     * Zawieszone skrzynki do panelu administratora (najnowsze najpierw): mailbox, person, suspended_at, reason, by,
     * contracts (umowy powiązane: type, id, numer, osoba, koniec), phone (komórkowy z umowy — do SMS-a przy przywróceniu).
     */
    public static function suspendedList(): array
    {
        self::migrate();
        $out = [];
        $rows = db_all("SELECT mailbox, MAX(person) AS person, MAX(suspended_at) AS suspended_at, MAX(suspend_reason) AS reason, MAX(suspended_by) AS by_
                        FROM dhosting_contract_boxes WHERE status='suspended' GROUP BY mailbox ORDER BY MAX(suspended_at) DESC");
        foreach ($rows as $r) {
            $contracts = [];
            $phone = '';
            foreach (self::contractsFor((string)$r['mailbox']) as $c) {
                $contracts[] = ['type' => $c['_type'], 'id' => (int)$c['id'], 'numer' => (string)($c['numer_umowy'] ?? ''),
                                'person' => (string)($c['imie_nazwisko'] ?? ''), 'end' => (string)($c['data_zakonczenia'] ?? $c['termin_oddania'] ?? '')];
                $t = (string)($c['telefon'] ?? '');
                if ($phone === '' && $t !== '' && sms_is_mobile($t)) $phone = $t;
            }
            $person = (string)$r['person'] !== '' ? (string)$r['person'] : (string)($contracts[0]['person'] ?? '');
            $out[] = ['mailbox' => (string)$r['mailbox'], 'person' => $person, 'suspended_at' => (string)$r['suspended_at'],
                      'reason' => (string)($r['reason'] ?? ''), 'by' => (string)($r['by_'] ?? ''), 'contracts' => $contracts, 'phone' => $phone];
        }
        return $out;
    }

    /** Czy skrzynka jest zawieszona (w SZO). */
    public static function isSuspended(string $mailbox): bool
    {
        self::migrate();
        return (bool)db_one("SELECT id FROM dhosting_contract_boxes WHERE mailbox=? AND status='suspended' LIMIT 1", [strtolower(trim($mailbox))]);
    }

    // ── CRON: zawieszanie po wygaśnięciu umowy ────────────────────────────────────────────────

    /** Czy umowa jeszcze trzyma skrzynkę: trwa, nie zaczęła się, „nie wyłączaj”, okres ochronny albo < GRACE_DAYS dni po końcu. */
    public static function keepsActive(array $row, ?int $now = null): bool
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        if (!empty($row['m365_nie_wylaczaj'])) return true;
        $start = (string)($row['data_rozpoczecia'] ?? $row['data_zawarcia'] ?? '');
        if ($start !== '' && $start > $today) return true;
        $grace = (string)($row['m365_deactivate_after'] ?? '');
        if ($grace !== '' && strtotime($grace) > $now) return true;
        $status = (string)($row['status'] ?? '');
        $closed = function_exists('contractStatusGroup')
            ? in_array(contractStatusGroup($status), ['closed', 'cancelled'], true)
            : in_array($status, ['zakończona', 'anulowana', 'rozwiązana', 'wygasła'], true);
        $end = (string)($row['data_zakonczenia'] ?? $row['termin_oddania'] ?? '');
        if ($closed) {
            $ref = $end !== '' ? $end : ($grace !== '' ? substr($grace, 0, 10) : '');
            if ($ref === '') return true;   // brak daty końca — nie zawieszamy w ciemno
            return strtotime($ref . ' 00:00:00 +' . self::GRACE_DAYS . ' days') > $now;
        }
        if (!empty($row['bezterminowa']) || $end === '') return true;
        return strtotime($end . ' 00:00:00 +' . self::GRACE_DAYS . ' days') > $now;
    }

    /** Umowy związane ze skrzynką: z naszej tabeli i po loginie Microsoft (także loginie źródłowym skrzynki z migracji). */
    public static function contractsFor(string $mailbox): array
    {
        $logins = [$mailbox];
        try {
            $reg = db_one("SELECT source_login FROM dhosting_mailboxes WHERE mailbox=?", [$mailbox]);
            if (!empty($reg['source_login'])) $logins[] = strtolower((string)$reg['source_login']);
        } catch (\Throwable $e) {}
        $out = [];
        foreach (self::TABLES as $t => $table) {
            $ids = array_map('intval', array_column(db_all("SELECT contract_id FROM dhosting_contract_boxes WHERE mailbox=? AND contract_type=?", [$mailbox, $t]), 'contract_id'));
            $ph = implode(',', array_fill(0, count($logins), '?'));
            try {
                $rows = db_all("SELECT * FROM {$table} WHERE LOWER(TRIM(m365_login)) IN ({$ph})" . ($ids ? ' OR id IN (' . implode(',', $ids) . ')' : ''), $logins);
            } catch (\Throwable $e) { $rows = []; }
            foreach ($rows as $r) $out[$t . ':' . $r['id']] = $r + ['_type' => $t];
        }
        return array_values($out);
    }

    /** Przebieg CRON: zawiesza skrzynki osób bez trwającej umowy (GRACE_DAYS dni po końcu). Zwraca linie dziennika. */
    public static function suspendExpired(?int $now = null): array
    {
        self::migrate();
        if (DhostingConfig::get('dhosting_contract_suspend') === '0') return ['[SKIP] Zawieszanie skrzynek po końcu umowy wyłączone (dhosting_contract_suspend=0).'];
        if (!DhostingConfig::api()->configured()) return ['[SKIP] Brak danych API serwera poczty.'];
        $boxes = array_column(db_all("SELECT DISTINCT mailbox FROM dhosting_contract_boxes WHERE status='active'"), 'mailbox');
        try {
            foreach (db_all("SELECT mailbox FROM dhosting_mailboxes WHERE status='created'") as $r) $boxes[] = (string)$r['mailbox'];
        } catch (\Throwable $e) {}
        $suspended = array_flip(array_column(db_all("SELECT DISTINCT mailbox FROM dhosting_contract_boxes WHERE status='suspended'"), 'mailbox'));
        $log = [];
        foreach (array_unique($boxes) as $box) {
            if (isset($suspended[$box]) && !db_one("SELECT id FROM dhosting_contract_boxes WHERE mailbox=? AND status='active' LIMIT 1", [$box])) continue;
            $rows = self::contractsFor($box);
            if (!$rows) continue;   // bez umowy w SZO (np. pracownik) — nie ruszamy
            $keep = array_filter($rows, fn($r) => self::keepsActive($r, $now));
            if ($keep) continue;
            $last = max(array_map(fn($r) => (string)($r['data_zakonczenia'] ?? $r['termin_oddania'] ?? ''), $rows));
            try {
                self::suspend($box, 'umowa wygasła' . ($last !== '' ? ' ' . $last : '') . ' (+' . self::GRACE_DAYS . ' dni)', 'CRON');
                foreach ($rows as $r) self::log($r['_type'], (int)$r['id'], 'Skrzynka pocztowa ' . $box . ' zawieszona ' . self::GRACE_DAYS . ' dni po końcu umowy (bez powiadomienia).');
                $log[] = "[OK] {$box}: zawieszona";
            } catch (\Throwable $e) {
                db()->prepare("UPDATE dhosting_contract_boxes SET last_error=? WHERE mailbox=?")->execute([mb_substr($e->getMessage(), 0, 500), $box]);
                $log[] = "[ERROR] {$box}: " . $e->getMessage();
            }
        }
        return $log ?: ['[SKIP] Brak skrzynek do zawieszenia.'];
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────────────────────

    /** Dane do pokazania raz w widoku umowy (hasło znika po wyświetleniu). */
    public static function remember(string $type, int $id, array $res): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION[self::K_NEW] = ['type' => $type, 'id' => $id] + $res;
    }

    public static function takeNew(string $type, int $id): ?array
    {
        $n = $_SESSION[self::K_NEW] ?? null;
        if (!is_array($n) || $n['type'] !== $type || (int)$n['id'] !== $id) return null;
        unset($_SESSION[self::K_NEW]);
        return $n;
    }

    private static function log(string $type, int $id, string $msg): void
    {
        try {
            if (function_exists('log_contract_action')) log_contract_action($type, $id, (int)((function_exists('current_user') ? current_user() : null)['id'] ?? 0), 'note', $msg);
        } catch (\Throwable $e) {}
    }

    private static function audit(string $event, array $data): void
    {
        try { if (function_exists('audit_log')) audit_log($event, $data); } catch (\Throwable $e) {}
    }
}
