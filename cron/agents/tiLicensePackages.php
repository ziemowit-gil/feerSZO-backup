<?php
/**
 * cron/agents/tiLicensePackages.php — pakiety poprawionych druków AM po zatwierdzonych korektach AMSK.
 *
 * Po zatwierdzeniu AMSK (podpis kierownika potwierdzony w SZO) pakiet powstaje automatycznie po LD_PACKAGE_DELAY_MIN minutach:
 * generowanie druków sprawy z poprawionych danych, unieważnienie poprzednich, ZIP z hasłem do osoby i kierownika (hasło SMS-em).
 * Seria korekt jednego celu → jeden pakiet (dla najnowszej). Uruchamiany przez cron/dispatcher.php (agent ti_licencje_packages, co 2 min)
 * albo ręcznie z konsoli:  php cron/agents/tiLicensePackages.php
 * Awaryjnie to samo uruchamia się przy otwarciu pulpitu Licencji / karty korekty (LicenseDocPackage::runDue).
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_licencje_dydaktyczne/lib/LicenseDocPackage.php';

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
try {
    $lines = (new LicenseDocPackage())->runDue();
} catch (\Throwable $e) {
    echo $ts() . ' BŁĄD ' . $e->getMessage() . "\n";
    exit(1);
}
if (!$lines) { echo $ts() . " ti_licencje_packages: brak pakietów do wygenerowania.\n"; exit(0); }
foreach ($lines as $l) echo $ts() . ' ' . $l . "\n";
echo $ts() . " Koniec.\n";
