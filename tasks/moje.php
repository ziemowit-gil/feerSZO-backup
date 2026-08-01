<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$user = current_user();
$uid  = (int)$user['id'];

function _mq(string $sql, array $p = []): int {
    try { return (int)(db_one($sql, $p)['n'] ?? 0); } catch (\Throwable $e) { return 0; }
}

// ── KPI ────────────────────────────────────────────────────────────────────
$kpi_active   = _mq("SELECT COUNT(*) AS n FROM tasks t
    JOIN task_assignments ta ON ta.task_id=t.id
    WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL", [$uid]);

$kpi_overdue  = _mq("SELECT COUNT(*) AS n FROM tasks t
    JOIN task_assignments ta ON ta.task_id=t.id
    WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL
      AND t.due_date IS NOT NULL AND date(t.due_date) < date('now')", [$uid]);

$kpi_today    = _mq("SELECT COUNT(*) AS n FROM tasks t
    JOIN task_assignments ta ON ta.task_id=t.id
    WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL
      AND date(t.due_date) = date('now')", [$uid]);

$kpi_done_w   = _mq("SELECT COUNT(*) AS n FROM tasks t
    JOIN task_assignments ta ON ta.task_id=t.id
    WHERE ta.user_id=? AND t.completed_at IS NOT NULL AND t.deleted_at IS NULL
      AND t.completed_at >= date('now','-7 days')", [$uid]);

// ── Moje aktywne zadania ────────────────────────────────────────────────────
$my_tasks = db_all(
    "SELECT t.*, tl.name AS list_name, tl.color AS list_color, tw.name AS ws_name
     FROM tasks t
     JOIN task_assignments ta ON ta.task_id=t.id
     JOIN task_lists tl ON tl.id=t.list_id
     JOIN task_workspaces tw ON tw.id=t.workspace_id
     WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL
     ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
     LIMIT 40",
    [$uid]
);

// ── Wolne zadania (bez przypisania) w moich obszarach ─────────────────────
$available = db_all(
    "SELECT t.*, tl.name AS list_name, tl.color AS list_color, tw.name AS ws_name
     FROM tasks t
     JOIN task_lists tl ON tl.id=t.list_id
     JOIN task_workspaces tw ON tw.id=t.workspace_id
     JOIN task_workspace_members twm ON twm.workspace_id=t.workspace_id AND twm.user_id=?
     WHERE t.completed_at IS NULL AND t.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM task_assignments ta2 WHERE ta2.task_id=t.id)
     ORDER BY t.priority DESC, t.created_at DESC
     LIMIT 12",
    [$uid]
);

// ── Ostatnio ukończone ──────────────────────────────────────────────────────
$recent_done = db_all(
    "SELECT t.*, tl.name AS list_name, tl.color AS list_color, tw.name AS ws_name
     FROM tasks t
     JOIN task_assignments ta ON ta.task_id=t.id
     JOIN task_lists tl ON tl.id=t.list_id
     JOIN task_workspaces tw ON tw.id=t.workspace_id
     WHERE ta.user_id=? AND t.completed_at IS NOT NULL AND t.deleted_at IS NULL
     ORDER BY t.completed_at DESC
     LIMIT 10",
    [$uid]
);

$PAGE_TITLE      = 'Moje zadania';
$PAGE_SUBTITLE   = 'Twój pulpit';
$TASKS_BREADCRUMB = [['label' => 'Zadania', 'url' => APP_URL . '/tasks/dashboard.php'], ['label' => 'Moje zadania']];

require_once __DIR__ . '/includes/header_tasks.php';

$pri_colors = [4 => '#dc2626', 3 => '#f59e0b', 2 => '#3b82f6', 1 => '#94a3b8'];
$pri_labels = [4 => 'Krytyczny', 3 => 'Wysoki', 2 => 'Normalny', 1 => 'Niski'];
?>

<style>
/* ══ Moje zadania ═══════════════════════════════════════════════════════════ */
.mz-wrap {
  --tz-line: #E5E9F0;
  --tz-muted: #5b6472;
  --tz-ink: #111827;
  --tz-50: rgba(5,150,105,.07);
  max-width: 1000px;
}

/* KPI karty */
.mz-kpi-row { display:grid; grid-template-columns: repeat(4,1fr); gap:.9rem; margin-bottom:1.5rem; }
@media(max-width:700px) { .mz-kpi-row { grid-template-columns: repeat(2,1fr); } }

.mz-kpi { background:#fff; border:1px solid var(--tz-line); border-radius:14px;
  padding:1rem 1.1rem; display:flex; flex-direction:column; gap:.25rem;
  box-shadow:0 1px 3px rgba(16,24,40,.07); }
.mz-kpi__val { font-size:2rem; font-weight:800; line-height:1; }
.mz-kpi__lbl { font-size:.78rem; color:var(--tz-muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
.mz-kpi--ok   .mz-kpi__val { color:#059669; }
.mz-kpi--warn .mz-kpi__val { color:#d97706; }
.mz-kpi--red  .mz-kpi__val { color:#dc2626; }
.mz-kpi--neu  .mz-kpi__val { color:#3b82f6; }

/* Sekcja-karta */
.mz-card { background:#fff; border:1px solid var(--tz-line); border-radius:14px;
  box-shadow:0 1px 3px rgba(16,24,40,.08); overflow:hidden; margin-bottom:1.25rem; }
.mz-card__hd { padding:.85rem 1.1rem; border-bottom:1px solid var(--tz-line);
  display:flex; align-items:center; gap:.55rem; font-weight:700; font-size:.95rem; color:var(--tz-ink); }
.mz-card__hd i { color:var(--tsk-green); }
.mz-card__hd .badge-cnt { margin-left:auto; background:var(--tz-50); color:var(--tsk-green);
  font-size:.72rem; font-weight:700; border-radius:99px; padding:.15em .6em; }

/* Wiersze zadań */
.mz-task-row { display:flex; align-items:center; gap:.75rem; padding:.7rem 1.1rem;
  border-bottom:1px solid var(--tz-line); transition:background .12s; }
.mz-task-row:last-child { border-bottom:none; }
.mz-task-row:hover { background:var(--tz-50); }

.mz-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

.mz-task-body { flex:1; min-width:0; }
.mz-task-title { font-weight:600; font-size:.92rem; color:var(--tz-ink);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mz-task-title a { color:inherit; text-decoration:none; }
.mz-task-title a:hover { text-decoration:underline; }
.mz-task-meta  { font-size:.75rem; color:var(--tz-muted); margin-top:.1rem; }

.mz-due { font-size:.77rem; font-weight:600; flex-shrink:0; border-radius:6px;
  padding:.2em .55em; }
.mz-due--ok   { background:#f0fdf4; color:#16a34a; }
.mz-due--soon { background:#fffbeb; color:#b45309; }
.mz-due--over { background:#fef2f2; color:#dc2626; }
.mz-due--none { background:#f1f5f9; color:#94a3b8; }

.mz-done-btn { background:none; border:1px solid #d1d5db; border-radius:50%;
  width:28px; height:28px; display:flex; align-items:center; justify-content:center;
  cursor:pointer; color:#9ca3af; flex-shrink:0; transition:all .15s; }
.mz-done-btn:hover { border-color:#059669; color:#059669; background:#f0fdf4; }
.mz-done-btn.done-ok { border-color:#059669; background:#059669; color:#fff; pointer-events:none; }

/* Puste stany */
.mz-empty { padding:2rem 1rem; text-align:center; color:var(--tz-muted); font-size:.9rem; }
.mz-empty i { font-size:2rem; color:#d1d5db; display:block; margin-bottom:.5rem; }

/* Ukończone (collapsible) */
.mz-done-row .mz-task-title { color:var(--tz-muted); text-decoration:line-through; }
</style>

<div class="tsk-main-content">
<div class="mz-wrap">

  <!-- KPI -->
  <div class="mz-kpi-row">
    <div class="mz-kpi <?= $kpi_active ? 'mz-kpi--ok' : 'mz-kpi--neu' ?>">
      <span class="mz-kpi__val"><?= $kpi_active ?></span>
      <span class="mz-kpi__lbl">Aktywne</span>
    </div>
    <div class="mz-kpi <?= $kpi_overdue ? 'mz-kpi--red' : 'mz-kpi--ok' ?>">
      <span class="mz-kpi__val"><?= $kpi_overdue ?></span>
      <span class="mz-kpi__lbl">Zaległe</span>
    </div>
    <div class="mz-kpi <?= $kpi_today ? 'mz-kpi--warn' : 'mz-kpi--neu' ?>">
      <span class="mz-kpi__val"><?= $kpi_today ?></span>
      <span class="mz-kpi__lbl">Termin dziś</span>
    </div>
    <div class="mz-kpi mz-kpi--neu">
      <span class="mz-kpi__val"><?= $kpi_done_w ?></span>
      <span class="mz-kpi__lbl">Ukończone (7 dni)</span>
    </div>
  </div>

  <!-- Moje aktywne zadania -->
  <div class="mz-card">
    <div class="mz-card__hd">
      <i class="bi bi-person-check-fill" aria-hidden="true"></i>
      Przypisane do mnie
      <?php if ($my_tasks): ?>
      <span class="badge-cnt"><?= count($my_tasks) ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$my_tasks): ?>
    <div class="mz-empty">
      <i class="bi bi-check2-circle"></i>
      Nie masz żadnych aktywnych zadań — świetna robota!
    </div>
    <?php else: ?>

    <?php foreach ($my_tasks as $t):
      $due_class = 'mz-due--none';
      $due_txt   = 'Brak terminu';
      if ($t['due_date']) {
          $days = (int)floor((strtotime($t['due_date']) - time()) / 86400);
          if ($days < 0)       { $due_class = 'mz-due--over'; $due_txt = 'Zaległe ' . abs($days) . ' d'; }
          elseif ($days === 0) { $due_class = 'mz-due--soon'; $due_txt = 'Dziś'; }
          elseif ($days <= 3)  { $due_class = 'mz-due--soon'; $due_txt = 'Za ' . $days . ' d'; }
          else                 { $due_class = 'mz-due--ok';   $due_txt = date('d.m', strtotime($t['due_date'])); }
      }
      $pri  = (int)($t['priority'] ?? 2);
      $pcol = $pri_colors[$pri] ?? '#94a3b8';
      $plbl = $pri_labels[$pri] ?? 'Normalny';
    ?>
    <div class="mz-task-row" id="mzrow-<?= (int)$t['id'] ?>">
      <button type="button" class="mz-done-btn"
              data-task-id="<?= (int)$t['id'] ?>"
              data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
              title="Oznacz jako ukończone"
              aria-label="Oznacz zadanie „<?= h($t['title']) ?>" jako ukończone">
        <i class="bi bi-check-lg" aria-hidden="true"></i>
      </button>

      <span class="mz-dot" style="background:<?= h($pcol) ?>" title="<?= h($plbl) ?>"></span>

      <div class="mz-task-body">
        <div class="mz-task-title">
          <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
        </div>
        <div class="mz-task-meta">
          <?php if ($t['ws_name']): ?>
          <i class="bi bi-layers" style="font-size:.7rem"></i>
          <?= h($t['ws_name']) ?> &rsaquo;
          <?php endif; ?>
          <span style="color:<?= h($t['list_color'] ?: '#6b7280') ?>;font-weight:600">
            <?= h($t['list_name']) ?>
          </span>
        </div>
      </div>

      <span class="mz-due <?= $due_class ?>"><?= h($due_txt) ?></span>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
  </div>

  <!-- Wolne do wzięcia -->
  <?php if ($available): ?>
  <div class="mz-card">
    <div class="mz-card__hd">
      <i class="bi bi-box-seam" aria-hidden="true"></i>
      Wolne w moich obszarach
      <span class="badge-cnt"><?= count($available) ?></span>
    </div>

    <?php foreach ($available as $t):
      $pri  = (int)($t['priority'] ?? 2);
      $pcol = $pri_colors[$pri] ?? '#94a3b8';
      $plbl = $pri_labels[$pri] ?? 'Normalny';
      $due_class = 'mz-due--none'; $due_txt = '';
      if ($t['due_date']) {
          $days = (int)floor((strtotime($t['due_date']) - time()) / 86400);
          if ($days < 0)       { $due_class = 'mz-due--over'; $due_txt = 'Zaległe'; }
          elseif ($days === 0) { $due_class = 'mz-due--soon'; $due_txt = 'Dziś'; }
          elseif ($days <= 3)  { $due_class = 'mz-due--soon'; $due_txt = 'Za ' . $days . ' d'; }
          else                 { $due_class = 'mz-due--ok';   $due_txt = date('d.m', strtotime($t['due_date'])); }
      }
    ?>
    <div class="mz-task-row">
      <span class="mz-dot" style="background:<?= h($pcol) ?>" title="<?= h($plbl) ?>"></span>

      <div class="mz-task-body">
        <div class="mz-task-title">
          <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
        </div>
        <div class="mz-task-meta">
          <?php if ($t['ws_name']): ?>
          <i class="bi bi-layers" style="font-size:.7rem"></i>
          <?= h($t['ws_name']) ?> &rsaquo;
          <?php endif; ?>
          <span style="color:<?= h($t['list_color'] ?: '#6b7280') ?>;font-weight:600">
            <?= h($t['list_name']) ?>
          </span>
        </div>
      </div>

      <?php if ($due_txt): ?>
      <span class="mz-due <?= $due_class ?>"><?= h($due_txt) ?></span>
      <?php endif; ?>

      <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"
         class="btn btn-outline-success btn-sm" style="flex-shrink:0;font-size:.75rem">
        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i> Otwórz
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Ostatnio ukończone -->
  <?php if ($recent_done): ?>
  <details class="mz-card">
    <summary class="mz-card__hd" style="cursor:pointer;list-style:none;user-select:none">
      <i class="bi bi-check-circle-fill" style="color:#059669" aria-hidden="true"></i>
      Ostatnio ukończone
      <span class="badge-cnt" style="background:#f0fdf4;color:#059669"><?= count($recent_done) ?></span>
      <i class="bi bi-chevron-down ms-auto" style="font-size:.8rem;color:var(--tz-muted)" aria-hidden="true"></i>
    </summary>

    <?php foreach ($recent_done as $t):
      $days_ago = $t['completed_at']
          ? (int)floor((time() - strtotime($t['completed_at'])) / 86400)
          : 0;
      $ago_txt  = $days_ago === 0 ? 'dziś' : ($days_ago === 1 ? 'wczoraj' : $days_ago . ' dni temu');
    ?>
    <div class="mz-task-row mz-done-row">
      <span class="mz-dot" style="background:#d1d5db"></span>

      <div class="mz-task-body">
        <div class="mz-task-title">
          <a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
        </div>
        <div class="mz-task-meta">
          <?= h($t['ws_name'] ?? '') ?>
          <?php if ($t['ws_name'] && $t['list_name']): ?> &rsaquo; <?php endif; ?>
          <?= h($t['list_name'] ?? '') ?>
        </div>
      </div>

      <span class="mz-due mz-due--ok" style="background:#f0fdf4;color:#059669"><?= h($ago_txt) ?></span>
    </div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>

</div><!-- .mz-wrap -->
</div><!-- .tsk-main-content -->

<script>
(function () {
  'use strict';
  const API = '<?= APP_URL ?>/tasks/api/task.php';

  document.querySelectorAll('.mz-done-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const taskId = btn.dataset.taskId;
      const csrf   = btn.dataset.csrf;
      btn.disabled = true;

      fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'complete', id: parseInt(taskId, 10), _csrf: csrf })
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          btn.classList.add('done-ok');
          const row = document.getElementById('mzrow-' + taskId);
          if (row) {
            row.style.transition = 'opacity .4s';
            row.style.opacity = '0';
            setTimeout(function () { row.remove(); }, 420);
          }
        } else {
          btn.disabled = false;
          alert(d.msg || 'Błąd — spróbuj ponownie.');
        }
      })
      .catch(function () { btn.disabled = false; });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
