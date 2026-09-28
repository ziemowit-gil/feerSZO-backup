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

// Moje otwarte zadania CRM z terminem na dziś albo minionym — plakietka przy
// pozycji „Zadania CRM". Zadanie bez terminu nie jest zaległe, więc nie miga.
$_crm_tasks_mine = 0;
try {
    $_crm_tasks_mine = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_tasks
          WHERE status='open' AND owner_id=?
            AND due_date IS NOT NULL AND date(due_date) <= date('now','localtime')",
        [(int)(current_user()['id'] ?? 0)]
    )['c'] ?? 0);
} catch (\Throwable $e) {}

// Otwarte znaleziska bota sprzątającego — plakietka przy „Porządku”. Liczymy
// tylko poważne: „kontakty bez opiekuna” nie ma migać w nawigacji codziennie.
$_crm_janitor_open = 0;
try {
    $_crm_janitor_open = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_janitor_findings WHERE status='open' AND severity='high'"
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
  padding: 0 1rem 0 0;
  gap: 0;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 1040;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

/* Brand w topbarze */
.crm-topbar-brand {
  display: flex; align-items: center; gap: .6rem;
  padding: 0 1.1rem; flex-shrink: 0; height: 100%;
  text-decoration: none; color: var(--crm-primary-dark);
  font-weight: 800; font-size: 1rem; letter-spacing: .3px;
  border-right: 1px solid #E5E7EB;
}
.crm-topbar-brand-icon {
  width: 30px; height: 30px;
  background: linear-gradient(135deg, #194E31 0%, #2E844A 100%);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .9rem; flex-shrink: 0;
}
.crm-topbar-brand-txt { min-width: 0; }
.crm-topbar-brand-org {
  font-size: .64rem; color: #9CA3AF; font-weight: 400;
  line-height: 1; letter-spacing: 0;
  max-width: 170px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Wyszukiwarka kontaktów na środku paska — „/” ustawia w niej kursor */
.crm-topbar-search { flex: 1 1 auto; min-width: 0; display: flex; justify-content: center; padding: 0 1rem; }
.crm-topbar-search form { position: relative; width: 100%; max-width: 460px; }
.crm-topbar-search input {
  width: 100%; height: 34px; border: 1px solid #E5E7EB; border-radius: 9px; background: #F9FAFB;
  padding: 0 4.2rem 0 2.1rem; font-size: .84rem; color: #111827; outline: none;
  transition: border-color .12s, background .12s, box-shadow .12s;
}
.crm-topbar-search input::placeholder { color: #9CA3AF; }
.crm-topbar-search input:hover { background: #fff; border-color: #D1D5DB; }
.crm-topbar-search input:focus { background: #fff; border-color: var(--crm-primary); box-shadow: 0 0 0 3px rgba(46,132,74,.15); }
.crm-topbar-search .bi { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: #9CA3AF; font-size: .82rem; pointer-events: none; }
.crm-topbar-search kbd {
  position: absolute; right: .55rem; top: 50%; transform: translateY(-50%); pointer-events: none;
  font-size: .6rem; font-weight: 700; color: #9CA3AF; border: 1px solid #E5E7EB; border-radius: 5px;
  padding: .06rem .35rem; line-height: 1.2; font-family: inherit; background: #fff;
}

/* User area w topbarze */
.crm-topbar-user {
  display: flex; align-items: center; gap: .4rem;
  padding-left: .5rem; margin-left: auto;
  flex-shrink: 0;
}
.crm-topbar-icon {
  width: 34px; height: 34px; border-radius: 9px; border: 1px solid #E5E7EB;
  background: #fff; color: #6B7280; display: inline-flex; align-items: center; justify-content: center;
  font-size: .95rem; cursor: pointer; transition: background .12s, color .12s, border-color .12s;
}
.crm-topbar-icon:hover, .crm-topbar-icon[aria-expanded="true"] { background: #F3F4F6; color: #111827; border-color: #D1D5DB; }
.crm-topbar-icon:focus-visible { outline: 2px solid var(--crm-primary); outline-offset: 1px; }
.crm-topbar-avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: linear-gradient(135deg, #194E31 0%, #2E844A 100%);
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-size: .75rem; font-weight: 700; cursor: pointer; border: 2px solid #E5E7EB;
}
.crm-topbar .dropdown-menu {
  font-size: .85rem; border: 1px solid #E5E7EB; border-radius: 12px;
  box-shadow: 0 12px 36px rgba(2,6,23,.14); padding: .35rem .3rem; margin-top: 6px !important;
}
.crm-topbar .dropdown-item { padding: .42rem .75rem; border-radius: 8px; }
.crm-topbar .dropdown-item:hover { background: #F1F5F9; color: var(--crm-primary); }

/* ══ PASEK ZAKŁADEK (pod topbarem) — rejestr $_crm_nav, mega-menu dla grup ═ */
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
  gap: .5rem;
  padding: 0 .85rem;
  box-shadow: 0 1px 2px rgba(0,0,0,.04);
}
.crm-navbar-scroll {
  display: flex; align-items: center; gap: .15rem;
  flex: 0 1 auto; min-width: 0; height: 100%;
  overflow-x: auto; overflow-y: hidden;
  scrollbar-width: none; -ms-overflow-style: none;
}
.crm-navbar-scroll::-webkit-scrollbar { display: none; }
.crm-navlink {
  display: inline-flex; align-items: center; gap: .45rem; white-space: nowrap;
  padding: .36rem .7rem; border-radius: 8px;
  font-size: .84rem; font-weight: 500; color: #374151;
  text-decoration: none; flex-shrink: 0; transition: background .1s, color .1s;
  background: none; border: 0; cursor: pointer; line-height: 1.2;
}
.crm-navlink > i { font-size: .92rem; color: #9CA3AF; transition: color .1s; }
.crm-navlink:hover, .crm-navlink.show { background: #F3F4F6; color: #111827; }
.crm-navlink:hover > i, .crm-navlink.show > i { color: #4B5563; }
.crm-navlink.active { background: var(--crm-primary-bg); color: var(--crm-primary); font-weight: 600; }
.crm-navlink.active > i { color: var(--crm-primary); }
.crm-navlink:focus-visible { outline: 2px solid var(--crm-primary); outline-offset: -2px; }
.crm-navlink.dropdown-toggle::after { margin-left: .05rem; opacity: .45; vertical-align: .12em; }
.crm-navlink .crm-nav-badge {
  background: #E5E7EB; color: #6B7280; font-size: .65rem; font-weight: 700;
  padding: .1rem .4rem; border-radius: 10px; min-width: 18px; text-align: center; line-height: 1.3;
}
.crm-navlink .crm-nav-badge.hot { background: #FEE2E2; color: #B91C1C; }
.crm-navlink.active .crm-nav-badge { background: var(--crm-primary); color: #fff; }
@media (max-width: 1199.98px) { .crm-navlink > i { display: none; } }

/* Tytuł strony — prawy koniec paska zakładek */
.crm-navbar-title {
  margin-left: auto; min-width: 0; flex: 0 1 auto;
  display: flex; align-items: center; gap: .4rem;
  padding-left: .75rem; border-left: 1px solid #E5E7EB;
  font-size: .8rem; font-weight: 600; color: #4B5563;
}
.crm-navbar-title .sep { color: #D1D5DB; font-size: .6rem; }
.crm-navbar-title .crm-topbar-page { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.crm-navbar-actions {
  display: flex; align-items: center; gap: .35rem; flex-shrink: 0;
  padding-left: .6rem; margin-left: .25rem; border-left: 1px solid #E5E7EB;
}

/* Rozwijane menu zakładek */
.crm-navbar .dropdown-menu {
  font-size: .84rem; border: 1px solid #E5E7EB; border-radius: 12px; min-width: 240px;
  box-shadow: 0 12px 36px rgba(2,6,23,.14); padding: .4rem .35rem; margin-top: 6px !important;
}
.crm-navbar .dropdown-header {
  font-size: .61rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  color: #9CA3AF; padding: .45rem .7rem .15rem; display: flex; align-items: center; gap: .35rem;
}
.crm-navbar .dropdown-item {
  padding: .4rem .7rem; border-radius: 8px; color: #374151;
  display: flex; align-items: center; gap: .55rem; width: 100%;
}
.crm-navbar .dropdown-item > i { color: #9CA3AF; width: 16px; text-align: center; font-size: .85rem; flex-shrink: 0; }
.crm-navbar .dropdown-item:hover, .crm-navbar .dropdown-item:focus { background: var(--crm-primary-bg); color: var(--crm-primary); }
.crm-navbar .dropdown-item:hover > i { color: var(--crm-primary); }
.crm-navbar .dropdown-item.active { background: var(--crm-primary-bg); color: var(--crm-primary); font-weight: 600; }
.crm-navbar .dropdown-item .badge { margin-left: auto; font-size: .6rem; }
.crm-navbar .dropdown-item.text-warning-emphasis > i { color: inherit; }
.crm-navbar .dropdown-menu.crm-mega { width: max-content; max-width: min(900px, calc(100vw - 1.5rem)); padding: .5rem .55rem .55rem; }
.crm-mega-grid { display: grid; grid-template-columns: repeat(var(--cols, 2), minmax(200px, 1fr)); gap: .1rem .7rem; }
.crm-mega-col { min-width: 0; }
.crm-mega-col + .crm-mega-col { border-left: 1px solid #F3F4F6; padding-left: .7rem; }
@media (max-width: 767.98px) { .crm-mega-grid { grid-template-columns: 1fr; } .crm-mega-col + .crm-mega-col { border: 0; padding: 0; } }

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

/* ══ SZYBKIE AKCJE (makra) ═══════════════════════════════════════════ */
.crm-macros { display:flex; align-items:center; gap:.25rem }
/* Pasek jest BIAŁY — makra muszą być ciemne. Wcześniej biały tekst na białym tle
   sprawiał, że przypięte przyciski wyglądały, jakby ich nie było. */
.crm-macro {
  display:inline-flex; align-items:center; gap:.35rem; height:32px; padding:0 .6rem;
  border-radius:8px; border:1px solid #E5E7EB; background:#fff;
  color:#374151; font-size:.78rem; font-weight:500; text-decoration:none; white-space:nowrap;
  cursor:pointer; transition:background .12s, border-color .12s, color .12s;
}
.crm-macro:hover { background:#F3F4F6; border-color:#D1D5DB; color:#111827 }
.crm-macro:focus-visible { outline:2px solid var(--crm-primary); outline-offset:1px }
.crm-macro i { font-size:.9rem }
.crm-macro-txt { display:none }
@media (min-width: 1200px) { .crm-macro-txt { display:inline } }
.crm-macro--new {
  background:var(--crm-primary); color:#fff; border-color:var(--crm-primary); font-weight:600;
}
.crm-macro--new:hover { background:var(--crm-primary-dark, #0165B8); color:#fff; border-color:transparent }
.crm-macro--new i { color:#fff !important }
@media (max-width: 767px) { .crm-macros .crm-macro:not(.crm-macro--new) { display:none } }

/* ══ TRYB PEŁNOEKRANOWY ══════════════════════════════════════════════ */
body.crm-fullscreen .crm-content { max-width: 100%; }

/* ══ RESPONSIVE ══════════════════════════════════════════════════════ */
@media (max-width: 768px) {
  .crm-topbar-brand { border-right: none; padding: 0 .75rem; }
  .crm-topbar-brand-org { display: none; }
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
window.openCommModal = function(contactId, channel, opts) {
    contactId = parseInt(contactId, 10) || 0;
    channel = channel || 'email';
    // opts: {tpl: <id szablonu>, subject: 'Re: …'} — pozwala otworzyć kompozytor
    // od razu z wybraną odpowiedzią zamiast klikać szablon w oknie.
    opts = opts || {};
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

    var _url = '<?= APP_URL ?>/crm/compose_modal.php?contact_id=' + encodeURIComponent(contactId)
             + '&channel=' + encodeURIComponent(channel)
             + (opts.tpl ? '&tpl=' + encodeURIComponent(opts.tpl) : '')
             + (opts.subject ? '&subject=' + encodeURIComponent(opts.subject) : '');
    fetch(_url)
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

  <!-- Marka -->
  <a href="<?= APP_URL ?>/crm/dashboard.php" class="crm-topbar-brand" aria-label="CRM — pulpit">
    <div class="crm-topbar-brand-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
    <div class="crm-topbar-brand-txt">
      <div>CRM</div>
      <?php if ($_org_name): ?>
      <div class="crm-topbar-brand-org" title="<?= h($_org_name) ?>"><?= h($_org_name) ?></div>
      <?php endif; ?>
    </div>
  </a>

  <!-- Wyszukiwarka kontaktów — jedno pole na środku, „/” ustawia w nim kursor -->
  <div class="crm-topbar-search d-none d-md-flex">
    <form action="<?= APP_URL ?>/crm/index.php" method="get" role="search">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="search" name="q" data-search-input autocomplete="off"
             placeholder="Szukaj kontaktu, firmy, e-maila…" aria-label="Szukaj w kontaktach CRM">
      <kbd aria-hidden="true">/</kbd>
    </form>
  </div>

  <!-- Prawa strona: szybkie akcje → „⋯" → launcher modułów → konto -->
  <div class="crm-topbar-user">

    <?php /* Szybkie akcje: przypięte makra + menu „Nowe". Zestaw przypiętych
             wybiera sobie KAŻDY UŻYTKOWNIK (includes/crm_macros.php). */
      require_once dirname(dirname(__DIR__)) . '/includes/crm_macros.php';
      $_macro_cat    = crm_macros_catalog();
      $_macro_pinned = crm_macros_user();
    ?>
    <?php if ($_cu && $_macro_cat): ?>
    <div class="crm-macros">
      <?php foreach ($_macro_pinned as $mk): $m = $_macro_cat[$mk]; ?>
      <?php if ($m['kind'] === 'link'): ?>
      <a class="crm-macro" href="<?= APP_URL . h($m['href']) ?>" title="<?= h($m['title']) ?>">
        <i class="bi <?= h($m['icon']) ?>" style="color:<?= h($m['color']) ?>" aria-hidden="true"></i>
        <span class="crm-macro-txt"><?= h($m['label']) ?></span>
      </a>
      <?php else: ?>
      <button type="button" class="crm-macro" title="<?= h($m['title']) ?>"
              data-quick-open="<?= h($m['type']) ?>">
        <i class="bi <?= h($m['icon']) ?>" style="color:<?= h($m['color']) ?>" aria-hidden="true"></i>
        <span class="crm-macro-txt"><?= h($m['label']) ?></span>
      </button>
      <?php endif; ?>
      <?php endforeach; ?>

      <div class="dropdown">
        <button type="button" class="crm-macro crm-macro--new" data-bs-toggle="dropdown"
                aria-expanded="false" title="Szybko dodaj kontakt, notatkę, sprawę albo zadanie (Alt+N)">
          <i class="bi bi-plus-lg" aria-hidden="true"></i><span class="d-none d-md-inline">Nowe</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:260px;font-size:.85rem">
          <?php foreach ($_macro_cat as $m): ?>
          <li>
            <?php if ($m['kind'] === 'link'): ?>
            <a class="dropdown-item d-flex align-items-center gap-2" href="<?= APP_URL . h($m['href']) ?>"
               title="<?= h($m['title']) ?>">
              <i class="bi <?= h($m['icon']) ?>" style="color:<?= h($m['color']) ?>"></i><?= h($m['label']) ?>
            </a>
            <?php else: ?>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2"
                    title="<?= h($m['title']) ?>" data-quick-open="<?= h($m['type']) ?>">
              <i class="bi <?= h($m['icon']) ?>" style="color:<?= h($m['color']) ?>"></i><?= h($m['label']) ?>
            </button>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
          <li>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2"
                    title="Uruchom szablon akcji — kilka wpisów jednym kliknięciem"
                    data-quick-open="workflow">
              <i class="bi bi-diagram-3" style="color:#0F766E"></i>Uruchom przepływ
            </button>
          </li>
          <li><hr class="dropdown-divider my-1"></li>
          <li>
            <a class="dropdown-item d-flex align-items-center gap-2 text-muted"
               href="<?= APP_URL ?>/crm/workflows.php">
              <i class="bi bi-diagram-3"></i>Przepływy (szablony akcji)
            </a>
          </li>
          <li>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2 text-muted"
                    title="Wybierz, które akcje mają być przyciskami w pasku"
                    onclick="if (window.QuickActions) { QuickActions.open(); document.getElementById('qaw-cfg-toggle').click(); }">
              <i class="bi bi-sliders"></i>Dostosuj przyciski…
            </button>
          </li>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <!-- Menu „⋯" — narzędzia, po które sięga się rzadziej -->
    <div class="dropdown">
      <button type="button" class="crm-topbar-icon" data-bs-toggle="dropdown" aria-expanded="false"
              aria-label="Więcej narzędzi" title="Więcej: Outlook, ustawienia CRM, zgłoszenie błędu">
        <i class="bi bi-three-dots" aria-hidden="true"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:240px;font-size:.85rem">
        <li>
          <button type="button" class="dropdown-item d-flex align-items-center gap-2 d-md-none"
                  onclick="var i=document.querySelector('.crm-topbar-search input');if(i){i.closest('.crm-topbar-search').classList.remove('d-none');i.focus();}">
            <i class="bi bi-search text-secondary"></i>Szukaj kontaktu
          </button>
        </li>
        <?php $_crm_has_ms = !empty($_cu['microsoft_id'] ?? ''); if ($_crm_has_ms): ?>
        <li>
          <a class="dropdown-item d-flex align-items-center gap-2<?= _crm_nav_active('/crm/calendar_settings') ? ' active' : '' ?>"
             href="<?= APP_URL ?>/crm/calendar_settings.php" title="Synchronizuj swój kalendarz Outlook">
            <i class="bi bi-microsoft text-primary"></i>Kalendarz Outlook
          </a>
        </li>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <li>
          <a class="dropdown-item d-flex align-items-center gap-2<?= _crm_nav_active('/crm/settings') ? ' active' : '' ?>"
             href="<?= APP_URL ?>/crm/settings/" title="Ustawienia modułu CRM">
            <i class="bi bi-gear-fill text-secondary"></i>Ustawienia CRM
          </a>
        </li>
        <?php endif; ?>
        <li>
          <a class="dropdown-item d-flex align-items-center gap-2" href="<?= APP_URL ?>/index.php">
            <i class="bi bi-house text-secondary"></i>System główny
          </a>
        </li>
        <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
        <li><hr class="dropdown-divider my-1"></li>
        <li>
          <button type="button" class="dropdown-item d-flex align-items-center gap-2"
                  data-bs-toggle="modal" data-bs-target="#bugReportModal"
                  title="Zgłoś błąd na tej stronie">
            <i class="bi bi-bug-fill text-danger"></i>Zgłoś błąd
          </button>
        </li>
        <?php endif; ?>
      </ul>
    </div>

    <?php $msw_active='crm'; $msw_dark=false; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

    <?php if ($_cu): ?>
    <div class="dropdown">
      <button type="button" class="crm-topbar-avatar" data-bs-toggle="dropdown"
              aria-haspopup="true" aria-expanded="false"
              aria-label="Menu konta: <?= h($_cu_name) ?>" title="<?= h($_cu_name) ?>">
        <?= h($_cu_initials) ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:220px;font-size:.84rem">
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
    <?php endif; ?>
  </div>

</header>

<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>
<?php $ASAI_WIDGET_SCOPE = 'crm';
      require_once dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; ?>

<?php
/*
 * ══ REJESTR MENU CRM ═══════════════════════════════════════════════════════
 * Menu ułożone WEDŁUG OBSZARÓW pracy (nie kolejności powstawania modułów):
 *   Pulpit · Skrzynka · Praca · Relacje · Komunikacja · Finanse · Porządek
 * Skrzynka jest osobnym odnośnikiem mimo że pasuje do „Pracy" — to najczęściej
 * otwierany ekran w module i ma żywy licznik nieprzeczytanych.
 *
 * Zakładka = ['label','icon', 'path' (link) ALBO 'groups'=>[['label','items'=>[…]]],
 *   'active'=>bool, 'badge'=>int, 'badge_hot'=>bool, 'title'=>string]
 * Pozycja  = ['label','path','icon', 'badge'=>int, 'badge_cls'=>'bg-…', 'cls'=>'…']
 * Zakładka z >1 grupą rozwija się jako mega-menu w kolumnach.
 */
$_stale_n = 0;
try {
    $_stale_n = (int)(db_one(
        "SELECT COUNT(*) AS c FROM crm_cases
          WHERE status NOT IN ('closed','cancelled') AND created_by = ?
            AND COALESCE(stale_ack_at, updated_at, created_at)
                < datetime('now', '-' || ? || ' days')",
        [(int)($_cu['id'] ?? 0), CRM_CASE_STALE_DAYS]
    )['c'] ?? 0);
} catch (\Throwable $e) {}
$_crm_queue_due = (int)$_crm_acts_due + (int)($_crm_tasks_mine ?? 0);
$_g_praca_n     = (int)$_crm_acts_due + $_stale_n;   // działania po terminie + sprawy bez ruchu

$_hit = fn(string ...$frags) => (bool)array_filter($frags, fn($f) => str_contains($_uri, $f));
$_crm_nav = [];

$_crm_nav[] = ['label'=>'Pulpit', 'icon'=>'bi-grid-1x2-fill', 'path'=>'/crm/dashboard.php', 'active'=>$_hit('/crm/dashboard')];
$_crm_nav[] = ['label'=>'Skrzynka', 'icon'=>'bi-inbox-fill', 'path'=>'/crm/inbox.php', 'active'=>$_hit('/crm/inbox'),
               'badge'=>(int)$_crm_inbox_unread, 'badge_hot'=>true, 'title'=>'Nieprzeczytane wiadomości w skrzynce'];

$_crm_nav[] = ['label'=>'Praca', 'icon'=>'bi-check2-square',
    'active'=>$_hit('/crm/activities','/crm/cases','/crm/calendar'),
    'badge'=>$_g_praca_n, 'badge_hot'=>true, 'title'=>'Działania po terminie i sprawy bez ruchu',
    'groups'=>[
        ['label'=>'Moja kolejka', 'items'=>[
            ['label'=>'Moja kolejka', 'path'=>'/crm/activities.php', 'icon'=>'bi-list-check', 'badge'=>$_crm_queue_due, 'badge_cls'=>'bg-danger'],
            ['label'=>'Kalendarz', 'path'=>'/crm/calendar.php', 'icon'=>'bi-calendar3-fill'],
        ]],
        ['label'=>'Sprawy', 'items'=>[
            ['label'=>'Rejestr spraw', 'path'=>'/crm/cases/index.php', 'icon'=>'bi-briefcase-fill'],
            ['label'=>'Do zamknięcia', 'path'=>'/crm/cases/stale.php', 'icon'=>'bi-clock-history', 'badge'=>$_stale_n, 'badge_cls'=>'bg-warning text-dark'],
        ]],
    ]];

$_rel_add = [];
if ($_crm_can_write) $_rel_add = [
    ['label'=>'Nowy kontakt — szybko', 'path'=>'/crm/contact/quick_add.php', 'icon'=>'bi-lightning-fill'],
    ['label'=>'Nowa osoba fizyczna', 'path'=>'/crm/contact/add_person.php', 'icon'=>'bi-person-plus'],
    ['label'=>'Nowa firma / organizacja', 'path'=>'/crm/contact/add_org.php', 'icon'=>'bi-building-add'],
    ['label'=>'Import CSV', 'path'=>'/crm/import.php', 'icon'=>'bi-file-earmark-arrow-up'],
    ['label'=>'Import podmiotów (NIP/REGON)', 'path'=>'/crm/contact/import_podmioty.php', 'icon'=>'bi-building-add'],
    ['label'=>'Kartoteki bez pochodzenia', 'path'=>'/crm/contact/orphans.php', 'icon'=>'bi-question-diamond'],
];
$_crm_nav[] = ['label'=>'Relacje', 'icon'=>'bi-people-fill',
    'active'=>$_hit('/crm/index','/crm/groups','/crm/group/','/crm/tags','/crm/contact/'),
    'badge'=>(int)$_crm_total, 'title'=>'Aktywne kartoteki',
    'groups'=>array_values(array_filter([
        ['label'=>'Kartoteki', 'items'=>[
            ['label'=>'Wszystkie kontakty', 'path'=>'/crm/index.php', 'icon'=>'bi-people-fill'],
            ['label'=>'Grupy', 'path'=>'/crm/groups.php', 'icon'=>'bi-collection-fill'],
            ['label'=>'Tagi', 'path'=>'/crm/tags.php', 'icon'=>'bi-tags-fill'],
            ['label'=>'Szybkie dzwonienie (mobile)', 'path'=>'/mobilna/', 'icon'=>'bi-telephone-outbound-fill'],
        ]],
        $_rel_add ? ['label'=>'Dodawanie', 'items'=>$_rel_add] : null,
    ]))];

$_kom_prep = [];
if ($_crm_can_write) $_kom_prep = [
    ['label'=>'Szablony wiadomości', 'path'=>'/crm/templates.php', 'icon'=>'bi-file-earmark-text-fill'],
    ['label'=>'Przepływy (szablony akcji)', 'path'=>'/crm/workflows.php', 'icon'=>'bi-diagram-3'],
    ['label'=>'Szablony spraw', 'path'=>'/crm/cases/templates.php', 'icon'=>'bi-journal-text'],
    ['label'=>'Typy spraw i SLA', 'path'=>'/crm/cases/types.php', 'icon'=>'bi-tags'],
    ['label'=>'Formularze', 'path'=>'/crm/form/manage.php', 'icon'=>'bi-window-split'],
];
$_kom_send = [
    ['label'=>'Wyślij wiadomość', 'path'=>'/crm/communicate.php', 'icon'=>'bi-send-fill'],
    ['label'=>'Wysyłka masowa', 'path'=>'/crm/mass_send.php', 'icon'=>'bi-megaphone-fill'],
    ['label'=>'Kampanie mailowe', 'path'=>'/crm/campaign/index.php', 'icon'=>'bi-graph-up-arrow'],
];
if (crm_setting('roundcube_url')) $_kom_send[] = ['label'=>'Webmail (Roundcube)', 'path'=>'/crm/webmail.php', 'icon'=>'bi-envelope-at-fill'];
$_crm_nav[] = ['label'=>'Komunikacja', 'icon'=>'bi-send-fill',
    'active'=>$_hit('/crm/communicate','/crm/mass_send','/crm/templates','/crm/campaign','/crm/form/','/crm/webmail','/crm/workflows'),
    'groups'=>array_values(array_filter([
        ['label'=>'Wysyłka', 'items'=>$_kom_send],
        $_kom_prep ? ['label'=>'Przygotowanie', 'items'=>$_kom_prep] : null,
    ]))];

$_fin_off = [['label'=>'Rejestr ofert', 'path'=>'/crm/offers/index.php', 'icon'=>'bi-file-earmark-ruled-fill']];
if ($_crm_can_write) $_fin_off[] = ['label'=>'Nowa oferta', 'path'=>'/crm/offers/form.php', 'icon'=>'bi-plus-lg'];
$_fin_off[] = ['label'=>'Katalog usług odpłatnych', 'path'=>'/crm/offers/catalog.php', 'icon'=>'bi-list-columns'];
if (!empty($_crm_offers_noconf))
    $_fin_off[] = ['label'=>'Bez potwierdzenia', 'path'=>'/crm/offers/index.php?noconf=1', 'icon'=>'bi-exclamation-triangle-fill', 'cls'=>'text-warning-emphasis', 'badge'=>(int)$_crm_offers_noconf, 'badge_cls'=>'bg-warning text-dark'];
$_fin_groups = [['label'=>'Oferty — działalność odpłatna', 'items'=>$_fin_off]];
if (module_enabled('invoices_enabled')) {
    $_fin_inv = [['label'=>'Rejestr faktur', 'path'=>'/crm/invoices/index.php', 'icon'=>'bi-receipt']];
    if ($_crm_can_write) {
        $_fin_inv[] = ['label'=>'Generuj zbiorczo', 'path'=>'/crm/invoices/generate.php', 'icon'=>'bi-layer-forward'];
        $_fin_inv[] = ['label'=>'Nowa faktura', 'path'=>'/crm/invoices/form.php', 'icon'=>'bi-plus-lg'];
    }
    $_fin_groups[] = ['label'=>'Faktury', 'items'=>$_fin_inv];
}
if (module_enabled('donations_enabled'))
    $_fin_groups[] = ['label'=>'Darowizny', 'items'=>[['label'=>'Rejestr darowizn', 'path'=>'/crm/donations/index.php', 'icon'=>'bi-gift']]];
$_crm_nav[] = ['label'=>'Finanse', 'icon'=>'bi-cash-coin',
    'active'=>$_hit('/crm/offers','/crm/invoices','/crm/donations'),
    'badge'=>(int)$_crm_offers_pending, 'title'=>'Oferty w toku', 'groups'=>$_fin_groups];

if ($_crm_can_write)
    $_crm_nav[] = ['label'=>'Porządek', 'icon'=>'bi-tools',
        'active'=>$_hit('/crm/janitor','/crm/triage','/crm/settings','/crm/contact/merge','/crm/contact/domains','/crm/contact/analyze','/crm/export'),
        'badge'=>(int)$_crm_janitor_open, 'badge_hot'=>true, 'title'=>'Znaleziska bota czekające na decyzję',
        'groups'=>[
            ['label'=>'Higiena kartoteki', 'items'=>[
                ['label'=>'Bot sprzątający', 'path'=>'/crm/janitor.php', 'icon'=>'bi-stars', 'badge'=>(int)$_crm_janitor_open, 'badge_cls'=>'bg-warning text-dark'],
                ['label'=>'Duplikaty kartotek', 'path'=>'/crm/contact/merge.php', 'icon'=>'bi-intersect'],
                ['label'=>'Kartoteki wg domen', 'path'=>'/crm/contact/domains.php', 'icon'=>'bi-diagram-3'],
            ]],
            ['label'=>'Napływ z poczty', 'items'=>[
                ['label'=>'Analiza kartotek z poczty', 'path'=>'/crm/contact/analyze.php', 'icon'=>'bi-funnel'],
                ['label'=>'Segregacja AI', 'path'=>'/crm/triage.php', 'icon'=>'bi-robot'],
            ]],
            ['label'=>'Narzędzia', 'items'=>[
                ['label'=>'Ustawienia CRM', 'path'=>'/crm/settings/index.php', 'icon'=>'bi-sliders'],
                ['label'=>'Eksport danych', 'path'=>'/crm/export.php', 'icon'=>'bi-download'],
            ]],
        ]];

/** Jedna pozycja rozwijanego menu. */
$_crm_item = function (array $it) {
    $cls = 'dropdown-item' . (!empty($it['cls']) ? ' ' . $it['cls'] : '') . (str_contains($_SERVER['REQUEST_URI'] ?? '', preg_replace('/\?.*/', '', $it['path'])) ? ' active' : '');
    $html = '<a class="' . $cls . '" href="' . APP_URL . h($it['path']) . '"><i class="bi ' . h($it['icon']) . '"></i>' . h($it['label']);
    if (!empty($it['badge'])) $html .= '<span class="badge ' . h($it['badge_cls'] ?? 'bg-secondary') . '">' . (int)$it['badge'] . '</span>';
    return $html . '</a>';
};
?>
<!-- ══ PASEK ZAKŁADEK ═══════════════════════════════════════════════════════════ -->
<nav class="crm-navbar" role="navigation" aria-label="Nawigacja CRM">
  <div class="crm-navbar-scroll">
    <?php foreach ($_crm_nav as $_n):
      $_act = !empty($_n['active']);
      $_badge = '';
      if (!empty($_n['badge']))
          $_badge = '<span class="crm-nav-badge' . (!empty($_n['badge_hot']) ? ' hot' : '') . '"' . (!empty($_n['title']) ? ' title="' . h($_n['title']) . '"' : '') . '>' . ($_n['badge'] > 999 ? '999+' : (int)$_n['badge']) . '</span>';
      if (!empty($_n['path'])): ?>
    <a href="<?= APP_URL . h($_n['path']) ?>" class="crm-navlink<?= $_act ? ' active' : '' ?>"<?= $_act ? ' aria-current="page"' : '' ?>>
      <i class="bi <?= h($_n['icon']) ?>"></i><span><?= h($_n['label']) ?></span><?= $_badge ?>
    </a>
    <?php else: $_mega = count($_n['groups']) > 1; ?>
    <div class="dropdown">
      <button type="button" data-crm-dd data-bs-toggle="dropdown" aria-expanded="false"
              class="crm-navlink dropdown-toggle<?= $_act ? ' active' : '' ?>">
        <i class="bi <?= h($_n['icon']) ?>"></i><span><?= h($_n['label']) ?></span><?= $_badge ?>
      </button>
      <?php if ($_mega): ?>
      <div class="dropdown-menu crm-mega">
        <div class="crm-mega-grid" style="--cols:<?= min(3, count($_n['groups'])) ?>">
          <?php foreach ($_n['groups'] as $_g): ?>
          <div class="crm-mega-col">
            <?php if (!empty($_g['label'])): ?><h6 class="dropdown-header"><?= h($_g['label']) ?></h6><?php endif; ?>
            <?php foreach ($_g['items'] as $_it) echo $_crm_item($_it); ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <ul class="dropdown-menu">
        <?php foreach ($_n['groups'] as $_g): ?>
          <?php if (!empty($_g['label'])): ?><li><h6 class="dropdown-header"><?= h($_g['label']) ?></h6></li><?php endif; ?>
          <?php foreach ($_g['items'] as $_it): ?><li><?= $_crm_item($_it) ?></li><?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php endif; endforeach; ?>
  </div>

  <!-- Tytuł strony: mówi, gdzie jesteś -->
  <div class="crm-navbar-title d-none d-lg-flex">
    <i class="bi bi-chevron-right sep" aria-hidden="true"></i>
    <span class="crm-topbar-page" title="<?= h($_crm_title) ?>"><?= h($_crm_title) ?></span>
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

<?php require_once dirname(dirname(__DIR__)) . '/includes/quick_actions_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/search_hotkey.php'; ?>
