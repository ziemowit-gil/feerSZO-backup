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

// Tony „Wymaga uwagi” — paleta Metro (przyciemniona do WCAG AA z białym tekstem)
$tone_css = [
    'red'    => ['#a20025', '#f2f2f2', '#e1e1e1'],
    'amber'  => ['#c25100', '#f2f2f2', '#e1e1e1'],
    'violet' => ['#6a00ff', '#f2f2f2', '#e1e1e1'],
    'blue'   => ['#0050ef', '#f2f2f2', '#e1e1e1'],
    'slate'  => ['#5f5f5f', '#f2f2f2', '#e1e1e1'],
];

// Wymóg uzupełnienia profilu w Katalogu (/katalog) — w trybie „require” przekierowuje przed wysłaniem strony
require_once __DIR__ . '/includes/directory.php';
require_once __DIR__ . '/modules/directory_profile/lib/directoryProfile.php';
$dpStatus = directoryProfileEnforce($_user) ?? directoryProfileStatus((int)$_user['id']);

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── Pulpit SZO — szata Metro UI (jak Tożsamość, EZD, CRM): lekki duży tytuł z kickerem,
   wskaźniki jako pełnokolorowe kafle Start, płaskie panele z szarym nagłówkiem,
   ZERO zaokrągleń i cieni, kwadratowe przyciski z ramką 2px, kafle modułów w kolorze modułu.
   Zwykły CSS (bez @apply) — nie czeka na Tailwind CDN. Tokeny --sm-* z includes/szo_metro.php. */
html body{background:#fff!important;background-image:none!important}
.dash{max-width:1440px;margin:0 auto;font-family:var(--sm-font,"Segoe UI",system-ui,sans-serif);color:#1d1d1d}
.dash *{border-radius:0!important}
.dash .rounded-circle{border-radius:50%!important}

/* Nagłówek */
.dash-hero{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;margin:0 0 1.5rem}
.dash-kicker{font-size:.7rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .35rem}
.dash-greet{font-size:2.6rem;font-weight:300;letter-spacing:-.025em;line-height:1.05;color:#1d1d1d;margin:0}
.dash-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem}
.dash-btn{display:inline-flex;align-items:center;gap:.45rem;height:36px;padding:0 .9rem;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;
  background:#fff;color:#1d1d1d;border:2px solid #1d1d1d;transition:background .12s,color .12s}
.dash-btn:hover,.dash-btn:focus-visible,.dash-btn[aria-expanded="true"]{background:#1d1d1d;color:#fff}
.dash-btn:hover .bi,.dash-btn:focus-visible .bi{color:#fff!important}
.dash-btn kbd{font-family:inherit;font-size:.6rem;font-weight:700;padding:.05rem .3rem;background:#f2f2f2;color:#5f5f5f;border:0;margin-left:.15rem}
.dash-btn-primary{background:#0050ef;border-color:#0050ef;color:#fff}
.dash-btn-primary:hover,.dash-btn-primary[aria-expanded="true"]{background:#1d1d1d;border-color:#1d1d1d}
.dash-btn .bi{font-size:.95rem}

/* Kafle wskaźników — żywe kafle Start */
.dash-kpi{display:grid;gap:.5rem;margin:0 0 1.75rem;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
.kpi-card{--k:#6d6d6d;position:relative;display:flex;flex-direction:column;min-height:140px;padding:.9rem 1rem .85rem;background:var(--k);color:#fff;text-decoration:none;transition:filter .12s}
a.kpi-card:hover,a.kpi-card:focus-visible{color:#fff;filter:brightness(1.12);outline:3px solid #1d1d1d;outline-offset:2px}
.kpi-icon{font-size:1.5rem;line-height:1;color:#fff}
.kpi-val{margin-top:auto;font-size:2.8rem;font-weight:300;line-height:1;color:#fff}
.kpi-lbl{font-size:.82rem;font-weight:600;color:#fff;margin-top:.35rem}
.kpi-sub{font-size:.72rem;color:rgba(255,255,255,.85);margin-top:.1rem}
.kpi-sub b{color:#fff}

/* Siatka główna */
.dash-grid{display:grid;gap:1.25rem;align-items:start;grid-template-columns:1fr}
@media (min-width:1280px){.dash-grid{grid-template-columns:minmax(0,2fr) minmax(300px,1fr)}}
.dash-main{display:grid;gap:1.25rem;min-width:0;grid-template-columns:1fr}
@media (min-width:768px){.dash-main{grid-template-columns:1fr 1fr}}
.dash-side{display:grid;gap:1.25rem;min-width:0}

/* Panele — płaskie, nagłówek jak tytuł sekcji Metro */
.dash-panel{background:#fff;min-width:0;border-top:2px solid #1d1d1d}
.dash-panel-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.6rem 0 .55rem;border-bottom:1px solid #e1e1e1}
.dash-panel-title{display:flex;align-items:center;gap:.55rem;font-size:1.2rem;font-weight:300;letter-spacing:-.01em;color:#1d1d1d;margin:0}
.dash-panel-title .bi{font-size:1rem}
.dash-panel-title .badge{font-size:.62rem;font-weight:700}
.dash-link{display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:600;text-decoration:none;padding:.25rem .5rem;color:#003bb0}
.dash-link:hover{background:#0050ef;color:#fff}
.dash-empty{text-align:left;font-size:.84rem;color:#5f5f5f;padding:1.1rem 0}
.dash-empty .bi{color:#007a00;margin-right:.35rem}

/* Wiersze list */
.dash-row{display:flex;align-items:center;gap:.75rem;width:100%;text-align:left;padding:.6rem .5rem;text-decoration:none;color:inherit;
  background:none;border:0;border-bottom:1px solid #e1e1e1;transition:background .1s}
.dash-row:last-child{border-bottom:0}
a.dash-row:hover,button.dash-row:hover{background:#f2f2f2;color:inherit}
.dash-row.is-unread{background:#e6eefd}
.dash-row-main{flex:1;min-width:0}
.dash-row-title{font-size:.88rem;font-weight:600;color:#1d1d1d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dash-row-sub{font-size:.75rem;color:#5f5f5f;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dash-row-end{font-size:.75rem;color:#5f5f5f;white-space:nowrap;text-align:right;flex-shrink:0}
.dash-pill{display:inline-flex;align-items:center;justify-content:center;min-width:3.2rem;padding:.2rem .4rem;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0}
.dash-dot{width:8px;height:8px;flex-shrink:0}
.dash-avatar{width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;flex-shrink:0;background:#e6e6e6;color:#1d1d1d}
.dash-row.is-unread .dash-avatar{background:#0050ef;color:#fff}
.dash-ic{width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;background:#0050ef;color:#fff}
.dash-panel .badge{font-weight:700;letter-spacing:.03em}

/* Wymaga uwagi — wiersze z kwadratową ikoną w kolorze tonu */
.attn-list{display:grid;gap:0}
.attn-row{display:flex;align-items:center;gap:.75rem;padding:.5rem;text-decoration:none;color:#1d1d1d;border-bottom:1px solid #e1e1e1}
.attn-row:last-child{border-bottom:0}
.attn-row:hover{background:#f2f2f2;color:#1d1d1d}
.attn-ic{width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;background:var(--fg);color:#fff}
.attn-lbl{flex:1;min-width:0;font-size:.86rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.attn-n{font-size:1.4rem;font-weight:300;line-height:1;color:var(--fg);flex-shrink:0;min-width:1.6rem;text-align:right}
.attn-ok{display:flex;align-items:center;gap:.75rem;padding:1rem 0}
.attn-ok-ic{width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;background:#007a00;color:#fff}
.attn-ok-t{font-size:.9rem;font-weight:600;color:#1d1d1d}
.attn-ok-s{font-size:.76rem;color:#5f5f5f}

/* Komunikaty */
.ann-row{display:block;padding:.6rem .5rem;text-decoration:none;color:inherit;border-bottom:1px solid #e1e1e1}
.ann-row:last-child{border-bottom:0}
.ann-row:hover{background:#f2f2f2;color:inherit}
.ann-row.unread{background:#e6eefd;box-shadow:inset 4px 0 0 #0050ef}
.ann-title{display:flex;align-items:center;gap:.4rem;font-size:.86rem;font-weight:600;color:#1d1d1d;min-width:0}
.ann-title span:not(.dash-dot){overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ann-meta{font-size:.73rem;color:#5f5f5f;margin-top:.1rem}

/* Moduły — kafle w kolorze modułu */
.dash-mods{display:grid;gap:.35rem;padding:.75rem 0 0;grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}
.dash-mod{display:flex;flex-direction:column;justify-content:space-between;gap:.6rem;min-height:84px;padding:.6rem .65rem;text-decoration:none;
  background:var(--mc,#5f5f5f);background:color-mix(in srgb,var(--mc,#5f5f5f) 80%,#000);color:#fff;font-size:.74rem;font-weight:600;line-height:1.15;transition:filter .12s}
.dash-mod:hover,.dash-mod:focus-visible{color:#fff;filter:brightness(1.12);outline:3px solid #1d1d1d;outline-offset:2px}
.dash-mod.on{box-shadow:inset 0 -4px 0 rgba(0,0,0,.35)}
.dash-mod-ic{font-size:1.25rem;line-height:1;color:#fff}

@media (max-width:575.98px){
  .dash-kpi{grid-template-columns:repeat(2,1fr)}
  .kpi-val{font-size:2.2rem}
  .dash-greet{font-size:1.9rem}
}
@media (prefers-reduced-motion:reduce){.dash *{transition:none!important}}
</style>

<div class="dash">

  <!-- ── Nagłówek ─────────────────────────────────────────────────────────── -->
  <header class="dash-hero">
    <div>
      <div class="dash-kicker"><?= h($date_pl_long) ?><?= $org_name ? ' · ' . h($org_name) : '' ?></div>
      <h1 class="dash-greet"><?= h($greeting) ?>, <?= h($first_name) ?></h1>
    </div>
    <div class="dash-actions">
      <a href="<?= APP_URL ?>/directory/profile_edit.php" class="dash-btn"><i class="bi bi-person-lines-fill" style="color:<?= $dpStatus['complete'] ? '#00807e' : '#c25100' ?>"></i>Profil w katalogu<?php if ($dpStatus['total']): ?> <kbd><?= (int)$dpStatus['pct'] ?>%</kbd><?php endif; ?></a>
    <?php if (can_edit()): ?>
      <?php if ($new_types): ?>
      <div class="dropdown">
        <button type="button" class="dash-btn dash-btn-primary" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-plus-lg"></i>Nowa umowa <i class="bi bi-chevron-down" style="font-size:.6rem;opacity:.7"></i>
        </button>
        <ul class="dropdown-menu shadow" style="min-width:220px;font-size:.84rem">
          <li><h6 class="dropdown-header" style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em">Typ umowy</h6></li>
          <?php foreach ($new_types as $slug => $label): ?>
          <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/contracts/<?= h($slug) ?>/add.php"><i class="bi <?= h($ct_icons[$slug] ?? 'bi-file-text') ?> me-2 text-muted"></i><?= h($label) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <button type="button" class="dash-btn" data-cmdk-open><i class="bi bi-search"></i>Szukaj <kbd>Ctrl K</kbd></button>
      <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="dash-btn"><i class="bi bi-calendar-x" style="color:#d97706"></i>Monitoring wygasania</a>
    <?php endif; ?>
    </div>
  </header>

  <?php include __DIR__ . '/modules/directory_profile/view/pulpitNotice.php'; ?>

  <!-- ── KPI ──────────────────────────────────────────────────────────────── -->
  <section class="dash-kpi" aria-label="Wskaźniki">

    <a href="<?= APP_URL ?>/contracts/rejestr.php" class="kpi-card" style="--k:#0050ef">
      <i class="kpi-icon bi bi-file-earmark-text-fill" aria-hidden="true"></i>
      <div class="kpi-val"><?= $kpi['total'] ?></div>
      <div class="kpi-lbl">Wszystkich umów</div>
      <div class="kpi-sub">+<?= $kpi['new_month'] ?> w tym miesiącu</div>
    </a>

    <a href="<?= APP_URL ?>/contracts/rejestr.php" class="kpi-card" style="--k:#007a00">
      <i class="kpi-icon bi bi-check-circle-fill" aria-hidden="true"></i>
      <div class="kpi-val"><?= $kpi['active'] ?></div>
      <div class="kpi-lbl">Aktywnych</div>
      <div class="kpi-sub"><?= $kpi['total'] > 0 ? round($kpi['active'] / $kpi['total'] * 100) : 0 ?>% wszystkich</div>
    </a>

    <?php if ($kpi['expiring_7'] > 0): ?>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--k:#a20025">
      <i class="kpi-icon bi bi-calendar-x-fill" aria-hidden="true"></i>
      <div class="kpi-val"><?= $kpi['expiring_7'] ?></div>
      <div class="kpi-lbl">Wygasa w 7 dni</div>
      <div class="kpi-sub"><?= $kpi['expiring_30'] ?> w ciągu 30 dni</div>
    </a>
    <?php elseif ($kpi['expiring_30'] > 0): ?>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--k:#c25100">
      <i class="kpi-icon bi bi-calendar-event-fill" aria-hidden="true"></i>
      <div class="kpi-val"><?= $kpi['expiring_30'] ?></div>
      <div class="kpi-lbl">Wygasa w 30 dni</div>
      <div class="kpi-sub">Sprawdź monitoring</div>
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--k:#6d6d6d">
      <i class="kpi-icon bi bi-calendar-check-fill" aria-hidden="true"></i>
      <div class="kpi-val">0</div>
      <div class="kpi-lbl">Wygasających</div>
      <div class="kpi-sub">Brak w ciągu 30 dni</div>
    </a>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/admin/approvals.php" class="kpi-card" style="--k:<?= $kpi['pending_approval'] ? '#6a00ff' : '#6d6d6d' ?>">
      <i class="kpi-icon bi bi-hourglass-split" aria-hidden="true"></i>
      <div class="kpi-val"><?= $kpi['pending_approval'] ?></div>
      <div class="kpi-lbl">Do akceptacji</div>
      <div class="kpi-sub"><?= $kpi['pending_approval'] ? 'Oczekuje na decyzję' : 'Brak oczekujących' ?></div>
    </a>

    <?php if (module_enabled('tasks_enabled')): ?>
    <a href="<?= APP_URL ?>/tasks/index.php" class="kpi-card" style="--k:<?= $tasks_overdue ? '#a20025' : '#00807e' ?>">
      <i class="kpi-icon bi bi-kanban-fill" aria-hidden="true"></i>
      <div class="kpi-val"><?= $kpi['tasks_mine'] ?></div>
      <div class="kpi-lbl">Moich zadań</div>
      <div class="kpi-sub"><?= $tasks_overdue ? '<b>' . $tasks_overdue . ' po terminie</b> · ' : '' ?><?= $kpi['tasks_open'] ?> otwartych łącznie</div>
    </a>
    <?php endif; ?>

  </section>

  <!-- ── Siatka: treść + panel boczny ─────────────────────────────────────── -->
  <div class="dash-grid">

    <div class="dash-main">

      <!-- Wygasają wkrótce -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-calendar-x" style="color:#c25100"></i>Wygasają wkrótce</h2>
          <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="dash-link">Wszystkie <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($expiring): foreach ($expiring as $r):
          $days = (int)round((strtotime($r['end_date']) - strtotime($today)) / 86400);
          $dc = $days <= 7 ? '#a20025' : ($days <= 14 ? '#c25100' : '#0050ef');
        ?>
        <a href="<?= contract_url($r['type'], (int)$r['id']) ?>" class="dash-row">
          <span class="dash-pill" style="background:<?= $dc ?>"><?= $days ?> dni</span>
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
          <h2 class="dash-panel-title"><i class="bi bi-hourglass-split" style="color:#6a00ff"></i>Do akceptacji
            <?php if ($kpi['pending_approval']): ?><span class="badge" style="background:#6a00ff"><?= $kpi['pending_approval'] ?></span><?php endif; ?>
          </h2>
          <a href="<?= APP_URL ?>/admin/approvals.php" class="dash-link">Rozpatrz <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($pending_approvals): foreach ($pending_approvals as $a): ?>
        <a href="<?= APP_URL ?>/admin/approvals.php" class="dash-row">
          <span class="dash-dot" style="background:#6a00ff"></span>
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
          <h2 class="dash-panel-title"><i class="bi bi-kanban" style="color:#00807e"></i>Moje zadania</h2>
          <a href="<?= APP_URL ?>/tasks/index.php" class="dash-link"><i class="bi bi-grid-3x3-gap"></i> Tablica</a>
        </div>
        <?php if ($my_tasks):
          $prio_colors = ['high'=>'#a20025','medium'=>'#c25100','low'=>'#8a8a8a','critical'=>'#6a00ff'];
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
          <div class="dash-row-end" style="<?= $overdue ? 'color:#a20025;font-weight:700' : '' ?>">
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
          <h2 class="dash-panel-title"><i class="bi bi-chat-dots" style="color:#0050ef"></i>Wiadomości
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
        <button type="button" class="dash-row<?= $unread ? ' is-unread' : '' ?>"
          onclick="window.MsgWidget && window.MsgWidget.openThread('<?= h($_dt['context_type']) ?>',<?= (int)$_dt['context_id'] ?>,'<?= h($_dt['contract_type'] ?? '') ?>','<?= h($_dt['_label'] ?? '') ?>','<?= h($_dt['_url'] ?? '#') ?>')">
          <span class="dash-avatar"><?= h($initials) ?></span>
          <div class="dash-row-main">
            <div class="dash-row-title"><?= h($_dt['_label'] ?? '—') ?><?= $_dt['_sub'] ? ' <span style="font-weight:400;color:#5f5f5f">· ' . h($_dt['_sub']) . '</span>' : '' ?></div>
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
          <h2 class="dash-panel-title"><i class="bi bi-lightning-charge-fill" style="color:#c25100"></i>Wymaga uwagi
            <?php if ($attn_total): ?><span class="badge" style="background:#c25100"><?= $attn_total ?></span><?php endif; ?>
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
            <div class="attn-ok-t">Nic nie czeka na Twoją decyzję</div>
            <div class="attn-ok-s">Wnioski, zgłoszenia i terminy są pod kontrolą.</div>
          </div>
        </div>
        <?php endif; ?>
      </section>

      <!-- Komunikaty -->
      <section class="dash-panel">
        <div class="dash-panel-head">
          <h2 class="dash-panel-title"><i class="bi bi-megaphone-fill" style="color:#0050ef"></i>Komunikaty
            <?php if ($ann_unread): ?><span class="badge bg-danger"><?= $ann_unread ?></span><?php endif; ?>
          </h2>
          <a href="<?= APP_URL ?>/komunikaty/index.php" class="dash-link">Wszystkie <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($ann_list): foreach ($ann_list as $an): $un = empty($an['is_read_by_me']); ?>
        <a href="<?= APP_URL ?>/komunikaty/index.php#ann-<?= (int)$an['id'] ?>" class="ann-row<?= $un ? ' unread' : '' ?>">
          <div class="ann-title">
            <?php if (!empty($an['is_pinned'])): ?><i class="bi bi-pin-angle-fill" style="color:#c25100;font-size:.75rem" title="Przypięte"></i><?php endif; ?>
            <span><?= h($an['title']) ?></span>
            <?php if ($un): ?><span class="dash-dot" style="background:#0050ef"></span><?php endif; ?>
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
          <h2 class="dash-panel-title"><i class="bi bi-clock-history" style="color:#5f5f5f"></i>Ostatnio dodane umowy</h2>
          <a href="<?= APP_URL ?>/contracts/rejestr.php" class="dash-link">Rejestr <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if ($recent): foreach ($recent as $r):
          $st = STATUS_LABELS[$r['status']] ?? ['class' => 'secondary', 'label' => $r['status']];
        ?>
        <a href="<?= contract_url($r['type'], (int)$r['id']) ?>" class="dash-row">
          <span class="dash-ic"><i class="bi <?= h($ct_icons[$r['type']] ?? 'bi-file-text') ?>"></i></span>
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
          <h2 class="dash-panel-title"><i class="bi bi-grid-3x3-gap-fill" style="color:#5f5f5f"></i>Moduły</h2>
          <a href="<?= APP_URL ?>/portal.php" class="dash-link">Portal <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="dash-mods">
          <?php foreach ($_sw_items as $m): if (($m['label'] ?? '') === 'SZO') continue; ?>
          <a href="<?= h($m['url']) ?>" class="dash-mod<?= !empty($m['on']) ? ' on' : '' ?>" style="--mc:<?= h($m['mc']) ?>">
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
