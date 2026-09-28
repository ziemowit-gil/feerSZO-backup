<?php
/**
 * cli/ti_protocols_next_month.php — otwiera protokół na następny miesiąc dla
 * każdego zatwierdzonego protokołu miesięcznego, który go jeszcze nie ma
 * (ti_protocols_backfill_next_months() z includes/ti_protocols.php).
 * Pomija zamknięte grupy i nieaktywne bez lekcji w następnym miesiącu.
 *
 *   php cli/ti_protocols_next_month.php --dry-run   # tylko pokaż
 *   php cli/ti_protocols_next_month.php             # otwórz
 */
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Tylko CLI.\n"); }
$base = dirname(__DIR__);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
defined('TI_PROTOCOLS_NO_AUTO_BACKFILL') || define('TI_PROTOCOLS_NO_AUTO_BACKFILL', true);   // CLI robi to jawnie (i umie --dry-run)
require_once $base . '/config.php';
require_once $base . '/includes/cli_inproc.php';   // cli_exit() — też tryb in-process (zakładka Testy)
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/includes/ti_protocols.php';
karty30_migrate();

$dry  = in_array('--dry-run', $argv, true);
$rows = ti_protocols_backfill_next_months($dry);
foreach ($rows as $r) {
    $name = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$r['course_id']])['name'] ?? '#' . $r['course_id'];
    echo ($dry ? '[dry-run] ' : '') . "{$name} (#{$r['course_id']}) → {$r['year_month']}\n";
}
echo ($dry ? 'Do otwarcia: ' : 'Otwarto: ') . count($rows) . "\n";
