<?php
/**
 * crm/includes/header_crm.php — CRM layout z lewym sidebarem.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

// ── Weryfikacja IKA — wymagana dla całego CRM ────────────────────────────────
// Sesja IKA jest współdzielona z systemem głównym (30 min). Użytkownicy bez
// przypisanego kodu IKA mogą poprosić admina o jego nadanie (Administracja → Kody IKA).
(function () {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    ika_require(APP_URL . $uri);
})();

$_cu        = current_user();
$_crm_title = $PAGE_TITLE ?? 'CRM';
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_crm_can_write = (is_admin() || can_write('crm'));

function _crm_nav_active(string $path): string {
    global $_uri;
    return str_contains($_uri, $path) ? ' active' : '';
}

$_cu_initials = '?';
$_cu_name     = '';
if ($_cu) {
    $name = trim(($_cu['first_name'] ?? '') . ' ' . ($_cu['last_name'] ?? ''));
    if ($name === '') $name = $_cu['name'] ?? '';
    $parts = preg_split('/\s+/', trim($name));
    $_cu_initials = '';
    foreach ($parts as $w) $_cu_initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    $_cu_initials = mb_substr($_cu_initials, 0, 2, 'UTF-8') ?: '?';
    $_cu_name = $name ?: ($_cu['email'] ?? 'Użytkownik');
}

// Statystyki do sidebara
try {
    $_crm_total = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1")['c'] ?? 0);
} catch (\Throwable $e) { $_crm_total = 0; }

// Nieprzeczytane w skrzynce współdzielonej (badge) — tabela może nie istnieć
$_crm_inbox_unread = 0;
try {
    $_crm_inbox_unread = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_communications
         WHERE direction='in' AND is_read=0 AND inbox_status='active'"
    )['c'] ?? 0);
} catch (\Throwable $e) {}

// Moje działania zaległe i na dziś (badge). Liczymy tylko to, co WYMAGA reakcji —
// plan na przyszły tydzień nie ma migać w nawigacji.
$_crm_acts_due = 0;
try {
    $_crm_acts_due = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_activities a
           JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
          WHERE a.status='planned' AND a.assigned_to=?
            AND a.scheduled_at IS NOT NULL
            AND date(a.scheduled_at) <= date('now','localtime')",
        [(int)(current_user()['id'] ?? 0)]
    )['c'] ?? 0);
} catch (\Throwable $e) {}

// Liczniki modułu Oferty (badge w pasku) — cicho, gdy modułu jeszcze nie migrowano
$_crm_offers_pending = 0;
$_crm_offers_noconf  = 0;
try {
    $_crm_offers_pending = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_offers WHERE deleted_at IS NULL AND status IN ('szkic','do_zatwierdzenia','wyslana')"
    )['c'] ?? 0);
    $_crm_offers_noconf = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_offers
         WHERE deleted_at IS NULL AND requires_confirmation=1 AND confirmation_id IS NULL
           AND status IN ('wyslana','zaakceptowana')"
    )['c'] ?? 0);
} catch (\Throwable $e) {}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_crm_title) ?> — CRM<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php $_crm_css_path = dirname(dirname(__DIR__)) . '/assets/css/crm-module.css';
      $_crm_css_v = @filemtime($_crm_css_path) ?: date('Ymd'); ?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/crm-module.css?v=<?= $_crm_css_v ?>">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
/* ── CRM Shell ───────────────────────────────────────────────────────── */
:root {
  --crm-sidebar-w: 220px;
  --crm-topbar-h: 52px;
  --crm-navbar-h: 46px;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }

body {
  /* Białe tło modułu — karty wyróżniają się obramowaniem, nie kontrastem tła. */
  background: #FFFFFF;
  font-family: system-ui, -apple-system, sans-serif;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ══ TOPBAR ══════════════════════════════════════════════════════════ */
.crm-topbar {
  height: var(--crm-topbar-h);
  background: #fff;
  border-bottom: 1px solid #E5E7EB;
  display: flex;
  align-items: center;
  padding: 0 1.25rem 0 0;
  gap: 0;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 1040;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

/* Brand w topbarze */
.crm-topbar-brand {
  width: auto;
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: 0 1.1rem;
  flex-shrink: 0;
  text-decoration: none;
  color: var(--crm-primary-dark);
  font-weight: 800;
  font-size: 1rem;
  letter-spacing: .3px;
  border-right: 1px solid #E5E7EB;
  height: 100%;
}
.crm-topbar-brand-icon {
  width: 30px; height: 30px;
  background: linear-gradient(135deg, #194E31 0%, #2E844A 100%);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .9rem; flex-shrink: 0;
}
.crm-topbar-brand-org {
  font-size: .64rem; color: #9CA3AF; font-weight: 400;
  line-height: 1; letter-spacing: 0;
}

/* Breadcrumb w topbarze */
.crm-topbar-breadcrumb {
  flex: 1;
  padding: 0 1.25rem;
  font-size: .83rem;
  color: #6B7280;
  display: flex; align-items: center; gap: .4rem;
  overflow: hidden;
}
.crm-topbar-breadcrumb a { color: var(--crm-primary); text-decoration: none; }
.crm-topbar-breadcrumb a:hover { text-decoration: underline; }
.crm-topbar-breadcrumb .sep { color: #D1D5DB; font-size: .75rem; }
.crm-topbar-page { font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* User area w topbarze */
.crm-topbar-user {
  display: flex; align-items: center; gap: .75rem;
  padding-left: 1rem;
}
.crm-topbar-avatar {
  width: 32px; height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, #194E31 0%, #2E844A 100%);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; font-weight: 700;
  cursor: pointer;
  border: 2px solid #E5E7EB;
}
.crm-topbar-username { font-size: .83rem; font-weight: 500; color: #374151; }

/* Powrót do systemu głównego */
.crm-topbar-sys-link {
  display: inline-flex; align-items: center; gap: .4rem;
  font-size: .78rem; color: #9CA3AF;
  text-decoration: none; padding: .25rem .6rem;
  border: 1px solid #E5E7EB; border-radius: 6px;
  transition: all .12s;
  white-space: nowrap;
}
.crm-topbar-sys-link:hover,
.crm-topbar-sys-link.active { color: var(--crm-primary); border-color: var(--crm-primary); background: var(--crm-primary-bg); }
.crm-topbar-sys-link i { font-size: .9rem; }
/* Wyloguj — zawsze widoczne, także na telefonie (patrz RESPONSIVE niżej) */
.crm-topbar-logout { color: #B42318; border-color: #FECDCA; }
.crm-topbar-logout:hover,
.crm-topbar-logout:focus-visible { color: #fff; background: #B42318; border-color: #B42318; }

/* ══ TOP NAVBAR (poziome menu — pod topbarem) ════════════════════════ */
.crm-navbar {
  position: fixed;
  top: var(--crm-topbar-h);
  left: 0; right: 0;
  height: var(--crm-navbar-h);
  z-index: 1035;
  background: #fff;
  border-bottom: 1px solid #E5E7EB;
  display: flex;
  align-items: center;
  gap: .25rem;
  padding: 0 1rem;
  box-shadow: 0 1px 2px rgba(0,0,0,.04);
}
.crm-navbar-scroll {
  display: flex; align-items: center; gap: .1rem;
  flex: 1; height: 100%;
  overflow-x: auto; overflow-y: hidden;
  scrollbar-width: none; -ms-overflow-style: none;
}
.crm-navbar-scroll::-webkit-scrollbar { display: none; }
.crm-navlink {
  display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap;
  padding: .4rem .7rem; border-radius: 7px;
  font-size: .83rem; font-weight: 500; color: #374151;
  text-decoration: none; flex-shrink: 0; transition: background .1s, color .1s;
}
.crm-navlink i { font-size: .95rem; color: #9CA3AF; transition: color .1s; }
.crm-navlink:hover { background: #F9FAFB; color: var(--crm-primary); }
.crm-navlink:hover i { color: var(--crm-primary); }
.crm-navlink.active { background: var(--crm-primary-bg); color: var(--crm-primary); font-weight: 600; }
.crm-navlink.active i { color: var(--crm-primary); }
.crm-navlink .crm-nav-badge {
  background: #E5E7EB; color: #6B7280; font-size: .65rem; font-weight: 700;
  padding: .1rem .4rem; border-radius: 10px; min-width: 18px; text-align: center;
}
.crm-navlink.active .crm-nav-badge { background: var(--crm-primary); color: #fff; }
.crm-navbar-actions {
  display: flex; align-items: center; gap: .4rem; flex-shrink: 0;
  padding-left: .6rem; margin-left: .25rem; border-left: 1px solid #E5E7EB;
}
.crm-navbar .dropdown-menu {
  font-size: .85rem; border-color: #E5E7EB; border-radius: 9px;
  box-shadow: 0 6px 24px rgba(0,0,0,.12); padding: .3rem;
}
.crm-navbar .dropdown-item { padding: .45rem .8rem; border-radius: 6px; }
.crm-navbar .dropdown-item i { color: #9CA3AF; }
.crm-navbar .dropdown-item:hover { background: #F1F5F9; color: var(--crm-primary); }
.crm-navbar .dropdown-item:hover i { color: var(--crm-primary); }

/* ══ MAIN CONTENT ═══════════════════════════════════════════════════ */
.crm-shell {
  margin-top: calc(var(--crm-topbar-h) + var(--crm-navbar-h));
  margin-left: 0;
  min-height: calc(100vh - var(--crm-topbar-h) - var(--crm-navbar-h));
  display: flex;
  flex-direction: column;
}
.crm-content {
  flex: 1;
  padding: 1.5rem;
  /* Bez sztywnego limitu szerokości — moduły CRM (skrzynka, listy, kartoteka)
     mają wykorzystać cały ekran, a nie zostawiać pasy pustki po bokach. */
  max-width: none;
  width: 100%;
}
.crm-footer {
  background: #fff;
  border-top: 1px solid #E5E7EB;
  padding: .5rem 1.5rem;
  font-size: .73rem;
  color: #9CA3AF;
  display: flex; justify-content: space-between; align-items: center;
  margin-left: 0;
}

/* ══ TRYB PEŁNOEKRANOWY ══════════════════════════════════════════════ */
body.crm-fullscreen .crm-content { max-width: 100%; }

/* ══ RESPONSIVE ══════════════════════════════════════════════════════ */
@media (max-width: 768px) {
  .crm-topbar-brand { border-right: none; }
  .crm-topbar-username { display: none; }
  .crm-topbar-sys-link { display: none; }
  .crm-topbar-sys-link.crm-topbar-logout { display: inline-flex; }  /* wylogowanie zostaje pod ręką */
  #mod-sw { display: none; }
  .crm-content { padding: 1rem .75rem; }
  /* Na telefonie skróć przyciski akcji do samych ikon */
  .crm-navbar-actions .crm-act-label { display: none; }
}

/* ══ PAGE HEADER (standardowy nagłówek strony) ═══════════════════════ */
.crm-page-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 1rem; flex-wrap: wrap;
  margin-bottom: 1.25rem;
}
.crm-page-title {
  font-size: 1.3rem; font-weight: 700; color: #111827; margin: 0 0 .15rem;
  display: flex; align-items: center; gap: .5rem;
}
.crm-page-subtitle { font-size: .82rem; color: #6B7280; }
.crm-page-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; flex-shrink: 0; }
</style>
<?php /* CRM to głównie tabele i listy — tło ledwie zaznaczone */ ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/app_bg.php'; app_bg_css('#EFF1F5', 'subtle'); ?>
<!-- Quill — ładowany globalnie, potrzebny dla modalnego kompozytora -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
</head>
<body>

<!-- Skip link — ułatwia pominięcie nawigacji dla czytników ekranu i klawiatury -->
<a href="#crmMain" class="skip-link">Przejdź do treści</a>

<!-- ══ Modal: Compose (wyślij wiadomość) ══════════════════════════════════════ -->
<div class="modal fade" id="crmComposeModal" tabindex="-1" aria-labelledby="crmComposeModalLabel">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fw-bold" id="crmComposeModalLabel">
          <i class="bi bi-send-fill text-primary me-2" aria-hidden="true"></i>
          <span id="crmComposeModalTitle">Wyślij wiadomość</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body" id="crmComposeModalBody">
        <div class="text-center py-5 text-muted">
          <div class="spinner-border spinner-border-sm" role="status"></div>
          <div class="mt-2 small">Ładowanie…</div>
        </div>
      </div>
      <div class="modal-footer py-2" id="crmComposeModalFooter">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<script>
/**
 * openCommModal(contactId, channel) — otwiera modalny composer wiadomości.
 * Można wywołać z dowolnego miejsca w CRM.
 * contactId = 0 (lub brak) → kompozytor od zera: odbiorcę wybiera się w oknie.
 */
window.openCommModal = function(contactId, channel) {
    contactId = parseInt(contactId, 10) || 0;
    channel = channel || 'email';
    var modalEl = document.getElementById('crmComposeModal');
    var modal   = bootstrap.Modal.getOrCreateInstance(modalEl);
    var body    = document.getElementById('crmComposeModalBody');
    var foot    = document.getElementById('crmComposeModalFooter');
    var title   = document.getElementById('crmComposeModalTitle');

    // Reset
    body.innerHTML  = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div><div class="mt-2 small">Ładowanie…</div></div>';
    foot.innerHTML  = '<button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>';
    title.textContent = contactId ? 'Wyślij wiadomość' : 'Nowa wiadomość';

    modal.show();

    // Pobierz fragment — uruchom JS dopiero gdy modal jest w pełni widoczny (Quill wymaga widocznego kontenera)
    var _pending_html = null;
    var _modal_shown  = false;

    function _inject(html) {
        body.innerHTML = html;
        body.querySelectorAll('script').forEach(function(old) {
            var s = document.createElement('script');
            Array.from(old.attributes).forEach(function(a) { s.setAttribute(a.name, a.value); });
            s.textContent = old.textContent;
            old.parentNode.replaceChild(s, old);
        });
    }

    modalEl.addEventListener('shown.bs.modal', function _onShown() {
        modalEl.removeEventListener('shown.bs.modal', _onShown);
        _modal_shown = true;
        if (_pending_html !== null) _inject(_pending_html);
    });

    fetch('<?= APP_URL ?>/crm/compose_modal.php?contact_id=' + encodeURIComponent(contactId) + '&channel=' + encodeURIComponent(channel))
        .then(function(r) { return r.text(); })
        .then(function(html) {
            if (_modal_shown) {
                _inject(html);
            } else {
                _pending_html = html; // czekaj na shown.bs.modal
            }
        })
        .catch(function() {
            body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania formularza.</div>';
        });
};
</script>

<!-- ══ TOPBAR ══════════════════════════════════════════════════════════════════ -->
<header class="crm-topbar" role="banner">

  <!-- Brand (lewa część topbara) -->
  <a href="<?= APP_URL ?>/crm/dashboard.php" class="crm-topbar-brand" aria-label="CRM — dashboard">
    <div class="crm-topbar-brand-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
    <div>
      <div>CRM</div>
      <?php if ($_org_name): ?>
      <div class="crm-topbar-brand-org" style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= h($_org_name) ?>"><?= h($_org_name) ?></div>
      <?php endif; ?>
    </div>
  </a>

  <!-- Breadcrumb / tytuł strony -->
  <div class="crm-topbar-breadcrumb">
    <span class="d-none d-sm-inline">
      <a href="<?= APP_URL ?>/crm/dashboard.php"><i class="bi bi-diagram-2-fill" style="color:var(--crm-primary)"></i></a>
      <span class="sep mx-1">/</span>
    </span>
    <span class="crm-topbar-page"><?= h($_crm_title) ?></span>
  </div>

  <!-- Prawa część: link do systemu + user -->
  <div class="crm-topbar-user">
    <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
    <button type="button"
            data-bs-toggle="modal" data-bs-target="#bugReportModal"
            title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd"
            style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.4);
                   border-radius:6px;padding:.18rem .5rem;font-size:.78rem;
                   color:rgba(255,255,255,.9);cursor:pointer;line-height:1.5;
                   transition:all .12s;white-space:nowrap;flex-shrink:0;
                   display:inline-flex;align-items:center;gap:.3rem">
      <i class="bi bi-bug-fill" style="font-size:.85rem"></i>
      <span class="d-none d-sm-inline">Zgłoś błąd</span>
    </button>
    <?php endif; ?>
    <?php $_crm_has_ms = !empty($_cu['microsoft_id'] ?? ''); if ($_crm_has_ms): ?>
    <a href="<?= APP_URL ?>/crm/calendar_settings.php"
       class="crm-topbar-sys-link<?= _crm_nav_active('/crm/calendar_settings') ? ' active' : '' ?>"
       title="Synchronizuj swój kalendarz Outlook">
      <i class="bi bi-microsoft"></i><span class="d-none d-lg-inline">Outlook</span>
    </a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/crm/settings/"
       class="crm-topbar-sys-link<?= _crm_nav_active('/crm/settings') ? ' active' : '' ?>"
       title="Ustawienia CRM">
      <i class="bi bi-gear-fill"></i><span class="d-none d-lg-inline">Ustawienia</span>
    </a>
    <?php endif; ?>
    <?php $msw_active='crm'; $msw_dark=false; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

    <?php if ($_cu): ?>
    <div class="dropdown">
      <button type="button"
              class="crm-topbar-avatar"
              data-bs-toggle="dropdown"
              aria-haspopup="true"
              aria-expanded="false"
              aria-label="Menu użytkownika">
        <?= h($_cu_initials) ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:200px;font-size:.84rem">
        <li class="px-3 py-2 border-bottom">
          <div class="fw-semibold" style="font-size:.85rem"><?= h($_cu_name) ?></div>
          <div class="text-muted" style="font-size:.75rem"><?= h($_cu['email'] ?? '') ?></div>
        </li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-person-circle me-2"></i>Moje konto</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/index.php"><i class="bi bi-house me-2"></i>System główny</a></li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Wyloguj się</a></li>
      </ul>
    </div>
    <span class="crm-topbar-username d-none d-md-inline"><?= h(explode(' ', $_cu_name)[0]) ?></span>
    <a href="<?= APP_URL ?>/auth/logout.php" class="crm-topbar-sys-link crm-topbar-logout"
       title="Wyloguj się z systemu">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-none d-lg-inline">Wyloguj</span>
    </a>
    <?php endif; ?>
  </div>

</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>

<!-- ══ TOP NAVBAR (poziome menu) ════════════════════════════════════════════════ -->
<nav class="crm-navbar" role="navigation" aria-label="Nawigacja CRM">

  <?php
  // Aktywność kategorii (gdy którakolwiek pozycja podrzędna jest aktywna)
  $_act_kontakty = (str_contains($_uri,'/crm/index') || str_contains($_uri,'/crm/groups') || str_contains($_uri,'/crm/group/') || str_contains($_uri,'/crm/tags')) ? ' active' : '';
  $_act_komun    = (str_contains($_uri,'/crm/communicate') || str_contains($_uri,'/crm/mass_send') || str_contains($_uri,'/crm/templates') || str_contains($_uri,'/crm/form/') || str_contains($_uri,'/crm/webmail')) ? ' active' : '';
  ?>
  <div class="crm-navbar-scroll">

    <a href="<?= APP_URL ?>/crm/dashboard.php" class="crm-navlink<?= _crm_nav_active('/crm/dashboard') ?>"<?= _crm_nav_active('/crm/dashboard') ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
    </a>

    <!-- Kategoria: Kontakty -->
    <div class="dropdown">
      <a href="#" role="button" data-crm-dd data-bs-toggle="dropdown" aria-expanded="false"
         class="crm-navlink dropdown-toggle<?= $_act_kontakty ?>">
        <i class="bi bi-people-fill"></i><span>Kontakty</span>
        <?php if ($_crm_total): ?><span class="crm-nav-badge"><?= $_crm_total > 999 ? '999+' : $_crm_total ?></span><?php endif; ?>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-people-fill me-2"></i>Wszystkie kontakty</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/groups.php"><i class="bi bi-collection-fill me-2"></i>Grupy</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/tags.php"><i class="bi bi-tags-fill me-2"></i>Tagi</a></li>
        <?php if ($_crm_can_write): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/analyze.php"><i class="bi bi-funnel me-2"></i>Analiza kartotek z poczty</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/domains.php"><i class="bi bi-diagram-3 me-2"></i>Kartoteki wg domen</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/merge.php"><i class="bi bi-intersect me-2"></i>Duplikaty kartotek</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/triage.php"><i class="bi bi-robot me-2"></i>Segregacja AI</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/mobilna/"><i class="bi bi-telephone-outbound-fill me-2"></i>Szybkie dzwonienie <span class="text-body-secondary small">(mobile)</span></a></li>
      </ul>
    </div>

    <!-- Kategoria: Komunikacja -->
    <div class="dropdown">
      <a href="#" role="button" data-crm-dd data-bs-toggle="dropdown" aria-expanded="false"
         class="crm-navlink dropdown-toggle<?= $_act_komun ?>">
        <i class="bi bi-send-fill"></i><span>Komunikacja</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/communicate.php"><i class="bi bi-send-fill me-2"></i>Wyślij wiadomość</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/mass_send.php"><i class="bi bi-megaphone-fill me-2"></i>Wysyłka masowa</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/campaign/index.php"><i class="bi bi-graph-up-arrow me-2"></i>Kampanie mailowe</a></li>
        <?php if ($_crm_can_write): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/templates.php"><i class="bi bi-file-earmark-text-fill me-2"></i>Szablony wiadomości</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/form/manage.php"><i class="bi bi-window-split me-2"></i>Formularze</a></li>
        <?php endif; ?>
        <?php if (crm_setting('roundcube_url')): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/webmail.php"><i class="bi bi-envelope-at-fill me-2"></i>Webmail (Roundcube)</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <a href="<?= APP_URL ?>/crm/inbox.php" class="crm-navlink<?= str_contains($_uri,'/crm/inbox') ? ' active' : '' ?>"<?= str_contains($_uri,'/crm/inbox') ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-inbox-fill"></i><span>Skrzynka</span>
      <?php if (!empty($_crm_inbox_unread)): ?><span class="crm-nav-badge"><?= $_crm_inbox_unread > 99 ? '99+' : (int)$_crm_inbox_unread ?></span><?php endif; ?>
    </a>

    <a href="<?= APP_URL ?>/crm/calendar.php" class="crm-navlink<?= str_contains($_uri,'/crm/calendar.php') ? ' active' : '' ?>"<?= str_contains($_uri,'/crm/calendar.php') ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-calendar3-fill"></i><span>Kalendarz</span>
    </a>

    <a href="<?= APP_URL ?>/crm/activities.php" class="crm-navlink<?= str_contains($_uri,'/crm/activities.php') ? ' active' : '' ?>"<?= str_contains($_uri,'/crm/activities.php') ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-list-check"></i><span>Działania</span>
      <?php if (!empty($_crm_acts_due)): ?><span class="crm-nav-badge"><?= $_crm_acts_due > 99 ? '99+' : (int)$_crm_acts_due ?></span><?php endif; ?>
    </a>

    <!-- Kategoria: Oferty (działalność odpłatna) -->
    <div class="dropdown">
      <a href="#" role="button" data-crm-dd data-bs-toggle="dropdown" aria-expanded="false"
         class="crm-navlink dropdown-toggle<?= (str_contains($_uri,'/crm/offers') ? ' active' : '') ?>">
        <i class="bi bi-file-earmark-ruled-fill"></i><span>Oferty</span>
        <?php if (!empty($_crm_offers_pending)): ?><span class="crm-nav-badge"><?= (int)$_crm_offers_pending ?></span><?php endif; ?>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/offers/index.php"><i class="bi bi-file-earmark-ruled-fill me-2"></i>Rejestr ofert</a></li>
        <?php if ($_crm_can_write): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/offers/form.php"><i class="bi bi-plus-lg me-2"></i>Nowa oferta</a></li>
        <?php endif; ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/offers/catalog.php"><i class="bi bi-list-columns me-2"></i>Katalog usług odpłatnych</a></li>
        <?php if (!empty($_crm_offers_noconf)): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-warning-emphasis" href="<?= APP_URL ?>/crm/offers/index.php?noconf=1">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>Bez potwierdzenia (<?= (int)$_crm_offers_noconf ?>)</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if (module_enabled('donations_enabled')): ?>
    <a href="<?= APP_URL ?>/crm/donations/index.php"
       class="crm-navlink<?= _crm_nav_active('/crm/donations') ?>">
      <i class="bi bi-gift"></i><span>Darowizny</span>
    </a>
    <?php endif; ?>

    <?php if (module_enabled('invoices_enabled')): ?>
    <div class="dropdown">
      <a href="#" role="button" data-crm-dd data-bs-toggle="dropdown" aria-expanded="false"
         class="crm-navlink dropdown-toggle<?= _crm_nav_active('/crm/invoices') ?>">
        <i class="bi bi-receipt"></i><span>Faktury</span>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/invoices/index.php"><i class="bi bi-list-ul me-2"></i>Rejestr faktur</a></li>
        <?php if ($_crm_can_write): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/invoices/generate.php"><i class="bi bi-layer-forward me-2"></i>Generuj zbiorczo</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/invoices/form.php"><i class="bi bi-plus-lg me-2"></i>Nowa faktura</a></li>
        <?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php
      // Licznik spraw zalegających — pokazujemy tylko własne, żeby nie straszyć
      // liczbą z całej organizacji. Cicho, gdy kolumna jeszcze nie migrowana.
      $_stale_n = 0;
      try {
          // Próg z jednego miejsca (CRM_CASE_STALE_DAYS) — wpisany na sztywno
          // rozjechałby licznik z listą i banerem po każdej zmianie progu.
          $_stale_n = (int)(db_one(
              "SELECT COUNT(*) AS c FROM crm_cases
                WHERE status NOT IN ('closed','cancelled') AND created_by = ?
                  AND COALESCE(stale_ack_at, updated_at, created_at)
                      < datetime('now', '-' || ? || ' days')",
              [(int)($_cu['id'] ?? 0), CRM_CASE_STALE_DAYS]
          )['c'] ?? 0);
      } catch (\Throwable $e) {}
    ?>
    <div class="dropdown">
      <a href="#" role="button" data-crm-dd data-bs-toggle="dropdown" aria-expanded="false"
         class="crm-navlink dropdown-toggle<?= _crm_nav_active('/crm/cases') ?>">
        <i class="bi bi-briefcase-fill"></i><span>Sprawy</span>
        <?php if ($_stale_n): ?><span class="crm-nav-badge" title="Sprawy bez ruchu ponad 30 dni"><?= $_stale_n ?></span><?php endif; ?>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/cases/index.php"><i class="bi bi-briefcase-fill me-2"></i>Rejestr spraw</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/cases/stale.php">
          <i class="bi bi-clock-history me-2"></i>Do zamknięcia
          <?php if ($_stale_n): ?><span class="badge bg-warning text-dark ms-1"><?= $_stale_n ?></span><?php endif; ?>
        </a></li>
      </ul>
    </div>

  </div>

  <?php if ($_crm_can_write): ?>
  <!-- Szybkie akcje -->
  <div class="crm-navbar-actions">
    <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-crm-primary btn-sm" title="Dodaj nową osobę">
      <i class="bi bi-person-plus"></i> <span class="crm-act-label">Dodaj osobę</span>
    </a>
    <a href="<?= APP_URL ?>/crm/contact/add_org.php" class="btn btn-crm-outline btn-sm" title="Dodaj nową firmę / organizację">
      <i class="bi bi-building-add"></i> <span class="crm-act-label">Dodaj firmę</span>
    </a>
    <a href="<?= APP_URL ?>/crm/contact/import.php" class="btn btn-crm-ghost btn-sm" title="Import CSV">
      <i class="bi bi-upload"></i>
    </a>
  </div>
  <?php endif; ?>

</nav>
<script>
// Kategorie w pasku menu: Popper ze strategią 'fixed', aby rozwijane menu nie było
// obcinane przez przewijany w poziomie kontener nawigacji.
(function(){
  if (typeof bootstrap === 'undefined') return;
  document.querySelectorAll('[data-crm-dd]').forEach(function(el){
    bootstrap.Dropdown.getOrCreateInstance(el, {
      popperConfig: function(defaultCfg){ return Object.assign({}, defaultCfg, { strategy: 'fixed' }); }
    });
  });
})();
</script>

<!-- ══ SHELL WRAPPER ═══════════════════════════════════════════════════════════ -->
<div class="crm-shell">
<main class="crm-content" id="crmMain" role="main">

<?php
$_flash = flash_get();
if ($_flash):
?>
<div class="alert alert-<?= h($_flash['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3"
     role="alert" aria-live="polite">
  <i class="bi bi-<?= $_flash['type']==='success' ? 'check-circle-fill text-success' : ($_flash['type']==='danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"
     aria-hidden="true"></i>
  <span><?= h($_flash['msg']) ?></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>
