<?php
declare(strict_types=1);

/**
 * modules/collaborator_data/lib/CollaboratorDataService.php — „Zmiana danych współpracownika”:
 * aktualizacja kontaktu, adresu i danych rozliczeniowych z dziennikiem zmian (collaborator_data_changes:
 * kto, kiedy, co, stara/nowa wartość, źródło, IP).
 *
 * Gdzie trafiają dane: kontakt → users (konto), adres i rozliczenia → persons (rejestr Osób, z którego
 * korzystają umowy i oświadczenia MSUES). Umów już zawartych NIE zmieniamy — to dokumenty z dnia zawarcia.
 *
 * Pola i ich ustawienia (etykieta, grupa, czy zmiana wymaga akceptacji) pochodzą ze Słownika Centralnego
 * (słownik systemowy COLLAB_FIELDS_DICT, atrybuty group/approval); stała COLLAB_FIELDS to logika zapisu
 * i wartości zapasowe, gdy słownika jeszcze nie ma. Statusy zmian: enum CollabChangeStatus + słownik etykiet.
 */

require_once dirname(__DIR__, 2) . '/dictionary/lib/dictionaryApi.php';

const COLLAB_FIELDS_DICT = 'zmiana_danych_pola';
const COLLAB_STATUS_DICT = 'zmiana_danych_statusy';

/** Kod pola => [etykieta, grupa, cel (user|person), kolumna, typ, wymaga akceptacji]. */
const COLLAB_FIELDS = [
    'telefon'                => ['Telefon',                                'kontakt',     'user',   'phone_number',           'phone', false],
    'alt_email'              => ['Dodatkowy adres e-mail',                 'kontakt',     'user',   'alt_email',              'email', false],
    'addr_street'            => ['Ulica',                                  'adres',       'person', 'addr_street',            'text',  false],
    'addr_house'             => ['Nr domu',                                'adres',       'person', 'addr_house',             'text',  false],
    'addr_flat'              => ['Nr lokalu',                              'adres',       'person', 'addr_flat',              'text',  false],
    'addr_postal'            => ['Kod pocztowy',                           'adres',       'person', 'addr_postal',            'postal', false],
    'addr_city'              => ['Miejscowość',                            'adres',       'person', 'addr_city',              'text',  false],
    'addr_country'           => ['Kraj',                                   'adres',       'person', 'addr_country',           'text',  false],
    'adres_korespondencyjny' => ['Adres do korespondencji (jeśli inny)',   'adres',       'person', 'adres_korespondencyjny', 'textarea', false],
    'rachunek_bankowy'       => ['Numer rachunku bankowego',               'rozliczenia', 'person', 'rachunek_bankowy',       'iban',  true],
    'urzad_skarbowy'         => ['Urząd skarbowy',                         'rozliczenia', 'person', 'urzad_skarbowy',         'text',  false],
];

const COLLAB_GROUPS = ['kontakt' => 'Kontakt', 'adres' => 'Adres', 'rozliczenia' => 'Dane rozliczeniowe'];

enum CollabChangeStatus: string
{
    case Applied  = 'applied';
    case Pending  = 'pending';
    case Rejected = 'rejected';

    public function label(): string
    {
        $fallback = match ($this) { self::Applied => 'wprowadzona', self::Pending => 'czeka na akceptację', self::Rejected => 'odrzucona' };
        return (string)dictLabel(COLLAB_STATUS_DICT, $this->value, $fallback);
    }

    public function color(): string
    {
        return match ($this) { self::Applied => '#007a00', self::Pending => '#c25100', self::Rejected => '#a20025' };
    }
}

/** Wynik zgłoszenia zmian (z numerem wygenerowanego wniosku SZD, jeśli powstał). */
final class CollabSubmitResult
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly int $applied = 0, public readonly int $pending = 0, public readonly array $errors = [],
                                public readonly ?int $applicationId = null, public readonly string $applicationNumber = '') {}
}

final class CollaboratorDataService
{
    private static bool $schemaDone = false;

    public function __construct(private readonly int $userId)
    {
        self::schema();
    }

    public static function schema(): void
    {
        if (self::$schemaDone) return;
        self::$schemaDone = true;
        db()->exec("CREATE TABLE IF NOT EXISTS collaborator_data_changes (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            person_id      INTEGER REFERENCES persons(id) ON DELETE SET NULL,
            field          TEXT NOT NULL,
            old_value      TEXT,
            new_value      TEXT,
            status         TEXT NOT NULL DEFAULT 'applied',
            source         TEXT NOT NULL DEFAULT 'self',
            application_id INTEGER REFERENCES user_applications(id) ON DELETE SET NULL,
            changed_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            decided_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            decided_at     DATETIME,
            decision_note  TEXT,
            ip             TEXT,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_cdc_user ON collaborator_data_changes(user_id, created_at)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_cdc_status ON collaborator_data_changes(status)");
        // Żądania podpisu wniosku SZD: zmiany czekają tu (payload), dopóki osoba nie podpisze wniosku kodem
        db()->exec("CREATE TABLE IF NOT EXISTS collaborator_sign_requests (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token          TEXT NOT NULL UNIQUE,
            payload_json   TEXT NOT NULL,
            code_hash      TEXT,
            channel        TEXT,
            sent_to        TEXT,
            sent_at        DATETIME,
            expires_at     DATETIME,
            attempts       INTEGER NOT NULL DEFAULT 0,
            status         TEXT NOT NULL DEFAULT 'pending',
            application_id INTEGER REFERENCES user_applications(id) ON DELETE SET NULL,
            ip             TEXT,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            signed_at      DATETIME
        )");
    }

    /**
     * Pola z ustawieniami ze Słownika Centralnego (etykieta, grupa, approval, aktywność); kody bez logiki pomijane.
     * @return array<string, array{label:string, group:string, target:string, column:string, type:string, approval:bool}>
     */
    public static function fields(): array
    {
        $items = dictItems(COLLAB_FIELDS_DICT, []);
        $out = [];
        foreach (COLLAB_FIELDS as $code => [$label, $group, $target, $col, $type, $approval]) {
            if ($items && !isset($items[$code])) continue;            // wyłączone w słowniku
            $it = $items[$code] ?? null;
            $out[$code] = [
                'label'    => (string)($it['label'] ?? $label),
                'group'    => isset(COLLAB_GROUPS[$it['attributes']['group'] ?? '']) ? (string)$it['attributes']['group'] : $group,
                'target'   => $target, 'column' => $col, 'type' => $type,
                'approval' => (bool)($it['attributes']['approval'] ?? $approval),
            ];
        }
        return $out;
    }

    /** Seed słownika pól i statusów (format importu Słownika Centralnego) — dla migracji jednorazowej. */
    public static function dictionarySeed(): string
    {
        $fields = [];
        $i = 0;
        foreach (COLLAB_FIELDS as $code => [$label, $group, , , , $approval]) {
            $fields[] = ['code' => $code, 'label' => $label, 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true,
                         'description' => 'Grupa: ' . COLLAB_GROUPS[$group] . ($approval ? '; zmiana wymaga akceptacji' : ''),
                         'attributes' => ['group' => $group, 'approval' => $approval]];
        }
        $statuses = [];
        foreach (CollabChangeStatus::cases() as $j => $s) {
            $statuses[] = ['code' => $s->value, 'label' => match ($s) {
                CollabChangeStatus::Applied => 'wprowadzona', CollabChangeStatus::Pending => 'czeka na akceptację', CollabChangeStatus::Rejected => 'odrzucona',
            }, 'parent_code' => null, 'sort_order' => ($j + 1) * 10, 'is_active' => true, 'description' => null];
        }
        return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
            ['code' => COLLAB_FIELDS_DICT, 'name' => 'Zmiana danych współpracownika — pola', 'kind' => 'system', 'parent_code' => null,
             'is_protected' => false, 'sort_order' => 60,
             'description' => 'Pola, które współpracownik zmienia samodzielnie (moduł Zmiana danych współpracownika). Kody są używane w kodzie — zmieniaj etykiety, kolejność, aktywność i atrybuty group (kontakt/adres/rozliczenia) oraz approval (true = zmiana czeka na akceptację administratora).',
             'entries' => $fields],
            ['code' => COLLAB_STATUS_DICT, 'name' => 'Zmiana danych współpracownika — statusy zmian', 'kind' => 'system', 'parent_code' => null,
             'is_protected' => false, 'sort_order' => 61,
             'description' => 'Statusy wpisów dziennika zmian danych współpracownika. Kody stałe — zmieniaj tylko etykiety.',
             'entries' => $statuses],
        ]], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    public function user(): array
    {
        return db_one("SELECT * FROM users WHERE id = ?", [$this->userId]) ?: [];
    }

    /** Rekord w rejestrze Osób: po e-mailu konta, potem po person_id z umów osoby. */
    public function person(): ?array
    {
        $u = $this->user();
        $mail = mb_strtolower(trim((string)($u['email'] ?? '')));
        if ($mail === '') return null;
        $p = db_one("SELECT * FROM persons WHERE lower(email) = ? ORDER BY id LIMIT 1", [$mail]);
        if ($p) return $p;
        foreach (['wolontariat' => ['email', 'm365_login'], 'zlecenie' => ['email', 'm365_login'], 'dzielo' => ['m365_login'], 'praca' => ['email_login']] as $t => $cols) {
            $w = implode(' OR ', array_map(fn($c) => "lower($c) = ?", $cols));
            try {
                $r = db_one("SELECT person_id FROM umowy_{$t} WHERE person_id IS NOT NULL AND ($w) ORDER BY id DESC LIMIT 1", array_fill(0, count($cols), $mail));
                if ($r && ($p = db_one("SELECT * FROM persons WHERE id = ?", [(int)$r['person_id']]))) return $p;
            } catch (\Throwable $e) {}
        }
        return null;
    }

    /** Aktualne wartości pól. @return array<string,string> */
    public function current(): array
    {
        $u = $this->user();
        $p = $this->person() ?? [];
        $out = [];
        foreach (self::fields() as $code => $f) {
            $out[$code] = (string)(($f['target'] === 'user' ? $u : $p)[$f['column']] ?? '');
        }
        if (($out['telefon'] ?? '') === '' && !empty($p['telefon'])) $out['telefon'] = (string)$p['telefon'];
        return $out;
    }

    /** Walidacja i normalizacja wartości; zwraca [wartość, błąd|null]. */
    public static function normalize(string $type, string $value): array
    {
        $v = trim($value);
        if ($v === '') return ['', null];
        switch ($type) {
            case 'phone':
                $d = preg_replace('/[^\d+]/', '', $v);
                return preg_match('/^\+?\d{9,15}$/', (string)$d) ? [(string)$d, null] : [$v, 'Podaj numer telefonu (9–15 cyfr, opcjonalnie z +).'];
            case 'email':
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? [mb_strtolower($v), null] : [$v, 'Podaj poprawny adres e-mail.'];
            case 'postal':
                return preg_match('/^\d{2}-\d{3}$/', $v) ? [$v, null] : [$v, 'Kod pocztowy w formacie 00-000.'];
            case 'iban':
                $n = strtoupper(preg_replace('/\s+/', '', $v));
                if (preg_match('/^\d{26}$/', $n)) $n = 'PL' . $n;
                if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $n) || !self::ibanOk($n)) return [$v, 'Numer rachunku nie przechodzi kontroli IBAN — sprawdź cyfry.'];
                return [trim(chunk_split($n, 4, ' ')), null];
        }
        return [mb_substr($v, 0, 500), null];
    }

    private static function ibanOk(string $iban): bool
    {
        $r = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($r) as $ch) $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        $mod = 0;
        foreach (str_split($num, 7) as $chunk) $mod = (int)(($mod . $chunk) % 97);
        return $mod === 1;
    }

    /**
     * Zgłoszenie zmian. Pola z approval=true trafiają do akceptacji (status pending), pozostałe wchodzą od razu.
     * Admin/redaktor zmieniający cudze dane: zmiany wchodzą od razu ($autoApprove).
     * @param array<string,string> $input
     */
    /** Walidacja: [błędy, zmiany do wprowadzenia (tylko pola, których wartość się zmienia)]. */
    public function prepare(array $input): array
    {
        $cur = $this->current();
        $errors = []; $todo = [];
        foreach (self::fields() as $code => $f) {
            if (!array_key_exists($code, $input)) continue;
            [$val, $err] = self::normalize($f['type'], (string)$input[$code]);
            if ($err) { $errors[$code] = $err; continue; }
            if ($val === ($cur[$code] ?? '')) continue;
            $todo[$code] = $val;
        }
        return [$errors, $todo];
    }

    /** @var array<string,string>|null dane podpisu wniosku SZD (sposób, adresat kodu, czas) — ustawiane przy sign() */
    private ?array $signInfo = null;

    public function submit(array $input, int $actorId, string $source = 'self', ?int $applicationId = null, bool $autoApprove = false): CollabSubmitResult
    {
        $fields = self::fields();
        $cur = $this->current();
        [$errors, $todo] = $this->prepare($input);
        if ($errors) return new CollabSubmitResult(0, 0, $errors);

        $applied = 0; $pending = 0; $ids = []; $rows = [];
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        foreach ($todo as $code => $val) {
            $f = $fields[$code];
            $wait = $f['approval'] && !$autoApprove;
            $personId = $this->person()['id'] ?? null;
            $ids[] = db_insert('collaborator_data_changes', [
                'user_id' => $this->userId, 'person_id' => $personId, 'field' => $code,
                'old_value' => $cur[$code] ?? '', 'new_value' => $val,
                'status' => ($wait ? CollabChangeStatus::Pending : CollabChangeStatus::Applied)->value,
                'source' => $source, 'application_id' => $applicationId, 'changed_by' => $actorId,
                'decided_by' => $wait ? null : $actorId, 'decided_at' => $wait ? null : date('Y-m-d H:i:s'), 'ip' => $ip,
            ]);
            $rows[] = [$f['label'], self::display($code, $cur[$code] ?? ''), $code === 'rachunek_bankowy' ? $val : self::display($code, $val), $wait];
            if ($wait) { $pending++; continue; }
            $this->write($code, $val, $actorId);
            $applied++;
        }
        if ($pending) $this->notifyAdmins($pending);
        [$appId, $appNo] = $rows && $applicationId === null ? $this->generateApplication($rows, $ids, $pending > 0) : [$applicationId, ''];
        return new CollabSubmitResult($applied, $pending, [], $appId, $appNo);
    }

    /**
     * Każda zmiana danych w systemie tworzy wniosek SZD („Wniosek o zmianę danych w systemie”) — z tabelą
     * pole / dotychczasowa / nowa wartość — i przechodzi drogę każdego wniosku: numer, suma kontrolna, przesyłka
     * w EZD (RPW) z PDF, potwierdzenie UPZ. Zmiany wprowadzone od razu → wniosek „rozpatrzony” z adnotacją;
     * zmiany czekające na akceptację → wniosek „nowy” i start obiegu typu.
     * @param list<array{0:string,1:string,2:string,3:bool}> $rows  [etykieta, stara, nowa, czeka]
     * @param list<int> $changeIds
     * @return array{0:?int,1:string} [id wniosku, numer]
     */
    private function generateApplication(array $rows, array $changeIds, bool $hasPending): array
    {
        try {
            $root = dirname(__DIR__, 3);
            require_once $root . '/includes/applications.php';
            _applications_init();
            $type = db_one("SELECT id, name FROM application_types WHERE name = 'wniosek_szd' AND is_active = 1");
            if (!$type) return [null, ''];
            $u = $this->user();
            $userArr = ['id' => $this->userId, 'name' => (string)($u['name'] ?? ''), 'email' => (string)($u['email'] ?? '')];
            $fields = [
                'identyfikator' => (string)($u['email'] ?? ''),
                'kontakt'       => implode(', ', array_filter([(string)($u['phone_number'] ?? ''), (string)($u['email'] ?? '')])),
                'system'        => 'SZO — dane współpracownika',
                'rodzaj'        => 'aktualizacja danych',
                'zmiany'        => implode("\n", array_map(fn($r) => str_replace('|', '/', $r[0]) . ' | ' . str_replace('|', '/', $r[1]) . ' | '
                                       . str_replace('|', '/', $r[2]) . ($r[3] ? ' (czeka na akceptację)' : ''), $rows)),
                'uzasadnienie'  => 'Aktualizacja danych przez współpracownika w module „Zmiana danych współpracownika”.',
                'data_zmiany'   => date('Y-m-d'),
                'zalaczniki'    => '',
                'podpis'        => $this->signInfo
                    ? 'podpisano kodem jednorazowym wysłanym ' . ($this->signInfo['channel'] === 'sms' ? 'SMS-em na numer ' : 'e-mailem na adres ') . $this->signInfo['to']
                      . ', ' . date('d.m.Y, H:i', strtotime($this->signInfo['at']))
                    : '',
            ];
            $fields = appDocsApplyLocked('wniosek_szd', $fields, $userArr);
            $title = 'SZD — zmiana danych: ' . mb_substr(implode(', ', array_map(fn($r) => mb_strtolower($r[0]), $rows)), 0, 120);
            $appId = db_insert('user_applications', [
                'user_id' => $this->userId, 'type_id' => (int)$type['id'], 'contract_type' => '', 'contract_id' => 0,
                'tytul' => $title, 'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'plik' => null,
                'status' => 'nowy',
            ]);
            if ($changeIds) {
                db()->prepare("UPDATE collaborator_data_changes SET application_id = ? WHERE id IN (" . implode(',', array_map('intval', $changeIds)) . ")")
                    ->execute([$appId]);
            }
            // Podpisany wniosek jest składany jak każdy: numer, suma kontrolna, EZD (RPW), UPZ, start obiegu typu
            appDocsAfterSubmit($appId);
            if (is_file($root . '/modules/app_workflow/logic/appWorkflow.php')) {
                require_once $root . '/modules/app_workflow/logic/appWorkflow.php';
                if (function_exists('appWfStart')) appWfStart($appId);
            }
            $no = (string)(db_one("SELECT doc_number FROM user_applications WHERE id = ?", [$appId])['doc_number'] ?? '');
            return [$appId, $no];
        } catch (\Throwable $e) {
            error_log('[collaborator_data] wniosek SZD: ' . $e->getMessage());
            return [null, ''];
        }
    }

    /** Po decyzji: gdy wszystkie zmiany wniosku SZD są rozpatrzone — zamknij wniosek z podsumowaniem. */
    private static function closeApplication(int $appId): void
    {
        $left = (int)(db_one("SELECT COUNT(*) AS c FROM collaborator_data_changes WHERE application_id = ? AND status = ?", [$appId, CollabChangeStatus::Pending->value])['c'] ?? 0);
        if ($left) return;
        $rej = (int)(db_one("SELECT COUNT(*) AS c FROM collaborator_data_changes WHERE application_id = ? AND status = ?", [$appId, CollabChangeStatus::Rejected->value])['c'] ?? 0);
        $all = (int)(db_one("SELECT COUNT(*) AS c FROM collaborator_data_changes WHERE application_id = ?", [$appId])['c'] ?? 0);
        $notes = array_filter(array_column(db_all("SELECT decision_note FROM collaborator_data_changes WHERE application_id = ? AND decision_note != ''", [$appId]), 'decision_note'));
        db()->prepare("UPDATE user_applications SET status = ?, odpowiedz = ?, odpowiedz_at = ? WHERE id = ?")->execute([
            $rej === $all ? 'odrzucony' : 'rozpatrzony',
            ($rej ? 'Wprowadzono ' . ($all - $rej) . ' z ' . $all . ' zmian, odrzucono ' . $rej . '.' : 'Wszystkie zmiany wprowadzone.') . ($notes ? ' ' . implode(' ', $notes) : ''),
            date('Y-m-d H:i:s'), $appId,
        ]);
    }

    /** Zapis wartości do users / persons (rekord Osoby tworzony, gdy go brak). */
    private function write(string $code, string $val, int $actorId): void
    {
        $f = self::fields()[$code] ?? null;
        if (!$f) return;
        if ($f['target'] === 'user') {
            db()->prepare("UPDATE users SET {$f['column']} = ? WHERE id = ?")->execute([$val, $this->userId]);
            if ($code === 'telefon' && ($p = $this->person())) db_update('persons', ['telefon' => $val], (int)$p['id']);
            return;
        }
        $p = $this->person();
        if (!$p) {
            $u = $this->user();
            $name = trim(((string)($u['first_name'] ?? '')) . ' ' . ((string)($u['last_name'] ?? ''))) ?: (string)($u['name'] ?? '');
            $pid = db_insert('persons', ['imie_nazwisko' => $name, 'email' => (string)($u['email'] ?? ''), 'telefon' => (string)($u['phone_number'] ?? ''), 'created_by' => $actorId]);
            db()->prepare("UPDATE collaborator_data_changes SET person_id = ? WHERE user_id = ? AND person_id IS NULL")->execute([$pid, $this->userId]);
            $p = db_one("SELECT * FROM persons WHERE id = ?", [$pid]) ?: [];
        }
        $data = [$f['column'] => $val];
        if (str_starts_with($code, 'addr_')) {
            $a = array_merge($p, $data);
            $street = trim(trim((string)($a['addr_street'] ?? '')) . ' ' . trim((string)($a['addr_house'] ?? '')) . (trim((string)($a['addr_flat'] ?? '')) !== '' ? '/' . trim((string)$a['addr_flat']) : ''));
            $data['adres'] = trim($street . ', ' . trim(trim((string)($a['addr_postal'] ?? '')) . ' ' . trim((string)($a['addr_city'] ?? ''))), ', ');
        }
        db_update('persons', $data, (int)$p['id']);
    }

    private function notifyAdmins(int $n): void
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/notifications.php';
            $u = $this->user();
            foreach (db_all("SELECT id FROM users WHERE role IN ('admin','editor') AND is_active = 1") as $a) {
                notif_create((int)$a['id'], 'system', 'Zmiana danych do akceptacji: ' . ($u['name'] ?? ''), $n . ' zmian(y) czeka na decyzję',
                             APP_URL . '/modules/collaborator_data/router.php?_route=/admin');
            }
        } catch (\Throwable $e) {}
    }

    /** Decyzja administratora o zmianie oczekującej. */
    public static function decide(int $changeId, bool $approve, int $actorId, string $note = ''): bool
    {
        self::schema();
        $c = db_one("SELECT * FROM collaborator_data_changes WHERE id = ? AND status = ?", [$changeId, CollabChangeStatus::Pending->value]);
        if (!$c) return false;
        if ($approve) (new self((int)$c['user_id']))->write((string)$c['field'], (string)$c['new_value'], $actorId);
        db()->prepare("UPDATE collaborator_data_changes SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?")
            ->execute([($approve ? CollabChangeStatus::Applied : CollabChangeStatus::Rejected)->value, $actorId, date('Y-m-d H:i:s'), mb_substr($note, 0, 500), $changeId]);
        if (!empty($c['application_id'])) { try { self::closeApplication((int)$c['application_id']); } catch (\Throwable $e) {} }
        try {
            require_once dirname(__DIR__, 3) . '/includes/notifications.php';
            $label = self::fields()[$c['field']]['label'] ?? $c['field'];
            notif_create((int)$c['user_id'], 'system', 'Zmiana danych: ' . $label . ' — ' . ($approve ? 'wprowadzona' : 'odrzucona'), $note,
                         APP_URL . '/modules/collaborator_data/router.php');
        } catch (\Throwable $e) {}
        return true;
    }

    // ── Podpis wniosku SZD kodem jednorazowym ─────────────────────────────────────

    /**
     * „Zapisz zmiany”: walidacja i żądanie podpisu wniosku SZD. Zmiany NIE wchodzą, dopóki osoba nie podpisze
     * wniosku kodem (SMS na telefon z konta; bez telefonu lub bramki — e-mail). Zwraca ['errors'=>…] albo ['token'=>…].
     */
    public function requestSignature(array $input): array
    {
        [$errors, $todo] = $this->prepare($input);
        if ($errors) return ['errors' => $errors];
        if (!$todo) return ['token' => null];
        // wcześniejsze niepodpisane żądania tej osoby tracą ważność — obowiązuje ostatni zapis
        db()->prepare("UPDATE collaborator_sign_requests SET status = 'cancelled' WHERE user_id = ? AND status = 'pending'")->execute([$this->userId]);
        $token = bin2hex(random_bytes(16));
        db_insert('collaborator_sign_requests', ['user_id' => $this->userId, 'token' => $token,
            'payload_json' => json_encode($todo, JSON_UNESCAPED_UNICODE), 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
        $sent = $this->sendCode($token);
        return ['token' => $token, 'sent' => $sent];
    }

    /** Żądanie podpisu tej osoby (pending, nieprzeterminowane payloadem) albo null. */
    public function signRequest(string $token): ?array
    {
        $r = db_one("SELECT * FROM collaborator_sign_requests WHERE token = ? AND user_id = ?", [$token, $this->userId]);
        return $r ?: null;
    }

    /** Wiersze podglądu wniosku: [etykieta, dotychczas, nowa, wymaga akceptacji]. */
    public function previewRows(array $req): array
    {
        $fields = self::fields(); $cur = $this->current(); $rows = [];
        foreach ((json_decode((string)$req['payload_json'], true) ?: []) as $code => $val) {
            if (!isset($fields[$code])) continue;
            $rows[] = [$fields[$code]['label'], self::display($code, $cur[$code] ?? ''), $code === 'rachunek_bankowy' ? (string)$val : self::display($code, (string)$val), $fields[$code]['approval']];
        }
        return $rows;
    }

    /**
     * Wysyła (ponownie) kod podpisu. $channel='email' wymusza e-mail (gdy SMS nie dotarł), inaczej SMS z zapasem na e-mail.
     * Zwraca ['ok'=>bool, 'channel'=>sms|email, 'to'=>zamaskowany adresat, 'error'=>?].
     */
    public function sendCode(string $token, ?string $channel = null): array
    {
        $req = $this->signRequest($token);
        if (!$req || $req['status'] !== 'pending') return ['ok' => false, 'error' => 'Żądanie podpisu wygasło — zapisz zmiany ponownie.'];
        if (!empty($req['sent_at']) && strtotime((string)$req['sent_at']) > time() - 45) return ['ok' => false, 'error' => 'Kod wysłano przed chwilą — odczekaj chwilę przed ponowną wysyłką.'];
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $u = $this->user();
        $org = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
        $phone = trim((string)($u['phone_number'] ?? ''));
        $force = $channel === 'email';
        $channel = ''; $to = ''; $err = null;
        if (!$force) try {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            if ($phone !== '' && function_exists('sms_is_enabled') && sms_is_enabled()) {
                sms_send($phone, "Kod podpisu wniosku o zmianę danych (SZD): {$code}. Ważny 10 minut. Nie podawaj go nikomu. [{$org}]", 'authorization_code');
                $channel = 'sms'; $to = '••• ••• ' . substr(preg_replace('/\D/', '', $phone), -3);
            }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
        if ($channel === '') {
            $mail = trim((string)($u['email'] ?? ''));
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Brak telefonu i adresu e-mail do wysłania kodu podpisu — uzupełnij telefon w koncie.'];
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add($mail, (string)($u['name'] ?? ''), 'Kod podpisu wniosku o zmianę danych (SZD)',
                '<p>Kod podpisu wniosku o zmianę danych: <strong style="font-size:1.4em;letter-spacing:.15em">' . $code . '</strong></p>'
                . '<p>Kod jest ważny 10 minut. Jeśli to nie Ty zmieniasz dane w ' . htmlspecialchars((string)$org, ENT_QUOTES) . ', zignoruj tę wiadomość i zgłoś to administratorowi.</p>',
                '', 'collab_sign', (int)$req['id'], '', true);
            $channel = 'email';
            [$l, $d] = array_pad(explode('@', $mail, 2), 2, '');
            $to = mb_substr($l, 0, 2) . '•••@' . $d;
        }
        db()->prepare("UPDATE collaborator_sign_requests SET code_hash = ?, channel = ?, sent_to = ?, sent_at = ?, expires_at = ?, attempts = 0 WHERE id = ?")
            ->execute([hash('sha256', $token . '|' . $code), $channel, $to, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 600), (int)$req['id']]);
        return ['ok' => true, 'channel' => $channel, 'to' => $to, 'error' => $err];
    }

    /** Podpis kodem → złożenie wniosku SZD i wprowadzenie zmian. Zwraca ['ok'=>bool, 'error'=>?, 'result'=>?CollabSubmitResult]. */
    public function sign(string $token, string $code, int $actorId): array
    {
        $req = $this->signRequest($token);
        if (!$req || $req['status'] !== 'pending') return ['ok' => false, 'error' => 'To żądanie podpisu nie jest już aktywne.'];
        if (empty($req['code_hash']) || strtotime((string)$req['expires_at']) < time()) return ['ok' => false, 'error' => 'Kod wygasł — wyślij nowy.'];
        if ((int)$req['attempts'] >= 5) return ['ok' => false, 'error' => 'Za dużo błędnych prób — wyślij nowy kod.'];
        if (!hash_equals((string)$req['code_hash'], hash('sha256', $token . '|' . preg_replace('/\D/', '', $code)))) {
            db()->prepare("UPDATE collaborator_sign_requests SET attempts = attempts + 1 WHERE id = ?")->execute([(int)$req['id']]);
            return ['ok' => false, 'error' => 'Nieprawidłowy kod. Pozostało prób: ' . max(0, 4 - (int)$req['attempts']) . '.'];
        }
        $now = date('Y-m-d H:i:s');
        // jednorazowość: tylko jedno żądanie przejdzie z pending na signed
        $st = db()->prepare("UPDATE collaborator_sign_requests SET status = 'signed', signed_at = ? WHERE id = ? AND status = 'pending'");
        $st->execute([$now, (int)$req['id']]);
        if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'Wniosek został już podpisany.'];
        $this->signInfo = ['channel' => (string)$req['channel'], 'to' => (string)$req['sent_to'], 'at' => $now];
        $res = $this->submit(json_decode((string)$req['payload_json'], true) ?: [], $actorId, 'self');
        $this->signInfo = null;
        if ($res->applicationId) db()->prepare("UPDATE collaborator_sign_requests SET application_id = ? WHERE id = ?")->execute([$res->applicationId, (int)$req['id']]);
        return ['ok' => true, 'result' => $res];
    }

    public function cancelSignature(string $token): void
    {
        db()->prepare("UPDATE collaborator_sign_requests SET status = 'cancelled' WHERE token = ? AND user_id = ? AND status = 'pending'")->execute([$token, $this->userId]);
    }

    /** Dziennik zmian (dla osoby albo wszystkich). @return list<array<string,mixed>> */
    public static function history(?int $userId = null, ?string $status = null, int $limit = 100): array
    {
        self::schema();
        $w = []; $p = [];
        if ($userId) { $w[] = 'c.user_id = ?'; $p[] = $userId; }
        if ($status) { $w[] = 'c.status = ?'; $p[] = $status; }
        return db_all("SELECT c.*, u.name AS user_name, u.email AS user_email, a.name AS actor_name, d.name AS decider_name
                       FROM collaborator_data_changes c
                       LEFT JOIN users u ON u.id = c.user_id LEFT JOIN users a ON a.id = c.changed_by LEFT JOIN users d ON d.id = c.decided_by"
                     . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY c.id DESC LIMIT " . max(1, min(500, $limit)), $p);
    }

    /** Wartość do wyświetlenia w dzienniku (rachunek zamaskowany poza ostatnimi 4 cyframi). */
    public static function display(string $field, ?string $value, bool $full = false): string
    {
        $v = trim((string)$value);
        if ($v === '') return '—';
        if ($field === 'rachunek_bankowy' && !$full) {
            $d = preg_replace('/\s+/', '', $v);
            return '•••• ' . substr((string)$d, -4);
        }
        return $v;
    }

    /** Typy wniosków modułu (SZD, SKK, UPO1–3, DYD1) aktywne w panelu wniosków. @return list<array<string,mixed>> */
    public static function applicationTypes(): array
    {
        require_once dirname(__DIR__, 3) . '/includes/applications.php';
        $out = [];
        foreach (app_types_all(true) as $t) {
            $tpl = appDocsTemplateFor($t['name'] ?? null);
            if ($tpl && ($tpl['group'] ?? '') === 'collab') $out[] = $t + ['code' => $tpl['code']];
        }
        return $out;
    }
}
