<?php
/** Wypycha szablon stopki do reguły transportowej Exchange (jeśli włączone). Cron: raz na dobę wystarczy. */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/modules/stopka/logic/stopka.php';
require_once dirname(__DIR__) . '/modules/stopka/logic/stopkaExchange.php';
stopkaExMigrate();
if (empty(stopkaExSettings()['ex_enabled'])) { echo "Synchronizacja z Exchange wyłączona.\n"; exit(0); }
[$msg, $err] = stopkaExPush();
echo ($err ? 'BŁĄD: ' : 'OK: ') . $msg . "\n";
exit($err ? 1 : 0);
