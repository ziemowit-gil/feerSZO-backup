<?php
/**
 * helpdesk/index.php — Panel Helpdesk IT
 * Layout 3-kolumnowy: Sidebar | Lista | Panel szczegółów (pane mode).
 * Konsola definiuje globalnie: hdInitQuill(), hdBindFullscreen(), hdFsClose(), hdBindPane().
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

// ── Initials helper ───────────────────────────────────────────────────────────
function hd_initials(string $name): string {
    $words = array_values(array_filter(explode(' ', trim($name))));
    if (!$words) return '?';
    if (count($words) === 1) return mb_strtoupper(mb_substr($words[0], 0, 2));
    return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words)-1], 0, 1));
}
function hd_av_color(string $name): string {
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#06b6d4','#84cc16','#f97316'];
    return $colors[abs(crc32($name)) % count($colors)];
}

// ── Renderer listy ────────────────────────────────────────────────────────────
function _hd_list_html(
    array $tickets, int $total, array $paging, int $per_page, bool $is_op, array $unread_set
): string {
    ob_start();
    if ($tickets):
        foreach ($tickets as $t):
            $unread  = isset($unread_set[(int)$t['id']]);
            $age     = max(0, time() - strtotime($t['updated_at'] ?? 'now'));
            $ago     = $age < 3600 ? max(1,(int)($age/60)).'min'
                      : ($age < 86400 ? (int)($age/3600).'h' : (int)($age/86400).'d');
            $req     = $t['requester_name'] ?? '';
            $initials= hd_initials($req);
            $avcolor = hd_av_color($req);
            $sla_ind = $is_op ? hd_sla_indicator($t) : '';
            $has_sla = $sla_ind !== '' && $sla_ind !== '<span class="text-muted">—</span>';
            $crit    = $t['priority'] === 'krytyczny';
            $closed  = in_array($t['status'], ['zamknięte','rozwiązane'], true);
            ?>
<div class="hd-row<?= $unread ? ' hd-row-unread' : '' ?><?= $closed ? ' hd-row-closed' : '' ?>"
     role="button" tabindex="0"
     data-id="<?= (int)$t['id'] ?>"
     data-href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$t['id'] ?>"
     aria-label="Zgłoszenie <?= h($t['number']) ?>: <?= h($t['title']) ?>">
  <div class="hd-row-av" style="background:<?= $avcolor ?>" aria-hidden="true"><?= h($initials) ?></div>
  <div class="hd-row-body">
    <div class="hd-row-top">
      <code class="hd-row-num"><?= h($t['number']) ?></code>
      <span class="hd-row-time"><?= $ago ?></span>
    </div>
    <div class="hd-row-title"><?= h($t['title']) ?></div>
    <div class="hd-row-meta">
      <?= hd_status_badge($t['status']) ?>
      <?php if ($crit): ?><?= hd_priority_badge($t['priority']) ?><?php endif; ?>
      <?php if ($has_sla): ?><?= $sla_ind ?><?php endif; ?>
      <?php if ($is_op): ?>
      <span class="hd-row-meta-person">
        <?= $t['assigned_name']
          ? '<i class="bi bi-person-check" style="color:#10b981"></i> '.h($t['assigned_name'])
          : '<span class="text-danger" style="font-size:.67rem"><i class="bi bi-circle-fill" style="font-size:.4rem"></i> nieopr.</span>' ?>
      </span>
      <?php endif; ?>
      <?php if ($unread): ?>
      <span class="hd-unread-dot" title="Nowe wiadomości"></span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($is_op): ?>
  <label class="hd-row-chk-label" onclick="event.stopPropagation()" title="Zaznacz">
    <input type="checkbox" class="form-check-input hd-row-chk" value="<?= (int)$t['id'] ?>"
           onchange="HdBulk.update()" aria-label="Zaznacz <?= h($t['number']) ?>">
  </label>
  <?php endif; ?>
</div>
            <?php endforeach;
            if ($paging['pages'] > 1): ?>
<div class="hd-list-pager">
  <span class="hd-list-pager-info">
    <?= (($paging['page']-1)*$per_page)+1 ?>–<?= min($paging['page']*$per_page,$total) ?> / <?= $total ?>
  </span>
  <nav aria-label="Strony"><?= pagination_html($paging) ?></nav>
</div>
            <?php endif;
    else: ?>
<div class="hd-list-empty">
  <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
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
/* ── Reset ─────────────────────────────────────────────────── */
body.hd-console-page { overflow: hidden; }

/* ── Główny kontener ──────────────────────────────────────── */
#hdApp {
  display: grid;
  grid-template-columns: 230px 330px 1fr;
  height: calc(100dvh - 62px);
  overflow: hidden;
  background: var(--hd-bg);
  border: 1px solid var(--hd-bd);
  border-radius: 14px;
  box-shadow: 0 2px 16px rgba(0,0,0,.06);
}
@media (max-width: 1200px) { #hdApp { grid-template-columns: 200px 300px 1fr; } }
@media (max-width: 960px) {
  #hdApp { grid-template-columns: 1fr; }
  #hdSidebar { display: none; }
  #hdPane { display: none; }
  #hdApp.hd-pane-open #hdListCol { display: none; }
  #hdApp.hd-pane-open #hdPane { display: block; }
}

/* ── Sidebar (biały) ──────────────────────────────────────── */
#hdSidebar {
  display: flex;
  flex-direction: column;
  background: var(--hd-panel);
  border-right: 1px solid var(--hd-bd);
  border-radius: 13px 0 0 13px;
  overflow: hidden;
}
.hdSb-head { padding: .85rem .8rem .65rem; flex-shrink: 0; border-bottom: 1px solid var(--hd-bd-l); }
.hdSb-logo {
  display: flex; align-items: center; gap: .45rem;
  font-size: .88rem; font-weight: 800; color: var(--hd-tx);
  text-decoration: none; margin-bottom: .65rem; letter-spacing: -.01em;
}
.hdSb-logo i { font-size: 1.15rem; color: var(--hd-accent); }
.hdSb-new {
  display: flex; align-items: center; justify-content: center; gap: .35rem;
  width: 100%; padding: .5rem .7rem;
  background: var(--hd-accent); color: #fff;
  border: none; border-radius: 8px; font-size: .8rem; font-weight: 600;
  cursor: pointer; text-decoration: none; transition: background .12s;
}
.hdSb-new:hover { background: var(--hd-accent-h); color: #fff; }
.hdSb-nav { flex: 1; overflow-y: auto; padding: .5rem 0; scrollbar-width: none; }
.hdSb-nav::-webkit-scrollbar { display: none; }
.hdSb-sep {
  padding: .6rem .8rem .18rem;
  font-size: .62rem; text-transform: uppercase; letter-spacing: .08em;
  color: var(--hd-tx3); font-weight: 700; user-select: none;
}
.hdSb-item {
  display: flex; align-items: center; gap: .5rem;
  padding: .38rem .75rem .38rem .8rem; margin: 0 .5rem .05rem;
  color: var(--hd-tx2); font-size: .8rem;
  cursor: pointer; background: none; border: none; width: calc(100% - 1rem);
  text-align: left; border-radius: 7px; transition: all .1s; line-height: 1.25;
}
.hdSb-item:hover { background: var(--hd-bg); color: var(--hd-tx); }
.hdSb-item.active { background: var(--hd-accent-l); color: var(--hd-accent); font-weight: 600; }
.hdSb-item i { width: 16px; font-size: .92rem; flex-shrink: 0; text-align: center; }
.hdSb-cnt {
  margin-left: auto; background: var(--hd-bg); color: var(--hd-tx3);
  border-radius: 999px; font-size: .64rem; font-weight: 700;
  min-width: 17px; height: 17px; padding: 0 5px;
  display: inline-flex; align-items: center; justify-content: center;
}
.hdSb-item.active .hdSb-cnt { background: var(--hd-accent-m); color: var(--hd-accent-h); }
.hdSb-cnt.warn  { background: #fef3c7; color: #92400e; }
.hdSb-cnt.danger{ background: #fee2e2; color: #991b1b; }
.hdSb-foot {
  padding: .5rem .55rem .65rem;
  border-top: 1px solid var(--hd-bd-l); flex-shrink: 0;
}
.hdSb-foot a {
  display: flex; align-items: center; gap: .4rem;
  padding: .32rem .55rem; font-size: .76rem; color: var(--hd-tx3);
  text-decoration: none; border-radius: 6px;
}
.hdSb-foot a:hover { background: var(--hd-bg); color: var(--hd-tx2); }
.hdSb-foot a i { width: 15px; font-size: .85rem; }

/* ── Lista ────────────────────────────────────────────────── */
#hdListCol {
  display: flex; flex-direction: column;
  background: var(--hd-panel);
  border-right: 1px solid var(--hd-bd);
  overflow: hidden;
}
.hdList-topbar {
  display: none; align-items: center; gap: .5rem;
  padding: .5rem .75rem; border-bottom: 1px solid var(--hd-bd);
  background: var(--hd-panel);
}
@media (max-width: 960px) { .hdList-topbar { display: flex; } }
.hdList-topbar-title { font-size: .9rem; font-weight: 700; color: var(--hd-tx); flex: 1; }
.hdList-head {
  background: var(--hd-panel); border-bottom: 1px solid var(--hd-bd-l);
  padding: .6rem .65rem .5rem; flex-shrink: 0;
}
.hdList-search-wrap { position: relative; margin-bottom: .4rem; }
.hdList-search-wrap i {
  position: absolute; left: .55rem; top: 50%; transform: translateY(-50%);
  color: var(--hd-tx3); pointer-events: none; font-size: .82rem;
}
#hdSearchInput {
  width: 100%; padding: .4rem .65rem .4rem 1.85rem;
  border: 1px solid var(--hd-bd); border-radius: 8px;
  font-size: .82rem; background: var(--hd-bg); color: var(--hd-tx);
  transition: all .15s;
}
#hdSearchInput:focus { outline: none; background: #fff; border-color: var(--hd-accent); box-shadow: 0 0 0 3px var(--hd-accent-m); }
.hdList-filters {
  display: flex; gap: .25rem; overflow-x: auto; scrollbar-width: none;
  padding-bottom: 2px; align-items: center;
}
.hdList-filters::-webkit-scrollbar { display: none; }
.hdList-pill {
  display: inline-flex; align-items: center; gap: .2rem;
  padding: .18rem .5rem; border: 1px solid var(--hd-bd);
  border-radius: 6px; font-size: .7rem; white-space: nowrap;
  cursor: pointer; background: var(--hd-panel); color: var(--hd-tx2);
  transition: all .1s; flex-shrink: 0; user-select: none;
}
.hdList-pill:hover, .hdList-pill.active {
  background: var(--hd-accent-l); border-color: var(--hd-accent-m); color: var(--hd-accent);
}
.hdList-count-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: .28rem .65rem; border-bottom: 1px solid var(--hd-bd-l);
  flex-shrink: 0;
}
.hdList-count-bar span { font-size: .72rem; color: var(--hd-tx3); }
#hdListBody { flex: 1; overflow-y: auto; scrollbar-width: thin; }

/* ── Karta zgłoszenia (nowy styl) ─────────────────────────── */
.hd-row { grid-template-columns: 36px 1fr auto; }
.hd-row-closed { opacity: .65; }
.hd-row-meta-person { margin-left: auto; font-size: .69rem; color: var(--hd-tx3); display: flex; align-items: center; gap: .18rem; }
.hd-row-meta-person i { font-size: .75rem; }
.hd-row-chk-label {
  display: flex; align-items: center; padding: 0 .4rem 0 .2rem;
  cursor: pointer; opacity: 0; transition: opacity .1s; flex-shrink: 0;
}
.hd-row:hover .hd-row-chk-label,
.hd-row .hd-row-chk:checked ~ .hd-row-chk-label,
.hd-row-chk:checked + .hd-row-chk-label { opacity: 1; }
.hd-list-empty {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 4rem 1rem; color: var(--hd-tx3); text-align: center;
}
.hd-list-empty i { font-size: 2.8rem; display: block; margin-bottom: .5rem; opacity: .4; }
.hd-list-empty p { font-size: .84rem; margin: 0; }
.hd-list-pager {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: .5rem; padding: .45rem .65rem;
  border-top: 1px solid var(--hd-bd-l); background: var(--hd-panel);
}
.hd-list-pager-info { font-size: .72rem; color: var(--hd-tx3); }
.hd-list-pager .page-link { font-size: .75rem; padding: .2rem .45rem; }
/* Loading state for list */
#hdListBody[aria-busy=true] { opacity: .5; pointer-events: none; transition: opacity .15s; }

/* ── Panel szczegółów ─────────────────────────────────────── */
#hdPane {
  display: block; overflow: hidden;
  background: var(--hd-panel);
  border-radius: 0 13px 13px 0;
}
#hdPaneContent {
  height: 100%; overflow-y: auto;
  padding: 1.1rem 1.4rem 1.5rem;
  scrollbar-width: thin;
}
.hd-pane-loading {
  display: flex; align-items: center; justify-content: center;
  min-height: 250px; color: var(--hd-tx3); gap: .6rem; font-size: .85rem;
}
.hd-pane-loading .spinner-border { width: 1.4rem; height: 1.4rem; border-color: var(--hd-accent); border-right-color: transparent; }

/* ── Toast ─────────────────────────────────────────────────── */
.hd-toast-wrap { position: fixed; bottom: 1.2rem; right: 1.2rem; z-index: 1090; display: flex; flex-direction: column; gap: .45rem; }
.hd-toast { background: #fff; border-left: 4px solid var(--hd-accent); box-shadow: var(--hd-shadow-md); border-radius: 10px; padding: .7rem 1rem; font-size: .84rem; min-width: 240px; max-width: 380px; animation: hdToastIn .18s ease; display: flex; align-items: flex-start; gap: .5rem; }
.hd-toast.ok { border-color: var(--hd-success); }
.hd-toast.err { border-color: var(--hd-err); }
@keyframes hdToastIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }

/* ── Bulk bar ──────────────────────────────────────────────── */
.hd-bulk-bar { position:fixed; bottom:0; left:0; right:0; z-index:1060; background:#1e1b4b; color:#e0e7ff; padding:.55rem 1.2rem; box-shadow:0 -2px 20px rgba(79,70,229,.3); border-top:2px solid var(--hd-accent); }
.hd-bulk-inner { display:flex; align-items:center; flex-wrap:wrap; gap:.5rem; max-width:1400px; margin:0 auto; }
.hd-bulk-count { font-size:.84rem; font-weight:600; color:#a5b4fc; white-space:nowrap; }
.hd-bulk-bar .input-group-text,.hd-bulk-bar .form-select { background:#312e81; border-color:#4338ca; color:#e0e7ff; font-size:.78rem; }
.hd-bulk-bar .btn-outline-secondary { border-color:#6366f1; color:#c7d2fe; }
.hd-bulk-bar .btn-outline-secondary:hover { background:#4338ca; }
.hd-bulk-bar .btn-outline-danger { border-color:#f87171; color:#fca5a5; }
.hd-bulk-bar .btn-outline-danger:hover { background:#dc2626; color:#fff; }
.hd-bulk-bar .btn-link { color:#818cf8; }

/* ── Offcanvas sidebar (mobile) ───────────────────────────── */
#hdOffcanvasSidebar { background: var(--hd-panel); width: 260px; }
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
      <div class="hdSb-sep">Widoki</div>
      <?php
      $nav_views = [
          ['view','all',        'bi-inbox',              'Wszystkie',    $cnt_total,      ''],
          ['view','mine',       'bi-person-check',       'Moje',         $cnt_mine,       $cnt_mine > 10 ? 'warn' : ''],
          ['view','unassigned', 'bi-exclamation-circle', 'Nieprzypisane',$cnt_unassigned, $cnt_unassigned > 0 ? 'danger' : ''],
      ];
      foreach ($nav_views as [$type, $val, $ico, $lbl, $cnt, $cls]):
          $active = $type === 'view' && $f_view === $val && $f_status === '' && $f_priority === '' && $f_q === '';
      ?>
      <button type="button" class="hdSb-item<?= $active ? ' active' : '' ?>" data-sb-view="<?= $val ?>">
        <i class="bi <?= $ico ?>"></i><span><?= $lbl ?></span>
        <?php if ($cnt > 0): ?><span class="hdSb-cnt <?= $cls ?>"><?= $cnt ?></span><?php endif; ?>
      </button>
      <?php endforeach; ?>

      <div class="hdSb-sep" style="margin-top:.5rem">Statusy</div>
      <?php endif; ?>

      <?php
      $status_nav = [
          ['nowe',            'bi-circle-fill',         'Nowe',         'text-primary-emphasis'],
          ['otwarte',         'bi-folder2-open',        'Otwarte',      'text-warning-emphasis'],
          ['oczekuje',        'bi-hourglass-split',     'Oczekuje',     ''],
          ['krytyczne',       'bi-exclamation-octagon', 'Krytyczne',    'text-danger-emphasis'],
          ['przekazane_zewn', 'bi-arrow-up-right-circle','Przekazane',  ''],
          ['wymaga_prac',     'bi-code-slash',          'Dev',          ''],
          ['rozwiązane',      'bi-check-circle',        'Rozwiązane',   'text-success-emphasis'],
          ['zamknięte',       'bi-lock',                'Zamknięte',    ''],
      ];
      foreach ($status_nav as [$skey, $ico, $slbl, $cls]):
          $scnt   = $stats[$skey] ?? 0;
          $active = $f_status === $skey && $f_q === '';
      ?>
      <button type="button" class="hdSb-item<?= $active ? ' active' : '' ?>" data-sb-status="<?= $skey ?>">
        <i class="bi <?= $ico ?> <?= $cls ?>"></i><span><?= $slbl ?></span>
        <?php if ($scnt > 0): ?>
        <span class="hdSb-cnt<?= $skey === 'krytyczne' && $scnt > 0 ? ' danger' : '' ?>"><?= $scnt ?></span>
        <?php endif; ?>
      </button>
      <?php endforeach; ?>
    </nav>

    <div class="hdSb-foot">
      <?php if ($cnt_unread): ?>
      <a href="<?= APP_URL ?>/helpdesk/index.php" style="color:var(--hd-accent);font-weight:600">
        <i class="bi bi-bell-fill"></i><?= $cnt_unread ?> nieodczytane
      </a>
      <?php endif; ?>
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
      <?php else: ?>
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
      <button type="button" class="btn btn-sm btn-outline-secondary"
              data-bs-toggle="offcanvas" data-bs-target="#hdOffcanvasSidebar" aria-label="Menu">
        <i class="bi bi-layout-sidebar"></i>
      </button>
      <span class="hdList-topbar-title">Helpdesk IT
        <span class="hd-unread-badge ms-1<?= $cnt_unread ? '' : ' d-none' ?>" id="hdUnreadBadge">
          <?= $cnt_unread ?: '' ?>
        </span>
      </span>
      <a href="<?= APP_URL ?>/helpdesk/new.php" class="btn btn-sm btn-primary py-1 px-2">
        <i class="bi bi-plus-lg"></i>
      </a>
    </div>

    <!-- Filtrowanie -->
    <div class="hdList-head">
      <form id="hdFilterForm" method="get" action="<?= APP_URL ?>/helpdesk/index.php" role="search">
        <input type="hidden" name="view"     id="hdViewInput"     value="<?= h($f_view) ?>">
        <input type="hidden" name="status"   id="hdStatusInput"   value="<?= h($f_status) ?>">
        <input type="hidden" name="category" id="hdCategoryInput" value="<?= h($f_category) ?>">
        <input type="hidden" name="priority" id="hdPriorityInput" value="<?= h($f_priority) ?>">

        <div class="hdList-search-wrap">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="text" id="hdSearchInput" name="q" value="<?= h($f_q) ?>"
                 placeholder="Szukaj numeru, tytułu… (/)"
                 autocomplete="off" aria-label="Szukaj zgłoszeń">
        </div>
        <div class="hdList-filters mt-1">
          <?php foreach (HD_CATEGORIES as $ckey => $clbl): ?>
          <span class="hdList-pill<?= $f_category === $ckey ? ' active' : '' ?>"
                data-filter="category" data-value="<?= h($ckey) ?>"
                role="button" tabindex="0"><?= h($clbl) ?></span>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <!-- Licznik -->
    <div class="hdList-count-bar">
      <span id="hdTotalCount" aria-live="polite">
        <?= $total_all ?> <?= $total_all === 1 ? 'zgłoszenie' : ($total_all < 5 ? 'zgłoszenia' : 'zgłoszeń') ?>
        <?php if ($f_q !== '' || $f_status !== '' || $f_category !== '' || $f_priority !== ''): ?>
        <a href="<?= APP_URL ?>/helpdesk/index.php?view=<?= h($f_view) ?>"
           class="ms-1 text-danger text-decoration-none" style="font-size:.7rem"
           title="Wyczyść filtry"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
      </span>
      <?php if ($is_op && $cnt_unread): ?>
      <span style="font-size:.7rem;color:var(--hd-accent);font-weight:600"><?= $cnt_unread ?> nowych</span>
      <?php endif; ?>
    </div>

    <!-- Lista AJAX -->
    <div id="hdListBody" aria-label="Lista zgłoszeń" aria-live="polite">
      <?= _hd_list_html($tickets, $total_all, $paging, $per_page, $is_op, $unread_set) ?>
    </div>
  </div>

  <!-- ── Panel szczegółów ───────────────────────────────────────────────────── -->
  <div id="hdPane">
    <div id="hdPaneContent">
      <div class="hd-pane-empty" id="hdPaneEmpty">
        <i class="bi bi-ticket-detailed-fill" aria-hidden="true"></i>
        <p>Wybierz zgłoszenie z listy</p>
      </div>
    </div>
  </div>

</div><!-- #hdApp -->

<!-- Offcanvas sidebar (mobile) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="hdOffcanvasSidebar"
     aria-labelledby="hdOffcanvasLabel">
  <div class="offcanvas-header pb-2 border-bottom">
    <span class="offcanvas-title fw-bold" id="hdOffcanvasLabel" style="font-size:.9rem">
      <i class="bi bi-headset text-primary me-2"></i>Helpdesk IT
    </span>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-0" id="hdOffcanvasBody"></div>
</div>

<!-- Bulk bar -->
<?php if ($is_op): ?>
<div id="hdBulkBar" class="hd-bulk-bar d-none" role="region" aria-label="Masowe akcje">
  <div class="hd-bulk-inner">
    <span class="hd-bulk-count me-2"><span id="hdBulkCount">0</span> zaznaczonych</span>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="input-group input-group-sm">
        <label class="input-group-text" for="hdBulkStatus">Status</label>
        <select class="form-select" id="hdBulkStatus" style="min-width:120px">
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
        <select class="form-select" id="hdBulkAssign" style="min-width:130px">
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
      <button class="btn btn-sm btn-link" onclick="HdBulk.clear()">
        <i class="bi bi-x me-1"></i>Odznacz
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="hd-toast-wrap" id="hdToasts" aria-live="assertive"></div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function () {
'use strict';

var APP  = <?= json_encode(APP_URL) ?>;
var CSRF = <?= json_encode(csrf_token()) ?>;

/* ── Globals (używane przez _detail.php w trybie pane) ─────── */
window.QUILL_TOOLBAR = [
  [{ header: [false, 2, 3] }],
  ['bold','italic','underline','strike'],
  [{ list:'ordered'}, { list:'bullet' }],
  ['blockquote','link'], ['clean']
];

window.hdInitQuill = function (root) {
  if (typeof Quill === 'undefined') return;
  (root || document).querySelectorAll('.hd-quill-wrap').forEach(function (wrap) {
    if (wrap._quill) return;
    var editorDiv = wrap.querySelector('[id]'); if (!editorDiv) return;
    var form = wrap.closest('form'); if (!form) return;
    var hiddenInput = form.querySelector('input[name="msg_body"]');
    var q = new Quill(editorDiv, {
      theme: 'snow', modules: { toolbar: window.QUILL_TOOLBAR }, placeholder: 'Wpisz odpowiedź…'
    });
    wrap._quill = q;
    var qlEd = wrap.querySelector('.ql-editor');
    if (qlEd) { qlEd.setAttribute('aria-label','Treść odpowiedzi'); qlEd.setAttribute('aria-multiline','true'); }
    q.on('text-change', function () { if (q.getText().trim()) wrap.classList.remove('is-invalid'); });
    var sb = form.querySelector('button[name="_add_msg"]');
    if (sb) sb.addEventListener('click', function (e) {
      if (q.getText().trim() === '') { e.preventDefault(); wrap.classList.add('is-invalid'); q.focus(); return; }
      if (hiddenInput) hiddenInput.value = q.root.innerHTML;
    });
  });
};

window.hdFsClose = function (overlay) {
  if (!overlay) return;
  var wrap = overlay._origWrap; if (!wrap) { overlay.classList.remove('active'); return; }
  var fsBody = overlay.querySelector('.hd-quill-fs-body'); if (!fsBody) { overlay.classList.remove('active'); return; }
  var tb = fsBody.querySelector('.ql-toolbar'), ct = fsBody.querySelector('.ql-container');
  if (tb) wrap.appendChild(tb); if (ct) wrap.appendChild(ct);
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
      var tb = wrap.querySelector('.ql-toolbar'), ct = wrap.querySelector('.ql-container');
      if (tb) fsBody.appendChild(tb); if (ct) fsBody.appendChild(ct);
      overlay.classList.add('active'); overlay._origWrap = wrap;
      setTimeout(function () { q.focus(); }, 80);
    });
  });
  root.querySelectorAll('.hd-fs-close').forEach(function (btn) {
    if (btn._fsBound) return; btn._fsBound = true;
    btn.addEventListener('click', function () { window.hdFsClose(document.getElementById(btn.dataset.overlay)); });
  });
  if (!document._hdEscBound) {
    document._hdEscBound = true;
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var a = document.querySelector('.hd-quill-fs-overlay.active');
      if (a) { e.preventDefault(); window.hdFsClose(a); }
    });
  }
};

/* ── hdBindPane ─────────────────────────────────────────────── */
window.hdBindPane = function (root, ticketId) {
  /* Toggle starszych wiadomości */
  root.querySelectorAll('[data-hd-older]').forEach(function (b) {
    b.addEventListener('click', function () {
      var w = root.querySelector('[data-hd-olderwrap]'); if (!w) return;
      var hid = w.classList.toggle('d-none');
      b.innerHTML = hid
        ? '<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze'
        : '<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze';
    });
  });
  /* Rozwijanie długich wiadomości */
  root.querySelectorAll('[data-hd-more]').forEach(function (b) {
    b.addEventListener('click', function () {
      var body = b.previousElementSibling; if (!body) return;
      b.textContent = body.classList.toggle('hd-expanded') ? 'Zwiń' : 'Pokaż całość';
    });
  });
  /* Makra — panel kart */
  root.querySelectorAll('.hd-macro-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = btn.closest('.hd-reply-box').querySelector('.hd-macro-panel');
      if (!panel) return;
      var open = panel.classList.toggle('open');
      btn.classList.toggle('open', open);
      btn.querySelector('i').className = 'bi bi-' + (open ? 'chevron-up' : 'card-text');
    });
  });
  root.querySelectorAll('.hd-macro-card').forEach(function (card) {
    card.addEventListener('click', function () {
      var body = card.dataset.body || '';
      var form = card.closest('form'); if (!form) return;
      var wrap = form.querySelector('.hd-quill-wrap');
      if (wrap && wrap._quill) {
        var q = wrap._quill;
        if (q.getText().trim() !== '' && !confirm('Zastąpić obecną treść wybranym makrem?')) return;
        q.root.innerHTML = body.replace(/\n/g,'<br>'); q.focus();
      }
    });
  });
  /* Stare hd-tpl-btn (fallback) */
  root.querySelectorAll('.hd-tpl-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var form = b.closest('form'); var wrap = form && form.querySelector('.hd-quill-wrap');
      if (wrap && wrap._quill) {
        var q = wrap._quill;
        if (q.getText().trim() !== '' && !confirm('Zastąpić treść?')) return;
        q.root.innerHTML = b.dataset.body.replace(/\n/g,'<br>'); q.focus();
      }
    });
  });
  /* Kopiowanie linku */
  root.querySelectorAll('[data-hd-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = b.closest('.input-group') && b.closest('.input-group').querySelector('[data-hd-copyinput]');
      if (inp && navigator.clipboard) navigator.clipboard.writeText(inp.value);
      b.innerHTML = '<i class="bi bi-check2"></i>';
    });
  });
  /* Checkbox udostępnienia firmie zewnętrznej */
  var vs = root.querySelector('#hdShareVendor');
  if (vs) vs.addEventListener('change', function () {
    var box = root.querySelector('[data-hd-vendorshare]');
    if (box) box.classList.toggle('d-none', !vs.checked);
  });
  /* Potwierdź formularze */
  root.querySelectorAll('form[data-hd-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.hdConfirm)) e.preventDefault(); });
  });
  /* Przycisk wstecz (mobilny) */
  root.querySelectorAll('[data-hd-back]').forEach(function (b) {
    b.addEventListener('click', function () { HdApp.closePane(); });
  });
  /* XHR dla wszystkich formularzy */
  root.querySelectorAll('form').forEach(function (f) {
    if (!f.action || f.action.indexOf('/helpdesk/') === -1) return;
    if (f.dataset.noXhr) return;
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var wrap = f.querySelector('.hd-quill-wrap');
      if (wrap && wrap._quill) {
        var q = wrap._quill;
        var hi = f.querySelector('input[name="msg_body"]');
        if (hi) hi.value = q.root.innerHTML;
        if (f.querySelector('button[name="_add_msg"]') && q.getText().trim() === '') {
          wrap.classList.add('is-invalid'); q.focus(); return;
        }
      }
      fetch(f.action, { method:'POST', body: new FormData(f), headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { HdApp.toast(d.error || 'Błąd operacji.', 'err'); return; }
          if (d.deleted) { HdApp.closePane(); HdApp.loadList(HdApp.formParams(), false); HdApp.toast('Zgłoszenie usunięte.','ok'); return; }
          if (d.flash && d.flash.msg) HdApp.toast(d.flash.msg, d.flash.type === 'success' ? 'ok' : 'err');
          HdApp.loadPane(ticketId);
          setTimeout(function () { HdApp.loadList(HdApp.formParams(), false); }, 300);
        })
        .catch(function () { HdApp.toast('Błąd sieci — spróbuj ponownie.','err'); });
    });
  });
  window.hdInitQuill(root);
  window.hdBindFullscreen(root);
};

/* ── HdApp ──────────────────────────────────────────────────── */
var HdApp = {
  listBody:null, paneContent:null, countEl:null, toastWrap:null,
  filterForm:null, viewInput:null, statusInput:null, catInput:null, priInput:null,
  searchInput:null, abort:null, debounce:null, currentId:0,

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
    this.bindSidebar(); this.bindSearch(); this.bindList();
    this.bindFilterPills(); this.bindPopstate();
    /* Klonuj sidebar do offcanvas (mobile) */
    var oc = document.getElementById('hdOffcanvasBody');
    if (oc) oc.innerHTML = document.getElementById('hdSidebar').innerHTML;
    document.body.classList.add('hd-console-page');
    var initId = <?= $initial_ticket ?: 0 ?>;
    if (initId) setTimeout(function () { self.loadPane(initId); }, 200);
  },

  formParams: function () {
    var p = new URLSearchParams();
    new FormData(this.filterForm).forEach(function (v,k) { if (v) p.set(k,v); });
    return p;
  },

  loadList: function (params, push) {
    var self = this;
    if (this.abort) try { this.abort.abort(); } catch(e){}
    this.abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
    this.listBody.setAttribute('aria-busy','true');
    var url = new URL(window.location.pathname, window.location.origin);
    params.forEach(function (v,k) { if (v) url.searchParams.set(k,v); });
    url.searchParams.set('_ajax','1');
    fetch(url.toString(), this.abort ? {signal:this.abort.signal} : {})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        self.listBody.innerHTML = d.list_html;
        self.updateCount(d.total); self.updateUnreadBadge(d.unread_count);
        if (push !== false) {
          var hu = new URL(window.location.href); hu.search = params.toString();
          history.pushState({ hd: params.toString() }, '', hu.toString());
        }
        self.listBody.removeAttribute('aria-busy'); self.bindList();
        if (self.currentId) {
          var card = self.listBody.querySelector('[data-id="' + self.currentId + '"]');
          if (card) card.classList.add('active');
        }
      })
      .catch(function (e) { if (e && e.name !== 'AbortError') self.listBody.removeAttribute('aria-busy'); });
  },

  loadPane: function (id) {
    var self = this;
    this.currentId = id;
    document.getElementById('hdApp').classList.add('hd-pane-open');
    this.paneContent.innerHTML = '<div class="hd-pane-loading"><div class="spinner-border" role="status"></div>Ładowanie…</div>';
    this.listBody.querySelectorAll('.hd-row').forEach(function (c) {
      c.classList.toggle('active', parseInt(c.dataset.id) === id);
    });
    fetch(APP + '/helpdesk/view.php?id=' + id + '&_pane=1')
      .then(function (r) { return r.text(); })
      .then(function (html) {
        self.paneContent.innerHTML = html;
        window.hdBindPane(self.paneContent, id);
        self.paneContent.scrollTo({ top:0, behavior:'smooth' });
      })
      .catch(function () {
        self.paneContent.innerHTML = '<div class="alert alert-danger m-3">Nie udało się załadować zgłoszenia.</div>';
      });
  },

  closePane: function () { document.getElementById('hdApp').classList.remove('hd-pane-open'); },

  updateCount: function (total) {
    if (!this.countEl) return;
    var n = parseInt(total,10) || 0;
    this.countEl.textContent = n + ' ' + (n===1?'zgłoszenie':n<5?'zgłoszenia':'zgłoszeń');
  },

  updateUnreadBadge: function (n) {
    var b = document.getElementById('hdUnreadBadge'); if (!b) return;
    if (n > 0) { b.textContent = n; b.classList.remove('d-none'); }
    else b.classList.add('d-none');
  },

  bindSidebar: function () {
    var self = this;
    function activate(view, status) {
      self.viewInput.value = view || ''; self.statusInput.value = status || '';
      self.catInput.value  = ''; self.priInput.value = '';
      var p = self.formParams(); self.loadList(p, true);
      document.querySelectorAll('.hdSb-item').forEach(function (el) {
        el.classList.toggle('active',
          (el.dataset.sbView && el.dataset.sbView === (view||'')) ||
          (el.dataset.sbStatus && el.dataset.sbStatus === (status||'')));
      });
    }
    document.querySelectorAll('[data-sb-view]').forEach(function (btn) {
      btn.addEventListener('click', function () { activate(btn.dataset.sbView, ''); });
    });
    document.querySelectorAll('[data-sb-status]').forEach(function (btn) {
      btn.addEventListener('click', function () { activate('', btn.dataset.sbStatus); });
    });
  },

  bindFilterPills: function () {
    var self = this;
    document.querySelectorAll('.hdList-pill').forEach(function (pill) {
      pill.addEventListener('click', function () {
        var already = pill.classList.contains('active');
        document.querySelectorAll('.hdList-pill').forEach(function (p) { p.classList.remove('active'); });
        self.catInput.value = already ? '' : pill.dataset.value;
        if (!already) pill.classList.add('active');
        self.loadList(self.formParams(), true);
      });
      pill.addEventListener('keydown', function (e) { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); pill.click(); } });
    });
  },

  bindSearch: function () {
    var self = this;
    if (!this.searchInput) return;
    this.searchInput.addEventListener('input', function () {
      clearTimeout(self.debounce);
      self.debounce = setTimeout(function () { self.loadList(self.formParams(), true); }, 360);
    });
    this.searchInput.addEventListener('keydown', function (e) {
      if (e.key==='Enter') { e.preventDefault(); clearTimeout(self.debounce); self.loadList(self.formParams(), true); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.ctrlKey||e.metaKey||e.altKey) return;
      var t = document.activeElement && document.activeElement.tagName;
      if (t==='INPUT'||t==='TEXTAREA'||t==='SELECT') return;
      if (e.key==='/') { e.preventDefault(); self.searchInput.focus(); self.searchInput.select(); }
    });
  },

  bindList: function () {
    var self = this;
    this.listBody.querySelectorAll('.hd-row').forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target.closest('input,label')) return;
        var id = parseInt(row.dataset.id, 10), href = row.dataset.href;
        if (window.innerWidth < 600) { window.location.href = href; return; }
        self.loadPane(id);
      });
      row.addEventListener('keydown', function (e) { if (e.key==='Enter') row.click(); });
    });
    this.listBody.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var p = new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search);
        self.loadList(p, true);
        self.listBody.scrollTo({ top:0, behavior:'smooth' });
      });
    });
  },

  bindPopstate: function () {
    var self = this;
    window.addEventListener('popstate', function (e) {
      var p = e.state && e.state.hd !== undefined ? new URLSearchParams(e.state.hd) : new URLSearchParams(window.location.search);
      if (self.viewInput)   self.viewInput.value   = p.get('view')     || '';
      if (self.statusInput) self.statusInput.value = p.get('status')   || '';
      if (self.catInput)    self.catInput.value    = p.get('category') || '';
      if (self.priInput)    self.priInput.value    = p.get('priority') || '';
      if (self.searchInput) self.searchInput.value = p.get('q')        || '';
      self.loadList(p, false);
    });
  },

  toast: function (msg, type) {
    if (!msg) return;
    var t = document.createElement('div');
    t.className = 'hd-toast ' + (type==='ok'?'ok':type==='err'?'err':'');
    var ico = type==='ok' ? '✓' : type==='err' ? '✕' : 'ℹ';
    t.innerHTML = '<span style="flex-shrink:0;font-weight:700">' + ico + '</span><span>' + msg + '</span>';
    this.toastWrap.appendChild(t);
    setTimeout(function () { t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(function () { t.remove(); }, 320); }, 4500);
  }
};

/* ── HdBulk ─────────────────────────────────────────────────── */
var HdBulk = {
  bar: document.getElementById('hdBulkBar'),
  countEl: document.getElementById('hdBulkCount'),
  getChecked: function () {
    return Array.from(document.querySelectorAll('#hdListBody .hd-row-chk:checked'))
                .map(function (c) { return parseInt(c.value,10); });
  },
  update: function () {
    var n = this.getChecked().length;
    if (this.countEl) this.countEl.textContent = n;
    if (this.bar) this.bar.classList.toggle('d-none', n===0);
  },
  clear: function () {
    document.querySelectorAll('#hdListBody .hd-row-chk').forEach(function (c) { c.checked=false; });
    this.update();
  },
  doAction: function (action, value) {
    var ids = this.getChecked();
    if (!ids.length) { HdApp.toast('Zaznacz przynajmniej jedno zgłoszenie.','err'); return; }
    if (action==='set_status' && !value) { HdApp.toast('Wybierz status.','err'); return; }
    if (action==='close' && !confirm('Zamknąć ' + ids.length + ' zgłoszeń?')) return;
    var payload = { _csrf:CSRF, action:action, ids:ids };
    if (value !== undefined) payload.value = String(value);
    fetch(APP + '/helpdesk/api/bulk.php', {
      method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { HdApp.toast(d.error||'Błąd akcji.','err'); return; }
      HdApp.toast('Wykonano na ' + (d.affected||ids.length) + ' zgłoszeniach.','ok');
      HdBulk.clear(); HdApp.loadList(HdApp.formParams(), false);
    })
    .catch(function () { HdApp.toast('Błąd sieci.','err'); });
  }
};

HdApp.init();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
