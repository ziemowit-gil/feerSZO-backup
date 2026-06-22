#!/usr/bin/env php
<?php
/**
 * cron/crm_inbox_watch.php
 *
 * Śledzi skrzynkę współdzieloną (domyślnie fundacja@feer.org.pl) i automatycznie
 * dopisuje nadawców do kartoteki CRM oraz loguje wiadomości przychodzące.
 *
 * Uruchamiany przez dispatcher.php (co ~10 min). Ręcznie:
 *   php cron/crm_inbox_watch.php
 *
 * Konfiguracja: CRM → Ustawienia → Śledzenie skrzynki (admin).
 */

define('APP_CLI', true);

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_inbox.php';

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_inbox_watch\n";

if (!module_enabled('crm_enabled')) {
    echo "[SKIP] Modul CRM jest wylaczony.\n";
    exit(0);
}

crm_migrate();

if (crm_setting('crm_inbox_watch_enabled') !== '1') {
    echo "[SKIP] Sledzenie skrzynki jest wylaczone (CRM > Ustawienia > Sledzenie skrzynki).\n";
    exit(0);
}

try {
    $r = crm_inbox_watch_run();
    echo '[' . date('Y-m-d H:i:s') . "] Skrzynka: {$r['mailbox']}\n";
    echo '[' . date('Y-m-d H:i:s') . "] Pobrano={$r['fetched']} utworzono={$r['created']} zalogowano={$r['logged']} pominieto={$r['skipped']}\n";
    foreach ($r['errors'] as $e) echo '[ERR] ' . $e . "\n";
} catch (\Throwable $e) {
    echo '[FATAL] ' . $e->getMessage() . "\n";
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_inbox_watch\n";
exit(0);
