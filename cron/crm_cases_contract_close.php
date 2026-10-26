<?php
/**
 * cron/crm_cases_contract_close.php — zamyka sprawy CRM przypisane do umów, które się zakończyły (zob. includes/crmContractCases.php).
 * Uruchamiany przez cron/dispatcher.php co godzinę.
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crmContractCases.php';

$r = crm_cases_close_for_ended_contracts();
echo '[' . date('Y-m-d H:i:s') . "] crm_cases_contract_close: sprawdzono {$r['checked']}, zamknięto {$r['closed']}\n";
