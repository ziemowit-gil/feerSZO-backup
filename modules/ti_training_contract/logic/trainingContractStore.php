<?php
/**
 * modules/ti_training_contract/logic/trainingContractStore.php — zapis umów szkoleniowych SZK (tabela umowy_szk),
 * rejestracja (numer SZK/NNN/RRRR + numer Rejestru Umów RU/NNNN/RRRR/INI), dane przykładowe i ich czyszczenie.
 *
 * SZK to zwykły typ umowy (CONTRACT_TYPES['szk'], Menu Umowy) i zastępuje dawne UMSZ: migrate() zmienia nazwę tabeli
 * umowy_szkolenie_ti → umowy_szk (id, numery TI/UMSZ i RU zostają) i dokłada kolumny wspólne dla typów umów, żeby
 * pętle po CONTRACT_TYPES (pulpit, raporty, wygasanie, rejestr RU) działały bez wyjątków.
 * Umowy testowe (is_test=1) dostają numery z „TEST” (SZK/TEST-NNN/RRRR, RU/TEST-NNNN/RRRR/INI) i NIE są liczone
 * w liczniku RU — ich usunięcie nie robi dziur ani duplikatów w prawdziwej numeracji.
 */
require_once __DIR__ . '/trainingContract.php';

final class TiTrainingContractStore
{
    public const TABLE = 'umowy_szk';
    public const LEGACY_TABLE = 'umowy_szkolenie_ti';
    public const PREFIX = 'SZK/';

    /** Zapas — statusy są w słowniku umowy_statusy (statuses()). */
    public const STATUSES = ['projekt', 'do podpisu', 'podpisana', 'w realizacji', 'zakończona', 'rozwiązana', 'anulowana'];

    /** Statusy umowy szkoleniowej ze słownika umowy_statusy: ['kod' => 'Etykieta']. */
    public static function statuses(?string $current = null): array
    {
        return function_exists('contractStatusOptions') ? contractStatusOptions('szk', $current) : array_combine(self::STATUSES, self::STATUSES);
    }

    /** Kolumny wspólne dla typów umów (pętle po CONTRACT_TYPES) + pola SZK: nazwa => definicja SQL. */
    private const EXTRA_COLUMNS = [
        'pesel' => "VARCHAR(32) NOT NULL DEFAULT ''", 'adres' => "TEXT", 'telefon' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'email' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'data_rozpoczecia' => "VARCHAR(10)", 'data_zakonczenia' => "VARCHAR(10)", 'bezterminowa' => "INTEGER NOT NULL DEFAULT 0",
        'numer_projektu' => "VARCHAR(190) NOT NULL DEFAULT ''", 'uwagi' => "TEXT",
        'nr_roboczy' => "VARCHAR(64) NOT NULL DEFAULT ''", 'nr_system' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'forma_podpisania' => "VARCHAR(64) NOT NULL DEFAULT ''", 'platforma_el' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'id_dokumentu_el' => "VARCHAR(190) NOT NULL DEFAULT ''", 'plik_potwierdzenia' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'plik_umowy' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'epodpis_dostawca' => "VARCHAR(64) NOT NULL DEFAULT ''", 'epodpis_nr_certyfikatu' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'epodpis_data_waznosci' => "VARCHAR(10)",
        'docusign_status' => "VARCHAR(64) NOT NULL DEFAULT ''", 'docusign_signer_email' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'docusign_signer_name' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'autenti_document_id' => "VARCHAR(190) NOT NULL DEFAULT ''", 'autenti_status' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'autenti_signer_email' => "VARCHAR(190) NOT NULL DEFAULT ''", 'autenti_signer_name' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'guardian_editor_id' => "INTEGER", 'person_id' => "INTEGER", 'access_level' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'ezd_sprawa_id' => "INTEGER",
        'podpisujacy_fundacja' => "VARCHAR(190) NOT NULL DEFAULT ''", 'podpisujacy_stanowisko' => "VARCHAR(190) NOT NULL DEFAULT ''",
        // SZK
        'grupa_id' => "INTEGER", 'platnik_nazwa' => "VARCHAR(190) NOT NULL DEFAULT ''", 'kwota_umowy' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'model_naliczania' => "VARCHAR(64) NOT NULL DEFAULT ''", 'promocja_id' => "INTEGER",
        'data_wypowiedzenia' => "VARCHAR(10)", 'powod_wypowiedzenia' => "TEXT",
    ];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('szk_migrate', __FILE__)) return;
        $pdo = db();
        $has = function (string $t) use ($pdo): bool {
            try { $pdo->query("SELECT 1 FROM {$t} LIMIT 1"); return true; } catch (\Throwable $e) { return false; }
        };
        // UMSZ → SZK: zmiana nazwy tabeli (dane, id i numery zostają); tylko gdy nowej jeszcze nie ma.
        if (!$has(self::TABLE) && $has(self::LEGACY_TABLE)) $pdo->exec("ALTER TABLE " . self::LEGACY_TABLE . " RENAME TO " . self::TABLE);
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id {$pk},
            numer_umowy VARCHAR(64) NOT NULL DEFAULT '',
            nr_rejestru VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'projekt',
            data_zawarcia VARCHAR(10) NOT NULL DEFAULT '',
            client_id INTEGER,
            imie_nazwisko VARCHAR(190) NOT NULL DEFAULT '',
            temat VARCHAR(255) NOT NULL DEFAULT '',
            opiekun VARCHAR(190) NOT NULL DEFAULT '',
            dane TEXT,
            is_test INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at VARCHAR(19),
            updated_at VARCHAR(19),
            registered_at VARCHAR(19)
        )");
        $cols = [];
        if (DB_TYPE === 'sqlite') foreach (db_all("PRAGMA table_info(" . self::TABLE . ")") as $c) $cols[strtolower((string)$c['name'])] = true;
        else foreach (db_all("SHOW COLUMNS FROM " . self::TABLE) as $c) $cols[strtolower((string)$c['Field'])] = true;
        foreach (self::EXTRA_COLUMNS as $name => $def) {
            if (!isset($cols[$name])) $pdo->exec("ALTER TABLE " . self::TABLE . " ADD COLUMN {$name} {$def}");
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_umowy_szk_client ON " . self::TABLE . "(client_id)");
    }

    public static function get(int $id): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM " . self::TABLE . " WHERE id = ?", [$id]);
        return $r ? self::hydrate($r) : null;
    }

    public static function list(): array
    {
        self::migrate();
        return db_all("SELECT id, numer_umowy, nr_rejestru, status, data_zawarcia, client_id, imie_nazwisko, temat, opiekun, is_test, created_at
                         FROM " . self::TABLE . " ORDER BY id DESC");
    }

    public static function countTest(): int
    {
        self::migrate();
        return (int)(db_one("SELECT COUNT(*) c FROM " . self::TABLE . " WHERE is_test = 1")['c'] ?? 0);
    }

    /** Wiersz z bazy → dane formularza (TiTrainingContract) + kolumny rejestru. */
    private static function hydrate(array $r): array
    {
        $d = array_merge(TiTrainingContract::defaults(), json_decode((string)$r['dane'], true) ?: []);
        $d['nr'] = (string)$r['numer_umowy'];
        $d['data'] = (string)$r['data_zawarcia'];
        return $d + ['_row' => $r];
    }

    /** Zapisuje (nowa: $id=0). Zwraca id. Numery nadane przy rejestracji się nie zmieniają. */
    public static function save(int $id, array $d, string $opiekun, int $uid, bool $isTest = false): int
    {
        self::migrate();
        $now = date('Y-m-d H:i:s');
        $dane = $d;
        unset($dane['nr'], $dane['_row']);
        $row = [
            'data_zawarcia' => (string)$d['data'],
            'client_id'     => (int)$d['client_id'] ?: null,
            'imie_nazwisko' => mb_substr((string)$d['imie_nazwisko'], 0, 190),
            'temat'         => mb_substr((string)$d['temat'], 0, 255),
            'pesel'         => mb_substr((string)($d['pesel'] ?? ''), 0, 32),
            'adres'         => (string)($d['adres'] ?? ''),
            'email'         => mb_substr((string)($d['email'] ?? ''), 0, 190),
            'telefon'       => mb_substr((string)($d['telefon'] ?? ''), 0, 64),
            'platnik_nazwa' => mb_substr((string)($d['platnik'] ?? ''), 0, 190),
            'kwota_umowy'   => mb_substr((string)($d['wynagrodzenie'] ?? ''), 0, 32),
            'dane'          => json_encode($dane, JSON_UNESCAPED_UNICODE),
            'updated_at'    => $now,
        ];
        if ($id > 0) {
            db_update(self::TABLE, $row, $id);
            return $id;
        }
        return db_insert(self::TABLE, $row + ['opiekun' => $opiekun, 'status' => 'projekt', 'is_test' => $isTest ? 1 : 0,
                                              'created_by' => $uid ?: null, 'created_at' => $now]);
    }

    /** Nadaje numer SZK i numer RU (jednorazowo). Zwraca [numer_umowy, nr_rejestru]. */
    public static function register(int $id): array
    {
        $c = self::get($id);
        if (!$c) throw new RuntimeException('Nie ma takiej umowy.');
        $row = $c['_row'];
        if ($row['numer_umowy'] !== '' && $row['nr_rejestru'] !== '') return [$row['numer_umowy'], $row['nr_rejestru']];
        $test = (int)$row['is_test'] === 1;
        $year = substr((string)$row['data_zawarcia'], 0, 4) ?: date('Y');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $nr = $row['numer_umowy'] !== '' ? $row['numer_umowy'] : self::nextNumber($year, $test);
            if ($row['nr_rejestru'] !== '') $ru = $row['nr_rejestru'];
            elseif ($test) {
                $n = (int)(db_one("SELECT COUNT(*) c FROM " . self::TABLE . " WHERE is_test = 1 AND nr_rejestru != ''")['c'] ?? 0) + 1;
                $ru = sprintf('RU/TEST-%04d/%s/%s', $n, date('Y'), get_opiekun_initials((string)$row['opiekun']));
            } else $ru = suggest_nr_rejestru((string)$row['opiekun']);
            db_update(self::TABLE, ['numer_umowy' => $nr, 'nr_rejestru' => $ru, 'registered_at' => date('Y-m-d H:i:s')], $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [$nr, $ru];
    }

    /** Kolejny numer SZK/NNN/RRRR (licznik w obrębie roku; testowe mają własny licznik TEST-NNN; stare TI/UMSZ/… liczą się razem). */
    private static function nextNumber(string $year, bool $test): string
    {
        $prefix = $test ? 'SZK/TEST-' : self::PREFIX;
        $legacy = $test ? 'TI/UMSZ/TEST-' : 'TI/UMSZ/';
        $max = 0;
        foreach ([$prefix, $legacy] as $pf) {
            foreach (db_all("SELECT numer_umowy FROM " . self::TABLE . " WHERE numer_umowy LIKE ?", [$pf . '%/' . $year]) as $r) {
                if (preg_match('#^' . preg_quote($pf, '#') . '(\d+)/\d{4}$#', $r['numer_umowy'], $m)) $max = max($max, (int)$m[1]);
            }
        }
        return sprintf('%s%03d/%s', $prefix, $max + 1, $year);
    }

    /** Umowa już podpisana (treści nie zmieniamy): grupa aktywna albo zamknięta, rozwiązana — nie projekt i nie anulowana. */
    public static function isSigned(?string $status): bool
    {
        $g = function_exists('contractStatusGroup') ? contractStatusGroup($status) : '';
        return in_array($g, ['active', 'closed'], true) || $status === 'rozwiązana';
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!isset(self::statuses()[$status])) return false;
        self::migrate();
        db_update(self::TABLE, ['status' => $status], $id);
        return true;
    }

    /** Usuwa projekt bez numeru albo umowę testową. Zarejestrowanych prawdziwych umów nie usuwamy (są w RU). */
    public static function delete(int $id): bool
    {
        $c = self::get($id);
        if (!$c) return false;
        $r = $c['_row'];
        if ((int)$r['is_test'] !== 1 && $r['nr_rejestru'] !== '') return false;
        db_exec("DELETE FROM " . self::TABLE . " WHERE id = ?", [$id]);
        return true;
    }

    // ── Dane przykładowe ─────────────────────────────────────────────────────────

    /** Tworzy przykładowe umowy testowe (Pani — projekt, Pan — zarejestrowana i podpisana). Zwraca listę opisów. */
    public static function seed(string $opiekun, int $uid): array
    {
        $out = [];
        foreach ([['K', false, 'projekt'], ['M', true, 'podpisana']] as [$plec, $reg, $status]) {
            $d = TiTrainingContract::sample($plec);
            if ($plec === 'M') { $d['finansowanie'] = 'wlasne'; $d['kwota_platnik'] = ''; $d['platnik'] = ''; $d['kwota_kursant'] = $d['wynagrodzenie']; }
            $id = self::save(0, $d, $opiekun, $uid, true);
            $nr = '';
            if ($reg) { [$nr, $ru] = self::register($id); $nr .= " / {$ru}"; }
            self::setStatus($id, $status);
            $out[] = "#{$id} {$d['imie_nazwisko']} — {$status}" . ($nr !== '' ? " — {$nr}" : '');
        }
        return $out;
    }

    /** Usuwa wszystkie umowy testowe (is_test=1). Zwraca liczbę usuniętych. */
    public static function purgeTest(): int
    {
        self::migrate();
        $n = self::countTest();
        db_exec("DELETE FROM " . self::TABLE . " WHERE is_test = 1");
        return $n;
    }

    // ── Uczestnicy TI (wypełnienie danych z karty) ──────────────────────────────

    public static function clients(): array
    {
        return db_all("SELECT id, name FROM k30_clients ORDER BY name");
    }

    /** Dane uczestnika z k30_clients do formularza umowy. */
    public static function fromClient(int $clientId): array
    {
        $c = db_one("SELECT * FROM k30_clients WHERE id = ?", [$clientId]);
        if (!$c) return [];
        $g = mb_strtolower((string)($c['gender'] ?? ''));
        $out = ['client_id' => (int)$c['id'], 'imie_nazwisko' => (string)$c['name'], 'email' => (string)($c['email'] ?? ''),
                'telefon' => (string)($c['phone'] ?? ''), 'adres' => (string)($c['address'] ?? ''), 'pesel' => (string)($c['pesel'] ?? '')];
        if ($g !== '') $out['plec'] = in_array($g[0], ['m'], true) ? 'M' : 'K';
        elseif (preg_match('/a$/u', explode(' ', trim((string)$c['name']))[0] ?? '')) $out['plec'] = 'K';
        else $out['plec'] = 'M';
        return $out;
    }
}
