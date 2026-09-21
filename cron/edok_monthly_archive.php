<?php
/**
 * cron/edok_monthly_archive.php — Automatyczna archiwizacja miesięczna EODoK
 * (Uchwała 5/2026 §7): wydruk kart akceptacji za miniony miesiąc + zestawienie
 * powiązań dokument→karta akceptacji→akceptant. Uruchamiany codziennie
 * (dispatcher), ale działa TYLKO w pierwszym dniu miesiąca (chyba że podano
 * --force lub okres YYYY-MM jako argument) — archiwizuje wtedy miesiąc
 * POPRZEDNI (ten sam wzorzec co cron/k30_ti_billing_autoissue.php).
 *
 * Uruchomienie ręczne (test/backfill):
 *   php cron/edok_monthly_archive.php --force
 *   php cron/edok_monthly_archive.php 2026-09
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/edok.php';
// Celowo BEZ includes/auth.php — session_start() wewnątrz auth_start() emituje
// ostrzeżenia w CLI po tym, jak skrypt już coś wypisał (ten sam wzorzec co
// w innych skryptach crona, np. cron/bulk_email_process.php: auth.php ładowane
// tylko w gałęzi web/HTTP, nie w prawdziwym CLI). edok_print_html()
// (wywoływana pośrednio przez edok_run_monthly_archive()) sama sprawdza
// function_exists('current_user') i działa bez sesji.

edok_migrate();

$force  = in_array('--force', $argv, true);
$period = null;
foreach ($argv as $a) { if (preg_match('/^(\d{4})-(\d{2})$/', $a, $m)) $period = [(int)$m[1], (int)$m[2]]; }

$is_first_day = ((int)date('j') === 1);
if (!$is_first_day && !$force && !$period) {
    echo "[" . date('Y-m-d H:i:s') . "] Nie pierwszy dzień miesiąca — pomijam automatyczną archiwizację EODoK.\n";
    exit(0);
}

if ($period) {
    [$year, $month] = $period;
} else {
    $prev_ts = strtotime('first day of last month');
    $year  = (int)date('Y', $prev_ts);
    $month = (int)date('m', $prev_ts);
}

echo "[" . date('Y-m-d H:i:s') . "] Archiwizacja EODoK za " . sprintf('%04d-%02d', $year, $month) . "…\n";

$archive = edok_run_monthly_archive($year, $month, null);
if ($archive === null) {
    echo "[" . date('Y-m-d H:i:s') . "] Brak dokumentów EODoK w tym miesiącu — pomijam.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Gotowe: {$archive['doc_count']} dokument(ów), PDF: {$archive['pdf_path']}"
   . ($archive['csv_path'] ? ", zestawienie: {$archive['csv_path']}" : '') . "\n";
