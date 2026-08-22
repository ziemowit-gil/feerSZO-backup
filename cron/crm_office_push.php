<?php
/**
 * Skrypt cron: dosyłanie kontaktów CRM do książki adresowej Outlooka.
 *
 * Obejmuje kontakty, które nie mają jeszcze wpisu w Outlooku albo zostały
 * zmienione po ostatnim zapisie (crm_contacts.office_pushed_at < updated_at).
 * Nic nie robi, dopóki administrator nie włączy zapisu w
 * Ustawienia CRM → Microsoft 365.
 *
 * Uruchamiany przez cron/dispatcher.php (agent 'crm_office_push').
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/auth.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_office.php';

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_office_push\n";

if (!crm_office_push_enabled()) {
    echo "  zapis kontaktów do Outlooka wyłączony — nic do zrobienia\n";
    exit(0);
}

$res = crm_office_push_pending(200);
echo "  nowe wpisy: {$res['created']}, zaktualizowane: {$res['updated']}, błędy: {$res['failed']}\n";
foreach ($res['errors'] as $e) echo "  ! $e\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_office_push\n";
