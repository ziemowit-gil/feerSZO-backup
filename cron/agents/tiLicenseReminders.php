<?php
/**
 * cron/agents/tiLicenseReminders.php — przypomnienia o terminie 7 dni w sprawach licencji AM5 (materiały) i AM7 (Office 365).
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_licencje_reminders: okno 7:00–8:00, odstęp 23 h — start o 7:00 bez dryfu)
 * albo ręcznie z konsoli:
 *   php cron/agents/tiLicenseReminders.php             # wysyłka (gdy włączone)
 *   php cron/agents/tiLicenseReminders.php --dry-run   # kto dostałby przypomnienie / co trafi do alertu — nic nie wysyła i nic nie zapisuje
 *   php cron/agents/tiLicenseReminders.php --force     # wysyłka mimo wyłączonego włącznika (ręczne uruchomienie przez admina)
 * Reguły (oświadczenia po terminie, podpis wniosku AM7, alert dla biura, najwyżej raz na 3 dni na osobę):
 * modules/ti_licencje_dydaktyczne/lib/LicenseDeadlineAgent.php.
 * Włącznik: pulpit Licencji (panel dydaktyka) › „Automatyczne przypomnienia” (org_setting ld_reminders_enabled; domyślnie wyłączone).
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_licencje_dydaktyczne/lib/LicenseDeadlineAgent.php';

$dry = false; $force = false;
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry-run')   $dry = true;
    elseif ($a === '--force') $force = true;
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . " Start: ti_licencje_reminders" . ($dry ? ' (podgląd — bez wysyłki)' : '') . "\n";

$res = (new LicenseDeadlineAgent(new LicenseManagementService()))->run($dry, $force || $dry);

if ($res['note'] !== '') { echo $ts() . ' ' . $res['note'] . "\n"; exit(0); }

foreach ($res['lines'] as $line) echo $ts() . '   ' . $line . "\n";
foreach ($res['errors'] as $err) echo $ts() . ' BŁĄD ' . $err . "\n";
echo $ts() . ' ' . ($dry ? 'Do wysłania' : 'Wysłano') . ": oświadczenia {$res['sent']['assignments']}, podpis AM7 {$res['sent']['sign']}, "
   . "sprawy w alercie dla biura {$res['sent']['office']}; pominięto (kontakt w ciągu " . LicenseDeadlineAgent::GAP_DAYS . " dni) {$res['skipped']}\n";
echo $ts() . " Koniec.\n";
