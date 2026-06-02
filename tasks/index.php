<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');
task_areas_migrate();

try {
    $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    if (($_tm['value'] ?? '1') === '0') {
        flash_set('error', 'Moduł zadań jest wyłączony przez administratora.');
        header('Location: ' . APP_URL . '/index.php'); exit;
    }
} catch (\Throwable $e) {}

$user     = current_user();
$uid      = (int)$user['id'];
$is_admin = is_admin();

$workspaces = task_user_workspaces($uid);
$ws_id      = (int)($_GET['ws'] ?? 0);
if (!$ws_id && $workspaces) {
    $ws_id = (int)$workspaces[0]['id'];
}

$workspace = null;
$tasks_raw = [];
$lists_map = [];

if ($ws_id) {
    task_require_workspace_access($ws_id);
    $workspace = db_one("SELECT * FROM task_workspaces WHERE id=? AND is_active=1", [$ws_id]);
    if ($workspace) {
        // Mapa list obszaru (bez kolumny is_active — nie istnieje)
        $lists_in_ws = db_all(
            "SELECT id, name, color, is_done_state FROM task_lists WHERE workspace_id=? ORDER BY position",
            [$ws_id]
        );
        foreach ($lists_in_ws as $l) {
            $lists_map[$l['id']] = $l;
        }

        // Wszystkie zadania płasko
        $tasks_raw = db_all(
            "SELECT t.*,
                    tl.name  AS list_name,
                    tl.color AS list_color,
                    tl.is_done_state,
                    (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id)            AS assignee_count,
                    (SELECT COUNT(*) FROM task_subtasks   ts WHERE ts.task_id = t.id)             AS st_total,
                    (SELECT COUNT(*) FROM task_subtasks   ts WHERE ts.task_id = t.id AND ts.is_done=1) AS st_done
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.list_id
             WHERE t.workspace_id = ? AND t.deleted_at IS NULL
             ORDER BY
               CASE WHEN t.completed_at IS NULL AND t.due_date IS NOT NULL
                         AND t.due_date < date('now') THEN 0 ELSE 1 END,
               t.priority DESC,
               t.due_date  ASC NULLS LAST,
               t.created_at DESC",
            [$ws_id]
        );

        foreach ($tasks_raw as &$t) {
            $t['tags'] = db_all(
                "SELECT tt.* FROM task_task_tags ttt
                 JOIN task_tags tt ON tt.id = ttt.tag_id
                 WHERE ttt.task_id = ? ORDER BY tt.name",
                [$t['id']]
            );
            $t['assignees'] = db_all(
                "SELECT u.id, u.name FROM task_assignments ta
                 JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY u.name",
                [$t['id']]
            );
            if ($t['completed_at'] || $t['is_done_state']) {
                $t['_status'] = 'done';
            } elseif ((int)$t['assignee_count'] > 0) {
                $t['_status'] = 'taken';
            } else {
                $t['_status'] = 'open';
            }
            $t['_mine']   = in_array($uid, array_column($t['assignees'], 'id'), true);
            $t['_overdue']= $t['due_date'] && !$t['completed_at']
                            && strtotime($t['due_date']) < strtotime('today');
        }
        unset($t);
    }
}

$available_tags = [];
if ($ws_id) {
    $available_tags = db_all(
        "SELECT * FROM task_tags WHERE is_active=1 AND (workspace_id IS NULL OR workspace_id=?) ORDER BY name",
        [$ws_id]
    );
}

$my_role = $ws_id ? task_workspace_role($ws_id, $uid) : null;
$can_add = in_array($my_role, ['admin', 'editor'], true);

// Filtry
$filter_status   = $_GET['status'] ?? 'all';
$filter_priority = (int)($_GET['pri'] ?? 0);
$filter_tag      = (int)($_GET['tag'] ?? 0);
$filter_list     = (int)($_GET['list'] ?? 0);
$filter_area     = (int)($_GET['area'] ?? 0);
$filter_q        = trim($_GET['q'] ?? '');
$all_areas       = task_get_areas();

// Zastosuj filtry
$tasks = array_filter($tasks_raw, function ($t) use ($filter_status, $filter_priority, $filter_tag, $filter_list, $filter_area, $filter_q, $uid) {
    if ($filter_status === 'open'  && $t['_status'] !== 'open')  return false;
    if ($filter_status === 'taken' && $t['_status'] !== 'taken') return false;
    if ($filter_status === 'done'  && $t['_status'] !== 'done')  return false;
    if ($filter_status === 'mine'  && !$t['_mine'])              return false;
    if ($filter_priority && (int)$t['priority'] !== $filter_priority) return false;
    if ($filter_tag  && !in_array($filter_tag,  array_column($t['tags'], 'id'), true)) return false;
    if ($filter_list && (int)$t['list_id'] !== $filter_list)    return false;
    if ($filter_area && (int)($t['area_id'] ?? 0) !== $filter_area) return false;
    if ($filter_q    && mb_stripos($t['title'] . ' ' . ($t['description'] ?? ''), $filter_q) === false) return false;
    return true;
});

// Liczniki
$cnt = ['all' => count($tasks_raw), 'open' => 0, 'taken' => 0, 'done' => 0, 'mine' => 0];
foreach ($tasks_raw as $t) {
    $cnt[$t['_status']]++;
    if ($t['_mine']) $cnt['mine']++;
}

$PAGE_TITLE       = $workspace ? h($workspace['name']) : 'Zadania';
$PAGE_SUBTITLE    = 'Widok tabelaryczny';
$TASKS_BREADCRUMB = $workspace ? h($workspace['name']) : 'Zadania';
$TASKS_WS_ID      = $ws_id;
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
/* ── Tokeny ─────────────────────────────────────────────────────────────── */
:root {
  --tk-focus:   #2563eb;
  --tk-border:  #e2e8f0;
  --tk-bg-soft: #f8fafc;
  --tk-text:    #0f172a;
  --tk-muted:   #64748b;
  --tk-radius:  .5rem;
  --tk-open:    #16a34a;
  --tk-taken:   #2563eb;
  --tk-done:    #64748b;
}

/* Skip link */
.skip-link{position:absolute;top:-3rem;left:1rem;z-index:9999;background:var(--tk-focus);
  color:#fff;padding:.4rem .9rem;border-radius:0 0 .4rem .4rem;font-size:.85rem;
  font-weight:600;text-decoration:none;transition:top .15s}
.skip-link:focus{top:0}

/* SR announce */
#tk-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

/* ── Zakładki obszarów ──────────────────────────────────────────────────── */
.ws-tabs{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1rem}
.ws-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.32rem .75rem;
  border:1.5px solid #e2e8f0;border-radius:2rem;background:#fff;color:#475569;
  font-size:.82rem;font-weight:500;text-decoration:none;transition:all .12s}
.ws-tab:hover{border-color:#93c5fd;color:#1d4ed8}
.ws-tab.active{border-color:var(--tk-focus);background:var(--tk-focus);color:#fff}
.ws-tab:focus-visible{outline:2px solid var(--tk-focus);outline-offset:3px}
.ws-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* ── Pasek filtrów ──────────────────────────────────────────────────────── */
.tk-toolbar{
  display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;
  background:#fff;border:1px solid var(--tk-border);
  border-radius:var(--tk-radius);
  padding:.55rem .75rem;
  margin-bottom:.85rem;
}
.tk-sep{width:1px;height:1.3rem;background:#e2e8f0;flex-shrink:0}

/* Status pills */
.tk-pill{
  display:inline-flex;align-items:center;gap:.3rem;
  padding:.22rem .65rem;border-radius:2rem;
  font-size:.77rem;font-weight:600;
  border:1.5px solid transparent;
  text-decoration:none;
  background:#f1f5f9;color:var(--tk-muted);
  transition:all .12s;white-space:nowrap;
}
.tk-pill:hover{border-color:#94a3b8}
.tk-pill.active{background:var(--tk-text);color:#fff;border-color:var(--tk-text)}
.tk-pill[data-s="open"].active  {background:var(--tk-open);border-color:var(--tk-open)}
.tk-pill[data-s="taken"].active {background:var(--tk-taken);border-color:var(--tk-taken)}
.tk-pill[data-s="done"].active  {background:var(--tk-done);border-color:var(--tk-done)}
.tk-pill[data-s="mine"].active  {background:#7c3aed;border-color:#7c3aed}
.tk-pill:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.pill-n{font-size:.67rem;opacity:.75}

.tk-select{
  font-size:.8rem;padding:.25rem .55rem;border-radius:.4rem;
  border:1.5px solid #e2e8f0;background:#fff;color:var(--tk-text);cursor:pointer;
}
.tk-select:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}

.tk-search{
  font-size:.82rem;padding:.28rem .65rem;border-radius:.4rem;
  border:1.5px solid #e2e8f0;background:#fff;
  width:180px;min-width:120px;
}
.tk-search:focus{outline:2px solid var(--tk-focus);outline-offset:2px;border-color:transparent}

/* ── Tabela zadań ───────────────────────────────────────────────────────── */
.tk-wrap{
  background:#fff;
  border:1px solid var(--tk-border);
  border-radius:var(--tk-radius);
  overflow:hidden;
}

/* ── Bulk action bar ─────────────────────────────────────────────────── */
.tk-bulk-bar {
  display: none;
  align-items: center;
  gap: .5rem;
  padding: .55rem .85rem;
  background: #eff6ff;
  border-bottom: 1px solid #bfdbfe;
  flex-wrap: wrap;
}
.tk-bulk-bar.visible { display: flex; }
.tk-bulk-count {
  font-size: .82rem; font-weight: 700; color: var(--tk-taken);
  white-space: nowrap;
}
.tk-bulk-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .25rem .65rem; border-radius: .35rem;
  font-size: .78rem; font-weight: 600;
  border: 1.5px solid #dbeafe; background: #fff; color: #1d4ed8;
  cursor: pointer; white-space: nowrap; transition: all .1s;
}
.tk-bulk-btn:hover { background: #dbeafe; border-color: #93c5fd; }
.tk-bulk-btn:focus-visible { outline: 2px solid var(--tk-focus); }
.tk-bulk-btn.danger { color: #dc2626; border-color: #fecaca; }
.tk-bulk-btn.danger:hover { background: #fef2f2; border-color: #dc2626; }
.tk-bulk-sep { width: 1px; height: 1.2rem; background: #bfdbfe; flex-shrink: 0; }
.tk-bulk-close {
  margin-left: auto; background: none; border: none;
  color: #64748b; cursor: pointer; font-size: .85rem; padding: .1rem .3rem;
}
.tk-bulk-close:hover { color: #dc2626; }

/* Checkbox column */
.tk-table th.th-check, .tk-table td.td-check {
  width: 36px; text-align: center; padding: 0 .5rem;
}
.tk-row-check {
  width: 15px; height: 15px; cursor: pointer; accent-color: var(--tk-taken);
}
.tk-table tbody tr.selected { background: #eff6ff !important; }

/* Bulk select dropdown */
.tk-bulk-pri-sel, .tk-bulk-list-sel {
  font-size: .78rem; padding: .22rem .5rem; border-radius: .35rem;
  border: 1.5px solid #dbeafe; background: #fff; color: #1d4ed8; cursor: pointer;
}

.tk-table{
  width:100%;
  border-collapse:collapse;
  font-size:.84rem;
}

/* Nagłówek tabeli */
.tk-table thead th{
  background:var(--tk-bg-soft);
  font-size:.72rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.06em;
  color:var(--tk-muted);
  padding:.55rem .75rem;
  border-bottom:2px solid var(--tk-border);
  white-space:nowrap;
  text-align:left;
}
.tk-table thead th.th-center{text-align:center}
.tk-table thead th:first-child{padding-left:1rem}

/* Wiersze */
.tk-table tbody tr{
  border-bottom:1px solid #f1f5f9;
  cursor:pointer;
  transition:background .1s;
}
.tk-table tbody tr:last-child{border-bottom:none}
.tk-table tbody tr:hover{background:#f8fafc}
.tk-table tbody tr:focus-visible{
  outline:2px solid var(--tk-focus);outline-offset:-2px;
  background:#eff6ff;
}
.tk-table tbody tr.row-done{opacity:.65}

/* Komórki */
.tk-table td{
  padding:.6rem .75rem;
  vertical-align:middle;
  color:var(--tk-text);
}
.tk-table td:first-child{padding-left:1rem}

/* Pasek priorytetu (lewa krawędź) */
.tk-table tbody tr td:first-child{
  border-left:3px solid transparent;
}
.tk-table tbody tr[data-pri="4"] td:first-child{border-left-color:#dc2626}
.tk-table tbody tr[data-pri="3"] td:first-child{border-left-color:#f59e0b}
.tk-table tbody tr[data-pri="2"] td:first-child{border-left-color:#3b82f6}
.tk-table tbody tr[data-pri="1"] td:first-child{border-left-color:#94a3b8}

/* Tytuł */
.tk-title{
  font-weight:600;color:var(--tk-text);line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;
}
.tk-subtitle{font-size:.72rem;color:var(--tk-muted);margin-top:.1rem}

/* Status badge */
.tk-status{
  display:inline-flex;align-items:center;gap:.25rem;
  font-size:.7rem;font-weight:700;padding:.15rem .5rem;
  border-radius:2rem;white-space:nowrap;
}
.s-open  {background:#dcfce7;color:#15803d}
.s-taken {background:#dbeafe;color:#1d4ed8}
.s-done  {background:#f1f5f9;color:#64748b}

/* Priorytet dot */
.pri-dot{
  display:inline-flex;align-items:center;gap:.3rem;
  font-size:.75rem;font-weight:600;white-space:nowrap;
}
.pri-dot i{font-size:.75rem}

/* Tagi */
.tk-tags{display:flex;flex-wrap:wrap;gap:.2rem}
.tk-tag{font-size:.64rem;padding:.08rem .38rem;border-radius:2rem;font-weight:700}

/* Postęp podzadań */
.tk-prog-wrap{display:flex;align-items:center;gap:.4rem}
.tk-prog-track{width:60px;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;flex-shrink:0}
.tk-prog-fill{height:100%;border-radius:2px}
.tk-prog-label{font-size:.68rem;color:var(--tk-muted);white-space:nowrap}

/* Avatary */
.tk-av-stack{display:flex}
.tk-av{display:inline-flex;align-items:center;justify-content:center;
  width:24px;height:24px;border-radius:50%;
  font-size:.58rem;font-weight:700;color:#fff;
  border:2px solid #fff;flex-shrink:0}
.tk-av-stack .tk-av+.tk-av{margin-left:-6px}

/* Kolumna akcji */
.tk-actions{display:flex;align-items:center;gap:.35rem}
.btn-claim{
  font-size:.73rem;font-weight:600;padding:.2rem .6rem;border-radius:2rem;
  border:1.5px solid var(--tk-open);color:var(--tk-open);background:#fff;
  white-space:nowrap;transition:all .12s;
}
.btn-claim:hover,.btn-claim:focus-visible{background:var(--tk-open);color:#fff}
.btn-claim:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.btn-claim:disabled{opacity:.5;cursor:not-allowed}
.btn-unclaim{
  font-size:.73rem;font-weight:600;padding:.2rem .6rem;border-radius:2rem;
  border:1.5px solid #e2e8f0;color:var(--tk-muted);background:#fff;
  white-space:nowrap;transition:all .12s;
}
.btn-unclaim:hover,.btn-unclaim:focus-visible{border-color:#dc2626;color:#dc2626}
.btn-unclaim:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}

/* Stan pusty */
.tk-empty{
  text-align:center;padding:3.5rem 1rem;color:var(--tk-muted);
}
.tk-empty i{font-size:2rem;display:block;margin-bottom:.6rem;opacity:.3}

/* Offcanvas */
#taskOffcanvas{width:600px;max-width:96vw}
#taskOffcanvas .offcanvas-header{border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem}
#taskOffcanvas .offcanvas-body{padding:0;overflow-y:auto}

/* Kolumna termin */
.td-due{white-space:nowrap;font-size:.78rem}
.td-due.overdue{color:#dc2626;font-weight:600}
</style>

<div id="tk-sr" aria-live="polite" aria-atomic="true"></div>

<?php if (!$workspaces): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-table display-3 text-muted opacity-25 d-block mb-3" aria-hidden="true"></i>
    <h2 class="h5 text-muted">Brak dostępnych obszarów</h2>
    <?php if ($is_admin): ?>
    <p class="text-muted small">Utwórz pierwszy obszar, aby zacząć dodawać zadania.</p>
    <a href="<?= APP_URL ?>/admin/tasks_workspaces.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Utwórz obszar
    </a>
    <?php else: ?>
    <p class="text-muted small">Poproś administratora o przypisanie Cię do obszaru.</p>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<!-- ── Zakładki obszarów ─────────────────────────────────────────────────── -->
<nav class="ws-tabs" aria-label="Obszary robocze">
  <?php foreach ($workspaces as $ws): ?>
  <a href="?ws=<?= $ws['id'] ?>"
     class="ws-tab <?= $ws['id'] == $ws_id ? 'active' : '' ?>"
     <?= $ws['id'] == $ws_id ? 'aria-current="page"' : '' ?>>
    <span class="ws-dot" style="background:<?= h($ws['color']) ?>" aria-hidden="true"></span>
    <i class="bi <?= h($ws['icon']) ?>" aria-hidden="true"></i>
    <?= h($ws['name']) ?>
    <span class="opacity-60" aria-label="<?= (int)$ws['task_count'] ?> zadań"><?= (int)$ws['task_count'] ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<?php if ($workspace): ?>

<!-- ── Pasek narzędzi ────────────────────────────────────────────────────── -->
<div class="tk-toolbar" role="search" aria-label="Filtry i wyszukiwanie">

  <!-- Szukaj -->
  <div>
    <label class="visually-hidden" for="tk-q">Szukaj zadania</label>
    <input type="search" id="tk-q" class="tk-search"
           placeholder="Szukaj…"
           value="<?= h($filter_q) ?>"
           aria-label="Szukaj zadania po tytule lub opisie"
           oninput="tkSearch(this.value)">
  </div>

  <div class="tk-sep" role="separator" aria-hidden="true"></div>

  <!-- Status -->
  <div class="d-flex gap-1 flex-wrap" role="group" aria-label="Filtr statusu">
    <?php
    $sp = [
      'all'   => ['Wszystkie', 'bi-list-ul'],
      'open'  => ['Wolne',     'bi-circle'],
      'taken' => ['Zajęte',    'bi-person-fill'],
      'done'  => ['Ukończone', 'bi-check-circle-fill'],
      'mine'  => ['Moje',      'bi-person-check-fill'],
    ];
    foreach ($sp as $k => [$lbl, $ico]):
      $url = '?' . http_build_query(array_merge($_GET, ['ws'=>$ws_id,'status'=>$k,'q'=>'']));
    ?>
    <a href="<?= $url ?>"
       class="tk-pill <?= $filter_status===$k?'active':'' ?>"
       data-s="<?= $k ?>"
       aria-pressed="<?= $filter_status===$k?'true':'false' ?>">
      <i class="bi <?= $ico ?>" aria-hidden="true"></i><?= $lbl ?>
      <span class="pill-n"><?= $cnt[$k] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="tk-sep" role="separator" aria-hidden="true"></div>

  <!-- Priorytet -->
  <label class="visually-hidden" for="tk-pri">Priorytet</label>
  <select id="tk-pri" class="tk-select" onchange="tkFilter('pri',this.value)">
    <option value="0" <?= !$filter_priority?'selected':'' ?>>Każdy priorytet</option>
    <option value="4" <?= $filter_priority==4?'selected':'' ?>>🔴 Krytyczny</option>
    <option value="3" <?= $filter_priority==3?'selected':'' ?>>🟡 Wysoki</option>
    <option value="2" <?= $filter_priority==2?'selected':'' ?>>🔵 Normalny</option>
    <option value="1" <?= $filter_priority==1?'selected':'' ?>>⚪ Niski</option>
  </select>

  <!-- Kategoria (lista) -->
  <?php if (count($lists_map) > 1): ?>
  <label class="visually-hidden" for="tk-list">Kategoria</label>
  <select id="tk-list" class="tk-select" onchange="tkFilter('list',this.value)">
    <option value="0" <?= !$filter_list?'selected':'' ?>>Każda kategoria</option>
    <?php foreach ($lists_map as $l): ?>
    <option value="<?= $l['id'] ?>" <?= $filter_list==$l['id']?'selected':'' ?>><?= h($l['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <!-- Tag -->
  <?php if ($available_tags): ?>
  <label class="visually-hidden" for="tk-tag">Tag</label>
  <select id="tk-tag" class="tk-select" onchange="tkFilter('tag',this.value)">
    <option value="0" <?= !$filter_tag?'selected':'' ?>>Każdy tag</option>
    <?php foreach ($available_tags as $tg): ?>
    <option value="<?= $tg['id'] ?>" <?= $filter_tag==$tg['id']?'selected':'' ?>><?= h($tg['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <!-- Obszar -->
  <?php if ($all_areas): ?>
  <label class="visually-hidden" for="tk-area">Obszar</label>
  <select id="tk-area" class="tk-select" onchange="tkFilter('area',this.value)">
    <option value="0" <?= !$filter_area?'selected':'' ?>>Każdy obszar</option>
    <?php foreach ($all_areas as $ar): ?>
    <option value="<?= $ar['id'] ?>" <?= $filter_area==$ar['id']?'selected':'' ?>>
      <?= h($ar['name']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <!-- Dodaj zadanie + Usuń obszar -->
  <?php if ($can_add): ?>
  <div class="ms-auto d-flex gap-2 align-items-center">
    <button type="button"
            class="btn btn-primary btn-sm"
            onclick="openAddModal()"
            aria-label="Dodaj nowe zadanie">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowe zadanie
    </button>
    <button type="button"
            class="btn btn-outline-danger btn-sm"
            onclick="openDeleteWsModal()"
            aria-haspopup="dialog"
            aria-label="Usuń obszar <?= h($workspace['name']) ?>">
      <i class="bi bi-trash3" aria-hidden="true"></i>
    </button>
  </div>
  <?php endif; ?>

</div>

<!-- ── Tabela zadań ──────────────────────────────────────────────────────── -->
<div class="tk-wrap">

  <!-- Pasek zbiorczych akcji -->
  <div class="tk-bulk-bar" id="tk-bulk-bar" role="toolbar" aria-label="Zbiorcze akcje">
    <span class="tk-bulk-count" id="tk-bulk-count" aria-live="polite">0 zaznaczonych</span>
    <div class="tk-bulk-sep"></div>

    <button class="tk-bulk-btn" onclick="bulkAction('assign_me')"
            aria-label="Przypisz zaznaczone zadania do siebie">
      <i class="bi bi-person-check" aria-hidden="true"></i>Przypisz do mnie
    </button>

    <button class="tk-bulk-btn" onclick="bulkAction('complete')"
            aria-label="Oznacz zaznaczone jako ukończone">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>Zakończ
    </button>

    <label class="visually-hidden" for="tk-bulk-pri">Zmień priorytet</label>
    <select id="tk-bulk-pri" class="tk-bulk-pri-sel"
            onchange="if(this.value){bulkAction('priority',{priority:parseInt(this.value)});this.value=''}"
            aria-label="Zmień priorytet zaznaczonych zadań">
      <option value="">⚑ Priorytet…</option>
      <option value="4">🔴 Krytyczny</option>
      <option value="3">🟡 Wysoki</option>
      <option value="2">🔵 Normalny</option>
      <option value="1">⚪ Niski</option>
    </select>

    <?php if (!empty($lists_map)): ?>
    <label class="visually-hidden" for="tk-bulk-list">Przenieś do listy</label>
    <select id="tk-bulk-list" class="tk-bulk-list-sel"
            onchange="if(this.value){bulkAction('move',{list_id:parseInt(this.value)});this.value=''}"
            aria-label="Przenieś zaznaczone zadania do wybranej kolumny">
      <option value="">↦ Przenieś do…</option>
      <?php foreach ($lists_map as $l): ?>
      <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <?php if ($can_add): ?>
    <div class="tk-bulk-sep"></div>
    <button class="tk-bulk-btn danger" onclick="bulkAction('delete')"
            aria-label="Usuń zaznaczone zadania">
      <i class="bi bi-trash3" aria-hidden="true"></i>Usuń
    </button>
    <?php endif; ?>

    <button class="tk-bulk-close" onclick="bulkClear()"
            aria-label="Anuluj zaznaczenie">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>

  <div style="overflow-x:auto">
    <table class="tk-table"
           id="task-table"
           role="grid"
           aria-label="Zadania obszaru <?= h($workspace['name']) ?>"
           aria-rowcount="<?= count($tasks) ?>">
      <thead>
        <tr>
          <th scope="col" class="th-check">
            <input type="checkbox" class="tk-row-check" id="tk-check-all"
                   onchange="bulkToggleAll(this)"
                   aria-label="Zaznacz wszystkie zadania">
          </th>
          <th scope="col" style="width:3%" aria-label="Priorytet i tytuł">Zadanie</th>
          <th scope="col" style="width:10%" class="th-center">Status</th>
          <th scope="col" style="width:10%">Kategoria</th>
          <th scope="col" style="width:8%"  class="th-center">Priorytet</th>
          <th scope="col" style="width:8%">Termin</th>
          <th scope="col" style="width:10%">Postęp</th>
          <th scope="col" style="width:12%">Tagi</th>
          <th scope="col" style="width:10%">Przypisani</th>
          <th scope="col" style="width:9%"  class="th-center">Akcja</th>
        </tr>
      </thead>
      <tbody id="tk-tbody">

      <?php if (!$tasks): ?>
      <tr>
        <td colspan="9">
          <div class="tk-empty">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <p class="fw-semibold mb-1">Brak zadań spełniających kryteria</p>
            <p class="small mb-0">Zmień filtry lub dodaj nowe zadanie.</p>
          </div>
        </td>
      </tr>

      <?php else: foreach ($tasks as $task):
        $pri_meta = [
          4 => ['🔴','Krytyczny','#dc2626'],
          3 => ['🟡','Wysoki',   '#f59e0b'],
          2 => ['🔵','Normalny', '#3b82f6'],
          1 => ['⚪','Niski',    '#94a3b8'],
        ][$task['priority']] ?? ['⚪','Normalny','#94a3b8'];

        $st_total = (int)$task['st_total'];
        $st_done  = (int)$task['st_done'];
        $st_pct   = $st_total ? round($st_done/$st_total*100) : 0;

        $status_info = match($task['_status']) {
          'open'  => ['s-open',  'Wolne',     'bi-circle'],
          'taken' => ['s-taken', 'Zajęte',    'bi-person-fill'],
          'done'  => ['s-done',  'Ukończone', 'bi-check-circle-fill'],
          default => ['s-open',  'Wolne',     'bi-circle'],
        };

        $row_label = h($task['title'])
          . ', status: ' . $status_info[1]
          . ', priorytet: ' . $pri_meta[1]
          . ($task['_overdue'] ? ', po terminie' : '');
      ?>
      <tr class="<?= $task['_status']==='done'?'row-done':'' ?>"
          data-pri="<?= $task['priority'] ?>"
          data-task-id="<?= $task['id'] ?>"
          tabindex="0"
          role="row"
          aria-label="<?= $row_label ?>"
          onclick="if(!event.target.closest('td.td-check')&&!event.target.closest('td:last-child'))openTask(<?= $task['id'] ?>)"
          onkeydown="if((event.key==='Enter'||event.key===' ')&&!event.target.closest('input'))openTask(<?= $task['id'] ?>)">

        <!-- Checkbox -->
        <td class="td-check" onclick="event.stopPropagation()">
          <input type="checkbox"
                 class="tk-row-check tk-row-select"
                 data-id="<?= $task['id'] ?>"
                 onchange="bulkOnCheck(this)"
                 aria-label="Zaznacz zadanie: <?= h($task['title']) ?>">
        </td>

        <!-- Zadanie: tytuł + podtytuł -->
        <td>
          <div class="tk-title"><?= h($task['title']) ?></div>
          <?php if ($task['description']): ?>
          <div class="tk-subtitle"><?= h(mb_substr(strip_tags($task['description']),0,80)) ?></div>
          <?php endif; ?>
        </td>

        <!-- Status -->
        <td class="th-center" style="text-align:center">
          <span class="tk-status <?= $status_info[0] ?>">
            <i class="bi <?= $status_info[2] ?>" aria-hidden="true"></i>
            <?= $status_info[1] ?>
          </span>
        </td>

        <!-- Kategoria -->
        <td>
          <?php $lc = $task['list_color'] ?: '#94a3b8'; ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= h($lc) ?>;flex-shrink:0" aria-hidden="true"></span>
            <?= h($task['list_name']) ?>
          </span>
        </td>

        <!-- Priorytet -->
        <td style="text-align:center">
          <span class="pri-dot" style="color:<?= $pri_meta[2] ?>">
            <span aria-hidden="true"><?= $pri_meta[0] ?></span>
            <span class="visually-hidden"><?= $pri_meta[1] ?></span>
            <span aria-hidden="true" style="font-size:.74rem;color:var(--tk-muted)"><?= $pri_meta[1] ?></span>
          </span>
        </td>

        <!-- Termin -->
        <td>
          <?php if ($task['due_date']): ?>
          <span class="td-due <?= $task['_overdue']?'overdue':'' ?>"
                aria-label="Termin: <?= h($task['due_date']) ?><?= $task['_overdue']?' (po terminie)':'' ?>">
            <?php if ($task['_overdue']): ?>
            <i class="bi bi-alarm me-1" aria-hidden="true"></i>
            <?php else: ?>
            <i class="bi bi-calendar3 me-1 text-muted" aria-hidden="true"></i>
            <?php endif; ?>
            <?= h(date('d.m.Y', strtotime($task['due_date']))) ?>
          </span>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Postęp podzadań -->
        <td>
          <?php if ($st_total > 0): ?>
          <div class="tk-prog-wrap"
               aria-label="Podzadania: <?= $st_done ?>/<?= $st_total ?>">
            <div class="tk-prog-track"
                 role="progressbar"
                 aria-valuenow="<?= $st_pct ?>"
                 aria-valuemin="0" aria-valuemax="100">
              <div class="tk-prog-fill <?= $st_done===$st_total?'bg-success':'bg-primary' ?>"
                   style="width:<?= $st_pct ?>%"></div>
            </div>
            <span class="tk-prog-label"><?= $st_done ?>/<?= $st_total ?></span>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Tagi -->
        <td>
          <?php if ($task['tags']): ?>
          <div class="tk-tags">
            <?php foreach (array_slice($task['tags'],0,3) as $tag): ?>
            <span class="tk-tag"
                  style="background:<?= h($tag['color']) ?>;color:<?= h($tag['text_color']) ?>">
              <?= h($tag['name']) ?>
            </span>
            <?php endforeach; ?>
            <?php if (count($task['tags'])>3): ?>
            <span class="tk-tag" style="background:#f1f5f9;color:#64748b">
              +<?= count($task['tags'])-3 ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Przypisani -->
        <td>
          <?php if ($task['assignees']): ?>
          <div class="tk-av-stack" aria-label="Przypisani: <?= h(implode(', ', array_column($task['assignees'],'name'))) ?>">
            <?php foreach (array_slice($task['assignees'],0,4) as $a): ?>
            <?= task_avatar_initials($a['name'], '#2563eb', '#fff') ?>
            <?php endforeach; ?>
            <?php if (count($task['assignees'])>4): ?>
            <span class="tk-av" style="background:#64748b"
                  aria-label="+<?= count($task['assignees'])-4 ?> więcej">
              +<?= count($task['assignees'])-4 ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">Brak</span>
          <?php endif; ?>
        </td>

        <!-- Akcja (klik zatrzymuje propagację) -->
        <td style="text-align:center" onclick="event.stopPropagation()">
          <?php if ($task['_status'] !== 'done'): ?>
          <div class="tk-actions justify-content-center">
            <?php if ($task['_mine']): ?>
            <button type="button"
                    class="btn-unclaim"
                    onclick="claimTask(<?= $task['id'] ?>,'remove',this)"
                    aria-label="Oddaj zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-person-dash me-1" aria-hidden="true"></i>Oddaj
            </button>
            <?php elseif ($task['_status'] === 'open'): ?>
            <button type="button"
                    class="btn-claim"
                    onclick="claimTask(<?= $task['id'] ?>,'add',this)"
                    aria-label="Weź zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Weź
            </button>
            <?php else: ?>
            <span class="text-muted" style="font-size:.73rem">—</span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.73rem">—</span>
          <?php endif; ?>
        </td>

      </tr>
      <?php endforeach; endif; ?>

      </tbody>
    </table>
  </div>

  <!-- Stopka z licznikiem -->
  <?php if ($tasks): ?>
  <div class="px-3 py-2 border-top" style="background:var(--tk-bg-soft);font-size:.75rem;color:var(--tk-muted)">
    Pokazano <strong><?= count($tasks) ?></strong> z <strong><?= count($tasks_raw) ?></strong> zadań
    <?php if ($filter_q || $filter_priority || $filter_tag || $filter_list || $filter_status !== 'all'): ?>
    · <a href="?ws=<?= $ws_id ?>" class="text-muted">Wyczyść filtry</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php endif; /* workspace */ ?>
<?php endif; /* workspaces */ ?>

<!-- ── Offcanvas: szczegóły zadania ─────────────────────────────────────── -->
<div class="offcanvas offcanvas-end shadow-lg"
     tabindex="-1"
     id="taskOffcanvas"
     role="dialog"
     aria-labelledby="taskOffcanvasLabel"
     aria-modal="true">
  <div class="offcanvas-header">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body"
       id="taskOffcanvasBody"
       aria-live="polite"
       aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: dodaj zadanie ─────────────────────────────────────────────── -->
<?php if ($can_add && $workspace): ?>
<div class="modal fade" id="addTaskModal" tabindex="-1"
     aria-labelledby="addTaskModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="addTaskModalLabel">
          <i class="bi bi-plus-circle me-1 text-primary" aria-hidden="true"></i>Nowe zadanie
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Zamknij formularz dodawania zadania"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="at-title">
            Tytuł <span class="text-danger" aria-hidden="true">*</span>
            <span class="visually-hidden">(wymagane)</span>
          </label>
          <input type="text" id="at-title" class="form-control"
                 placeholder="Co trzeba zrobić?" maxlength="255" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="at-desc">
            Opis <span class="text-muted fw-normal">(opcjonalnie)</span>
          </label>
          <textarea id="at-desc" class="form-control" rows="3"
                    placeholder="Szczegóły zadania…"></textarea>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold small" for="at-list">Kategoria</label>
            <select id="at-list" class="form-select form-select-sm">
              <?php foreach ($lists_map as $l): ?>
              <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold small" for="at-priority">Priorytet</label>
            <select id="at-priority" class="form-select form-select-sm">
              <option value="1">⚪ Niski</option>
              <option value="2" selected>🔵 Normalny</option>
              <option value="3">🟡 Wysoki</option>
              <option value="4">🔴 Krytyczny</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold small" for="at-due">Termin</label>
            <input type="date" id="at-due" class="form-control form-control-sm">
          </div>
          <?php if ($all_areas): ?>
          <div class="col-12">
            <label class="form-label fw-semibold small" for="at-area">Obszar</label>
            <select id="at-area" class="form-select form-select-sm">
              <option value="0">— brak obszaru —</option>
              <?php foreach ($all_areas as $ar): ?>
              <option value="<?= $ar['id'] ?>"><?= h($ar['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <div id="at-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm"
                id="at-submit" onclick="submitAddTask()">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zadanie
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const CSRF     = <?= json_encode(csrf_token()) ?>;
const WS_ID    = <?= (int)$ws_id ?>;
const CAN_EDIT = <?= $can_add ? 'true' : 'false' ?>;
const BASE     = <?= json_encode(rtrim(APP_URL,'/')) ?>;

/* SR announce */
function tkAnnounce(msg) {
    const el = document.getElementById('tk-sr');
    if (!el) return;
    el.textContent = '';
    setTimeout(() => { el.textContent = msg; }, 50);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* Otwórz offcanvas z detalami */
function openTask(taskId) {
    const body = document.getElementById('taskOffcanvasBody');
    body.innerHTML = '<div class="text-center py-5 text-muted">'
        + '<div class="spinner-border spinner-border-sm" role="status">'
        + '<span class="visually-hidden">Ładowanie…</span></div>'
        + '<div class="mt-2 small">Ładowanie…</div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(
        document.getElementById('taskOffcanvas')
    ).show();
    fetch(BASE + '/tasks/detail.php?id=' + taskId)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => {
            body.innerHTML = '';
            body.appendChild(document.createRange().createContextualFragment(html));
        })
        .catch(err => {
            body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania: '
                + escHtml(String(err)) + '</div>';
        });
}

/* Weź / Oddaj */
function claimTask(taskId, action, btn) {
    btn.disabled = true;
    btn.textContent = action === 'add' ? 'Biorę…' : 'Oddaję…';
    fetch(BASE + '/tasks/api/claim.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, task_id: taskId, action: action})
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            tkAnnounce(action === 'add' ? 'Zadanie przypisane.' : 'Zadanie oddane.');
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = action === 'add'
                ? '<i class="bi bi-hand-index me-1"></i>Weź'
                : '<i class="bi bi-person-dash me-1"></i>Oddaj';
            alert(r.error || 'Błąd.');
        }
    })
    .catch(() => { btn.disabled = false; alert('Błąd połączenia.'); });
}

/* Filtry URL */
function tkFilter(key, val) {
    const url = new URL(window.location.href);
    if (!val || val === '0') url.searchParams.delete(key);
    else url.searchParams.set(key, val);
    window.location.href = url.toString();
}

/* Wyszukiwanie live (client-side po tekście) */
let _searchTimer;
function tkSearch(q) {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        const rows = document.querySelectorAll('#tk-tbody tr[data-task-id]');
        const lq   = q.toLowerCase();
        let vis = 0;
        rows.forEach(tr => {
            const title = tr.querySelector('.tk-title')?.textContent?.toLowerCase() || '';
            const sub   = tr.querySelector('.tk-subtitle')?.textContent?.toLowerCase() || '';
            const match = !lq || title.includes(lq) || sub.includes(lq);
            tr.style.display = match ? '' : 'none';
            if (match) vis++;
        });
        tkAnnounce('Znaleziono ' + vis + ' zadań.');
    }, 200);
}

/* Dodaj zadanie */
function openAddModal() {
    const el = document.getElementById('addTaskModal');
    if (!el) return;
    document.getElementById('at-title').value    = '';
    document.getElementById('at-desc').value     = '';
    document.getElementById('at-due').value      = '';
    document.getElementById('at-priority').value = '2';
    document.getElementById('at-error').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(el).show();
    setTimeout(() => document.getElementById('at-title').focus(), 350);
}

function submitAddTask() {
    const titleEl = document.getElementById('at-title');
    const title   = titleEl.value.trim();
    if (!title) { titleEl.classList.add('is-invalid'); titleEl.focus(); return; }
    titleEl.classList.remove('is-invalid');

    const btn = document.getElementById('at-submit');
    btn.disabled    = true;
    btn.textContent = 'Dodawanie…';

    fetch(BASE + '/tasks/api/task.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            _csrf:        CSRF,
            action:       'create',
            title:        title,
            description:  document.getElementById('at-desc').value,
            due_date:     document.getElementById('at-due').value || null,
            priority:     parseInt(document.getElementById('at-priority').value),
            list_id:      parseInt(document.getElementById('at-list').value),
            area_id:      parseInt(document.getElementById('at-area')?.value || '0') || null,
            workspace_id: WS_ID
        })
    })
    .then(r => r.json())
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zadanie';
        if (r.ok) {
            bootstrap.Modal.getInstance(document.getElementById('addTaskModal')).hide();
            tkAnnounce('Zadanie dodane.');
            location.reload();
        } else {
            const err = document.getElementById('at-error');
            err.textContent = r.error || 'Błąd zapisu.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled = false;
        const err = document.getElementById('at-error');
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
}

// ── Zbiorcze akcje ────────────────────────────────────────────────────────
function bulkSelected() {
    return Array.from(document.querySelectorAll('.tk-row-select:checked')).map(el => parseInt(el.dataset.id));
}

function bulkUpdateBar() {
    const ids  = bulkSelected();
    const bar  = document.getElementById('tk-bulk-bar');
    const cnt  = document.getElementById('tk-bulk-count');
    const all  = document.getElementById('tk-check-all');
    const rows = document.querySelectorAll('.tk-row-select');
    if (bar)  bar.classList.toggle('visible', ids.length > 0);
    if (cnt)  cnt.textContent = ids.length + ' zaznaczon' + (ids.length === 1 ? 'e' : 'ych');
    if (all)  all.indeterminate = ids.length > 0 && ids.length < rows.length;
    if (all)  all.checked = ids.length === rows.length && rows.length > 0;
    document.querySelectorAll('#tk-tbody tr').forEach(tr => {
        const cb = tr.querySelector('.tk-row-select');
        tr.classList.toggle('selected', cb?.checked || false);
    });
}

function bulkOnCheck(cb) {
    bulkUpdateBar();
}

function bulkToggleAll(masterCb) {
    document.querySelectorAll('.tk-row-select').forEach(cb => { cb.checked = masterCb.checked; });
    bulkUpdateBar();
}

function bulkClear() {
    document.querySelectorAll('.tk-row-select,.tk-check-all').forEach(cb => { cb.checked = false; });
    const all = document.getElementById('tk-check-all');
    if (all) { all.checked = false; all.indeterminate = false; }
    bulkUpdateBar();
}

function bulkAction(action, extra = {}) {
    const ids = bulkSelected();
    if (!ids.length) return;

    const labels = { assign_me:'Przypisz do mnie', unassign_me:'Odpnij mnie',
                     priority:'Zmień priorytet', move:'Przenieś', complete:'Zakończ', delete:'Usuń' };

    if (action === 'delete') {
        if (!confirm(`Usunąć ${ids.length} zadań? Tej operacji nie można cofnąć.`)) return;
    }

    fetch(BASE + '/tasks/api/bulk.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ _csrf: CSRF, action, task_ids: ids, ...extra })
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            tkAnnounce((labels[action] || action) + ': ' + (r.data?.affected || ids.length) + ' zadań.');
            bulkClear();
            location.reload();
        } else {
            alert(r.error || 'Błąd zbiorczej akcji.');
        }
    })
    .catch(() => alert('Błąd połączenia.'));
}

const atTitle = document.getElementById('at-title');
if (atTitle) atTitle.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); submitAddTask(); }
});
</script>

<?php if ($can_add && $workspace): ?>
<!-- ── Modal: Usuń obszar ────────────────────────────────────────────────── -->
<div id="del-ws-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
            z-index:9500;backdrop-filter:blur(2px)"
     aria-hidden="true"
     onclick="closeDeleteWsModal()"></div>

<div id="del-ws-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="del-ws-title"
     aria-describedby="del-ws-desc"
     tabindex="-1"
     style="display:none;position:fixed;top:50%;left:50%;
            transform:translate(-50%,-50%);
            z-index:9600;width:380px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;
            box-shadow:0 20px 48px rgba(0,0,0,.25);overflow:hidden">

  <!-- Nagłówek -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:.8rem 1.1rem;border-bottom:1px solid #fee2e2;background:#fef2f2">
    <h2 id="del-ws-title"
        style="font-size:.92rem;font-weight:700;margin:0;color:#dc2626;
               display:flex;align-items:center;gap:.4rem">
      <i class="bi bi-trash3-fill" aria-hidden="true"></i>
      Usuń obszar roboczy
    </h2>
    <button type="button" class="btn-close"
            id="del-ws-close-btn"
            onclick="closeDeleteWsModal()"
            aria-label="Anuluj usuwanie obszaru"
            style="font-size:.8rem"></button>
  </div>

  <!-- Treść -->
  <div style="padding:.95rem 1.1rem">
    <p id="del-ws-desc" style="font-size:.85rem;color:#374151;margin-bottom:.6rem;line-height:1.5">
      Usuwasz obszar roboczy:<br>
      <strong style="font-size:.95rem;color:#0f172a"><?= h($workspace['name']) ?></strong>
    </p>

    <!-- Liczniki -->
    <?php
    $ws_task_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",
        [$ws_id]
    )['n'] ?? 0);
    $ws_member_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM task_workspace_members WHERE workspace_id=?",
        [$ws_id]
    )['n'] ?? 0);
    ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;
                padding:.65rem .85rem;margin-bottom:.75rem;font-size:.82rem">
      <div style="display:flex;justify-content:space-between;padding:.2rem 0;border-bottom:1px solid #f1f5f9">
        <span style="color:#64748b">Zadania</span>
        <strong style="color:<?= $ws_task_count > 0 ? '#dc2626' : '#64748b' ?>">
          <?= $ws_task_count ?>
        </strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:.2rem 0">
        <span style="color:#64748b">Członkowie</span>
        <strong style="color:#64748b"><?= $ws_member_count ?></strong>
      </div>
    </div>

    <?php if ($ws_task_count > 0): ?>
    <div style="display:flex;gap:.5rem;align-items:flex-start;padding:.5rem .65rem;
                background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;
                font-size:.79rem;color:#92400e;margin-bottom:.75rem">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span>Wszystkie <strong><?= $ws_task_count ?> zadań</strong> zostaną trwale usunięte. Tej operacji nie można cofnąć.</span>
    </div>
    <?php endif; ?>

    <!-- Potwierdzenie -->
    <div style="margin-bottom:.65rem">
      <label for="del-ws-confirm"
             style="font-size:.79rem;font-weight:600;display:block;margin-bottom:.3rem;color:#374151">
        Wpisz nazwę obszaru aby potwierdzić:
        <code style="background:#f1f5f9;padding:.05rem .3rem;border-radius:.25rem;
                     font-size:.82rem;color:#dc2626"><?= h($workspace['name']) ?></code>
      </label>
      <input type="text"
             id="del-ws-confirm"
             class="form-control form-control-sm"
             autocomplete="off"
             autocorrect="off"
             spellcheck="false"
             placeholder="wpisz dokładną nazwę…"
             aria-required="true"
             oninput="delWsCheckConfirm(this)"
             onkeydown="if(event.key==='Enter'){event.preventDefault();delWsSubmit()}">
    </div>

    <div id="del-ws-err" class="alert alert-danger small py-2 d-none" role="alert"></div>
  </div>

  <!-- Stopka -->
  <div style="display:flex;justify-content:flex-end;gap:.5rem;
              padding:.65rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            onclick="closeDeleteWsModal()">Anuluj</button>
    <button type="button"
            class="btn btn-danger btn-sm"
            id="del-ws-submit-btn"
            disabled
            onclick="delWsSubmit()"
            aria-label="Potwierdź i usuń obszar <?= h($workspace['name']) ?>">
      <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Usuń bezpowrotnie
    </button>
  </div>
</div>

<script>
const _WS_NAME = <?= json_encode($workspace['name']) ?>;
const _WS_ID   = <?= (int)$ws_id ?>;
let   _delWsPrevFocus = null;

window.openDeleteWsModal = function() {
    const modal    = document.getElementById('del-ws-modal');
    const backdrop = document.getElementById('del-ws-backdrop');
    const inp      = document.getElementById('del-ws-confirm');
    const btn      = document.getElementById('del-ws-submit-btn');
    const err      = document.getElementById('del-ws-err');

    inp.value      = '';
    btn.disabled   = true;
    err.classList.add('d-none');

    _delWsPrevFocus       = document.activeElement;
    backdrop.style.display = 'block';
    modal.style.display    = 'block';
    backdrop.removeAttribute('aria-hidden');

    requestAnimationFrame(() => inp.focus());
    modal.addEventListener('keydown', _delWsTrapFocus);
};

window.closeDeleteWsModal = function() {
    document.getElementById('del-ws-modal').style.display    = 'none';
    document.getElementById('del-ws-backdrop').style.display = 'none';
    document.getElementById('del-ws-backdrop').setAttribute('aria-hidden', 'true');
    document.getElementById('del-ws-modal').removeEventListener('keydown', _delWsTrapFocus);
    (_delWsPrevFocus || document.querySelector('[onclick="openDeleteWsModal()"]'))?.focus();
    _delWsPrevFocus = null;
};

function _delWsTrapFocus(e) {
    if (e.key === 'Escape') { e.preventDefault(); closeDeleteWsModal(); return; }
    if (e.key !== 'Tab') return;
    const modal    = document.getElementById('del-ws-modal');
    const focusable = Array.from(modal.querySelectorAll(
        'button:not([disabled]),input,[tabindex]:not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
    else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
}

window.delWsCheckConfirm = function(inp) {
    const btn = document.getElementById('del-ws-submit-btn');
    btn.disabled = inp.value.trim() !== _WS_NAME;
    inp.style.borderColor = '';
};

window.delWsSubmit = function() {
    const inp = document.getElementById('del-ws-confirm');
    const btn = document.getElementById('del-ws-submit-btn');
    const err = document.getElementById('del-ws-err');

    if (inp.value.trim() !== _WS_NAME) {
        inp.style.borderColor = '#dc2626';
        inp.focus();
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Usuwam…';
    err.classList.add('d-none');

    fetch(BASE + '/tasks/api/delete_workspace.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ _csrf: CSRF, workspace_id: _WS_ID })
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            closeDeleteWsModal();
            // Przekieruj do dashboardu po usunięciu
            window.location.href = BASE + '/tasks/dashboard.php';
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Usuń bezpowrotnie';
            err.textContent = r.error || 'Błąd usuwania.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Usuń bezpowrotnie';
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
};
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
