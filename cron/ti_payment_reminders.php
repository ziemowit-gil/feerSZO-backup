<?php
/**
 * cron/ti_payment_reminders.php — codzienne przypomnienia o braku wpłaty za zajęcia TI (e-mail + SMS), o 7:00.
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_payment_reminders: okno 7:00–8:00, odstęp 23 h — start o 7:00 bez dryfu)
 * albo ręcznie z konsoli:
 *   php cron/ti_payment_reminders.php --dry-run          # kto dostałby dziś przypomnienie (nic nie wysyła, nic nie zapisuje)
 *   php cron/ti_payment_reminders.php --client=123       # tylko ten kursant (id z k30_clients)
 *   php cron/ti_payment_reminders.php --dry-run --date=2026-10-12   # symulacja na wskazany dzień (tylko z --dry-run)
 * Reguły (co to „brak wpłaty”, kto jest adresatem, bezpieczniki): modules/ti_payment_reminders/logic/tiPaymentReminders.php.
 * Przy wyłączonych przypomnieniach (Panel dydaktyka → Przypomnienia o wpłatach) kończy się bez skutków; treść wiadomości to szablony
 * ti_payment_reminder (Szablony e-mail / Szablony SMS). Ponowne uruchomienie tego samego dnia niczego nie dubluje.
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_payment_reminders/logic/tiPaymentReminders.php';

$dry = false; $client = 0; $date = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run')                  $dry = true;
    elseif (str_starts_with($a, '--client=')) $client = (int)substr($a, 9);
    elseif (str_starts_with($a, '--date='))   $date = substr($a, 7);
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
if ($date !== '' && (!$dry || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)))) {
    fwrite(STDERR, "--date=RRRR-MM-DD działa tylko razem z --dry-run (wysyłka zawsze dotyczy dzisiejszego dnia).\n");
    exit(2);
}

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . " Start: ti_payment_reminders" . ($dry ? ' (podgląd — bez wysyłki)' : '') . "\n";

$res = TiPaymentReminders::run(['dry_run' => $dry, 'client_id' => $client ?: null, 'today' => $date !== '' ? $date : date('Y-m-d')]);

if ($res['note'] !== '') { echo $ts() . ' ' . $res['note'] . "\n"; exit(0); }

echo $ts() . " Dzień {$res['date']}: kursantów z zaległością: {$res['clients']}, "
   . ($dry ? 'do wysłania' : 'wysłano') . ': e-mail ' . ($dry ? $res['to_send']['email'] : $res['sent']['email'])
   . ', SMS ' . ($dry ? $res['to_send']['sms'] : $res['sent']['sms']) . ($dry ? '' : ", błędy: {$res['failed']}") . "\n";
foreach ($res['skipped'] as $reason => $n) echo $ts() . "   pominięto ({$n}): {$reason}\n";
foreach ($res['errors'] as $err) echo $ts() . " BŁĄD {$err}\n";
echo $ts() . " Koniec.\n";
