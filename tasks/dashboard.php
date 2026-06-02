<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$user     = current_user();
$uid      = (int)$user['id'];
$is_admin = is_admin();

// ── KPI ───────────────────────────────────────────────────────────────────
function _tq(string $sql, array $p = []): int {
    try { return (int)(db_one($sql, $p)['n'] ?? 0); } catch (\Throwable $e) { return 0; }
}

$kpi = [
    'total'    => _tq("SELECT COUNT(*) AS n FROM tasks WHERE deleted_at IS NULL"),
    'open'     => _tq("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
                        AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id=t.id)=0"),
    'taken'    => _tq("SELECT COUNT(*) AS n FROM tasks t WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
                        AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id=t.id)>0"),
    'done'     => _tq("SELECT COUNT(*) AS n FROM tasks WHERE deleted_at IS NULL AND completed_at IS NOT NULL"),
    'mine'     => _tq("SELECT COUNT(*) AS n FROM tasks t
                        JOIN task_assignments ta ON ta.task_id=t.id
                        WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL", [$uid]),
    'overdue'  => _tq("SELECT COUNT(*) AS n FROM tasks WHERE deleted_at IS NULL AND completed_at IS NULL
                        AND due_date IS NOT NULL AND due_date < date('now')"),
    'due_7'    => _tq("SELECT COUNT(*) AS n FROM tasks WHERE deleted_at IS NULL AND completed_at IS NULL
                        AND due_date IS NOT NULL
                        AND due_date BETWEEN date('now') AND date('now','+7 days')"),
];

// Moje zadania (szczegółowo)
$my_tasks = db_all(
    "SELECT t.*, tl.name AS list_name, tl.color AS list_color
     FROM tasks t
     JOIN task_assignments ta ON ta.task_id = t.id
     JOIN task_lists tl ON tl.id = t.list_id
     WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL
     ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
     LIMIT 8",
    [$uid]
);

// Ostatnio dodane wolne zadania
$new_open = db_all(
    "SELECT t.*, tl.name AS list_name, tl.color AS list_color, tw.name AS ws_name
     FROM tasks t
     JOIN task_lists tl ON tl.id = t.list_id
     JOIN task_workspaces tw ON tw.id = t.workspace_id
     WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
       AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id=t.id)=0
     ORDER BY t.priority DESC, t.created_at DESC
     LIMIT 6"
);

// Aktywność (ostatnie zdarzenia)
$recent = db_all(
    "SELECT th.event_type, th.occurred_at, th.from_value, th.to_value,
            u.name AS actor_name, t.title AS task_title
     FROM task_history th
     JOIN tasks t ON t.id = th.task_id
     JOIN users u ON u.id = th.user_id
     WHERE t.deleted_at IS NULL
     ORDER BY th.occurred_at DESC LIMIT 12"
);

// Workspaces stats
$ws_stats = task_user_workspaces($uid);

$PAGE_TITLE      = 'Dashboard';
$PAGE_SUBTITLE   = 'Przegląd zadań';
$TASKS_BREADCRUMB = 'Dashboard';
require_once __DIR__ . '/includes/header_tasks.php';

$pri_colors = [4=>'#dc2626',3=>'#f59e0b',2=>'#3b82f6',1=>'#94a3b8'];
$pri_labels = [4=>'Krytyczny',3=>'Wysoki',2=>'Normalny',1=>'Niski'];
?>

<style>
/* ── KPI cards ──────────────────────────────────────────────── */
.tsk-kpi {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: .75rem;
  padding: 1.1rem 1.2rem;
  display: block; text-decoration: none;
  transition: box-shadow .15s, border-color .15s, transform .1s;
  position: relative; overflow: hidden;
}
.tsk-kpi:hover {
  box-shadow: 0 4px 16px rgba(0,0,0,.09);
  border-color: #a5b4fc;
  transform: translateY(-2px);
}
.tsk-kpi:focus-visible {
  outline: 3px solid var(--tsk-focus) !important;
}
.tsk-kpi-stripe {
  position: absolute; top: 0; left: 0; right: 0;
  height: 3px;
}
.tsk-kpi-val {
  font-size: 2rem; font-weight: 800; line-height: 1;
  color: var(--tsk-text); margin-bottom: .25rem;
}
.tsk-kpi-label {
  font-size: .8rem; color: #64748b; font-weight: 500;
}
.tsk-kpi-icon {
  position: absolute; bottom: .8rem; right: 1rem;
  font-size: 1.6rem; opacity: .09;
}

/* ── Sekcje ─────────────────────────────────────────────────── */
.tsk-section {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: .75rem;
  overflow: hidden;
}
.tsk-section-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: .8rem 1rem;
  border-bottom: 1px solid #f1f5f9;
  background: #f8fafc;
}
.tsk-section-title {
  font-size: .78rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: #64748b; display: flex; align-items: center; gap: .4rem;
  margin: 0;
}

/* ── Wiersze zadań ───────────────────────────────────────────── */
.tsk-task-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .6rem 1rem;
  border-bottom: 1px solid #f8fafc;
  cursor: pointer; text-decoration: none; color: inherit;
  transition: background .1s;
}
.tsk-task-row:last-child { border-bottom: none; }
.tsk-task-row:hover { background: #f8fafc; }
.tsk-task-row:focus-visible { outline: 3px solid var(--tsk-focus) !important; outline-offset: -2px; }
.tsk-task-pri {
  width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.tsk-task-title {
  flex: 1; font-size: .87rem; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tsk-task-due {
  font-size: .73rem; white-space: nowrap;
  color: #94a3b8;
}
.tsk-task-due.overdue { color: #dc2626; font-weight: 700; }
.tsk-task-list {
  font-size: .7rem; color: #94a3b8; white-space: nowrap;
}

/* ── Aktywność ───────────────────────────────────────────────── */
.tsk-act-row {
  display: flex; gap: .6rem; align-items: flex-start;
  padding: .45rem 1rem; border-bottom: 1px solid #f8fafc;
  font-size: .78rem; color: #64748b;
}
.tsk-act-row:last-child { border-bottom: none; }
.tsk-act-icon { flex-shrink: 0; margin-top: .1rem; font-size: .8rem; color: #94a3b8; }
.tsk-act-text { flex: 1; line-height: 1.4; }
.tsk-act-time { flex-shrink: 0; font-size: .7rem; color: #94a3b8; white-space: nowrap; }

/* ── Workspace cards ─────────────────────────────────────────── */
.tsk-ws-card {
  display: flex; align-items: center; gap: .75rem;
  padding: .6rem 1rem; border-bottom: 1px solid #f8fafc;
  text-decoration: none; color: inherit;
  transition: background .1s;
}
.tsk-ws-card:last-child { border-bottom: none; }
.tsk-ws-card:hover { background: #f8fafc; }
.tsk-ws-icon {
  width: 34px; height: 34px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .95rem; flex-shrink: 0;
}
.tsk-ws-name { font-size: .88rem; font-weight: 600; }
.tsk-ws-cnt  { font-size: .75rem; color: #94a3b8; }
.tsk-ws-prog { flex: 1; }
.tsk-ws-bar  { height: 3px; background: #e2e8f0; border-radius: 2px; overflow: hidden; margin-top: .25rem; }
.tsk-ws-fill { height: 100%; border-radius: 2px; }

/* Puste stany */
.tsk-empty { text-align: center; padding: 2rem 1rem; color: #94a3b8; font-size: .84rem; }
.tsk-empty i { font-size: 1.5rem; display: block; margin-bottom: .4rem; opacity: .3; }
</style>

<!-- ── Nagłówek dashboardu ───────────────────────────────────────────── -->
<div class="d-flex align-items-start justify-content-between mb-4">
  <div>
    <h1 class="h4 fw-bold mb-0" style="color:var(--tsk-text)">
      Dzień dobry, <?= h(explode(' ', $_tu_name ?? 'Użytkowniku')[0]) ?> 👋
    </h1>
    <p class="text-muted small mb-0">
      <?php if ($kpi['mine'] > 0): ?>
        Masz <strong><?= $kpi['mine'] ?></strong> aktywnych zadań.
        <?php if ($kpi['overdue'] > 0): ?>
        <span class="text-danger fw-semibold">Uwaga: <?= $kpi['overdue'] ?> po terminie!</span>
        <?php endif; ?>
      <?php else: ?>
        Nie masz przypisanych zadań. <?php if ($kpi['open'] > 0): ?>
        Dostępne: <strong><?= $kpi['open'] ?></strong> wolnych zadań.
        <?php endif; ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($is_admin): ?>
  <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowe zadanie
  </a>
  <?php endif; ?>
</div>

<!-- ── KPI ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-4 col-lg-2">
    <a class="tsk-kpi" href="<?= APP_URL ?>/tasks/index.php"
       aria-label="Wszystkie zadania: <?= $kpi['total'] ?>">
      <div class="tsk-kpi-stripe" style="background:#6366f1"></div>
      <div class="tsk-kpi-val"><?= $kpi['total'] ?></div>
      <div class="tsk-kpi-label">Wszystkie</div>
      <i class="bi bi-table tsk-kpi-icon" aria-hidden="true"></i>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="tsk-kpi" href="<?= APP_URL ?>/tasks/index.php?status=open"
       aria-label="Wolne zadania: <?= $kpi['open'] ?>">
      <div class="tsk-kpi-stripe" style="background:#16a34a"></div>
      <div class="tsk-kpi-val" style="color:<?= $kpi['open'] > 0 ? '#16a34a' : 'inherit' ?>">
        <?= $kpi['open'] ?>
      </div>
      <div class="tsk-kpi-label">Wolne</div>
      <i class="bi bi-circle tsk-kpi-icon" aria-hidden="true"></i>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="tsk-kpi" href="<?= APP_URL ?>/tasks/index.php?status=taken"
       aria-label="Zajęte zadania: <?= $kpi['taken'] ?>">
      <div class="tsk-kpi-stripe" style="background:#2563eb"></div>
      <div class="tsk-kpi-val"><?= $kpi['taken'] ?></div>
      <div class="tsk-kpi-label">Zajęte</div>
      <i class="bi bi-person-fill tsk-kpi-icon" aria-hidden="true"></i>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="tsk-kpi" href="<?= APP_URL ?>/tasks/index.php?status=done"
       aria-label="Ukończone zadania: <?= $kpi['done'] ?>">
      <div class="tsk-kpi-stripe" style="background:#64748b"></div>
      <div class="tsk-kpi-val"><?= $kpi['done'] ?></div>
      <div class="tsk-kpi-label">Ukończone</div>
      <i class="bi bi-check-circle-fill tsk-kpi-icon" aria-hidden="true"></i>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="tsk-kpi" href="<?= APP_URL ?>/tasks/index.php?status=mine"
       aria-label="Moje zadania: <?= $kpi['mine'] ?>">
      <div class="tsk-kpi-stripe" style="background:#7c3aed"></div>
      <div class="tsk-kpi-val"><?= $kpi['mine'] ?></div>
      <div class="tsk-kpi-label">Moje</div>
      <i class="bi bi-person-check-fill tsk-kpi-icon" aria-hidden="true"></i>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="tsk-kpi <?= $kpi['overdue'] > 0 ? 'border-danger' : '' ?>"
       href="<?= APP_URL ?>/tasks/index.php"
       aria-label="Zadania po terminie: <?= $kpi['overdue'] ?>">
      <div class="tsk-kpi-stripe" style="background:#dc2626"></div>
      <div class="tsk-kpi-val" style="color:<?= $kpi['overdue'] > 0 ? '#dc2626' : 'inherit' ?>">
        <?= $kpi['overdue'] ?>
      </div>
      <div class="tsk-kpi-label">Po terminie</div>
      <i class="bi bi-alarm tsk-kpi-icon" aria-hidden="true"></i>
    </a>
  </div>

</div>

<!-- ── Siatka główna ─────────────────────────────────────────────────── -->
<div class="row g-3">

  <!-- Moje zadania -->
  <div class="col-lg-5">
    <div class="tsk-section h-100">
      <div class="tsk-section-head">
        <h2 class="tsk-section-title">
          <i class="bi bi-person-check" aria-hidden="true"></i>
          Moje zadania
          <?php if ($kpi['mine'] > 0): ?>
          <span class="badge rounded-pill ms-1" style="background:var(--tsk-green-light);color:var(--tsk-green);font-size:.65rem">
            <?= $kpi['mine'] ?>
          </span>
          <?php endif; ?>
        </h2>
        <a href="<?= APP_URL ?>/tasks/index.php?status=mine"
           class="btn btn-outline-secondary btn-sm py-0"
           style="font-size:.74rem">Wszystkie</a>
      </div>

      <?php if ($my_tasks): foreach ($my_tasks as $t):
        $overdue = $t['due_date'] && strtotime($t['due_date']) < strtotime('today');
        $pc = $pri_colors[$t['priority']] ?? '#94a3b8';
      ?>
      <a class="tsk-task-row"
         href="<?= APP_URL ?>/tasks/index.php?status=mine"
         onclick="event.preventDefault(); window.taskOpenById(<?= $t['id'] ?>)"
         aria-label="Zadanie: <?= h($t['title']) ?><?= $overdue?' (po terminie)':'' ?>">
        <span class="tsk-task-pri" style="background:<?= $pc ?>" aria-hidden="true"></span>
        <span class="tsk-task-title"><?= h($t['title']) ?></span>
        <span class="tsk-task-list">
          <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= h($t['list_color']?:'#94a3b8') ?>;margin-right:3px" aria-hidden="true"></span>
          <?= h($t['list_name']) ?>
        </span>
        <?php if ($t['due_date']): ?>
        <span class="tsk-task-due <?= $overdue?'overdue':'' ?>">
          <?= $overdue ? '⚠' : '' ?> <?= h(date('d.m', strtotime($t['due_date']))) ?>
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; else: ?>
      <div class="tsk-empty">
        <i class="bi bi-check2-all" aria-hidden="true"></i>
        Nie masz przypisanych zadań.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Wolne zadania do wzięcia -->
  <div class="col-lg-4">
    <div class="tsk-section h-100">
      <div class="tsk-section-head">
        <h2 class="tsk-section-title">
          <i class="bi bi-circle" aria-hidden="true"></i>
          Wolne — do wzięcia
          <?php if ($kpi['open'] > 0): ?>
          <span class="badge rounded-pill ms-1" style="background:#dcfce7;color:#15803d;font-size:.65rem">
            <?= $kpi['open'] ?>
          </span>
          <?php endif; ?>
        </h2>
        <a href="<?= APP_URL ?>/tasks/index.php?status=open"
           class="btn btn-outline-secondary btn-sm py-0"
           style="font-size:.74rem">Wszystkie</a>
      </div>

      <?php if ($new_open): foreach ($new_open as $t):
        $overdue = $t['due_date'] && strtotime($t['due_date']) < strtotime('today');
        $pc = $pri_colors[$t['priority']] ?? '#94a3b8';
      ?>
      <a class="tsk-task-row"
         href="<?= APP_URL ?>/tasks/index.php?status=open"
         onclick="event.preventDefault(); window.taskOpenById(<?= $t['id'] ?>)"
         aria-label="Wolne zadanie: <?= h($t['title']) ?>">
        <span class="tsk-task-pri" style="background:<?= $pc ?>" aria-hidden="true"></span>
        <span class="tsk-task-title"><?= h($t['title']) ?></span>
        <?php if ($t['due_date']): ?>
        <span class="tsk-task-due <?= $overdue?'overdue':'' ?>">
          <?= h(date('d.m', strtotime($t['due_date']))) ?>
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; else: ?>
      <div class="tsk-empty">
        <i class="bi bi-inbox" aria-hidden="true"></i>
        Brak wolnych zadań.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ostatnia aktywność -->
  <div class="col-lg-3">
    <div class="tsk-section h-100">
      <div class="tsk-section-head">
        <h2 class="tsk-section-title">
          <i class="bi bi-clock-history" aria-hidden="true"></i>
          Aktywność
        </h2>
      </div>

      <?php
      $ev_labels = ['moved'=>'Przeniesiono','created'=>'Dodano','assigned'=>'Przypisano',
                    'unassigned'=>'Odpięto','completed'=>'Ukończono','reopened'=>'Wznowiono',
                    'comment_added'=>'Komentarz','tag_added'=>'Tag','priority_changed'=>'Priorytet',
                    'due_changed'=>'Termin','uploaded_file'=>'Plik'];
      $ev_icons  = ['moved'=>'bi-arrow-right','created'=>'bi-plus-circle','assigned'=>'bi-person-plus',
                    'unassigned'=>'bi-person-dash','completed'=>'bi-check-circle','reopened'=>'bi-arrow-counterclockwise',
                    'comment_added'=>'bi-chat','tag_added'=>'bi-tag','priority_changed'=>'bi-flag',
                    'due_changed'=>'bi-calendar3','uploaded_file'=>'bi-paperclip'];
      if ($recent): foreach ($recent as $ev):
        $icon  = $ev_icons[$ev['event_type']] ?? 'bi-circle';
        $label = $ev_labels[$ev['event_type']] ?? $ev['event_type'];
        $time  = substr($ev['occurred_at'], 0, 16);
      ?>
      <div class="tsk-act-row">
        <i class="bi <?= $icon ?> tsk-act-icon" aria-hidden="true"></i>
        <span class="tsk-act-text">
          <strong style="font-size:.75rem"><?= h($label) ?></strong>
          <span style="display:block;font-size:.73rem;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= h(mb_substr($ev['task_title'],0,30)) ?>
          </span>
          <span style="font-size:.7rem"><?= h($ev['actor_name']) ?></span>
        </span>
        <span class="tsk-act-time" aria-label="Czas: <?= h($time) ?>">
          <?= h(substr($time, 5)) ?>
        </span>
      </div>
      <?php endforeach; else: ?>
      <div class="tsk-empty">
        <i class="bi bi-clock" aria-hidden="true"></i>
        Brak aktywności.
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ── Obszary robocze ────────────────────────────────────────────────── -->
<?php if ($ws_stats): ?>
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="tsk-section">
      <div class="tsk-section-head">
        <h2 class="tsk-section-title">
          <i class="bi bi-grid" aria-hidden="true"></i>
          Obszary robocze
        </h2>
        <?php if ($is_admin): ?>
        <a href="<?= APP_URL ?>/admin/tasks_workspaces.php"
           class="btn btn-outline-secondary btn-sm py-0"
           style="font-size:.74rem">Zarządzaj</a>
        <?php endif; ?>
      </div>
      <div class="row g-0">
        <?php foreach ($ws_stats as $ws):
          // Liczba ukończonych zadań w obszarze
          $ws_done = (int)(db_one(
            "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND completed_at IS NOT NULL AND deleted_at IS NULL",
            [$ws['id']]
          )['n'] ?? 0);
          $ws_total = (int)$ws['task_count'];
          $ws_pct   = $ws_total ? round($ws_done / $ws_total * 100) : 0;
        ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
          <a class="tsk-ws-card" href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws['id'] ?>">
            <span class="tsk-ws-icon"
                  style="background:<?= h($ws['color']) ?>22;color:<?= h($ws['color']) ?>">
              <i class="bi <?= h($ws['icon']) ?>" aria-hidden="true"></i>
            </span>
            <div class="tsk-ws-prog">
              <div class="tsk-ws-name"><?= h($ws['name']) ?></div>
              <div class="d-flex align-items-center gap-2">
                <div class="tsk-ws-bar flex-grow-1"
                     role="progressbar"
                     aria-valuenow="<?= $ws_pct ?>"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-label="Ukończono <?= $ws_pct ?>%">
                  <div class="tsk-ws-fill"
                       style="width:<?= $ws_pct ?>%;background:<?= h($ws['color']) ?>"></div>
                </div>
                <span class="tsk-ws-cnt"><?= $ws_done ?>/<?= $ws_total ?></span>
              </div>
            </div>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Offcanvas do otwierania zadań z dashboardu -->
<div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="taskOffcanvas"
     role="dialog" aria-labelledby="taskOffcanvasLabel" aria-modal="true">
  <div class="offcanvas-header" style="border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body p-0 overflow-auto"
       id="taskOffcanvasBody" aria-live="polite" aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode(rtrim(APP_URL,'/')) ?>;

window.taskOpenById = function(taskId) {
    const body = document.getElementById('taskOffcanvasBody');
    body.innerHTML = '<div class="text-center py-5 text-muted">'
        + '<div class="spinner-border spinner-border-sm" role="status">'
        + '<span class="visually-hidden">Ładowanie…</span></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(
        document.getElementById('taskOffcanvas')
    ).show();
    fetch(BASE + '/tasks/detail.php?id=' + taskId)
        .then(r => r.text())
        .then(html => {
            body.innerHTML = '';
            body.appendChild(document.createRange().createContextualFragment(html));
        })
        .catch(() => {
            body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>';
        });
};
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
