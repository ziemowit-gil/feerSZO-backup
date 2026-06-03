#!/usr/bin/env php
<?php
/**
 * cron/sync_crm_volunteers.php
 *
 * Synchronizuje grupę CRM "Wolontariusze":
 *  - dodaje kontakty wolontariuszy z aktywnymi umowami (projekt/podpisana/w realizacji)
 *  - usuwa kontakty bez żadnej aktywnej umowy
 *
 * Uruchamiany automatycznie przez dispatcher.php (raz dziennie, w nocy).
 * Można też uruchomić ręcznie: php cron/sync_crm_volunteers.php
 */

define('APP_CLI', true);

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';

echo '[' . date('Y-m-d H:i:s') . "] Start: sync_crm_volunteers\n";

if (!module_enabled('crm_enabled')) {
    echo "[SKIP] Modul CRM jest wylaczony.\n";
    exit(0);
}

crm_migrate();

$result = CrmManager::syncVolunteerGroup();

echo '[' . date('Y-m-d H:i:s') . '] Dodano do grupy:  ' . $result['added']   . "\n";
echo '[' . date('Y-m-d H:i:s') . '] Usunieto z grupy: ' . $result['removed'] . "\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: sync_crm_volunteers\n";
exit(0);
