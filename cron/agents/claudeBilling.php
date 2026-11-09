<?php
/**
 * cron/agents/claudeBilling.php — przebieg dzienny abonamentu Claude (modules/claude_billing):
 * naliczenia (z fakturą w Fakturowni i kursem PKO BP), odczyt wpłat Szybkim przelewem z Fakturowni, przypomnienia w dniu terminu,
 * wstrzymanie dostępu po karencji (zgłoszenie Helpdesk), wznowienie po wpłacie, ponowienie faktur, których nie udało się wystawić.
 * Rejestr: modules/cron_dispatcher/logic/registry.php ('claude_billing').
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/claude_billing/logic/claudeBilling.php';

$r = ClaudeBilling::run();
echo date('Y-m-d H:i:s') . ' claude_billing: naliczenia ' . $r['charges'] . ', przypomnienia ' . $r['reminders'] . ', wstrzymane ' . $r['suspended'] . ', wznowione ' . $r['resumed']
    . ($r['errors'] ? ' BŁĘDY: ' . implode(' | ', $r['errors']) : '') . PHP_EOL;
foreach ($r['log'] as $l) echo '  ' . $l . PHP_EOL;
