<?php
/**
 * modules/adminRegistry/lib/adminRegistry.php — rejestr pozycji panelu administratora (jedno źródło prawdy).
 *
 * adminRegistry($cnt) zwraca grupy pulpitu admin/index.php: 'Nazwa grupy' => ['icon','color','items'=>[
 *   ['icon','label','url','tile'?,'badge'?,'badge_type'?,'danger'?,'kw'?], …]].
 * Konsumenci: pulpit admina (kafle i lista) oraz paleta poleceń Ctrl+K (includes/menu.php, węzeł „Admin”) —
 * dzięki temu nowa pozycja dopisana TUTAJ pojawia się w obu miejscach. $cnt = liczniki do plakietek (brak klucza = 0).
 */
function adminRegistry(array $cnt = []): array
{
    $cnt += array_fill_keys(['applications', 'audit_today', 'expiring_30', 'expiring_7', 'log_today', 'mail_failed', 'pending_avatars', 'res_pending', 'ti_msgs', 'users'], 0);
    return [

    'Konta i dostępy' => [
        'icon'  => 'bi-shield-person',
        'color' => '#a20025',
        'items' => [
            ['icon'=>'bi-people',               'label'=>'Użytkownicy',              'url'=>'/admin/users.php',              'tile'=>true, 'badge'=>$cnt['users'] ?: null, 'badge_type'=>'secondary', 'kw'=>'konta loginy hasła'],
            ['icon'=>'bi-shield-lock',          'label'=>'Role i uprawnienia',       'url'=>'/admin/roles.php',              'tile'=>true, 'kw'=>'role uprawnienia dostęp'],
            ['icon'=>'bi-door-open',            'label'=>'Metody logowania',         'url'=>'/admin/login_settings.php',     'tile'=>true, 'kw'=>'logowanie 2fa office'],
            ['icon'=>'bi-envelope-paper',       'label'=>'E-mail: nowe dane logowania TI', 'url'=>'/admin/tiLoginyMail.php',  'kw'=>'login loginy nowy format kursant rodzic mail wysyłka'],
            ['icon'=>'bi-person-badge',         'label'=>'Podszywanie',              'url'=>'/admin/impersonate.php',        'tile'=>true, 'kw'=>'impersonacja zaloguj jako'],
            ['icon'=>'bi-person-bounding-box',  'label'=>'Zdjęcia profilowe',        'url'=>'/admin/avatars.php',            'tile'=>true, 'badge'=>$cnt['pending_avatars'] ?: null, 'badge_type'=>'warning', 'kw'=>'awatary zdjęcia akceptacja'],
            ...(org_setting('allow_standalone_vol_accounts') === '1' ? [
                ['icon'=>'bi-person-plus',      'label'=>'Wolontariusze bez umowy',  'url'=>'/admin/volunteer_accounts.php', 'kw'=>'konta wolontariuszy samodzielne'],
            ] : []),
            ['icon'=>'bi-key',                  'label'=>'Kody IKA',                 'url'=>'/admin/manage_cpc.php',         'kw'=>'ika kody cpc'],
            ['icon'=>'bi-patch-check-fill',     'label'=>'Certyfikaty X.509',        'url'=>'/admin/x509_login.php',         'kw'=>'x509 szocert certyfikat logowanie'],
            ['icon'=>'bi-shield-lock',          'label'=>'SAML Identity Provider',   'url'=>'/admin/saml.php',               'kw'=>'saml sso idp'],
            ['icon'=>'bi-person-lines-fill',    'label'=>'Pola profilu',             'url'=>'/admin/profile_fields.php',     'kw'=>'profil pola katalog'],
        ],
    ],

    'Ustawienia' => [
        'icon'  => 'bi-sliders',
        'color' => '#0050ef',
        'items' => [
            ['icon'=>'bi-building',             'label'=>'Dane organizacji',         'url'=>'/admin/org_settings.php',       'tile'=>true, 'kw'=>'organizacja nazwa krs logo branding'],
            ['icon'=>'bi-toggles2',             'label'=>'Moduły',                   'url'=>'/admin/modules_settings.php',   'tile'=>true, 'kw'=>'włączanie modułów'],
            ['icon'=>'bi-grid-3x3-gap-fill',    'label'=>'Widoczność modułów per rola','url'=>'/admin/module_perms.php',     'tile'=>true, 'kw'=>'moduły role widoczność'],
            ['icon'=>'bi-layout-sidebar',       'label'=>'Konfiguracja menu',        'url'=>'/admin/menu_config.php',        'tile'=>true, 'kw'=>'menu nawigacja'],
            ['icon'=>'bi-stars',                'label'=>'Ustawienia AI',            'url'=>'/admin/ai_settings.php',        'tile'=>true, 'kw'=>'ai asystent'],
            ['icon'=>'bi-calendar-event-fill',  'label'=>'Ustawienia wydarzeń',      'url'=>'/admin/events_settings.php',    'kw'=>'wydarzenia'],
            ['icon'=>'bi-building-gear',        'label'=>'Wirtualne biurko',         'url'=>'/admin/ezd_settings.php',       'kw'=>'ezd ustawienia biurko'],
            ['icon'=>'bi-shield-check',         'label'=>'EU DSS (walidacja eIDAS)', 'url'=>'/admin/dss_settings.php',       'kw'=>'dss eidas podpis walidacja'],
            ['icon'=>'bi-diagram-2',            'label'=>'Procesy EZD (workflow)',   'url'=>'/admin/ezd_workflows.php',      'kw'=>'ezd bpm proces'],
            ['icon'=>'bi-diagram-3',            'label'=>'Workflow akceptacji',      'url'=>'/admin/approval_workflows.php', 'kw'=>'akceptacja zatwierdzanie'],
            ['icon'=>'bi-ui-checks-grid',       'label'=>'SelfService',              'url'=>'/onboarding/settings.php',      'kw'=>'onboarding selfservice'],
            ['icon'=>'bi-kanban',               'label'=>'Obszary zadań',            'url'=>'/admin/tasks_workspaces.php',   'kw'=>'zadania obszary'],
            ['icon'=>'bi-tag',                  'label'=>'Tagi zadań',               'url'=>'/admin/tasks_tags.php',         'kw'=>'zadania tagi'],
            ['icon'=>'bi-book-half',            'label'=>'Słownik Centralny',        'url'=>'/modules/dictionary/router.php','tile'=>true, 'kw'=>'słowniki listy wyboru instytucje dotujące kategorie import eksport'],
            ['icon'=>'bi-ui-checks',            'label'=>'Pola w formularzach',      'url'=>'/admin/form_fields.php',        'kw'=>'wymagane pola formularze'],
            ['icon'=>'bi-building-heart',       'label'=>'Zasady i Wprowadzenie',    'url'=>'/admin/org_rules.php',          'kw'=>'zasady wprowadzenie e-learning'],
        ],
    ],

    'Integracje' => [
        'icon'  => 'bi-plug',
        'color' => '#00807e',
        'items' => [
            ['icon'=>'bi-microsoft',            'label'=>'Microsoft 365',            'url'=>'/admin/m365_settings.php',      'tile'=>true, 'kw'=>'m365 office graph entra'],
            ['icon'=>'bi-cloud-upload',         'label'=>'SharePoint',               'url'=>'/admin/sharepoint_settings.php','tile'=>true, 'kw'=>'sharepoint pliki'],
            ['icon'=>'bi-credit-card-2-front',  'label'=>'Portal płatności (/platnosci)', 'url'=>'/platnosci/admin.php',     'tile'=>true, 'kw'=>'płatności portal nrb przelew'],
            ['icon'=>'bi-cloud-arrow-up',       'label'=>'Magazyn plików / ownCloud','url'=>'/admin/owncloud_settings.php',  'tile'=>true, 'kw'=>'owncloud webdav magazyn'],
            ['icon'=>'bi-key',                  'label'=>'API i webhooki',           'url'=>'/admin/api_manage.php',         'tile'=>true, 'kw'=>'api klucze webhooki rest'],
            ['icon'=>'bi-plug',                 'label'=>'Integracje',               'url'=>'/admin/integrations.php',       'tile'=>true, 'kw'=>'integracje przegląd'],
            ['icon'=>'bi-key-fill',             'label'=>'Kody dostępowe M365',      'url'=>'/admin/ms_edu_codes.php',       'kw'=>'m365 kody lccc edu'],
            ['icon'=>'bi-people-fill',          'label'=>'Konta M365 Edu — hurtowo', 'url'=>'/modules/ms_edu_bulk/index.php','kw'=>'m365 edu konta hurtowo uczniowie'],
            ['icon'=>'bi-magic',                'label'=>'Kreator kont M365 z listy (XLS)', 'url'=>'/modules/ms_edu_bulk/kreator.php', 'kw'=>'m365 kreator xls excel import'],
            ['icon'=>'bi-person-gear',          'label'=>'Konta EDU — zarządzanie',  'url'=>'/modules/ms_edu_bulk/konta.php','kw'=>'m365 edu zarządzanie offboarding mfa'],
            ['icon'=>'bi-cloud-arrow-down',     'label'=>'Pliki umów z SharePointa', 'url'=>'/admin/sp_contract_files.php',  'kw'=>'sharepoint pliki umów'],
            ['icon'=>'bi-file-earmark-arrow-down','label'=>'Skany z SharePointa → umowy', 'url'=>'/admin/sp_contract_match.php', 'kw'=>'sharepoint skany dopasowanie'],
            ['icon'=>'bi-credit-card',          'label'=>'Płatności / Stripe',       'url'=>'/admin/stripe_settings.php',    'kw'=>'stripe płatności'],
            ['icon'=>'bi-wallet2',              'label'=>'Płatności / PayU',         'url'=>'/admin/payu_settings.php',      'kw'=>'payu płatności'],
            ['icon'=>'bi-wallet2',              'label'=>'Płatności / Przelewy24',   'url'=>'/admin/p24_settings.php',       'kw'=>'p24 przelewy24 płatności'],
            ['icon'=>'bi-receipt',              'label'=>'Comarch Betterfly',        'url'=>'/admin/betterfly_settings.php', 'kw'=>'betterfly faktury'],
            ['icon'=>'bi-receipt',              'label'=>'Faktury / Comarch (API)',  'url'=>'/admin/comarch_settings.php',   'kw'=>'comarch faktury api'],
            ['icon'=>'bi-calendar2-check',      'label'=>'Szkolenia (TidyCal)',      'url'=>'/admin/tidycal_settings.php',   'kw'=>'tidycal szkolenia'],
            ['icon'=>'bi-check2-square',        'label'=>'Zadania / Nozbe',          'url'=>'/admin/nozbe_settings.php',     'kw'=>'nozbe zadania'],
            ['icon'=>'bi-kanban',               'label'=>'Redmine (Helpdesk)',       'url'=>'/admin/redmine_settings.php',   'kw'=>'redmine helpdesk'],
            ['icon'=>'bi-ticket-perforated',    'label'=>'Helpdesk — ustawienia',    'url'=>'/helpdesk/admin.php',           'kw'=>'helpdesk ustawienia operatorzy skrzynki e-mail csat sla godziny pracy numeracja migracja numerów zgłoszeń'],
            ['icon'=>'bi-diagram-3',            'label'=>'Helpdesk — reguły automatyczne','url'=>'/modules/helpdeskRules/router.php','kw'=>'helpdesk reguły automatyzacje wyzwalacze kroki sla'],
            ['icon'=>'bi-signpost-split',       'label'=>'Helpdesk — statusy własne kategorii','url'=>'/modules/helpdeskStatuses/router.php','kw'=>'helpdesk statusy własne kategorii podstatusy etapy wnioski am'],
            ['icon'=>'bi-card-text',            'label'=>'Helpdesk — gotowe odpowiedzi i makra','url'=>'/helpdesk/admin_macros.php','kw'=>'helpdesk makra przebiegi gotowe odpowiedzi'],
            ['icon'=>'bi-envelope-gear',        'label'=>'Helpdesk — szablony e-mail','url'=>'/helpdesk/admin_email_templates.php','kw'=>'helpdesk szablony maili powiadomienia'],
        ],
    ],

    'Kontrola i CRON' => [
        'icon'  => 'bi-clipboard2-pulse',
        'color' => '#c25100',
        'items' => [
            ['icon'=>'bi-clipboard2-pulse',     'label'=>'Kontrola IT (pulpit stanu)','url'=>'/modules/it_control/index.php','tile'=>true, 'kw'=>'kontrola it audyt monitoring'],
            ['icon'=>'bi-journal-check',        'label'=>'Kontrola Dzienników (logi)','url'=>'/modules/log_control/index.php','tile'=>true, 'kw'=>'kontrola dzienników logi anomalie'],
            ['icon'=>'bi-calendar2-range',      'label'=>'Harmonogram CRON (edytor)','url'=>'/admin/cron_dispatcher.php',    'tile'=>true, 'kw'=>'cron dispatcher harmonogram agenci'],
            ['icon'=>'bi-toggles',              'label'=>'Status systemu',           'url'=>'/admin/system_status.php',      'tile'=>true, 'kw'=>'status system'],
            ['icon'=>'bi-journal-text',         'label'=>'Dziennik zdarzeń',         'url'=>'/admin/log.php',                'tile'=>true, 'badge'=>$cnt['log_today'] ?: null, 'badge_type'=>'info', 'kw'=>'log logowania auth'],
            ['icon'=>'bi-shield-exclamation',   'label'=>'Audit log',                'url'=>'/admin/audit_log.php',          'tile'=>true, 'badge'=>$cnt['audit_today'] ?: null, 'badge_type'=>'secondary', 'kw'=>'audyt admin'],
            ['icon'=>'bi-clock-history',        'label'=>'Konfiguracja CRON',        'url'=>'/admin/cron_setup.php',         'kw'=>'cron token url'],
            ['icon'=>'bi-journal-text',         'label'=>'Przeglądarka logów',       'url'=>'/admin/logs_global.php',        'kw'=>'logi php błędy'],
            ['icon'=>'bi-shield-check',         'label'=>'Dziennik audytu (hologramy, karty)','url'=>'/modules/audit_logs/index.php', 'kw'=>'audyt hologramy karty nfc'],
            ['icon'=>'bi-info-circle',          'label'=>'Informacje o systemie',    'url'=>'/admin/system_info.php',        'kw'=>'php wersja serwer'],
            ['icon'=>'bi-lightning-charge',     'label'=>'Redis (cache)',            'url'=>'/admin/redis.php',              'kw'=>'redis cache'],
        ],
    ],

    'Komunikacja' => [
        'icon'  => 'bi-envelope',
        'color' => '#c0006a',
        'items' => [
            ['icon'=>'bi-send-check',           'label'=>'Kolejka e-mail',           'url'=>'/admin/mail_queue.php',         'tile'=>true, 'badge'=>$cnt['mail_failed'] ?: null, 'badge_type'=>'danger', 'kw'=>'maile błędy kolejka'],
            ['icon'=>'bi-megaphone',            'label'=>'Komunikaty / ogłoszenia',  'url'=>'/komunikaty/index.php',         'tile'=>true, 'kw'=>'ogłoszenia komunikaty'],
            ['icon'=>'bi-envelope-paper',       'label'=>'Maile systemowe',          'url'=>'/admin/email_templates.php',    'tile'=>true, 'kw'=>'szablony maili'],
            ['icon'=>'bi-chat-dots',            'label'=>'SMS',                      'url'=>'/admin/sms_settings.php',       'tile'=>true, 'kw'=>'sms smsapi serwersms'],
            ['icon'=>'bi-megaphone-fill',       'label'=>'Nowe ogłoszenie',          'url'=>'/komunikaty/compose.php',       'kw'=>'ogłoszenie nowe'],
            ['icon'=>'bi-gear',                 'label'=>'Zarządzaj ogłoszeniami',   'url'=>'/komunikaty/manage.php',        'kw'=>'ogłoszenia zarządzanie'],
            ['icon'=>'bi-bell',                 'label'=>'Powiadomienia e-mail',     'url'=>'/admin/msg_settings.php',       'kw'=>'powiadomienia'],
            ['icon'=>'bi-tags',                 'label'=>'Typy wiadomości',          'url'=>'/admin/message_types.php',      'kw'=>'wiadomości typy'],
            ['icon'=>'bi-envelope-check',       'label'=>'Masowe maile',             'url'=>'/admin/bulk_email.php',         'kw'=>'masowa wysyłka'],
            ['icon'=>'bi-whatsapp',             'label'=>'WhatsApp',                 'url'=>'/admin/whatsapp_settings.php',  'kw'=>'whatsapp'],
            ['icon'=>'bi-whatsapp',             'label'=>'WhatsApp — grupa',         'url'=>'/admin/whatsapp_group.php',     'kw'=>'whatsapp grupa'],
            ['icon'=>'bi-envelope-at',          'label'=>'Postivo',                  'url'=>'/admin/postivo_settings.php',   'kw'=>'postivo list poczta'],
            ['icon'=>'bi-envelope-heart',       'label'=>'Wysyłka powitalna',        'url'=>'/admin/resend_welcome.php',     'kw'=>'powitanie mail'],
        ],
    ],

    'Dokumenty i umowy' => [
        'icon'  => 'bi-file-earmark-text',
        'color' => '#6a00ff',
        'items' => [
            ['icon'=>'bi-inbox',                'label'=>'Pisma / wnioski',          'url'=>'/admin/applications.php',       'tile'=>true, 'badge'=>$cnt['applications'] ?: null, 'badge_type'=>'danger', 'kw'=>'wnioski pisma'],
            ['icon'=>'bi-file-earmark-text',    'label'=>'Wzory dokumentów',         'url'=>'/admin/contract_templates.php', 'tile'=>true, 'kw'=>'szablony umów wzory'],
            ['icon'=>'bi-calendar-x',           'label'=>'Wygasające umowy',         'url'=>'/admin/contract_expiry.php',    'tile'=>true, 'badge'=>$cnt['expiring_7'] ?: ($cnt['expiring_30'] ?: null), 'badge_type'=>$cnt['expiring_7'] ? 'danger' : 'warning', 'kw'=>'wygasanie koniec umowy'],
            ['icon'=>'bi-calendar3',            'label'=>'Kalendarz umów',           'url'=>'/admin/calendar.php',           'tile'=>true, 'kw'=>'kalendarz'],
            ['icon'=>'bi-ui-checks-grid',       'label'=>'Typy wniosków',            'url'=>'/admin/application_types.php',  'kw'=>'wnioski typy'],
            ['icon'=>'bi-printer',              'label'=>'Wzory wydruków',           'url'=>'/admin/print_templates.php',    'kw'=>'wydruki szablony'],
            ['icon'=>'bi-envelope',             'label'=>'Wzory kopert',             'url'=>'/admin/envelope_templates.php', 'kw'=>'koperty dl c5 c4'],
            ['icon'=>'bi-pen-fill',             'label'=>'Autenti eSign',            'url'=>'/admin/autenti_settings.php',   'kw'=>'autenti podpis'],
            ['icon'=>'bi-pen-fill',             'label'=>'DocuSign eSign',           'url'=>'/admin/docusign_settings.php',  'kw'=>'docusign podpis'],
            ['icon'=>'bi-bar-chart-line',       'label'=>'Statystyki',               'url'=>'/reports/volunteer_stats.php',  'kw'=>'statystyki raporty wolontariusze'],
            ['icon'=>'bi-signpost-split',       'label'=>'Statusy umów',             'url'=>'/modules/contract_status/router.php?_route=/statusy', 'tile'=>true, 'kw'=>'statusy umów rozpiska grupy przejścia słownik'],
            ['icon'=>'bi-arrow-counterclockwise','label'=>'Status umowy — wycofanie', 'url'=>'/modules/contract_status/router.php', 'danger'=>true, 'kw'=>'cofnij status umowy'],
        ],
    ],

    'TI — panel dydaktyka' => [
        'icon'  => 'bi-easel2',
        'color' => '#76608a',
        'items' => [
            ['icon'=>'bi-easel2',               'label'=>'Panel dydaktyka (TI) — wyłączenia', 'url'=>'/karty30/ti/dydaktyk/wylaczenia.php', 'tile'=>true, 'kw'=>'ti przerwa komunikat wyłączenia blackout'],
            ['icon'=>'bi-envelope-exclamation', 'label'=>'Wiad. od prowadzących',    'url'=>'/admin/ti_admin_msgs.php',      'tile'=>true, 'badge'=>$cnt['ti_msgs'] ?: null, 'badge_type'=>'danger', 'kw'=>'wiadomości prowadzący kierownictwo ti'],
            ['icon'=>'bi-person-badge',         'label'=>'Dyżury kierownika / Zarządu (DK)', 'url'=>'/modules/ti_duty/index.php', 'tile'=>true, 'kw'=>'dyżury dk grafik zarząd ti'],
        ],
    ],

    'CRM' => [
        'icon'  => 'bi-diagram-2-fill',
        'color' => '#007a00',
        'items' => [
            ['icon'=>'bi-person-lines-fill',    'label'=>'Ustawienia CRM',           'url'=>'/crm/settings/',                'tile'=>true, 'kw'=>'crm ustawienia'],
            ['icon'=>'bi-window-split',         'label'=>'Formularze webowe',        'url'=>'/crm/form/manage.php',          'tile'=>true, 'kw'=>'crm formularze www'],
            ['icon'=>'bi-database-gear',        'label'=>'Baza danych CRM',          'url'=>'/admin/crm_database.php',       'tile'=>true, 'kw'=>'crm baza'],
            ['icon'=>'bi-bookmark-fill',        'label'=>'Statusy CRM',              'url'=>'/crm/settings/statuses.php',    'kw'=>'crm statusy'],
            ['icon'=>'bi-layers',               'label'=>'Grupy pól',                'url'=>'/crm/settings/field_groups.php','kw'=>'crm pola grupy'],
            ['icon'=>'bi-list-columns',         'label'=>'Pola formularza',          'url'=>'/crm/settings/fields.php',      'kw'=>'crm pola'],
            ['icon'=>'bi-diagram-2',            'label'=>'Dostęp CRM-only',          'url'=>'/admin/crm_access.php',         'kw'=>'crm dostęp'],
            ['icon'=>'bi-geo-alt-fill',         'label'=>'Import TERYT',             'url'=>'/admin/teryt_import.php',       'kw'=>'teryt gminy import'],
            ['icon'=>'bi-check2-square',        'label'=>'Nozbe',                    'url'=>'/crm/settings/nozbe.php',       'kw'=>'crm nozbe'],
        ],
    ],

    'Zasoby i rezerwacje' => [
        'icon'  => 'bi-calendar-check',
        'color' => '#825a2c',
        'items' => [
            ['icon'=>'bi-calendar-check',       'label'=>'Rezerwacje zasobów (SRS)', 'url'=>'/modules/srs/admin/',           'tile'=>true, 'badge'=>$cnt['res_pending'] ?: null, 'badge_type'=>'warning', 'kw'=>'srs rezerwacje sale'],
            ['icon'=>'bi-box',                  'label'=>'Zasoby',                   'url'=>'/modules/srs/admin/resources.php', 'tile'=>true, 'kw'=>'srs zasoby sprzęt'],
            ['icon'=>'bi-tags',                 'label'=>'Kategorie zasobów',        'url'=>'/modules/srs/admin/categories.php','kw'=>'srs kategorie'],
        ],
    ],

    'Strategia i rozwój' => [
        'icon'  => 'bi-bullseye',
        'color' => '#5a6f52',
        'items' => [
            ['icon'=>'bi-bullseye',             'label'=>'Strategia Rozwoju NGO',    'url'=>'/strategy/index.php',           'tile'=>true, 'kw'=>'strategia cele'],
            ['icon'=>'bi-globe2',               'label'=>'Sfery Pożytku Publicznego','url'=>'/strategy/spheres/index.php',   'tile'=>true, 'kw'=>'sfery pożytek'],
            ['icon'=>'bi-bar-chart-line',       'label'=>'Raporty strategiczne',     'url'=>'/strategy/reports/index.php',   'tile'=>true, 'kw'=>'raporty strategia'],
        ],
    ],

    'EOD Dokumentów Księgowych' => [
        'icon'  => 'bi-file-earmark-check',
        'color' => '#0f6fa8',
        'items' => [
            ['icon'=>'bi-gear',                 'label'=>'Ustawienia (baza, MPK)',   'url'=>'/admin/ksiegowosc_settings.php','tile'=>true, 'kw'=>'kdok księgowość mpk'],
            ['icon'=>'bi-receipt-cutoff',       'label'=>'KSeF — ustawienia',        'url'=>'/admin/kdok_ksef_settings.php', 'tile'=>true, 'kw'=>'ksef faktury'],
            ['icon'=>'bi-grid-3x2',             'label'=>'Macierz uprawnień',        'url'=>'/admin/kdok_matrix.php',        'tile'=>true, 'kw'=>'kdok uprawnienia macierz'],
            ['icon'=>'bi-shield-lock',          'label'=>'Role dostępu',             'url'=>'/admin/ksiegowosc_roles.php',   'kw'=>'kdok role'],
            ['icon'=>'bi-patch-check',          'label'=>'Certyfikaty X.509',        'url'=>'/admin/kdok_certs.php',         'kw'=>'kdok certyfikaty x509'],
            ['icon'=>'bi-cloud-arrow-up',       'label'=>'eArchiwum (FTP / R2)',     'url'=>'/admin/kdok_archive_settings.php', 'kw'=>'kdok archiwum ftp r2'],
            ['icon'=>'bi-arrow-repeat',         'label'=>'Wyczyść stare PDF',        'url'=>'/admin/kdok_cleanup_run.php',   'kw'=>'kdok czyszczenie pdf'],
            ['icon'=>'bi-trash3',               'label'=>'Wyczyść dane obiegu',      'url'=>'/admin/kdok_clear.php',         'danger'=>true, 'kw'=>'kdok czyszczenie obieg'],
        ],
    ],

    'System i narzędzia' => [
        'icon'  => 'bi-tools',
        'color' => '#1d1d1d',
        'items' => [
            ['icon'=>'bi-arrow-repeat',         'label'=>'Aktualizacja systemu',     'url'=>'/upgrade.php',                  'tile'=>true, 'kw'=>'upgrade aktualizacja'],
            ['icon'=>'bi-archive',              'label'=>'Kopie zapasowe',           'url'=>'/admin/backups.php',            'tile'=>true, 'kw'=>'backup kopie zapasowe usuń poprzedni miesiąc czyszczenie retencja przywracanie'],
            ['icon'=>'bi-database-fill-gear',   'label'=>'Narzędzia bazy danych',    'url'=>'/admin/db_tools.php',           'tile'=>true, 'kw'=>'baza sqlite'],
            ['icon'=>'bi-git',                  'label'=>'Wersja i historia zmian',  'url'=>'/admin/version.php',            'tile'=>true, 'kw'=>'wersja changelog'],
            ['icon'=>'bi-shield-lock',          'label'=>'Certyfikat i licencja',    'url'=>'/admin/app_license.php',        'kw'=>'certyfikat licencja'],
            ['icon'=>'bi-database-gear',        'label'=>'Zarządzanie migracjami',   'url'=>'/admin/migrations.php',         'kw'=>'migracje schemat'],
            ['icon'=>'bi-database-add',         'label'=>'Seedery (dane testowe)',   'url'=>'/seeder-test',                  'kw'=>'seedery dane testowe demo'],
            ['icon'=>'bi-link-45deg',           'label'=>'Krótkie linki',            'url'=>'/admin/short_urls.php',         'kw'=>'skracacz linki'],
            ['icon'=>'bi-rocket-takeoff',       'label'=>'Czyszczenie przed wdrożeniem','url'=>'/admin/clean_for_prod.php',  'danger'=>true, 'kw'=>'wdrożenie produkcja czyszczenie'],
            ['icon'=>'bi-trash3',               'label'=>'Wyczyść bazę',             'url'=>'/admin/clean_db.php',           'danger'=>true, 'kw'=>'czyszczenie bazy'],
        ],
    ],

];
}
