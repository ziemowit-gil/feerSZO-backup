<?php
/**
 * Migracja numerów zgłoszeń Helpdesku do wzorca {PREFIX}-{YYYY}-{NNNNN} (modules/helpdeskMigrate).
 * php cli/helpdesk_migrate_numbers.php            — podgląd planu (nic nie zapisuje)
 * php cli/helpdesk_migrate_numbers.php --apply    — wykonuje migrację w jednej transakcji
 */
if (php_sapi_name() !== 'cli') die("Tylko CLI.\n");
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/modules/helpdeskMigrate/lib/helpdeskMigrate.php';

$apply = in_array('--apply', $argv, true);
$plan = hdMigratePlan();
echo count($plan) . " zgłoszeń do migracji" . ($apply ? '' : ' (podgląd; użyj --apply)') . "\n";
foreach (array_slice($plan, 0, 50) as $p) echo "  {$p['old']} → {$p['new']}\n";
if (count($plan) > 50) echo "  … i " . (count($plan) - 50) . " kolejnych\n";
if ($apply) { $r = hdMigrateRun(0); echo isset($r['error']) ? "BŁĄD: {$r['error']}\n" : "Zmigrowano: {$r['migrated']}\n"; }
