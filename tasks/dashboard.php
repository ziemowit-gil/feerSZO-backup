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
/* ══ Dashboard zadań — styl „panel wolontariusza" (pvtz) ════════════════
   Zmienne strukturalne zgodne z .pvtz; akcent = --tsk-green (emerald).   */
.tsk-dash {
  --tz-line: #E5E9F0;
  --tz-muted: #5b6472;
  --tz-ink: #111827;
  --tz-50: rgba(5,150,105,.07);
  --tz-strong: var(--tsk-green);
  max-width: 1100px;
}

/* Nagłówek strony */
.tsk-dash .dash-h { margin-bottom: 1.25rem; }
.tsk-dash .dash-h h1 { font-size: 1.5rem; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; color: var(--tz-ink); }
.tsk-dash .dash-h p  { color: var(--tz-muted); margin: .2rem 0 0; font-size: .9rem; }

/* Karty — identycznie jak .tz-card */
.tsk-dash .tz-card { background: #fff; border: 1px solid var(--tz-line); border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.08); overflow: hidden; }
.tsk-dash .tz-card__hd { padding: .9rem 1.15rem; border-bottom: 1px solid var(--tz-line); display: flex; align-items: center; gap: .6rem; font-weight: 700; font-size: .95rem; color: var(--tz-ink); }
.tsk-dash .tz-card__hd i { color: var(--tsk-green); }
.tsk-dash .tz-card__hd a.btn { margin-left: auto; font-size: .74rem; }

/* Nagłówki sekcji — identycznie jak .tz-section-h */
.tsk-dash .tz-section-h { font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--tz-muted); margin: 1.4rem 0 .7rem; }
.tsk-dash .tz-section-h:first-child { margin-top: 0; }

/* KPI tiles — wzorowane na .tz-tile */
.tsk-dash .kpi-tile {
  position: relative; display: flex; flex-direction: column; gap: .15rem;
  background: #fff; border: 1px solid var(--tz-line); border-radius: 14px;
  padding: 1rem 1.05rem; text-decoration: none; color: inherit;
  min-height: 100px; transition: transform .15s, border-color .15s, box-shadow .15s;
  overflow: hidden;
}
.tsk-dash .kpi-tile:hover, .tsk-dash .kpi-tile:focus-visible {
  transform: translateY(-2px); border-color: var(--tsk-green);
  box-shadow: 0 8px 24px -6px rgba(5,150,105,.22); color: inherit;
}
.tsk-dash .kpi-tile__ico {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--tsk-green); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; margin-bottom: .5rem; flex-shrink: 0;
}
.tsk-dash .kpi-tile__val  { font-size: 1.7rem; font-weight: 800; line-height: 1; color: var(--tz-ink); }
.tsk-dash .kpi-tile__lbl  { font-size: .76rem; color: var(--tz-muted); font-weight: 500; }
.tsk-dash .kpi-tile.is-red   .kpi-tile__ico { background: #dc2626; }
.tsk-dash .kpi-tile.is-red   .kpi-tile__val { color: #dc2626; }
.tsk-dash .kpi-tile.is-green .kpi-tile__ico { background: #16a34a; }
.tsk-dash .kpi-tile.is-blue  .kpi-tile__ico { background: #2563eb; }
.tsk-dash .kpi-tile.is-slate .kpi-tile__ico { background: #64748b; }
.tsk-dash .kpi-tile.is-violet .kpi-tile__ico { background: #7c3aed; }

/* Wiersze zadań */
.tsk-dash .tsk-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .65rem 1.15rem; border-bottom: 1px solid var(--tz-line);
  text-decoration: none; color: inherit; transition: background .1s;
}
.tsk-dash .tsk-row:last-child { border-bottom: none; }
.tsk-dash .tsk-row:hover { background: #f8fafc; }
.tsk-dash .tsk-row:focus-visible { outline: 3px solid var(--tsk-focus) !important; outline-offset: -2px; }
.tsk-dash .tsk-row__dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.tsk-dash .tsk-row__ttl { flex: 1; font-size: .87rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--tz-ink); }
.tsk-dash .tsk-row__list { font-size: .7rem; color: var(--tz-muted); white-space: nowrap; }
.tsk-dash .tsk-row__due  { font-size: .73rem; white-space: nowrap; color: var(--tz-muted); }
.tsk-dash .tsk-row__due.overdue { color: #dc2626; font-weight: 700; }

/* Aktywność */
.tsk-dash .act-row {
  display: flex; gap: .6rem; align-items: flex-start;
  padding: .55rem 1.15rem; border-bottom: 1px solid var(--tz-line);
  font-size: .78rem; color: var(--tz-muted);
}
.tsk-dash .act-row:last-child { border-bottom: none; }
.tsk-dash .act-row__ico { flex-shrink: 0; margin-top: .1rem; font-size: .8rem; color: var(--tz-muted); }
.tsk-dash .act-row__bd  { flex: 1; line-height: 1.4; }
.tsk-dash .act-row__time { flex-shrink: 0; font-size: .7rem; color: var(--tz-muted); white-space: nowrap; }

/* Obszary robocze */
.tsk-dash .ws-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .65rem 1.15rem; border-bottom: 1px solid var(--tz-line);
  text-decoration: none; color: inherit; transition: background .1s;
}
.tsk-dash .ws-row:last-child { border-bottom: none; }
.tsk-dash .ws-row:hover { background: #f8fafc; }
.tsk-dash .ws-row__ico {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: .95rem; flex-shrink: 0;
}
.tsk-dash .ws-row__prog { flex: 1; min-width: 0; }
.tsk-dash .ws-row__name { font-size: .88rem; font-weight: 600; color: var(--tz-ink); }
.tsk-dash .ws-row__bar  { height: 5px; background: var(--tz-line); border-radius: 999px; overflow: hidden; margin-top: .3rem; }
.tsk-dash .ws-row__fill { height: 5px; border-radius: 999px; }
.tsk-dash .ws-row__cnt  { font-size: .74rem; color: var(--tz-muted); white-space: nowrap; }

/* Puste stany */
.tsk-dash .tsk-empty { text-align: center; padding: 2rem 1rem; color: var(--tz-muted); font-size: .84rem; }
.tsk-dash .tsk-empty i { font-size: 1.6rem; display: block; margin-bottom: .4rem; opacity: .3; }
</style>

<div class="tsk-dash">

<!-- ── Nagłówek ─────────────────────────────────────────────────────── -->
<div class="dash-h d-flex flex-wrap align-items-start justify-content-between gap-2">
  <div>
    <h1>Dzień dobry, <?= h(explode(' ', $_tu_name ?? 'Użytkowniku')[0]) ?> 👋</h1>
    <p>
      <?php if ($kpi['mine'] > 0): ?>
        Masz <strong><?= $kpi['mine'] ?></strong> aktywnych zadań.<?php if ($kpi['overdue'] > 0): ?> <span class="text-danger fw-semibold">Uwaga: <?= $kpi['overdue'] ?> po terminie!</span><?php endif; ?>
      <?php else: ?>
        Nie masz przypisanych zadań.<?php if ($kpi['open'] > 0): ?> Dostępne: <strong><?= $kpi['open'] ?></strong> wolnych.<?php endif; ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($is_admin): ?>
  <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm"
     style="background:var(--tsk-green);color:#fff;border-radius:10px;font-weight:600;min-height:40px;display:inline-flex;align-items:center;gap:.4rem">
    <i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe zadanie
  </a>
  <?php endif; ?>
</div>

<!-- ── KPI ──────────────────────────────────────────────────────────── -->
<h2 class="tz-section-h">Podsumowanie</h2>
<div class="row g-3 mb-4">

  <div class="col-6 col-md-4 col-lg-2">
    <a class="kpi-tile" href="<?= APP_URL ?>/tasks/index.php" aria-label="Wszystkie zadania: <?= $kpi['total'] ?>">
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-table"></i></span>
      <div class="kpi-tile__val"><?= $kpi['total'] ?></div>
      <div class="kpi-tile__lbl">Wszystkie</div>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="kpi-tile is-green" href="<?= APP_URL ?>/tasks/index.php?status=open" aria-label="Do zrobienia: <?= $kpi['open'] ?>">
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-circle"></i></span>
      <div class="kpi-tile__val"><?= $kpi['open'] ?></div>
      <div class="kpi-tile__lbl">Do zrobienia</div>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="kpi-tile is-blue" href="<?= APP_URL ?>/tasks/index.php?status=taken" aria-label="Przydzielone: <?= $kpi['taken'] ?>">
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
      <div class="kpi-tile__val"><?= $kpi['taken'] ?></div>
      <div class="kpi-tile__lbl">Przydzielone</div>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="kpi-tile is-slate" href="<?= APP_URL ?>/tasks/index.php?status=done" aria-label="Ukończone: <?= $kpi['done'] ?>">
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
      <div class="kpi-tile__val"><?= $kpi['done'] ?></div>
      <div class="kpi-tile__lbl">Ukończone</div>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="kpi-tile is-violet" href="<?= APP_URL ?>/tasks/index.php?status=mine" aria-label="Moje zadania: <?= $kpi['mine'] ?>">
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-person-check-fill"></i></span>
      <div class="kpi-tile__val"><?= $kpi['mine'] ?></div>
      <div class="kpi-tile__lbl">Moje</div>
    </a>
  </div>

  <div class="col-6 col-md-4 col-lg-2">
    <a class="kpi-tile <?= $kpi['overdue'] > 0 ? 'is-red' : '' ?>"
       href="<?= APP_URL ?>/tasks/index.php"
       aria-label="Po terminie: <?= $kpi['overdue'] ?>">
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-alarm"></i></span>
      <div class="kpi-tile__val"><?= $kpi['overdue'] ?></div>
      <div class="kpi-tile__lbl">Po terminie</div>
    </a>
  </div>

</div>

<!-- ── Siatka główna ─────────────────────────────────────────────────── -->
<h2 class="tz-section-h">Zadania</h2>
<div class="row g-3">

  <!-- Moje zadania -->
  <div class="col-lg-5">
    <div class="tz-card h-100">
      <div class="tz-card__hd">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        Moje zadania
        <?php if ($kpi['mine'] > 0): ?>
        <span class="badge rounded-pill ms-1"
              style="background:var(--tsk-green-light);color:var(--tsk-green);font-size:.65rem">
          <?= $kpi['mine'] ?>
        </span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/tasks/index.php?status=mine"
           class="btn btn-outline-secondary btn-sm py-0"
           style="font-size:.74rem">Wszystkie</a>
      </div>

      <?php if ($my_tasks): foreach ($my_tasks as $t):
        $overdue = $t['due_date'] && strtotime($t['due_date']) < strtotime('today');
        $pc = $pri_colors[$t['priority']] ?? '#94a3b8';
      ?>
      <a class="tsk-row"
         href="<?= APP_URL ?>/tasks/index.php?status=mine"
         onclick="event.preventDefault(); window.taskOpenById(<?= $t['id'] ?>)"
         aria-label="Zadanie: <?= h($t['title']) ?><?= $overdue?' (po terminie)':'' ?>">
        <span class="tsk-row__dot" style="background:<?= $pc ?>" aria-hidden="true"></span>
        <span class="tsk-row__ttl"><?= h($t['title']) ?></span>
        <span class="tsk-row__list">
          <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= h($t['list_color']?:'#94a3b8') ?>;margin-right:3px" aria-hidden="true"></span>
          <?= h($t['list_name']) ?>
        </span>
        <?php if ($t['due_date']): ?>
        <span class="tsk-row__due <?= $overdue?'overdue':'' ?>">
          <?= $overdue ? '⚠ ' : '' ?><?= h(date('d.m', strtotime($t['due_date']))) ?>
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

  <!-- Dostępne zadania do wzięcia -->
  <div class="col-lg-4">
    <div class="tz-card h-100">
      <div class="tz-card__hd">
        <i class="bi bi-circle" aria-hidden="true"></i>
        Dostępne — do wzięcia
        <?php if ($kpi['open'] > 0): ?>
        <span class="badge rounded-pill ms-1"
              style="background:#dcfce7;color:#15803d;font-size:.65rem">
          <?= $kpi['open'] ?>
        </span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/tasks/index.php?status=open"
           class="btn btn-outline-secondary btn-sm py-0"
           style="font-size:.74rem">Wszystkie</a>
      </div>

      <?php if ($new_open): foreach ($new_open as $t):
        $overdue = $t['due_date'] && strtotime($t['due_date']) < strtotime('today');
        $pc = $pri_colors[$t['priority']] ?? '#94a3b8';
      ?>
      <a class="tsk-row"
         href="<?= APP_URL ?>/tasks/index.php?status=open"
         onclick="event.preventDefault(); window.taskOpenById(<?= $t['id'] ?>)"
         aria-label="Dostępne zadanie: <?= h($t['title']) ?>">
        <span class="tsk-row__dot" style="background:<?= $pc ?>" aria-hidden="true"></span>
        <span class="tsk-row__ttl"><?= h($t['title']) ?></span>
        <?php if ($t['due_date']): ?>
        <span class="tsk-row__due <?= $overdue?'overdue':'' ?>">
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
    <div class="tz-card h-100">
      <div class="tz-card__hd">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        Aktywność
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
      <div class="act-row">
        <i class="bi <?= $icon ?> act-row__ico" aria-hidden="true"></i>
        <span class="act-row__bd">
          <strong style="font-size:.75rem;color:var(--tz-ink)"><?= h($label) ?></strong>
          <span style="display:block;font-size:.73rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= h(mb_substr($ev['task_title'],0,30)) ?>
          </span>
          <span style="font-size:.7rem"><?= h($ev['actor_name']) ?></span>
        </span>
        <span class="act-row__time" aria-label="Czas: <?= h($time) ?>">
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
<h2 class="tz-section-h" style="margin-top:1.6rem">Obszary robocze</h2>
<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-grid" aria-hidden="true"></i>
    Obszary robocze
    <?php if ($is_admin): ?>
    <a href="<?= APP_URL ?>/admin/tasks_workspaces.php"
       class="btn btn-outline-secondary btn-sm py-0"
       style="font-size:.74rem">Zarządzaj</a>
    <?php endif; ?>
  </div>
  <div class="row g-0">
    <?php foreach ($ws_stats as $ws):
      $ws_done  = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND completed_at IS NOT NULL AND deleted_at IS NULL",
        [$ws['id']]
      )['n'] ?? 0);
      $ws_total = (int)$ws['task_count'];
      $ws_pct   = $ws_total ? round($ws_done / $ws_total * 100) : 0;
    ?>
    <div class="col-12 col-sm-6 col-md-4 col-lg-3">
      <a class="ws-row" href="<?= APP_URL ?>/tasks/index.php?ws=<?= $ws['id'] ?>">
        <span class="ws-row__ico"
              style="background:<?= h($ws['color']) ?>22;color:<?= h($ws['color']) ?>">
          <i class="bi <?= h($ws['icon']) ?>" aria-hidden="true"></i>
        </span>
        <div class="ws-row__prog">
          <div class="ws-row__name"><?= h($ws['name']) ?></div>
          <div class="d-flex align-items-center gap-2">
            <div class="ws-row__bar flex-grow-1"
                 role="progressbar"
                 aria-valuenow="<?= $ws_pct ?>"
                 aria-valuemin="0" aria-valuemax="100"
                 aria-label="Ukończono <?= $ws_pct ?>%">
              <div class="ws-row__fill"
                   style="width:<?= $ws_pct ?>%;background:<?= h($ws['color']) ?>"></div>
            </div>
            <span class="ws-row__cnt"><?= $ws_done ?>/<?= $ws_total ?></span>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</div><!-- /tsk-dash -->

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
