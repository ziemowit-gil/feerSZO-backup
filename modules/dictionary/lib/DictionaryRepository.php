<?php
declare(strict_types=1);
/**
 * Repozytorium Słownika Centralnego — jedyne miejsce z SQL-em. Wyłącznie zapytania parametryzowane;
 * nazwy kolumn w INSERT/UPDATE tylko z białej listy (klucze tablic nie trafiają do SQL wprost).
 * SQL przenośny (SQLite/MySQL): bez INSERT OR …, bez funkcji dat bazy — znaczniki czasu liczy PHP.
 */
final class DictionaryRepository
{
    private const DICT_COLS  = ['parent_id', 'code', 'name', 'description', 'kind', 'is_protected', 'sort_order',
                                'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at'];
    private const ENTRY_COLS = ['dictionary_id', 'parent_id', 'code', 'label', 'description', 'attributes', 'sort_order',
                                'is_active', 'valid_from', 'valid_to', 'version', 'created_at', 'created_by',
                                'updated_at', 'updated_by', 'deleted_at'];

    public function __construct(private readonly \PDO $pdo) {}

    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) return $fn();
        $this->pdo->beginTransaction();
        try { $r = $fn(); $this->pdo->commit(); return $r; }
        catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    /** Transakcja zawsze wycofywana — „próba na sucho” importu. */
    public function dryRun(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try { return $fn(); }
        finally { $this->pdo->rollBack(); }
    }

    // ── Słowniki ──────────────────────────────────────────────────────────

    public function findDictionaryByCode(string $code, bool $withDeleted = false): ?Dictionary
    {
        $st = $this->pdo->prepare('SELECT * FROM dictionaries WHERE code = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'));
        $st->execute([$code]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? Dictionary::fromRow($r) : null;
    }

    public function findDictionaryById(int $id): ?Dictionary
    {
        $st = $this->pdo->prepare('SELECT * FROM dictionaries WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? Dictionary::fromRow($r) : null;
    }

    /** @return Dictionary[] */
    public function allDictionaries(bool $withDeleted = false): array
    {
        $rows = $this->pdo->query('SELECT * FROM dictionaries' . ($withDeleted ? '' : ' WHERE deleted_at IS NULL')
                                  . ' ORDER BY sort_order, name')->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([Dictionary::class, 'fromRow'], $rows);
    }

    /** @return array<int,int> dictionary_id => liczba nieusuniętych wpisów */
    public function entryCounts(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT dictionary_id, COUNT(*) AS n FROM dictionary_entries WHERE deleted_at IS NULL GROUP BY dictionary_id') as $r)
            $out[(int)$r['dictionary_id']] = (int)$r['n'];
        return $out;
    }

    public function insertDictionary(array $data): int
    {
        return $this->insert('dictionaries', self::DICT_COLS, $data);
    }

    public function updateDictionary(int $id, array $data): void
    {
        $this->update('dictionaries', self::DICT_COLS, $id, $data);
    }

    // ── Wpisy ─────────────────────────────────────────────────────────────

    /** @return DictionaryEntry[] w kolejności sort_order, label */
    public function entries(int $dictionaryId, bool $withDeleted = false): array
    {
        $st = $this->pdo->prepare('SELECT * FROM dictionary_entries WHERE dictionary_id = ?'
                                  . ($withDeleted ? '' : ' AND deleted_at IS NULL') . ' ORDER BY sort_order, label, id');
        $st->execute([$dictionaryId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $labels = $this->labelsFor(array_map(fn($r) => (int)$r['id'], $rows));
        return array_map(fn($r) => DictionaryEntry::fromRow($r, $labels[(int)$r['id']] ?? []), $rows);
    }

    public function findEntry(int $id): ?DictionaryEntry
    {
        $st = $this->pdo->prepare('SELECT * FROM dictionary_entries WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? DictionaryEntry::fromRow($r, $this->labelsFor([(int)$r['id']])[(int)$r['id']] ?? []) : null;
    }

    public function findEntryByCode(int $dictionaryId, string $code): ?DictionaryEntry
    {
        $st = $this->pdo->prepare('SELECT * FROM dictionary_entries WHERE dictionary_id = ? AND code = ?');
        $st->execute([$dictionaryId, $code]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? DictionaryEntry::fromRow($r, $this->labelsFor([(int)$r['id']])[(int)$r['id']] ?? []) : null;
    }

    public function hasLiveChildren(int $entryId): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM dictionary_entries WHERE parent_id = ? AND deleted_at IS NULL');
        $st->execute([$entryId]);
        return (int)$st->fetchColumn() > 0;
    }

    public function insertEntry(array $data): int
    {
        return $this->insert('dictionary_entries', self::ENTRY_COLS, $data);
    }

    /** Aktualizacja z blokadą optymistyczną: false = wersja w bazie inna niż $expectedVersion. */
    public function updateEntry(int $id, array $data, ?int $expectedVersion = null): bool
    {
        return $this->update('dictionary_entries', self::ENTRY_COLS, $id, $data, $expectedVersion) > 0;
    }

    /** @param int[] $ids @return array<int, array<string,string>> */
    public function labelsFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = $this->pdo->prepare('SELECT entry_id, locale, label FROM dictionary_entry_labels WHERE entry_id IN ('
                                      . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $st->execute($chunk);
            foreach ($st as $r) $out[(int)$r['entry_id']][(string)$r['locale']] = (string)$r['label'];
        }
        return $out;
    }

    /** @param array<string,string> $labels pełny zestaw tłumaczeń (brakujące języki są usuwane) */
    public function replaceLabels(int $entryId, array $labels): void
    {
        $this->pdo->prepare('DELETE FROM dictionary_entry_labels WHERE entry_id = ?')->execute([$entryId]);
        $ins = $this->pdo->prepare('INSERT INTO dictionary_entry_labels (entry_id, locale, label) VALUES (?, ?, ?)');
        foreach ($labels as $loc => $label) $ins->execute([$entryId, (string)$loc, (string)$label]);
    }

    // ── Historia ──────────────────────────────────────────────────────────

    public function addHistory(int $dictionaryId, ?int $entryId, string $action, ?array $before, ?array $after, ?int $userId): void
    {
        $this->pdo->prepare('INSERT INTO dictionary_history (dictionary_id, entry_id, action, before_json, after_json, user_id, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$dictionaryId, $entryId, $action,
                       $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                       $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                       $userId, date('Y-m-d H:i:s')]);
    }

    /** Historia słownika (najnowsze najpierw); $entryId zawęża do jednego wpisu. */
    public function history(int $dictionaryId, ?int $entryId = null, int $limit = 200): array
    {
        $sql = 'SELECT h.*, e.code AS entry_code FROM dictionary_history h
                LEFT JOIN dictionary_entries e ON e.id = h.entry_id
                WHERE h.dictionary_id = ?' . ($entryId ? ' AND h.entry_id = ?' : '') . ' ORDER BY h.id DESC LIMIT ' . max(1, min(1000, $limit));
        $st = $this->pdo->prepare($sql);
        $st->execute($entryId ? [$dictionaryId, $entryId] : [$dictionaryId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param int[] $ids @return array<int,string> id => nazwa użytkownika */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        try {
            $st = $this->pdo->prepare('SELECT id, name, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            $out = [];
            foreach ($st as $r) $out[(int)$r['id']] = (string)($r['name'] ?: $r['email']);
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    // ── Wspólne ───────────────────────────────────────────────────────────

    private function insert(string $table, array $allowed, array $data): int
    {
        $data = array_intersect_key($data, array_flip($allowed));
        $cols = array_keys($data);
        $st = $this->pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    private function update(string $table, array $allowed, int $id, array $data, ?int $expectedVersion = null): int
    {
        $data = array_intersect_key($data, array_flip($allowed));
        if (!$data) return 0;
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
        $sql = "UPDATE $table SET $set WHERE id = ?" . ($expectedVersion !== null ? ' AND version = ?' : '');
        $params = [...array_values($data), $id];
        if ($expectedVersion !== null) $params[] = $expectedVersion;
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
