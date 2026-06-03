#!/usr/bin/env php
<?php
/**
 * cli/cleanAll.php — Usuwa zawartość WSZYSTKICH tabel w bazie danych.
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  UWAGA: operacja NIEODWRACALNA — kasuje 100% danych!        ║
 * ║  Zachowane zostaje tylko połączenie z bazą (plik .db).      ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Użycie:
 *   php cli/cleanAll.php               — interaktywne potwierdzenie
 *   php cli/cleanAll.php --yes         — pomiń pytanie (np. w skryptach)
 *   php cli/cleanAll.php --counts      — tylko wyświetl liczby, nic nie rób
 *   php cli/cleanAll.php --tenant=SLUG — wyczyść bazę wskazanego tenanta
 *   php cli/cleanAll.php --skip=tabela1,tabela2  — pomiń wybrane tabele
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Ten skrypt działa tylko z linii poleceń.\n");
}

// ── Opcje ─────────────────────────────────────────────────────────────────────
$opts   = getopt('', ['yes', 'counts', 'tenant:', 'skip:']);
$noask  = isset($opts['yes']);
$counts = isset($opts['counts']);
$tenant = $opts['tenant'] ?? null;
$skip   = array_filter(array_map('trim', explode(',', $opts['skip'] ?? '')));

// ── Środowisko ────────────────────────────────────────────────────────────────
if (!isset($_SERVER['HTTP_HOST']))     $_SERVER['HTTP_HOST']     = 'localhost';
if (!isset($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
if (!isset($_SERVER['HTTPS']))         $_SERVER['HTTPS']         = 'off';

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';

// ── Kolory ANSI ───────────────────────────────────────────────────────────────
$tty = stream_isatty(STDOUT);
function clr(string $text, string $c): string {
    global $tty;
    if (!$tty) return $text;
    $codes = ['red'=>'31','yellow'=>'33','green'=>'32','cyan'=>'36','bold'=>'1','dim'=>'2','reset'=>'0'];
    return "\033[" . ($codes[$c] ?? '0') . "m{$text}\033[0m";
}
function ok(string $s):  void { echo clr('  ✓ ', 'green')  . $s . "\n"; }
function err(string $s): void { echo clr('  ✗ ', 'red')    . $s . "\n"; }
function inf(string $s): void { echo clr('  · ', 'dim')    . $s . "\n"; }

// ── Wybór bazy ────────────────────────────────────────────────────────────────
if ($tenant !== null) {
    $slug    = preg_replace('/[^a-z0-9_-]/', '', strtolower($tenant));
    $db_path = $root . '/tenants/' . $slug . '/umowy.db';
    if (!is_file($db_path)) {
        fwrite(STDERR, clr("Błąd: ", 'red') . "nie znaleziono bazy tenanta '$slug' ($db_path)\n");
        exit(1);
    }
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
    $label = "tenant '$slug' — $db_path";
} else {
    $pdo   = db();
    $label = defined('DB_PATH') ? DB_PATH : 'główna baza';
}

// ── Pobierz wszystkie tabele użytkownika (nie wewnętrzne sqlite_*) ────────────
$all_tables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

if (empty($all_tables)) {
    echo clr("Baza nie zawiera żadnych tabel.\n", 'yellow');
    exit(0);
}

// Zastosuj pominięcia
$tables = array_values(array_filter($all_tables, fn($t) => !in_array($t, $skip, true)));
$skipped = array_values(array_diff($all_tables, $tables));

// ── Nagłówek ──────────────────────────────────────────────────────────────────
echo "\n";
echo clr("╔══════════════════════════════════════════════════════════════╗\n", 'bold');
echo clr("║   cleanAll.php — usuwanie zawartości WSZYSTKICH tabel        ║\n", 'bold');
echo clr("╚══════════════════════════════════════════════════════════════╝\n", 'bold');
echo "\n";
echo clr("  Baza:   ", 'bold') . $label . "\n";
echo clr("  Tabele: ", 'bold') . count($tables) . " do wyczyszczenia";
if ($skipped) echo " + " . count($skipped) . " pominiętych (--skip)";
echo "\n\n";

// ── Policz wiersze ────────────────────────────────────────────────────────────
$row_counts = [];
$total = 0;
foreach ($tables as $t) {
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM " . $pdo->quote($t))->fetchColumn();
    } catch (\Throwable $e) {
        $n = -1;
    }
    $row_counts[$t] = $n;
    if ($n > 0) $total += $n;
}

// Wydruk w kolumnach po 3
$non_empty = array_filter($row_counts, fn($n) => $n > 0);
$empty     = array_filter($row_counts, fn($n) => $n === 0);

if ($non_empty) {
    echo clr("  Tabele z danymi (" . count($non_empty) . "):\n", 'yellow');
    $i = 0;
    foreach ($non_empty as $t => $n) {
        $padded = sprintf("    %-42s %6d wierszy", $t, $n);
        echo clr($padded . "\n", $n > 1000 ? 'red' : ($n > 100 ? 'yellow' : 'reset'));
        $i++;
    }
    echo "\n";
}

if ($empty) {
    echo clr("  Tabele puste (" . count($empty) . "): ", 'dim');
    echo implode(', ', array_keys($empty)) . "\n\n";
}

if ($skipped) {
    echo clr("  Pominięte (--skip): ", 'cyan') . implode(', ', $skipped) . "\n\n";
}

$total_str = number_format($total, 0, ',', ' ');
echo clr("  Łącznie do usunięcia: ", 'bold') . clr($total_str . " wierszy", $total > 0 ? 'red' : 'green') . "\n\n";

if ($counts) {
    echo clr("  (tryb --counts — nic nie usunięto)\n\n", 'dim');
    exit(0);
}

if ($total === 0 && !$noask) {
    echo clr("  Baza jest już pusta — nic do zrobienia.\n\n", 'green');
    exit(0);
}

// ── Podwójne potwierdzenie ────────────────────────────────────────────────────
if (!$noask) {
    echo clr("  ⚠  UWAGA: ta operacja jest NIEODWRACALNA.\n", 'red');
    echo clr("     Usunięte zostaną WSZYSTKIE dane: umowy, użytkownicy, ustawienia, logi.\n\n", 'red');

    echo "  Podaj dokładnie  " . clr("USUŃ WSZYSTKO", 'bold') . "  aby kontynuować: ";
    $line1 = trim(fgets(STDIN));
    if ($line1 !== 'USUŃ WSZYSTKO') {
        echo clr("\n  Anulowano.\n\n", 'yellow');
        exit(1);
    }

    echo "\n  Potwierdź jeszcze raz — wpisz  " . clr("TAK", 'bold') . ": ";
    $line2 = trim(fgets(STDIN));
    if ($line2 !== 'TAK') {
        echo clr("\n  Anulowano.\n\n", 'yellow');
        exit(1);
    }
    echo "\n";
}

// ── Czyszczenie ───────────────────────────────────────────────────────────────
echo clr("  Czyszczenie...\n\n", 'cyan');

$pdo->exec("PRAGMA foreign_keys = OFF");
$pdo->beginTransaction();

$ok_count  = 0;
$err_count = 0;
$del_total = 0;

foreach ($tables as $t) {
    try {
        $deleted = $pdo->exec("DELETE FROM " . $pdo->quote($t));
        $n       = $row_counts[$t] ?? 0;
        if ($n > 0) {
            ok(sprintf("%-42s  usunięto %d wierszy", $t, $n));
            $del_total += max(0, $n);
        } else {
            inf(sprintf("%-42s  (pusta)", $t));
        }
        $ok_count++;
    } catch (\Throwable $e) {
        err(sprintf("%-42s  BŁĄD: %s", $t, $e->getMessage()));
        $err_count++;
    }
}

// Resetuj sekwencje auto-increment
try {
    $pdo->exec("DELETE FROM sqlite_sequence");
    ok("sqlite_sequence                              sekwencje zresetowane");
} catch (\Throwable $e) {
    // sqlite_sequence nie istnieje jeśli nie ma tabel z AUTOINCREMENT
}

if ($err_count > 0) {
    $pdo->rollBack();
    echo "\n";
    err("Wystąpiły błędy — transakcja wycofana. Żadne dane nie zostały usunięte.");
    echo "\n";
    exit(1);
}

$pdo->commit();
$pdo->exec("PRAGMA foreign_keys = ON");

// VACUUM — odzyskaj miejsce na dysku
echo "\n";
echo clr("  Optymalizacja bazy (VACUUM)...", 'dim');
try {
    $pdo->exec("VACUUM");
    echo clr(" gotowe\n", 'green');
} catch (\Throwable $e) {
    echo clr(" pominięto (" . $e->getMessage() . ")\n", 'yellow');
}

// ── Podsumowanie ──────────────────────────────────────────────────────────────
echo "\n";
echo clr("╔══════════════════════════════════════════════════════════════╗\n", 'green');
echo clr("║  Gotowe                                                      ║\n", 'green');
echo clr("╚══════════════════════════════════════════════════════════════╝\n", 'green');
echo "\n";
echo clr("  Wyczyszczono: ", 'bold') . $ok_count . " tabel\n";
echo clr("  Usunięto:     ", 'bold') . number_format($del_total, 0, ',', ' ') . " wierszy\n";
if ($skipped) {
    echo clr("  Pominięto:    ", 'bold') . count($skipped) . " tabel (" . implode(', ', $skipped) . ")\n";
}
echo "\n";
