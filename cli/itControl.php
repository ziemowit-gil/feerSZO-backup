<?php
/**
 * cli/itControl.php — Kontrola IT z linii poleceń (Kontrola Dzienników + Kontrola ogólna).
 *
 *   php cli/itControl.php --dry-run            # wszystko, bez zapisu i wysyłki
 *   php cli/itControl.php --suite=logs --json  # tylko dzienniki, wynik JSON
 *   php cli/itControl.php --report --force     # raport e-mailem teraz
 *   php cli/itControl.php --help
 *
 * Logika: modules/it_control/logic/itControlCli.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

define('APP_CLI', true);
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/modules/it_control/logic/itControlCli.php';

exit(itControlCliMain($argv));
