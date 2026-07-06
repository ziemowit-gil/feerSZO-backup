<?php
/**
 * tasks/includes/header_tasks.php
 * Standalone layout modułu Zadania — wzorowany na header_k30.php.
 *
 * Wymaga zdefiniowania $PAGE_TITLE przed include.
 * Opcjonalnie: $PAGE_SUBTITLE, $TASKS_WS_ID (int), $TASKS_BREADCRUMB (string)
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$_tu        = current_user();
$_tsk_title = $PAGE_TITLE ?? 'Zadania';
$_tsk_sub   = $PAGE_SUBTITLE ?? '';
$_tsk_bc    = $TASKS_BREADCRUMB ?? ($PAGE_TITLE ?? '');
$_uri       = $_SERVER['REQUEST_URI'] ?? '';
$_org_name  = defined('ORG_NAME') ? ORG_NAME : '';
$_is_admin  = is_admin();
$_ws_id     = $TASKS_WS_ID ?? 0;

// Workspaces dla sidebara
$_tsk_workspaces = task_user_workspaces((int)$_tu['id']);

// Moje zadania — licznik
$_my_count = 0;
try {
    $_my_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks t
         JOIN task_assignments ta ON ta.task_id = t.id
         WHERE ta.user_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL",
        [(int)$_tu['id']]
    )['n'] ?? 0);
} catch (\Throwable $e) {}

// Wolne zadania — licznik
$_open_count = 0;
try {
    $_open_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks t
         WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
         AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0"
    )['n'] ?? 0);
} catch (\Throwable $e) {}

// Inicjały użytkownika
$_tu_name = trim(($_tu['first_name'] ?? '') . ' ' . ($_tu['last_name'] ?? ''));
if (!$_tu_name) $_tu_name = $_tu['name'] ?? $_tu['email'] ?? 'Użytkownik';
$_tu_parts    = preg_split('/\s+/', trim($_tu_name));
$_tu_initials = '';
foreach ($_tu_parts as $_w) {
    $_tu_initials .= mb_strtoupper(mb_substr($_w, 0, 1, 'UTF-8'), 'UTF-8');
}
$_tu_initials = mb_substr($_tu_initials, 0, 2, 'UTF-8') ?: '?';

function _tsk_active(string $path): bool {
    global $_uri;
    return str_contains($_uri, $path);
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($_tsk_title) ?> — Zadania<?= $_org_name ? ' · ' . h($_org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<style>
/* ══════════════════════════════════════════════════════════════
   Moduł Zadania — standalone layout
   Paleta: emerald/teal — kontrast ≥ 4.5:1 WCAG AA
   ══════════════════════════════════════════════════════════════ */
:root {
  --tsk-green:      #059669;   /* emerald-600  kontrast 4.55:1 na białym ✓ */
  --tsk-green-dark: #064e3b;   /* emerald-900  topbar */
  --tsk-green-mid:  #10b981;   /* emerald-500 */
  --tsk-green-bg:   #ecfdf5;   /* emerald-50 */
  --tsk-green-light:#d1fae5;   /* emerald-100 */
  --tsk-focus:      #facc15;   /* żółty — widoczny na każdym tle */
  --tsk-text:       #0f172a;
  --tsk-text-sub:   #334155;
  --tsk-border:     #e2e8f0;
  --tsk-bg:         #f8fafc;
  --tsk-sidebar-w:  240px;
  --tsk-topbar-h:   52px;
}

*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
  margin: 0;
  background: var(--tsk-bg);
  color: var(--tsk-text);
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: .9375rem;
  line-height: 1.6;
}

/* ── Skip link ───────────────────────────────────────────────── */
.skip-link {
  position: absolute; top: -100%; left: 1rem;
  z-index: 9999; background: var(--tsk-green);
  color: #fff; padding: .65rem 1.4rem;
  border-radius: 0 0 8px 8px;
  font-size: .95rem; font-weight: 700;
  text-decoration: none;
  border: 3px solid var(--tsk-focus);
}
.skip-link:focus { top: 0; outline: 3px solid var(--tsk-focus); }

/* ── Focus ring globalny ─────────────────────────────────────── */
*:focus-visible {
  outline: 3px solid var(--tsk-focus) !important;
  outline-offset: 2px !important;
  border-radius: 3px;
}
*:focus:not(:focus-visible) { outline: none; }

/* ── Topbar ──────────────────────────────────────────────────── */
.tsk-topbar {
  height: var(--tsk-topbar-h);
  background: var(--tsk-green-dark);
  color: #fff;
  display: flex;
  align-items: center;
  padding: 0 1.25rem 0 0;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 1040;
  box-shadow: 0 2px 8px rgba(0,0,0,.28);
}

.tsk-brand {
  width: var(--tsk-sidebar-w);
  display: flex; align-items: center; gap: .6rem;
  padding: 0 1rem;
  flex-shrink: 0;
  text-decoration: none; color: #fff;
  font-weight: 800; font-size: .97rem;
  height: 100%;
  border-right: 1px solid rgba(255,255,255,.2);
  transition: background .15s;
}
.tsk-brand:hover { background: rgba(255,255,255,.08); color: #fff; }
.tsk-brand-icon {
  width: 30px; height: 30px;
  background: rgba(255,255,255,.18);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.tsk-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
.tsk-brand-sub  { font-size: .64rem; opacity: .7; font-weight: 400; }

/* Breadcrumb */
.tsk-topbar-bc {
  flex: 1; padding: 0 1.25rem;
  font-size: .84rem;
  color: rgba(255,255,255,.8);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tsk-topbar-bc strong { color: #fff; }

/* Przełącznik obszaru roboczego */
.tsk-ws-switch-btn {
  display: inline-flex; align-items: center; gap: .4rem;
  background: rgba(255,255,255,.13);
  border: 1.5px solid rgba(255,255,255,.35);
  border-radius: 6px; color: #fff;
  padding: .3rem .6rem;
  font-size: .82rem; font-weight: 500;
  cursor: pointer; white-space: nowrap; flex-shrink: 0;
  transition: background .12s;
}
.tsk-ws-switch-btn:hover { background: rgba(255,255,255,.24); }
.tsk-ws-switch-dot {
  width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0;
  border: 1.5px solid rgba(255,255,255,.6);
}
.tsk-ws-switch-btn i.bi-grid-3x3-gap { font-size: .85rem; }

.tsk-ws-switch-menu {
  width: 290px; max-height: 420px;
  flex-direction: column;
  padding: 0; overflow: hidden;
}
.tsk-ws-switch-menu.show { display: flex; }
.tsk-ws-switch-search-wrap { padding: .5rem; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
.tsk-ws-switch-list { overflow-y: auto; }
.tsk-ws-switch-item {
  display: flex; align-items: center; gap: .55rem;
  padding: .5rem .9rem;
  color: #334155; text-decoration: none;
  font-size: .84rem; white-space: nowrap;
}
.tsk-ws-switch-item:hover { background: #f8fafc; color: #0f172a; }
.tsk-ws-switch-item.active { background: var(--tsk-green-bg); color: var(--tsk-green); font-weight: 600; }
.tsk-ws-switch-item i { flex-shrink: 0; }
.tsk-ws-switch-sep { height: 1px; background: #f1f5f9; margin: .3rem 0; }
.tsk-ws-switch-empty { text-align: center; color: #94a3b8; font-size: .8rem; padding: 1rem; }

/* Akcje topbara */
.tsk-topbar-actions { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }

.tsk-sys-link {
  display: inline-flex; align-items: center; gap: .35rem;
  color: rgba(255,255,255,.8); text-decoration: none;
  font-size: .78rem; padding: .28rem .6rem;
  border: 1px solid rgba(255,255,255,.3);
  border-radius: 5px; white-space: nowrap;
  transition: background .12s;
}
.tsk-sys-link:hover { background: rgba(255,255,255,.15); color: #fff; }

.tsk-user-btn {
  display: flex; align-items: center; gap: .45rem;
  background: rgba(255,255,255,.13);
  border: 1.5px solid rgba(255,255,255,.35);
  border-radius: 6px; color: #fff;
  padding: .28rem .7rem;
  font-size: .82rem; font-weight: 500;
  cursor: default; text-decoration: none;
}
.tsk-user-av {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--tsk-green-mid);
  display: flex; align-items: center; justify-content: center;
  font-size: .67rem; font-weight: 700; flex-shrink: 0;
}

/* Mini centrum powiadomień */
.tsk-notif-btn {
  position: relative;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,.13);
  border: 1.5px solid rgba(255,255,255,.35);
  border-radius: 6px; color: #fff;
  width: 34px; height: 34px; flex-shrink: 0;
  font-size: .95rem; cursor: pointer;
  transition: background .12s;
}
.tsk-notif-btn:hover { background: rgba(255,255,255,.24); }
.tsk-notif-btn.tsk-notif-shake { animation: tskNotifShake .5s ease; }
@keyframes tskNotifShake {
  0%, 100% { transform: rotate(0); }
  20%      { transform: rotate(-12deg); }
  40%      { transform: rotate(10deg); }
  60%      { transform: rotate(-8deg); }
  80%      { transform: rotate(6deg); }
}
.tsk-notif-badge {
  position: absolute; top: -4px; right: -4px;
  background: #dc2626; color: #fff;
  border-radius: 999px; font-size: .6rem; font-weight: 700;
  min-width: 16px; height: 16px; padding: 0 3px;
  display: flex; align-items: center; justify-content: center;
  line-height: 1; border: 1.5px solid var(--tsk-green-dark);
}
.tsk-notif-item.fw-semibold { background: #f5f9ff; }

/* Mobile toggle */
.tsk-menu-toggle {
  display: none;
  background: none; border: none; color: #fff;
  font-size: 1.3rem; padding: .3rem .5rem;
  cursor: pointer; margin-right: .5rem;
}

/* ── Sidebar ─────────────────────────────────────────────────── */
.tsk-sidebar {
  position: fixed;
  top: var(--tsk-topbar-h); left: 0; bottom: 0;
  width: var(--tsk-sidebar-w);
  background: #fff;
  border-right: 1px solid var(--tsk-border);
  display: flex; flex-direction: column;
  overflow-y: auto; overflow-x: hidden;
  z-index: 1030;
  transition: left .2s;
}
.tsk-sidebar::-webkit-scrollbar { width: 5px; }
.tsk-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

.tsk-nav-label {
  font-size: .68rem; font-weight: 700;
  letter-spacing: .09em; text-transform: uppercase;
  color: #94a3b8; padding: 1rem 1rem .3rem;
  display: block;
}

.tsk-nav-link {
  display: flex; align-items: center; gap: .6rem;
  padding: .52rem 1rem;
  color: var(--tsk-text-sub);
  text-decoration: none;
  font-size: .88rem; font-weight: 500;
  border-left: 3px solid transparent;
  transition: background .1s, color .1s;
  position: relative;
}
.tsk-nav-link i { font-size: .95rem; flex-shrink: 0; width: 18px; text-align: center; }
.tsk-nav-link:hover { background: var(--tsk-green-bg); color: var(--tsk-green); }
.tsk-nav-link.active {
  background: var(--tsk-green-bg);
  color: var(--tsk-green);
  border-left-color: var(--tsk-green);
  font-weight: 700;
}

/* Badge licznika w navlinku */
.tsk-nav-badge {
  margin-left: auto;
  font-size: .64rem; font-weight: 700;
  background: var(--tsk-green-light); color: var(--tsk-green);
  border-radius: 2rem; padding: .05rem .45rem;
  flex-shrink: 0;
}
.tsk-nav-link.active .tsk-nav-badge { background: rgba(255,255,255,.7); }

/* Kropka koloru obszaru + licznik zadań — używane w przełączniku obszarów w topbarze */
.tsk-ws-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.tsk-ws-cnt { margin-left: auto; font-size: .68rem; color: #94a3b8; }

/* Podpowiedź: wybór obszaru przeniesiony do topbara (jednorazowa, do odrzucenia) */
.tsk-ws-hint {
  display: flex; align-items: flex-start; gap: .4rem;
  margin: .5rem .75rem; padding: .55rem .6rem;
  background: var(--tsk-green-bg); border: 1px solid var(--tsk-green-light);
  border-radius: 6px; color: var(--tsk-text-sub);
  font-size: .72rem; line-height: 1.4;
}
.tsk-ws-hint i.bi-arrow-up-circle { color: var(--tsk-green); font-size: .9rem; flex-shrink: 0; margin-top: .05rem; }
.tsk-ws-hint span { flex: 1; }
.tsk-ws-hint-close {
  background: none; border: none; color: inherit; opacity: .6;
  cursor: pointer; padding: 0; flex-shrink: 0; line-height: 1;
}
.tsk-ws-hint-close:hover { opacity: 1; }

/* Separator */
.tsk-nav-sep { height: 1px; background: var(--tsk-border); margin: .5rem .75rem; }

/* Sidebar footer */
.tsk-sidebar-footer {
  margin-top: auto;
  padding: .75rem 1rem;
  border-top: 1px solid var(--tsk-border);
  font-size: .77rem; color: #94a3b8;
}

/* ── Main content ────────────────────────────────────────────── */
.tsk-main {
  margin-top: var(--tsk-topbar-h);
  margin-left: var(--tsk-sidebar-w);
  min-height: calc(100vh - var(--tsk-topbar-h));
  padding: 1.5rem 1.75rem 2.5rem;
}

/* ── Flash messages ──────────────────────────────────────────── */
.tsk-flash { margin-bottom: 1rem; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 768px) {
  .tsk-menu-toggle { display: block; }
  .tsk-sidebar { left: calc(-1 * var(--tsk-sidebar-w)); }
  .tsk-sidebar.open { left: 0; box-shadow: 4px 0 20px rgba(0,0,0,.15); }
  .tsk-main { margin-left: 0; padding: 1rem; }
  .tsk-topbar-bc { display: none; }
}
</style>
</head>
<body>

<!-- Skip link -->
<a class="skip-link" href="#tsk-main-content">Przejdź do treści</a>

<!-- ── Topbar ──────────────────────────────────────────────────────────── -->
<header class="tsk-topbar" role="banner">

  <button class="tsk-menu-toggle"
          aria-label="Otwórz/zamknij menu"
          aria-expanded="false"
          aria-controls="tsk-sidebar"
          onclick="tskToggleSidebar(this)">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>

  <a class="tsk-brand" href="<?= APP_URL ?>/tasks/dashboard.php"
     aria-label="Zadania — strona główna modułu">
    <span class="tsk-brand-icon" aria-hidden="true">
      <i class="bi bi-table"></i>
    </span>
    <span class="tsk-brand-text">
      <span>Zadania</span>
      <span class="tsk-brand-sub"><?= h($_org_name) ?></span>
    </span>
  </a>

  <?php if ($_tsk_workspaces):
      $_tsk_cur_ws  = null;
      foreach ($_tsk_workspaces as $_wsRow) {
          if ((int)$_wsRow['id'] === (int)$_ws_id) { $_tsk_cur_ws = $_wsRow; break; }
      }
      $_tsk_view_qs = isset($_GET['view']) ? '&view=' . urlencode($_GET['view']) : '';
  ?>
  <div class="dropdown" id="tsk-ws-switch-wrap">
    <button type="button" class="tsk-ws-switch-btn" id="tsk-ws-switch-btn"
            data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
            aria-label="Przełącz obszar roboczy<?= $_tsk_cur_ws ? ' — bieżący: ' . h($_tsk_cur_ws['name']) : ' — obecnie wszystkie obszary' ?>">
      <?php if ($_tsk_cur_ws): ?>
      <span class="tsk-ws-switch-dot" style="background:<?= h($_tsk_cur_ws['color']) ?>" aria-hidden="true"></span>
      <span class="text-truncate" style="max-width:140px"><?= h($_tsk_cur_ws['name']) ?></span>
      <?php else: ?>
      <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
      <span class="d-none d-sm-inline">Wszystkie obszary</span>
      <?php endif; ?>
      <i class="bi bi-chevron-down" style="font-size:.62rem;opacity:.75" aria-hidden="true"></i>
    </button>
    <div class="dropdown-menu shadow tsk-ws-switch-menu" id="tsk-ws-switch-menu"
         role="menu" aria-label="Lista obszarów roboczych">
      <div class="tsk-ws-switch-search-wrap">
        <input type="search" id="tsk-ws-switch-search"
               class="form-control form-control-sm"
               placeholder="Szukaj obszaru…"
               aria-label="Szukaj obszaru roboczego"
               oninput="tskWsFilter(this.value)">
      </div>
      <div class="tsk-ws-switch-list" id="tsk-ws-switch-list">
        <a href="<?= APP_URL ?>/tasks/index.php<?= $_tsk_view_qs ? '?' . ltrim($_tsk_view_qs, '&') : '' ?>"
           class="tsk-ws-switch-item <?= !$_ws_id ? 'active' : '' ?>"
           data-name="wszystkie zadania"
           aria-current="<?= !$_ws_id ? 'page' : 'false' ?>">
          <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
          <span class="flex-grow-1">Wszystkie zadania</span>
        </a>
        <div class="tsk-ws-switch-sep" role="separator"></div>
        <?php foreach ($_tsk_workspaces as $ws): ?>
        <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws['id'] ?><?= $_tsk_view_qs ?>"
           class="tsk-ws-switch-item <?= (int)$_ws_id === (int)$ws['id'] ? 'active' : '' ?>"
           data-name="<?= h(mb_strtolower($ws['name'])) ?>"
           aria-current="<?= (int)$_ws_id === (int)$ws['id'] ? 'page' : 'false' ?>">
          <span class="tsk-ws-dot" style="background:<?= h($ws['color']) ?>" aria-hidden="true"></span>
          <i class="bi <?= h($ws['icon']) ?>" aria-hidden="true"></i>
          <span class="flex-grow-1 text-truncate"><?= h($ws['name']) ?></span>
          <span class="tsk-ws-cnt" aria-label="<?= (int)$ws['task_count'] ?> zadań"><?= (int)$ws['task_count'] ?></span>
        </a>
        <?php endforeach; ?>
        <div class="tsk-ws-switch-empty" id="tsk-ws-switch-empty" style="display:none">Brak wyników</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="tsk-topbar-bc" aria-label="Bieżąca lokalizacja">
    Zadania <?php if ($_tsk_bc): ?>/ <strong><?= h($_tsk_bc) ?></strong><?php endif; ?>
  </div>

  <div class="tsk-topbar-actions">
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
    <?php $msw_active='tasks'; $msw_dark=true; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

    <?php
    $_tsk_notif_unread = 0;
    $_tsk_notif_latest = [];
    try {
        require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';
        notif_migrate();
        $_tsk_notif_unread = (int)(db_one(
            "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND type='task' AND is_read=0",
            [(int)$_tu['id']]
        )['c'] ?? 0);
        $_tsk_notif_latest = db_all(
            "SELECT * FROM notifications WHERE user_id=? AND type='task' ORDER BY created_at DESC LIMIT 8",
            [(int)$_tu['id']]
        );
    } catch (\Throwable $e) {}
    ?>
    <div class="dropdown" id="tsk-notif-wrap">
      <button type="button" class="tsk-notif-btn" id="tsk-notif-btn"
              data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              aria-label="Powiadomienia zadań — <?= $_tsk_notif_unread ?> nieprzeczytanych">
        <i class="bi bi-bell-fill" aria-hidden="true"></i>
        <span class="tsk-notif-badge <?= $_tsk_notif_unread ? '' : 'd-none' ?>" id="tsk-notif-count">
          <?= $_tsk_notif_unread > 99 ? '99+' : $_tsk_notif_unread ?>
        </span>
      </button>
      <div class="dropdown-menu dropdown-menu-end shadow" id="tsk-notif-menu"
           style="width:320px;max-height:420px;overflow-y:auto" role="menu"
           aria-label="Lista powiadomień modułu Zadania">
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
          <span class="fw-semibold" style="font-size:.85rem">Powiadomienia — Zadania</span>
          <button type="button" class="btn btn-link btn-sm p-0 text-muted <?= $_tsk_notif_unread ? '' : 'd-none' ?>"
                  id="tsk-notif-mark-all" style="font-size:.75rem">Oznacz przeczytane</button>
        </div>
        <div id="tsk-notif-list">
          <?php if (!$_tsk_notif_latest): ?>
          <div class="text-center py-4 text-muted" style="font-size:.82rem" id="tsk-notif-empty">
            <i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:.3" aria-hidden="true"></i>
            Brak powiadomień
          </div>
          <?php else: foreach ($_tsk_notif_latest as $n): ?>
          <a href="<?= h($n['url'] ?: APP_URL . '/tasks/notifications.php') ?>"
             class="dropdown-item py-2 px-3 tsk-notif-item <?= $n['is_read'] ? '' : 'fw-semibold' ?>"
             style="white-space:normal;font-size:.82rem;border-bottom:1px solid #f1f5f9"
             data-notif-id="<?= (int)$n['id'] ?>">
            <div class="d-flex gap-2 align-items-start">
              <i class="bi bi-kanban-fill mt-1 flex-shrink-0" style="color:#8B5CF6;font-size:.9rem" aria-hidden="true"></i>
              <div class="flex-grow-1">
                <div><?= h($n['title']) ?></div>
                <?php if ($n['body']): ?>
                <div class="text-muted fw-normal" style="font-size:.74rem"><?= h($n['body']) ?></div>
                <?php endif; ?>
                <div class="text-muted fw-normal" style="font-size:.72rem"><?= h(substr($n['created_at'], 0, 16)) ?></div>
              </div>
              <?php if (!$n['is_read']): ?>
              <span class="rounded-circle bg-primary flex-shrink-0" style="width:7px;height:7px;margin-top:5px" aria-hidden="true"></span>
              <?php endif; ?>
            </div>
          </a>
          <?php endforeach; endif; ?>
        </div>
        <div class="px-3 py-2 border-top">
          <a href="<?= APP_URL ?>/tasks/notifications.php" class="btn btn-sm w-100"
             style="background:#f1f5f9;color:#374151;font-size:.8rem">
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia powiadomień
          </a>
        </div>
      </div>
    </div>

    <span class="tsk-user-btn" aria-label="Zalogowany: <?= h($_tu_name) ?>">
      <span class="tsk-user-av" aria-hidden="true"><?= h($_tu_initials) ?></span>
      <span class="d-none d-sm-inline"><?= h($_tu_name) ?></span>
    </span>
    <a class="tsk-sys-link" href="<?= APP_URL ?>/auth/logout.php"
       onclick="return confirm('Wylogować się?')"
       title="Wyloguj się" aria-label="Wyloguj się">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
      <span class="d-none d-sm-inline">Wyloguj</span>
    </a>
  </div>

</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>

<!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
<nav id="tsk-sidebar" class="tsk-sidebar" aria-label="Nawigacja modułu Zadania">

  <span class="tsk-nav-label">Przegląd</span>

  <a class="tsk-nav-link <?= _tsk_active('/tasks/dashboard') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/dashboard.php"
     aria-current="<?= _tsk_active('/tasks/dashboard') ? 'page' : 'false' ?>">
    <i class="bi bi-speedometer2" aria-hidden="true"></i>
    Dashboard
  </a>

  <a class="tsk-nav-link <?= _tsk_active('/tasks/inbox') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/inbox.php"
     aria-current="<?= _tsk_active('/tasks/inbox') ? 'page' : 'false' ?>">
    <i class="bi bi-inbox" aria-hidden="true"></i>
    Skrzynka
    <?php
    // Licznik nieprzeczytanych wiadomości
    try {
        $_inbox_unread = task_msg_unread((int)$_tu['id']);
    } catch (\Throwable $e) { $_inbox_unread = 0; }
    if ($_inbox_unread > 0): ?>
    <span class="tsk-nav-badge"
          style="background:#fee2e2;color:#dc2626"
          aria-label="<?= $_inbox_unread ?> nieprzeczytanych">
      <?= $_inbox_unread ?>
    </span>
    <?php endif; ?>
  </a>

  <a class="tsk-nav-link <?= _tsk_active('/tasks/index') || (str_contains($_uri,'/tasks/') && !str_contains($_uri,'dashboard') && !str_contains($_uri,'notification') && !str_contains($_uri,'admin') && !str_contains($_uri,'api') && !str_contains($_uri,'inbox') && !str_contains($_uri,'archive')) && !_tsk_active('/tasks/dashboard') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/index.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
     aria-current="<?= str_contains($_uri,'/tasks/index') ? 'page' : 'false' ?>">
    <i class="bi bi-table" aria-hidden="true"></i>
    Wszystkie zadania
    <?php if ($_open_count > 0): ?>
    <span class="tsk-nav-badge" aria-label="<?= $_open_count ?> wolnych"><?= $_open_count ?></span>
    <?php endif; ?>
  </a>

  <a class="tsk-nav-link <?= _tsk_active('/tasks/archive') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/archive.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
     aria-current="<?= _tsk_active('/tasks/archive') ? 'page' : 'false' ?>">
    <i class="bi bi-archive" aria-hidden="true"></i>
    Archiwum zadań
  </a>

  <?php
  // "Moje zadania" — link z filtrem mine
  $mine_url = APP_URL . '/tasks/index.php?status=mine' . ($_ws_id ? '&ws='.$_ws_id : '');
  ?>
  <a class="tsk-nav-link <?= str_contains($_uri,'status=mine') ? 'active' : '' ?>"
     href="<?= $mine_url ?>"
     aria-current="<?= str_contains($_uri,'status=mine') ? 'page' : 'false' ?>">
    <i class="bi bi-person-check" aria-hidden="true"></i>
    Moje zadania
    <?php if ($_my_count > 0): ?>
    <span class="tsk-nav-badge" aria-label="<?= $_my_count ?> zadań"><?= $_my_count ?></span>
    <?php endif; ?>
  </a>

  <?php if ($_tsk_workspaces): ?>
  <div class="tsk-ws-hint" id="tsk-ws-hint">
    <i class="bi bi-arrow-up-circle" aria-hidden="true"></i>
    <span>Wybór obszaru przenieśliśmy do paska na górze.</span>
    <button type="button" class="tsk-ws-hint-close" onclick="tskDismissWsHint()" aria-label="Zamknij podpowiedź o przełączniku obszarów">
      <i class="bi bi-x" aria-hidden="true"></i>
    </button>
  </div>
  <?php endif; ?>

  <!-- Lider / Admin -->
  <?php
  // Sprawdź czy user jest liderem jakiegokolwiek obszaru
  $_tsk_is_leader = false;
  $_tsk_open_problems = 0;
  if ($_is_admin) {
      $_tsk_is_leader = true;
  } else {
      $_led = db_one(
          "SELECT COUNT(*) AS n FROM task_workspace_members WHERE user_id=? AND role IN ('admin','editor')",
          [(int)$_tu['id']]
      );
      $_tsk_is_leader = (int)($_led['n'] ?? 0) > 0;
  }
  if ($_tsk_is_leader) {
      // Liczba otwartych problemów w obszarach lidera
      try {
          if ($_is_admin) {
              $_prob = db_one(
                  "SELECT COUNT(*) AS n FROM task_history th
                   JOIN tasks t ON t.id=th.task_id
                   WHERE th.event_type='leader_notified'
                     AND (th.metadata IS NULL OR json_extract(th.metadata,'$.resolved') IS NULL)"
              );
          } else {
              $_my_ws = db_all(
                  "SELECT workspace_id FROM task_workspace_members WHERE user_id=? AND role IN ('admin','editor')",
                  [(int)$_tu['id']]
              );
              $_my_ws_ids = array_column($_my_ws, 'workspace_id');
              if ($_my_ws_ids) {
                  $_ph = implode(',', array_fill(0, count($_my_ws_ids), '?'));
                  $_prob = db_one(
                      "SELECT COUNT(*) AS n FROM task_history th
                       JOIN tasks t ON t.id=th.task_id
                       WHERE th.event_type='leader_notified'
                         AND t.workspace_id IN ({$_ph})
                         AND (th.metadata IS NULL OR json_extract(th.metadata,'$.resolved') IS NULL)",
                      $_my_ws_ids
                  );
              }
          }
          $_tsk_open_problems = (int)($_prob['n'] ?? 0);
      } catch (\Throwable $e) {}
  }
  ?>
  <?php if ($_tsk_is_leader): ?>
  <div class="tsk-nav-sep" role="separator"></div>
  <span class="tsk-nav-label">Lider</span>

  <a class="tsk-nav-link <?= _tsk_active('/tasks/problems') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/problems.php"
     aria-current="<?= _tsk_active('/tasks/problems') ? 'page' : 'false' ?>">
    <i class="bi bi-megaphone" aria-hidden="true"></i>
    Zgłoszone problemy
    <?php if ($_tsk_open_problems > 0): ?>
    <span class="tsk-nav-badge"
          style="background:#fef9c3;color:#92400e"
          aria-label="<?= $_tsk_open_problems ?> otwartych problemów">
      <?= $_tsk_open_problems ?>
    </span>
    <?php endif; ?>
  </a>
  <?php endif; ?>

  <!-- Ustawienia -->
  <div class="tsk-nav-sep" role="separator"></div>
  <span class="tsk-nav-label">Ustawienia</span>

  <a class="tsk-nav-link <?= _tsk_active('/tasks/notifications') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/notifications.php"
     aria-current="<?= _tsk_active('/tasks/notifications') ? 'page' : 'false' ?>">
    <i class="bi bi-bell" aria-hidden="true"></i>
    Powiadomienia
    <?php
    // Licznik nieprzeczytanych (zadania due w ciągu 7 dni)
    try {
        $_due_soon = (int)(db_one(
            "SELECT COUNT(*) AS n FROM tasks t
             JOIN task_assignments ta ON ta.task_id=t.id
             WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL
               AND t.due_date IS NOT NULL AND t.due_date <= date('now','+3 days')",
            [(int)$_tu['id']]
        )['n'] ?? 0);
    } catch (\Throwable $e) { $_due_soon = 0; }
    if ($_due_soon > 0): ?>
    <span class="tsk-nav-badge"
          style="background:#fee2e2;color:#dc2626"
          aria-label="<?= $_due_soon ?> zadań z bliskim terminem">
      <?= $_due_soon ?>
    </span>
    <?php endif; ?>
  </a>

  <?php
  // Widoczne dla liderów obszarów i adminów
  $_tsk_is_any_leader = $_is_admin || (bool)db_one(
      "SELECT 1 FROM task_workspace_members WHERE user_id=? AND role IN ('admin','editor')",
      [(int)$_tu['id']]
  );
  ?>
  <?php if ($_tsk_is_any_leader): ?>
  <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/workspaces') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/settings/workspaces.php"
     aria-current="<?= _tsk_active('/tasks/settings/workspaces') ? 'page' : 'false' ?>">
    <i class="bi bi-sliders" aria-hidden="true"></i>
    Obszary i listy
  </a>
  <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/tags') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/settings/tags.php"
     aria-current="<?= _tsk_active('/tasks/settings/tags') ? 'page' : 'false' ?>">
    <i class="bi bi-tags" aria-hidden="true"></i>
    Tagi
  </a>
  <?php endif; ?>
  <?php if ($_is_admin): ?>
  <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/areas') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/settings/areas.php"
     aria-current="<?= _tsk_active('/tasks/settings/areas') ? 'page' : 'false' ?>">
    <i class="bi bi-layers" aria-hidden="true"></i>
    Obszary zadań
  </a>
  <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/roles') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/settings/roles.php"
     aria-current="<?= _tsk_active('/tasks/settings/roles') ? 'page' : 'false' ?>">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    Uprawnienia ról
  </a>
  <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/fields') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/tasks/settings/fields.php"
     aria-current="<?= _tsk_active('/tasks/settings/fields') ? 'page' : 'false' ?>">
    <i class="bi bi-ui-checks-grid" aria-hidden="true"></i>
    Uprawnienia pól
  </a>
  <?php endif; ?>
  <?php if ($_is_admin): ?>
  <div class="tsk-nav-sep" role="separator"></div>
  <a class="tsk-nav-link <?= _tsk_active('/admin/tasks_cleanup') ? 'active' : '' ?>"
     href="<?= APP_URL ?>/admin/tasks_cleanup.php"
     style="color:#dc2626"
     aria-label="Wyczyść moduł zadań — operacja nieodwracalna">
    <i class="bi bi-trash3" aria-hidden="true"></i>
    Wyczyść moduł
  </a>
  <?php endif; ?>

  <!-- Sidebar footer -->
  <div class="tsk-sidebar-footer" aria-hidden="true">
    Moduł Zadania
  </div>

</nav>

<!-- ── Treść główna ────────────────────────────────────────────────────── -->
<main id="tsk-main-content" class="tsk-main" tabindex="-1">

<?php
// Flash messages
$_fm = flash_get();
if ($_fm): ?>
<div class="tsk-flash" role="status" aria-live="polite">
  <div class="alert alert-<?= $_fm['type'] === 'error' ? 'danger' : h($_fm['type']) ?> alert-dismissible">
    <?= h($_fm['msg'] ?? $_fm['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
</div>
<?php endif; ?>

<!-- Sidebar JS (mobile toggle) -->
<script>
function tskToggleSidebar(btn) {
    const sb   = document.getElementById('tsk-sidebar');
    const open = sb.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', function(e) {
    const sb  = document.getElementById('tsk-sidebar');
    const btn = document.querySelector('.tsk-menu-toggle');
    if (sb && btn && !sb.contains(e.target) && !btn.contains(e.target)) {
        sb.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    }
});
</script>

<!-- Przełącznik obszaru roboczego — wyszukiwarka w liście -->
<script>
function tskWsFilter(query) {
    const q     = query.trim().toLowerCase();
    const list  = document.getElementById('tsk-ws-switch-list');
    if (!list) return;
    const items = list.querySelectorAll('.tsk-ws-switch-item');
    const sep   = list.querySelector('.tsk-ws-switch-sep');
    let visible = 0;
    items.forEach(function (item) {
        const match = !q || (item.dataset.name || '').includes(q);
        item.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    if (sep) sep.style.display = q ? 'none' : '';
    const empty = document.getElementById('tsk-ws-switch-empty');
    if (empty) empty.style.display = visible ? 'none' : '';
}

(function () {
    const wrap = document.getElementById('tsk-ws-switch-wrap');
    if (!wrap) return;
    wrap.addEventListener('shown.bs.dropdown', function () {
        const search = document.getElementById('tsk-ws-switch-search');
        if (search) { search.value = ''; tskWsFilter(''); search.focus(); }
    });
})();

/* Podpowiedź w sidebarze o przeniesieniu przełącznika obszarów do topbara */
(function () {
    var KEY = 'feer_tasks_ws_hint_dismissed_v1';
    var el  = document.getElementById('tsk-ws-hint');
    if (!el) return;
    try { if (window.localStorage.getItem(KEY)) el.remove(); } catch (e) {}
})();
window.tskDismissWsHint = function () {
    var el = document.getElementById('tsk-ws-hint');
    if (el) el.remove();
    try { window.localStorage.setItem('feer_tasks_ws_hint_dismissed_v1', '1'); } catch (e) {}
};
</script>

<!-- Mini centrum powiadomień — polling + dźwięk -->
<script>
(function () {
    const APP_URL   = '<?= APP_URL ?>';
    const POLL_MS   = 25000;
    let lastUnread  = <?= (int)$_tsk_notif_unread ?>;
    let audioCtx    = null;

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    // Krótki "ding" wygenerowany na żywo (Web Audio API) — bez plików binarnych.
    function tskPlayDing() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const now = audioCtx.currentTime;
            [[880, 0], [1318.5, 0.09]].forEach(([freq, delay]) => {
                const osc  = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, now + delay);
                gain.gain.exponentialRampToValueAtTime(0.18, now + delay + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.5);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(now + delay);
                osc.stop(now + delay + 0.55);
            });
        } catch (e) { /* autoplay zablokowany lub brak Web Audio — cicho pomiń */ }
    }

    function tskRenderNotifList(items) {
        const list = document.getElementById('tsk-notif-list');
        if (!list) return;
        if (!items.length) {
            list.innerHTML = '<div class="text-center py-4 text-muted" style="font-size:.82rem" id="tsk-notif-empty">'
                + '<i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:.3" aria-hidden="true"></i>Brak powiadomień</div>';
            return;
        }
        list.innerHTML = items.map(n => (
            '<a href="' + escHtml(n.url || (APP_URL + '/tasks/notifications.php')) + '"'
            + ' class="dropdown-item py-2 px-3 tsk-notif-item ' + (n.is_read ? '' : 'fw-semibold') + '"'
            + ' style="white-space:normal;font-size:.82rem;border-bottom:1px solid #f1f5f9" data-notif-id="' + n.id + '">'
            + '<div class="d-flex gap-2 align-items-start">'
            + '<i class="bi bi-kanban-fill mt-1 flex-shrink-0" style="color:#8B5CF6;font-size:.9rem" aria-hidden="true"></i>'
            + '<div class="flex-grow-1"><div>' + escHtml(n.title) + '</div>'
            + (n.body ? '<div class="text-muted fw-normal" style="font-size:.74rem">' + escHtml(n.body) + '</div>' : '')
            + '<div class="text-muted fw-normal" style="font-size:.72rem">' + escHtml((n.created_at || '').slice(0, 16)) + '</div></div>'
            + (n.is_read ? '' : '<span class="rounded-circle bg-primary flex-shrink-0" style="width:7px;height:7px;margin-top:5px" aria-hidden="true"></span>')
            + '</div></a>'
        )).join('');
    }

    function tskApplyUnread(unread) {
        const badge   = document.getElementById('tsk-notif-count');
        const markAll = document.getElementById('tsk-notif-mark-all');
        const btn     = document.getElementById('tsk-notif-btn');
        if (badge) {
            badge.textContent = unread > 99 ? '99+' : unread;
            badge.classList.toggle('d-none', unread === 0);
        }
        if (markAll) markAll.classList.toggle('d-none', unread === 0);
        if (btn) btn.setAttribute('aria-label', 'Powiadomienia zadań — ' + unread + ' nieprzeczytanych');
    }

    function tskPollNotifications() {
        fetch(APP_URL + '/tasks/api/notif_poll.php', {cache: 'no-store'})
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                if (d.unread > lastUnread) {
                    tskPlayDing();
                    const btn = document.getElementById('tsk-notif-btn');
                    if (btn) {
                        btn.classList.remove('tsk-notif-shake');
                        void btn.offsetWidth; // restart animacji
                        btn.classList.add('tsk-notif-shake');
                    }
                }
                lastUnread = d.unread;
                tskApplyUnread(d.unread);
                tskRenderNotifList(d.latest || []);
            })
            .catch(() => {});
    }

    document.addEventListener('click', function (e) {
        const link = e.target.closest('#tsk-notif-menu [data-notif-id]');
        if (link) {
            fetch(APP_URL + '/tasks/api/notif_poll.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_read', id: parseInt(link.dataset.notifId, 10)})
            }).catch(() => {});
            return;
        }
        const markAll = e.target.closest('#tsk-notif-mark-all');
        if (markAll) {
            fetch(APP_URL + '/tasks/api/notif_poll.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_all'})
            }).then(r => r.json()).then(d => {
                if (d.ok) { lastUnread = d.unread; tskApplyUnread(d.unread); tskRenderNotifList(d.latest || []); }
            }).catch(() => {});
        }
    });

    setInterval(tskPollNotifications, POLL_MS);
})();
</script>
