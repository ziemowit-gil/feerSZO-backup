<?php
/**
 * modules/holograms/logic/holograms.php — Ewidencja hologramów (naklejek zabezpieczających).
 *
 * Tabele:
 *  - holograms      jedna naklejka = jeden wiersz; holo_number UNIQUE,
 *  - holograms_log  historia zdarzeń (dodanie, wydanie, zwrot, uszkodzenie,
 *                   przywrócenie do puli) — rozliczalność: kto, kiedy, co i komu.
 *
 * Cykl życia (HOLO_TRANSITIONS):
 *   available ─wydanie─▶ issued ─zwrot─▶ returned ─ponowne wydanie─▶ issued
 *        │                  │                │
 *        └──────────────────┴──uszkodzenie───┴──▶ damaged (stan końcowy)
 *        └──────────────────┴──zagubienie────┴──▶ lost    (stan końcowy)
 *   returned ─przywrócenie do puli─▶ available
 *
 * Zagubienie i uszkodzenie wymagają opisu i rozliczają naklejkę przy umowie.
 * Każda operacja trafia też do wspólnego dziennika audit_logs
 * (modules/audit_logs) — w tej samej transakcji co zmiana.
 *
 * Powiązanie z umową (contract_type + contract_id, opcjonalne): naklejka wydana
 * w ramach umowy musi być rozliczona (zwrot albo uszkodzenie) zanim umowa
 * zostanie zakończona lub rozwiązana — ContractStatusTransitionValidator
 * (includes/contract_transitions.php) woła holo_assert_contract_settled().
 * Zwrot zostawia powiązanie (dowód rozliczenia na karcie umowy); przywrócenie
 * do puli je zdejmuje.
 *
 * Wszystkie operacje na wielu naklejkach (seria, wydanie zakresu, zmiana
 * statusu) są atomowe: jedna transakcja PDO, przy błędzie rollBack — nie ma
 * stanu „połowa zakresu wydana”.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';

const HOLO_STATUSES = [
    'available' => ['label' => 'Dostępny',   'plural' => 'Dostępne',   'icon' => 'bi-check2-circle'],
    'issued'    => ['label' => 'Wydany',     'plural' => 'Wydane',     'icon' => 'bi-box-arrow-up-right'],
    'returned'  => ['label' => 'Zwrócony',   'plural' => 'Zwrócone',   'icon' => 'bi-arrow-return-left'],
    'damaged'   => ['label' => 'Uszkodzony', 'plural' => 'Uszkodzone', 'icon' => 'bi-x-octagon'],
    'lost'      => ['label' => 'Zagubiony',  'plural' => 'Zagubione',  'icon' => 'bi-question-octagon'],
];

/** Dozwolone przejścia: status docelowy => statusy, z których można przejść. */
const HOLO_TRANSITIONS = [
    'issued'    => ['available', 'returned'],
    'returned'  => ['issued'],
    'damaged'   => ['available', 'issued', 'returned'],
    'lost'      => ['available', 'issued', 'returned'],
    'available' => ['returned'],
];

/** Statusy końcowe — wymagają opisu okoliczności. */
const HOLO_TERMINAL = ['damaged', 'lost'];

const HOLO_MAX_SERIES   = 5000;   // limit jednej serii / jednego wydania zakresem
const HOLO_PREFIX_RE    = '/^[A-Za-z0-9][A-Za-z0-9\-\/_.]{0,39}$/';
const HOLO_NUMBER_RE    = '/^[A-Za-z0-9][A-Za-z0-9\-\/_.]{0,63}$/';

function holograms_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS holograms (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        holo_number  TEXT NOT NULL UNIQUE,
        batch_number TEXT,
        status       TEXT NOT NULL DEFAULT 'available'
                     CHECK (status IN ('available','issued','damaged','returned','lost')),
        assigned_to  TEXT,
        issued_at    DATETIME,
        issued_by    TEXT,
        notes        TEXT,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_status ON holograms(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_batch  ON holograms(batch_number)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS holograms_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        hologram_id  INTEGER NOT NULL REFERENCES holograms(id) ON DELETE CASCADE,
        action       TEXT NOT NULL,
        from_status  TEXT,
        to_status    TEXT,
        details      TEXT,
        user_id      INTEGER,
        user_name    TEXT NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_log_h ON holograms_log(hologram_id)");
    // v2: powiązanie z umową
    $cols = array_column($pdo->query("PRAGMA table_info(holograms)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('contract_type', $cols, true)) $pdo->exec("ALTER TABLE holograms ADD COLUMN contract_type TEXT");
    if (!in_array('contract_id', $cols, true))   $pdo->exec("ALTER TABLE holograms ADD COLUMN contract_id INTEGER");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_contract ON holograms(contract_type, contract_id)");
    // v3: status 'lost' — SQLite nie zmienia CHECK przez ALTER, więc przebudowa tabeli
    $ddl = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='holograms'")->fetchColumn();
    if ($ddl !== '' && !str_contains($ddl, "'lost'") && !$pdo->inTransaction()) holograms_rebuild_with_lost($pdo);
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_assigned ON holograms(assigned_to)");
    audit_logs_migrate();
}

/**
 * Przebudowa tabeli holograms z nowym CHECK (dodany status 'lost').
 * foreign_keys musi być wyłączone POZA transakcją — inaczej DROP TABLE
 * skasowałby kaskadowo holograms_log. Po przebudowie sprawdzamy spójność kluczy.
 */
function holograms_rebuild_with_lost(PDO $pdo): void {
    $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec("CREATE TABLE holograms_v3 (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            holo_number   TEXT NOT NULL UNIQUE,
            batch_number  TEXT,
            status        TEXT NOT NULL DEFAULT 'available'
                          CHECK (status IN ('available','issued','damaged','returned','lost')),
            assigned_to   TEXT,
            issued_at     DATETIME,
            issued_by     TEXT,
            notes         TEXT,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            contract_type TEXT,
            contract_id   INTEGER
        )");
        $pdo->exec("INSERT INTO holograms_v3 (id, holo_number, batch_number, status, assigned_to, issued_at, issued_by, notes, created_at, contract_type, contract_id)
                    SELECT id, holo_number, batch_number, status, assigned_to, issued_at, issued_by, notes, created_at, contract_type, contract_id FROM holograms");
        $pdo->exec("DROP TABLE holograms");
        $pdo->exec("ALTER TABLE holograms_v3 RENAME TO holograms");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_status   ON holograms(status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_batch    ON holograms(batch_number)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_holograms_contract ON holograms(contract_type, contract_id)");
        if ($pdo->query("PRAGMA foreign_key_check")->fetch()) throw new RuntimeException('holograms v3: naruszenie kluczy obcych po przebudowie');
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('PRAGMA foreign_keys=ON');
        throw $e;
    }
    $pdo->exec('PRAGMA foreign_keys=ON');
}

/** Typy umów, przy których można wydawać hologramy (umowy z osobą/wykonawcą). */
const HOLO_CONTRACT_TYPES = ['wolontariat', 'zlecenie', 'praca', 'dzielo', 'uslugi', 'inne'];

/**
 * Umowa do powiązania: [type, id, numer, osoba, url] albo null, gdy typ spoza listy
 * lub umowy nie ma. Osoba: imie_nazwisko / nazwa_wykonawcy / strona_umowy.
 */
function holo_contract_info(string $type, int $id): ?array {
    if (!in_array($type, HOLO_CONTRACT_TYPES, true) || $id <= 0) return null;
    try {
        $c = db_one("SELECT * FROM umowy_{$type} WHERE id=?", [$id]);
    } catch (\Throwable $e) { return null; }
    if (!$c) return null;
    return [
        'type'   => $type,
        'id'     => $id,
        'number' => (string)($c['numer_umowy'] ?? ''),
        'person' => (string)($c['imie_nazwisko'] ?? $c['nazwa_wykonawcy'] ?? $c['strona_umowy'] ?? ''),
        'label'  => (defined('CONTRACT_TYPES') ? (CONTRACT_TYPES[$type] ?? $type) : $type),
        'url'    => APP_URL . "/contracts/{$type}/view.php?id={$id}",
    ];
}

/** Numery naklejek wydanych w ramach umowy i wciąż nierozliczonych (status issued). */
function holo_contract_unsettled(string $type, int $id): array {
    if (!in_array($type, HOLO_CONTRACT_TYPES, true) || $id <= 0) return [];
    try {
        holograms_migrate();
        return array_column(db_all(
            "SELECT holo_number FROM holograms WHERE contract_type=? AND contract_id=? AND status='issued' ORDER BY holo_number",
            [$type, $id]
        ), 'holo_number');
    } catch (\Throwable $e) { return []; }
}

/**
 * Blokada zamknięcia/rozwiązania umowy z nierozliczonymi hologramami.
 * Moduł wyłączony → brak blokady. Rzuca wyjątek podanej klasy (ContractTransitionException).
 */
function holo_assert_contract_settled(string $type, int $id, string $exceptionClass = \RuntimeException::class): void {
    if (function_exists('module_enabled') && !module_enabled('holograms_enabled')) return;
    $open = holo_contract_unsettled($type, $id);
    if (!$open) return;
    $list = implode(', ', array_slice($open, 0, 6)) . (count($open) > 6 ? ' i ' . (count($open) - 6) . ' innych' : '');
    throw new $exceptionClass(
        'Nie można zamknąć/rozwiązać umowy — nierozliczone hologramy (' . count($open) . '): ' . $list
        . '. Przyjmij zwrot albo oznacz jako uszkodzone na karcie „Hologramy” tej umowy.'
    );
}

/** Sama kolorowa kropka statusu (np. w filtrach) — dekoracyjna. */
function holo_status_dot(string $status): string {
    return '<span class="holo-badge is-' . (isset(HOLO_STATUSES[$status]) ? $status : 'unknown') . ' !tw-p-0 !tw-bg-transparent" aria-hidden="true"><span class="holo-badge__dot"></span></span>';
}

/** Znacznik statusu — wygląd w modules/holograms/partials/ui.php (.holo-badge). */
function holo_status_badge(string $status): string {
    $label = HOLO_STATUSES[$status]['label'] ?? $status;
    $cls   = isset(HOLO_STATUSES[$status]) ? $status : 'unknown';
    return '<span class="holo-badge is-' . $cls . '"><span class="holo-badge__dot" aria-hidden="true"></span>' . h($label) . '</span>';
}

/** Błąd walidacji / reguły biznesowej — komunikat nadaje się do pokazania użytkownikowi. */
class HologramException extends RuntimeException {}

class HologramService
{
    private PDO $pdo;
    private int $userId;
    private string $userName;

    public function __construct(?array $user = null)
    {
        holograms_migrate();
        $this->pdo      = db();
        $this->userId   = (int)($user['id'] ?? 0);
        $this->userName = trim((string)($user['name'] ?? ''));
    }

    // ── Numeracja ─────────────────────────────────────────────────────────────

    /**
     * Lista numerów z zakresu: prefiks + liczby od..do dopełnione zerami.
     * Szerokość dopełnienia: podana jawnie albo z długości zapisu numeru
     * początkowego („001” → 3). Zwraca np. HOLO-2026-001 … HOLO-2026-100.
     */
    public static function buildRange(string $prefix, string $from, string $to, ?int $pad = null): array
    {
        $prefix = trim($prefix);
        $from   = trim($from);
        $to     = trim($to);
        if ($prefix !== '' && !preg_match(HOLO_PREFIX_RE, $prefix)) {
            throw new HologramException('Prefiks może zawierać litery, cyfry oraz znaki - / _ . (maks. 40 znaków).');
        }
        if (!ctype_digit($from) || !ctype_digit($to)) {
            throw new HologramException('Numer początkowy i końcowy muszą być liczbami całkowitymi.');
        }
        if (strlen($from) > 12 || strlen($to) > 12) {
            throw new HologramException('Numer może mieć najwyżej 12 cyfr.');
        }
        $a = (int)$from;
        $b = (int)$to;
        if ($b < $a) throw new HologramException('Numer końcowy nie może być mniejszy niż początkowy.');
        $count = $b - $a + 1;
        if ($count > HOLO_MAX_SERIES) {
            throw new HologramException('Jednorazowo można obsłużyć maks. ' . HOLO_MAX_SERIES . ' naklejek (podano ' . $count . ').');
        }
        $pad = $pad ?? strlen($from);
        $pad = max(1, min(12, $pad));
        if (strlen((string)$b) > $pad) $pad = strlen((string)$b);
        $out = [];
        for ($i = $a; $i <= $b; $i++) {
            $out[] = $prefix . str_pad((string)$i, $pad, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** Numery wpisane ręcznie (przecinki, średniki, spacje, nowe linie) — bez duplikatów. */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (!preg_match(HOLO_NUMBER_RE, $p)) {
                throw new HologramException('Nieprawidłowy numer hologramu: „' . mb_substr($p, 0, 40) . '”.');
            }
            $out[$p] = true;
        }
        if (count($out) > HOLO_MAX_SERIES) {
            throw new HologramException('Jednorazowo można obsłużyć maks. ' . HOLO_MAX_SERIES . ' naklejek.');
        }
        return array_keys($out);
    }

    // ── Dodawanie serii ───────────────────────────────────────────────────────

    /**
     * Dodaje serię naklejek w jednej transakcji.
     * $skipDuplicates = false → jeśli którykolwiek numer już istnieje, nic nie jest dodawane.
     * $skipDuplicates = true  → istniejące numery są pomijane, reszta dodana.
     * @return array{added:int, skipped:string[]}
     */
    public function addSeries(array $numbers, string $batch, string $notes = '', bool $skipDuplicates = false): array
    {
        $batch = mb_substr(trim($batch), 0, 100);
        $notes = mb_substr(trim($notes), 0, 2000);
        if (!$numbers) throw new HologramException('Brak numerów do dodania.');

        $existing = $this->existingNumbers($numbers);
        if ($existing && !$skipDuplicates) {
            throw new HologramException(
                'W ewidencji są już numery z tego zakresu (' . count($existing) . '): '
                . self::sample($existing) . '. Zmień zakres albo zaznacz „Pomiń istniejące numery”.'
            );
        }
        $existingSet = array_flip($existing);
        $now = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            // INSERT OR IGNORE — chroni też przed wyścigiem z równoległym dodawaniem
            $ins = $this->pdo->prepare(
                "INSERT OR IGNORE INTO holograms (holo_number, batch_number, status, notes, created_at)
                 VALUES (?, ?, 'available', ?, ?)"
            );
            $log = $this->logStatement();
            $added = 0;
            $skipped = $existing;
            foreach ($numbers as $n) {
                if (isset($existingSet[$n])) continue;
                $ins->execute([$n, $batch !== '' ? $batch : null, $notes !== '' ? $notes : null, $now]);
                if ($ins->rowCount() === 0) {
                    if (!$skipDuplicates) throw new HologramException('Numer ' . $n . ' został właśnie dodany przez kogoś innego. Spróbuj ponownie.');
                    $skipped[] = $n;
                    continue;
                }
                $id = (int)$this->pdo->lastInsertId();
                $log->execute([$id, 'created', null, 'available', $batch !== '' ? 'Seria: ' . $batch : null, $this->userId ?: null, $this->userName, $now]);
                $added++;
            }
            if ($added) {
                $this->audit('holograms.series_added', [
                    'added'   => $added,
                    'skipped' => count($skipped),
                    'range'   => $numbers[0] . ' – ' . end($numbers),
                    'batch'   => $batch,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    // ── Wydawanie ─────────────────────────────────────────────────────────────

    /**
     * Wydaje naklejki (status issued) osobie / dokumentowi / sprzętowi.
     * Wszystkie numery muszą istnieć i być dostępne lub zwrócone — inaczej nic nie jest wydawane.
     */
    public function issue(array $numbers, string $assignedTo, string $issuedBy, string $notes = '', ?array $contract = null): int
    {
        // $contract — ['type' => …, 'id' => …]; weryfikowany, żeby nie powiązać z nieistniejącą umową
        $c = null;
        if ($contract && (($contract['type'] ?? '') !== '' || (int)($contract['id'] ?? 0))) {
            $c = holo_contract_info((string)($contract['type'] ?? ''), (int)($contract['id'] ?? 0));
            if (!$c) throw new HologramException('Wskazana umowa nie istnieje albo jej typ nie obsługuje hologramów.');
        }
        $assignedTo = mb_substr(trim($assignedTo), 0, 255);
        $issuedBy   = mb_substr(trim($issuedBy), 0, 255) ?: $this->userName;
        $notes      = mb_substr(trim($notes), 0, 2000);
        if ($assignedTo === '') throw new HologramException('Wskaż, komu lub czemu przypisujesz naklejki.');
        if (!$numbers) throw new HologramException('Brak numerów do wydania.');

        $now = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $rows = $this->fetchByNumbers($numbers);
            $this->assertAllFound($numbers, $rows);
            $this->assertTransition($rows, 'issued');

            $upd = $this->pdo->prepare(
                "UPDATE holograms SET status='issued', assigned_to=?, issued_at=?, issued_by=?,
                        contract_type=?, contract_id=?,
                        notes=CASE WHEN ? <> '' THEN ? ELSE notes END
                 WHERE id=? AND status IN ('available','returned')"
            );
            $cDesc = $c ? ' · umowa ' . ($c['number'] ?: '#' . $c['id']) : '';
            $log = $this->logStatement();
            foreach ($rows as $r) {
                $upd->execute([$assignedTo, $now, $issuedBy, $c['type'] ?? null, $c['id'] ?? null, $notes, $notes, $r['id']]);
                if ($upd->rowCount() !== 1) throw new HologramException('Hologram ' . $r['holo_number'] . ' zmienił status w trakcie operacji. Spróbuj ponownie.');
                $log->execute([$r['id'], 'issued', $r['status'], 'issued',
                    'Przypisano: ' . $assignedTo . $cDesc . ($notes !== '' ? ' · ' . $notes : ''), $this->userId ?: null, $this->userName, $now]);
            }
            $this->audit('holograms.issued', [
                'count'       => count($rows),
                'numbers'     => self::sample(array_column($rows, 'holo_number'), 50),
                'assigned_to' => $assignedTo,
                'issued_by'   => $issuedBy,
                'contract'    => $c ? $c['type'] . '#' . $c['id'] : null,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return count($rows);
    }

    // ── Zmiana statusu (zwrot, uszkodzenie, przywrócenie do puli) ─────────────

    /**
     * @param int[] $ids
     * Zwrot zachowuje historię przypisania w logu, ale czyści assigned_to,
     * żeby lista pokazywała aktualny stan. Uszkodzenie wymaga opisu.
     */
    public function changeStatus(array $ids, string $to, string $note = ''): int
    {
        if (!isset(HOLO_TRANSITIONS[$to]) || $to === 'issued') {
            throw new HologramException('Nieobsługiwana zmiana statusu.');
        }
        $ids  = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $note = mb_substr(trim($note), 0, 2000);
        if (!$ids) throw new HologramException('Zaznacz co najmniej jeden hologram.');
        if (count($ids) > HOLO_MAX_SERIES) throw new HologramException('Za dużo pozycji naraz.');
        if ($to === 'damaged' && $note === '') {
            throw new HologramException('Opisz uszkodzenie (np. „rozdarta przy naklejaniu”) — to wymóg rozliczenia.');
        }
        if ($to === 'lost' && $note === '') {
            throw new HologramException('Opisz okoliczności zagubienia (kto, kiedy, gdzie) — to wymóg rozliczenia.');
        }

        $now = date('Y-m-d H:i:s');
        $action = ['returned' => 'returned', 'damaged' => 'damaged', 'lost' => 'lost', 'available' => 'restocked'][$to];
        $this->pdo->beginTransaction();
        try {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT * FROM holograms WHERE id IN ({$ph})");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new HologramException('Część zaznaczonych hologramów nie istnieje.');
            $this->assertTransition($rows, $to);

            $clearAssignment = in_array($to, ['returned', 'available'], true);
            $upd = $this->pdo->prepare(
                "UPDATE holograms SET status=?"
                . ($clearAssignment ? ", assigned_to=NULL, issued_at=NULL, issued_by=NULL" : '')
                . ($to === 'available' ? ", contract_type=NULL, contract_id=NULL" : '')
                . ", notes=CASE WHEN ? <> '' THEN ? ELSE notes END WHERE id=? AND status=?"
            );
            $log = $this->logStatement();
            foreach ($rows as $r) {
                $upd->execute([$to, $note, $note, $r['id'], $r['status']]);
                if ($upd->rowCount() !== 1) throw new HologramException('Hologram ' . $r['holo_number'] . ' zmienił status w trakcie operacji. Spróbuj ponownie.');
                $details = $note;
                if ($r['status'] === 'issued' && $r['assigned_to']) {
                    $details = 'Był przypisany: ' . $r['assigned_to'] . ($note !== '' ? ' · ' . $note : '');
                }
                $log->execute([$r['id'], $action, $r['status'], $to, $details !== '' ? $details : null, $this->userId ?: null, $this->userName, $now]);
            }
            $this->audit('holograms.' . $action, [
                'count'   => count($rows),
                'numbers' => self::sample(array_column($rows, 'holo_number'), 50),
                'from'    => implode(',', array_unique(array_column($rows, 'status'))),
                'to'      => $to,
                'note'    => $note,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return count($rows);
    }

    // ── Odczyt ────────────────────────────────────────────────────────────────

    /** @return array{rows:array, total:int} */
    public function search(string $status, string $q, string $batch, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset(HOLO_STATUSES[$status])) { $where[] = 'status = ?'; $params[] = $status; }
        if ($q !== '') {
            $where[] = "(holo_number LIKE ? ESCAPE '\\' OR assigned_to LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params[] = $like; $params[] = $like;
        }
        if ($batch !== '') { $where[] = 'batch_number = ?'; $params[] = $batch; }
        $w = implode(' AND ', $where);

        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM holograms WHERE {$w}");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT * FROM holograms WHERE {$w} ORDER BY holo_number LIMIT ? OFFSET ?");
        foreach ($params as $i => $p) $stmt->bindValue($i + 1, $p);
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** Naklejki powiązane z umową (wydane, zwrócone, uszkodzone). */
    public function forContract(string $type, int $id): array
    {
        $s = $this->pdo->prepare("SELECT * FROM holograms WHERE contract_type=? AND contract_id=?
                                  ORDER BY CASE status WHEN 'issued' THEN 0 ELSE 1 END, holo_number");
        $s->execute([$type, $id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Liczba naklejek w każdym statusie + łącznie. */
    public function stats(): array
    {
        $out = array_fill_keys(array_keys(HOLO_STATUSES), 0);
        foreach ($this->pdo->query("SELECT status, COUNT(*) c FROM holograms GROUP BY status") as $r) {
            $out[$r['status']] = (int)$r['c'];
        }
        $out['total'] = array_sum($out);
        return $out;
    }

    /** Podsumowanie serii dostaw: ile naklejek w każdym statusie. */
    public function batches(): array
    {
        return $this->pdo->query(
            "SELECT COALESCE(batch_number,'') AS batch_number, COUNT(*) AS total,
                    SUM(status='available') AS available, SUM(status='issued') AS issued,
                    SUM(status='returned') AS returned, SUM(status='damaged') AS damaged, SUM(status='lost') AS lost,
                    MIN(holo_number) AS first_no, MAX(holo_number) AS last_no, MIN(created_at) AS created_at
             FROM holograms GROUP BY COALESCE(batch_number,'') ORDER BY MIN(created_at) DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT * FROM holograms WHERE id=?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function history(int $id): array
    {
        $s = $this->pdo->prepare("SELECT * FROM holograms_log WHERE hologram_id=? ORDER BY id DESC");
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    /** Wpis do wspólnego audit_logs — wołany wewnątrz transakcji operacji. */
    private function audit(string $action, array $details): void
    {
        audit_log($action, array_filter($details, fn($v) => $v !== null && $v !== ''), $this->userId ?: null);
    }

    private function logStatement(): PDOStatement
    {
        return $this->pdo->prepare(
            "INSERT INTO holograms_log (hologram_id, action, from_status, to_status, details, user_id, user_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
    }

    /** Które z podanych numerów już istnieją (zapytania w paczkach — limit parametrów SQLite). */
    private function existingNumbers(array $numbers): array
    {
        $found = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->pdo->prepare("SELECT holo_number FROM holograms WHERE holo_number IN ({$ph})");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $n) $found[] = $n;
        }
        return $found;
    }

    private function fetchByNumbers(array $numbers): array
    {
        $rows = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->pdo->prepare("SELECT * FROM holograms WHERE holo_number IN ({$ph})");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;
        }
        return $rows;
    }

    private function assertAllFound(array $numbers, array $rows): void
    {
        $missing = array_values(array_diff($numbers, array_column($rows, 'holo_number')));
        if ($missing) {
            throw new HologramException('Brak w ewidencji (' . count($missing) . '): ' . self::sample($missing) . '. Najpierw dodaj je jako serię.');
        }
    }

    private function assertTransition(array $rows, string $to): void
    {
        $allowed = HOLO_TRANSITIONS[$to];
        $bad = [];
        foreach ($rows as $r) {
            if (!in_array($r['status'], $allowed, true)) {
                $bad[] = $r['holo_number'] . ' (' . (HOLO_STATUSES[$r['status']]['label'] ?? $r['status']) . ')';
            }
        }
        if ($bad) {
            $label = mb_strtolower(HOLO_STATUSES[$to]['label']);
            throw new HologramException('Nie można zmienić statusu na „' . $label . '” (' . count($bad) . '): ' . self::sample($bad) . '. Nic nie zostało zmienione.');
        }
    }

    private static function sample(array $items, int $max = 8): string
    {
        $s = implode(', ', array_slice($items, 0, $max));
        return count($items) > $max ? $s . ' i ' . (count($items) - $max) . ' innych' : $s;
    }
}

/**
 * Karta „Hologramy” do bocznej kolumny widoku umowy (Bootstrap, jak reszta widoku):
 * stan rozliczenia, lista naklejek powiązanych z umową, szybki zwrot i wydanie nowej.
 */
function holo_contract_card(string $type, int $id): void {
    if (!in_array($type, HOLO_CONTRACT_TYPES, true) || $id <= 0) return;
    if (function_exists('module_enabled') && !module_enabled('holograms_enabled')) return;
    $u = function_exists('current_user') ? current_user() : null;
    if (!in_array($u['role'] ?? '', ['admin', 'editor'], true)) return;
    try {
        $rows = (new HologramService($u))->forContract($type, $id);
    } catch (\Throwable $e) { return; }
    $open = array_values(array_filter($rows, fn($r) => $r['status'] === 'issued'));
    $bs = ['available' => 'bg-success', 'issued' => 'bg-primary', 'returned' => 'bg-warning text-dark', 'damaged' => 'bg-danger', 'lost' => 'bg-dark'];
    $base = APP_URL . '/modules/holograms';
    ?>
    <div class="card shadow-sm mb-3 <?= $open ? 'border-warning' : '' ?>" id="hologramy">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-patch-check text-primary" aria-hidden="true"></i>
        <span>Hologramy</span>
        <?php if ($open): ?>
        <span class="badge bg-warning text-dark ms-auto">Do rozliczenia: <?= count($open) ?></span>
        <?php elseif ($rows): ?>
        <span class="badge bg-success ms-auto"><i class="bi bi-check2" aria-hidden="true"></i> Rozliczone</span>
        <?php endif; ?>
      </div>
      <div class="card-body small">
        <?php if ($open): ?>
        <p class="text-warning-emphasis mb-2">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
          Umowy nie da się zakończyć ani rozwiązać, dopóki wydane naklejki nie zostaną zwrócone lub oznaczone jako uszkodzone.
        </p>
        <?php endif; ?>
        <?php if (!$rows): ?>
        <p class="text-muted mb-2">Do tej umowy nie wydano hologramów.</p>
        <?php else: ?>
        <ul class="list-unstyled mb-2">
          <?php foreach ($rows as $r): ?>
          <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
            <a href="<?= $base ?>/view.php?id=<?= (int)$r['id'] ?>" class="font-monospace text-decoration-none"><?= h($r['holo_number']) ?></a>
            <span class="badge <?= $bs[$r['status']] ?? 'bg-secondary' ?>"><?= h(HOLO_STATUSES[$r['status']]['label'] ?? $r['status']) ?></span>
            <?php if ($r['status'] === 'issued'): ?>
            <form method="post" action="<?= $base ?>/view.php?id=<?= (int)$r['id'] ?>&amp;back=contract" class="ms-auto">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="to_status" value="returned">
              <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2"
                      aria-label="Przyjmij zwrot hologramu <?= h($r['holo_number']) ?>">
                <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Zwrot
              </button>
            </form>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <a href="<?= $base ?>/index.php?issue_type=<?= h($type) ?>&amp;issue_id=<?= $id ?>" class="btn btn-sm btn-outline-primary w-100">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Wydaj hologram do tej umowy
        </a>
      </div>
    </div>
    <?php
}
