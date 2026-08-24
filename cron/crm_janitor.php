<?php
/**
 * Skrypt cron: bot sprzątający kartotekę CRM.
 *
 * Uruchamiany przez cron/dispatcher.php (agent 'crm_janitor'), raz na dobę
 * w nocy. Można też wprost:
 *   php cron/crm_janitor.php            # przebieg właściwy
 *   php cron/crm_janitor.php --dry-run  # tylko policz, niczego nie zapisuj
 *
 * Poprawki kosmetyczne idą przez CrmManager::updateContact(), więc w historii
 * zmian kartoteki podpisują się jako „proces automatyczny" — bez sesji nie ma
 * zalogowanego użytkownika i to jest właściwa informacja, a nie brak danych.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/auth.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_janitor.php';

$dry = in_array('--dry-run', $argv ?? [], true);

echo '[' . date('Y-m-d H:i:s') . '] Start: crm_janitor' . ($dry ? ' (dry-run)' : '') . "\n";

if (function_exists('org_setting') && org_setting('crm_enabled') === '0') {
    echo "  Moduł CRM wyłączony — koniec.\n";
    exit(0);
}

try {
    $r = crm_janitor_run($dry);
} catch (\Throwable $e) {
    fwrite(STDERR, '  BŁĄD: ' . $e->getMessage() . "\n");
    exit(2);
}

printf("  poprawek kosmetycznych: %d\n", $r['fixed']);
printf("  zgłoszeń do decyzji:    %d\n", $r['found']);
foreach ($r['per_rule'] as $rule => $n) {
    $cfg = crm_janitor_rules()[$rule] ?? [];
    printf("    · %-16s %-40s %d\n", $rule, $cfg['label'] ?? '', $n);
}

if ($dry && $r['samples']) {
    echo "  przykłady poprawek, które by poszły:\n";
    foreach ($r['samples'] as $s) {
        foreach ($s['fix'] as $field => [$before, $after]) {
            printf("    #%d %s: „%s” → „%s”\n", $s['id'], $field, $before, $after);
        }
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_janitor\n";
exit(0);
