<?php
/**
 * Skrzynka Helpdesku: e-maile przychodzące → zgłoszenia (modules/helpdeskMail/lib/helpdeskMail.php).
 * Czyta wiadomości już zapisane przez skanowanie Poczty (crm_communications), nie łączy się z Microsoft Graph
 * poza dociąganiem załączników. Ręcznie: php cron/helpdesk_mail_intake.php [--dry-run]
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/helpdeskMail/lib/helpdeskMail.php';

$dry = in_array('--dry-run', $argv, true);
echo "[" . date('Y-m-d H:i:s') . "] Start: helpdesk_mail_intake" . ($dry ? ' (dry-run)' : '') . "\n";
try {
    $s = hdMailIntakeRun($dry);
    echo "  zgłoszenia: {$s['tickets']}, odpowiedzi: {$s['replies']}, pominięte: {$s['skipped']}, potwierdzenia: {$s['acks']}\n";
} catch (\Throwable $e) {
    echo "  ✗ " . $e->getMessage() . "\n";
}
