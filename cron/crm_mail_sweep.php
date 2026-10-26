<?php
/**
 * cron/crm_mail_sweep.php — przebiegi Skrzynki CRM wykonywane co 5 minut:
 *   • „Poza biurem”: rozmowy nieobecnych opiekunów, w których odpisał nadawca → bez opiekuna (includes/user_availability.php),
 *   • autoodpowiedzi skrzynek współdzielonych (includes/crm_mail_autoreply.php; z godzinami pracy),
 *   • powiadomienia dla obserwatorów rozmów (includes/crm_mail_followers.php) — gdy moduł jest wdrożony.
 */
if (PHP_SAPI !== 'cli' && !(defined('CRON_DISPATCH') && CRON_DISPATCH === true)) {
    http_response_code(403);
    exit('Dostęp tylko przez CLI lub cron.php');
}
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/crm.php';
require_once $base . '/includes/crm_mailbox.php';

if (!module_enabled('crm_enabled') || !crm_mailbox_ready()) { echo "CRM/Poczta wyłączone.\n"; return; }
require_once $base . '/includes/crm_mail_autoreply.php';

$ooo = crm_ooo_sweep();
$ar = crm_ar_run();
$fol = ['notified' => 0];
if (is_file($base . '/includes/crm_mail_followers.php')) { require_once $base . '/includes/crm_mail_followers.php'; $fol = crm_fol_sweep(); }
echo '[' . date('Y-m-d H:i:s') . "] poza-biurem odpięto={$ooo} autoodpowiedzi wysłano={$ar['sent']} pominięto={$ar['skipped']} obserwatorzy powiadomiono={$fol['notified']}\n";
foreach ($ar['errors'] as $e) echo "  BŁĄD autoodpowiedzi: {$e}\n";
