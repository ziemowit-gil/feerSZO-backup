<?php
/**
 * cron/ti_week_digest.php — poniedziałkowa wysyłka „Zajęcia w tym tygodniu” (e-mail + SMS) do uczestników TI.
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_week_digest: okno 7:00–8:00, odstęp 23 h; wysyła tylko w poniedziałki) albo ręcznie:
 *   php cron/ti_week_digest.php --dry-run                 # kto dostałby wiadomość (nic nie wysyła, nic nie zapisuje; ignoruje dzień tygodnia)
 *   php cron/ti_week_digest.php --client=123              # tylko ten uczestnik (id z k30_clients)
 *   php cron/ti_week_digest.php --dry-run --date=2026-10-26
 * Reguły i bezpieczniki: modules/ti_week_digest/logic/tiWeekDigest.php. Przy wyłączonym mechanizmie (Panel dydaktyka → Wysyłka tygodniowa)
 * kończy się bez skutków; ponowne uruchomienie w tym samym tygodniu niczego nie dubluje.
 */
if (php_sapi_name() !== 'cli') { die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n"); }
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_week_digest/logic/tiWeekDigest.php';

$dry = false; $client = 0; $date = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif (str_starts_with($a, '--client=')) $client = (int)substr($a, 9);
    elseif (str_starts_with($a, '--date=')) $date = substr($a, 7);
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
if ($date !== '' && (!$dry || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) { fwrite(STDERR, "--date=RRRR-MM-DD działa tylko razem z --dry-run.\n"); exit(2); }

echo '[' . date('Y-m-d H:i:s') . '] Start: ti_week_digest' . ($dry ? ' (podgląd — bez wysyłki)' : '') . "\n";
$r = TiWeekDigest::run(['dry_run' => $dry, 'client_id' => $client ?: null, 'today' => $date !== '' ? $date : date('Y-m-d'), 'only_monday' => !$dry && !$client]);
foreach ($r['preview'] as $p) echo sprintf("  %s — zajęć: %d, e-mail: %d, SMS: %d\n", $p['name'], $p['lessons'], $p['email'], $p['sms']);
echo '[' . date('Y-m-d H:i:s') . '] Koniec. Tydzień od ' . ($r['week'] ?: '—') . ', uczestników: ' . $r['students'] . ', wysłano e-maili: ' . $r['sent']['email'] . ', SMS: ' . $r['sent']['sms']
   . ', pominięto: ' . json_encode($r['skipped'], JSON_UNESCAPED_UNICODE) . ', błędy: ' . $r['failed'] . "\n";
foreach (array_slice($r['errors'], 0, 5) as $e) echo "  BLAD: {$e}\n";
