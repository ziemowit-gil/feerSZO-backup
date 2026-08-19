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

$cnt['moodle_pending'] = 0;
try { $cnt['moodle_pending'] = (int)db()->query("SELECT COUNT(*) FROM moodle_enrollments WHERE status='oczekuje'")->fetchColumn(); }
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

// ── Grupy kafelków (9 grup, brak duplikatów) ──────────────────────────────────
$groups = [

    'Użytkownicy i bezpieczeństwo' => [
        'icon'  => 'bi-shield-person',
        'color' => 'blue',
        'items' => [
            ['icon'=>'bi-people',               'label'=>'Użytkownicy',              'url'=>'/admin/users.php',              'badge'=>$cnt['users'] ?: null,      'badge_type'=>'secondary'],
            ...(org_setting('allow_standalone_vol_accounts') === '1' ? [
                ['icon'=>'bi-person-plus',      'label'=>'Wolontariusze bez umowy',  'url'=>'/admin/volunteer_accounts.php'],
            ] : []),
            ['icon'=>'bi-shield-lock',          'label'=>'Role i uprawnienia',       'url'=>'/admin/roles.php'],
            ['icon'=>'bi-key',                  'label'=>'Kody IKA',                 'url'=>'/admin/manage_cpc.php'],
            ['icon'=>'bi-door-open',            'label'=>'Metody logowania',         'url'=>'/admin/login_settings.php'],
            ['icon'=>'bi-patch-check-fill',     'label'=>'Certyfikaty X.509',        'url'=>'/admin/x509_login.php'],
            ['icon'=>'bi-shield-lock',          'label'=>'SAML Identity Provider',   'url'=>'/admin/saml.php'],
            ['icon'=>'bi-journal-text',         'label'=>'Dziennik zdarzeń',         'url'=>'/admin/log.php',               'badge'=>$cnt['log_today'] ?: null,  'badge_type'=>'info'],
            ['icon'=>'bi-shield-exclamation',   'label'=>'Audit log',                'url'=>'/admin/audit_log.php',         'badge'=>$cnt['audit_today'] ?: null,'badge_type'=>'secondary'],
            ['icon'=>'bi-person-badge',         'label'=>'Podszywanie',              'url'=>'/admin/impersonate.php'],
        ],
    ],

    'Organizacja i konfiguracja' => [
        'icon'  => 'bi-building',
        'color' => 'purple',
        'items' => [
            ['icon'=>'bi-building',             'label'=>'Dane organizacji',         'url'=>'/admin/org_settings.php'],
            ['icon'=>'bi-toggles2',             'label'=>'Moduły',                   'url'=>'/admin/modules_settings.php'],
            ['icon'=>'bi-grid-3x3-gap-fill',    'label'=>'Widoczność modułów per rola','url'=>'/admin/module_perms.php'],
            ['icon'=>'bi-calendar-event-fill',  'label'=>'Ustawienia wydarzeń',     'url'=>'/admin/events_settings.php'],
            ['icon'=>'bi-building-gear',        'label'=>'Wirtualne biurko',           'url'=>'/admin/ezd_settings.php'],
            ['icon'=>'bi-shield-check',         'label'=>'EU DSS (walidacja eIDAS)', 'url'=>'/admin/dss_settings.php'],
            ['icon'=>'bi-diagram-2',            'label'=>'Procesy EZD (workflow)',   'url'=>'/admin/ezd_workflows.php'],
            ['icon'=>'bi-diagram-3',            'label'=>'Workflow akceptacji',      'url'=>'/admin/approval_workflows.php'],
            ['icon'=>'bi-ui-checks-grid',       'label'=>'SelfService',              'url'=>'/onboarding/settings.php'],
            ['icon'=>'bi-kanban',               'label'=>'Obszary zadań',            'url'=>'/admin/tasks_workspaces.php'],
            ['icon'=>'bi-tag',                  'label'=>'Tagi zadań',               'url'=>'/admin/tasks_tags.php'],
            ['icon'=>'bi-person-bounding-box',  'label'=>'Zdjęcia profilowe',        'url'=>'/admin/avatars.php',           'badge'=>$cnt['pending_avatars'] ?: null, 'badge_type'=>'warning'],
            ['icon'=>'bi-person-lines-fill',    'label'=>'Pola profilu',             'url'=>'/admin/profile_fields.php'],
            ['icon'=>'bi-ui-checks',            'label'=>'Pola w formularzach',      'url'=>'/admin/form_fields.php'],
            ['icon'=>'bi-stars',                'label'=>'Ustawienia AI',            'url'=>'/admin/ai_settings.php'],
            ['icon'=>'bi-layout-sidebar',       'label'=>'Konfiguracja menu',        'url'=>'/admin/menu_config.php'],
        ],
    ],

    'CRM' => [
        'icon'  => 'bi-diagram-2-fill',
        'color' => 'green',
        'items' => [
            ['icon'=>'bi-person-lines-fill',    'label'=>'Ustawienia CRM',           'url'=>'/crm/settings/'],
            ['icon'=>'bi-bookmark-fill',        'label'=>'Statusy CRM',              'url'=>'/crm/settings/statuses.php'],
            ['icon'=>'bi-layers',               'label'=>'Grupy pól',                'url'=>'/crm/settings/field_groups.php'],
            ['icon'=>'bi-list-columns',         'label'=>'Pola formularza',          'url'=>'/crm/settings/fields.php'],
            ['icon'=>'bi-diagram-2',            'label'=>'Dostęp CRM-only',          'url'=>'/admin/crm_access.php'],
            ['icon'=>'bi-database-gear',        'label'=>'Baza danych CRM',          'url'=>'/admin/crm_database.php'],
            ['icon'=>'bi-geo-alt-fill',         'label'=>'Import TERYT',             'url'=>'/admin/teryt_import.php'],
            ['icon'=>'bi-check2-square',        'label'=>'Nozbe',                    'url'=>'/crm/settings/nozbe.php'],
            ['icon'=>'bi-window-split',         'label'=>'Formularze webowe',        'url'=>'/crm/form/manage.php'],
        ],
    ],

    'Komunikacja' => [
        'icon'  => 'bi-envelope',
        'color' => 'teal',
        'items' => [
            ['icon'=>'bi-megaphone',            'label'=>'Komunikaty / ogłoszenia', 'url'=>'/komunikaty/index.php'],
            ['icon'=>'bi-megaphone-fill',       'label'=>'Nowe ogłoszenie',         'url'=>'/komunikaty/compose.php'],
            ['icon'=>'bi-gear',                 'label'=>'Zarządzaj ogłoszeniami',  'url'=>'/komunikaty/manage.php'],
            ['icon'=>'bi-bell',                 'label'=>'Powiadomienia e-mail',    'url'=>'/admin/msg_settings.php'],
            ['icon'=>'bi-envelope-paper',       'label'=>'Maile systemowe',         'url'=>'/admin/email_templates.php'],
            ['icon'=>'bi-send-check',           'label'=>'Kolejka e-mail',          'url'=>'/admin/mail_queue.php',        'badge'=>$cnt['mail_failed'] ?: null,'badge_type'=>'danger'],
            ['icon'=>'bi-tags',                 'label'=>'Typy wiadomości',         'url'=>'/admin/message_types.php'],
            ['icon'=>'bi-envelope-check',       'label'=>'Masowe maile',            'url'=>'/admin/bulk_email.php'],
            ['icon'=>'bi-chat-dots',            'label'=>'SMS',                     'url'=>'/admin/sms_settings.php'],
            ['icon'=>'bi-whatsapp',             'label'=>'WhatsApp',                'url'=>'/admin/whatsapp_settings.php'],
            ['icon'=>'bi-whatsapp',             'label'=>'WhatsApp — grupa',        'url'=>'/admin/whatsapp_group.php'],
            ['icon'=>'bi-envelope-at',          'label'=>'Postivo',                 'url'=>'/admin/postivo_settings.php'],
            ['icon'=>'bi-envelope-heart',       'label'=>'Wysyłka powitalna',       'url'=>'/admin/resend_welcome.php'],
        ],
    ],

    'Strategia i rozwój' => [
        'icon'  => 'bi-bullseye',
        'color' => 'purple',
        'items' => [
            ['icon'=>'bi-bullseye',              'label'=>'Strategia Rozwoju NGO',    'url'=>'/strategy/index.php'],
            ['icon'=>'bi-globe2',                'label'=>'Sfery Pożytku Publicznego','url'=>'/strategy/spheres/index.php'],
            ['icon'=>'bi-bar-chart-line',        'label'=>'Raporty strategiczne',     'url'=>'/strategy/reports/index.php'],
        ],
    ],

    'Dokumenty i umowy' => [
        'icon'  => 'bi-file-earmark-text',
        'color' => 'orange',
        'items' => [
            ['icon'=>'bi-inbox',                'label'=>'Pisma / wnioski',          'url'=>'/admin/applications.php',     'badge'=>$cnt['applications'] ?: null,'badge_type'=>'danger'],
            ['icon'=>'bi-ui-checks-grid',       'label'=>'Typy wniosków',            'url'=>'/admin/application_types.php'],
            ['icon'=>'bi-file-earmark-text',    'label'=>'Wzory dokumentów',         'url'=>'/admin/contract_templates.php'],
            ['icon'=>'bi-printer',              'label'=>'Wzory wydruków',           'url'=>'/admin/print_templates.php'],
            ['icon'=>'bi-envelope',            'label'=>'Wzory kopert',             'url'=>'/admin/envelope_templates.php'],
            ['icon'=>'bi-pen-fill',             'label'=>'Autenti eSign',            'url'=>'/admin/autenti_settings.php'],
            ['icon'=>'bi-pen-fill',             'label'=>'DocuSign eSign',           'url'=>'/admin/docusign_settings.php'],
            ['icon'=>'bi-calendar-x',           'label'=>'Wygasające umowy',         'url'=>'/admin/contract_expiry.php',   'badge'=>$cnt['expiring_7'] ?: ($cnt['expiring_30'] ?: null), 'badge_type'=>$cnt['expiring_7'] ? 'danger' : 'warning'],
            ['icon'=>'bi-calendar3',            'label'=>'Kalendarz umów',           'url'=>'/admin/calendar.php'],
            ['icon'=>'bi-bar-chart-line',       'label'=>'Statystyki',               'url'=>'/reports/volunteer_stats.php'],
        ],
    ],

    'E-learning i zasoby' => [
        'icon'  => 'bi-mortarboard',
        'color' => 'indigo',
        'items' => [
            ['icon'=>'bi-mortarboard',          'label'=>'Moodle',                   'url'=>'/admin/moodle.php',            'badge'=>$cnt['moodle_pending'] ?: null,'badge_type'=>'warning'],
            ['icon'=>'bi-building-heart',       'label'=>'Zasady i Wprowadzenie',    'url'=>'/admin/org_rules.php'],
            ['icon'=>'bi-calendar-check',       'label'=>'Rezerwacje zasobów',       'url'=>'/resources/admin/',            'badge'=>$cnt['res_pending'] ?: null, 'badge_type'=>'warning'],
            ['icon'=>'bi-box',                  'label'=>'Zasoby',                   'url'=>'/resources/admin/resources.php'],
            ['icon'=>'bi-tags',                 'label'=>'Kategorie zasobów',        'url'=>'/resources/admin/categories.php'],
        ],
    ],

    'Integracje' => [
        'icon'  => 'bi-plug',
        'color' => 'slate',
        'items' => [
            ['icon'=>'bi-plug',                 'label'=>'Integracje',               'url'=>'/admin/integrations.php'],
        ],
    ],

    'EOD Dokumentów Księgowych' => [
        'icon'  => 'bi-file-earmark-check',
        'color' => 'cyan',
        'items' => [
            ['icon'=>'bi-shield-lock',          'label'=>'Role dostępu',             'url'=>'/admin/ksiegowosc_roles.php'],
            ['icon'=>'bi-patch-check',          'label'=>'Certyfikaty X.509',        'url'=>'/admin/kdok_certs.php'],
            ['icon'=>'bi-gear',                 'label'=>'Ustawienia (baza, MPK)',   'url'=>'/admin/ksiegowosc_settings.php'],
            ['icon'=>'bi-grid-3x2',             'label'=>'Macierz uprawnień',        'url'=>'/admin/kdok_matrix.php'],
            ['icon'=>'bi-cloud-arrow-up',       'label'=>'eArchiwum (FTP / R2)',     'url'=>'/admin/kdok_archive_settings.php'],
            ['icon'=>'bi-receipt-cutoff',       'label'=>'KSeF — ustawienia',        'url'=>'/admin/kdok_ksef_settings.php'],
            ['icon'=>'bi-arrow-repeat',         'label'=>'Wyczyść stare PDF',        'url'=>'/admin/kdok_cleanup_run.php'],
            ['icon'=>'bi-trash3',               'label'=>'Wyczyść dane obiegu',      'url'=>'/admin/kdok_clear.php'],
        ],
    ],

    'Narzędzia' => [
        'icon'  => 'bi-tools',
        'color' => 'red',
        'items' => [
            ['icon'=>'bi-toggles',              'label'=>'Status systemu',           'url'=>'/admin/system_status.php'],
            ['icon'=>'bi-shield-lock',          'label'=>'Certyfikat i licencja',    'url'=>'/admin/app_license.php'],
            ['icon'=>'bi-arrow-repeat',         'label'=>'Aktualizacja systemu',     'url'=>'/upgrade.php'],
            ['icon'=>'bi-git',                  'label'=>'Wersja i historia zmian',  'url'=>'/admin/version.php'],
            ['icon'=>'bi-clock-history',        'label'=>'Konfiguracja CRON',        'url'=>'/admin/cron_setup.php'],
            ['icon'=>'bi-info-circle',          'label'=>'Informacje o systemie',    'url'=>'/admin/system_info.php'],
            ['icon'=>'bi-archive',              'label'=>'Kopie zapasowe',           'url'=>'/admin/backups.php'],
            ['icon'=>'bi-database-fill-gear',   'label'=>'Narzędzia bazy danych',   'url'=>'/admin/db_tools.php'],
            ['icon'=>'bi-journal-text',         'label'=>'Przeglądarka logów',       'url'=>'/admin/logs_global.php'],
            ['icon'=>'bi-database-gear',        'label'=>'Zarządzanie migracjami',   'url'=>'/admin/migrations.php'],
            ['icon'=>'bi-link-45deg',           'label'=>'Krótkie linki',            'url'=>'/admin/short_urls.php'],
            ['icon'=>'bi-rocket-takeoff',       'label'=>'Czyszczenie przed wdrożeniem','url'=>'/admin/clean_for_prod.php','danger'=>true],
            ['icon'=>'bi-trash3',               'label'=>'Wyczyść bazę',             'url'=>'/admin/clean_db.php',         'danger'=>true],
        ],
    ],

];

// ── Paleta kolorów ────────────────────────────────────────────────────────────
$color_map = [
    'blue'   => ['bg'=>'#EFF6FF','border'=>'#BFDBFE','icon_bg'=>'#2563EB','icon_text'=>'#fff','head'=>'#1E40AF'],
    'purple' => ['bg'=>'#F5F3FF','border'=>'#DDD6FE','icon_bg'=>'#7C3AED','icon_text'=>'#fff','head'=>'#5B21B6'],
    'green'  => ['bg'=>'#F0FDF4','border'=>'#BBF7D0','icon_bg'=>'#16A34A','icon_text'=>'#fff','head'=>'#15803D'],
    'teal'   => ['bg'=>'#F0FDFA','border'=>'#99F6E4','icon_bg'=>'#0D9488','icon_text'=>'#fff','head'=>'#0F766E'],
    'orange' => ['bg'=>'#FFF7ED','border'=>'#FED7AA','icon_bg'=>'#EA580C','icon_text'=>'#fff','head'=>'#9A3412'],
    'indigo' => ['bg'=>'#EEF2FF','border'=>'#C7D2FE','icon_bg'=>'#4F46E5','icon_text'=>'#fff','head'=>'#3730A3'],
    'slate'  => ['bg'=>'#F8FAFC','border'=>'#CBD5E1','icon_bg'=>'#475569','icon_text'=>'#fff','head'=>'#1E293B'],
    'cyan'   => ['bg'=>'#ECFEFF','border'=>'#A5F3FC','icon_bg'=>'#0891B2','icon_text'=>'#fff','head'=>'#155E75'],
    'red'    => ['bg'=>'#FFF1F2','border'=>'#FECDD3','icon_bg'=>'#DC2626','icon_text'=>'#fff','head'=>'#991B1B'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Układ strony ─────────────────────────────────────────────────── */
.adm-page  { max-width: 1200px; padding: 2rem 1.5rem; }
.adm-title { font-size: 1.5rem; font-weight: 800; color: #0F172A; margin-bottom: .2rem; display:flex;align-items:center;gap:.6rem; }
.adm-sub   { font-size: .875rem; color: #64748B; margin-bottom: 1.75rem; }

/* ── Pasek statystyk ──────────────────────────────────────────────── */
.adm-stats {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: .75rem;
    margin-bottom: 1.75rem;
}
.adm-stat {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: .9rem 1.1rem;
    text-decoration: none;
    color: inherit;
    transition: box-shadow .15s, border-color .15s;
    display: flex;
    align-items: center;
    gap: .75rem;
}
.adm-stat:hover { box-shadow: 0 3px 12px rgba(0,0,0,.08); border-color: #CBD5E1; color: inherit; }
.adm-stat-icon {
    width: 36px; height: 36px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
}
.adm-stat-val  { font-size: 1.25rem; font-weight: 800; line-height: 1; }
.adm-stat-lbl  { font-size: .72rem; color: #64748B; margin-top: .15rem; }
.adm-stat-alert .adm-stat-val { color: #DC2626; }

/* ── Wyszukiwarka ─────────────────────────────────────────────────── */
.adm-search-wrap {
    position: relative;
    margin-bottom: 1.5rem;
    max-width: 420px;
}
.adm-search-wrap i {
    position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
    color: #94A3B8; font-size: .95rem; pointer-events: none;
}
#admSearch {
    width: 100%;
    padding: .55rem .9rem .55rem 2.4rem;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    font-size: .88rem;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
#admSearch:focus { border-color: #93C5FD; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.adm-search-clear {
    position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
    display: none; background: none; border: none; color: #94A3B8; cursor: pointer;
    font-size: .95rem; padding: 0; line-height: 1;
}
.adm-search-clear:hover { color: #475569; }

/* ── Siatka i karty ───────────────────────────────────────────────── */
.adm-grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

.adm-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    transition: box-shadow .15s;
}
.adm-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.adm-card.adm-hidden { display: none; }

.adm-card-head {
    display: flex; align-items: center; gap: .75rem;
    padding: .85rem 1.1rem;
    border-bottom: 1px solid #F1F5F9;
}
.adm-card-head-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; flex-shrink: 0;
}
.adm-card-head-label {
    font-size: .78rem; font-weight: 700; letter-spacing: .05em;
    text-transform: uppercase;
}

/* ── Elementy listy ───────────────────────────────────────────────── */
.adm-items { padding: .3rem 0; }
.adm-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .5rem 1.1rem;
    font-size: .875rem; font-weight: 500; color: #334155;
    text-decoration: none;
    transition: background .1s, color .1s;
    border-left: 3px solid transparent;
}
.adm-item i { font-size: .9rem; width: 17px; text-align: center; color: #94A3B8; flex-shrink: 0; transition: color .1s; }
.adm-item:hover { background: #F8FAFC; color: #0F172A; border-left-color: #2563EB; }
.adm-item:hover i { color: #2563EB; }
.adm-item.adm-item-hidden { display: none; }

.adm-item.danger { color: #DC2626; }
.adm-item.danger i { color: #FCA5A5; }
.adm-item.danger:hover { background: #FFF1F2; border-left-color: #DC2626; }
.adm-item.danger:hover i { color: #DC2626; }

.adm-badge {
    margin-left: auto; font-size: .65rem; font-weight: 700;
    padding: .15rem .45rem; border-radius: 10px; line-height: 1.4;
    white-space: nowrap;
}
.adm-badge-danger    { background: #FEE2E2; color: #DC2626; }
.adm-badge-warning   { background: #FEF9C3; color: #A16207; }
.adm-badge-info      { background: #DBEAFE; color: #1D4ED8; }
.adm-badge-secondary { background: #F1F5F9; color: #475569; }

/* ── Brak wyników wyszukiwania ────────────────────────────────────── */
#admNoResults {
    display: none;
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem 1rem;
    color: #94A3B8;
    font-size: .9rem;
}

/* ── Responsywność ────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .adm-grid        { grid-template-columns: 1fr; }
    .adm-page        { padding: 1.25rem 1rem; }
    .adm-stats       { grid-template-columns: repeat(2, 1fr); }
    .adm-search-wrap { max-width: 100%; }
}
</style>

<div class="adm-page">

  <div class="adm-title">
    <i class="bi bi-shield-shaded" style="color:#2563eb"></i>
    Administrator
  </div>
  <p class="adm-sub">Centrum zarządzania systemem — użytkownicy, ustawienia, integracje i narzędzia.</p>

  <?php
  // ── Pasek szybkich statystyk ──────────────────────────────────────────────
  $stats = [
      ['val'=>$cnt['users'],        'lbl'=>'Aktywnych użytkowników', 'icon'=>'bi-people',         'color'=>'#2563EB','bg'=>'#EFF6FF', 'url'=>'/admin/users.php',         'alert'=>false],
      ['val'=>$cnt['applications'], 'lbl'=>'Nowych wniosków',       'icon'=>'bi-inbox',           'color'=>'#DC2626','bg'=>'#FEE2E2', 'url'=>'/admin/applications.php',  'alert'=>$cnt['applications']>0],
      ['val'=>$cnt['mail_failed'],  'lbl'=>'Błędnych maili',        'icon'=>'bi-envelope-x',      'color'=>'#DC2626','bg'=>'#FEE2E2', 'url'=>'/admin/mail_queue.php',    'alert'=>$cnt['mail_failed']>0],
      ['val'=>$cnt['expiring_30'],  'lbl'=>'Umów wygasa (30 dni)',  'icon'=>'bi-calendar-x',      'color'=>'#EA580C','bg'=>'#FFF7ED', 'url'=>'/admin/contract_expiry.php','alert'=>$cnt['expiring_7']>0],
      ['val'=>$cnt['res_pending'],  'lbl'=>'Oczekujących rezerwacji','icon'=>'bi-calendar-check',  'color'=>'#4F46E5','bg'=>'#EEF2FF', 'url'=>'/resources/admin/',        'alert'=>$cnt['res_pending']>0],
      ['val'=>$cnt['log_today'],    'lbl'=>'Zdarzeń dzisiaj',       'icon'=>'bi-journal-text',     'color'=>'#0D9488','bg'=>'#F0FDFA', 'url'=>'/admin/log.php',           'alert'=>false],
  ];
  ?>

  <div class="adm-stats">
    <?php foreach ($stats as $st): ?>
    <a href="<?= APP_URL . $st['url'] ?>" class="adm-stat<?= $st['alert'] ? ' adm-stat-alert' : '' ?>">
      <div class="adm-stat-icon" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>">
        <i class="bi <?= $st['icon'] ?>"></i>
      </div>
      <div>
        <div class="adm-stat-val"><?= number_format($st['val']) ?></div>
        <div class="adm-stat-lbl"><?= h($st['lbl']) ?></div>
      </div>
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
    ['ok'=>$s_serwis_pass,     'label'=>'Hasło serwis@local',      'url'=>'/admin/users.php',                    'tip'=>'Zmień z domyślnego "serwis"','warn'=>true],
    ['ok'=>$s_org_admin,       'label'=>'Administrator org',       'url'=>'#create-admin',                       'tip'=>'Konto admina dla właściciela systemu','action'=>true],
  ];

  $done  = count(array_filter($setup_items, fn($i) => $i['ok']));
  $total = count($setup_items);

  if ($done < $total && !org_setting('setup_complete')):
  ?>
  <div class="card shadow-sm mb-4" style="border-left:4px solid #f59e0b">
    <div class="card-body py-3">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-list-check text-warning" style="font-size:1.1rem"></i>
        <strong class="small">Konfiguracja wstępna</strong>
        <span class="text-muted small ms-auto"><?= $done ?>/<?= $total ?> ukończone</span>
      </div>
      <div class="progress mb-3" style="height:4px">
        <div class="progress-bar bg-warning" style="width:<?= round($done/$total*100) ?>%"></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.35rem" id="setup">
        <?php foreach ($setup_items as $si):
          $is_ok   = $si['ok'];
          $is_warn = !$is_ok && !empty($si['warn']);
          $is_act  = !$is_ok && !empty($si['action']);
          $href    = (!empty($si['action']) && !$is_ok) ? '#' : APP_URL . $si['url'];
          $onclick = $is_act ? 'onclick="document.getElementById(\'createAdminModal\').classList.toggle(\'d-none\')"' : '';
          $bg      = $is_ok ? '#f0fdf4' : ($is_warn ? '#fff1f2' : '#fffbeb');
          $color   = $is_ok ? '#16a34a' : ($is_warn ? '#be123c' : '#b45309');
          $icon    = $is_ok ? 'check-circle-fill' : ($is_warn ? 'exclamation-circle-fill' : 'circle');
        ?>
        <a href="<?= h($href) ?>" <?= $onclick ?>
           class="text-decoration-none d-flex align-items-center gap-2 py-1 px-2 rounded"
           style="font-size:.82rem;color:<?= $color ?>;background:<?= $bg ?>"
           title="<?= h($si['tip']) ?>">
          <i class="bi bi-<?= $icon ?>" style="flex-shrink:0"></i>
          <?= h($si['label']) ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (!$s_org_admin): ?>
      <div id="createAdminModal" class="d-none mt-3 p-3 border rounded" style="background:#fffbeb">
        <div class="fw-semibold small mb-2"><i class="bi bi-person-plus me-1"></i>Utwórz administratora organizacji</div>
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="create_org_admin">
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">Imię i nazwisko</label>
            <input type="text" name="adm_name" class="form-control form-control-sm" placeholder="Jan Kowalski" required>
          </div>
          <div class="col-sm-5">
            <label class="form-label small fw-semibold mb-1">E-mail</label>
            <input type="email" name="adm_email" class="form-control form-control-sm" placeholder="jan@organizacja.pl" required>
          </div>
          <div class="col-sm-3">
            <button class="btn btn-warning btn-sm w-100"><i class="bi bi-send me-1"></i>Utwórz i wyślij</button>
          </div>
          <div class="col-12">
            <div class="form-text">Hasło zostanie wygenerowane automatycznie i wysłane na podany adres e-mail.</div>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="d-flex align-items-center gap-2 mt-3 pt-2 border-top">
        <span class="text-muted" style="font-size:.78rem">
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
            <i class="bi bi-check2-circle me-1"></i>Konfiguracja wstępna — zakończ
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Wyszukiwarka ──────────────────────────────────────────────────────── -->
  <div class="adm-search-wrap">
    <i class="bi bi-search"></i>
    <input id="admSearch" type="search" placeholder="Szukaj funkcji…" autocomplete="off">
    <button class="adm-search-clear" id="admSearchClear" title="Wyczyść"><i class="bi bi-x"></i></button>
  </div>

  <!-- ── Siatka kart ───────────────────────────────────────────────────────── -->
  <div class="adm-grid" id="admGrid">
  <?php foreach ($groups as $group_name => $group): ?>
    <?php $c = $color_map[$group['color']]; ?>
    <div class="adm-card" data-group="<?= h($group_name) ?>">

      <div class="adm-card-head" style="background:<?= $c['bg'] ?>;border-bottom-color:<?= $c['border'] ?>">
        <div class="adm-card-head-icon" style="background:<?= $c['icon_bg'] ?>;color:<?= $c['icon_text'] ?>">
          <i class="bi <?= $group['icon'] ?>"></i>
        </div>
        <div class="adm-card-head-label" style="color:<?= $c['head'] ?>">
          <?= h($group_name) ?>
        </div>
      </div>

      <div class="adm-items">
        <?php foreach ($group['items'] as $item): ?>
        <a href="<?= APP_URL . $item['url'] ?>"
           class="adm-item<?= !empty($item['danger']) ? ' danger' : '' ?>"
           data-label="<?= h(mb_strtolower($item['label'])) ?>">
          <i class="bi <?= $item['icon'] ?>"></i>
          <?= h($item['label']) ?>
          <?php if (!empty($item['badge'])): ?>
          <span class="adm-badge adm-badge-<?= h($item['badge_type'] ?? 'secondary') ?>">
            <?= (int)$item['badge'] ?>
          </span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

    </div>
  <?php endforeach; ?>

    <div id="admNoResults">
      <i class="bi bi-search" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
      Nie znaleziono funkcji pasujących do zapytania.
    </div>
  </div>

<?php if ($_is_service_account && $_saas_panel_url): ?>
<div class="adm-saas-banner mt-4">
  <div class="adm-saas-icon"><i class="bi bi-server"></i></div>
  <div class="adm-saas-body">
    <div class="adm-saas-label">Panel zarządzania tenantami SaaS</div>
    <div class="adm-saas-desc">Tworzenie i konfiguracja tenantów, provisioning baz danych, zarządzanie planami i modułami. Dostępny wyłącznie dla konta serwisowego.</div>
  </div>
  <a href="<?= h($_saas_panel_url) ?>" class="adm-saas-btn" target="_blank">
    <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz panel SaaS
  </a>
</div>
<style>
.adm-saas-banner {
  display:flex;align-items:center;gap:1.1rem;
  background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);
  border-radius:14px;padding:1.1rem 1.4rem;max-width:1200px;
  box-shadow:0 4px 20px rgba(79,70,229,.25);
}
.adm-saas-icon {
  width:46px;height:46px;border-radius:12px;flex-shrink:0;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);
  display:flex;align-items:center;justify-content:center;
  font-size:1.4rem;color:#a5b4fc;
}
.adm-saas-body   { flex:1;min-width:0; }
.adm-saas-label  { font-size:.8rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#a5b4fc;margin-bottom:.2rem; }
.adm-saas-desc   { font-size:.84rem;color:rgba(255,255,255,.65);line-height:1.4; }
.adm-saas-btn {
  flex-shrink:0;white-space:nowrap;
  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
  color:#fff;border-radius:8px;padding:.55rem 1.1rem;
  font-size:.84rem;font-weight:600;text-decoration:none;transition:background .15s;
}
.adm-saas-btn:hover { background:rgba(255,255,255,.2);color:#fff; }
@media (max-width:640px) { .adm-saas-banner { flex-direction:column;align-items:flex-start;gap:.75rem; } }
</style>
<?php endif; ?>

</div>

<script>
(function () {
  const input   = document.getElementById('admSearch');
  const clear   = document.getElementById('admSearchClear');
  const noRes   = document.getElementById('admNoResults');
  const cards   = document.querySelectorAll('.adm-card[data-group]');

  function filter(q) {
    q = q.trim().toLowerCase();
    clear.style.display = q ? 'block' : 'none';
    let anyVisible = false;

    cards.forEach(card => {
      const items = card.querySelectorAll('.adm-item[data-label]');
      let cardVisible = false;

      items.forEach(item => {
        const match = !q || item.dataset.label.includes(q);
        item.classList.toggle('adm-item-hidden', !match);
        if (match) cardVisible = true;
      });

      card.classList.toggle('adm-hidden', !cardVisible);
      if (cardVisible) anyVisible = true;
    });

    noRes.style.display = anyVisible ? 'none' : 'block';
  }

  input.addEventListener('input', () => filter(input.value));
  clear.addEventListener('click', () => { input.value = ''; input.focus(); filter(''); });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
