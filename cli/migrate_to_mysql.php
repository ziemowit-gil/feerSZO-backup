#!/usr/bin/env php
<?php
/**
 * cli/migrate_to_mysql.php — Migracja bazy SQLite → MySQL / MariaDB
 *
 * Tworzy schemat i kopiuje wszystkie dane z pliku SQLite do MySQL.
 * Bezpieczne do uruchomienia wielokrotnie (IF NOT EXISTS / INSERT IGNORE).
 *
 * Użycie:
 *   php cli/migrate_to_mysql.php --db=feer --user=root --pass=haslo
 *   php cli/migrate_to_mysql.php --db=feer --user=root --host=db.example.com --dry-run
 *
 * Opcje:
 *   --host=HOST      Host MySQL       (domyślnie: localhost)
 *   --port=PORT      Port MySQL       (domyślnie: 3306)
 *   --db=NAME        Nazwa bazy       (wymagane)
 *   --user=USER      Użytkownik       (wymagane)
 *   --pass=PASS      Hasło            (lub zmienna środowiskowa MYSQL_PWD)
 *   --sqlite=PATH    Plik SQLite      (domyślnie: umowy.db z config.php)
 *   --dry-run        Sprawdź i pokaż schemat bez kopiowania danych
 *   --force          Pomiń pytanie o potwierdzenie
 *   --drop           DROP TABLE IF EXISTS przed CREATE (uwaga: usuwa dane!)
 *   --skip-fk        Pomiń definicje FOREIGN KEY (dla MariaDB < 10.3)
 *   --charset=CHAR   Charset tabeli   (domyślnie: utf8mb4)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit("Tylko CLI.\n");
}

define('APP_CLI', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';

// ── ANSI ──────────────────────────────────────────────────────────────────────
$NO_COLOR = !stream_isatty(STDIN) || getenv('NO_COLOR');
function c(string $code, string $t): string { global $NO_COLOR; return $NO_COLOR ? $t : "\033[{$code}m{$t}\033[0m"; }
function ok(string $s):   string { return c('32', "✓ $s"); }
function err(string $s):  string { return c('31', "✗ $s"); }
function warn(string $s): string { return c('33', "⚠ $s"); }
function info(string $s): string { return c('36', "ℹ $s"); }
function bold(string $s): string { return c('1',  $s); }
function h1(string $s):   string { return "\n" . c('1;34', "── $s") . "\n"; }

// ── Opcje ─────────────────────────────────────────────────────────────────────
$opts = getopt('', [
    'host:', 'port:', 'db:', 'user:', 'pass:',
    'sqlite:', 'dry-run', 'force', 'drop', 'skip-fk', 'charset:', 'help',
]);

if (isset($opts['help'])) {
    echo <<<HELP

Migracja SQLite → MySQL / MariaDB

Użycie:
  php cli/migrate_to_mysql.php --db=feer --user=root --pass=haslo
  php cli/migrate_to_mysql.php --db=feer --user=root --dry-run

Opcje:
  --host=HOST      Host MySQL (domyślnie: localhost)
  --port=PORT      Port MySQL (domyślnie: 3306)
  --db=NAME        Nazwa bazy danych (wymagane)
  --user=USER      Użytkownik MySQL (wymagane)
  --pass=PASS      Hasło (lub zmienna środowiskowa MYSQL_PWD)
  --sqlite=PATH    Ścieżka do pliku SQLite (domyślnie: z config.php)
  --dry-run        Pokaż schemat bez importu danych
  --force          Pomiń pytanie o potwierdzenie
  --drop           DROP TABLE IF EXISTS przed CREATE (usuwa istniejące dane!)
  --skip-fk        Pomiń definicje FOREIGN KEY
  --charset=CHAR   Charset (domyślnie: utf8mb4)
  --help           Ta pomoc

Po migracji:
  1. Zaktualizuj config.local.php: zmień DB_TYPE=mysql i podaj dane połączenia
  2. Uruchom php cli/prod_check.php aby zweryfikować środowisko

HELP;
    exit(0);
}

$mysql_host   = $opts['host']    ?? 'localhost';
$mysql_port   = (int)($opts['port'] ?? 3306);
$mysql_db     = $opts['db']      ?? '';
$mysql_user   = $opts['user']    ?? '';
$mysql_pass   = $opts['pass']    ?? getenv('MYSQL_PWD') ?: '';
$sqlite_path  = $opts['sqlite']  ?? DB_PATH;
$charset      = $opts['charset'] ?? 'utf8mb4';
$dry_run      = isset($opts['dry-run']);
$force        = isset($opts['force']);
$drop_tables  = isset($opts['drop']);
$skip_fk      = isset($opts['skip-fk']);

if (!$mysql_db || !$mysql_user) {
    echo err('Podaj --db=NAZWA i --user=UZYTKOWNIK') . "\n";
    echo "  php cli/migrate_to_mysql.php --help\n";
    exit(1);
}

if (!file_exists($sqlite_path)) {
    echo err("Plik SQLite nie istnieje: {$sqlite_path}") . "\n";
    exit(1);
}

// ── Połączenie SQLite ─────────────────────────────────────────────────────────
try {
    $sqlite = new PDO('sqlite:' . $sqlite_path);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $sqlite->exec('PRAGMA foreign_keys=OFF; PRAGMA journal_mode=WAL;');
} catch (PDOException $e) {
    echo err("Nie można otworzyć SQLite: " . $e->getMessage()) . "\n";
    exit(1);
}

// ── Połączenie MySQL ──────────────────────────────────────────────────────────
try {
    $dsn   = "mysql:host={$mysql_host};port={$mysql_port};dbname={$mysql_db};charset={$charset}";
    $mysql = new PDO($dsn, $mysql_user, $mysql_pass, [
        PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
        PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo err("Nie można połączyć z MySQL ({$mysql_user}@{$mysql_host}/{$mysql_db}): " . $e->getMessage()) . "\n";
    exit(1);
}

// ── Pobierz wersję MySQL ──────────────────────────────────────────────────────
$mysql_ver = $mysql->query('SELECT VERSION()')->fetchColumn();
echo ok("SQLite: " . basename($sqlite_path) . " (" . round(filesize($sqlite_path) / 1048576, 1) . " MB)") . "\n";
echo ok("MySQL: {$mysql_user}@{$mysql_host}/{$mysql_db} — {$mysql_ver}") . "\n";

// MySQL 8.0+ supports TEXT DEFAULT values; warn for older
$mysql_major = (int)explode('.', $mysql_ver)[0];
$mysql_minor = (int)(explode('.', $mysql_ver)[1] ?? 0);
$mysql_patch = (int)trim(explode('.', $mysql_ver)[2] ?? '0', '-abcdefghijklmnopqrstuvwxyz');
$supports_text_default = ($mysql_major > 8) || ($mysql_major === 8 && $mysql_minor === 0 && $mysql_patch >= 13)
                       || str_contains(strtolower($mysql_ver), 'mariadb');

if (!$supports_text_default) {
    echo warn("MySQL < 8.0.13: TEXT DEFAULT może nie być obsługiwany. Upgrade MySQL lub użyj MariaDB.") . "\n";
}

// ── Lista tabel SQLite ────────────────────────────────────────────────────────
$tables = $sqlite->query(
    "SELECT name FROM sqlite_master
     WHERE type='table' AND name NOT LIKE 'sqlite_%'
     ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

echo info(count($tables) . " tabel do migracji") . "\n";

if ($dry_run) {
    echo "\n" . bold("── Tryb dry-run: generowanie schematu MySQL ──") . "\n\n";
    foreach ($tables as $table) {
        echo "-- Tabela: {$table}\n";
        echo build_mysql_create($sqlite, $table, $charset, $skip_fk) . "\n\n";
    }
    echo info("Dry-run zakończony. Żadne dane nie zostały zmodyfikowane.") . "\n";
    exit(0);
}

// ── Potwierdzenie ─────────────────────────────────────────────────────────────
if (!$force) {
    echo "\n" . c('1;33', "UWAGA: Dane z SQLite zostaną skopiowane do MySQL.") . "\n";
    if ($drop_tables) {
        echo c('1;31', "UWAGA: --drop aktywny — istniejące tabele MySQL zostaną usunięte!") . "\n";
    }
    echo "Wpisz  tak  i naciśnij Enter, aby kontynuować: ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'tak') {
        echo "Anulowano.\n"; exit(0);
    }
}

// ── Migracja ──────────────────────────────────────────────────────────────────
$mysql->exec('SET FOREIGN_KEY_CHECKS=0');

$stats = ['created' => 0, 'existed' => 0, 'rows' => 0, 'errors' => []];
$BATCH = 500;

foreach ($tables as $table) {
    $create_sql = build_mysql_create($sqlite, $table, $charset, $skip_fk);

    // DROP?
    if ($drop_tables) {
        $mysql->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    // CREATE
    try {
        $mysql->exec($create_sql);
        $stats['created']++;
        echo ok("  CREATE `{$table}`") . "\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            $stats['existed']++;
            echo info("  SKIP   `{$table}` (już istnieje)") . "\n";
        } else {
            $stats['errors'][] = "CREATE `{$table}`: " . $e->getMessage();
            echo err("  FAIL   `{$table}`: " . $e->getMessage()) . "\n";
            continue;
        }
    }

    // Indexes
    $idx_sqls = build_mysql_indexes($sqlite, $table);
    foreach ($idx_sqls as $idx_sql) {
        try { $mysql->exec($idx_sql); } catch (PDOException $e) { /* ignore duplicate */ }
    }

    // Copy data
    $total_rows = (int)$sqlite->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($total_rows === 0) continue;

    $cols = get_column_names($sqlite, $table);
    $col_list = '`' . implode('`, `', $cols) . '`';
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $insert_sql = "INSERT IGNORE INTO `{$table}` ({$col_list}) VALUES ({$placeholders})";
    $stmt = $mysql->prepare($insert_sql);

    $offset  = 0;
    $copied  = 0;
    while (true) {
        $rows = $sqlite->query("SELECT * FROM `{$table}` LIMIT {$BATCH} OFFSET {$offset}")->fetchAll();
        if (!$rows) break;
        $mysql->beginTransaction();
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
            $copied++;
        }
        $mysql->commit();
        $offset += $BATCH;
        echo "  \033[F\033[K" . ok("  DATA   `{$table}` — " . number_format($copied) . " / " . number_format($total_rows) . " wierszy") . "\n";
    }
    $stats['rows'] += $copied;
}

$mysql->exec('SET FOREIGN_KEY_CHECKS=1');

// ── Raport ────────────────────────────────────────────────────────────────────
echo "\n" . bold("══ Migracja zakończona ══") . "\n";
echo "  Tabele utworzone : {$stats['created']}\n";
echo "  Tabele istniejące: {$stats['existed']}\n";
echo "  Wiersze skopiowane: " . number_format($stats['rows']) . "\n";
if ($stats['errors']) {
    echo "\n" . err(count($stats['errors']) . " błędów:") . "\n";
    foreach ($stats['errors'] as $e) echo "  - $e\n";
}
echo "\n" . ok("Następny krok: zaktualizuj config.local.php (DB_TYPE=mysql)") . "\n";
echo "  php cli/prod_check.php — weryfikacja środowiska produkcyjnego\n\n";

// ══════════════════════════════════════════════════════════════════════════════
//  FUNKCJE POMOCNICZE
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Buduje MySQL CREATE TABLE na podstawie SQLite PRAGMA table_info / foreign_key_list.
 */
function build_mysql_create(PDO $sqlite, string $table, string $charset, bool $skip_fk): string
{
    $cols_info = $sqlite->query("PRAGMA table_info(`{$table}`)")->fetchAll();
    $fk_list   = $sqlite->query("PRAGMA foreign_key_list(`{$table}`)")->fetchAll();

    $col_defs = [];
    $pk_cols  = [];

    // Sprawdź czy jest single-INTEGER-PK (→ AUTO_INCREMENT)
    $pk_count = count(array_filter($cols_info, fn($c) => (int)$c['pk'] > 0));
    $single_int_pk = null;
    if ($pk_count === 1) {
        foreach ($cols_info as $c) {
            if ((int)$c['pk'] === 1 && str_contains(strtoupper($c['type']), 'INT')) {
                $single_int_pk = $c['name'];
            }
        }
    }

    foreach ($cols_info as $col) {
        $name    = $col['name'];
        $type    = $col['type'];
        $notnull = (bool)(int)$col['notnull'];
        $dflt    = $col['dflt_value'];    // może być null, '0', "'text'", 'CURRENT_TIMESTAMP'
        $pk      = (int)$col['pk'];

        if ($name === $single_int_pk) {
            $col_defs[] = "`{$name}` INT NOT NULL AUTO_INCREMENT";
            continue;
        }

        $mysql_type = sqlite_type_to_mysql($type);
        $def = "`{$name}` {$mysql_type}";

        if ($notnull) $def .= ' NOT NULL';

        if ($dflt !== null) {
            $def .= ' DEFAULT ' . sqlite_default_to_mysql($dflt);
        } elseif (!$notnull) {
            $def .= ' DEFAULT NULL';
        }

        if ($pk > 0) $pk_cols[$pk] = $name;
        $col_defs[] = $def;
    }

    // PRIMARY KEY
    if ($single_int_pk) {
        $col_defs[] = "PRIMARY KEY (`{$single_int_pk}`)";
    } elseif ($pk_cols) {
        ksort($pk_cols);
        $col_defs[] = 'PRIMARY KEY (`' . implode('`, `', $pk_cols) . '`)';
    }

    // FOREIGN KEYS
    if (!$skip_fk && $fk_list) {
        $fk_groups = [];
        foreach ($fk_list as $fk) {
            $fk_groups[(int)$fk['id']][] = $fk;
        }
        foreach ($fk_groups as $fk_rows) {
            usort($fk_rows, fn($a, $b) => (int)$a['seq'] <=> (int)$b['seq']);
            $from_cols = implode('`, `', array_column($fk_rows, 'from'));
            $to_cols   = implode('`, `', array_column($fk_rows, 'to'));
            $ref_table = $fk_rows[0]['table'];
            $on_del    = strtoupper($fk_rows[0]['on_delete'] ?? 'NO ACTION');
            $on_upd    = strtoupper($fk_rows[0]['on_update'] ?? 'NO ACTION');

            $fk_name = substr('fk_' . $table . '_' . $fk_rows[0]['from'], 0, 64);
            $fk_def  = "CONSTRAINT `{$fk_name}` FOREIGN KEY (`{$from_cols}`) REFERENCES `{$ref_table}` (`{$to_cols}`)";
            if ($on_del !== 'NO ACTION') $fk_def .= " ON DELETE {$on_del}";
            if ($on_upd !== 'NO ACTION') $fk_def .= " ON UPDATE {$on_upd}";
            $col_defs[] = $fk_def;
        }
    }

    $body = "  " . implode(",\n  ", $col_defs);
    return "CREATE TABLE IF NOT EXISTS `{$table}` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci;";
}

/**
 * Buduje MySQL CREATE INDEX na podstawie PRAGMA index_list.
 */
function build_mysql_indexes(PDO $sqlite, string $table): array
{
    $sqls     = [];
    $idx_list = $sqlite->query("PRAGMA index_list(`{$table}`)")->fetchAll();
    foreach ($idx_list as $idx) {
        if ($idx['origin'] === 'pk') continue; // PRIMARY KEY już w CREATE TABLE
        $idx_info = $sqlite->query("PRAGMA index_info(`{$idx['name']}`)")->fetchAll();
        if (!$idx_info) continue;
        $cols   = '`' . implode('`, `', array_column($idx_info, 'name')) . '`';
        $unique = $idx['unique'] ? 'UNIQUE ' : '';
        $idx_name = substr($idx['name'], 0, 64);
        $sqls[] = "CREATE {$unique}INDEX IF NOT EXISTS `{$idx_name}` ON `{$table}` ({$cols});";
    }
    return $sqls;
}

/**
 * Zwraca listę nazw kolumn tabeli.
 */
function get_column_names(PDO $sqlite, string $table): array
{
    return array_column(
        $sqlite->query("PRAGMA table_info(`{$table}`)")->fetchAll(),
        'name'
    );
}

/**
 * Mapuje typ SQLite → MySQL.
 */
function sqlite_type_to_mysql(string $type): string
{
    $upper = strtoupper(trim($type));
    $base  = preg_replace('/\(.*\)/', '', $upper); // bez rozmiaru

    // Zachowaj rozmiar dla VARCHAR/CHAR
    if (str_contains($base, 'VARCHAR') || str_contains($base, 'CHAR')) return $type ?: 'VARCHAR(255)';
    if (str_contains($base, 'DECIMAL') || str_contains($base, 'NUMERIC')) return $type ?: 'DECIMAL(10,4)';

    return match(true) {
        str_contains($base, 'BIGINT')    => 'BIGINT',
        str_contains($base, 'TINYINT')   => 'TINYINT(1)',
        str_contains($base, 'SMALLINT')  => 'SMALLINT',
        str_contains($base, 'MEDIUMINT') => 'MEDIUMINT',
        str_contains($base, 'INT')       => 'INT',
        str_contains($base, 'BOOL')      => 'TINYINT(1)',
        str_contains($base, 'TEXT')      => 'LONGTEXT',
        str_contains($base, 'BLOB')      => 'LONGBLOB',
        str_contains($base, 'CLOB')      => 'LONGTEXT',
        str_contains($base, 'REAL')      => 'DOUBLE',
        str_contains($base, 'FLOAT')     => 'FLOAT',
        str_contains($base, 'DOUBLE')    => 'DOUBLE',
        str_contains($base, 'DATETIME')  => 'DATETIME',
        str_contains($base, 'DATE')      => 'DATE',
        str_contains($base, 'TIME')      => 'TIME',
        str_contains($base, 'JSON')      => 'JSON',
        $base === ''                     => 'TEXT',
        default                          => 'TEXT',
    };
}

/**
 * Konwertuje wartość domyślną SQLite → MySQL.
 */
function sqlite_default_to_mysql(?string $dflt): string
{
    if ($dflt === null) return 'NULL';
    $d = trim($dflt);

    // CURRENT_TIMESTAMP — identyczne w MySQL
    if (strtoupper($d) === 'CURRENT_TIMESTAMP') return 'CURRENT_TIMESTAMP';

    // datetime('now') w różnych wariantach
    if (preg_match("/^[\(]?datetime\('now'.*\)[\)]?$/i", $d)) return 'CURRENT_TIMESTAMP';

    // NOW() → CURRENT_TIMESTAMP
    if (strtoupper($d) === 'NOW()') return 'CURRENT_TIMESTAMP';

    // Quoted string: 'value' lub '' lub "value"
    if (preg_match("/^'(.*)'$/s", $d, $m)) return "'" . str_replace("'", "''", $m[1]) . "'";
    if (preg_match('/^"(.*)"$/s', $d, $m)) return "'" . str_replace("'", "''", $m[1]) . "'";

    // NULL string
    if (strtoupper($d) === 'NULL') return 'NULL';

    // Liczby
    if (is_numeric($d)) return $d;

    // Fallback: owiń w cudzysłów
    return "'" . addslashes($d) . "'";
}
