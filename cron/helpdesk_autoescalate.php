<?php
/**
 * Automatyczne podbijanie zgłoszeń Helpdesku po przekroczeniu SLA (modules/helpdeskEscalation).
 * Włącznik: Helpdesk › Ustawienia › Automatyczne podbijanie (domyślnie wyłączone).
 * Ręcznie: php cron/helpdesk_autoescalate.php [--dry-run]
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/helpdeskEscalation/lib/helpdeskEscalation.php';

$dry = in_array('--dry-run', $argv, true);
echo "[" . date('Y-m-d H:i:s') . "] Start: helpdesk_autoescalate" . ($dry ? ' (dry-run)' : '') . "\n";
try {
    $r = hdAutoEscRun($dry);
    echo !empty($r['disabled']) ? "  wyłączone w ustawieniach\n" : "  do podbicia: {$r['candidates']}, poziom 1: {$r['l1']}, poziom 2: {$r['l2']}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
