<?php
/**
 * tasks/includes/header_tasks.php
 * Layout modułu Zadania — Tailwind (prefiks tw-, preflight wyłączony obok
 * Bootstrapa, który zostaje silnikiem interaktywnych komponentów: dropdowny,
 * offcanvas, modale — zgodnie z konwencją migracji, patrz includes/header.php).
 *
 * Wymaga zdefiniowania $PAGE_TITLE przed include.
 * Opcjonalnie: $PAGE_SUBTITLE, $TASKS_WS_ID (int), $TASKS_BREADCRUMB (string)
 *
 * Podział na pliki (przebudowa 2026-09-03, poprzednio jeden plik 1133 linii):
 *   - tasks/includes/tasks_topbar.php  — górny pasek (marka, przełącznik obszaru,
 *     powiadomienia, menu użytkownika)
 *   - tasks/includes/tasks_sidebar.php — offcanvas nawigacji
 *   - tasks/includes/tasks_notif_modal.php — modal "Ustawienia powiadomień"
 *   - assets/js/tasks-header.js — cała logika JS (dawniej kilka inline <script>)
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';

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
<!-- Tailwind — warstwa wizualna modułu Zadania (klasy z prefiksem "tw-",
     preflight wyłączony, żeby nie kolidować z Bootstrapem, który zostaje
     silnikiem interaktywnych komponentów: dropdowny/offcanvas/modale). -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    prefix: 'tw-',
    corePlugins: { preflight: false },
  };
</script>
<style type="text/tailwindcss">
/* ══════════════════════════════════════════════════════════════
   Moduł Zadania — layout w stylu panelu wolontariusza
   Paleta: emerald/teal  ·  WCAG AA
   Zmienne --tsk-* są WSPÓLNYM kontraktem z pozostałymi, jeszcze
   nieprzebudowanymi stronami modułu (dashboard.php, charts.php,
   files.php, moje.php, notifications.php, problems.php, inbox.php)
   — NIE zmieniać nazw/wartości bez przeglądu tych plików.
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
  @apply tw-m-0 tw-min-h-screen tw-flex tw-flex-col tw-text-[.9375rem] tw-leading-[1.6];
  background: var(--tsk-bg);
  color: var(--tsk-text);
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}

/* Skip link */
.skip-link {
  @apply tw-absolute tw-left-4 tw-z-[9999] tw-text-white tw-py-[.65rem] tw-px-[1.4rem]
         tw-rounded-b-lg tw-text-[.95rem] tw-font-bold tw-no-underline tw-border-[3px] tw-border-solid;
  top: -100%;
  background: var(--tsk-green);
  border-color: var(--tsk-focus);
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
  @apply tw-bg-white tw-border-b tw-border-[#E5E7EB] tw-shadow-[0_1px_3px_rgba(0,0,0,.04)] tw-sticky tw-top-0 tw-z-[1040];
}
.tsk-menu-btn {
  @apply tw-border tw-border-[#E5E7EB] tw-bg-white tw-rounded-[9px] tw-w-10 tw-h-10
         tw-flex tw-items-center tw-justify-center tw-text-xl tw-text-[#374151] tw-cursor-pointer tw-shrink-0
         tw-transition-colors;
}
.tsk-menu-btn:hover { border-color: var(--tsk-green); color: var(--tsk-green); }

.tsk-brand {
  @apply tw-flex tw-items-center tw-gap-[.6rem] tw-no-underline tw-text-[#111827] tw-font-extrabold tw-text-[.98rem] tw-min-w-0;
}
.tsk-brand:hover { color: #111827; }
.tsk-brand-icon {
  @apply tw-w-[34px] tw-h-[34px] tw-text-white tw-rounded-[9px]
         tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0;
  background: var(--tsk-green);
}
.tsk-brand-sub {
  @apply tw-text-[.66rem] tw-opacity-60 tw-font-medium tw-leading-none
         tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis tw-max-w-[180px];
}

.tsk-avatar {
  @apply tw-w-9 tw-h-9 tw-rounded-full tw-text-white
         tw-flex tw-items-center tw-justify-center tw-text-[.78rem] tw-font-bold
         tw-cursor-pointer tw-border-0 tw-leading-none;
  background: var(--tsk-green);
}

/* ── Offcanvas nawigacja ─────────────────────────────────────── */
.tsk-offcanvas { max-width: 285px; }
.tsk-offcanvas .offcanvas-header {
  background: var(--tsk-green-dark); color: #fff;
}
.tsk-offcanvas .offcanvas-body {
  @apply tw-flex tw-flex-col;
  padding: .35rem 0;
}
.tsk-nav-label {
  @apply tw-text-[.65rem] tw-font-bold tw-tracking-[.08em] tw-uppercase tw-text-[#9CA3AF]
         tw-px-4 tw-pt-[.85rem] tw-pb-[.3rem] tw-select-none tw-block;
}
.tsk-nav-link {
  @apply tw-flex tw-items-center tw-gap-[.6rem] tw-py-2 tw-px-3 tw-rounded-lg
         tw-text-[.88rem] tw-font-medium tw-text-[#374151] tw-no-underline
         tw-transition-colors tw-mx-2 tw-my-[.05rem] tw-border-l-[3px] tw-border-transparent;
}
.tsk-nav-link i {
  @apply tw-text-base tw-w-5 tw-text-center tw-shrink-0 tw-text-[#9CA3AF] tw-transition-colors;
}
.tsk-nav-link:hover { background: var(--tsk-green-bg); color: var(--tsk-green); border-left-color: var(--tsk-green); }
.tsk-nav-link:hover i { color: var(--tsk-green); }
.tsk-nav-link.active {
  @apply tw-font-bold;
  background: var(--tsk-green-bg); color: var(--tsk-green); border-left-color: var(--tsk-green);
}
.tsk-nav-link.active i { color: var(--tsk-green); }
.tsk-nav-link.tsk-danger { color: #dc2626; }
.tsk-nav-link.tsk-danger i { color: #dc2626; }
.tsk-nav-link.tsk-danger:hover { background: #fef2f2; color: #b91c1c; border-left-color: #dc2626; }
.tsk-nav-badge {
  @apply tw-ml-auto tw-text-white tw-text-[.65rem] tw-font-bold tw-py-[.1rem] tw-px-[.4rem]
         tw-rounded-[10px] tw-min-w-[18px] tw-text-center;
  background: var(--tsk-green);
}
.tsk-nav-sep { @apply tw-h-px tw-bg-[#F3F4F6] tw-mx-3 tw-my-[.4rem]; }
.tsk-sidebar-bottom { @apply tw-mt-auto tw-border-t tw-border-[#F3F4F6] tw-p-2; }

/* ── Przełącznik obszaru ─────────────────────────────────────── */
.tsk-ws-switch-btn {
  @apply tw-inline-flex tw-items-center tw-gap-[.4rem] tw-bg-[#F4F6F9] tw-border tw-border-[#E5E7EB]
         tw-rounded-lg tw-text-[#374151] tw-py-[.3rem] tw-px-[.7rem] tw-text-[.83rem] tw-font-medium
         tw-cursor-pointer tw-whitespace-nowrap tw-shrink-0 tw-transition-colors;
}
.tsk-ws-switch-btn:hover { background: var(--tsk-green-bg); border-color: var(--tsk-green); color: var(--tsk-green); }
.tsk-ws-switch-dot { @apply tw-w-[9px] tw-h-[9px] tw-rounded-full tw-shrink-0; }
.tsk-ws-switch-menu {
  @apply tw-w-[290px] tw-max-h-[420px] tw-flex-col tw-p-0 tw-overflow-hidden;
}
.tsk-ws-switch-menu.show { display: flex; }
.tsk-ws-switch-search-wrap { @apply tw-p-2 tw-border-b tw-border-[#e2e8f0] tw-shrink-0; }
.tsk-ws-switch-list { @apply tw-overflow-y-auto; }
.tsk-ws-switch-item {
  @apply tw-flex tw-items-center tw-gap-[.55rem] tw-py-2 tw-px-[.9rem] tw-text-[#334155] tw-no-underline
         tw-text-[.84rem] tw-whitespace-nowrap;
}
.tsk-ws-switch-item:hover { background: #f8fafc; color: #0f172a; }
.tsk-ws-switch-item.active { background: var(--tsk-green-bg); color: var(--tsk-green); font-weight: 600; }
.tsk-ws-switch-item i { @apply tw-shrink-0; }
.tsk-ws-switch-sep { @apply tw-h-px tw-bg-[#f1f5f9] tw-my-[.3rem]; }
.tsk-ws-switch-empty { @apply tw-text-center tw-text-[#94a3b8] tw-text-[.8rem] tw-p-4; }
.tsk-ws-dot { @apply tw-w-2 tw-h-2 tw-rounded-full tw-shrink-0; }
.tsk-ws-cnt { @apply tw-ml-auto tw-text-[.68rem] tw-text-[#94a3b8]; }

/* ── Powiadomienia ───────────────────────────────────────────── */
.tsk-notif-btn {
  @apply tw-relative tw-inline-flex tw-items-center tw-justify-center
         tw-bg-[#F4F6F9] tw-border tw-border-[#E5E7EB] tw-rounded-lg tw-text-[#374151]
         tw-w-[38px] tw-h-[38px] tw-shrink-0 tw-text-[.95rem] tw-cursor-pointer tw-transition-colors;
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
  @apply tw-absolute tw-top-[-4px] tw-right-[-4px] tw-bg-red-600 tw-text-white
         tw-rounded-full tw-text-[.6rem] tw-font-bold tw-min-w-[16px] tw-h-4 tw-px-[3px]
         tw-flex tw-items-center tw-justify-center tw-leading-none tw-border-2 tw-border-white;
}
.tsk-notif-item.fw-semibold { background: #f5f9ff; }

/* ── Treść główna ────────────────────────────────────────────── */
#tsk-main { flex: 1 0 auto; }
.tsk-flash { margin-bottom: 1rem; }

/* ── Stopka ──────────────────────────────────────────────────── */
.tsk-footer {
  @apply tw-border-t tw-border-[#E5E7EB] tw-py-[.6rem] tw-px-6 tw-text-xs tw-text-[#9CA3AF] tw-bg-white
         tw-flex tw-justify-between tw-flex-wrap tw-gap-2;
}

/* ── Baner konfiguracji powiadomień e-mail (nowi użytkownicy) ─── */
#tsk-setup-banner {
  @apply tw-bg-amber-50 tw-border tw-border-amber-300 tw-rounded-[10px] tw-py-3 tw-px-4
         tw-flex tw-items-center tw-gap-3 tw-flex-wrap tw-mb-4;
  animation: tskBannerIn .25s ease;
}
#tsk-setup-banner .tsk-sb-icon {
  @apply tw-w-9 tw-h-9 tw-rounded-[9px] tw-bg-amber-500 tw-text-white
         tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0;
}
#tsk-setup-banner .tsk-sb-body { @apply tw-flex-1 tw-min-w-[180px]; }
#tsk-setup-banner .tsk-sb-title { @apply tw-font-bold tw-text-[.88rem] tw-text-amber-900; }
#tsk-setup-banner .tsk-sb-sub   { @apply tw-text-[.77rem] tw-text-amber-800 tw-mt-[.1rem]; }

/* ── Baner powiadomień przeglądarkowych ──────────────────────── */
#tsk-notif-banner {
  @apply tw-border tw-border-emerald-300 tw-rounded-[10px] tw-py-3 tw-px-4
         tw-flex tw-items-center tw-gap-3 tw-flex-wrap tw-mb-4;
  background: var(--tsk-green-bg);
  animation: tskBannerIn .25s ease;
}
@keyframes tskBannerIn {
  from { opacity:0; transform:translateY(-6px); }
  to   { opacity:1; transform:translateY(0); }
}
#tsk-notif-banner .tsk-nb-icon {
  @apply tw-w-9 tw-h-9 tw-rounded-[9px] tw-text-white
         tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0;
  background: var(--tsk-green);
}
#tsk-notif-banner .tsk-nb-body { @apply tw-flex-1 tw-min-w-[180px]; }
#tsk-notif-banner .tsk-nb-title { @apply tw-font-bold tw-text-[.88rem]; color: #065f46; }
#tsk-notif-banner .tsk-nb-sub { @apply tw-text-[.77rem] tw-mt-[.1rem]; color: #047857; }
</style>
</head>
<body>

<a class="skip-link" href="#tsk-main">Przejdź do treści</a>

<?php require_once __DIR__ . '/tasks_topbar.php'; ?>

<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>
<?php $ASAI_WIDGET_SCOPE = 'zadania';
      require_once dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/quick_actions_widget.php'; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/search_hotkey.php'; ?>

<?php require_once __DIR__ . '/tasks_sidebar.php'; ?>

<!-- ══ Treść główna ══════════════════════════════════════════════════════════ -->
<main class="container-xl py-4" id="tsk-main" tabindex="-1" role="main">

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
<?php endif; ?>

<?php require_once __DIR__ . '/tasks_notif_modal.php'; ?>

<script>
  window.TSK_HEADER = { appUrl: <?= json_encode(APP_URL) ?>, unread: <?= (int)$_tsk_notif_unread ?> };
</script>
<script src="<?= APP_URL ?>/assets/js/tasks-header.js" defer></script>
