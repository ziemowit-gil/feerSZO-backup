<?php
/**
 * Reguły czasowe Helpdesku (modules/helpdeskRules): zgłoszenia bez aktywności od N dni → zmiana statusu / notatka.
 * Ręcznie: php cron/helpdesk_rules.php [--dry-run]
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/helpdeskRules/lib/helpdeskRules.php';

$dry = in_array('--dry-run', $argv, true);
echo "[" . date('Y-m-d H:i:s') . "] Start: helpdesk_rules" . ($dry ? ' (dry-run)' : '') . "\n";
try {
    foreach (hdRulesRunTime($dry) as $name => $n) echo "  {$name}: {$n}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
