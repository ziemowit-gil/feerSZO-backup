<?php
/**
 * cron/agents/usPrzelewyReminders.php — przypomnienia o terminach zobowiązań podatkowych (moduł Przelewy do US).
 *
 * Uruchamiany przez cron/dispatcher.php (agent us_przelewy_reminders: okno 7:00–8:00, odstęp 23 h) albo ręcznie:
 *   php cron/agents/usPrzelewyReminders.php            # wysyłka (gdy w ustawieniach modułu są adresy e-mail)
 *   php cron/agents/usPrzelewyReminders.php --dry-run  # co trafiłoby do przypomnienia — nic nie wysyła i nic nie zapisuje
 * Reguły (raz przed terminem, raz po terminie, jeden zbiorczy e-mail na adres): uspPrzypomnienia()
 * w modules/us_przelewy/logic/usPrzelewy.php. Puste adresy = wyłączone.
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/us_przelewy/logic/usPrzelewy.php';

$dry = false;
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . " Start: us_przelewy_reminders" . ($dry ? ' (podgląd — bez wysyłki)' : '') . "\n";

$res = uspPrzypomnienia($dry);
if ($res['note'] !== '') { echo $ts() . ' ' . $res['note'] . "\n"; exit(0); }
foreach ($res['lines'] as $line) echo $ts() . '   ' . $line . "\n";
echo $ts() . ' ' . ($dry ? 'Do przypomnienia: ' . count($res['lines']) : 'Wysłano e-maili: ' . $res['sent']) . "\n";
echo $ts() . " Koniec.\n";
