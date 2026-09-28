<?php
// ti.feer.org.pl / → chooser (public, no login required)
if (in_array($_SERVER['HTTP_HOST'] ?? '', ['ti.feer.org.pl', 'ti.ngosystem.pl'], true)
    && preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI'] ?? '/') === '/') {
    header('Location: /karty30/ti/kursant/chooser.php', true, 302);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
if (defined('CRM_STANDALONE') && CRM_STANDALONE) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }

$PAGE_TITLE = 'Pulpit';
$_user      = current_user();
$today      = date('Y-m-d');
$month_start = date('Y-m-01');

// ── KPI — główne liczniki ─────────────────────────────────────────────────────
$kpi = ['total' => 0, 'active' => 0, 'new_month' => 0, 'expiring_7' => 0,
        'expiring_30' => 0, 'pending_approval' => 0, 'tasks_open' => 0, 'tasks_mine' => 0];

$end_col_map = [
    'zlecenie' => 'data_zakonczenia', 'uslugi' => 'data_zakonczenia',
    'wolontariat' => 'data_zakonczenia', 'dzielo' => 'termin_oddania',
    'praca' => 'data_zakonczenia', 'inne' => 'data_zakonczenia',
];

foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $col   = $end_col_map[$slug] ?? 'data_zakonczenia';
    try {
        $total  = (int)(db_one("SELECT COUNT(*) AS c FROM {$table}")['c'] ?? 0);
        $active = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE status IN ('podpisana','w realizacji','obowiązująca')")['c'] ?? 0);
        $new_m  = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE DATE(created_at) >= ?", [$month_start])['c'] ?? 0);
        $exp7   = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE bezterminowa=0 AND {$col} BETWEEN ? AND DATE(?,'+7 days') AND status NOT IN ('zakończona','anulowana','rozwiązana')", [$today, $today])['c'] ?? 0);
        $exp30  = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE bezterminowa=0 AND {$col} BETWEEN ? AND DATE(?,'+30 days') AND status NOT IN ('zakończona','anulowana','rozwiązana')", [$today, $today])['c'] ?? 0);
        $kpi['total']       += $total;
        $kpi['active']      += $active;
        $kpi['new_month']   += $new_m;
        $kpi['expiring_7']  += $exp7;
        $kpi['expiring_30'] += $exp30;
    } catch (\Throwable $e) {}
}

try { $kpi['pending_approval'] = (int)(db_one("SELECT COUNT(*) AS c FROM approval_requests WHERE status='pending'")['c'] ?? 0); } catch (\Throwable $e) {}
try {
    $kpi['tasks_open'] = (int)(db_one("SELECT COUNT(*) AS c FROM tasks WHERE deleted_at IS NULL AND status NOT IN ('done','archived')")['c'] ?? 0);
    $kpi['tasks_mine'] = (int)(db_one("SELECT COUNT(*) AS c FROM task_assignments ta JOIN tasks t ON t.id=ta.task_id WHERE ta.user_id=? AND t.deleted_at IS NULL AND t.status NOT IN ('done','archived')", [(int)$_user['id']])['c'] ?? 0);
} catch (\Throwable $e) {}

// ── Wygasające ────────────────────────────────────────────────────────────────
$expiring = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $col   = $end_col_map[$slug] ?? 'data_zakonczenia';
    try {
        $rows = db_all(
            "SELECT id, numer_umowy, imie_nazwisko, {$col} AS end_date, '{$slug}' AS type, '{$label}' AS type_label
             FROM {$table}
             WHERE bezterminowa=0 AND {$col} BETWEEN ? AND DATE(?,'+30 days')
               AND status NOT IN ('zakończona','anulowana','rozwiązana')
             ORDER BY {$col} LIMIT 8",
            [$today, $today]
        );
        $expiring = array_merge($expiring, $rows);
    } catch (\Throwable $e) {}
}
usort($expiring, fn($a, $b) => strcmp($a['end_date'], $b['end_date']));

// ── Oczekujące akceptacje ─────────────────────────────────────────────────────
$pending_approvals = [];
try {
    $pending_approvals = db_all(
        "SELECT ar.id, ar.contract_type, ar.contract_id, ar.numer_umowy, ar.requester_name, ar.created_at
         FROM approval_requests ar WHERE ar.status='pending' ORDER BY ar.created_at DESC LIMIT 5"
    );
} catch (\Throwable $e) {}

// ── Wiadomości ────────────────────────────────────────────────────────────────
$dash_threads = [];
if (can_edit()) {
    try {
        require_once __DIR__ . '/includes/messages.php';
        $dash_threads = db_all("
            SELECT m.context_type, m.context_id, m.contract_type,
                   MAX(m.created_at) AS last_at,
                   SUM(CASE WHEN m.sender_type='user' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread,
                   (SELECT sender_name FROM messages m2 WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id ORDER BY m2.created_at DESC LIMIT 1) AS last_sender,
                   (SELECT body FROM messages m2 WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id ORDER BY m2.created_at DESC LIMIT 1) AS last_body
            FROM messages m GROUP BY m.context_type, m.context_id ORDER BY last_at DESC LIMIT 6
        ");
        foreach ($dash_threads as &$_dt) {
            if ($_dt['context_type'] === 'contract') {
                $table = 'umowy_' . $_dt['contract_type'];
                try {
                    $r = db_one("SELECT numer_umowy, imie_nazwisko FROM {$table} WHERE id=?", [(int)$_dt['context_id']]);
                    $_dt['_label'] = $r['numer_umowy'] ?? '#' . $_dt['context_id'];
                    $_dt['_sub']   = $r['imie_nazwisko'] ?? '';
                    $_dt['_url']   = APP_URL . '/contracts/' . $_dt['contract_type'] . '/view.php?id=' . $_dt['context_id'];
                } catch (\Throwable $e) { $_dt['_label'] = '#' . $_dt['context_id']; $_dt['_sub'] = ''; $_dt['_url'] = '#'; }
            } else {
                try {
                    $r = db_one("SELECT imie_nazwisko FROM onboarding_volunteers WHERE id=?", [(int)$_dt['context_id']]);
                    $_dt['_label'] = $r['imie_nazwisko'] ?? '#' . $_dt['context_id'];
                    $_dt['_sub']   = 'Zgłoszenie';
                    $_dt['_url']   = APP_URL . '/admin/onboarding_view.php?id=' . $_dt['context_id'];
                } catch (\Throwable $e) { $_dt['_label'] = ''; $_dt['_sub'] = ''; $_dt['_url'] = '#'; }
            }
        }
        unset($_dt);
    } catch (\Throwable $e) {}
}

// ── Moje zadania ──────────────────────────────────────────────────────────────
$my_tasks = [];
try {
    $my_tasks = db_all(
        "SELECT t.id, t.title, t.priority, t.due_date, tl.name AS list_name, tw.name AS ws_name
         FROM tasks t
         JOIN task_assignments ta ON ta.task_id = t.id AND ta.user_id = ?
         LEFT JOIN task_lists tl ON tl.id = t.list_id
         LEFT JOIN task_workspaces tw ON tw.id = t.workspace_id
         WHERE t.deleted_at IS NULL AND t.status NOT IN ('done','archived')
         ORDER BY t.due_date ASC NULLS LAST, t.priority DESC
         LIMIT 6",
        [(int)$_user['id']]
    );
} catch (\Throwable $e) {}

// ── Liczniki rejestru menu (jedno źródło prawdy dla badge'y) → lista „Wymaga uwagi”
require_once __DIR__ . '/includes/menu.php';
$cnt = _menu_counts();

$tasks_overdue = 0;
try {
    $tasks_overdue = (int)(db_one(
        "SELECT COUNT(*) AS c FROM task_assignments ta JOIN tasks t ON t.id=ta.task_id
         WHERE ta.user_id=? AND t.deleted_at IS NULL AND t.status NOT IN ('done','archived')
           AND t.due_date IS NOT NULL AND t.due_date < ?", [(int)$_user['id'], $today])['c'] ?? 0);
} catch (\Throwable $e) {}

// ── Komunikaty organizacji ────────────────────────────────────────────────────
$ann_list = []; $ann_unread = 0;
try {
    require_once __DIR__ . '/includes/notifications.php'; notif_migrate();
    $ann_list   = array_slice(ann_list_for_user((int)$_user['id'], $_user['role'] ?? ''), 0, 5);
    $ann_unread = count(array_filter(ann_list_for_user((int)$_user['id'], $_user['role'] ?? ''), fn($a) => empty($a['is_read_by_me'])));
} catch (\Throwable $e) {}

// ── Ostatnio dodane umowy ─────────────────────────────────────────────────────
$recent = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    if (!module_enabled('contract_' . $slug)) continue;
    try {
        $rows = db_all("SELECT id, '{$slug}' AS type, '{$label}' AS type_label, numer_umowy, imie_nazwisko, status, created_at
                        FROM " . table_for_type($slug) . " ORDER BY created_at DESC LIMIT 3");
        $recent = array_merge($recent, $rows);
    } catch (\Throwable $e) {}
}
usort($recent, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
$recent = array_slice($recent, 0, 6);
$ct_icons = _menu_contract_icons();

// ── Wymaga uwagi — zebrane w jedną listę, tylko niezerowe ─────────────────────
$attn = [];
$attn_add = function (int $n, string $label, string $url, string $icon, string $tone = 'amber') use (&$attn) {
    if ($n > 0) $attn[] = ['n' => $n, 'label' => $label, 'url' => $url, 'icon' => $icon, 'tone' => $tone];
};
$attn_add($kpi['pending_approval'], 'Wnioski do akceptacji', '/admin/approvals.php', 'bi-hourglass-split', 'violet');
$attn_add($kpi['expiring_7'],       'Umowy wygasające w 7 dni', '/admin/contract_expiry.php', 'bi-calendar-x', 'red');
if (module_enabled('tasks_enabled'))
    $attn_add($tasks_overdue,       'Moje zadania po terminie', '/tasks/index.php', 'bi-exclamation-circle', 'red');
if (module_enabled('terminations_enabled'))
    $attn_add($cnt['term'],         'Rozwiązania umów do rozpatrzenia', '/admin/terminations.php', 'bi-file-earmark-x');
$attn_add($cnt['rek'],              'Nowe zgłoszenia wolontariuszy', '/contracts/rekrutacja/index.php', 'bi-megaphone');
if (module_enabled('onboarding_enabled'))
    $attn_add($cnt['ob'],           'Onboarding — oczekujący', '/onboarding/index.php', 'bi-person-check');
if (module_enabled('timesheets_enabled'))
    $attn_add($cnt['ts'],           'Ewidencja godzin do zatwierdzenia', '/admin/timesheets.php', 'bi-clock-history');
if (module_enabled('certificates_enabled'))
    $attn_add($cnt['cert'],         'Wnioski o zaświadczenia', '/admin/certificates.php', 'bi-award');
$attn_add($cnt['zwr'],              'Zwroty kosztów do weryfikacji', '/contracts/zwroty/index.php', 'bi-receipt-cutoff');
$attn_add($cnt['res'],              'Rezerwacje zasobów', '/modules/srs/', 'bi-box-seam');
if (!empty($cnt['has_shipping']))
    $attn_add($cnt['ship'],         'Przesyłki do nadania', '/admin/shipments.php', 'bi-truck');
if (module_enabled('obiegi_enabled'))
    $attn_add($cnt['obieg'],        'Obiegi w Twojej skrzynce', '/obiegi/index.php', 'bi-diagram-2', 'blue');
if (module_enabled('helpdesk_enabled'))
    $attn_add($cnt['hd'],           'Otwarte zgłoszenia helpdesk', '/helpdesk/index.php', 'bi-ticket-perforated');
if (module_enabled('messages_enabled'))
    $attn_add($cnt['msg'],          'Nieprzeczytane wiadomości', '/admin/messages.php', 'bi-chat-dots', 'blue');
if (!empty($cnt['alias_op']))
    $attn_add($cnt['alias'],        'Wnioski o aliasy e-mail', '/admin/email_aliasy.php', 'bi-at');
if (module_enabled('vpn_enabled'))
    $attn_add($cnt['vpn'],          'Wnioski o dostęp VPN', '/admin/vpn.php', 'bi-shield-check');
if (module_enabled('wsparcie_ou_enabled'))
    $attn_add($cnt['wsparcie_ou'],  'Wsparcie zewnętrzne OU', '/wsparcie_ou/index.php', 'bi-building-add');
if (is_admin())
    $attn_add($cnt['adm'],          'Sprawy administracyjne (poczta, wnioski o konta)', '/admin/index.php', 'bi-shield-shaded', 'slate');
$attn_add($ann_unread,              'Nieprzeczytane komunikaty', '/komunikaty/index.php', 'bi-megaphone-fill', 'blue');
$attn_total = array_sum(array_column($attn, 'n'));

// ── Nagłówek: powitanie + polska data ─────────────────────────────────────────
$hour = (int)date('H');
$greeting = $hour < 5 ? 'Dobranoc' : ($hour < 12 ? 'Dzień dobry' : ($hour < 18 ? 'Witaj' : 'Dobry wieczór'));
$first_name = explode(' ', trim($_user['first_name'] ?? $_user['name'] ?? $_user['email'] ?? ''))[0];
$_dni  = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
$_mies = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
$date_pl_long = $_dni[(int)date('w')] . ', ' . date('j') . ' ' . $_mies[(int)date('n') - 1] . ' ' . date('Y');
$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

// Typy umów dostępne w „Nowa umowa”
$new_types = [];
foreach (CONTRACT_TYPES as $slug => $label)
    if (module_enabled('contract_' . $slug) && file_exists(__DIR__ . "/contracts/{$slug}/add.php"))
        $new_types[$slug] = $label;

$tone_css = [
    'red'    => ['#dc2626', '#fef2f2', '#fecaca'],
    'amber'  => ['#d97706', '#fffbeb', '#fde68a'],
    'violet' => ['#7c3aed', '#f5f3ff', '#ddd6fe'],
    'blue'   => ['#2563eb', '#eff6ff', '#bfdbfe'],
    'slate'  => ['#475569', '#f8fafc', '#e2e8f0'],
];

include __DIR__ . '/includes/header.php';
?>

<style type="text/tailwindcss">
/* ── Pulpit — nazwane klasy z @apply (pełna strona, więc Tailwind CDN je kompiluje) ── */
.dash { @apply tw-max-w-[1440px] tw-mx-auto; }

/* Nagłówek */
.dash-hero { @apply tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-5; }
.dash-greet { @apply tw-text-2xl tw-font-extrabold tw-tracking-tight tw-text-slate-900 tw-m-0 tw-leading-tight; }
.dash-sub { @apply tw-text-sm tw-text-slate-500 tw-mt-1; }
.dash-actions { @apply tw-flex tw-flex-wrap tw-items-center tw-gap-2; }
.dash-btn {
    @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-px-3 tw-py-1.5 tw-text-[.82rem] tw-font-semibold tw-no-underline tw-cursor-pointer tw-transition-colors;
    background: #fff; color: #334155; border: 1px solid #e2e8f0;
}
.dash-btn:hover { background: #f8fafc; color: #0f172a; border-color: #cbd5e1; }
.dash-btn kbd { @apply tw-text-[.6rem] tw-font-bold tw-rounded tw-px-1 tw-py-0.5 tw-ml-1; background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; font-family: inherit; }
.dash-btn-primary { background: #2563eb; color: #fff; border-color: #2563eb; }
.dash-btn-primary:hover { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
.dash-btn .bi { font-size: .9rem; }

/* Karty KPI */
.dash-kpi { @apply tw-grid tw-gap-3 tw-mb-4; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
.kpi-card {
    @apply tw-relative tw-block tw-overflow-hidden tw-rounded-2xl tw-bg-white tw-no-underline tw-transition-shadow;
    border: 1px solid #e2e8f0; padding: 1rem 1.1rem 1rem; color: inherit;
}
.kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--kpi-accent, #94a3b8); }
a.kpi-card:hover { box-shadow: 0 8px 24px rgba(2,6,23,.08); color: inherit; }
.kpi-top { @apply tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-2; }
.kpi-icon { @apply tw-w-9 tw-h-9 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0; }
.kpi-val { @apply tw-text-3xl tw-font-extrabold tw-leading-none tw-text-slate-900; }
.kpi-lbl { @apply tw-text-[.72rem] tw-font-bold tw-uppercase tw-tracking-wider tw-text-slate-500 tw-mt-1.5; }
.kpi-sub { @apply tw-text-[.72rem] tw-text-slate-400 tw-mt-0.5; }

/* Siatka główna */
.dash-grid { @apply tw-grid tw-gap-4 tw-items-start; grid-template-columns: 1fr; }
@media (min-width: 1280px) { .dash-grid { grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr); } }
.dash-main { @apply tw-grid tw-gap-4 tw-min-w-0; grid-template-columns: 1fr; }
@media (min-width: 768px) { .dash-main { grid-template-columns: 1fr 1fr; } }
.dash-side { @apply tw-grid tw-gap-4 tw-min-w-0; }

/* Panele */
.dash-panel { @apply tw-bg-white tw-rounded-2xl tw-overflow-hidden tw-min-w-0; border: 1px solid #e2e8f0; }
.dash-panel-head { @apply tw-flex tw-items-center tw-justify-between tw-gap-2 tw-px-4 tw-py-2.5; border-bottom: 1px solid #f1f5f9; background: #fafbfc; }
.dash-panel-title { @apply tw-flex tw-items-center tw-gap-2 tw-text-[.86rem] tw-font-bold tw-text-slate-800 tw-m-0; }
.dash-panel-title .bi { font-size: .95rem; }
.dash-panel-title .badge { font-size: .62rem; }
.dash-link { @apply tw-text-[.76rem] tw-font-semibold tw-no-underline tw-inline-flex tw-items-center tw-gap-1 tw-rounded-md tw-px-2 tw-py-1 tw-transition-colors; color: #2563eb; }
.dash-link:hover { background: #eff6ff; color: #1d4ed8; }
.dash-empty { @apply tw-text-center tw-text-[.8rem] tw-text-slate-400 tw-py-6 tw-px-4; }
.dash-empty .bi { color: #16a34a; margin-right: .3rem; }

/* Wiersze list */
.dash-row {
    @apply tw-flex tw-items-center tw-gap-3 tw-px-4 tw-py-2.5 tw-no-underline tw-transition-colors tw-w-full tw-text-left;
    border-bottom: 1px solid #f8fafc; color: inherit; background: none; border-left: 0; border-right: 0; border-top: 0;
}
.dash-row:last-child { border-bottom: 0; }
a.dash-row:hover, button.dash-row:hover { background: #f8fafc; color: inherit; }
.dash-row-main { @apply tw-flex-1 tw-min-w-0; }
.dash-row-title { @apply tw-text-[.83rem] tw-font-semibold tw-text-slate-900 tw-truncate; }
.dash-row-sub { @apply tw-text-[.73rem] tw-text-slate-400 tw-truncate; }
.dash-row-end { @apply tw-text-[.72rem] tw-text-slate-500 tw-whitespace-nowrap tw-text-right tw-shrink-0; }
.dash-pill { @apply tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-text-[.68rem] tw-font-bold tw-px-2 tw-py-0.5 tw-shrink-0 tw-min-w-[2.4rem]; }
.dash-dot { @apply tw-w-2 tw-h-2 tw-rounded-full tw-shrink-0; }
.dash-avatar { @apply tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-[.65rem] tw-font-bold tw-shrink-0; }

/* Wymaga uwagi */
.attn-row { @apply tw-flex tw-items-center tw-gap-3 tw-px-3 tw-py-2 tw-rounded-xl tw-no-underline tw-transition-colors; color: #0f172a; }
.attn-row:hover { background: var(--bg); color: #0f172a; }
.attn-ic { @apply tw-w-8 tw-h-8 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-text-[.9rem] tw-shrink-0; background: var(--bg); color: var(--fg); }
.attn-lbl { @apply tw-flex-1 tw-min-w-0 tw-text-[.82rem] tw-font-medium tw-truncate; }
.attn-n { @apply tw-text-[.74rem] tw-font-extrabold tw-rounded-full tw-px-2 tw-py-0.5 tw-shrink-0; background: var(--bg); color: var(--fg); border: 1px solid var(--bd); }
.attn-list { @apply tw-p-2 tw-grid tw-gap-0.5; }
.attn-ok { @apply tw-flex tw-items-center tw-gap-3 tw-px-4 tw-py-5; }
.attn-ok-ic { @apply tw-w-10 tw-h-10 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-lg tw-shrink-0; background: #f0fdf4; color: #16a34a; }

/* Komunikaty */
.ann-row { @apply tw-block tw-px-4 tw-py-2.5 tw-no-underline; border-bottom: 1px solid #f8fafc; color: inherit; }
.ann-row:last-child { border-bottom: 0; }
.ann-row:hover { background: #f8fafc; color: inherit; }
.ann-row.unread { background: #eff6ff; }
.ann-row.unread:hover { background: #dbeafe; }
.ann-title { @apply tw-text-[.82rem] tw-font-semibold tw-text-slate-900 tw-flex tw-items-center tw-gap-1.5; }
.ann-meta { @apply tw-text-[.71rem] tw-text-slate-400 tw-mt-0.5; }

/* Moduły */
.dash-mods { @apply tw-grid tw-gap-2 tw-p-3; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); }
.dash-mod {
    @apply tw-flex tw-flex-col tw-items-center tw-gap-1.5 tw-rounded-xl tw-no-underline tw-text-center tw-py-2.5 tw-px-1 tw-transition-colors;
    border: 1px solid #eef2f7; color: #334155; font-size: .7rem; font-weight: 600; line-height: 1.15;
}
.dash-mod:hover { background: #f8fafc; color: #0f172a; border-color: #cbd5e1; }
.dash-mod.on { border-color: #bfdbfe; background: #eff6ff; color: #1d4ed8; }
.dash-mod-ic { @apply tw-w-9 tw-h-9 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-[1.1rem]; background: var(--mb, #f8fafc); color: var(--mc, #64748b); }

@media (max-width: 575.98px) {
  .dash-kpi { grid-template-columns: repeat(2, 1fr); }
  .kpi-val { @apply tw-text-2xl; }
  .dash-greet { @apply tw-text-xl; }
}
</style>

<div class="dash">

  <!-- ── Nagłówek ─────────────────────────────────────────────────────────── -->
  <header class="dash-hero">
    <div>
      <h1 class="dash-greet"><?= h($greeting) ?>, <?= h($first_name) ?> 👋</h1>
      <div class="dash-sub"><?= h($date_pl_long) ?><?= $org_name ? ' · ' . h($org_name) : '' ?></div>
    </div>
    <?php if (can_edit()): ?>
    <div class="dash-actions">
      <?php if ($new_types): ?>
      <div class="dropdown">
        <button type="button" class="dash-btn dash-btn-primary" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-plus-lg"></i>Nowa umowa <i class="bi bi-chevron-down" style="font-size:.6rem;opacity:.7"></i>
        </button>
        <ul class="dropdown-menu shadow" style="min-width:220px;font-size:.84rem;border-radius:12px">
          <li><h6 class="dropdown-header" style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em">Typ umowy</h6></li>
          <?php foreach ($new_types as $slug => $label): ?>
          <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/contracts/<?= h($slug) ?>/add.php"><i class="bi <?= h($ct_icons[$slug] ?? 'bi-file-text') ?> me-2 text-muted"></i><?= h($label) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <button type="button" class="dash-btn" data-cmdk-open><i class="bi bi-search"></i>Szukaj <kbd>Ctrl K</kbd></button>
      <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="dash-btn"><i class="bi bi-calendar-x" style="color:#d97706"></i>Monitoring wygasania</a>
    </div>
    <?php endif; ?>
  </header>

  <!-- ── KPI ──────────────────────────────────────────────────────────────── -->
  <section class="dash-kpi" aria-label="Wskaźniki">

    <a href="<?= APP_URL ?>/contracts/rejestr.php" class="kpi-card" style="--kpi-accent:#2563eb">
      <div class="kpi-top"><div class="kpi-icon" style="background:#eff6ff;color:#2563eb"><i class="bi bi-file-earmark-text-fill"></i></div></div>
      <div class="kpi-val"><?= $kpi['total'] ?></div>
      <div class="kpi-lbl">Wszystkich umów</div>
      <div class="kpi-sub">+<?= $kpi['new_month'] ?> w tym miesiącu</div>
    </a>

    <div class="kpi-card" style="--kpi-accent:#16a34a">
      <div class="kpi-top"><div class="kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="bi bi-check-circle-fill"></i></div></div>
      <div class="kpi-val"><?= $kpi['active'] ?></div>
      <div class="kpi-lbl">Aktywnych</div>
      <div class="kpi-sub"><?= $kpi['total'] > 0 ? round($kpi['active'] / $kpi['total'] * 100) : 0 ?>% wszystkich</div>
    </div>

    <?php if ($kpi['expiring_7'] > 0): ?>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--kpi-accent:#dc2626">
      <div class="kpi-top"><div class="kpi-icon" style="background:#fef2f2;color:#dc2626"><i class="bi bi-calendar-x-fill"></i></div></div>
      <div class="kpi-val" style="color:#dc2626"><?= $kpi['expiring_7'] ?></div>
      <div class="kpi-lbl">Wygasa w 7 dni</div>
      <div class="kpi-sub"><?= $kpi['expiring_30'] ?> w ciągu 30 dni</div>
    </a>
    <?php elseif ($kpi['expiring_30'] > 0): ?>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--kpi-accent:#f59e0b">
      <div class="kpi-top"><div class="kpi-icon" style="background:#fffbeb;color:#d97706"><i class="bi bi-calendar-event-fill"></i></div></div>
      <div class="kpi-val" style="color:#d97706"><?= $kpi['expiring_30'] ?></div>
      <div class="kpi-lbl">Wygasa w 30 dni</div>
      <div class="kpi-sub">Sprawdź monitoring</div>
    </a>
    <?php else: ?>
    <div class="kpi-card" style="--kpi-accent:#16a34a">
      <div class="kpi-top"><div class="kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="bi bi-calendar-check-fill"></i></div></div>
      <div class="kpi-val">0</div>
      <div class="kpi-lbl">Wygasających</div>
      <div class="kpi-sub">Brak w ciągu 30 dni</div>
    </div>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/admin/approvals.php" class="kpi-card" style="--kpi-accent:#7c3aed">
      <div class="kpi-top"><div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="bi bi-hourglass-split"></i></div></div>
      <div class="kpi-val" style="color:<?= $kpi['pending_approval'] ? '#7c3aed' : '#0f172a' ?>"><?= $kpi['pending_approval'] ?></div>
      <div class="kpi-lbl">Do akceptacji</div>
      <div class="kpi-sub"><?= $kpi['pending_approval'] ? 'Oczekuje na decyzję' : 'Brak oczekujących' ?></div>
    </a>

    <?php if (module_enabled('tasks_enabled')): ?>
    <a href="<?= APP_URL ?>/tasks/index.php" class="kpi-card" style="--kpi-accent:#0ea5e9">
      <div class="kpi-top"><div class="kpi-icon" style="background:#f0f9ff;color:#0ea5e9"><i class="bi bi-kanban-fill"></i></div></div>
      <div class="kpi-val"><?= $kpi['tasks_mine'] ?></div>
      <div class="kpi-lbl">Moich zadań</div>
      <div class="kpi-sub"><?= $tasks_overdue ? '<span style="color:#dc2626;font-weight:700">' . $tasks_overdue . ' po terminie</span> · ' : '' ?><?= $kpi['tasks_open'] ?> otwartych łącznie</div>
    </a>
    <?php endif; ?>

  </section>

  <!-- ── Siatka: treść + panel boczny ─────────────────────────────────────── -->
  <div class="dash-grid">

    <div class="dash-main">

      <!-- Wygasają wkrótce -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-calendar-x" style="color:#d97706"></i>Wygasają wkrótce</h2>
          <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="dash-link">Wszystkie <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($expiring): foreach ($expiring as $r):
          $days = (int)round((strtotime($r['end_date']) - strtotime($today)) / 86400);
          $dc = $days <= 7 ? '#dc2626' : ($days <= 14 ? '#d97706' : '#0284c7');
          $db = $days <= 7 ? '#fef2f2' : ($days <= 14 ? '#fffbeb' : '#f0f9ff');
        ?>
        <a href="<?= contract_url($r['type'], (int)$r['id']) ?>" class="dash-row">
          <span class="dash-pill" style="background:<?= $db ?>;color:<?= $dc ?>"><?= $days ?> dni</span>
          <div class="dash-row-main">
            <div class="dash-row-title"><?= h($r['imie_nazwisko'] ?: ($r['numer_umowy'] ?: '—')) ?></div>
            <div class="dash-row-sub"><?= h($r['type_label']) ?><?= $r['numer_umowy'] ? ' · ' . h($r['numer_umowy']) : '' ?></div>
          </div>
          <div class="dash-row-end"><?= date_pl($r['end_date']) ?></div>
        </a>
        <?php endforeach; else: ?>
        <div class="dash-empty"><i class="bi bi-check-circle"></i>Brak umów wygasających w ciągu 30 dni</div>
        <?php endif; ?>
      </section>

      <!-- Do akceptacji -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-hourglass-split" style="color:#7c3aed"></i>Do akceptacji
            <?php if ($kpi['pending_approval']): ?><span class="badge" style="background:#7c3aed"><?= $kpi['pending_approval'] ?></span><?php endif; ?>
          </h2>
          <a href="<?= APP_URL ?>/admin/approvals.php" class="dash-link">Rozpatrz <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($pending_approvals): foreach ($pending_approvals as $a): ?>
        <a href="<?= APP_URL ?>/admin/approvals.php" class="dash-row">
          <span class="dash-dot" style="background:#7c3aed"></span>
          <div class="dash-row-main">
            <div class="dash-row-title"><?= h($a['numer_umowy'] ?: ('#' . (int)$a['contract_id'])) ?></div>
            <div class="dash-row-sub"><?= h($a['requester_name'] ?? '') ?><?= !empty($a['contract_type']) ? ' · ' . h(CONTRACT_TYPES[$a['contract_type']] ?? $a['contract_type']) : '' ?></div>
          </div>
          <div class="dash-row-end"><?= date_pl($a['created_at']) ?></div>
        </a>
        <?php endforeach; else: ?>
        <div class="dash-empty"><i class="bi bi-check-circle"></i>Brak wniosków do akceptacji</div>
        <?php endif; ?>
      </section>

      <!-- Moje zadania -->
      <?php if (module_enabled('tasks_enabled')): ?>
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-kanban" style="color:#0ea5e9"></i>Moje zadania</h2>
          <a href="<?= APP_URL ?>/tasks/index.php" class="dash-link"><i class="bi bi-grid-3x3-gap"></i> Tablica</a>
        </div>
        <?php if ($my_tasks):
          $prio_colors = ['high'=>'#ef4444','medium'=>'#f59e0b','low'=>'#94a3b8','critical'=>'#7c3aed'];
          foreach ($my_tasks as $t):
            $pc = $prio_colors[$t['priority'] ?? 'low'] ?? '#94a3b8';
            $overdue = $t['due_date'] && $t['due_date'] < $today;
        ?>
        <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>" class="dash-row">
          <span class="dash-dot" style="background:<?= $pc ?>"></span>
          <div class="dash-row-main">
            <div class="dash-row-title"><?= h($t['title']) ?></div>
            <div class="dash-row-sub"><?= h($t['ws_name'] ?? '') ?><?= ($t['ws_name'] && $t['list_name']) ? ' › ' : '' ?><?= h($t['list_name'] ?? '') ?></div>
          </div>
          <?php if ($t['due_date']): ?>
          <div class="dash-row-end" style="<?= $overdue ? 'color:#dc2626;font-weight:700' : '' ?>">
            <?= $overdue ? '<i class="bi bi-exclamation-circle-fill"></i> ' : '<i class="bi bi-calendar2"></i> ' ?><?= date_pl($t['due_date']) ?>
          </div>
          <?php endif; ?>
        </a>
        <?php endforeach; else: ?>
        <div class="dash-empty"><i class="bi bi-check2-circle"></i>Brak przypisanych zadań</div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- Wiadomości -->
      <?php if (can_edit()): $total_unread = array_sum(array_column($dash_threads, 'unread')); ?>
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-chat-dots" style="color:#2563eb"></i>Wiadomości
            <?php if ($total_unread): ?><span class="badge bg-danger"><?= $total_unread ?></span><?php endif; ?>
          </h2>
          <a href="<?= APP_URL ?>/admin/messages.php" class="dash-link">Wszystkie <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($dash_threads): foreach ($dash_threads as $_dt):
          $unread   = (int)$_dt['unread'];
          $initials = mb_strtoupper(mb_substr($_dt['_label'] ?? '?', 0, 2));
          $preview  = mb_substr(strip_tags($_dt['last_body'] ?? ''), 0, 70);
          $sender   = $_dt['last_sender'] ? explode(' ', $_dt['last_sender'])[0] . ': ' : '';
        ?>
        <button type="button" class="dash-row" style="<?= $unread ? 'background:#eff6ff' : '' ?>"
          onclick="window.MsgWidget && window.MsgWidget.openThread('<?= h($_dt['context_type']) ?>',<?= (int)$_dt['context_id'] ?>,'<?= h($_dt['contract_type'] ?? '') ?>','<?= h($_dt['_label'] ?? '') ?>','<?= h($_dt['_url'] ?? '#') ?>')">
          <span class="dash-avatar" style="<?= $unread ? 'background:#dbeafe;color:#1d4ed8' : 'background:#f1f5f9;color:#64748b' ?>"><?= h($initials) ?></span>
          <div class="dash-row-main">
            <div class="dash-row-title" style="<?= $unread ? 'color:#1d4ed8' : '' ?>"><?= h($_dt['_label'] ?? '—') ?><?= $_dt['_sub'] ? ' <span style="font-weight:400;color:#94a3b8">· ' . h($_dt['_sub']) . '</span>' : '' ?></div>
            <div class="dash-row-sub"><?= h($sender . $preview) ?></div>
          </div>
          <div class="dash-row-end">
            <?= date_pl($_dt['last_at']) ?>
            <?php if ($unread): ?><div><span class="badge bg-danger" style="font-size:.6rem"><?= $unread ?></span></div><?php endif; ?>
          </div>
        </button>
        <?php endforeach; else: ?>
        <div class="dash-empty"><i class="bi bi-chat" style="color:#94a3b8"></i>Brak wiadomości</div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

    </div><!-- /.dash-main -->

    <aside class="dash-side">

      <!-- Wymaga uwagi -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-lightning-charge-fill" style="color:#d97706"></i>Wymaga uwagi
            <?php if ($attn_total): ?><span class="badge" style="background:#d97706"><?= $attn_total ?></span><?php endif; ?>
          </h2>
        </div>
        <?php if ($attn): ?>
        <div class="attn-list">
          <?php foreach ($attn as $a): [$fg, $bg, $bd] = $tone_css[$a['tone']] ?? $tone_css['amber']; ?>
          <a href="<?= APP_URL . h($a['url']) ?>" class="attn-row" style="--fg:<?= $fg ?>;--bg:<?= $bg ?>;--bd:<?= $bd ?>">
            <span class="attn-ic"><i class="bi <?= h($a['icon']) ?>"></i></span>
            <span class="attn-lbl"><?= h($a['label']) ?></span>
            <span class="attn-n"><?= (int)$a['n'] ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="attn-ok">
          <span class="attn-ok-ic"><i class="bi bi-check-lg"></i></span>
          <div>
            <div class="tw-text-[.84rem] tw-font-semibold tw-text-slate-900">Nic nie czeka na Twoją decyzję</div>
            <div class="tw-text-[.74rem] tw-text-slate-400">Wnioski, zgłoszenia i terminy są pod kontrolą.</div>
          </div>
        </div>
        <?php endif; ?>
      </section>

      <!-- Komunikaty -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-megaphone-fill" style="color:#2563eb"></i>Komunikaty
            <?php if ($ann_unread): ?><span class="badge bg-danger"><?= $ann_unread ?></span><?php endif; ?>
          </h2>
          <a href="<?= APP_URL ?>/komunikaty/index.php" class="dash-link">Wszystkie <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($ann_list): foreach ($ann_list as $an): $un = empty($an['is_read_by_me']); ?>
        <a href="<?= APP_URL ?>/komunikaty/index.php#ann-<?= (int)$an['id'] ?>" class="ann-row<?= $un ? ' unread' : '' ?>">
          <div class="ann-title">
            <?php if (!empty($an['is_pinned'])): ?><i class="bi bi-pin-angle-fill" style="color:#d97706;font-size:.75rem" title="Przypięte"></i><?php endif; ?>
            <span class="tw-truncate"><?= h($an['title']) ?></span>
            <?php if ($un): ?><span class="dash-dot" style="background:#2563eb"></span><?php endif; ?>
          </div>
          <div class="ann-meta"><?= h($an['author_name'] ?? '') ?><?= !empty($an['author_name']) ? ' · ' : '' ?><?= date_pl(substr((string)$an['created_at'], 0, 10)) ?></div>
        </a>
        <?php endforeach; else: ?>
        <div class="dash-empty"><i class="bi bi-megaphone" style="color:#94a3b8"></i>Brak komunikatów</div>
        <?php endif; ?>
      </section>

      <!-- Ostatnie umowy -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-clock-history" style="color:#64748b"></i>Ostatnio dodane umowy</h2>
          <a href="<?= APP_URL ?>/contracts/rejestr.php" class="dash-link">Rejestr <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($recent): foreach ($recent as $r):
          $st = STATUS_LABELS[$r['status']] ?? ['class' => 'secondary', 'label' => $r['status']];
        ?>
        <a href="<?= contract_url($r['type'], (int)$r['id']) ?>" class="dash-row">
          <span class="kpi-icon" style="width:30px;height:30px;font-size:.85rem;background:#eff6ff;color:#2563eb"><i class="bi <?= h($ct_icons[$r['type']] ?? 'bi-file-text') ?>"></i></span>
          <div class="dash-row-main">
            <div class="dash-row-title"><?= h($r['imie_nazwisko'] ?: ($r['numer_umowy'] ?: '—')) ?></div>
            <div class="dash-row-sub"><?= h($r['type_label']) ?><?= $r['numer_umowy'] ? ' · ' . h($r['numer_umowy']) : '' ?></div>
          </div>
          <span class="badge bg-<?= h($st['class']) ?>" style="font-size:.6rem"><?= h($st['label']) ?></span>
        </a>
        <?php endforeach; else: ?>
        <div class="dash-empty"><i class="bi bi-file-earmark" style="color:#94a3b8"></i>Brak umów</div>
        <?php endif; ?>
      </section>

      <!-- Moduły -->
      <?php if (!empty($_sw_items)): ?>
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-grid-3x3-gap-fill" style="color:#64748b"></i>Moduły</h2>
          <a href="<?= APP_URL ?>/portal.php" class="dash-link">Portal <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="dash-mods">
          <?php foreach ($_sw_items as $m): if (($m['label'] ?? '') === 'SZO') continue; ?>
          <a href="<?= h($m['url']) ?>" class="dash-mod<?= !empty($m['on']) ? ' on' : '' ?>" style="--mc:<?= h($m['mc']) ?>;--mb:<?= h($m['mb']) ?>">
            <span class="dash-mod-ic"><i class="bi <?= h($m['icon']) ?>"></i></span><?= h($m['label']) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

    </aside><!-- /.dash-side -->

  </div><!-- /.dash-grid -->

</div><!-- /.dash -->

<?php include __DIR__ . '/includes/footer.php'; ?>
