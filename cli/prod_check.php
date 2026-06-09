#!/usr/bin/env php
<?php
/**
 * cli/prod_check.php — Lista kontrolna przed wdrożeniem na produkcję
 *
 * Sprawdza konfigurację, bezpieczeństwo i dostępność usług.
 * NIE modyfikuje żadnych danych ani ustawień.
 *
 * Użycie:
 *   php cli/prod_check.php              — standardowy raport
 *   php cli/prod_check.php --strict     — ostrzeżenia = błędy (exit 1)
 *   php cli/prod_check.php --no-color   — bez kolorów ANSI
 *   php cli/prod_check.php --json       — output JSON
 *
 * Kody wyjścia:
 *   0 — OK (lub tylko ostrzeżenia bez --strict)
 *   1 — ostrzeżenia ze --strict
 *   2 — błędy krytyczne
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit("Tylko CLI.\n");
}

define('APP_CLI', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/prod_check_lib.php';

$opts   = getopt('', ['strict', 'no-color', 'json', 'help']);
$STRICT = isset($opts['strict']);
$JSON   = isset($opts['json']);

if (isset($opts['help'])) {
    echo <<<HELP

Lista kontrolna wdrożenia produkcyjnego — FEER System

Użycie:
  php cli/prod_check.php [--strict] [--no-color] [--json]

Opcje:
  --strict    Ostrzeżenia jako błędy (exit 1)
  --no-color  Bez kolorów ANSI
  --json      Wynik JSON
  --help      Ta pomoc

Kody wyjścia: 0=OK, 1=ostrzeżenia(strict), 2=błędy krytyczne

HELP;
    exit(0);
}

// ── ANSI ──────────────────────────────────────────────────────────────────────
$NC = isset($opts['no-color']) || !stream_isatty(STDOUT) || getenv('NO_COLOR') || $JSON;
function ansi(string $code, string $t): string { global $NC; return $NC ? $t : "\033[{$code}m{$t}\033[0m"; }
function pass(string $s): string { return ansi('32', "  ✓  ") . $s; }
function fail(string $s): string { return ansi('31', "  ✗  ") . $s; }
function warn(string $s): string { return ansi('33', "  ⚠  ") . $s; }
function skip(string $s): string { return ansi('90', "  –  ") . $s; }
function note(string $s): string { return ansi('90', "       → $s"); }
function h1(string $s):   string { return "\n" . ansi('1;34', "── $s ──") . "\n"; }

// ── Uruchom sprawdzenia ────────────────────────────────────────────────────────
$results = run_prod_checks($root);
$count   = prod_check_summary($results);

if ($JSON) {
    echo json_encode([
        'summary'   => $count,
        'checks'    => $results,
        'exit_code' => $count['fail'] > 0 ? 2 : ($STRICT && $count['warn'] > 0 ? 1 : 0),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($count['fail'] > 0 ? 2 : ($STRICT && $count['warn'] > 0 ? 1 : 0));
}

// ── Wydruk ────────────────────────────────────────────────────────────────────
$current_section = '';
foreach ($results as $r) {
    if ($r['section'] !== $current_section) {
        $current_section = $r['section'];
        echo h1($current_section);
    }
    $line = match($r['status']) {
        'ok'    => pass($r['label']),
        'warn'  => warn($r['label']),
        'fail'  => fail($r['label']),
        default => skip($r['label']),
    };
    echo $line;
    if ($r['detail']) {
        foreach (explode("\n", $r['detail']) as $dl) {
            echo "\n" . note($dl);
        }
    }
    echo "\n";
}

// ── Podsumowanie ──────────────────────────────────────────────────────────────
echo "\n" . ansi('1', "══ Podsumowanie ══") . "\n";
printf(
    "%s  %s  %s  %s\n\n",
    ansi('32', "  ✓ OK     : {$count['ok']}"),
    ansi('33', "  ⚠ Uwagi  : {$count['warn']}"),
    ansi('31', "  ✗ Błędy  : {$count['fail']}"),
    ansi('90', "  – Pominięto: {$count['skip']}")
);

if ($count['fail'] > 0) {
    echo ansi('1;31', "  ✗ SYSTEM NIE JEST GOTOWY DO WDROŻENIA") . "\n\n";
    exit(2);
} elseif ($count['warn'] > 0 && $STRICT) {
    echo ansi('1;33', "  ⚠ Tryb --strict: ostrzeżenia traktowane jako błędy") . "\n\n";
    exit(1);
} elseif ($count['warn'] > 0) {
    echo ansi('1;33', "  ⚠ Są ostrzeżenia — przejrzyj i zdecyduj") . "\n\n";
    exit(0);
} else {
    echo ansi('1;32', "  ✓ System gotowy do wdrożenia na produkcję") . "\n\n";
    exit(0);
}
