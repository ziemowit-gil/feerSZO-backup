<?php
/**
 * cron/k30_ti_billing_autoissue.php — Automatyczne wystawianie rozliczeń TI
 * ostatniego dnia miesiąca + powiadomienia (e-mail + SMS).
 *
 * Logika:
 *   1. Uruchamiany codziennie (dispatcher), ale działa TYLKO w ostatnim dniu miesiąca
 *      (chyba że podano --force lub okres YYYY-MM jako argument).
 *   2. Dla każdego kursanta z aktywnym zapisem, którego rozliczenie za bieżący
 *      miesiąc daje godziny>0 LUB kwotę>0 (model godzinowy / miesięczny / stały),
 *      wystawia rozliczenie (k30_ti_issue_billing) — idempotentnie.
 *   3. Przelicza saldo (auto-pobranie z nadpłaty) i wysyła powiadomienie
 *      (SMS z kwotą za okres + e-mail) — jednorazowo (guard notified_at).
 *
 * Uruchomienie ręczne (test):
 *   php cron/k30_ti_billing_autoissue.php --force
 *   php cron/k30_ti_billing_autoissue.php 2026-06
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/karty30.php';
require_once $base_dir . '/includes/ti_payments.php';

karty30_migrate();
ti_payments_migrate();

// ── Parametry / okno uruchomienia ─────────────────────────────────────────────
$force  = in_array('--force', $argv, true);
$period = null;
foreach ($argv as $a) { if (preg_match('/^(\d{4})-(\d{2})$/', $a, $m)) $period = [(int)$m[1], (int)$m[2]]; }

if ($period) {
    [$year, $month] = $period;
} else {
    $year  = (int)date('Y');
    $month = (int)date('m');
}

$is_last_day = (date('j') === date('t')); // dziś == ostatni dzień miesiąca
if (!$is_last_day && !$force && !$period) {
    echo "[" . date('Y-m-d H:i:s') . "] Nie ostatni dzień miesiąca — pomijam automatyczne rozliczenia.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Automatyczne rozliczenia TI za {$month}/{$year} — start"
   . ($force ? ' (--force)' : '') . ($period ? ' (okres z argumentu)' : '') . "\n";

// ── Kandydaci: kursanci z aktywnym zapisem ────────────────────────────────────
$clients = db_all("SELECT DISTINCT client_id FROM k30_ti_enrollments WHERE status='active'");

$issued = 0; $skip = 0; $sms = 0; $eml = 0; $errors = 0;

foreach ($clients as $row) {
    $cid = (int)$row['client_id'];
    try {
        $calc = k30_ti_calculate_billing($cid, $month, $year);
        // Pomiń, gdy brak godzin i brak kwoty (np. brak obecności i model godzinowy)
        if ($calc['hours_billed'] <= 0 && $calc['amount'] <= 0) { $skip++; continue; }

        $bid = k30_ti_issue_billing($cid, $month, $year);
        ti_billing_recompute($cid);            // auto-pobranie z nadpłaty
        $n = k30_ti_billing_notify($bid);      // SMS + e-mail (jednorazowo)
        $issued++;
        if (!empty($n['sms']))   $sms++;
        if (!empty($n['email'])) $eml++;
    } catch (\Throwable $e) {
        $errors++;
        echo "  [BŁĄD] client_id={$cid}: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('H:i:s') . "] Zakończono — wystawiono: {$issued}, pominięto: {$skip}, "
   . "SMS: {$sms}, e-mail: {$eml}, błędów: {$errors}\n";

try { org_setting_set('k30_ti_autoissue_last', date('Y-m-d H:i:s') . " ({$month}/{$year}: {$issued})"); } catch (\Throwable $e) {}
