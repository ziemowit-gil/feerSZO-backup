<?php
/**
 * cli/mailMarkAllRead.php — jednorazowo oznacza jako przeczytane nieprzeczytaną pocztę przychodzącą
 * (crm_communications: Skrzynka CRM i Poczta EZD to ta sama tabela) i przelicza liczniki wątków EZD.
 *
 *   php cli/mailMarkAllRead.php                         # podgląd: ile nieprzeczytanych, w podziale na skrzynki
 *   php cli/mailMarkAllRead.php --apply                 # oznacz wszystkie
 *   php cli/mailMarkAllRead.php --mailbox=biuro@feer.org.pl --apply   # tylko jedna skrzynka (adres albo ID)
 *
 * Dotyczy wiadomości w obsłudze (inbox_status='active'); spam, ukryte i załatwione zostają bez zmian.
 * Uruchamialne też z Admin › Seedery (/seeder-test).
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit('Tylko z CLI.'); }
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/ezd_mail.php';

$opts  = cli_getopt('', ['apply', 'mailbox:']);
$apply = array_key_exists('apply', $opts);
$mbArg = trim((string)($opts['mailbox'] ?? ''));

$mailboxId = null;
if ($mbArg !== '') {
    $mb = ctype_digit($mbArg)
        ? db_one("SELECT id, mailbox FROM poczta_mailboxes WHERE id=?", [(int)$mbArg])
        : db_one("SELECT id, mailbox FROM poczta_mailboxes WHERE LOWER(mailbox)=LOWER(?)", [$mbArg]);
    if (!$mb) { echo "Nie znaleziono skrzynki „{$mbArg}”.\n"; cli_exit(1); }
    $mailboxId = (int)$mb['id'];
}

$rows = db_all(
    "SELECT c.mailbox_id, COALESCE(m.mailbox, '(bez skrzynki)') AS mailbox, COUNT(*) AS n
       FROM crm_communications c LEFT JOIN poczta_mailboxes m ON m.id = c.mailbox_id
      WHERE c.direction='in' AND c.inbox_status='active' AND c.is_read=0"
    . ($mailboxId ? " AND c.mailbox_id=" . $mailboxId : '') . "
      GROUP BY c.mailbox_id, m.mailbox ORDER BY n DESC"
);
$total = array_sum(array_map(fn($r) => (int)$r['n'], $rows));

if (!$total) { echo "Brak nieprzeczytanych wiadomości" . ($mailboxId ? " w tej skrzynce" : '') . ".\n"; cli_exit(0); }
echo "Nieprzeczytane w obsłudze: {$total}\n";
foreach ($rows as $r) printf("  %5d  %s\n", (int)$r['n'], $r['mailbox']);

if (!$apply) { echo "\nPodgląd — nic nie zmieniono. Dodaj --apply, żeby oznaczyć jako przeczytane.\n"; cli_exit(0); }

$n = (new EzdMailService())->markAllRead($mailboxId);
echo "\nOK — oznaczono jako przeczytane: {$n}.\n";
cli_exit(0);
