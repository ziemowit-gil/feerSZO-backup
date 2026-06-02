<?php
/**
 * admin/index.php — Panel Administratora
 *
 * Centralny dashboard wszystkich funkcji administracyjnych.
 * Zastępuje rozsiane linki w bocznym pasku nawigacyjnym.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Administrator';

// ── Liczniki do odznak ────────────────────────────────────────────────────────
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

// ── Flaga konta serwisowego ───────────────────────────────────────────────────
$_is_service_account = (current_user()['email'] ?? '') === 'serwis@local';

// URL panelu SaaS (działa zarówno dla tenant jak i standalone)
$_saas_panel_url = null;
if ($_is_service_account) {
    if (defined('TENANT_SLUG') && TENANT_SLUG !== '') {
        $_saas_panel_url = preg_replace(
            '#/org/' . preg_quote(TENANT_SLUG, '#') . '$#', '',
            rtrim(APP_URL, '/')
        ) . '/saas-tenent/x/';
    } else {
        // Instalacja standalone — panel SaaS względem APP_URL
        $_saas_panel_url = rtrim(APP_URL, '/') . '/saas-tenent/x/';
    }
}

// ── Grupy kafelków ────────────────────────────────────────────────────────────
$groups = [

    'Użytkownicy i bezpieczeństwo' => [
        'icon'  => 'bi-shield-person',
        'color' => 'blue',
        'items' => [
            ['icon'=>'bi-people',          'label'=>'Użytkownicy',       'url'=>'/admin/users.php',         'badge'=>$cnt['users'] ?: null,   'badge_type'=>'secondary'],
            ['icon'=>'bi-shield-lock',     'label'=>'Role i uprawnienia','url'=>'/admin/roles.php'],
            ['icon'=>'bi-key',             'label'=>'Kody IKA',          'url'=>'/admin/manage_cpc.php'],
            ['icon'=>'bi-door-open',       'label'=>'Metody logowania',  'url'=>'/admin/login_settings.php'],
            ['icon'=>'bi-journal-text',    'label'=>'Dziennik zdarzeń',  'url'=>'/admin/log.php',           'badge'=>$cnt['log_today'] ?: null,'badge_type'=>'info'],
            ['icon'=>'bi-person-badge',    'label'=>'Podszywanie',       'url'=>'/admin/impersonate.php'],
        ],
    ],

    'Organizacja i system' => [
        'icon'  => 'bi-building',
        'color' => 'purple',
        'items' => [
            ['icon'=>'bi-building',        'label'=>'Dane organizacji',  'url'=>'/admin/org_settings.php'],
            ['icon'=>'bi-toggles2',        'label'=>'Moduły',            'url'=>'/admin/modules_settings.php'],
            ['icon'=>'bi-calendar-event-fill','label'=>'Ustawienia wydarzeń','url'=>'/admin/events_settings.php'],
            ['icon'=>'bi-diagram-3',       'label'=>'Workflow akceptacji','url'=>'/admin/approval_workflows.php'],
            ['icon'=>'bi-ui-checks-grid',  'label'=>'SelfService',       'url'=>'/onboarding/settings.php'],
            ['icon'=>'bi-kanban',          'label'=>'Obszary zadań',     'url'=>'/admin/tasks_workspaces.php'],
            ['icon'=>'bi-tags',            'label'=>'Tagi zadań',        'url'=>'/admin/tasks_tags.php'],
            ['icon'=>'bi-person-lines-fill','label'=>'Pola profilu',     'url'=>'/admin/profile_fields.php'],
            ['icon'=>'bi-ui-checks',       'label'=>'Pola w formularzach','url'=>'/admin/form_fields.php'],
        ],
    ],

    'Komunikacja' => [
        'icon'  => 'bi-envelope',
        'color' => 'green',
        'items' => [
            ['icon'=>'bi-bell',            'label'=>'Powiadomienia e-mail','url'=>'/admin/msg_settings.php'],
            ['icon'=>'bi-send-check',      'label'=>'Kolejka e-mail',    'url'=>'/admin/mail_queue.php',    'badge'=>$cnt['mail_failed'] ?: null,'badge_type'=>'danger'],
            ['icon'=>'bi-tags',            'label'=>'Typy wiadomości',   'url'=>'/admin/message_types.php'],
        ],
    ],

    'Wnioski i dokumenty' => [
        'icon'  => 'bi-inbox',
        'color' => 'orange',
        'items' => [
            ['icon'=>'bi-inbox',           'label'=>'Pisma / wnioski',   'url'=>'/admin/applications.php',  'badge'=>$cnt['applications'] ?: null,'badge_type'=>'danger'],
            ['icon'=>'bi-ui-checks-grid',  'label'=>'Typy wniosków',     'url'=>'/admin/application_types.php'],
        ],
    ],

    'E-learning' => [
        'icon'  => 'bi-mortarboard',
        'color' => 'teal',
        'items' => [
            ['icon'=>'bi-mortarboard',     'label'=>'Moodle',            'url'=>'/admin/moodle.php',         'badge'=>$cnt['moodle_pending'] ?: null,'badge_type'=>'warning'],
            ['icon'=>'bi-building-heart',  'label'=>'Zasady i Wprowadzenie', 'url'=>'/admin/org_rules.php',   'danger'=>false],

        ],
    ],

    'Zasoby organizacji' => [
        'icon'  => 'bi-calendar-check-fill',
        'color' => 'teal',
        'items' => [
            ['icon'=>'bi-calendar-check',    'label'=>'Rezerwacje',          'url'=>'/resources/admin/',           'badge'=>$cnt['res_pending'] ?: null, 'badge_type'=>'warning'],
            ['icon'=>'bi-box',               'label'=>'Zasoby',              'url'=>'/resources/admin/resources.php'],
            ['icon'=>'bi-tags',              'label'=>'Kategorie zasobów',   'url'=>'/resources/admin/categories.php'],
        ],
    ],

    'Integracje' => [
        'icon'  => 'bi-plug',
        'color' => 'slate',
        'items' => [
            ['icon'=>'bi-microsoft',       'label'=>'Microsoft 365',     'url'=>'/admin/m365.php'],
            ['icon'=>'bi-chat-dots',       'label'=>'SMS',               'url'=>'/admin/sms_settings.php'],
            ['icon'=>'bi-whatsapp',        'label'=>'WhatsApp',          'url'=>'/admin/whatsapp_settings.php'],
            ['icon'=>'bi-envelope-at',     'label'=>'Postivo (maile)',    'url'=>'/admin/postivo_settings.php'],
            ['icon'=>'bi-building-check',  'label'=>'CEIDG',             'url'=>'/admin/ceidg_settings.php'],
            ['icon'=>'bi-box-seam',        'label'=>'Apaczka',           'url'=>'/admin/apaczka_settings.php'],
            ['icon'=>'bi-truck',           'label'=>'Furgonetka',        'url'=>'/admin/furgonetka_settings.php'],
        ],
    ],

    'Dokumenty Księgowe' => [
        'icon'  => 'bi-file-earmark-check',
        'color' => 'green',
        'items' => [
            ['icon'=>'bi-shield-lock',   'label'=>'Role dostępu',        'url'=>'/admin/ksiegowosc_roles.php'],
            ['icon'=>'bi-patch-check',   'label'=>'Certyfikaty X.509 i IKA', 'url'=>'/admin/kdok_certs.php'],
            ['icon'=>'bi-gear',          'label'=>'Ustawienia (baza, MPK)', 'url'=>'/admin/ksiegowosc_settings.php'],
            ['icon'=>'bi-arrow-repeat',  'label'=>'Wyczyść stare PDF',  'url'=>'/admin/kdok_cleanup_run.php'],
        ],
    ],

    'Narzędzia' => [
        'icon'  => 'bi-tools',
        'color' => 'red',
        'items' => [
            ['icon'=>'bi-info-circle',       'label'=>'Informacje o systemie', 'url'=>'/admin/system_info.php', 'danger'=>false],
            ['icon'=>'bi-archive',           'label'=>'Kopie zapasowe',        'url'=>'/admin/backups.php',     'danger'=>false],
            ['icon'=>'bi-layers',            'label'=>'Obszary zadań',         'url'=>'/admin/task_areas.php',  'danger'=>false],
            ['icon'=>'bi-database-fill-gear','label'=>'Narzędzia bazy danych','url'=>'/admin/db_tools.php',     'danger'=>false],
            ['icon'=>'bi-trash3',          'label'=>'Wyczyść bazę',      'url'=>'/admin/clean_db.php',       'danger'=>true],
            ['icon'=>'bi-journal-text',    'label'=>'Przeglądarka logów',    'url'=>'/admin/logs_global.php',  'danger'=>false],
            ['icon'=>'bi-database-gear',   'label'=>'Zarządzanie migracjami', 'url'=>'/admin/migrations.php',   'danger'=>false],
            ['icon'=>'bi-link-45deg',      'label'=>'Krótkie linki (aliasy)', 'url'=>'/admin/short_urls.php',  'danger'=>false],
        ],
    ],

];

// ── Kolory grup → CSS ─────────────────────────────────────────────────────────
$color_map = [
    'blue'   => ['bg'=>'#EFF6FF','border'=>'#BFDBFE','icon_bg'=>'#2563EB','icon_text'=>'#fff','head'=>'#1E40AF'],
    'purple' => ['bg'=>'#F5F3FF','border'=>'#DDD6FE','icon_bg'=>'#7C3AED','icon_text'=>'#fff','head'=>'#5B21B6'],
    'green'  => ['bg'=>'#F0FDF4','border'=>'#BBF7D0','icon_bg'=>'#16A34A','icon_text'=>'#fff','head'=>'#15803D'],
    'orange' => ['bg'=>'#FFF7ED','border'=>'#FED7AA','icon_bg'=>'#EA580C','icon_text'=>'#fff','head'=>'#9A3412'],
    'teal'   => ['bg'=>'#F0FDFA','border'=>'#99F6E4','icon_bg'=>'#0D9488','icon_text'=>'#fff','head'=>'#0F766E'],
    'slate'  => ['bg'=>'#F8FAFC','border'=>'#CBD5E1','icon_bg'=>'#475569','icon_text'=>'#fff','head'=>'#1E293B'],
    'red'    => ['bg'=>'#FFF1F2','border'=>'#FECDD3','icon_bg'=>'#DC2626','icon_text'=>'#fff','head'=>'#991B1B'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.adm-page  { max-width: 1200px; padding: 2rem 1.5rem; }
.adm-title { font-size: 1.6rem; font-weight: 800; color: #0F172A; margin-bottom: .25rem; display:flex;align-items:center;gap:.6rem; }
.adm-sub   { font-size: .9rem;  color: #64748B; margin-bottom: 2.25rem; }

/* Siatka grup */
.adm-grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }

/* Karta grupy */
.adm-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    transition: box-shadow .15s;
}
.adm-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }

.adm-card-head {
    display: flex; align-items: center; gap: .75rem;
    padding: .9rem 1.1rem;
    border-bottom: 1px solid #F1F5F9;
}
.adm-card-head-icon {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.adm-card-head-label {
    font-size: .82rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase;
}

/* Lista linków w karcie */
.adm-items { padding: .35rem 0; }
.adm-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .55rem 1.1rem;
    font-size: .88rem; font-weight: 500; color: #334155;
    text-decoration: none;
    transition: background .1s, color .1s;
    border-left: 3px solid transparent;
}
.adm-item i { font-size: .95rem; width: 18px; text-align: center; color: #94A3B8; flex-shrink: 0; transition: color .1s; }
.adm-item:hover { background: #F8FAFC; color: #0F172A; border-left-color: #2563EB; }
.adm-item:hover i { color: #2563EB; }
.adm-item.danger { color: #DC2626; }
.adm-item.danger i { color: #FCA5A5; }
.adm-item.danger:hover { background: #FFF1F2; border-left-color: #DC2626; }
.adm-item.danger:hover i { color: #DC2626; }
.adm-item .adm-badge {
    margin-left: auto; font-size: .65rem; font-weight: 700;
    padding: .15rem .45rem; border-radius: 10px; line-height: 1.4;
}
.adm-badge-danger   { background: #FEE2E2; color: #DC2626; }
.adm-badge-warning  { background: #FEF9C3; color: #A16207; }
.adm-badge-info     { background: #DBEAFE; color: #1D4ED8; }
.adm-badge-secondary{ background: #F1F5F9; color: #475569; }

@media (max-width: 640px) {
    .adm-grid { grid-template-columns: 1fr; }
    .adm-page { padding: 1.25rem 1rem; }
}
</style>

<div class="adm-page">

  <div class="adm-title">
    <i class="bi bi-shield-shaded" style="color:#2563eb"></i>
    Administrator
  </div>
  <p class="adm-sub">Centrum zarządzania systemem — użytkownicy, ustawienia, integracje i narzędzia.</p>

  <div class="adm-grid">
  <?php foreach ($groups as $group_name => $group): ?>
    <?php $c = $color_map[$group['color']]; ?>
    <div class="adm-card">

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
           class="adm-item<?= !empty($item['danger']) ? ' danger' : '' ?>">
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
  </div>

<?php if ($_is_service_account && $_saas_panel_url): ?>
<!-- ── Panel SaaS — tylko dla serwis@local ──────────────────────────────── -->
<div class="adm-saas-banner mt-4">
  <div class="adm-saas-icon" aria-hidden="true">
    <i class="bi bi-server"></i>
  </div>
  <div class="adm-saas-body">
    <div class="adm-saas-label">Panel zarządzania tenantami SaaS</div>
    <div class="adm-saas-desc">
      Tworzenie i konfiguracja tenantów, provisioning baz danych, zarządzanie planami i modułami.
      Dostępny wyłącznie dla konta serwisowego.
    </div>
  </div>
  <a href="<?= h($_saas_panel_url) ?>" class="adm-saas-btn" target="_blank">
    <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz panel SaaS
  </a>
</div>
<style>
.adm-saas-banner {
  display: flex; align-items: center; gap: 1.1rem;
  background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
  border-radius: 14px; padding: 1.1rem 1.4rem;
  max-width: 1200px;
  box-shadow: 0 4px 20px rgba(79,70,229,.25);
}
.adm-saas-icon {
  width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
  background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem; color: #a5b4fc;
}
.adm-saas-body { flex: 1; min-width: 0; }
.adm-saas-label {
  font-size: .8rem; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; color: #a5b4fc; margin-bottom: .2rem;
}
.adm-saas-desc { font-size: .84rem; color: rgba(255,255,255,.65); line-height: 1.4; }
.adm-saas-btn {
  flex-shrink: 0; white-space: nowrap;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2);
  color: #fff; border-radius: 8px; padding: .55rem 1.1rem;
  font-size: .84rem; font-weight: 600; text-decoration: none;
  transition: background .15s;
}
.adm-saas-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
@media (max-width: 640px) {
  .adm-saas-banner { flex-direction: column; align-items: flex-start; gap: .75rem; }
}
</style>
<?php endif; ?>

</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
