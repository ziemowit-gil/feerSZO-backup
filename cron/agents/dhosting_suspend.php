#!/usr/bin/env php
<?php
/**
 * cron/agents/dhosting_suspend.php — zawieszanie skrzynek pocztowych (nowy serwer poczty) DhostingContractMail::GRACE_DAYS
 * dni po wygaśnięciu umowy (polecenie 2026-11-13): skrzynka wyłączona + losowe hasło, BEZ powiadamiania użytkownika.
 * Pomija osoby z inną trwającą umową, „nie wyłączaj”, okres ochronny po rozwiązaniu i skrzynki bez umowy w SZO.
 * Uruchamiany przez cron/dispatcher.php co godzinę; wyłącznik: ustawienie dhosting_contract_suspend=0.
 * Ręcznie: php cron/agents/dhosting_suspend.php
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/approval.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 2) . '/modules/dhosting_migrator/logic/DhostingContractMail.php';

@set_time_limit(0);
foreach (DhostingContractMail::suspendExpired() as $l) echo $l, "\n";
exit(0);
