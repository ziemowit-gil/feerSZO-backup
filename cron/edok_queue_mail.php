<?php
/**
 * cron/edok_queue_mail.php — przyjmuje załączniki z e-maili do Kolejki do opisu EODoK
 * (skrzynka z org_setting edok_queue_mailbox, ustawiana na edok/queue.php).
 */
if (PHP_SAPI !== 'cli' && !(defined('CRON_DISPATCH') && CRON_DISPATCH === true)) {
    http_response_code(403);
    exit('Dostęp tylko przez CLI lub cron.php');
}
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/edok_queue.php';

if (org_setting('edok_queue_mail_enabled') !== '1') { echo "Wyłączone.\n"; return; }
$r = edok_queue_mail_ingest();
org_setting_set('edok_queue_mail_last_run', date('Y-m-d H:i:s') . ' — wiadomości: ' . $r['messages'] . ', dodano plików: ' . $r['added']
    . ($r['errors'] ? ', błędy: ' . count($r['errors']) : '') . ($r['skipped_senders'] ? ', pominięci nadawcy: ' . count($r['skipped_senders']) : ''));
echo '[' . date('Y-m-d H:i:s') . "] wiadomości={$r['messages']} dodano={$r['added']} pominięci=" . count($r['skipped_senders']) . " błędy=" . count($r['errors']) . "\n";
foreach ($r['errors'] as $e) echo "  BŁĄD: {$e}\n";
