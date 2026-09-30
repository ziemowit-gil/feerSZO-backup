<?php
/**
 * modules/cron_dispatcher/logic/registry.php — rejestr agentów CRON.
 *
 * Przeniesiony 1:1 z cron/dispatcher.php, żeby panel admina
 * (admin/cron_dispatcher.php) mógł go czytać bez uruchamiania agentów.
 * To są wartości DOMYŚLNE — nadpisania z panelu (włącz/wyłącz, interwał, okno
 * godzinowe) leżą w tabeli cron_agent_overrides i nakłada je
 * cron_dispatcher_effective() (logic/cron_dispatcher.php).
 *
 * Nowego agenta dopisuj TUTAJ (nie w dispatcher.php).
 */

/**
 * Interwał dla sync_m365: normalnie co 15 min, ale gdy istnieje umowa
 * z aktywnym/właśnie upłynniętym okresem ochronnym po rozwiązaniu (patrz
 * includes/termination.php::decide_termination(), m365_deactivate_after),
 * skracamy do 5 min — żeby konto M365/SZO wyłączyło się szybko po
 * zakończeniu 4h okresu, a nie czekało do kolejnych 15 min.
 */
function _dispatcher_sync_m365_interval(): int {
    $normal = 900;   // 15 min
    $fast   = 300;    // 5 min
    $tables = ['umowy_wolontariat', 'umowy_zlecenie', 'umowy_dzielo'];
    foreach ($tables as $table) {
        try {
            $row = db_one(
                "SELECT 1 FROM {$table}
                 WHERE m365_konto = 1 AND m365_konto_aktywne = 1
                   AND m365_deactivate_after IS NOT NULL
                   AND m365_deactivate_after > datetime('now', '-1 hour')
                 LIMIT 1"
            );
            if ($row) return $fast;
        } catch (\Throwable $e) {
            // Kolumna/tabela może jeszcze nie istnieć (samonaprawa schematu
            // przy pierwszym użyciu modułu wniosków o rozwiązanie) — pomijamy.
        }
    }
    return $normal;
}

/** @return array<string,array{file:string,interval:int|string,schedule?:array{0:int,1:int},args?:string}> */
function cron_dispatcher_registry(): array
{
    $cronDir = dirname(__DIR__, 3) . '/cron';

    // ── Rejestr agentów ───────────────────────────────────────────────────────
    // interval: minimalna przerwa między uruchomieniami (sekundy)
    // schedule: opcjonalne ograniczenie godzinowe [H_start, H_end] — tylko w tym zakresie
    $AGENTS = [
        'mail_queue' => [
            'file'     => $cronDir . '/mail_queue.php',
            'interval' => 300,          // co 5 min
        ],
        'ext_agent' => [
            'file'     => $cronDir . '/ext_agent.php',
            'interval' => 120,          // co 2 min — kolejka stempli i sprzątanie
        ],
        'tasks_reminder' => [
            'file'     => $cronDir . '/tasks_due_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [7, 9],       // między 7:00 a 9:00
        ],
        'crm_janitor' => [
            'file'     => $cronDir . '/crm_janitor.php',
            'interval' => 86400,        // raz na dobę
            'schedule' => [2, 4],       // w nocy — przechodzi całą kartotekę
        ],
        'crm_activities_reminder' => [
            'file'     => $cronDir . '/crm_activities_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [7, 9],       // między 7:00 a 9:00 — zanim ktoś zacznie dzwonić
        ],
        'tasks_mail_reply' => [
            'file'     => $cronDir . '/tasks_mail_reply.php',
            'interval' => 300,          // co 5 min — odpowiedzi e-mailem → komentarze (tylko gdy włączone)
        ],
        'tasks_due_soon' => [
            'file'     => $cronDir . '/tasks_due_soon.php',
            'interval' => 900,          // co 15 min — przypomnienie ~1 h przed godziną terminu
        ],
        'tasks_digest' => [
            'file'     => $cronDir . '/tasks_digest.php',
            'interval' => 86400,        // raz dziennie — podsumowanie zamiast pojedynczych maili
            'schedule' => [16, 18],     // po południu, pod koniec dnia pracy
        ],
        'tasks_recurring' => [
            'file'     => $cronDir . '/tasks_recurring.php',
            'interval' => 86400,
            'schedule' => [6, 8],
        ],
        'tasks_archive' => [
            'file'     => $cronDir . '/tasks_archive.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [3, 5],       // między 3:00 a 5:00
        ],
        'tasks_nozbe_sync' => [
            'file'     => $cronDir . '/tasks_nozbe_sync.php',
            'interval' => 900,          // co 15 min — per-user push do Nozbe, patrz includes/task_nozbe.php
        ],
        'tasks_notification_worker' => [
            'file'     => $cronDir . '/tasks_notification_worker.php',
            'interval' => 120,          // co 2 minuty — przetwarza kolejkę powiadomień
        ],
        'sync_m365' => [
            'file'     => $cronDir . '/sync_m365.php',
            'interval' => '_dispatcher_sync_m365_interval', // 15 min normalnie, 5 min po terminacjach (patrz wyżej)
        ],
        'kdok_cleanup' => [
            'file'     => $cronDir . '/kdok_cleanup.php',
            'interval' => 604800,       // raz w tygodniu
            'schedule' => [2, 4],       // między 2:00 a 4:00
        ],
        'contract_auto_complete' => [
            'file'     => $cronDir . '/contract_auto_complete.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [4, 6],       // między 4:00 a 6:00
        ],
        'campaign_send' => [
            'file'     => $cronDir . '/campaign_send.php',
            'interval' => 60,           // co minutę
        ],
        'bulk_email' => [
            'file'     => $cronDir . '/bulk_email_process.php',
            'interval' => 60,           // co minutę
        ],
        'contract_expiry' => [
            'file'     => $cronDir . '/contract_expiry_reminder.php',
            'interval' => 86400,
            'schedule' => [8, 10],
        ],
        'pelnomocnictwa_expiry' => [
            'file'     => $cronDir . '/pelnomocnictwa_expiry_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ],
        'gdpr_clauses_review' => [
            'file'     => $cronDir . '/gdpr_clauses_review.php',
            'interval' => 86400,        // raz dziennie (przypomnienie raz na termin przeglądu)
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ],
        'backup_monitor' => [
            'file'     => $cronDir . '/backup_monitor.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [7, 9],       // rano — RPO + weryfikacja integralności kopii
        ],
        'termination_milestone_reminder' => [
            'file'     => $cronDir . '/termination_milestone_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ],
        'szkolenia_notifier' => [
            'file'     => $cronDir . '/agents/szkolenia_notifier.php',
            'interval' => 86400,        // sprawdza codziennie, wysyła raz w miesiącu (po 25. dniu)
            'schedule' => [9, 11],      // między 9:00 a 11:00
        ],
        'volunteer_account_reminder' => [
            'file'     => $cronDir . '/volunteer_account_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ],
        'guardian_consent_renewal' => [
            'file'     => $cronDir . '/guardian_consent_renewal.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [7, 9],       // między 7:00 a 9:00
        ],
        'minor_volunteer_periodic_verification' => [
            'file'     => $cronDir . '/minor_volunteer_periodic_verification.php',
            'interval' => 86400,        // raz dziennie (cykl 90-dniowy sprawdzany wewnątrz)
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ],
        'never_logged_in_reminder' => [
            'file'     => $cronDir . '/agents/never_logged_in_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [9, 11],      // między 9:00 a 11:00
        ],
        'zus_reminder' => [
            'file'     => $cronDir . '/zus_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ],
        'ezd_reminder' => [
            'file'     => $cronDir . '/ezd_deadline_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [7, 9],       // między 7:00 a 9:00
        ],
        'postivo_status_sync' => [
            'file'     => $cronDir . '/postivo_status_sync.php',
            'interval' => 10800,        // co 3 godziny — Postivo przetwarza asynchronicznie
        ],
        'postivo_dispatch_batch' => [
            'file'     => $cronDir . '/postivo_dispatch_batch.php',
            'interval' => 86400,        // raz dziennie — zbiorcza wysyłka wpisów RPW-W
            'schedule' => [16, 17],     // zakolejkowanych w ciągu dnia (status "Przetwarzanie - Postivo")
        ],
        'process_m365_queue' => [
            'file'     => $cronDir . '/process_m365_queue.php',
            'interval' => 300,          // co 5 min
        ],
        'sync_m365_reverse' => [
            'file'     => $cronDir . '/sync_m365_reverse.php',
            'interval' => 3600,         // co godzinę
            'schedule' => [1, 23],
        ],
        'sync_ldap' => [
            'file'     => $cronDir . '/sync_ldap.php',
            'interval' => 3600,         // co godzinę
            'schedule' => [1, 23],
        ],
        'k30_m365_expire' => [
            'file'     => $cronDir . '/k30_m365_expire.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [2, 5],       // między 2:00 a 5:00
        ],
        'k30_ti_billing_autoissue' => [
            'file'     => $cronDir . '/k30_ti_billing_autoissue.php',
            'interval' => 86400,        // raz dziennie (skrypt działa tylko w pierwszy dzień miesiąca, za miesiąc poprzedni)
            'schedule' => [21, 23],     // wieczorem pierwszego dnia miesiąca
        ],
        'edok_monthly_archive' => [
            'file'     => $cronDir . '/edok_monthly_archive.php',
            'interval' => 86400,        // raz dziennie (skrypt działa tylko w pierwszy dzień miesiąca, za miesiąc poprzedni — Uchwała 5/2026 §7)
            'schedule' => [21, 23],     // wieczorem pierwszego dnia miesiąca
        ],
        'edok_queue_mail' => [
            'file'     => $cronDir . '/edok_queue_mail.php',
            'interval' => 300,          // co 5 min — załączniki z e-maili do Kolejki do opisu EODoK
            'schedule' => [6, 23],
        ],
        'ti_lesson_reminders' => [
            'file'     => $cronDir . '/ti_lesson_reminders.php',
            'interval' => 86400,        // raz dziennie — SMS o zajęciach zaplanowanych na jutro
            'schedule' => [8, 10],
        ],
        'backup' => [
            'file'     => $cronDir . '/agents/backup.php',
            'interval' => 14400,        // co 4h — backup przyrostowy (lokalny)
        ],
        'sp_backup_incremental' => [
            'file'     => $cronDir . '/agents/sp_backup_incremental.php',
            'interval' => 21600,        // co 6h — backup przyrostowy na SharePoint
        ],
        'sp_backup_full' => [
            'file'     => $cronDir . '/agents/sp_backup_full.php',
            'interval' => 86400,        // raz dziennie — pełny backup systemu na SharePoint
            'schedule' => [1, 3],       // w nocy
        ],
        'sync_crm_volunteers' => [
            'file'     => $cronDir . '/sync_crm_volunteers.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [3, 5],       // między 3:00 a 5:00
        ],
        'outlook_calendar_sync' => [
            'file'     => $cronDir . '/outlook_calendar_sync.php',
            'interval' => 3600,         // co godzinę
            'schedule' => [6, 23],      // w godzinach pracy
        ],
        'sync_users_to_test' => [
            'file'     => $cronDir . '/sync_users_to_test.php',
            'interval' => 3600,         // co godzinę
        ],
        'crm_inbox_watch' => [
            'file'     => $cronDir . '/crm_inbox_watch.php',
            'interval' => 600,          // co 10 min
            'schedule' => [6, 23],      // w godzinach pracy
        ],
        'poczta_dispatch' => [
            'file'     => $cronDir . '/poczta_dispatch.php',
            'interval' => 600,          // co 10 min — generuje zadania skanowania skrzynek
            'schedule' => [6, 23],      // w godzinach pracy
        ],
        'poczta_worker' => [
            'file'     => $cronDir . '/poczta_worker.php',
            'interval' => 60,           // co minutę — konsumuje kolejkę RabbitMQ „poczta_skanowanie"
            'schedule' => [6, 23],
        ],
        'crm_office_push' => [
            'file'     => $cronDir . '/crm_office_push.php',
            'interval' => 1800,         // co 30 min — dosyła kontakty do książki adresowej Outlooka
            'schedule' => [6, 22],
        ],
        'crm_office_mail' => [
            'file'     => $cronDir . '/crm_office_mail.php',
            'interval' => 600,          // co 10 minut — dociąga korespondencję do kartotek
            'schedule' => [6, 22],
            'args'     => '--limit=25',
        ],
        'crm_offers' => [
            'file'     => $cronDir . '/crm_offers_agent.php',
            'interval' => 86400,        // raz dziennie — wygaszanie ofert, follow-up, brak potwierdzeń
            'schedule' => [7, 9],       // między 7:00 a 9:00
        ],
        'crm_assign_owners' => [
            'file'     => $cronDir . '/crm_assign_owners.php',
            'interval' => 86400,        // raz dziennie — opiekunowie dla nowych kartotek
            'schedule' => [5, 7],
            'args'     => '--apply',
        ],
        'crm_sync_partners' => [
            'file'     => $cronDir . '/crm_sync_partners.php',
            'interval' => 86400,        // raz dziennie — grupa „Współpracownicy" wg typów umów
            'schedule' => [3, 5],
            'args'     => '--apply',
        ],
        'crm_volunteers_expired' => [
            'file'     => $cronDir . '/crm_volunteers_expired.php',
            'interval' => 604800,       // raz w tygodniu — porządkuje grupę wolontariuszy
            'schedule' => [4, 6],
            'args'     => '--apply',
        ],
        'crm_retention' => [
            'file'     => $cronDir . '/crm_retention.php',
            'interval' => 604800,       // raz w tygodniu — przegląd retencji danych osobowych
            'schedule' => [5, 7],       // agent tylko OZNACZA, anonimizuje człowiek
        ],
        'crm_email_kinds' => [
            'file'     => $cronDir . '/crm_email_kinds.php',
            'interval' => 86400,        // raz dziennie — nowe adresy z importów i skrzynki
            'schedule' => [4, 6],
            'args'     => '--limit=300',
        ],
        'crm_consents_expiring' => [
            'file'     => $cronDir . '/crm_consents_expiring.php',
            'interval' => 604800,       // raz w tygodniu — zgody z ograniczoną ważnością
            'schedule' => [6, 8],
        ],
        'crm_cases_due' => [
            'file'     => $cronDir . '/crm_cases_due.php',
            'interval' => 86400,        // raz dziennie — terminy spraw i naruszenia SLA
            'schedule' => [7, 9],       // między 7:00 a 9:00
        ],
        'crm_inbox_autoabandon' => [
            'file'     => $cronDir . '/crm_inbox_autoabandon.php',
            'interval' => 86400,        // raz dziennie — porzuca wiadomości bez akcji starsze niż 3 mies.
            'schedule' => [3, 5],       // między 3:00 a 5:00
        ],
        'sprawdz_konto_log_cleanup' => [
            'file'     => $cronDir . '/sprawdz_konto_log_cleanup.php',
            'interval' => 86400,        // raz dziennie — retencja logów (90 dni)
            'schedule' => [3, 5],       // między 3:00 a 5:00
        ],
        'ezd_mail_ingest' => [
            'file'     => $cronDir . '/ezd_mail_ingest.php',
            'interval' => 300,          // co 5 min — match [EZD:ZNAK] i Inbox Ogólny
            'schedule' => [6, 23],      // w godzinach pracy (M365 Graph jest tu ograniczony)
        ],
        'betterfly_sync' => [
            'file'     => $cronDir . '/betterfly_sync.php',
            'interval' => 10800,        // co 3 godziny — statusy płatności faktur Betterfly + dokończenie zatwierdzeń
            'schedule' => [6, 23],      // w godzinach pracy
        ],
        'betterfly_purchase_import' => [
            'file'     => $cronDir . '/betterfly_purchase_import.php',
            'interval' => 3600,         // co godzinę — nowe faktury zakupu do obiegu EODoK (jeśli włączone)
            'schedule' => [6, 23],      // w godzinach pracy
        ],
        'redmine_sync' => [
            'file'     => $cronDir . '/redmine_sync.php',
            'interval' => 600,          // co 10 min — notatki i statusy z Redmine → Helpdesk
            'schedule' => [6, 23],      // w godzinach pracy
        ],
    ];

    // Agenci aktywni tylko w środowisku Docker
    if (file_exists('/.dockerenv')) {
        $AGENTS['gdpr_statement_reminder'] = [
            'file'     => $cronDir . '/gdpr_statement_reminder.php',
            'interval' => 86400,        // raz dziennie
            'schedule' => [8, 10],      // między 8:00 a 10:00
        ];
    }
    return $AGENTS;
}
