<?php
/**
 * cron/stopka_exchange_sync.php — odświeża regułę transportową Exchange ze stopką e-mail (raz na dobę, w nocy).
 *
 * Uruchamiany przez cron/dispatcher.php (agent stopka_exchange_sync) albo ręcznie: php cron/stopka_exchange_sync.php
 * Przy wyłączonej synchronizacji (Stopka e-mail → Exchange) kończy się bez skutków. Reguła w trybie Audit nie zmienia wiadomości.
 * Wynik trafia też do stopka_settings.ex_last_sync/ex_last_msg (widać go w panelu admina).
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/m365.php';
require_once $base_dir . '/modules/stopka/logic/stopka.php';
require_once $base_dir . '/modules/stopka/logic/stopkaExchange.php';

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
stopkaExMigrate();
if (empty(stopkaExSettings()['ex_enabled'])) {
    echo $ts() . " stopka_exchange_sync: synchronizacja z Exchange wyłączona — pomijam.\n";
    exit(0);
}
[$msg, $err] = stopkaExPush();
echo $ts() . ' stopka_exchange_sync: ' . ($err ? 'BŁĄD: ' : 'OK: ') . $msg . "\n";
exit($err ? 1 : 0);
