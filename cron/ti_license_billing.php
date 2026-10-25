<?php
/**
 * cron/ti_license_billing.php — przypomnienia o rozliczeniu miesięcznym licencji i produktów kursantów TI (e-mail + SMS), o 7:00,
 * DZIEŃ PRZED terminem rozliczenia (dzień miesiąca z przypisania; w krótszych miesiącach ostatni dzień).
 *
 *   php cron/ti_license_billing.php --dry-run                 # kto dostałby przypomnienie (nic nie wysyła)
 *   php cron/ti_license_billing.php --dry-run --date=2026-11-24
 *   php cron/ti_license_billing.php --id=12                   # tylko to przypisanie
 * Reguły i szablony: modules/ti_licencje/logic/tiLicencjeBilling.php (ti_license_billing_reminder[_guardian]). Ponowne uruchomienie
 * tego samego dnia niczego nie dubluje (unikalny wpis na licencję + okres + kanał + adresata).
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_licencje/logic/tiLicencjeNotify.php';
require_once $base_dir . '/modules/ti_licencje/logic/tiLicencjeBilling.php';

$dry = false; $only = 0; $date = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run')               $dry = true;
    elseif (str_starts_with($a, '--id=')) $only = (int)substr($a, 5);
    elseif (str_starts_with($a, '--date=')) $date = substr($a, 7);
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
if ($date !== '' && (!$dry || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) { fwrite(STDERR, "--date=RRRR-MM-DD działa tylko z --dry-run\n"); exit(2); }
echo "[" . date('Y-m-d H:i:s') . "] Start: ti_license_billing" . ($dry ? ' (dry-run)' : '') . "\n";
try {
    $r = TiLicencjeBilling::runReminders($date !== '' ? $date : null, $dry, $only ?: null);
    echo "  do przypomnienia: " . count($r['due']) . ($dry ? '' : ", wysłano: {$r['sent']}, pominięto: {$r['skipped']}") . "\n";
    foreach ($r['due'] as $d) echo "   - {$d['type']} #{$d['id']} {$d['client']} / {$d['software']} → termin {$d['date']}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
    exit(1);
}
