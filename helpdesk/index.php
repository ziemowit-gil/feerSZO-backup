<?php
/**
 * helpdesk/index.php — Panel Helpdesk IT
 *
 * Redesign: layout 3-kolumnowy à la Zendesk/Intercom:
 *   · Lewy sidebar  — nawigacja po widokach ze statystykami
 *   · Środkowa kolumna — lista zgłoszeń (karty AJAX, wyszukiwanie)
 *   · Prawa kolumna  — panel szczegółów (AJAX fragment, _detail.php pane mode)
 *
 * Konsola definiuje: QUILL_TOOLBAR, hdInitQuill(), hdBindFullscreen(), hdFsClose().
 * Po załadowaniu panelu wywoływane jest hdBindPane(root, ticketId).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u     = current_user();
$uid   = (int)$u['id'];
$is_op = hd_is_operator();

// ── Filtry ────────────────────────────────────────────────────────────────────
$f_q        = trim($_GET['q'] ?? '');
$f_status   = $_GET['status'] ?? '';
$f_category = $_GET['category'] ?? '';
$f_priority = $_GET['priority'] ?? '';
$f_view     = ($is_op && in_array($_GET['view'] ?? '', ['all','unassigned','mine'], true))
            ? $_GET['view'] : ($is_op ? 'all' : 'mine');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 30;

$where = ['1=1']; $params = [];
if (!$is_op || $f_view === 'mine') {
    $where[] = 't.requester_id = ?'; $params[] = $uid;
} elseif ($f_view === 'unassigned') {
    $where[] = 't.assigned_to IS NULL';
    $where[] = "t.status NOT IN ('zamknięte')";
}
if ($f_status && isset(HD_STATUSES[$f_status]))       { $where[] = 't.status = ?';   $params[] = $f_status; }
if ($f_category && isset(HD_CATEGORIES[$f_category])) { $where[] = 't.category = ?'; $params[] = $f_category; }
if ($f_priority && isset(HD_PRIORITIES[$f_priority])) { $where[] = 't.priority = ?'; $params[] = $f_priority; }
if ($f_q !== '') {
    $where[] = '(t.number LIKE ? OR t.title LIKE ? OR t.requester_name LIKE ?)';
    $like = '%'.$f_q.'%'; $params[] = $like; $params[] = $like; $params[] = $like;
}
$where_sql = implode(' AND ', $where);

$total_all = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets t WHERE {$where_sql}", $params)['c'] ?? 0);
$offset    = ($page - 1) * $per_page;
$tickets   = db_all(
    "SELECT t.*, u.name AS assigned_name
     FROM helpdesk_tickets t LEFT JOIN users u ON u.id = t.assigned_to
     WHERE {$where_sql}
     ORDER BY
       CASE t.status WHEN 'nowe' THEN 0 WHEN 'krytyczne' THEN 1 WHEN 'otwarte' THEN 2 WHEN 'oczekuje' THEN 3 ELSE 4 END,
       t.updated_at DESC
     LIMIT {$per_page} OFFSET {$offset}", $params);

$paging     = paginate($total_all, $per_page, $page,
    APP_URL.'/helpdesk/index.php?'.http_build_query(array_filter([
        'q'=>$f_q,'status'=>$f_status,'category'=>$f_category,'priority'=>$f_priority,'view'=>$f_view,
    ])));
$unread_ids = $is_op ? hd_unread_ids($uid) : [];
$unread_set = array_flip($unread_ids);
$cnt_unread = count($unread_ids);

// ── Statystyki + liczniki sidebara ────────────────────────────────────────────
$stats = [];
try {
    $stat_rows = db_all("SELECT status, COUNT(*) AS c FROM helpdesk_tickets " .
        ($is_op ? '' : "WHERE requester_id={$uid}") . " GROUP BY status", []);
    foreach ($stat_rows as $s) $stats[$s['status']] = (int)$s['c'];
} catch (\Throwable $e) {}
$cnt_total      = array_sum($stats);
$cnt_unassigned = $is_op ? (int)(db_one(
    "SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE assigned_to IS NULL AND status NOT IN ('zamknięte')", []
)['c'] ?? 0) : 0;
$cnt_mine       = $is_op ? (int)(db_one(
    "SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE assigned_to=? AND status NOT IN ('zamknięte','rozwiązane')", [$uid]
)['c'] ?? 0) : 0;

// ── Renderer listy (kart AJAX) ────────────────────────────────────────────────
function _hd_list_html(
    array $tickets, int $total, array $paging, int $per_page, bool $is_op, array $unread_set
): string {
    ob_start();
    if ($tickets):
        foreach ($tickets as $t):
            $unread  = isset($unread_set[(int)$t['id']]);
            $age     = max(0, time() - strtotime($t['updated_at'] ?? 'now'));
            $ago     = $age < 3600 ? max(1,(int)($age/60)).'min'
                      : ($age < 86400 ? (int)($age/3600).'godz' : (int)($age/86400).'dni');
            $sla_ind = $is_op ? hd_sla_indicator($t) : '';
            $has_sla = $sla_ind !== '' && $sla_ind !== '<span class="text-muted">—</span>';
            $crit    = ($t['priority'] === 'krytyczny');
            ?>
<div class="hd-card<?= $unread ? ' hd-card-unread' : '' ?><?= ($t['status']==='krytyczne') ? ' hd-card-critical' : '' ?>"
     role="button" tabindex="0"
     data-id="<?= (int)$t['id'] ?>"
     data-href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$t['id'] ?>"
     aria-label="Zgłoszenie <?= h($t['number']) ?>: <?= h($t['title']) ?>">
  <div class="hd-card-indicator"<?= $crit ? ' style="background:#ef4444"' : '' ?>></div>
  <div class="hd-card-body">
    <div class="hd-card-row1">
      <code class="hd-card-num"><?= h($t['number']) ?></code>
      <span class="hd-card-age"><?= $ago ?> temu</span>
    </div>
    <div class="hd-card-title"><?= h($t['title']) ?></div>
    <div class="hd-card-meta">
      <?= hd_status_badge($t['status']) ?>
      <?php if ($t['priority'] !== 'normalny'): ?><?= hd_priority_badge($t['priority']) ?><?php endif; ?>
      <?php if ($has_sla): ?><?= $sla_ind ?><?php endif; ?>
      <?php if ($is_op && $t['assigned_name']): ?>
        <span class="hd-card-person"><i class="bi bi-person-check"></i><?= h($t['assigned_name']) ?></span>
      <?php elseif ($is_op): ?>
        <span class="hd-card-person text-danger"><i class="bi bi-exclamation-circle"></i>Brak</span>
      <?php else: ?>
        <span class="hd-card-person"><?= h($t['requester_name']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($is_op): ?>
  <label class="hd-card-chk" onclick="event.stopPropagation()" title="Zaznacz">
    <input type="checkbox" class="form-check-input hd-row-chk" value="<?= (int)$t['id'] ?>"
           onchange="HdBulk.update()" aria-label="Zaznacz <?= h($t['number']) ?>">
  </label>
  <?php endif; ?>
</div>
        <?php endforeach;
        if ($paging['pages'] > 1): ?>
<div class="hd-list-pager d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2">
  <span class="text-muted" style="font-size:.73rem">
    <?= (($paging['page']-1)*$per_page)+1 ?>–<?= min($paging['page']*$per_page,$total) ?> / <?= $total ?>
  </span>
  <nav aria-label="Strony"><?= pagination_html($paging) ?></nav>
</div>
        <?php endif;
    else: ?>
<div class="hd-list-empty">
  <i class="bi bi-inbox"></i>
  <p>Brak zgłoszeń</p>
</div>
    <?php endif;
    return ob_get_clean();
}

// ── AJAX endpoint ─────────────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'           => true,
        'total'        => $total_all,
        'list_html'    => _hd_list_html($tickets, $total_all, $paging, $per_page, $is_op, $unread_set),
        'unread_count' => $cnt_unread,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Pełna strona ──────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Helpdesk IT';
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();

$initial_ticket = (int)($_GET['ticket'] ?? 0);
$op_list        = $is_op ? db_all(
    "SELECT id, name FROM users WHERE (helpdesk_operator=1 OR role='admin') AND is_active=1 ORDER BY name", []
) : [];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">

<style>
/* ═══════════════════════════════════════════════════════════
   HELPDESK KONSOLA — 3-kolumnowy layout (Zendesk-style)
   ═══════════════════════════════════════════════════════════ */

/* ── Reset / host ──────────────────────────────────────────── */
body.hd-console-page { overflow: hidden; }

/* ── Główny kontener ──────────────────────────────────────── */
#hdApp {
  display: grid;
  grid-template-columns: 220px 340px 1fr;
  grid-template-rows: 1fr;
  height: calc(100dvh - 62px);
  overflow: hidden;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 2px 12px rgba(15,23,42,.08);
}

@media (max-width: 1180px) {
  #hdApp { grid-template-columns: 180px 300px 1fr; }
}
@media (max-width: 920px) {
  #hdApp { grid-template-columns: 0 1fr 0; }
  #hdSidebar { display: none; }
  #hdPane { display: none; }
  #hdApp.hd-pane-open { grid-template-columns: 0 0 1fr; }
  #hdApp.hd-pane-open #hdListCol { display: none; }
  #hdApp.hd-pane-open #hdPane { display: flex; }
}

/* ── Sidebar ──────────────────────────────────────────────── */
#hdSidebar {
  background: #1e293b;
  color: #94a3b8;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 13px 0 0 13px;
  border-right: 1px solid rgba(255,255,255,.04);
}
.hdSb-head {
  padding: .8rem .75rem .6rem;
  flex-shrink: 0;
}
.hdSb-logo {
  display: flex;
  align-items: center;
  gap: .45rem;
  font-size: .84rem;
  font-weight: 700;
  color: #f1f5f9;
  text-decoration: none;
  margin-bottom: .7rem;
}
.hdSb-logo i { font-size: 1.1rem; color: #60a5fa; }
.hdSb-new {
  display: flex;
  align-items: center;
  gap: .35rem;
  width: 100%;
  padding: .45rem .7rem;
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 7px;
  font-size: .8rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: background .12s;
}
.hdSb-new:hover { background: #1d4ed8; color: #fff; }
.hdSb-nav { flex: 1; overflow-y: auto; padding: .4rem 0; scrollbar-width: none; }
.hdSb-nav::-webkit-scrollbar { display: none; }
.hdSb-section { margin-bottom: .2rem; }
.hdSb-sep {
  padding: .55rem .75rem .2rem;
  font-size: .63rem;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: #475569;
  font-weight: 700;
  user-select: none;
}
.hdSb-item {
  display: flex;
  align-items: center;
  gap: .5rem;
  padding: .38rem .75rem;
  color: #94a3b8;
  text-decoration: none;
  font-size: .8rem;
  cursor: pointer;
  background: none;
  border: none;
  width: 100%;
  text-align: left;
  border-radius: 0;
  transition: background .1s, color .1s;
  line-height: 1.2;
}
.hdSb-item:hover { background: rgba(255,255,255,.06); color: #e2e8f0; }
.hdSb-item.active { background: rgba(96,165,250,.15); color: #93c5fd; font-weight: 600; }
.hdSb-item i { width: 16px; font-size: .95rem; flex-shrink: 0; text-align: center; }
.hdSb-item .hdSb-cnt {
  margin-left: auto;
  background: rgba(255,255,255,.09);
  color: #64748b;
  border-radius: 999px;
  font-size: .66rem;
  font-weight: 700;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.hdSb-item .hdSb-cnt.warn { background: rgba(251,191,36,.15); color: #fbbf24; }
.hdSb-item .hdSb-cnt.danger { background: rgba(239,68,68,.18); color: #fca5a5; }
.hdSb-foot {
  padding: .5rem .75rem .6rem;
  border-top: 1px solid rgba(255,255,255,.05);
  flex-shrink: 0;
}
.hdSb-foot a {
  display: flex;
  align-items: center;
  gap: .45rem;
  padding: .35rem .4rem;
  font-size: .77rem;
  color: #64748b;
  text-decoration: none;
  border-radius: 6px;
}
.hdSb-foot a:hover { background: rgba(255,255,255,.05); color: #94a3b8; }
.hdSb-foot a i { width: 15px; }

/* ── Lista zgłoszeń ───────────────────────────────────────── */
#hdListCol {
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  border-right: 1px solid #e2e8f0;
  overflow: hidden;
}
.hdList-head {
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  padding: .6rem .75rem;
  flex-shrink: 0;
}
.hdList-search-wrap {
  position: relative;
  margin-bottom: .4rem;
}
.hdList-search-wrap i {
  position: absolute;
  left: .55rem;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  pointer-events: none;
  font-size: .85rem;
}
#hdSearchInput {
  width: 100%;
  padding: .38rem .7rem .38rem 1.9rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  font-size: .83rem;
  background: #f1f5f9;
  color: #1e293b;
  transition: all .15s;
}
#hdSearchInput:focus {
  outline: none;
  background: #fff;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96,165,250,.12);
}
.hdList-filters {
  display: flex;
  gap: .3rem;
  overflow-x: auto;
  scrollbar-width: none;
  padding-bottom: 2px;
  align-items: center;
}
.hdList-filters::-webkit-scrollbar { display: none; }
.hdList-pill {
  display: inline-flex;
  align-items: center;
  gap: .22rem;
  padding: .2rem .55rem;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  font-size: .72rem;
  white-space: nowrap;
  cursor: pointer;
  background: #fff;
  color: #475569;
  transition: all .12s;
  flex-shrink: 0;
  user-select: none;
}
.hdList-pill:hover, .hdList-pill.active {
  background: #eff6ff;
  border-color: #bfdbfe;
  color: #1d4ed8;
}
.hdList-count-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .3rem .75rem;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
  flex-shrink: 0;
}
.hdList-count-bar span { font-size: .73rem; color: #64748b; }
#hdListBody {
  flex: 1;
  overflow-y: auto;
}

/* ── Karty zgłoszeń ───────────────────────────────────────── */
.hd-card {
  display: flex;
  align-items: stretch;
  border-bottom: 1px solid #f1f5f9;
  cursor: pointer;
  background: #fff;
  transition: background .08s;
  text-decoration: none;
  color: inherit;
  position: relative;
}
.hd-card:hover { background: #f8fafc; }
.hd-card.active {
  background: #eff6ff;
  box-shadow: inset 3px 0 0 #2563eb;
}
.hd-card-critical { background: #fff5f5; }
.hd-card-critical:hover { background: #fff1f1; }
.hd-card-unread .hd-card-title { font-weight: 700; }
.hd-card-indicator {
  width: 4px;
  flex-shrink: 0;
  background: transparent;
  align-self: stretch;
}
.hd-card-unread .hd-card-indicator { background: #2563eb; }
.hd-card-body {
  flex: 1;
  padding: .6rem .6rem .6rem .55rem;
  min-width: 0;
}
.hd-card-row1 {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: .18rem;
}
.hd-card-num {
  font-size: .67rem;
  color: #94a3b8;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-weight: 600;
  letter-spacing: .01em;
}
.hd-card-age {
  font-size: .69rem;
  color: #94a3b8;
  white-space: nowrap;
  flex-shrink: 0;
}
.hd-card-title {
  font-size: .83rem;
  font-weight: 500;
  color: #1e293b;
  margin-bottom: .3rem;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.3;
}
.hd-card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  align-items: center;
}
.hd-card-meta .badge { font-size: .65rem; }
.hd-card-person {
  margin-left: auto;
  font-size: .7rem;
  color: #64748b;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: .18rem;
}
.hd-card-chk {
  display: flex;
  align-items: center;
  padding: 0 .6rem 0 .3rem;
  cursor: pointer;
  opacity: 0;
  transition: opacity .1s;
}
.hd-card:hover .hd-card-chk,
.hd-row-chk:checked ~ .hd-card-chk,
.hd-card .hd-row-chk:checked { opacity: 1; }
.hd-list-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 1rem;
  color: #94a3b8;
  text-align: center;
}
.hd-list-empty i { font-size: 2.4rem; display: block; margin-bottom: .5rem; }
.hd-list-empty p { font-size: .84rem; margin: 0; }
.hd-list-pager { border-top: 1px solid #f1f5f9; background: #fff; }
.hd-list-pager .page-link { font-size: .76rem; padding: .25rem .5rem; }

/* ── Panel szczegółów ─────────────────────────────────────── */
#hdPane {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: #fff;
  border-radius: 0 13px 13px 0;
}
#hdPaneContent {
  flex: 1;
  overflow-y: auto;
  padding: 1.2rem 1.5rem 1.5rem;
}
.hd-pane-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 300px;
  color: #94a3b8;
  text-align: center;
  padding: 3rem;
}
.hd-pane-empty i { font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: .75rem; }
.hd-pane-empty p { font-size: .88rem; margin: 0; }
.hd-pane-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
  color: #94a3b8;
  gap: .5rem;
  font-size: .85rem;
}
.hd-pane-loading .spinner-border { width: 1.2rem; height: 1.2rem; }

/* ── Topbar listy (mobilny widok z przyciskiem pane) ──────── */
.hdList-topbar {
  display: none;
  align-items: center;
  gap: .5rem;
  padding: .5rem .75rem;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
}
@media (max-width: 920px) { .hdList-topbar { display: flex; } }
.hdList-topbar-title {
  font-size: .88rem;
  font-weight: 700;
  color: #1e293b;
  flex: 1;
}

/* ── Bulk bar ─────────────────────────────────────────────── */
.hd-bulk-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 1060;
  background: #1e293b;
  color: #f1f5f9;
  padding: .55rem 1.2rem;
  box-shadow: 0 -4px 16px rgba(0,0,0,.25);
  border-top: 2px solid #3b82f6;
}
.hd-bulk-inner {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: .5rem;
  max-width: 1400px;
  margin: 0 auto;
}
.hd-bulk-count { font-size: .84rem; font-weight: 600; color: #93c5fd; white-space: nowrap; }
.hd-bulk-bar .input-group-text { background: #334155; border-color: #475569; color: #e2e8f0; font-size: .78rem; }
.hd-bulk-bar .form-select { background: #334155; border-color: #475569; color: #f1f5f9; font-size: .78rem; }
.hd-bulk-bar .btn-outline-secondary { border-color: #475569; color: #e2e8f0; }
.hd-bulk-bar .btn-outline-secondary:hover { background: #475569; }
.hd-bulk-bar .btn-outline-danger { border-color: #ef4444; color: #fca5a5; }
.hd-bulk-bar .btn-outline-danger:hover { background: #ef4444; color: #fff; }

/* ── Toast ────────────────────────────────────────────────── */
.hd-toast-wrap { position: fixed; bottom: 1.2rem; right: 1.2rem; z-index: 1090; display: flex; flex-direction: column; gap: .4rem; }
.hd-toast { background: #fff; border-left: 4px solid #2563eb; box-shadow: 0 6px 20px rgba(0,0,0,.15); border-radius: 8px; padding: .65rem 1rem; font-size: .84rem; min-width: 240px; max-width: 360px; animation: hdToastIn .18s ease; }
.hd-toast.ok { border-color: #16a34a; }
.hd-toast.err { border-color: #dc2626; }
@keyframes hdToastIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

/* ── Pane: paginacja + overrides ─────────────────────────── */
#hdPaneContent .crm-object-header,
#hdPaneContent .crm-filter-bar { display: none; }
</style>

<!-- ══ LAYOUT ══════════════════════════════════════════════════════════════════ -->
<div id="hdApp">

  <!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
  <aside id="hdSidebar">
    <div class="hdSb-head">
      <a href="<?= APP_URL ?>/helpdesk/index.php" class="hdSb-logo">
        <i class="bi bi-headset" aria-hidden="true"></i>Helpdesk IT
      </a>
      <a href="<?= APP_URL ?>/helpdesk/new.php" class="hdSb-new">
        <i class="bi bi-plus-lg"></i>Nowe zgłoszenie
      </a>
    </div>

    <nav class="hdSb-nav" aria-label="Widoki helpdesku">
      <?php if ($is_op): ?>
      <div class="hdSb-section">
        <div class="hdSb-sep">Widoki</div>
        <?php
        $nav_views = [
            ['view','all',       'bi-inbox',             'Wszystkie',   $cnt_total,      ''],
            ['view','mine',      'bi-person-check',      'Moje',        $cnt_mine,       $cnt_mine > 10 ? 'warn' : ''],
            ['view','unassigned','bi-exclamation-circle','Nieprzypisane',$cnt_unassigned, $cnt_unassigned > 0 ? 'danger' : ''],
        ];
        foreach ($nav_views as [$type, $val, $ico, $lbl, $cnt, $cls]):
            $active = ($type === 'view' && $f_view === $val && $f_status === '' && $f_priority === '' && $f_q === '');
        ?>
        <button type="button" class="hdSb-item<?= $active ? ' active' : '' ?>"
                data-sb-view="<?= $val ?>">
          <i class="bi <?= $ico ?>"></i><span><?= $lbl ?></span>
          <?php if ($cnt > 0): ?>
          <span class="hdSb-cnt <?= $cls ?>"><?= $cnt ?></span>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="hdSb-section">
        <div class="hdSb-sep">Statusy</div>
        <?php
        $status_nav = [
            ['nowe',            'bi-inbox-fill',          'Nowe',       'primary'],
            ['otwarte',         'bi-folder2-open',        'Otwarte',    'warning'],
            ['oczekuje',        'bi-hourglass-split',     'Oczekuje',   ''],
            ['krytyczne',       'bi-exclamation-octagon', 'Krytyczne',  'danger'],
            ['przekazane_zewn', 'bi-box-arrow-up-right',  'Przekazane', ''],
            ['wymaga_prac',     'bi-code-slash',          'Dev',        ''],
            ['rozwiązane',      'bi-check-circle',        'Rozwiązane', ''],
            ['zamknięte',       'bi-lock',                'Zamknięte',  ''],
        ];
        foreach ($status_nav as [$skey, $ico, $slbl, $cls]):
            $scnt   = $stats[$skey] ?? 0;
            $active = ($f_status === $skey && $f_q === '');
        ?>
        <button type="button" class="hdSb-item<?= $active ? ' active' : '' ?>"
                data-sb-status="<?= $skey ?>">
          <i class="bi <?= $ico ?> <?= $cls ? 'text-'.$cls.'-emphasis' : '' ?>"></i>
          <span><?= $slbl ?></span>
          <?php if ($scnt > 0): ?>
          <span class="hdSb-cnt <?= ($skey === 'krytyczne' && $scnt > 0) ? 'danger' : '' ?>"><?= $scnt ?></span>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
    </nav>

    <div class="hdSb-foot">
      <?php if ($is_op): ?>
      <a href="<?= APP_URL ?>/helpdesk/admin_macros.php">
        <i class="bi bi-card-text"></i>Gotowe odpowiedzi
      </a>
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/helpdesk/admin_email_templates.php">
        <i class="bi bi-envelope-gear"></i>Szablony e-mail
      </a>
      <a href="<?= APP_URL ?>/helpdesk/admin.php">
        <i class="bi bi-gear"></i>Ustawienia
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (!$is_op): ?>
      <a href="<?= APP_URL ?>/helpdesk/new.php">
        <i class="bi bi-plus-circle"></i>Nowe zgłoszenie
      </a>
      <?php endif; ?>
    </div>
  </aside>

  <!-- ── Kolumna listy ──────────────────────────────────────────────────────── -->
  <div id="hdListCol">

    <!-- Mobilny topbar -->
    <div class="hdList-topbar">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="hdMobileSidebarBtn"
              data-bs-toggle="offcanvas" data-bs-target="#hdOffcanvasSidebar" aria-label="Menu">
        <i class="bi bi-layout-sidebar"></i>
      </button>
      <span class="hdList-topbar-title">Helpdesk IT
        <?php if ($cnt_unread): ?>
        <span class="hd-unread-badge ms-1" id="hdUnreadBadge"><?= $cnt_unread ?></span>
        <?php else: ?>
        <span class="hd-unread-badge ms-1 d-none" id="hdUnreadBadge"></span>
        <?php endif; ?>
      </span>
      <a href="<?= APP_URL ?>/helpdesk/new.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i>
      </a>
    </div>

    <!-- Pasek wyszukiwania i filtrów -->
    <div class="hdList-head">
      <form id="hdFilterForm" method="get" action="<?= APP_URL ?>/helpdesk/index.php" role="search">
        <input type="hidden" name="view"     id="hdViewInput"     value="<?= h($f_view) ?>">
        <input type="hidden" name="status"   id="hdStatusInput"   value="<?= h($f_status) ?>">
        <input type="hidden" name="category" id="hdCategoryInput" value="<?= h($f_category) ?>">
        <input type="hidden" name="priority" id="hdPriorityInput" value="<?= h($f_priority) ?>">

        <div class="hdList-search-wrap">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="text" id="hdSearchInput" name="q" value="<?= h($f_q) ?>"
                 placeholder="Szukaj numeru, tytułu, zgłaszającego… (/)"
                 autocomplete="off" aria-label="Szukaj zgłoszeń">
        </div>

        <div class="hdList-filters mt-1">
          <!-- Filtry szybkie: kategoria -->
          <?php foreach (HD_CATEGORIES as $ckey => $clbl): ?>
          <span class="hdList-pill<?= $f_category === $ckey ? ' active' : '' ?>"
                data-filter="category" data-value="<?= h($ckey) ?>"
                role="button" tabindex="0">
            <?= h($clbl) ?>
          </span>
          <?php endforeach; ?>
        </div>

        <?php foreach (HD_PRIORITIES as $pkey => $pdef): ?>
        <input type="hidden" name="priority_opt_<?= h($pkey) ?>" value="<?= h($pdef['label']) ?>" disabled>
        <?php endforeach; ?>
      </form>
    </div>

    <!-- Licznik + szybkie sortowanie -->
    <div class="hdList-count-bar">
      <span id="hdTotalCount" aria-live="polite" aria-atomic="true">
        <?= $total_all ?> <?= $total_all === 1 ? 'zgłoszenie' : ($total_all < 5 ? 'zgłoszenia' : 'zgłoszeń') ?>
        <?php if ($f_q !== '' || $f_status !== '' || $f_category !== '' || $f_priority !== ''): ?>
        <a href="<?= APP_URL ?>/helpdesk/index.php?view=<?= h($f_view) ?>"
           class="ms-1 text-danger text-decoration-none" style="font-size:.72rem"
           title="Wyczyść filtry"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
      </span>
      <span style="font-size:.72rem;color:#94a3b8">
        <?php if ($is_op && $cnt_unread): ?>
        <span class="text-primary fw-bold"><?= $cnt_unread ?> nowych</span>
        <?php endif; ?>
      </span>
    </div>

    <!-- Karty zgłoszeń (AJAX region) -->
    <div id="hdListBody" aria-label="Lista zgłoszeń" aria-live="polite" aria-atomic="true">
      <?= _hd_list_html($tickets, $total_all, $paging, $per_page, $is_op, $unread_set) ?>
    </div>
  </div>

  <!-- ── Panel szczegółów ───────────────────────────────────────────────────── -->
  <div id="hdPane">
    <div id="hdPaneContent">
      <div class="hd-pane-empty" id="hdPaneEmpty">
        <i class="bi bi-ticket-detailed" aria-hidden="true"></i>
        <p>Wybierz zgłoszenie z listy</p>
      </div>
    </div>
  </div>

</div><!-- #hdApp -->

<!-- Offcanvas sidebar (mobile) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="hdOffcanvasSidebar" aria-labelledby="hdOffcanvasLabel"
     style="background:#1e293b;color:#94a3b8;width:260px">
  <div class="offcanvas-header pb-2">
    <span class="offcanvas-title text-white fw-bold" id="hdOffcanvasLabel" style="font-size:.9rem">
      <i class="bi bi-headset text-primary me-2"></i>Helpdesk IT
    </span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-0" id="hdOffcanvasBody">
    <!-- JS klonuje zawartość hdSidebar -->
  </div>
</div>

<!-- Bulk bar (operator) -->
<?php if ($is_op): ?>
<div id="hdBulkBar" class="hd-bulk-bar d-none" role="region" aria-label="Masowe akcje">
  <div class="hd-bulk-inner">
    <span class="hd-bulk-count me-2"><span id="hdBulkCount">0</span> zaznaczonych</span>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="input-group input-group-sm">
        <label class="input-group-text" for="hdBulkStatus">Status</label>
        <select class="form-select form-select-sm" id="hdBulkStatus" style="min-width:120px">
          <option value="">— wybierz —</option>
          <?php foreach (HD_STATUSES as $k => $s): ?>
          <option value="<?= h($k) ?>"><?= h($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm"
                onclick="HdBulk.doAction('set_status',document.getElementById('hdBulkStatus').value)">Zmień</button>
      </div>
      <div class="input-group input-group-sm">
        <label class="input-group-text" for="hdBulkAssign">Przypisz</label>
        <select class="form-select form-select-sm" id="hdBulkAssign" style="min-width:130px">
          <option value="">— wybierz —</option>
          <option value="0">Usuń przypisanie</option>
          <?php foreach ($op_list as $op): ?>
          <option value="<?= (int)$op['id'] ?>"><?= h($op['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm"
                onclick="HdBulk.doAction('assign',document.getElementById('hdBulkAssign').value)">OK</button>
      </div>
      <button class="btn btn-sm btn-outline-danger" onclick="HdBulk.doAction('close')">
        <i class="bi bi-x-circle me-1"></i>Zamknij zaznaczone
      </button>
      <button class="btn btn-sm btn-link text-muted" onclick="HdBulk.clear()">
        <i class="bi bi-x me-1"></i>Odznacz
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="hd-toast-wrap" id="hdToasts" aria-live="assertive"></div>

<!-- Quill JS -->
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<script>
(function () {
'use strict';

/* ═══════════════════════════════════════════════════════════════════
   GLOBALNE (używane przez _detail.php w trybie pane)
   ═══════════════════════════════════════════════════════════════════ */
var APP  = <?= json_encode(APP_URL) ?>;
var CSRF = <?= json_encode(csrf_token()) ?>;

window.QUILL_TOOLBAR = [
  [{ header: [false, 2, 3] }],
  ['bold','italic','underline','strike'],
  [{ list:'ordered'}, { list:'bullet' }],
  ['blockquote','link'],
  ['clean']
];

window.hdInitQuill = function (root) {
  if (typeof Quill === 'undefined') return;
  (root || document).querySelectorAll('.hd-quill-wrap').forEach(function (wrap) {
    if (wrap._quill) return;
    var editorDiv = wrap.querySelector('[id]'); if (!editorDiv) return;
    var form = wrap.closest('form'); if (!form) return;
    var hiddenInput = form.querySelector('input[name="msg_body"]');
    var q = new Quill(editorDiv, {
      theme: 'snow',
      modules: { toolbar: window.QUILL_TOOLBAR },
      placeholder: 'Wpisz odpowiedź…'
    });
    wrap._quill = q;
    var qlEditor = wrap.querySelector('.ql-editor');
    if (qlEditor) {
      qlEditor.setAttribute('aria-label', 'Treść odpowiedzi');
      qlEditor.setAttribute('aria-multiline', 'true');
    }
    q.on('text-change', function () {
      if (q.getText().trim() !== '') wrap.classList.remove('is-invalid');
    });
    var submitBtn = form.querySelector('button[name="_add_msg"]');
    if (submitBtn) {
      submitBtn.addEventListener('click', function (e) {
        if (q.getText().trim() === '') {
          e.preventDefault();
          wrap.classList.add('is-invalid');
          q.focus();
          return;
        }
        if (hiddenInput) hiddenInput.value = q.root.innerHTML;
      });
    }
  });
};

window.hdFsClose = function (overlay) {
  if (!overlay) return;
  var wrap = overlay._origWrap; if (!wrap) { overlay.classList.remove('active'); return; }
  var fsBody = overlay.querySelector('.hd-quill-fs-body'); if (!fsBody) { overlay.classList.remove('active'); return; }
  var toolbar = fsBody.querySelector('.ql-toolbar');
  var container = fsBody.querySelector('.ql-container');
  if (toolbar) wrap.appendChild(toolbar);
  if (container) wrap.appendChild(container);
  overlay.classList.remove('active');
};

window.hdBindFullscreen = function (root) {
  root = root || document;
  root.querySelectorAll('.hd-fs-btn').forEach(function (btn) {
    if (btn._fsBound) return; btn._fsBound = true;
    btn.addEventListener('click', function () {
      var wrap    = document.getElementById(btn.dataset.target); if (!wrap) return;
      var overlay = document.getElementById(btn.dataset.target.replace('_wrap','_fsOverlay')); if (!overlay) return;
      var fsBody  = document.getElementById(btn.dataset.target.replace('_wrap','_fsBody')); if (!fsBody) return;
      var q = wrap._quill; if (!q) return;
      var toolbar = wrap.querySelector('.ql-toolbar');
      var container = wrap.querySelector('.ql-container');
      if (toolbar) fsBody.appendChild(toolbar);
      if (container) fsBody.appendChild(container);
      overlay.classList.add('active');
      overlay._origWrap = wrap;
      setTimeout(function () { q.focus(); }, 80);
    });
  });
  root.querySelectorAll('.hd-fs-close').forEach(function (btn) {
    if (btn._fsBound) return; btn._fsBound = true;
    btn.addEventListener('click', function () {
      window.hdFsClose(document.getElementById(btn.dataset.overlay));
    });
  });
  if (!document._hdEscBound) {
    document._hdEscBound = true;
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var active = document.querySelector('.hd-quill-fs-overlay.active');
      if (active) { e.preventDefault(); window.hdFsClose(active); }
    });
  }
};

/* ═══════════════════════════════════════════════════════════════════
   hdBindPane — wiąże zachowania w załadowanym fragmencie pane
   ═══════════════════════════════════════════════════════════════════ */
window.hdBindPane = function (root, ticketId) {
  /* UI-only toggles */
  root.querySelectorAll('[data-hd-older]').forEach(function (b) {
    b.addEventListener('click', function () {
      var w = root.querySelector('[data-hd-olderwrap]'); if (!w) return;
      var hid = w.classList.toggle('d-none');
      b.innerHTML = hid
        ? '<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze wiadomości'
        : '<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze wiadomości';
    });
  });
  root.querySelectorAll('[data-hd-more]').forEach(function (b) {
    b.addEventListener('click', function () {
      var body = b.previousElementSibling; if (!body) return;
      b.textContent = body.classList.toggle('hd-expanded') ? 'Zwiń' : 'Pokaż całość';
    });
  });
  root.querySelectorAll('.hd-tpl-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var form = b.closest('form');
      var wrap = form.querySelector('.hd-quill-wrap');
      if (wrap && wrap._quill) {
        var q = wrap._quill;
        if (q.getText().trim() !== '' && !confirm('Zastąpić obecną treść wybranym szablonem?')) return;
        q.root.innerHTML = b.dataset.body.replace(/\n/g,'<br>');
        q.focus(); return;
      }
      var ta = form.querySelector('.hd-msg-body'); if (!ta) return;
      if (ta.value.trim() !== '' && !confirm('Zastąpić treść?')) return;
      ta.value = b.dataset.body; ta.focus();
    });
  });
  root.querySelectorAll('[data-hd-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = b.closest('.input-group').querySelector('[data-hd-copyinput]'); if (!inp) return;
      if (navigator.clipboard) navigator.clipboard.writeText(inp.value);
      b.innerHTML = '<i class="bi bi-check2"></i>';
    });
  });
  var vs = root.querySelector('#hdShareVendor');
  if (vs) vs.addEventListener('change', function () {
    var box = root.querySelector('[data-hd-vendorshare]');
    if (box) box.classList.toggle('d-none', !vs.checked);
  });
  root.querySelectorAll('form[data-hd-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.hdConfirm)) e.preventDefault();
    });
  });

  /* Przycisk "Wróć" (mobilny) */
  root.querySelectorAll('[data-hd-back]').forEach(function (b) {
    b.addEventListener('click', function () { HdApp.closePane(); });
  });

  /* XHR dla wszystkich formularzy w pane */
  root.querySelectorAll('form').forEach(function (f) {
    if (!f.action || f.action.indexOf('/helpdesk/') === -1) return;
    if (f.dataset.noXhr) return;
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      /* Sync Quill przed wysłaniem */
      var wrap = f.querySelector('.hd-quill-wrap');
      if (wrap && wrap._quill) {
        var q = wrap._quill;
        var hidInp = f.querySelector('input[name="msg_body"]');
        if (hidInp) hidInp.value = q.root.innerHTML;
        var clicked = f.querySelector('button[name="_add_msg"]:focus, button[name="_add_msg"].active');
        var isAddMsg = f.querySelector('button[name="_add_msg"]');
        if (isAddMsg && q.getText().trim() === '') {
          wrap.classList.add('is-invalid'); q.focus(); return;
        }
      }
      var fd = new FormData(f);
      fetch(f.action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { HdApp.toast(d.error || 'Błąd operacji.', 'err'); return; }
        if (d.deleted) {
          HdApp.closePane();
          HdApp.loadList(HdApp.formParams(), false);
          HdApp.toast('Zgłoszenie usunięte.', 'ok');
          return;
        }
        if (d.flash && d.flash.msg) {
          HdApp.toast(d.flash.msg, d.flash.type === 'success' ? 'ok' : 'err');
        }
        HdApp.loadPane(ticketId);
        setTimeout(function () { HdApp.loadList(HdApp.formParams(), false); }, 300);
      })
      .catch(function () { HdApp.toast('Błąd sieci — spróbuj ponownie.', 'err'); });
    });
  });

  /* Inicjalizacja Quilla i fullscreen w załadowanym fragmencie */
  window.hdInitQuill(root);
  window.hdBindFullscreen(root);
};

/* ═══════════════════════════════════════════════════════════════════
   HdApp — główna logika konsoli
   ═══════════════════════════════════════════════════════════════════ */
var HdApp = {
  listBody:    null,
  paneContent: null,
  countEl:     null,
  toastWrap:   null,
  filterForm:  null,
  viewInput:   null,
  statusInput: null,
  catInput:    null,
  priInput:    null,
  searchInput: null,
  abort:       null,
  debounce:    null,
  currentId:   0,

  init: function () {
    var self = this;
    this.listBody    = document.getElementById('hdListBody');
    this.paneContent = document.getElementById('hdPaneContent');
    this.countEl     = document.getElementById('hdTotalCount');
    this.toastWrap   = document.getElementById('hdToasts');
    this.filterForm  = document.getElementById('hdFilterForm');
    this.viewInput   = document.getElementById('hdViewInput');
    this.statusInput = document.getElementById('hdStatusInput');
    this.catInput    = document.getElementById('hdCategoryInput');
    this.priInput    = document.getElementById('hdPriorityInput');
    this.searchInput = document.getElementById('hdSearchInput');

    this.bindSidebar();
    this.bindSearch();
    this.bindList();
    this.bindFilterPills();
    this.bindPopstate();

    /* Klonuj sidebar do offcanvas */
    var oc = document.getElementById('hdOffcanvasBody');
    if (oc) oc.innerHTML = document.getElementById('hdSidebar').innerHTML;

    document.body.classList.add('hd-console-page');

    /* Otwórz ticket z URL ?ticket=X */
    var initId = <?= $initial_ticket ?: 0 ?>;
    if (initId) { setTimeout(function () { self.loadPane(initId); }, 200); }
  },

  /* ── Formularz → URLSearchParams ─────────────────────────── */
  formParams: function () {
    var p = new URLSearchParams();
    new FormData(this.filterForm).forEach(function (v, k) { if (v) p.set(k, v); });
    return p;
  },

  /* ── Ładowanie listy (AJAX) ───────────────────────────────── */
  loadList: function (params, push) {
    var self = this;
    if (this.abort) { try { this.abort.abort(); } catch (e) {} }
    this.abort = typeof AbortController !== 'undefined' ? new AbortController() : null;

    this.listBody.setAttribute('aria-busy', 'true');
    this.listBody.style.opacity = '.5';
    this.listBody.style.pointerEvents = 'none';

    var url = new URL(window.location.pathname, window.location.origin);
    params.forEach(function (v, k) { if (v) url.searchParams.set(k, v); });
    url.searchParams.set('_ajax', '1');
    var opts = this.abort ? { signal: this.abort.signal } : {};

    fetch(url.toString(), opts)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        self.listBody.innerHTML = d.list_html;
        self.updateCount(d.total);
        self.updateUnreadBadge(d.unread_count);
        if (push !== false) {
          var hu = new URL(window.location.href);
          hu.search = params.toString();
          history.pushState({ hd: params.toString() }, '', hu.toString());
        }
        self.listBody.removeAttribute('aria-busy');
        self.listBody.style.opacity = '1';
        self.listBody.style.pointerEvents = '';
        self.bindList();
        /* Przywróć zaznaczenie aktywnego ticketu */
        if (self.currentId) {
          var card = self.listBody.querySelector('[data-id="' + self.currentId + '"]');
          if (card) card.classList.add('active');
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        self.listBody.removeAttribute('aria-busy');
        self.listBody.style.opacity = '1';
        self.listBody.style.pointerEvents = '';
      });
  },

  /* ── Ładowanie pane ───────────────────────────────────────── */
  loadPane: function (id) {
    var self = this;
    this.currentId = id;
    document.getElementById('hdApp').classList.add('hd-pane-open');

    this.paneContent.innerHTML =
      '<div class="hd-pane-loading"><div class="spinner-border text-primary" role="status"></div> Ładowanie…</div>';

    /* Zaznacz kartę */
    this.listBody.querySelectorAll('.hd-card').forEach(function (c) {
      c.classList.toggle('active', parseInt(c.dataset.id) === id);
    });

    fetch(APP + '/helpdesk/view.php?id=' + id + '&_pane=1')
      .then(function (r) { return r.text(); })
      .then(function (html) {
        self.paneContent.innerHTML = html;
        /* Pane mode nie zawiera skryptów — wiązanie zewnętrzne */
        window.hdBindPane(self.paneContent, id);
        /* Scroll do góry */
        self.paneContent.scrollTo({ top: 0, behavior: 'smooth' });
      })
      .catch(function () {
        self.paneContent.innerHTML =
          '<div class="alert alert-danger m-3">Nie udało się załadować zgłoszenia.</div>';
      });
  },

  closePane: function () {
    document.getElementById('hdApp').classList.remove('hd-pane-open');
  },

  updateCount: function (total) {
    if (!this.countEl) return;
    var n = parseInt(total, 10) || 0;
    var label = n === 1 ? 'zgłoszenie' : n < 5 ? 'zgłoszenia' : 'zgłoszeń';
    this.countEl.textContent = n + ' ' + label;
  },

  updateUnreadBadge: function (n) {
    var b = document.getElementById('hdUnreadBadge'); if (!b) return;
    if (n > 0) { b.textContent = n; b.classList.remove('d-none'); }
    else b.classList.add('d-none');
  },

  /* ── Sidebar ──────────────────────────────────────────────── */
  bindSidebar: function () {
    var self = this;
    function activate(view, status, cat, pri) {
      self.viewInput.value   = view   || '';
      self.statusInput.value = status || '';
      self.catInput.value    = cat    || '';
      self.priInput.value    = pri    || '';
      var p = self.formParams();
      self.loadList(p, true);
      /* Aktywny sidebar item */
      document.querySelectorAll('.hdSb-item').forEach(function (el) {
        var isView = el.dataset.sbView && el.dataset.sbView === (view || '');
        var isSt   = el.dataset.sbStatus && el.dataset.sbStatus === (status || '');
        el.classList.toggle('active', isView || isSt);
      });
      /* Sync pills */
      document.querySelectorAll('.hdList-pill').forEach(function (pill) {
        pill.classList.toggle('active', pill.dataset.filter === 'category' && pill.dataset.value === (cat||''));
      });
    }

    document.querySelectorAll('[data-sb-view]').forEach(function (btn) {
      btn.addEventListener('click', function () { activate(btn.dataset.sbView, '', '', ''); });
    });
    document.querySelectorAll('[data-sb-status]').forEach(function (btn) {
      btn.addEventListener('click', function () { activate('', btn.dataset.sbStatus, '', ''); });
    });
  },

  /* ── Szybkie filtry (pills) ───────────────────────────────── */
  bindFilterPills: function () {
    var self = this;
    document.querySelectorAll('.hdList-pill').forEach(function (pill) {
      pill.addEventListener('click', function () {
        var already = pill.classList.contains('active');
        document.querySelectorAll('.hdList-pill').forEach(function (p) { p.classList.remove('active'); });
        if (!already) {
          pill.classList.add('active');
          self.catInput.value = pill.dataset.value;
        } else {
          self.catInput.value = '';
        }
        self.loadList(self.formParams(), true);
      });
      pill.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pill.click(); }
      });
    });
  },

  /* ── Wyszukiwarka ────────────────────────────────────────── */
  bindSearch: function () {
    var self = this;
    if (!this.searchInput) return;
    this.searchInput.addEventListener('input', function () {
      clearTimeout(self.debounce);
      self.debounce = setTimeout(function () { self.loadList(self.formParams(), true); }, 360);
    });
    this.searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(self.debounce); self.loadList(self.formParams(), true); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      var t = document.activeElement && document.activeElement.tagName;
      if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
      if (e.key === '/') { e.preventDefault(); self.searchInput.focus(); self.searchInput.select(); }
    });
  },

  /* ── Kliknięcie karty ─────────────────────────────────────── */
  bindList: function () {
    var self = this;
    this.listBody.querySelectorAll('.hd-card').forEach(function (card) {
      card.addEventListener('click', function (e) {
        if (e.target.closest('input,label')) return;
        var id = parseInt(card.dataset.id, 10);
        var href = card.dataset.href;
        /* Na bardzo małych ekranach → pełna nawigacja */
        if (window.innerWidth < 600) { window.location.href = href; return; }
        self.loadPane(id);
      });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') card.click();
      });
    });
    /* Paginacja AJAX */
    this.listBody.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var p = new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search);
        self.loadList(p, true);
        self.listBody.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  },

  /* ── Popstate ────────────────────────────────────────────── */
  bindPopstate: function () {
    var self = this;
    window.addEventListener('popstate', function (e) {
      var p = (e.state && e.state.hd !== undefined)
            ? new URLSearchParams(e.state.hd)
            : new URLSearchParams(window.location.search);
      if (self.viewInput)   self.viewInput.value   = p.get('view')     || '';
      if (self.statusInput) self.statusInput.value = p.get('status')   || '';
      if (self.catInput)    self.catInput.value    = p.get('category') || '';
      if (self.priInput)    self.priInput.value    = p.get('priority') || '';
      if (self.searchInput) self.searchInput.value = p.get('q')        || '';
      self.loadList(p, false);
    });
  },

  /* ── Toast ───────────────────────────────────────────────── */
  toast: function (msg, type) {
    if (!msg) return;
    var t = document.createElement('div');
    t.className = 'hd-toast ' + (type === 'ok' ? 'ok' : type === 'err' ? 'err' : '');
    t.textContent = msg;
    this.toastWrap.appendChild(t);
    setTimeout(function () {
      t.style.opacity = '0'; t.style.transition = 'opacity .3s';
      setTimeout(function () { t.remove(); }, 320);
    }, 4500);
  }
};

/* ═══════════════════════════════════════════════════════════════════
   HdBulk — masowe akcje
   ═══════════════════════════════════════════════════════════════════ */
var HdBulk = {
  bar:     document.getElementById('hdBulkBar'),
  countEl: document.getElementById('hdBulkCount'),
  getChecked: function () {
    return Array.from(document.querySelectorAll('#hdListBody .hd-row-chk:checked'))
                .map(function (c) { return parseInt(c.value, 10); });
  },
  update: function () {
    var ids = this.getChecked(); var n = ids.length;
    if (this.countEl) this.countEl.textContent = n;
    if (this.bar) this.bar.classList.toggle('d-none', n === 0);
  },
  clear: function () {
    document.querySelectorAll('#hdListBody .hd-row-chk').forEach(function (c) { c.checked = false; });
    this.update();
  },
  doAction: function (action, value) {
    var ids = this.getChecked();
    if (!ids.length) { HdApp.toast('Zaznacz przynajmniej jedno zgłoszenie.', 'err'); return; }
    if (action === 'set_status' && !value) { HdApp.toast('Wybierz status.', 'err'); return; }
    if (action === 'assign' && value === '') { HdApp.toast('Wybierz operatora.', 'err'); return; }
    if (action === 'close' && !confirm('Zamknąć ' + ids.length + ' zgłoszeń?')) return;
    var payload = { _csrf: CSRF, action: action, ids: ids };
    if (value !== undefined) payload.value = String(value);
    fetch(APP + '/helpdesk/api/bulk.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { HdApp.toast(d.error || 'Błąd akcji.', 'err'); return; }
      HdApp.toast('Wykonano na ' + (d.affected || ids.length) + ' zgłoszeniach.', 'ok');
      HdBulk.clear();
      HdApp.loadList(HdApp.formParams(), false);
    })
    .catch(function () { HdApp.toast('Błąd sieci.', 'err'); });
  }
};

/* Start */
HdApp.init();

})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
