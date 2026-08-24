<?php
/**
 * cron/crm_sync_partners.php — synchronizacja grupy „Współpracownicy" i podgrup
 * per typ umowy (zlecenie, dzieło, usługi, praca, powierzenie, inne).
 *
 * Wolontariusze mają swoją grupę; reszta osób i podmiotów z aktywnymi umowami
 * nie miała żadnego przekroju, więc wysyłka „do wszystkich zleceniobiorców"
 * oznaczała ręczne wyklikiwanie kontaktów.
 *
 * Uruchamiany przez dispatcher raz dziennie. Bez --apply tylko liczy.
 *
 *   php cron/crm_sync_partners.php            # podgląd
 *   php cron/crm_sync_partners.php --apply    # zapis
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

define('APP_CLI', true);
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_partner_groups.php';

$apply = in_array('--apply', $argv, true);

echo '[' . date('Y-m-d H:i:s') . '] Start: crm_sync_partners' . ($apply ? ' (ZAPIS)' : ' (podgląd)') . "\n";

if (!module_enabled('crm_enabled')) { echo "[SKIP] Moduł CRM wyłączony.\n"; exit(0); }
crm_migrate();

$report = crm_partner_groups_sync($apply);

foreach ($report as $key => $r) {
    if ($key === '_removed') continue;
    printf("  %-28s powinno: %4d  dodano: %3d  usunięto: %3d\n",
        $r['label'], $r['should'], $r['added'], $r['removed']);
}
foreach ($report['_removed'] ?? [] as $gone) {
    echo '  · usunięto nieaktualną podgrupę: ' . $gone . "\n";
}

if (!$apply) echo "  Uruchom ponownie z --apply, żeby zapisać skład grup.\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_sync_partners\n";
