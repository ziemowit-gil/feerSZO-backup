<?php
/**
 * tasks/includes/header_tasks.php
 * Layout modułu Zadania — styl panelu wolontariusza (biały navbar + offcanvas).
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

$_tsk_workspaces = task_user_workspaces((int)$_tu['id']);

$_my_count = 0;
try {
    $_my_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks t
         JOIN task_assignments ta ON ta.task_id = t.id
         WHERE ta.user_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL",
        [(int)$_tu['id']]
    )['n'] ?? 0);
} catch (\Throwable $e) {}

$_open_count = 0;
try {
    $_open_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks t
         WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
         AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0"
    )['n'] ?? 0);
} catch (\Throwable $e) {}

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

/* ── Powiadomienia ─────────────────────────────────────────────────────── */
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

/* ── Lider / problemy ──────────────────────────────────────────────────── */
$_tsk_is_leader    = false;
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

$_tsk_is_any_leader = $_is_admin || (bool)db_one(
    "SELECT 1 FROM task_workspace_members WHERE user_id=? AND role IN ('admin','editor')",
    [(int)$_tu['id']]
);

/* ── Powiadomienia — zadania zbliżające się ────────────────────────────── */
$_due_soon = 0;
try {
    $_due_soon = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks t
         JOIN task_assignments ta ON ta.task_id=t.id
         WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL
           AND t.due_date IS NOT NULL AND t.due_date <= date('now','+3 days')",
        [(int)$_tu['id']]
    )['n'] ?? 0);
} catch (\Throwable $e) {}

/* ── Skrzynka — nieprzeczytane ─────────────────────────────────────────── */
$_inbox_unread = 0;
try { $_inbox_unread = task_msg_unread((int)$_tu['id']); } catch (\Throwable $e) {}

/* ── Konfigurator: czy użytkownik kiedykolwiek skonfigurował powiadomienia? ─ */
$_tsk_notif_setup_needed = false;
try {
    $_tsk_notif_setup_needed = !db_one(
        "SELECT 1 FROM task_notification_prefs WHERE user_id=?",
        [(int)$_tu['id']]
    );
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
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
   Moduł Zadania — layout w stylu panelu wolontariusza
   Paleta: emerald/teal  ·  WCAG AA
   ══════════════════════════════════════════════════════════════ */
:root {
  --tsk-green:       #059669;
  --tsk-green-dark:  #064e3b;
  --tsk-green-mid:   #10b981;
  --tsk-green-bg:    #ecfdf5;
  --tsk-green-light: #d1fae5;
  --tsk-focus:       #facc15;
  --tsk-text:        #0f172a;
  --tsk-text-sub:    #334155;
  --tsk-border:      #e2e8f0;
  --tsk-bg:          #F0F2F5;
}

*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
  margin: 0; background: var(--tsk-bg);
  color: var(--tsk-text);
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: .9375rem; line-height: 1.6;
  min-height: 100vh; display: flex; flex-direction: column;
}

/* Skip link */
.skip-link {
  position: absolute; top: -100%; left: 1rem; z-index: 9999;
  background: var(--tsk-green); color: #fff;
  padding: .65rem 1.4rem; border-radius: 0 0 8px 8px;
  font-size: .95rem; font-weight: 700; text-decoration: none;
  border: 3px solid var(--tsk-focus);
}
.skip-link:focus { top: 0; outline: 3px solid var(--tsk-focus); }

/* Focus ring */
*:focus-visible {
  outline: 3px solid var(--tsk-focus) !important;
  outline-offset: 2px !important; border-radius: 3px;
}
*:focus:not(:focus-visible) { outline: none; }

/* ── Biały navbar (wzorzec .pv-navbar) ──────────────────────── */
.tsk-navbar {
  background: #fff;
  border-bottom: 1px solid #E5E7EB;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  position: sticky; top: 0; z-index: 1040;
}
.tsk-menu-btn {
  border: 1px solid #E5E7EB; background: #fff;
  border-radius: 9px; width: 40px; height: 40px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.25rem; color: #374151; cursor: pointer; flex-shrink: 0;
  transition: border-color .12s, color .12s;
}
.tsk-menu-btn:hover { border-color: var(--tsk-green); color: var(--tsk-green); }

.tsk-brand {
  display: flex; align-items: center; gap: .6rem;
  text-decoration: none; color: #111827; font-weight: 800;
  font-size: .98rem; min-width: 0;
}
.tsk-brand:hover { color: #111827; }
.tsk-brand-icon {
  width: 34px; height: 34px; background: var(--tsk-green);
  color: #fff; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.tsk-brand-sub {
  font-size: .66rem; opacity: .6; font-weight: 500; line-height: 1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;
}

.tsk-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--tsk-green); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; font-weight: 700; cursor: pointer; border: none; line-height: 1;
}

/* ── Offcanvas nawigacja ─────────────────────────────────────── */
.tsk-offcanvas { max-width: 285px; }
.tsk-offcanvas .offcanvas-header {
  background: var(--tsk-green-dark); color: #fff;
}
.tsk-offcanvas .offcanvas-body {
  display: flex; flex-direction: column; padding: .35rem 0;
}
.tsk-nav-label {
  font-size: .65rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: #9CA3AF;
  padding: .85rem 1rem .3rem; user-select: none; display: block;
}
.tsk-nav-link {
  display: flex; align-items: center; gap: .6rem;
  padding: .5rem .75rem; border-radius: 8px;
  font-size: .88rem; font-weight: 500; color: #374151;
  text-decoration: none;
  transition: background .1s, color .1s, border-color .1s;
  margin: .05rem .5rem; border-left: 3px solid transparent;
}
.tsk-nav-link i {
  font-size: 1rem; width: 20px; text-align: center;
  flex-shrink: 0; color: #9CA3AF; transition: color .1s;
}
.tsk-nav-link:hover { background: var(--tsk-green-bg); color: var(--tsk-green); border-left-color: var(--tsk-green); }
.tsk-nav-link:hover i { color: var(--tsk-green); }
.tsk-nav-link.active {
  background: var(--tsk-green-bg); color: var(--tsk-green);
  font-weight: 700; border-left-color: var(--tsk-green);
}
.tsk-nav-link.active i { color: var(--tsk-green); }
.tsk-nav-link.tsk-danger { color: #dc2626; }
.tsk-nav-link.tsk-danger i { color: #dc2626; }
.tsk-nav-link.tsk-danger:hover { background: #fef2f2; color: #b91c1c; border-left-color: #dc2626; }
.tsk-nav-badge {
  margin-left: auto;
  background: var(--tsk-green); color: #fff;
  font-size: .65rem; font-weight: 700;
  padding: .1rem .4rem; border-radius: 10px; min-width: 18px; text-align: center;
}
.tsk-nav-sep { height: 1px; background: #F3F4F6; margin: .4rem .75rem; }
.tsk-sidebar-bottom { margin-top: auto; border-top: 1px solid #F3F4F6; padding: .5rem; }

/* ── Przełącznik obszaru ─────────────────────────────────────── */
.tsk-ws-switch-btn {
  display: inline-flex; align-items: center; gap: .4rem;
  background: #F4F6F9; border: 1px solid #E5E7EB;
  border-radius: 8px; color: #374151;
  padding: .3rem .7rem; font-size: .83rem; font-weight: 500;
  cursor: pointer; white-space: nowrap; flex-shrink: 0;
  transition: background .12s, border-color .12s;
}
.tsk-ws-switch-btn:hover { background: var(--tsk-green-bg); border-color: var(--tsk-green); color: var(--tsk-green); }
.tsk-ws-switch-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.tsk-ws-switch-menu {
  width: 290px; max-height: 420px;
  flex-direction: column; padding: 0; overflow: hidden;
}
.tsk-ws-switch-menu.show { display: flex; }
.tsk-ws-switch-search-wrap { padding: .5rem; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
.tsk-ws-switch-list { overflow-y: auto; }
.tsk-ws-switch-item {
  display: flex; align-items: center; gap: .55rem;
  padding: .5rem .9rem; color: #334155; text-decoration: none;
  font-size: .84rem; white-space: nowrap;
}
.tsk-ws-switch-item:hover { background: #f8fafc; color: #0f172a; }
.tsk-ws-switch-item.active { background: var(--tsk-green-bg); color: var(--tsk-green); font-weight: 600; }
.tsk-ws-switch-item i { flex-shrink: 0; }
.tsk-ws-switch-sep { height: 1px; background: #f1f5f9; margin: .3rem 0; }
.tsk-ws-switch-empty { text-align: center; color: #94a3b8; font-size: .8rem; padding: 1rem; }
.tsk-ws-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.tsk-ws-cnt { margin-left: auto; font-size: .68rem; color: #94a3b8; }

/* ── Powiadomienia ───────────────────────────────────────────── */
.tsk-notif-btn {
  position: relative;
  display: inline-flex; align-items: center; justify-content: center;
  background: #F4F6F9; border: 1px solid #E5E7EB;
  border-radius: 8px; color: #374151;
  width: 38px; height: 38px; flex-shrink: 0;
  font-size: .95rem; cursor: pointer;
  transition: background .12s, border-color .12s;
}
.tsk-notif-btn:hover { background: var(--tsk-green-bg); border-color: var(--tsk-green); color: var(--tsk-green); }
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
  line-height: 1; border: 2px solid #fff;
}
.tsk-notif-item.fw-semibold { background: #f5f9ff; }

/* ── Treść główna ────────────────────────────────────────────── */
#tsk-main { flex: 1 0 auto; }
.tsk-flash { margin-bottom: 1rem; }

/* ── Stopka ──────────────────────────────────────────────────── */
.tsk-footer {
  border-top: 1px solid #E5E7EB; padding: .6rem 1.5rem;
  font-size: .75rem; color: #9CA3AF; background: #fff;
  display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem;
}

/* ── Baner konfiguracji powiadomień e-mail (nowi użytkownicy) ─── */
#tsk-setup-banner {
  background: #fffbeb; border: 1px solid #fcd34d;
  border-radius: 10px; padding: .75rem 1rem;
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
  margin-bottom: 1rem; animation: tskBannerIn .25s ease;
}
#tsk-setup-banner .tsk-sb-icon {
  width:36px; height:36px; border-radius:9px;
  background:#f59e0b; color:#fff;
  display:flex; align-items:center; justify-content:center;
  font-size:1rem; flex-shrink:0;
}
#tsk-setup-banner .tsk-sb-body { flex:1; min-width:180px; }
#tsk-setup-banner .tsk-sb-title { font-weight:700; font-size:.88rem; color:#78350f; }
#tsk-setup-banner .tsk-sb-sub   { font-size:.77rem; color:#92400e; margin-top:.1rem; }

/* ── Baner powiadomień przeglądarkowych ──────────────────────── */
#tsk-notif-banner {
  background: #ecfdf5; border: 1px solid #6ee7b7;
  border-radius: 10px; padding: .75rem 1rem;
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
  margin-bottom: 1rem; animation: tskBannerIn .25s ease;
}
@keyframes tskBannerIn {
  from { opacity:0; transform:translateY(-6px); }
  to   { opacity:1; transform:translateY(0); }
}
#tsk-notif-banner .tsk-nb-icon {
  width:36px; height:36px; border-radius:9px;
  background:var(--tsk-green); color:#fff;
  display:flex; align-items:center; justify-content:center;
  font-size:1rem; flex-shrink:0;
}
#tsk-notif-banner .tsk-nb-body { flex:1; min-width:180px; }
#tsk-notif-banner .tsk-nb-title { font-weight:700; font-size:.88rem; color:#065f46; }
#tsk-notif-banner .tsk-nb-sub { font-size:.77rem; color:#047857; margin-top:.1rem; }
</style>
</head>
<body>

<a class="skip-link" href="#tsk-main">Przejdź do treści</a>

<!-- ══ Navbar ══════════════════════════════════════════════════════════════ -->
<header class="tsk-navbar" role="banner">
  <div class="container-xl d-flex align-items-center gap-2 py-2">

    <button class="tsk-menu-btn" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#tskNav"
            aria-controls="tskNav" aria-label="Otwórz menu nawigacji">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <a href="<?= APP_URL ?>/tasks/dashboard.php" class="tsk-brand"
       aria-label="Zadania — strona główna modułu">
      <span class="tsk-brand-icon" aria-hidden="true"><i class="bi bi-table"></i></span>
      <span class="d-flex flex-column">
        <span>Zadania</span>
        <?php if ($_org_name): ?>
        <span class="tsk-brand-sub" title="<?= h($_org_name) ?>"><?= h($_org_name) ?></span>
        <?php endif; ?>
      </span>
    </a>

    <?php if ($_tsk_workspaces):
        $_tsk_cur_ws  = null;
        foreach ($_tsk_workspaces as $_wsRow) {
            if ((int)$_wsRow['id'] === (int)$_ws_id) { $_tsk_cur_ws = $_wsRow; break; }
        }
        // tasks/files.php ustawia $TASKS_FILES_VIEW = true — WS switcher zmienia URL na files.php
        $_tsk_on_files = $TASKS_FILES_VIEW ?? false;
        $_tsk_view_qs  = $_tsk_on_files ? '' : (isset($_GET['view']) ? '&view=' . urlencode($_GET['view']) : '');
        $_tsk_ws_base  = $_tsk_on_files
            ? (APP_URL . '/tasks/files.php')
            : (APP_URL . '/tasks/index.php');
    ?>
    <div class="dropdown d-none d-sm-block" id="tsk-ws-switch-wrap">
      <button type="button" class="tsk-ws-switch-btn" id="tsk-ws-switch-btn"
              data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
              aria-label="Przełącz obszar roboczy<?= $_tsk_cur_ws ? ' — bieżący: ' . h($_tsk_cur_ws['name']) : '' ?>">
        <?php if ($_tsk_cur_ws): ?>
        <span class="tsk-ws-switch-dot" style="background:<?= h($_tsk_cur_ws['color']) ?>" aria-hidden="true"></span>
        <span class="text-truncate" style="max-width:130px"><?= h($_tsk_cur_ws['name']) ?></span>
        <?php else: ?>
        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
        <span>Wszystkie obszary</span>
        <?php endif; ?>
        <i class="bi bi-chevron-down" style="font-size:.6rem;opacity:.6" aria-hidden="true"></i>
      </button>
      <div class="dropdown-menu shadow tsk-ws-switch-menu" role="menu" aria-label="Lista obszarów roboczych">
        <div class="tsk-ws-switch-search-wrap">
          <input type="search" id="tsk-ws-switch-search"
                 class="form-control form-control-sm"
                 placeholder="Szukaj obszaru…"
                 aria-label="Szukaj obszaru roboczego"
                 oninput="tskWsFilter(this.value)">
        </div>
        <div class="tsk-ws-switch-list" id="tsk-ws-switch-list">
          <?php if (!$_tsk_on_files): ?>
          <a href="<?= APP_URL ?>/tasks/index.php<?= $_tsk_view_qs ? '?' . ltrim($_tsk_view_qs, '&') : '' ?>"
             class="tsk-ws-switch-item <?= !$_ws_id ? 'active' : '' ?>"
             data-name="wszystkie zadania"
             aria-current="<?= !$_ws_id ? 'page' : 'false' ?>">
            <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
            <span class="flex-grow-1">Wszystkie zadania</span>
          </a>
          <div class="tsk-ws-switch-sep" role="separator"></div>
          <?php endif; ?>
          <?php foreach ($_tsk_workspaces as $ws): ?>
          <a href="<?= $_tsk_ws_base ?>?ws=<?= $ws['id'] ?><?= $_tsk_view_qs ?>"
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

    <nav class="ms-auto d-flex align-items-center gap-2" aria-label="Akcje użytkownika">

      <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
      <button type="button"
              class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
              data-bs-toggle="modal" data-bs-target="#bugReportModal"
              title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd">
        <i class="bi bi-bug-fill" aria-hidden="true"></i>
        <span class="d-none d-md-inline">Zgłoś błąd</span>
      </button>
      <?php endif; ?>

      <?php $msw_active='tasks'; $msw_dark=false; require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

      <!-- Powiadomienia -->
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
                <span class="rounded-circle flex-shrink-0" style="width:7px;height:7px;margin-top:5px;background:var(--tsk-green)" aria-hidden="true"></span>
                <?php endif; ?>
              </div>
            </a>
            <?php endforeach; endif; ?>
          </div>
          <div class="px-3 py-2 border-top d-flex gap-2">
            <a href="<?= APP_URL ?>/tasks/notifications.php" class="btn btn-sm flex-fill"
               style="background:#f1f5f9;color:#374151;font-size:.8rem">
              <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia
            </a>
            <button type="button" class="btn btn-sm flex-fill"
                    style="background:#fffbeb;color:#92400e;font-size:.8rem;border:1px solid #fcd34d"
                    onclick="tskOpenNotifSettings()">
              <i class="bi bi-gear-fill me-1" aria-hidden="true"></i>Ustawienia
            </button>
          </div>
        </div>
      </div>

      <!-- User dropdown -->
      <div class="dropdown">
        <button type="button" class="tsk-avatar"
                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                aria-label="Menu użytkownika <?= h($_tu_name) ?>">
          <?= h($_tu_initials) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.88rem;min-width:200px">
          <li class="px-3 py-2 border-bottom">
            <div class="fw-bold"><?= h($_tu_name) ?></div>
            <div class="text-muted small"><?= h($_tu['email'] ?? '') ?></div>
          </li>
          <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-house-door me-2" aria-hidden="true"></i>Mój panel</a></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php"
               onclick="return confirm('Wylogować się?')">
              <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się
            </a>
          </li>
        </ul>
      </div>

    </nav>
  </div>
</header>

<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>
<?php $ASAI_WIDGET_SCOPE = 'zadania';
      require_once dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/quick_actions_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/search_hotkey.php'; ?>

<!-- ══ Offcanvas — nawigacja ════════════════════════════════════════════════ -->
<div class="offcanvas offcanvas-start tsk-offcanvas" tabindex="-1" id="tskNav"
     aria-label="Nawigacja modułu Zadania">
  <div class="offcanvas-header">
    <span class="offcanvas-title fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-table" aria-hidden="true"></i>Zadania
    </span>
    <button type="button" class="btn-close btn-close-white"
            data-bs-dismiss="offcanvas" aria-label="Zamknij menu"></button>
  </div>
  <nav class="offcanvas-body" aria-label="Sekcje modułu Zadania">

    <span class="tsk-nav-label">Przegląd</span>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/dashboard') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/dashboard.php"
       aria-current="<?= _tsk_active('/tasks/dashboard') ? 'page' : 'false' ?>">
      <i class="bi bi-speedometer2" aria-hidden="true"></i>Dashboard
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/inbox') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/inbox.php"
       aria-current="<?= _tsk_active('/tasks/inbox') ? 'page' : 'false' ?>">
      <i class="bi bi-inbox" aria-hidden="true"></i>Skrzynka
      <?php if ($_inbox_unread > 0): ?>
      <span class="tsk-nav-badge" style="background:#fee2e2;color:#dc2626"
            aria-label="<?= $_inbox_unread ?> nieprzeczytanych"><?= $_inbox_unread ?></span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= (str_contains($_uri,'/tasks/index') || (str_contains($_uri,'/tasks/') && !_tsk_active('/tasks/dashboard') && !_tsk_active('/tasks/inbox') && !_tsk_active('/tasks/archive') && !_tsk_active('/tasks/notification') && !_tsk_active('/tasks/settings') && !_tsk_active('/tasks/problems'))) ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/index.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= str_contains($_uri,'/tasks/index') ? 'page' : 'false' ?>">
      <i class="bi bi-table" aria-hidden="true"></i>Wszystkie zadania
      <?php if ($_open_count > 0): ?>
      <span class="tsk-nav-badge" aria-label="<?= $_open_count ?> wolnych"><?= $_open_count ?></span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/files') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/files.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= _tsk_active('/tasks/files') ? 'page' : 'false' ?>">
      <i class="bi bi-folder2-open" aria-hidden="true"></i>Pliki
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/archive') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/archive.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= _tsk_active('/tasks/archive') ? 'page' : 'false' ?>">
      <i class="bi bi-archive" aria-hidden="true"></i>Archiwum zadań
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/moje') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/moje.php"
       aria-current="<?= _tsk_active('/tasks/moje') ? 'page' : 'false' ?>">
      <i class="bi bi-person-check" aria-hidden="true"></i>Moje zadania
      <?php if ($_my_count > 0): ?>
      <span class="tsk-nav-badge" aria-label="<?= $_my_count ?> zadań"><?= $_my_count ?></span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/charts') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/charts.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= _tsk_active('/tasks/charts') ? 'page' : 'false' ?>">
      <i class="bi bi-bar-chart-line" aria-hidden="true"></i>Wykresy
    </a>

    <?php if ($_tsk_is_leader): ?>
    <div class="tsk-nav-sep" role="separator"></div>
    <span class="tsk-nav-label">Lider</span>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/problems') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/problems.php"
       aria-current="<?= _tsk_active('/tasks/problems') ? 'page' : 'false' ?>">
      <i class="bi bi-megaphone" aria-hidden="true"></i>Zgłoszone problemy
      <?php if ($_tsk_open_problems > 0): ?>
      <span class="tsk-nav-badge" style="background:#fef9c3;color:#92400e"
            aria-label="<?= $_tsk_open_problems ?> otwartych"><?= $_tsk_open_problems ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

    <div class="tsk-nav-sep" role="separator"></div>
    <span class="tsk-nav-label">Ustawienia</span>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/notification_settings') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/notification_settings.php"
       aria-current="<?= _tsk_active('/tasks/notification_settings') ? 'page' : 'false' ?>"
       onclick="if(!event.ctrlKey&&!event.metaKey){event.preventDefault();tskOpenNotifSettings();}">
      <i class="bi bi-bell-fill" aria-hidden="true"></i>Powiadomienia — ustawienia
      <?php if ($_tsk_notif_setup_needed): ?>
      <span class="tsk-nav-badge" style="background:#f59e0b;color:#fff" aria-label="do skonfigurowania">!</span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/notifications.php') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/notifications.php"
       aria-current="<?= _tsk_active('/tasks/notifications.php') ? 'page' : 'false' ?>">
      <i class="bi bi-clock-history" aria-hidden="true"></i>Historia powiadomień
      <?php if ($_due_soon > 0): ?>
      <span class="tsk-nav-badge" style="background:#fee2e2;color:#dc2626"
            aria-label="<?= $_due_soon ?> zadań z bliskim terminem"><?= $_due_soon ?></span>
      <?php endif; ?>
    </a>

    <?php if ($_tsk_is_any_leader): ?>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/workspaces') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/workspaces.php">
      <i class="bi bi-sliders" aria-hidden="true"></i>Obszary i listy
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/tags') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/tags.php">
      <i class="bi bi-tags" aria-hidden="true"></i>Tagi
    </a>
    <?php endif; ?>
    <?php if ($_is_admin): ?>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/areas') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/areas.php">
      <i class="bi bi-layers" aria-hidden="true"></i>Obszary zadań
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/roles') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/roles.php">
      <i class="bi bi-shield-lock" aria-hidden="true"></i>Uprawnienia ról
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/fields') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/fields.php">
      <i class="bi bi-ui-checks-grid" aria-hidden="true"></i>Uprawnienia pól
    </a>
    <div class="tsk-nav-sep" role="separator"></div>
    <a class="tsk-nav-link tsk-danger"
       href="<?= APP_URL ?>/admin/tasks_cleanup.php"
       aria-label="Wyczyść moduł zadań — operacja nieodwracalna">
      <i class="bi bi-trash3" aria-hidden="true"></i>Wyczyść moduł
    </a>
    <?php endif; ?>

    <div class="tsk-sidebar-bottom">
      <a href="<?= APP_URL ?>/auth/logout.php"
         class="tsk-nav-link"
         style="color:#dc2626"
         onclick="return confirm('Wylogować się?')">
        <i class="bi bi-box-arrow-right" aria-hidden="true" style="color:#dc2626"></i>Wyloguj się
      </a>
    </div>

  </nav>
</div>

<!-- ══ Treść główna ══════════════════════════════════════════════════════════ -->
<main class="container-xl py-4" id="tsk-main" tabindex="-1" role="main">

<!-- ── Komunikat: moduł tymczasowo wyłączony ─────────────────────────────── -->
<div class="alert mb-4 d-flex align-items-start gap-3 border-0 rounded-3"
     style="background:#fef3c7;border-left:4px solid #f59e0b!important;border-left-style:solid!important">
  <i class="bi bi-exclamation-triangle-fill text-warning fs-4 flex-shrink-0 mt-1"></i>
  <div>
    <strong class="d-block mb-1" style="color:#92400e">Moduł Zadań jest tymczasowo niedostępny</strong>
    <span style="color:#78350f;font-size:.92rem">
      Tymczasowo wracamy do Trello — prosimy korzystać z tablicy zespołu do czasu wprowadzenia aktualizacji.
    </span>
    <div class="mt-2">
      <a href="https://trello.com/b/VDjMNkbr/feer-wsp%C3%B3%C5%82praca-zespo%C5%82u"
         target="_blank" rel="noopener"
         class="btn btn-sm btn-warning fw-semibold">
        <i class="bi bi-trello me-1"></i>Otwórz tablicę Trello
      </a>
    </div>
  </div>
</div>
<?php
require_once __DIR__ . '/footer_tasks.php';
exit;
?>

<?php
$_fm = flash_get();
if ($_fm): ?>
<div class="tsk-flash" role="status" aria-live="polite">
  <div class="alert alert-<?= $_fm['type'] === 'error' ? 'danger' : h($_fm['type']) ?> alert-dismissible">
    <?= h($_fm['msg'] ?? $_fm['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
</div>
<?php endif; ?>

<!-- Baner zachęty do powiadomień przeglądarkowych -->
<div id="tsk-notif-banner" role="alert" aria-live="polite" style="display:none">
  <div class="tsk-nb-icon" aria-hidden="true"><i class="bi bi-bell-fill"></i></div>
  <div class="tsk-nb-body">
    <div class="tsk-nb-title">Włącz powiadomienia w przeglądarce</div>
    <div class="tsk-nb-sub">Otrzymasz alert na ekranie, gdy pojawi się nowe zadanie lub komentarz — nawet gdy karta jest w tle.</div>
  </div>
  <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
    <button type="button" class="btn btn-sm" id="tsk-notif-enable-btn"
            style="background:var(--tsk-green);color:#fff;font-size:.8rem;border:none">
      <i class="bi bi-bell-fill me-1" aria-hidden="true"></i>Włącz powiadomienia
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="tsk-notif-dismiss-btn"
            style="font-size:.8rem">Nie teraz</button>
  </div>
</div>

<?php if ($_tsk_notif_setup_needed): ?>
<!-- Baner konfiguracji powiadomień e-mail — nowi/niekonfigurowany użytkownicy -->
<div id="tsk-setup-banner" role="alert" aria-live="polite">
  <div class="tsk-sb-icon" aria-hidden="true"><i class="bi bi-gear-fill"></i></div>
  <div class="tsk-sb-body">
    <div class="tsk-sb-title">Skonfiguruj powiadomienia e-mail</div>
    <div class="tsk-sb-sub">Używasz ustawień domyślnych — wybierz co i kiedy trafia na Twoją skrzynkę.</div>
  </div>
  <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
    <button type="button" onclick="tskOpenNotifSettings()"
            class="btn btn-sm" style="background:#f59e0b;color:#fff;font-size:.8rem;border:none">
      <i class="bi bi-gear-fill me-1" aria-hidden="true"></i>Skonfiguruj teraz
    </button>
    <button type="button" id="tsk-setup-dismiss-btn"
            class="btn btn-sm btn-outline-secondary" style="font-size:.8rem">Później</button>
  </div>
</div>
<script>
(function () {
    var KEY  = 'tskSetupBannerDismissed';
    var DAYS = 7;
    var el   = document.getElementById('tsk-setup-banner');
    var btn  = document.getElementById('tsk-setup-dismiss-btn');
    if (!el) return;
    var ts = parseInt(localStorage.getItem(KEY) || '0', 10);
    if (ts && (Date.now() - ts) < DAYS * 86400 * 1000) {
        el.style.display = 'none';
    }
    if (btn) btn.addEventListener('click', function () {
        localStorage.setItem(KEY, String(Date.now()));
        el.style.display = 'none';
    });
})();
</script>
<?php endif; ?>

<script>
/* Przełącznik obszaru roboczego — wyszukiwarka */
function tskWsFilter(query) {
    const q    = query.trim().toLowerCase();
    const list = document.getElementById('tsk-ws-switch-list');
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

/* Mini centrum powiadomień — polling + dźwięk + natywne powiadomienia przeglądarki */
(function () {
    const APP_URL  = '<?= APP_URL ?>';
    const POLL_MS  = 25000;
    let lastUnread = <?= (int)$_tsk_notif_unread ?>;
    let audioCtx   = null;

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function tskPlayDing() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const now = audioCtx.currentTime;
            [[880, 0], [1318.5, 0.09]].forEach(([freq, delay]) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine'; osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, now + delay);
                gain.gain.exponentialRampToValueAtTime(0.18, now + delay + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.5);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(now + delay); osc.stop(now + delay + 0.55);
            });
        } catch (e) {}
    }

    function tskNativeNotif(title, body, url) {
        try {
            if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
            const n = new Notification(title, {
                body: body || '',
                icon: APP_URL + '/assets/img/icon-192.png',
                tag:  'tsk-notif'
            });
            if (url) n.onclick = function () { window.focus(); window.location = url; n.close(); };
        } catch (e) {}
    }

    function tskRenderNotifList(items) {
        const list = document.getElementById('tsk-notif-list');
        if (!list) return;
        if (!items.length) {
            list.innerHTML = '<div class="text-center py-4 text-muted" style="font-size:.82rem"><i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:.3" aria-hidden="true"></i>Brak powiadomień</div>';
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
            + (n.is_read ? '' : '<span class="rounded-circle flex-shrink-0" style="width:7px;height:7px;margin-top:5px;background:var(--tsk-green)" aria-hidden="true"></span>')
            + '</div></a>'
        )).join('');
    }

    function tskApplyUnread(unread) {
        const badge   = document.getElementById('tsk-notif-count');
        const markAll = document.getElementById('tsk-notif-mark-all');
        const btn     = document.getElementById('tsk-notif-btn');
        if (badge) { badge.textContent = unread > 99 ? '99+' : unread; badge.classList.toggle('d-none', unread === 0); }
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
                    if (btn) { btn.classList.remove('tsk-notif-shake'); void btn.offsetWidth; btn.classList.add('tsk-notif-shake'); }
                    /* Powiadomienie natywne przeglądarki — pierwsze nowe powiadomienie z listy */
                    const newest = (d.latest || []).find(n => !n.is_read);
                    if (newest) tskNativeNotif(newest.title, newest.body, newest.url);
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
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_read', id: parseInt(link.dataset.notifId, 10)})
            }).catch(() => {});
            return;
        }
        const markAll = e.target.closest('#tsk-notif-mark-all');
        if (markAll) {
            fetch(APP_URL + '/tasks/api/notif_poll.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_all'})
            }).then(r => r.json()).then(d => {
                if (d.ok) { lastUnread = d.unread; tskApplyUnread(d.unread); tskRenderNotifList(d.latest || []); }
            }).catch(() => {});
        }
    });

    setInterval(tskPollNotifications, POLL_MS);
})();

/* Baner zachęty do powiadomień przeglądarkowych */
(function () {
    const DISMISS_KEY     = 'tskNotifBannerDismissed';
    const DISMISS_DAYS    = 14;

    function bannerShouldShow() {
        if (typeof Notification === 'undefined') return false;
        if (Notification.permission !== 'default') return false;
        const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
        if (ts && (Date.now() - ts) < DISMISS_DAYS * 86400 * 1000) return false;
        return true;
    }

    function bannerHide() {
        const el = document.getElementById('tsk-notif-banner');
        if (el) el.style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!bannerShouldShow()) return;
        const banner = document.getElementById('tsk-notif-banner');
        if (!banner) return;
        banner.style.display = '';

        document.getElementById('tsk-notif-enable-btn').addEventListener('click', function () {
            Notification.requestPermission().then(function (result) {
                bannerHide();
                if (result === 'granted') {
                    /* Potwierdzenie po włączeniu */
                    try { new Notification('Powiadomienia włączone', { body: 'Będziesz informowany/a o nowych zadaniach i komentarzach.', tag: 'tsk-welcome' }); }
                    catch (e) {}
                }
            }).catch(function () { bannerHide(); });
        });

        document.getElementById('tsk-notif-dismiss-btn').addEventListener('click', function () {
            localStorage.setItem(DISMISS_KEY, String(Date.now()));
            bannerHide();
        });
    });
})();
</script>

<!-- ══ Modal: Ustawienia powiadomień ════════════════════════════════════ -->
<div class="modal fade" id="tskNotifSettingsModal" tabindex="-1"
     aria-labelledby="tskNotifSettingsModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <div class="modal-header py-2 px-3" style="background:#1e40af;border-bottom:none">
        <h5 class="modal-title h6 fw-bold mb-0 text-white" id="tskNotifSettingsModalLabel">
          <i class="bi bi-bell-fill me-2" aria-hidden="true"></i>Ustawienia powiadomień
        </h5>
        <button type="button" class="btn-close btn-close-white btn-sm"
                data-bs-dismiss="modal" aria-label="Zamknij ustawienia powiadomień"></button>
      </div>
      <div class="modal-body p-0 overflow-auto" id="tskNotifSettingsModalBody" style="max-height:82vh">
        <div class="text-center py-5 text-muted">
          <div class="spinner-border spinner-border-sm" role="status">
            <span class="visually-hidden">Ładowanie…</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
/* ── Modal: Ustawienia powiadomień — AJAX loader ──────────────────────────── */
(function () {
    var APP = '<?= APP_URL ?>';
    var FRAG_URL = APP + '/tasks/notification_settings.php?_fragment=1';
    var modalEl  = null;
    var modalInst = null;
    var loaded   = false;

    function getInst() {
        if (!modalEl) {
            modalEl   = document.getElementById('tskNotifSettingsModal');
            modalInst = bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        return modalInst;
    }

    function getBody() {
        return document.getElementById('tskNotifSettingsModalBody');
    }

    function showBanner(body, ok, msg) {
        var old = body.querySelector('.tsk-ns-alert-wrap');
        if (old) old.remove();
        var wrap = document.createElement('div');
        wrap.className = 'tsk-ns-alert-wrap';
        wrap.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'warning')
            + ' d-flex align-items-center gap-2 py-2 mx-3 mt-3 mb-0" role="status" style="font-size:.83rem">'
            + '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i>'
            + '<span>' + msg.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>'
            + '<button type="button" class="btn-close btn-sm ms-auto py-0" onclick="this.closest(\'.tsk-ns-alert-wrap\').remove()" aria-label="Zamknij"></button>'
            + '</div>';
        body.prepend(wrap);
    }

    function wireBody(body) {
        body.addEventListener('submit', function (e) {
            var form = e.target.closest('form');
            if (!form) return;
            e.preventDefault();
            var submitter = e.submitter;
            var fd = new FormData(form);
            if (submitter && submitter.name) fd.set(submitter.name, submitter.value);

            var btn = submitter || form.querySelector('[type=submit]');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:.85em;height:.85em" aria-hidden="true"></span>'; }

            fetch(FRAG_URL, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                    showBanner(body, d.ok, d.msg || (d.ok ? 'OK' : 'Błąd'));
                    if (d.reload) loadContent(true);
                })
                .catch(function () {
                    if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                    showBanner(body, false, 'Błąd połączenia. Spróbuj ponownie.');
                });
        });
    }

    function loadContent(force) {
        var body = getBody();
        if (!body) return;
        if (loaded && !force) return;
        loaded = false;
        body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
        fetch(FRAG_URL)
            .then(function (r) { return r.text(); })
            .then(function (html) {
                body.innerHTML = '';
                var frag = document.createRange().createContextualFragment(html);
                body.appendChild(frag);
                loaded = true;
                wireBody(body);
            })
            .catch(function () {
                body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania ustawień powiadomień.</div>';
            });
    }

    window.tskOpenNotifSettings = function () {
        getInst().show();
        loadContent(false);
    };

    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('tskNotifSettingsModal');
        if (el) {
            el.addEventListener('hidden.bs.modal', function () { loaded = false; });
        }
    });
})();
</script>
