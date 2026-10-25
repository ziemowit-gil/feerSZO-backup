<?php
/**
 * modules/field_crypto/lib/fcDb.php — przezroczyste szyfrowanie kolumn z PESEL w warstwie db() (funkcjonalność współdzielona).
 *
 * Jak to działa (kod aplikacji nie musi o niczym wiedzieć):
 *   ODCZYT   Każdy wiersz pobrany przez db_one / db_all / ->fetch*() / foreach po zapytaniu dostaje odszyfrowane wartości
 *            (wartość zaczynająca się od „enc1:” jest odszyfrowywana kontekstem zarejestrowanej kolumny). Dotyczy też JOIN-ów,
 *            SELECT * i aliasów (c.pesel AS client_pesel).
 *   ZAPIS    Kod nadal zapisuje PESEL jawnie (INSERT/UPDATE jak dotąd). Po żądaniu, które coś zapisało do zarejestrowanej tabeli,
 *            „zamiatanie” (fcSweep, register_shutdown_function) szyfruje jawne wartości i uzupełnia indeksy ślepe.
 *            Jawny PESEL leży więc w bazie najwyżej do końca żądania; wyjątek: zapisy przez PDO::exec() (poza obiektem zapytania)
 *            — domknie je kolejny zapis do tej tabeli albo `php cli/fieldCrypto.php encrypt --apply`.
 *   SZUKANIE Po zaszyfrowanej kolumnie nie działa WHERE pesel = ?, LIKE ani SUBSTR. Zamiast tego:
 *            fcMatchPesel()  — dokładne dopasowanie całego PESEL-u (indeks ślepy + zapas dla jeszcze jawnych wierszy),
 *            fcMatchLast5()  — ostatnie 5 cyfr (weryfikacja tożsamości); kolumna musi mieć 'l5' => true w rejestrze.
 *
 * Włączenie: dopiero gdy w config.local.php jest DATA_KEY (fcAutoEnabled). Bez klucza nic się nie zmienia — kod działa
 * jak przed wprowadzeniem szyfrowania. Kolumny rejestruje fcColumns() (wpis z 'auto' => true).
 *
 * Wyłączenie odczytu przezroczystego (np. narzędzie CLI, które ma zobaczyć surowe dane): FcStatement::$raw = true.
 */

require_once __DIR__ . '/fieldCrypto.php';
require_once dirname(__DIR__) . '/logic/fcColumns.php';

/** Szyfrowanie przezroczyste działa tylko z osobnym DATA_KEY (nie awaryjnie z APP_KEY). */
function fcAutoEnabled(): bool { return fcHasDedicatedKey(); }

/** Zarejestrowane kolumny przezroczyste: [table, column, kind, blind, l5?]. */
function fcAutoColumns(): array {
    static $c = null;
    return $c ??= array_values(array_filter(fcColumns(), fn($x) => !empty($x['auto'])));
}

function fcCtx(array $c): string { return $c['table'] . '.' . $c['column']; }

/** [nazwa kolumny => [konteksty]] oraz lista wszystkich kontekstów (zapas dla aliasów). */
function fcAutoContexts(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = ['by' => [], 'all' => []];
    foreach (fcAutoColumns() as $c) { $m['by'][$c['column']][] = fcCtx($c); $m['all'][] = fcCtx($c); }
    $m['all'] = array_values(array_unique($m['all']));
    return $m;
}

function fcAutoDecryptValue(string $v, int|string|null $key = null): string {
    if (!str_starts_with($v, FC_PREFIX)) return $v;
    $ctx = fcAutoContexts();
    $try = is_string($key) && isset($ctx['by'][$key]) ? array_merge($ctx['by'][$key], $ctx['all']) : $ctx['all'];
    foreach (array_unique($try) as $c) {
        $p = fcDecrypt($v, $c);
        if ($p !== null) return $p;
    }
    return $v;   // brak klucza / nieznany kontekst — nie wywracamy aplikacji, zostaje szyfrogram
}

function fcAutoDecryptRow(mixed $r): mixed {
    if (is_string($r)) return fcAutoDecryptValue($r);
    if (is_array($r)) {
        foreach ($r as $k => $v) {
            if (is_string($v)) { if (str_starts_with($v, FC_PREFIX)) $r[$k] = fcAutoDecryptValue($v, $k); }
            elseif (is_array($v)) $r[$k] = fcAutoDecryptRow($v);
        }
        return $r;
    }
    if (is_object($r)) {
        foreach (get_object_vars($r) as $k => $v) if (is_string($v) && str_starts_with($v, FC_PREFIX)) $r->$k = fcAutoDecryptValue($v, $k);
    }
    return $r;
}

final class FcStatement extends PDOStatement
{
    /** true = bez odszyfrowywania i bez rejestrowania zapisów (CLI, zamiatanie). */
    public static bool $raw = false;

    protected function __construct() {}

    public function execute(?array $params = null): bool {
        $ok = parent::execute($params);
        if ($ok && !self::$raw) fcNoteWrite($this->queryString);
        return $ok;
    }

    #[\ReturnTypeWillChange]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        $r = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        return ($r === false || self::$raw) ? $r : fcAutoDecryptRow($r);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $r = parent::fetchAll($mode, ...$args);
        if (self::$raw) return $r;
        foreach ($r as $i => $row) $r[$i] = fcAutoDecryptRow($row);
        return $r;
    }

    #[\ReturnTypeWillChange]
    public function fetchColumn(int $column = 0): mixed {
        $r = parent::fetchColumn($column);
        return (is_string($r) && !self::$raw) ? fcAutoDecryptValue($r) : $r;
    }

    #[\ReturnTypeWillChange]
    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false {
        $r = parent::fetchObject($class, $constructorArgs);
        return ($r === false || self::$raw) ? $r : fcAutoDecryptRow($r);
    }

    /** foreach ($pdo->query(...) as $row) — iterator PDO omija fetch(), więc oddajemy wiersze przez własny. */
    public function getIterator(): Iterator {
        return (function () { while (($r = $this->fetch()) !== false) yield $r; })();
    }
}

/** Podpina warstwę do połączenia (wywołane z db()). Bez DATA_KEY nic nie robi. */
function fcAttach(PDO $pdo): void {
    if (!fcAutoEnabled() || !fcAutoColumns()) return;
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FcStatement::class]);
}

// ───────────────────────── Zapis: zamiatanie ─────────────────────────

/** @var array<string,bool> tabele, do których zapisano w tym żądaniu */
$GLOBALS['_fc_dirty'] = [];

function fcNoteWrite(string $sql): void {
    if (!fcAutoEnabled() || !preg_match('/^\s*(INSERT|UPDATE|REPLACE)\b/i', $sql)) return;
    $any = false;
    foreach (fcAutoColumns() as $c) {
        if (stripos($sql, $c['table']) !== false) { $GLOBALS['_fc_dirty'][$c['table']] = true; $any = true; }
    }
    if ($any) {
        static $registered = false;
        if (!$registered) { $registered = true; register_shutdown_function('fcSweep'); }
    }
}

/** Dodaje kolumny indeksów ślepych, jeśli ich nie ma (idempotentne, raz na żądanie). */
function fcEnsureIdx(array $c): void {
    static $done = [];
    $k = fcCtx($c);
    if (isset($done[$k])) return;
    $done[$k] = true;
    $cols = fcTableColumns($c['table']);
    $need = array_filter([$c['blind'] ?? null, !empty($c['l5']) ? $c['column'] . '_l5' : null]);
    foreach ($need as $n) {
        if (in_array($n, $cols, true)) continue;
        try {
            db()->exec("ALTER TABLE `{$c['table']}` ADD COLUMN `{$n}` VARCHAR(64) NOT NULL DEFAULT ''");
            try { db()->exec("CREATE INDEX `ix_{$c['table']}_{$n}` ON `{$c['table']}`(`{$n}`)"); } catch (\Throwable $e) {}
        } catch (\Throwable $e) { /* inny proces mógł dodać kolumnę równolegle */ }
    }
}

function fcTableColumns(string $table): array {
    try {
        if (DB_TYPE === 'sqlite') return array_column(db()->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        return db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (\Throwable $e) { return []; }
}

/** Szyfruje jawne wartości (i uzupełnia indeksy) w tabelach zapisanych w tym żądaniu albo wskazanych w $tables. */
function fcSweep(?array $tables = null, int $limit = 2000): int {
    static $running = false;
    if ($running || !fcAutoEnabled()) return 0;
    $running = true;
    $n = 0;
    $prevRaw = FcStatement::$raw;
    FcStatement::$raw = true;
    try {
        $tables ??= array_keys($GLOBALS['_fc_dirty']);
        foreach (fcAutoColumns() as $c) {
            if (!in_array($c['table'], $tables, true)) continue;
            if (!in_array($c['column'], fcTableColumns($c['table']), true)) continue;
            fcEnsureIdx($c);
            $col = $c['column'];
            $idx = $c['blind'] ?? null;
            // wyczyszczona wartość (anonimizacja, retencja) nie może zostawić po sobie indeksu — to ślad skasowanego PESEL-u
            foreach (array_filter([$idx, !empty($c['l5']) ? $col . '_l5' : null]) as $ix) {
                db_exec("UPDATE `{$c['table']}` SET `{$ix}` = '' WHERE (`{$col}` IS NULL OR `{$col}` = '') AND `{$ix}` <> ''");
            }
            $need = "`{$col}` NOT LIKE 'enc1:%'" . ($idx ? " OR `{$idx}` = ''" : '') . (!empty($c['l5']) ? " OR `{$col}_l5` = ''" : '');
            $rows = db_all("SELECT id, `{$col}` AS v FROM `{$c['table']}` WHERE `{$col}` IS NOT NULL AND `{$col}` <> '' AND ({$need}) LIMIT {$limit}");
            foreach ($rows as $r) {
                $plain = fcIsEncrypted((string)$r['v']) ? fcDecrypt((string)$r['v'], fcCtx($c)) : (string)$r['v'];
                if ($plain === null) continue;
                fcWriteColumn($c, (int)$r['id'], $plain);
                $n++;
            }
        }
    } catch (\Throwable $e) {
        error_log('[field_crypto] sweep: ' . $e->getMessage());
    } finally {
        FcStatement::$raw = $prevRaw;
        $running = false;
        $GLOBALS['_fc_dirty'] = [];
    }
    return $n;
}

// ───────────────────────── Szukanie po zaszyfrowanej kolumnie ─────────────────────────

function fcAutoEntry(string $table, string $col): ?array {
    foreach (fcAutoColumns() as $c) if ($c['table'] === $table && $c['column'] === $col) return $c;
    return null;
}

/**
 * Warunek SQL „PESEL równa się wartości”: [sql, parametry]. Działa w obu stanach (wiersze już zaszyfrowane i jeszcze jawne).
 * $alias — alias tabeli w zapytaniu (np. 'c' → c.`pesel`). Wartość inna niż 11 cyfr → warunek nigdy niespełniony.
 */
function fcMatchPesel(string $table, string $col, ?string $value, string $alias = ''): array {
    $digits = preg_replace('/\D/', '', (string)$value);
    $p = $alias !== '' ? $alias . '.' : '';
    if (strlen($digits) !== 11) return ['0=1', []];
    $plain = "REPLACE(REPLACE({$p}`{$col}`,' ',''),'-','') = ?";
    $e = fcAutoEntry($table, $col);
    if (!$e || !fcAutoEnabled() || empty($e['blind'])) return [$plain, [$digits]];
    fcEnsureIdx($e);
    $ids = fcBlindAll($digits, fcCtx($e), 'digits');
    return ['(' . $p . '`' . $e['blind'] . '` IN (' . implode(',', array_fill(0, count($ids), '?')) . ") OR {$plain})", [...$ids, $digits]];
}

/** Warunek „ostatnie 5 cyfr PESEL-u” (weryfikacja tożsamości); kolumna musi mieć 'l5' => true. */
function fcMatchLast5(string $table, string $col, ?string $last5, string $alias = ''): array {
    $d = preg_replace('/\D/', '', (string)$last5);
    $p = $alias !== '' ? $alias . '.' : '';
    if (strlen($d) !== 5) return ['0=1', []];
    $plain = "SUBSTR({$p}`{$col}`, -5) = ?";
    $e = fcAutoEntry($table, $col);
    if (!$e || !fcAutoEnabled() || empty($e['l5'])) return [$plain, [$d]];
    fcEnsureIdx($e);
    $ids = fcBlindAll($d, fcCtx($e) . '#l5', 'digits');
    return ['(' . $p . '`' . $col . '_l5` IN (' . implode(',', array_fill(0, count($ids), '?')) . ") OR {$plain})", [...$ids, $d]];
}
