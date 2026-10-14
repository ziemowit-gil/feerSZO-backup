<?php
/**
 * cron/ti_product_reminders.php — przypomnienia o wygasających domenach i hostingu kursantów TI (e-mail), o 8:00.
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_product_reminders) albo ręcznie:
 *   php cron/ti_product_reminders.php --dry-run                    # komu przypomniałby dziś (nic nie wysyła, nic nie zapisuje)
 *   php cron/ti_product_reminders.php --dry-run --date=2026-11-01  # symulacja na wskazany dzień
 * Reguły: modules/ti_products/logic/tiProductReminders.php. Ponowne uruchomienie tego samego dnia niczego nie dubluje.
 */
if (php_sapi_name() !== 'cli') die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_products/logic/tiProductReminders.php';

$dry = false; $date = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run')                 $dry = true;
    elseif (str_starts_with($a, '--date=')) $date = substr($a, 7);
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
if ($date !== '' && (!$dry || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) { fwrite(STDERR, "--date=RRRR-MM-DD działa tylko z --dry-run.\n"); exit(2); }

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . ' Start: ti_product_reminders' . ($dry ? ' (podgląd — bez wysyłki)' : '') . "\n";
$res = TiProductReminders::run($dry, $date);
if ($res['note'] !== '') { echo $ts() . ' ' . $res['note'] . "\n"; exit(0); }
foreach ($res['items'] as $i) {
    echo $ts() . sprintf("   %s — %s %s (%s), wygasa %s, zostało %d dni, próg %d → %s\n", $i['client'], $i['type'], $i['name'], $i['provider'], $i['expires'],
        $i['left'], $i['stage'], $i['to'] ? implode(', ', $i['to']) : 'nikt (' . (implode('; ', array_unique($i['skip'])) ?: 'brak adresu') . ')');
}
echo $ts() . ' Przypomnień: ' . count($res['items']) . ($dry ? '' : ", e-maili: {$res['sent']}, zestawień dla admina: {$res['admin']}") . "\n";
echo $ts() . " Koniec.\n";
