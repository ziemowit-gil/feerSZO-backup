<?php
/**
 * cron/agents/noclegiInstructions.php — instrukcja zameldowania z kodem do drzwi / skrytki (moduł Noclegi).
 *
 * Uruchamiany przez cron/dispatcher.php (agent noclegi_instructions) albo ręcznie:
 *   php cron/agents/noclegiInstructions.php             # wysyłka do kolejki e-mail
 *   php cron/agents/noclegiInstructions.php --dry-run   # tylko lista, bez wysyłki i bez zapisu
 * Wysyłamy potwierdzonym rezerwacjom, których przyjazd mieści się w oknie ujawnienia (ustawienie nocl_code_reveal_days,
 * domyślnie 2 dni), raz na rezerwację. Wyłączenie: org_setting nocl_mail_instruction = 0.
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}
$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/noclegi/logic/noclegi.php';

$dry = in_array('--dry-run', array_slice($argv ?? [], 1), true);
$ts  = fn() => '[' . date('Y-m-d H:i:s') . ']';
if (trim(org_setting('nocl_mail_instruction')) === '0') { echo $ts() . " Wyłączone (nocl_mail_instruction = 0).\n"; exit(0); }
noclAutoComplete();
$due = noclDueInstructions();
echo $ts() . ' Do wysłania: ' . count($due) . ($dry ? " (podgląd)\n" : "\n");
$sent = 0;
foreach ($due as $d) {
    $r = noclRequestGet((int)$d['id']);
    echo $ts() . '   #' . $r['id'] . ' ' . $r['person_name'] . ', przyjazd ' . $r['date_from'] . "\n";
    if ($dry) continue;
    if (noclSendInstruction((int)$r['id'])) $sent++;
    else echo $ts() . "   pominięto — brak adresu e-mail albo pokoju.\n";
}
echo $ts() . " Wysłano: {$sent}.\n";
