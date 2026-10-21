<?php
/**
 * modules/ti_training_contract/logic/trainingContractStore.php — zapis umów szkoleniowych TI (tabela umowy_szkolenie_ti),
 * rejestracja (numer TI/UMSZ/NNN/RRRR + numer Rejestru Umów RU/NNNN/RRRR/INI), dane przykładowe i ich czyszczenie.
 *
 * Rejestr Umów: tabela jest źródłem w CONTRACT_REGISTRY_EXTRA (includes/functions.php), więc zarejestrowane umowy widać
 * w contracts/rejestr.php i liczą się do globalnego licznika RU. Umowy testowe (is_test=1) dostają numery z „TEST”
 * (TI/UMSZ/TEST-NNN/RRRR, RU/TEST-NNNN/RRRR/INI) i NIE są liczone w liczniku RU — ich usunięcie nie robi dziur
 * ani duplikatów w prawdziwej numeracji.
 */
require_once __DIR__ . '/trainingContract.php';

final class TiTrainingContractStore
{
    public const TABLE = 'umowy_szkolenie_ti';

    /** Zapas — statusy są w słowniku umowy_statusy (statuses()). */
    public const STATUSES = ['projekt', 'do podpisu', 'podpisana', 'w realizacji', 'zakończona', 'rozwiązana', 'anulowana'];

    /** Statusy umowy szkoleniowej ze słownika umowy_statusy: ['kod' => 'Etykieta']. */
    public static function statuses(?string $current = null): array
    {
        return function_exists('contractStatusOptions') ? contractStatusOptions('szkolenie_ti', $current) : array_combine(self::STATUSES, self::STATUSES);
    }

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_training_contract_migrate', __FILE__)) return;
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        db()->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
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
        db()->exec("CREATE INDEX IF NOT EXISTS idx_umowy_szkolenie_ti_client ON " . self::TABLE . "(client_id)");
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

    /** Nadaje numer TI/UMSZ i numer RU (jednorazowo). Zwraca [numer_umowy, nr_rejestru]. */
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

    /** Kolejny numer TI/UMSZ/NNN/RRRR (licznik w obrębie roku; testowe mają własny licznik TEST-NNN). */
    private static function nextNumber(string $year, bool $test): string
    {
        $prefix = $test ? 'TI/UMSZ/TEST-' : 'TI/UMSZ/';
        $max = 0;
        foreach (db_all("SELECT numer_umowy FROM " . self::TABLE . " WHERE numer_umowy LIKE ?", [$prefix . '%/' . $year]) as $r) {
            if (preg_match('#^' . preg_quote($prefix, '#') . '(\d+)/\d{4}$#', $r['numer_umowy'], $m)) $max = max($max, (int)$m[1]);
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
