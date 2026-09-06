#!/usr/bin/env php
<?php
/**
 * cron/dispatcher.php — Główny dyspozytor agentów CRON.
 *
 * Jeden wpis w crontab, uruchamiany co minutę:
 *   * * * * * php /var/www/umowy/cron/dispatcher.php >> /var/log/umowy_cron.log 2>&1
 *
 * Każdy agent ma własny interwał (sekundy). Dyspozytor sprawdza plik blokady
 * z czasem ostatniego uruchomienia i wywołuje agenta jeśli interwał minął.
 * Agenci uruchamiani asynchronicznie (nie blokują dyspozytora).
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';

// ── Rejestr agentów ───────────────────────────────────────────────────────
// interval: minimalna przerwa między uruchomieniami (sekundy)
// schedule: opcjonalne ograniczenie godzinowe [H_start, H_end] — tylko w tym zakresie
$AGENTS = [
    'mail_queue' => [
        'file'     => __DIR__ . '/mail_queue.php',
        'interval' => 300,          // co 5 min
    ],
    'ext_agent' => [
        'file'     => __DIR__ . '/ext_agent.php',
        'interval' => 120,          // co 2 min — kolejka stempli i sprzątanie
    ],
    'tasks_reminder' => [
        'file'     => __DIR__ . '/tasks_due_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'crm_janitor' => [
        'file'     => __DIR__ . '/crm_janitor.php',
        'interval' => 86400,        // raz na dobę
        'schedule' => [2, 4],       // w nocy — przechodzi całą kartotekę
    ],
    'crm_activities_reminder' => [
        'file'     => __DIR__ . '/crm_activities_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [7, 9],       // między 7:00 a 9:00 — zanim ktoś zacznie dzwonić
    ],
    'tasks_recurring' => [
        'file'     => __DIR__ . '/tasks_recurring.php',
        'interval' => 86400,
        'schedule' => [6, 8],
    ],
    'tasks_archive' => [
        'file'     => __DIR__ . '/tasks_archive.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [3, 5],       // między 3:00 a 5:00
    ],
    'tasks_nozbe_sync' => [
        'file'     => __DIR__ . '/tasks_nozbe_sync.php',
        'interval' => 900,          // co 15 min — per-user push do Nozbe, patrz includes/task_nozbe.php
    ],
    'tasks_notification_worker' => [
        'file'     => __DIR__ . '/tasks_notification_worker.php',
        'interval' => 120,          // co 2 minuty — przetwarza kolejkę powiadomień
    ],
    'sync_m365' => [
        'file'     => __DIR__ . '/sync_m365.php',
        'interval' => 900,          // co 15 min
    ],
    'kdok_cleanup' => [
        'file'     => __DIR__ . '/kdok_cleanup.php',
        'interval' => 604800,       // raz w tygodniu
        'schedule' => [2, 4],       // między 2:00 a 4:00
    ],
    'contract_auto_complete' => [
        'file'     => __DIR__ . '/contract_auto_complete.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [4, 6],       // między 4:00 a 6:00
    ],
    'campaign_send' => [
        'file'     => __DIR__ . '/campaign_send.php',
        'interval' => 60,           // co minutę
    ],
    'bulk_email' => [
        'file'     => __DIR__ . '/bulk_email_process.php',
        'interval' => 60,           // co minutę
    ],
    'contract_expiry' => [
        'file'     => __DIR__ . '/contract_expiry_reminder.php',
        'interval' => 86400,
        'schedule' => [8, 10],
    ],
    'szkolenia_notifier' => [
        'file'     => __DIR__ . '/agents/szkolenia_notifier.php',
        'interval' => 86400,        // sprawdza codziennie, wysyła raz w miesiącu (po 25. dniu)
        'schedule' => [9, 11],      // między 9:00 a 11:00
    ],
    'volunteer_account_reminder' => [
        'file'     => __DIR__ . '/volunteer_account_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [8, 10],      // między 8:00 a 10:00
    ],
    'guardian_consent_renewal' => [
        'file'     => __DIR__ . '/guardian_consent_renewal.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'minor_volunteer_periodic_verification' => [
        'file'     => __DIR__ . '/minor_volunteer_periodic_verification.php',
        'interval' => 86400,        // raz dziennie (cykl 90-dniowy sprawdzany wewnątrz)
        'schedule' => [8, 10],      // między 8:00 a 10:00
    ],
    'never_logged_in_reminder' => [
        'file'     => __DIR__ . '/agents/never_logged_in_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [9, 11],      // między 9:00 a 11:00
    ],
    'zus_reminder' => [
        'file'     => __DIR__ . '/zus_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [8, 10],      // między 8:00 a 10:00
    ],
    'ezd_reminder' => [
        'file'     => __DIR__ . '/ezd_deadline_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'process_m365_queue' => [
        'file'     => __DIR__ . '/process_m365_queue.php',
        'interval' => 300,          // co 5 min
    ],
    'sync_m365_reverse' => [
        'file'     => __DIR__ . '/sync_m365_reverse.php',
        'interval' => 3600,         // co godzinę
        'schedule' => [1, 23],
    ],
    'sync_ldap' => [
        'file'     => __DIR__ . '/sync_ldap.php',
        'interval' => 3600,         // co godzinę
        'schedule' => [1, 23],
    ],
    'k30_m365_expire' => [
        'file'     => __DIR__ . '/k30_m365_expire.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [2, 5],       // między 2:00 a 5:00
    ],
    'k30_ti_billing_autoissue' => [
        'file'     => __DIR__ . '/k30_ti_billing_autoissue.php',
        'interval' => 86400,        // raz dziennie (skrypt działa tylko w pierwszy dzień miesiąca, za miesiąc poprzedni)
        'schedule' => [21, 23],     // wieczorem pierwszego dnia miesiąca
    ],
    'ti_lesson_reminders' => [
        'file'     => __DIR__ . '/ti_lesson_reminders.php',
        'interval' => 86400,        // raz dziennie — SMS o zajęciach zaplanowanych na jutro
        'schedule' => [8, 10],
    ],
    'backup' => [
        'file'     => __DIR__ . '/agents/backup.php',
        'interval' => 14400,        // co 4h — backup przyrostowy (lokalny)
    ],
    'sp_backup_incremental' => [
        'file'     => __DIR__ . '/agents/sp_backup_incremental.php',
        'interval' => 21600,        // co 6h — backup przyrostowy na SharePoint
    ],
    'sp_backup_full' => [
        'file'     => __DIR__ . '/agents/sp_backup_full.php',
        'interval' => 86400,        // raz dziennie — pełny backup systemu na SharePoint
        'schedule' => [1, 3],       // w nocy
    ],
    'sync_crm_volunteers' => [
        'file'     => __DIR__ . '/sync_crm_volunteers.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [3, 5],       // między 3:00 a 5:00
    ],
    'outlook_calendar_sync' => [
        'file'     => __DIR__ . '/outlook_calendar_sync.php',
        'interval' => 3600,         // co godzinę
        'schedule' => [6, 23],      // w godzinach pracy
    ],
    'sync_users_to_test' => [
        'file'     => __DIR__ . '/sync_users_to_test.php',
        'interval' => 3600,         // co godzinę
    ],
    'crm_inbox_watch' => [
        'file'     => __DIR__ . '/crm_inbox_watch.php',
        'interval' => 600,          // co 10 min
        'schedule' => [6, 23],      // w godzinach pracy
    ],
    'poczta_dispatch' => [
        'file'     => __DIR__ . '/poczta_dispatch.php',
        'interval' => 600,          // co 10 min — generuje zadania skanowania skrzynek
        'schedule' => [6, 23],      // w godzinach pracy
    ],
    'poczta_worker' => [
        'file'     => __DIR__ . '/poczta_worker.php',
        'interval' => 60,           // co minutę — konsumuje kolejkę RabbitMQ „poczta_skanowanie"
        'schedule' => [6, 23],
    ],
    'crm_office_push' => [
        'file'     => __DIR__ . '/crm_office_push.php',
        'interval' => 1800,         // co 30 min — dosyła kontakty do książki adresowej Outlooka
        'schedule' => [6, 22],
    ],
    'crm_office_mail' => [
        'file'     => __DIR__ . '/crm_office_mail.php',
        'interval' => 600,          // co 10 minut — dociąga korespondencję do kartotek
        'schedule' => [6, 22],
        'args'     => '--limit=25',
    ],
    'crm_offers' => [
        'file'     => __DIR__ . '/crm_offers_agent.php',
        'interval' => 86400,        // raz dziennie — wygaszanie ofert, follow-up, brak potwierdzeń
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'crm_assign_owners' => [
        'file'     => __DIR__ . '/crm_assign_owners.php',
        'interval' => 86400,        // raz dziennie — opiekunowie dla nowych kartotek
        'schedule' => [5, 7],
        'args'     => '--apply',
    ],
    'crm_sync_partners' => [
        'file'     => __DIR__ . '/crm_sync_partners.php',
        'interval' => 86400,        // raz dziennie — grupa „Współpracownicy" wg typów umów
        'schedule' => [3, 5],
        'args'     => '--apply',
    ],
    'crm_volunteers_expired' => [
        'file'     => __DIR__ . '/crm_volunteers_expired.php',
        'interval' => 604800,       // raz w tygodniu — porządkuje grupę wolontariuszy
        'schedule' => [4, 6],
        'args'     => '--apply',
    ],
    'crm_retention' => [
        'file'     => __DIR__ . '/crm_retention.php',
        'interval' => 604800,       // raz w tygodniu — przegląd retencji danych osobowych
        'schedule' => [5, 7],       // agent tylko OZNACZA, anonimizuje człowiek
    ],
    'crm_email_kinds' => [
        'file'     => __DIR__ . '/crm_email_kinds.php',
        'interval' => 86400,        // raz dziennie — nowe adresy z importów i skrzynki
        'schedule' => [4, 6],
        'args'     => '--limit=300',
    ],
    'crm_consents_expiring' => [
        'file'     => __DIR__ . '/crm_consents_expiring.php',
        'interval' => 604800,       // raz w tygodniu — zgody z ograniczoną ważnością
        'schedule' => [6, 8],
    ],
    'crm_cases_due' => [
        'file'     => __DIR__ . '/crm_cases_due.php',
        'interval' => 86400,        // raz dziennie — terminy spraw i naruszenia SLA
        'schedule' => [7, 9],       // między 7:00 a 9:00
    ],
    'crm_inbox_autoabandon' => [
        'file'     => __DIR__ . '/crm_inbox_autoabandon.php',
        'interval' => 86400,        // raz dziennie — porzuca wiadomości bez akcji starsze niż 3 mies.
        'schedule' => [3, 5],       // między 3:00 a 5:00
    ],
    'ezd_mail_ingest' => [
        'file'     => __DIR__ . '/ezd_mail_ingest.php',
        'interval' => 300,          // co 5 min — match [EZD:ZNAK] i Inbox Ogólny
        'schedule' => [6, 23],      // w godzinach pracy (M365 Graph jest tu ograniczony)
    ],
];

// Agenci aktywni tylko w środowisku Docker
if (file_exists('/.dockerenv')) {
    $AGENTS['gdpr_statement_reminder'] = [
        'file'     => __DIR__ . '/gdpr_statement_reminder.php',
        'interval' => 86400,        // raz dziennie
        'schedule' => [8, 10],      // między 8:00 a 10:00
    ];
}

$lock_dir = sys_get_temp_dir();
$now      = time();
$hour     = (int)date('G');

foreach ($AGENTS as $name => $cfg) {
    $agent_file = $cfg['file'];

    if (!file_exists($agent_file)) {
        echo "[SKIP] {$name}: plik nie istnieje ({$agent_file})\n";
        continue;
    }

    // Sprawdź okno godzinowe
    if (isset($cfg['schedule'])) {
        [$h_from, $h_to] = $cfg['schedule'];
        if ($hour < $h_from || $hour >= $h_to) {
            continue;
        }
    }

    // Sprawdź interwał
    $lock = $lock_dir . '/umowy_cron_' . $name . '.last';
    $last = file_exists($lock) ? (int)file_get_contents($lock) : 0;
    if ($now - $last < $cfg['interval']) {
        continue;
    }

    // Zapisz czas uruchomienia PRZED startem (zapobiega podwójnemu uruchomieniu)
    file_put_contents($lock, $now);

    $php  = PHP_BINARY ?: 'php';
    $cmd  = escapeshellarg($php) . ' ' . escapeshellarg($agent_file);
    // Argumenty agenta (np. --apply dla skryptów z trybem podglądu) — każdy osobno
    // przez escapeshellarg, żeby wpis w rejestrze nie mógł doklejać poleceń powłoki.
    foreach (preg_split('/\s+/', trim((string)($cfg['args'] ?? ''))) as $arg) {
        if ($arg !== '') $cmd .= ' ' . escapeshellarg($arg);
    }
    $log  = defined('LOG_PATH') ? LOG_PATH . '/cron_' . $name . '.log' : '/dev/null';

    // Uruchom asynchronicznie — nie blokuj dyspozytora
    exec("{$cmd} >> " . escapeshellarg($log) . " 2>&1 &");

    echo "[RUN] {$name} @ " . date('H:i:s') . "\n";
}
