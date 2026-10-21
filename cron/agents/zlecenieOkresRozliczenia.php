<?php
/**
 * cron/agents/zlecenieOkresRozliczenia.php — zlecenia dłuższe niż miesiąc: status „okres do rozliczenia” i przypomnienia
 * o zamknięciu miesiąca w saldzie rozliczeń.
 *
 * Uruchamiany przez cron/dispatcher.php (agent zlecenie_okres_rozliczenia: okno 6:00–7:00, odstęp 23 h) albo ręcznie:
 *   php cron/agents/zlecenieOkresRozliczenia.php            # uzgodnienie statusów + przypomnienia (w dni z ustawienia)
 *   php cron/agents/zlecenieOkresRozliczenia.php --dry-run  # co by się zmieniło i do kogo poszłoby przypomnienie — nic nie zapisuje
 * Reguły: zl_okres_sync_all() i zl_okres_przypomnienia() w includes/zlecenie_rachunki.php.
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/zlecenie_rachunki.php';

$dry = false;
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . " Start: zlecenie_okres_rozliczenia" . ($dry ? ' (podgląd — bez zmian i wysyłki)' : '') . "\n";

$changes = zl_okres_sync_all($dry);
echo $ts() . ' Statusy: ' . (count($changes) ? count($changes) . ' zmian' : 'bez zmian') . "\n";
foreach ($changes as $line) echo $ts() . '   ' . $line . "\n";

$res = zl_okres_przypomnienia($dry);
if ($res['note'] !== '') echo $ts() . ' Przypomnienia: ' . $res['note'] . "\n";
foreach ($res['lines'] as $line) echo $ts() . '   ' . $line . "\n";
if ($res['note'] === '') echo $ts() . ' ' . ($dry ? 'Do przypomnienia: ' . count($res['lines']) : 'Dodano do kolejki e-maili: ' . $res['sent']) . "\n";
echo $ts() . " Koniec.\n";
