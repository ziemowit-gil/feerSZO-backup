<?php
/**
 * Przeliczanie kategorii kontaktów CRM (wolontariusz, zleceniobiorca, darczyńca, klient, partner, beneficjent…).
 * Ręcznie: php cron/crm_categories.php
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crmCategories.php';
echo "[" . date('Y-m-d H:i:s') . "] Start: crm_categories\n";
try {
    foreach (crm_categories_recalc() as $slug => $r) echo "  {$slug}: +{$r['added']} −{$r['removed']}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
