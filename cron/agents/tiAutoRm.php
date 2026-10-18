<?php
/**
 * cron/agents/tiAutoRm.php — automatyczne RM (rozliczenie miesięczne) na prośbę uczestnika.
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_auto_rm: okno 6:00–7:00, odstęp 23 h) albo ręcznie z konsoli:
 *   php cron/agents/tiAutoRm.php             # wystawia zaległe RM za poprzedni miesiąc (od dnia AutoRm::DAY)
 *   php cron/agents/tiAutoRm.php --dry-run   # komu zostałby wystawiony RM — nic nie zapisuje i nie wysyła
 * Reguły: modules/ti_dop/lib/AutoRm.php. Włączanie per uczestnik: Dokumenty rozliczeniowe › Nowy RM › „Automatyczne RM co miesiąc”.
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/karty30.php';
require_once $base_dir . '/includes/ti_payments.php';
require_once $base_dir . '/modules/ti_dop/lib/AutoRm.php';

$dry = false;
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . ' Start: ti_auto_rm' . ($dry ? ' (podgląd)' : '') . "\n";

$res = AutoRm::run($dry);
if (!$res['ym']) { echo $ts() . ' Przed ' . AutoRm::DAY . ". dniem miesiąca — nic do wystawienia.\n"; exit(0); }
foreach ($res['issued'] as $l) echo $ts() . '   ' . $l . "\n";
foreach ($res['errors'] as $l) echo $ts() . ' BŁĄD ' . $l . "\n";
echo $ts() . ' RM za ' . $res['ym'] . ': ' . ($dry ? 'do wystawienia ' : 'wystawiono ') . count($res['issued']) . ', pominięto ' . count($res['skipped']) . ', błędy ' . count($res['errors']) . "\n";
