#!/usr/bin/env php
<?php
/**
 * CLI: czyszczenie bazy danych.
 * Przeznaczony dla instalacji FEER (bez tenanta).
 *
 * Użycie:
 *   php cli/clean_db.php [opcje]
 *
 * Opcje:
 *   --full    Usuwa też użytkowników (poza serwis@local)
 *   --yes     Pomija interaktywne potwierdzenie
 *   --counts  Wyświetla tylko liczby rekordów, nie czyści
 *   --tenant=SLUG  Czyści bazę wybranego tenanta (zamiast głównej FEER)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Ten skrypt działa tylko z linii poleceń.\n");
}

$opts   = getopt('', ['full', 'yes', 'counts', 'tenant:']);
$full   = isset($opts['full']);
$noask  = isset($opts['yes']);
$counts = isset($opts['counts']);
$tenant = $opts['tenant'] ?? null;

// Minimalne środowisko żeby config.php nie zgłaszał błędów PHP CLI
if (!isset($_SERVER['HTTP_HOST']))     $_SERVER['HTTP_HOST']     = 'localhost';
if (!isset($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
if (!isset($_SERVER['HTTPS']))         $_SERVER['HTTPS']         = 'off';

$root = dirname(__DIR__);

// Ładuj config/db
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/db_clean.php';

if ($tenant !== null) {
    // Tryb tenanta
    $slug    = preg_replace('/[^a-z0-9]/', '', strtolower($tenant));
    $db_path = $root . '/tenants/' . $slug . '/umowy.db';
    if (!is_file($db_path)) {
        fwrite(STDERR, "Błąd: nie znaleziono bazy tenanta '$slug' ($db_path)\n");
        exit(1);
    }
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
    $label = "tenant '$slug' ($db_path)";
} else {
    // Tryb FEER (główna baza)
    $pdo   = db();
    $label = "FEER (" . DB_PATH . ")";
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║          Czyszczenie bazy danych — Rejestr Umów      ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";
echo "Baza:  $label\n";
echo "Tryb:  " . ($full ? "PEŁNE czyszczenie (wraz z użytkownikami)" : "dane umów, dokumentów i zadań") . "\n\n";

$c = db_clean_counts($pdo);
$total_rows = array_sum(array_filter($c, fn($v) => $v !== null));

// Grupowanie dla czytelnego wydruku
$groups = [
    'Obieg dokumentów' => ['contract_supervisors','contract_letters','certificate_requests',
                            'contract_termination_requests','contract_edit_requests',
                            'contract_amendments','contract_audit_log','contract_approvals'],
    'Umowy'            => ['umowy_zlecenie','umowy_uslugi','umowy_wolontariat',
                            'umowy_dzielo','umowy_praca','umowy_inne'],
    'Wiadomości'       => ['messages'],
    'Onboarding'       => ['onboarding_messages','onboarding_volunteers'],
    'Zadania'          => ['task_notification_log','task_history','task_list_time','task_files',
                            'task_subtasks','task_comments','task_task_tags','task_assignments',
                            'tasks','task_workspace_members','task_notification_prefs',
                            'task_lists','task_tags','task_workspaces'],
    'Inne'             => ['sms_login_tokens','m365_standalone_accounts'],
];
if ($full) $groups['Użytkownicy'] = ['users'];

foreach ($groups as $grp => $tables) {
    $grp_total = 0;
    $lines = [];
    foreach ($tables as $t) {
        $n = $c[$t] ?? null;
        if ($n === null) continue;
        $grp_total += $n;
        if ($n > 0 || $counts) {
            $lines[] = sprintf("  %-45s %5d", $t, $n);
        }
    }
    if ($grp_total > 0 || $counts) {
        echo "── $grp (" . ($grp_total > 0 ? "$grp_total wierszy" : "pusta") . ")\n";
        echo implode("\n", $lines) . ($lines ? "\n" : "");
    }
}

echo "\nŁącznie do usunięcia: $total_rows wierszy\n";

if ($counts) { echo "\n(tryb --counts, nic nie usunięto)\n"; exit(0); }
if ($total_rows === 0 && !$full) { echo "Baza jest pusta — nic do zrobienia.\n"; exit(0); }

echo "\n";
if (!$noask) {
    echo "Wpisz  WYCZYŚĆ  aby potwierdzić (lub cokolwiek innego aby anulować): ";
    $line = trim(fgets(STDIN));
    if ($line !== 'WYCZYŚĆ') {
        echo "Anulowano.\n";
        exit(1);
    }
}

echo "\nCzyszczenie...\n";
$results = db_clean($pdo, $full, null);

$ok = $skip = $err = 0;
foreach ($results as $r) {
    if ($r['status'] === 'ok' && $r['deleted'] > 0) {
        echo "  ✓ {$r['table']} — {$r['deleted']} wierszy\n";
        $ok++;
    } elseif ($r['status'] === 'err') {
        echo "  ✗ {$r['table']} — BŁĄD: {$r['error']}\n";
        $err++;
    } else {
        $skip++;
    }
}

echo "\nGotowe. Wyczyszczono: $ok tabel";
if ($skip)  echo ", pominięto: $skip";
if ($err)   echo ", błędy: $err";
echo ".\n";
exit($err > 0 ? 1 : 0);
