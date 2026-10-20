<?php
/**
 * admin/index.php — Panel Administratora
 *
 * Centralny dashboard wszystkich funkcji administracyjnych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
ika_require(APP_URL . '/admin/index.php', 3600);
$PAGE_TITLE = 'Administrator';

// ── Setup: utwórz administratora org ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create_org_admin') {
    csrf_check();
    require_once dirname(__DIR__) . '/includes/mail_queue.php';
    $adm_name  = trim($_POST['adm_name']  ?? '');
    $adm_email = trim($_POST['adm_email'] ?? '');
    $adm_pass  = bin2hex(random_bytes(6));
    $adm_errors = [];

    if (!$adm_name)                          $adm_errors[] = 'Podaj imię i nazwisko.';
    if (!filter_var($adm_email, FILTER_VALIDATE_EMAIL)) $adm_errors[] = 'Podaj prawidłowy e-mail.';
    if (!$adm_errors) {
        $exists = db_one("SELECT id FROM users WHERE email=?", [$adm_email]);
        if ($exists) $adm_errors[] = 'Użytkownik z tym e-mailem już istnieje.';
    }
    if (!$adm_errors) {
        $hash = password_hash($adm_pass, PASSWORD_BCRYPT);
        db_insert('users', ['name'=>$adm_name,'email'=>$adm_email,'password'=>$hash,'role'=>'admin','is_active'=>1]);

        $org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
        $url  = defined('APP_URL') ? APP_URL : '';
        $body = "Witaj {$adm_name},\n\n"
              . "Zostało dla Ciebie utworzone konto administratora w systemie Platforma NGO — {$org}.\n\n"
              . "Dane logowania:\n"
              . "  Adres systemu : {$url}\n"
              . "  E-mail        : {$adm_email}\n"
              . "  Hasło         : {$adm_pass}\n\n"
              . "Po pierwszym logowaniu zmień hasło w ustawieniach konta.\n\n"
              . "Pozdrawiamy,\n{$org}";

        mail_queue_add($adm_email, $adm_name, "Dostęp do systemu — {$org}", nl2br(htmlspecialchars($body)), $body);
        flash_set('success', "Administrator {$adm_name} ({$adm_email}) został utworzony. Dane logowania wysłano na podany adres e-mail.");
        header('Location: ' . APP_URL . '/admin/index.php'); exit;
    }
    flash_set('error', implode(' ', $adm_errors));
    header('Location: ' . APP_URL . '/admin/index.php#setup'); exit;
}

// ── Setup: ręczne zakończenie konfiguracji wstępnej ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'finish_setup') {
    csrf_check();
    org_setting_set('setup_complete', '1');
    org_setting_set('setup_completed_at', date('Y-m-d H:i:s'));
    org_setting_set('setup_completed_by', (string)(current_user()['id'] ?? ''));
    flash_set('success', 'Konfiguracja wstępna została oznaczona jako zakończona.');
    header('Location: ' . APP_URL . '/admin/index.php'); exit;
}

// ── Liczniki ──────────────────────────────────────────────────────────────────
$cnt = [];

$cnt['users'] = 0;
try { $cnt['users'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(); }
catch (\Throwable $e) {}

$cnt['applications'] = 0;
try { $cnt['applications'] = (int)db()->query("SELECT COUNT(*) FROM user_applications WHERE status='nowy'")->fetchColumn(); }
catch (\Throwable $e) {}

$cnt['mail_failed'] = 0;
try { $r = db_one("SELECT COUNT(*) AS c FROM mail_queue WHERE status='failed'"); $cnt['mail_failed'] = (int)($r['c'] ?? 0); }
catch (\Throwable $e) {}

$cnt['log_today'] = 0;
try { $r = db_one("SELECT COUNT(*) AS c FROM auth_log WHERE DATE(created_at)=DATE('now')"); $cnt['log_today'] = (int)($r['c'] ?? 0); }
catch (\Throwable $e) {}

$cnt['res_pending'] = 0;
try { $r = db_one("SELECT COUNT(*) AS c FROM resource_reservations WHERE status IN ('zlozony','pending_admin')"); $cnt['res_pending'] = (int)($r['c'] ?? 0); }
catch (\Throwable $e) {}

$cnt['expiring_7']  = 0;
$cnt['expiring_30'] = 0;
try {
    $today = date('Y-m-d');
    $d30   = date('Y-m-d', strtotime('+30 days'));
    $d7    = date('Y-m-d', strtotime('+7 days'));
    foreach (['umowy_wolontariat','umowy_zlecenie','umowy_dzielo','umowy_uslugi','umowy_inne'] as $_et) {
        try {
            $r30 = db_one("SELECT COUNT(*) AS c FROM {$_et} WHERE bezterminowa=0 AND data_zakonczenia IS NOT NULL AND data_zakonczenia >= ? AND data_zakonczenia <= ? AND status NOT IN ('zakończona','anulowana','rozwiązana')", [$today, $d30]);
            $r7  = db_one("SELECT COUNT(*) AS c FROM {$_et} WHERE bezterminowa=0 AND data_zakonczenia IS NOT NULL AND data_zakonczenia >= ? AND data_zakonczenia <= ? AND status NOT IN ('zakończona','anulowana','rozwiązana')", [$today, $d7]);
            $cnt['expiring_30'] += (int)($r30['c'] ?? 0);
            $cnt['expiring_7']  += (int)($r7['c'] ?? 0);
        } catch (\Throwable $e) {}
    }
} catch (\Throwable $e) {}

$cnt['audit_today'] = 0;
try { $r = db_one("SELECT COUNT(*) AS c FROM admin_audit_log WHERE DATE(created_at)=DATE('now','localtime')"); $cnt['audit_today'] = (int)($r['c'] ?? 0); }
catch (\Throwable $e) {}

$cnt['pending_avatars'] = 0;
try {
    require_once dirname(__DIR__) . '/includes/directory.php';
    directory_migrate();
    $cnt['pending_avatars'] = directory_pending_avatars_count();
} catch (\Throwable $e) {}

// ── Flaga konta serwisowego ───────────────────────────────────────────────────
$_is_service_account = (current_user()['email'] ?? '') === 'serwis@local';

$_saas_panel_url = null;
if ($_is_service_account) {
    if (defined('TENANT_SLUG') && TENANT_SLUG !== '') {
        $_saas_panel_url = preg_replace(
            '#/org/' . preg_quote(TENANT_SLUG, '#') . '$#', '',
            rtrim(APP_URL, '/')
        ) . '/saas-tenent/x/';
    } else {
        $_saas_panel_url = rtrim(APP_URL, '/') . '/saas-tenent/x/';
    }
}

// ── Wiadomości od prowadzących TI (licznik) ───────────────────────────────────
$cnt['ti_msgs'] = 0;
try {
    if (!function_exists('ti_admin_msg_unread_count')) { @require_once dirname(__DIR__) . '/includes/ti_messages.php'; }
    if (function_exists('ti_admin_msg_unread_count')) $cnt['ti_msgs'] = (int)ti_admin_msg_unread_count();
} catch (\Throwable $e) {}

/*
 * ── Grupy pulpitu (szata Metro) ──────────────────────────────────────────────
 * Zakładka „Admin” w menu nie ma listy rozwijanej — to jest jedyne miejsce ze
 * wszystkimi pozycjami admina. Każda grupa = sekcja z kaflami Start (pozycje 'tile')
 * i listą rzadziej używanych pozycji pod kaflami. Pozycje 'danger' zawsze na liście.
 * 'kw' = dodatkowe słowa dla filtra. Kolor grupy: odcień Metro z AA na białym tekście.
 */
$groups = [

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
            ['icon'=>'bi-archive',              'label'=>'Kopie zapasowe',           'url'=>'/admin/backups.php',            'tile'=>true, 'kw'=>'backup kopie'],
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

/** Słowa do filtra: etykieta + słowa kluczowe + nazwa grupy, małymi literami. */
$adm_keys = fn(array $it, string $grp): string => mb_strtolower($it['label'] . ' ' . ($it['kw'] ?? '') . ' ' . $grp);

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Pulpit admina w szacie Metro (warstwa wspólna: includes/szo_metro.php, grupa „admin”) ── */
html body .adx{max-width:1320px;font-family:var(--sm-font,"Segoe UI",system-ui,sans-serif)}
html body .adx-eyebrow{font-size:.7rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--sm-acc-d,#76001b);margin:0 0 .2rem}
html body .adx-title{font-size:2.6rem;font-weight:300;letter-spacing:-.02em;line-height:1.05;color:#1d1d1d;margin:0}
html body .adx-sub{font-size:.9rem;color:#5f5f5f;margin:.4rem 0 1.5rem}

/* Liczniki — płaskie szare kafle z dużą lekką liczbą */
html body .adx-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.25rem;margin-bottom:1.75rem}
html body .adx-stat{display:flex;flex-direction:column;gap:.15rem;padding:.75rem 1rem .8rem;background:#f2f2f2;color:#1d1d1d;text-decoration:none;border-left:6px solid transparent}
html body .adx-stat:hover{background:#e6e6e6;color:#1d1d1d}
html body .adx-stat-val{font-size:2rem;font-weight:300;line-height:1}
html body .adx-stat-lbl{font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#5f5f5f;display:flex;align-items:center;gap:.35rem}
html body .adx-stat.is-alert{border-left-color:#a20025}
html body .adx-stat.is-alert .adx-stat-val{color:#a20025}

/* Konfiguracja wstępna */
html body .adx-setup{background:#f2f2f2;border-left:6px solid #c25100;padding:1rem 1.25rem;margin-bottom:1.75rem}
html body .adx-setup-h{display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem}
html body .adx-setup-h strong{font-size:1.2rem;font-weight:300;color:#1d1d1d}
html body .adx-setup-h span{margin-left:auto;font-size:.8rem;color:#5f5f5f}
html body .adx-setup-bar{height:4px;background:#e1e1e1;margin-bottom:.85rem}
html body .adx-setup-bar>div{height:100%;background:#c25100}
html body .adx-setup-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.25rem}
html body .adx-setup-i{display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;font-size:.84rem;text-decoration:none;background:#fff;color:#8f3c00;border-left:3px solid #c25100}
html body .adx-setup-i.ok{color:#005700;border-left-color:#007a00}
html body .adx-setup-i.warn{color:#a20025;border-left-color:#a20025}
html body .adx-setup-i:hover{background:#e6e6e6}
html body .adx-setup-foot{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-top:.85rem;padding-top:.75rem;border-top:1px solid #e1e1e1;font-size:.8rem;color:#5f5f5f}
html body .adx-setup-new{background:#fff;padding:1rem;margin-top:.85rem;border-left:3px solid #c25100}

/* Pasek: filtr + skoki do sekcji */
html body .adx-bar{position:sticky;top:0;z-index:5;background:#fff;padding:.5rem 0 .6rem;margin-bottom:.5rem;border-bottom:1px solid #e1e1e1}
html body .adx-search{position:relative;max-width:460px}
html body .adx-search>.bi{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:#5f5f5f;pointer-events:none}
html body .adx-search input{width:100%;height:40px;padding:0 2.4rem 0 2.3rem;border:2px solid #8a8a8a;background:#fff;font-size:.95rem;color:#1d1d1d;outline:none}
html body .adx-search input:focus{border-color:var(--sm-acc,#a20025)}
html body .adx-search input::-webkit-search-cancel-button{display:none}
html body .adx-search-clear{position:absolute;right:0;top:0;width:40px;height:40px;border:0;background:none;color:#5f5f5f;display:none}
html body .adx-search-clear:hover{background:#f2f2f2;color:#1d1d1d}
html body .adx-jump{display:flex;flex-wrap:wrap;gap:0;margin-top:.5rem}
html body .adx-jump a{padding:.25rem .65rem;font-size:.8rem;color:#5f5f5f;text-decoration:none;border-bottom:3px solid transparent}
html body .adx-jump a:hover{color:#1d1d1d;background:#f2f2f2;border-bottom-color:var(--g)}
html body .adx-jump a.is-off{display:none}

/* Sekcje-grupy */
html body .adx-sec{padding:1.25rem 0 .5rem;scroll-margin-top:110px}
html body .adx-sec[hidden]{display:none}
html body .adx-sec-h{display:flex;align-items:center;gap:.6rem;margin:0 0 .75rem;font-size:1.45rem;font-weight:300;color:#1d1d1d;letter-spacing:-.01em}
html body .adx-sec-h .bi{color:var(--g);font-size:1.15rem}
html body .adx-sec-h small{font-size:.75rem;color:#8a8a8a;font-weight:400;letter-spacing:0}

/* Kafle Start — kwadratowe, pełny kolor grupy, ikona + nazwa, plakietka z licznikiem */
html body .adx-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.3rem;max-width:calc(6 * 190px)}
html body .adx-tile{position:relative;aspect-ratio:1/1;display:flex;flex-direction:column;justify-content:space-between;padding:.8rem .85rem .7rem;
  background:var(--g);color:#fff;text-decoration:none;overflow:hidden;transition:filter .12s,transform .12s}
html body .adx-tile:hover{color:#fff;filter:brightness(1.15)}
html body .adx-tile:active{transform:scale(.97)}
html body .adx-tile>.bi{font-size:2rem;line-height:1}
html body .adx-tile-lbl{font-size:.86rem;font-weight:600;line-height:1.2;overflow-wrap:anywhere}
html body .adx-badge{position:absolute;top:0;right:0;min-width:28px;padding:.3rem .5rem;background:#fff;color:#1d1d1d;font-size:.85rem;font-weight:700;text-align:center;line-height:1}
html body .adx-badge-danger{color:#a20025}
html body .adx-badge-warning{color:#8f3c00}
html body .adx-badge-info{color:#003bb0}

/* Lista rzadziej używanych pozycji pod kaflami */
html body .adx-list{columns:3 260px;column-gap:1.5rem;margin:.6rem 0 0;padding:0;list-style:none}
html body .adx-list li{break-inside:avoid}
html body .adx-li{display:flex;align-items:center;gap:.6rem;padding:.38rem .6rem;font-size:.88rem;color:#1d1d1d;text-decoration:none;border-left:3px solid transparent}
html body .adx-li>.bi{color:#5f5f5f;width:1.1rem;text-align:center;flex-shrink:0}
html body .adx-li:hover{background:#f2f2f2;color:#1d1d1d;border-left-color:var(--g)}
html body .adx-li:hover>.bi{color:var(--g)}
html body .adx-li .adx-badge{position:static;margin-left:auto;background:#f2f2f2;padding:.15rem .4rem;font-size:.7rem}
html body .adx-li.danger,html body .adx-li.danger>.bi{color:#a20025}
html body .adx-li.danger:hover{border-left-color:#a20025}
html body .adx-hide{display:none!important}

html body .adx-none{display:none;padding:3rem 1rem;text-align:center;color:#5f5f5f;font-size:1.05rem;font-weight:300}

/* Panel SaaS (tylko konto serwisowe) */
html body .adx-saas{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;background:#1d1d1d;color:#fff;padding:1rem 1.25rem;margin-top:2rem}
html body .adx-saas>.bi{font-size:1.6rem;color:#fff;width:48px;height:48px;display:flex;align-items:center;justify-content:center;background:#6a00ff}
html body .adx-saas-b{flex:1;min-width:220px}
html body .adx-saas-t{font-size:1.2rem;font-weight:300}
html body .adx-saas-d{font-size:.84rem;color:rgba(255,255,255,.7)}
html body .adx-saas a{border:2px solid #fff;color:#fff;padding:.45rem 1rem;font-weight:600;text-decoration:none;font-size:.86rem}
html body .adx-saas a:hover{background:#fff;color:#1d1d1d}

@media (max-width:640px){
  html body .adx-title{font-size:2rem}
  html body .adx-tiles{grid-template-columns:repeat(3,1fr)}
  html body .adx-tile{padding:.55rem}
  html body .adx-tile>.bi{font-size:1.5rem}
  html body .adx-tile-lbl{font-size:.74rem}
  html body .adx-stats{grid-template-columns:repeat(2,1fr)}
}
@media (prefers-reduced-motion:reduce){html body .adx-tile{transition:none}}
</style>

<div class="adx">

  <p class="adx-eyebrow">SZO · Administracja</p>
  <h1 class="adx-title">Administrator</h1>
  <p class="adx-sub">Centrum zarządzania systemem — konta i dostępy, ustawienia, integracje, kontrola i narzędzia.</p>

  <?php
  // ── Liczniki ──────────────────────────────────────────────────────────────
  $stats = [
      ['val'=>$cnt['users'],        'lbl'=>'Aktywnych użytkowników', 'icon'=>'bi-people',         'url'=>'/admin/users.php',          'alert'=>false],
      ['val'=>$cnt['applications'], 'lbl'=>'Nowych wniosków',        'icon'=>'bi-inbox',          'url'=>'/admin/applications.php',   'alert'=>$cnt['applications']>0],
      ['val'=>$cnt['mail_failed'],  'lbl'=>'Błędnych maili',         'icon'=>'bi-envelope-x',     'url'=>'/admin/mail_queue.php',     'alert'=>$cnt['mail_failed']>0],
      ['val'=>$cnt['expiring_30'],  'lbl'=>'Umów wygasa (30 dni)',   'icon'=>'bi-calendar-x',     'url'=>'/admin/contract_expiry.php','alert'=>$cnt['expiring_7']>0],
      ['val'=>$cnt['res_pending'],  'lbl'=>'Oczekujących rezerwacji','icon'=>'bi-calendar-check', 'url'=>'/modules/srs/admin/',       'alert'=>$cnt['res_pending']>0],
      ['val'=>$cnt['log_today'],    'lbl'=>'Zdarzeń dzisiaj',        'icon'=>'bi-journal-text',   'url'=>'/admin/log.php',            'alert'=>false],
  ];
  ?>
  <div class="adx-stats">
    <?php foreach ($stats as $st): ?>
    <a href="<?= APP_URL . $st['url'] ?>" class="adx-stat<?= $st['alert'] ? ' is-alert' : '' ?>">
      <span class="adx-stat-val"><?= number_format($st['val'], 0, ',', ' ') ?></span>
      <span class="adx-stat-lbl"><i class="bi <?= $st['icon'] ?>" aria-hidden="true"></i><?= h($st['lbl']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php
  // ── Checklist konfiguracji wstępnej ──────────────────────────────────────────
  $s_org_name  = org_setting('org_name');
  $s_org_krs   = org_setting('org_krs');
  $s_logo      = org_setting('org_logo');
  $s_color     = org_setting('sidebar_color');
  $s_cron      = db_one("SELECT 1 FROM settings WHERE key_='cron_token'");
  $s_cert      = file_exists(dirname(__DIR__) . '/certs/app.crt');
  $s_m365      = org_setting('m365_tenant_id') || org_setting('smtp_host') || org_setting('m365_send_from_email');
  $s_reps      = false;
  try { $s_reps = (bool)db_one("SELECT 1 FROM org_representatives WHERE is_active=1"); } catch(\Throwable $e){}

  $s_serwis_pass = false;
  try {
      $serwis = db_one("SELECT password FROM users WHERE email='serwis@local'");
      if ($serwis) $s_serwis_pass = !password_verify('serwis', $serwis['password']);
  } catch(\Throwable $e){}

  $s_org_admin = false;
  try {
      $s_org_admin = (bool)db_one("SELECT 1 FROM users WHERE role='admin' AND is_active=1 AND email != 'serwis@local'");
  } catch(\Throwable $e){}

  $setup_items = [
    ['ok'=>(bool)$s_org_name,  'label'=>'Nazwa organizacji',      'url'=>'/admin/org_settings.php',              'tip'=>'Pełna nazwa w bazie danych'],
    ['ok'=>(bool)$s_org_krs,   'label'=>'Numer KRS',              'url'=>'/admin/org_settings.php',              'tip'=>'Wymagany do certyfikatu'],
    ['ok'=>(bool)$s_logo,      'label'=>'Logo organizacji',        'url'=>'/admin/org_settings.php#branding',    'tip'=>'Wyświetlane na stronie logowania'],
    ['ok'=>$s_color!=='#1e293b'&&(bool)$s_color,'label'=>'Kolor brandingu','url'=>'/admin/org_settings.php#branding','tip'=>'Kolor sidebara i panelu'],
    ['ok'=>(bool)$s_m365,      'label'=>'E-mail (M365 lub SMTP)', 'url'=>'/admin/org_settings.php#mail',         'tip'=>'Konfiguracja wysyłki maili'],
    ['ok'=>$s_cert,            'label'=>'Certyfikat instalacyjny', 'url'=>'/admin/app_license.php',              'tip'=>'x509 wymagany do uruchomienia'],
    ['ok'=>(bool)$s_cron,      'label'=>'Token CRON',              'url'=>'/admin/cron_setup.php',               'tip'=>'Potrzebny do URL-cron'],
    ['ok'=>$s_reps,            'label'=>'Osoby do reprezentacji',  'url'=>'/admin/org_settings.php#representatives','tip'=>'Podpisujący umowy'],
    ['ok'=>$s_serwis_pass,     'label'=>'Hasło serwis@local',      'url'=>'/admin/users.php',                    'tip'=>'Zmień z domyślnego „serwis”','warn'=>true],
    ['ok'=>$s_org_admin,       'label'=>'Administrator org',       'url'=>'#create-admin',                       'tip'=>'Konto admina dla właściciela systemu','action'=>true],
  ];

  $done  = count(array_filter($setup_items, fn($i) => $i['ok']));
  $total = count($setup_items);

  if ($done < $total && !org_setting('setup_complete')):
  ?>
  <section class="adx-setup" aria-label="Konfiguracja wstępna">
    <div class="adx-setup-h">
      <i class="bi bi-list-check" style="color:#c25100;font-size:1.2rem" aria-hidden="true"></i>
      <strong>Konfiguracja wstępna</strong>
      <span><?= $done ?>/<?= $total ?> ukończone</span>
    </div>
    <div class="adx-setup-bar"><div style="width:<?= round($done/$total*100) ?>%"></div></div>
    <div class="adx-setup-grid" id="setup">
      <?php foreach ($setup_items as $si):
        $is_ok   = $si['ok'];
        $is_warn = !$is_ok && !empty($si['warn']);
        $is_act  = !$is_ok && !empty($si['action']);
        $href    = (!empty($si['action']) && !$is_ok) ? '#' : APP_URL . $si['url'];
        $onclick = $is_act ? 'onclick="document.getElementById(\'createAdminModal\').classList.toggle(\'d-none\');return false"' : '';
        $icon    = $is_ok ? 'check-circle-fill' : ($is_warn ? 'exclamation-circle-fill' : 'circle');
      ?>
      <a href="<?= h($href) ?>" <?= $onclick ?> class="adx-setup-i<?= $is_ok ? ' ok' : ($is_warn ? ' warn' : '') ?>" title="<?= h($si['tip']) ?>">
        <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><?= h($si['label']) ?>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$s_org_admin): ?>
    <div id="createAdminModal" class="d-none adx-setup-new">
      <div class="fw-semibold mb-2"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz administratora organizacji</div>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="create_org_admin">
        <div class="col-sm-4">
          <label class="form-label small mb-1" for="adm_name">Imię i nazwisko</label>
          <input type="text" id="adm_name" name="adm_name" class="form-control form-control-sm" placeholder="Jan Kowalski" required>
        </div>
        <div class="col-sm-5">
          <label class="form-label small mb-1" for="adm_email">E-mail</label>
          <input type="email" id="adm_email" name="adm_email" class="form-control form-control-sm" placeholder="jan@organizacja.pl" required>
        </div>
        <div class="col-sm-3">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-send me-1" aria-hidden="true"></i>Utwórz i wyślij</button>
        </div>
        <div class="col-12">
          <div class="form-text">Hasło zostanie wygenerowane automatycznie i wysłane na podany adres e-mail.</div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="adx-setup-foot">
      <span>
        <?php if ($done < $total): ?>
          Możesz pominąć pozostałe punkty i ręcznie zakończyć konfigurację wstępną.
        <?php else: ?>
          Wszystkie punkty ukończone — możesz zamknąć konfigurację wstępną.
        <?php endif; ?>
      </span>
      <form method="post" class="ms-auto"
            onsubmit="return confirm('Czy na pewno zakończyć konfigurację wstępną? Lista kontrolna zniknie z panelu.');">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="finish_setup">
        <button class="btn btn-success btn-sm">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Konfiguracja wstępna — zakończ
        </button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── Filtr kafli + skoki do sekcji ─────────────────────────────────────── -->
  <div class="adx-bar">
    <div class="adx-search" role="search">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input id="admSearch" type="search" placeholder="Szukaj funkcji…  ( / )" autocomplete="off" aria-label="Szukaj funkcji administratora">
      <button type="button" class="adx-search-clear" id="admSearchClear" title="Wyczyść" aria-label="Wyczyść filtr"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <nav class="adx-jump" aria-label="Sekcje panelu">
      <?php $gi = 0; foreach ($groups as $group_name => $group): $gi++; ?>
      <a href="#adx-g<?= $gi ?>" data-jump="adx-g<?= $gi ?>" style="--g:<?= h($group['color']) ?>"><?= h($group_name) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div id="admGrid">
  <?php $gi = 0; foreach ($groups as $group_name => $group): $gi++;
      $tiles = array_values(array_filter($group['items'], fn($it) => !empty($it['tile']) && empty($it['danger'])));
      $rest  = array_values(array_filter($group['items'], fn($it) =>  empty($it['tile']) || !empty($it['danger'])));
  ?>
    <section class="adx-sec" id="adx-g<?= $gi ?>" data-group="<?= h($group_name) ?>" style="--g:<?= h($group['color']) ?>" aria-labelledby="adx-g<?= $gi ?>-h">
      <h2 class="adx-sec-h" id="adx-g<?= $gi ?>-h"><i class="bi <?= $group['icon'] ?>" aria-hidden="true"></i><?= h($group_name) ?> <small><?= count($group['items']) ?></small></h2>

      <?php if ($tiles): ?>
      <div class="adx-tiles">
        <?php foreach ($tiles as $item): ?>
        <a href="<?= APP_URL . $item['url'] ?>" class="adx-tile" data-k="<?= h($adm_keys($item, $group_name)) ?>">
          <i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i>
          <span class="adx-tile-lbl"><?= h($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?>
          <span class="adx-badge adx-badge-<?= h($item['badge_type'] ?? 'secondary') ?>"><?= (int)$item['badge'] ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($rest): ?>
      <ul class="adx-list">
        <?php foreach ($rest as $item): ?>
        <li>
          <a href="<?= APP_URL . $item['url'] ?>" class="adx-li<?= !empty($item['danger']) ? ' danger' : '' ?>" data-k="<?= h($adm_keys($item, $group_name)) ?>">
            <i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i><?= h($item['label']) ?>
            <?php if (!empty($item['badge'])): ?>
            <span class="adx-badge adx-badge-<?= h($item['badge_type'] ?? 'secondary') ?>"><?= (int)$item['badge'] ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

    <div class="adx-none" id="admNoResults" role="status">
      <i class="bi bi-search d-block mb-2" style="font-size:1.6rem" aria-hidden="true"></i>
      Nie znaleziono funkcji pasujących do zapytania.
    </div>
  </div>

<?php if ($_is_service_account && $_saas_panel_url): ?>
  <div class="adx-saas">
    <i class="bi bi-server" aria-hidden="true"></i>
    <div class="adx-saas-b">
      <div class="adx-saas-t">Panel zarządzania tenantami SaaS</div>
      <div class="adx-saas-d">Tworzenie i konfiguracja tenantów, provisioning baz danych, zarządzanie planami i modułami. Dostępny wyłącznie dla konta serwisowego.</div>
    </div>
    <a href="<?= h($_saas_panel_url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz panel SaaS</a>
  </div>
<?php endif; ?>

</div>

<script>
(function () {
  const input = document.getElementById('admSearch');
  const clear = document.getElementById('admSearchClear');
  const noRes = document.getElementById('admNoResults');
  const secs  = document.querySelectorAll('.adx-sec[data-group]');
  const fold  = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');

  function filter(raw) {
    const words = fold(raw.trim()).split(/\s+/).filter(Boolean);
    clear.style.display = words.length ? 'block' : 'none';
    let any = false;
    secs.forEach(sec => {
      let n = 0;
      sec.querySelectorAll('[data-k]').forEach(el => {
        const k = fold(el.dataset.k);
        const hit = words.every(w => k.includes(w));
        (el.closest('li') || el).classList.toggle('adx-hide', !hit);
        if (hit) n++;
      });
      sec.hidden = n === 0;
      const j = document.querySelector('[data-jump="' + sec.id + '"]');
      if (j) j.classList.toggle('is-off', n === 0);
      if (n) any = true;
    });
    noRes.style.display = any ? 'none' : 'block';
  }

  input.addEventListener('input', () => filter(input.value));
  clear.addEventListener('click', () => { input.value = ''; input.focus(); filter(''); });
  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') { input.value = ''; filter(''); }
    if (e.key === 'Enter') {                       // Enter = otwórz pierwszą pasującą pozycję
      const first = [...document.querySelectorAll('.adx-sec:not([hidden]) [data-k]')]
        .find(el => !(el.closest('li') || el).classList.contains('adx-hide'));
      if (first) { e.preventDefault(); location.href = first.href; }
    }
  });
  document.addEventListener('keydown', e => {      // „/” = fokus na filtrze
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) {
      e.preventDefault(); input.focus();
    }
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
