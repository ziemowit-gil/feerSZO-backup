<?php
/**
 * cli/ppkAutoEnroll.php — ponowny autozapis do PPK (co 4 lata od 2023, miesiąc z ustawienia ppk_auto_enroll_month, domyślnie marzec).
 * Dopisuje wersję „uczestnik” (stawki domyślne 2% / 1,5%) umowom o pracę z rezygnacją, dla których termin autozapisu już nadszedł.
 * Użycie: php cli/ppkAutoEnroll.php [RRRR-MM-DD]   (data = „dziś”, do testów); najlepiej z crona raz na dobę. Idempotentne.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/ppk/logic/PpkService.php';

$today = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) { fwrite(STDERR, "Data w formacie RRRR-MM-DD.\n"); exit(2); }
PpkService::migrate();
$n = PpkService::autoEnrollAll($today);
fwrite(STDOUT, "Ponownie zapisano do PPK: $n umów (stan na $today).\n");
