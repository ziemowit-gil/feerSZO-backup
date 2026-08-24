<?php
/**
 * cron/crm_assign_owners.php — automatyczne przypisywanie opiekunów nowym kartotekom.
 *
 * Reguły i rozdział po równo ustawia się w CRM → Ustawienia → Opiekunowie (automat);
 * ten agent stosuje je do kartotek, które NIE MAJĄ opiekuna. Kartotek z opiekunem
 * nie rusza nigdy — automat nie odbiera nikomu prowadzonych kontaktów.
 *
 *   php cron/crm_assign_owners.php            # podgląd
 *   php cron/crm_assign_owners.php --apply    # zapis
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
require_once $base_dir . '/includes/crm_owner_rules.php';

$apply = in_array('--apply', $argv, true);

echo '[' . date('Y-m-d H:i:s') . '] Start: crm_assign_owners' . ($apply ? ' (ZAPIS)' : ' (podgląd)') . "\n";

if (!module_enabled('crm_enabled')) { echo "[SKIP] Moduł CRM wyłączony.\n"; exit(0); }
crm_migrate();

$r = crm_owner_assign_bulk([
    'apply'      => $apply,
    'overwrite'  => false,          // nigdy nie nadpisujemy z crona
    'roundrobin' => true,
    'limit'      => 2000,
]);

foreach (array_slice($r['rows'], 0, 50) as $row) {
    echo '  ' . ($apply ? '✓' : '·') . ' ' . $row['name'] . ' → #' . $row['owner_id'] . ' (' . $row['why'] . ")\n";
}
if (count($r['rows']) > 50) echo '  … i ' . (count($r['rows']) - 50) . " dalszych\n";

echo "  Podsumowanie: sprawdzono {$r['total']}, z reguł {$r['matched']}, "
   . "po równo {$r['roundrobin']}, bez zmian {$r['skipped']}\n";
if (!$apply && $r['rows']) echo "  Uruchom ponownie z --apply, żeby zapisać.\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_assign_owners\n";
