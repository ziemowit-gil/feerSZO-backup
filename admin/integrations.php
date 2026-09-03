<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Integracje';

// ── Status integracji ──────────────────────────────────────────────────────────
$int_m365       = (bool)(org_setting('m365_tenant_id'));
$int_sp         = (bool)(org_setting('sharepoint_site_url'));
$int_smtp       = (bool)(org_setting('smtp_host') ?: org_setting('m365_send_from_email'));
$int_sms        = (bool)(org_setting('sms_api_key') ?: org_setting('sms_api_token'));
$int_whatsapp   = (bool)(org_setting('whatsapp_token'));
$int_postivo    = (bool)(org_setting('postivo_api_key'));
$int_autenti    = (bool)(org_setting('autenti_client_id'));
$int_docusign   = (bool)(org_setting('docusign_account_id'));
$int_apaczka    = org_setting('apaczka_enabled') === '1' && (bool)(org_setting('apaczka_api_key'));
$int_furgonetka = org_setting('furgonetka_enabled') === '1';
$int_ceidg      = (bool)(org_setting('ceidg_api_key'));
$int_canva      = org_setting('canva_enabled') !== '0';
$int_ai         = (bool)(org_setting('ai_openai_key') ?: org_setting('ai_anthropic_key'));

$api_keys_count = 0;
$webhooks_count = 0;
try {
    $api_keys_count = (int)(db_one("SELECT COUNT(*) c FROM api_keys WHERE is_active=1")['c'] ?? 0);
    $webhooks_count = (int)(db_one("SELECT COUNT(*) c FROM webhook_endpoints WHERE is_active=1")['c'] ?? 0);
} catch (\Throwable $e) {}

$connected_total = array_sum(array_map('intval', [
    $int_m365, $int_sp, $int_smtp, $int_sms, $int_whatsapp, $int_postivo,
    $int_autenti, $int_docusign, $int_apaczka, $int_furgonetka,
    $int_ceidg, $int_canva, $int_ai,
    $api_keys_count > 0, $webhooks_count > 0,
]));

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.int-page  { max-width: 1100px; }
.int-section-head {
    font-size: .72rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #94A3B8;
    padding: .25rem 0 .5rem;
    border-bottom: 1px solid #F1F5F9;
    margin-bottom: .75rem;
    display: flex; align-items: center; gap: .4rem;
}
.int-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: .85rem 1rem;
    display: flex; align-items: center; gap: .85rem;
    transition: box-shadow .15s, border-color .15s;
    text-decoration: none; color: inherit;
    min-height: 72px;
}
.int-card:hover { box-shadow: 0 3px 14px rgba(0,0,0,.08); border-color: #CBD5E1; color: inherit; }
.int-icon {
    width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
}
.int-name  { font-size: .875rem; font-weight: 600; color: #0F172A; line-height: 1.2; }
.int-desc  { font-size: .75rem; color: #64748B; margin-top: .1rem; }
.int-badge { font-size: .68rem; font-weight: 600; padding: .15rem .5rem; border-radius: 20px; white-space: nowrap; }
.int-badge-ok  { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
.int-badge-off { background: #F8FAFC; color: #94A3B8; border: 1px solid #E2E8F0; }
</style>

<div class="int-page">

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-plug text-primary me-2"></i>Integracje</h4>
    <div class="text-muted small"><?= $connected_total ?> skonfigurowanych · klucze API, webhooki i usługi zewnętrzne</div>
  </div>
</div>

<?= flash_html() ?>

<?php
function _icard(string $icon, string $name, string $desc, bool $ok, string $url, string $color, string $badge_override = ''): void {
    global $APP_URL;
    $app = defined('APP_URL') ? APP_URL : '';
    $bg  = $color . '18';
    $badge = $badge_override ?: ($ok
        ? '<span class="int-badge int-badge-ok"><i class="bi bi-check-circle me-1"></i>Połączone</span>'
        : '<span class="int-badge int-badge-off"><i class="bi bi-circle me-1"></i>Nieskonfigurowane</span>');
    echo '<div class="col-sm-6 col-lg-4">';
    echo '<a href="' . $app . h($url) . '" class="int-card">';
    echo '<div class="int-icon" style="background:' . $bg . ';color:' . h($color) . '">';
    echo '<i class="bi ' . h($icon) . '"></i></div>';
    echo '<div class="flex-grow-1 overflow-hidden">';
    echo '<div class="int-name">' . h($name) . '</div>';
    echo '<div class="int-desc">' . h($desc) . '</div>';
    echo '</div>';
    echo '<div class="ms-auto ps-2 flex-shrink-0">' . $badge . '</div>';
    echo '</a></div>';
}

function _isection(string $icon, string $label): void {
    echo '<div class="col-12"><div class="int-section-head"><i class="bi ' . $icon . '"></i>' . h($label) . '</div></div>';
}
?>

<div class="row g-3">

  <?php _isection('bi-microsoft', 'Microsoft & Chmura') ?>

  <?php _icard('bi-microsoft',         'Microsoft 365',       'Konta, M365 Groups, licencje',            $int_m365,  '/admin/m365.php',                '#0078d4') ?>
  <?php _icard('bi-cloud-upload-fill', 'SharePoint — pliki',  'Synchronizacja i przechowywanie plików',  $int_sp,    '/admin/sharepoint_settings.php',  '#038387') ?>
  <?php _icard('bi-cloud-arrow-up',    'SharePoint Backup',   'Automatyczny backup bazy danych',         $int_sp,    '/admin/sp_onboarding.php',        '#038387') ?>

  <?php _isection('bi-envelope', 'Komunikacja') ?>

  <?php _icard('bi-envelope-fill',     'E-mail (SMTP/M365)',  'Wysyłka przez SMTP lub Microsoft Graph',  $int_smtp,     '/admin/org_settings.php#mail',    '#d97706') ?>
  <?php _icard('bi-chat-text-fill',    'SMS',                 'Powiadomienia SMS przez bramkę API',       $int_sms,      '/admin/sms_settings.php',         '#7c3aed') ?>
  <?php _icard('bi-whatsapp',          'WhatsApp',            'Wiadomości przez WhatsApp Business API',   $int_whatsapp, '/admin/whatsapp_settings.php',    '#25d366') ?>
  <?php _icard('bi-envelope-at-fill',  'Postivo',             'Wysyłka korespondencji przez Postivo',     $int_postivo,  '/admin/postivo_settings.php',     '#f59e0b') ?>

  <?php _isection('bi-pen-fill', 'Podpisy elektroniczne') ?>

  <?php _icard('bi-pen-fill',          'Autenti eSign',       'Podpisywanie dokumentów przez Autenti',   $int_autenti,  '/admin/autenti_settings.php',     '#1e40af') ?>
  <?php _icard('bi-pen-fill',          'DocuSign',            'Podpisywanie dokumentów przez DocuSign',  $int_docusign, '/admin/docusign_settings.php',    '#ffb900') ?>

  <?php _isection('bi-box-seam', 'Logistyka') ?>

  <?php _icard('bi-box-seam-fill',     'Apaczka',             'Nadawanie paczek — Apaczka.pl',           $int_apaczka,    '/admin/apaczka_settings.php',    '#f97316') ?>
  <?php _icard('bi-truck',             'Furgonetka',          'Nadawanie paczek — Furgonetka.pl',        $int_furgonetka, '/admin/furgonetka_settings.php', '#0369a1') ?>
  <?php _icard('bi-building-check',    'CEIDG',               'Weryfikacja firm z rejestru CEIDG',       $int_ceidg,      '/admin/ceidg_settings.php',      '#16a34a') ?>

  <?php _isection('bi-mortarboard', 'E-learning & Zasoby') ?>

  <?php _icard('bi-key-fill',          'Kody dostępowe M365', 'Automatyczne zakładanie kont M365 z kodów LCCC', $int_m365, '/admin/ms_edu_codes.php', '#0078d4') ?>
  <?php _icard('bi-palette2',          'Canva Pro',           'Dostęp do kont Canva Pro dla zespołu',     $int_canva,  '/admin/canva.php',     '#7d2ae8') ?>

  <?php _isection('bi-stars', 'AI') ?>

  <?php _icard('bi-stars',             'Asystent AI',         'OpenAI lub Anthropic — generowanie treści', $int_ai, '/admin/ai_settings.php', '#8b5cf6') ?>

  <?php _isection('bi-code-slash', 'API & Integracje deweloperskie') ?>

  <?php
  $api_badge = '<span class="int-badge ' . ($api_keys_count > 0 ? 'int-badge-ok' : 'int-badge-off') . '">'
             . ($api_keys_count > 0 ? '<i class="bi bi-check-circle me-1"></i>' . $api_keys_count . ' aktywnych' : '<i class="bi bi-circle me-1"></i>Brak')
             . '</span>';
  $wh_badge  = '<span class="int-badge ' . ($webhooks_count > 0 ? 'int-badge-ok' : 'int-badge-off') . '">'
             . ($webhooks_count > 0 ? '<i class="bi bi-check-circle me-1"></i>' . $webhooks_count . ' aktywnych' : '<i class="bi bi-circle me-1"></i>Brak')
             . '</span>';
  ?>
  <?php _icard('bi-key-fill',          'Klucze API',          'Dostęp zewnętrznych aplikacji do systemu',    $api_keys_count > 0, '/admin/api_manage.php?tab=api',          '#2563eb', $api_badge) ?>
  <?php _icard('bi-webhook',           'Webhooki',            'Powiadomienia o zdarzeniach na zewnętrzne URL', $webhooks_count > 0, '/admin/api_manage.php?tab=webhooks',     '#2563eb', $wh_badge) ?>
  <?php _icard('bi-puzzle-fill',       'Integracje zewnętrzne', 'Zarządzaj połączeniami z platformami',      false,               '/admin/api_manage.php?tab=integrations', '#6366f1', '<span class="int-badge int-badge-off"><i class="bi bi-gear me-1"></i>Konfiguruj</span>') ?>

  <?php _isection('bi-file-earmark-arrow-up', 'Import danych') ?>

  <?php _icard('bi-trello',                'Import z Trello',  'Importuj zadania z tablicy Trello',     false, '/admin/trello_import.php',    '#0052cc', '<span class="int-badge int-badge-off"><i class="bi bi-arrow-right me-1"></i>Otwórz</span>') ?>
  <?php _icard('bi-file-earmark-arrow-up', 'Import CSV',       'Import wolontariuszy z pliku CSV',      false, '/admin/import_volunteers.php', '#0369a1', '<span class="int-badge int-badge-off"><i class="bi bi-arrow-right me-1"></i>Otwórz</span>') ?>

</div>

</div><!-- /int-page -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
