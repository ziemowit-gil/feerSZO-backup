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

require_once dirname(__DIR__) . '/includes/functions.php';
$_mz_brand   = org_setting('sidebar_color') ?: '#1e293b';
$_mz_initials = '';
foreach (explode(' ', $user['name'] ?? '') as $part) {
    $_mz_initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$_mz_initials = mb_substr($_mz_initials, 0, 2);

$PAGE_TITLE      = 'Moje zadania';
$PAGE_SUBTITLE   = 'Twój pulpit';
$TASKS_BREADCRUMB = [['label' => 'Zadania', 'url' => APP_URL . '/tasks/dashboard.php'], ['label' => 'Moje zadania']];

require_once __DIR__ . '/includes/header_tasks.php';

$pri_colors = [4 => '#dc2626', 3 => '#f59e0b', 2 => '#3b82f6', 1 => '#94a3b8'];
$pri_labels = [4 => 'Krytyczny', 3 => 'Wysoki', 2 => 'Normalny', 1 => 'Niski'];
?>

<?php
// Grupy wg terminu — „co mam zrobić najpierw” (kolejność w grupie: priorytet, termin — z zapytania)
$mz_today  = strtotime(date('Y-m-d'));
$mz_groups = ['over' => ['Po terminie', 'is-bad', []], 'today' => ['Dziś', 'is-warn', []], 'week' => ['Najbliższe 7 dni', '', []],
              'later' => ['Później', '', []], 'none' => ['Bez terminu', '', []]];
foreach ($my_tasks as $t) {
    $d = $t['due_date'] ? (int)floor((strtotime($t['due_date']) - $mz_today) / 86400) : null;
    $k = $d === null ? 'none' : ($d < 0 ? 'over' : ($d === 0 ? 'today' : ($d <= 7 ? 'week' : 'later')));
    $t['_days'] = $d;
    $mz_groups[$k][2][] = $t;
}
$mz_due = static function (?int $d, ?string $date): array {
    if ($d === null) return ['', ''];
    if ($d < 0)   return [abs($d) === 1 ? 'wczoraj' : abs($d) . ' dni po terminie', 'is-bad'];
    if ($d === 0) return ['dziś', 'is-warn'];
    if ($d === 1) return ['jutro', 'is-warn'];
    return [$d <= 7 ? 'za ' . $d . ' dni' : date('d.m', strtotime((string)$date)), ''];
};
?>
<style>
/* ══ Moje zadania (2026-11-10) — Metro jak reszta modułu: nagłówek z kafelkiem (bez kolorowego banera, na którym
   biały tekst bywał niewidoczny), kafelki liczb, zadania pogrupowane wg terminu, obok wolne i ostatnio ukończone ══ */
html body #tsk-main .mz-head-av{width:44px;height:44px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;background:var(--tsk-green);color:#fff;font-size:1rem;font-weight:700}
html body #tsk-main .mz-kpis{list-style:none;margin:0 0 1.25rem;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:4px}
html body #tsk-main .mz-kpis a{display:flex;flex-direction:column;gap:.1rem;padding:.8rem 1rem;background:var(--tm-light);border-left:4px solid var(--tm-field);color:var(--tsk-text);text-decoration:none}
html body #tsk-main .mz-kpis a:hover{background:var(--tm-light-h)}
html body #tsk-main .mz-kpis a:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #tsk-main .mz-kpis .is-ok{border-left-color:var(--tsk-green)}
html body #tsk-main .mz-kpis .is-warn{border-left-color:var(--tm-orange);background:#fff4e5}
html body #tsk-main .mz-kpis .is-bad{border-left-color:var(--tm-red);background:#fde7e9}
html body #tsk-main .mz-kpi-n{font-size:1.75rem;font-weight:300;line-height:1.1;font-variant-numeric:tabular-nums}
html body #tsk-main .mz-kpi-l{font-size:.82rem;color:#3f3f46}
html body #tsk-main .mz-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1.25rem;align-items:start}
@media(min-width:1200px){html body #tsk-main .mz-grid{grid-template-columns:minmax(0,1fr) 380px}}
html body #tsk-main .mz-card{background:#fff;border:1px solid var(--tm-line);border-top:2px solid var(--tsk-green);margin-bottom:1.25rem}
html body #tsk-main .mz-card-hd{display:flex;align-items:center;gap:.5rem;margin:0;padding:.75rem 1rem;border-bottom:1px solid var(--tm-line);font-size:1rem;font-weight:600;color:var(--tsk-text)}
html body #tsk-main .mz-card-hd .mz-n{margin-left:auto;min-width:1.5rem;padding:.05rem .4rem;background:var(--tm-light);font-size:.74rem;font-weight:700;text-align:center}
html body #tsk-main .mz-grp{margin:0;padding:.6rem 1rem .3rem;font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#3f3f46;display:flex;align-items:center;gap:.5rem}
html body #tsk-main .mz-grp.is-bad{color:var(--tm-red)}
html body #tsk-main .mz-grp.is-warn{color:var(--tm-orange)}
html body #tsk-main .mz-grp .mz-n{min-width:1.3rem;padding:.02rem .35rem;background:var(--tm-light);color:var(--tsk-text);font-size:.7rem;text-align:center;letter-spacing:0}
html body #tsk-main .mz-row{display:flex;align-items:center;gap:.7rem;padding:.6rem 1rem;border-top:1px solid #f0f0f0}
html body #tsk-main .mz-row:hover{background:#f7f7f7}
html body #tsk-main .mz-done-btn{width:28px;height:28px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid #8a8886;background:#fff;color:transparent;cursor:pointer}
html body #tsk-main .mz-done-btn:hover,html body #tsk-main .mz-done-btn:focus-visible{border-color:var(--tsk-green);color:var(--tsk-green);background:var(--tsk-green-bg)}
html body #tsk-main .mz-done-btn:focus-visible{outline:2px solid var(--tsk-text);outline-offset:1px}
html body #tsk-main .mz-done-btn.done-ok{background:var(--tsk-green);border-color:var(--tsk-green);color:#fff;pointer-events:none}
html body #tsk-main .mz-pri{width:10px;height:10px;flex-shrink:0}
html body #tsk-main .mz-body{flex:1;min-width:0}
html body #tsk-main .mz-title{display:block;font-weight:600;font-size:.92rem;color:var(--tsk-text);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html body #tsk-main .mz-title:hover,html body #tsk-main .mz-title:focus-visible{text-decoration:underline}
html body #tsk-main .mz-meta{font-size:.76rem;color:var(--tm-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html body #tsk-main .mz-due{flex-shrink:0;padding:.12rem .5rem;font-size:.76rem;font-weight:600;background:var(--tm-light);color:#3f3f46;white-space:nowrap}
html body #tsk-main .mz-due.is-bad{background:#fde7e9;color:var(--tm-red)}
html body #tsk-main .mz-due.is-warn{background:#fff4e5;color:#8a3a00}
html body #tsk-main .mz-open{flex-shrink:0;display:inline-flex;align-items:center;gap:.3rem;min-height:30px;padding:0 .65rem;border:1px solid #8a8886;color:var(--tsk-text);font-size:.8rem;font-weight:600;text-decoration:none}
html body #tsk-main .mz-open:hover{background:var(--tm-light);color:var(--tsk-text)}
html body #tsk-main .mz-empty{display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:2rem 1rem;text-align:center;color:#3f3f46}
html body #tsk-main .mz-empty>.bi{font-size:1.8rem;color:var(--tsk-green)}
html body #tsk-main .mz-done .mz-title{color:var(--tm-muted);text-decoration:line-through}
@media(prefers-reduced-motion:reduce){html body #tsk-main .mz-row{transition:none!important}}
</style>

<div class="tsk-main-content">

<div class="tk-head">
  <h1 class="tk-head-t">
    <span class="mz-head-av" aria-hidden="true"><?= h($_mz_initials) ?></span>
    <span>Moje zadania<span class="ws-set-sub"><?= h($user['name'] ?? '') ?><?= !empty($user['email']) ? ' · ' . h($user['email']) : '' ?></span></span>
  </h1>
  <div class="tk-head-act">
    <a class="tk-hbtn" href="<?= APP_URL ?>/tasks/router.php?_route=/kalendarz"><i class="bi bi-calendar3" aria-hidden="true"></i>Kalendarz</a>
    <a class="tk-hbtn tk-hbtn--cta" href="<?= APP_URL ?>/tasks/router.php?_route=/nowe"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe zadanie</a>
  </div>
</div>

<ul class="mz-kpis" aria-label="Podsumowanie moich zadań">
  <li><a href="#mz-assigned" class="<?= $kpi_active ? 'is-ok' : '' ?>"><span class="mz-kpi-n"><?= $kpi_active ?></span><span class="mz-kpi-l">aktywnych przypisanych</span></a></li>
  <li><a href="#mz-grp-over" class="<?= $kpi_overdue ? 'is-bad' : '' ?>"><span class="mz-kpi-n"><?= $kpi_overdue ?></span><span class="mz-kpi-l">po terminie</span></a></li>
  <li><a href="#mz-grp-today" class="<?= $kpi_today ? 'is-warn' : '' ?>"><span class="mz-kpi-n"><?= $kpi_today ?></span><span class="mz-kpi-l">z terminem dziś</span></a></li>
  <li><a href="#mz-done" class="<?= $kpi_done_w ? 'is-ok' : '' ?>"><span class="mz-kpi-n"><?= $kpi_done_w ?></span><span class="mz-kpi-l">ukończonych w 7 dni</span></a></li>
</ul>

<div class="mz-grid">
  <!-- Przypisane do mnie — pogrupowane wg terminu -->
  <section class="mz-card" id="mz-assigned" aria-labelledby="mz-assigned-h">
    <h2 class="mz-card-hd" id="mz-assigned-h"><i class="bi bi-person-check" aria-hidden="true" style="color:var(--tsk-green)"></i>Przypisane do mnie
      <?php if ($my_tasks): ?><span class="mz-n"><span class="visually-hidden">zadań: </span><?= count($my_tasks) ?></span><?php endif; ?></h2>
    <?php if (!$my_tasks): ?>
    <div class="mz-empty">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>
      <span>Nie masz żadnych aktywnych zadań — świetna robota!</span>
      <?php if ($available): ?><a class="mz-open" href="#mz-free">Zobacz wolne zadania w Twoich obszarach</a><?php endif; ?>
    </div>
    <?php else: foreach ($mz_groups as $gk => [$gl, $gc, $gt]): if (!$gt) continue; ?>
    <h3 class="mz-grp <?= $gc ?>" id="mz-grp-<?= $gk ?>"><?= h($gl) ?><span class="mz-n"><?= count($gt) ?></span></h3>
    <?php foreach ($gt as $t):
      $pri = (int)($t['priority'] ?? 2); [$dl, $dc] = $mz_due($t['_days'], $t['due_date']); ?>
    <div class="mz-row" id="mzrow-<?= (int)$t['id'] ?>">
      <button type="button" class="mz-done-btn" data-task-id="<?= (int)$t['id'] ?>" data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>"
              title="Oznacz jako ukończone" aria-label="Oznacz zadanie „<?= h($t['title']) ?>” jako ukończone"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
      <span class="mz-pri" style="background:<?= h($pri_colors[$pri] ?? '#94a3b8') ?>" title="Priorytet: <?= h($pri_labels[$pri] ?? 'Normalny') ?>"></span>
      <div class="mz-body">
        <a class="mz-title" href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
        <div class="mz-meta"><?= h($t['ws_name'] ?? '') ?><?= ($t['ws_name'] ?? '') !== '' ? ' › ' : '' ?><?= h($t['list_name'] ?? '') ?><span class="visually-hidden">, priorytet <?= h($pri_labels[$pri] ?? 'Normalny') ?></span></div>
      </div>
      <?php if ($dl !== ''): ?><span class="mz-due <?= $dc ?>"><?= h($dl) ?></span><?php endif; ?>
    </div>
    <?php endforeach; endforeach; endif; ?>
  </section>

  <aside>
    <?php if ($available): ?>
    <section class="mz-card" id="mz-free" aria-labelledby="mz-free-h">
      <h2 class="mz-card-hd" id="mz-free-h"><i class="bi bi-box-seam" aria-hidden="true" style="color:var(--tsk-green)"></i>Wolne w moich obszarach<span class="mz-n"><?= count($available) ?></span></h2>
      <?php foreach ($available as $t):
        $pri = (int)($t['priority'] ?? 2);
        $d = $t['due_date'] ? (int)floor((strtotime($t['due_date']) - $mz_today) / 86400) : null;
        [$dl, $dc] = $mz_due($d, $t['due_date']); ?>
      <div class="mz-row">
        <span class="mz-pri" style="background:<?= h($pri_colors[$pri] ?? '#94a3b8') ?>" title="Priorytet: <?= h($pri_labels[$pri] ?? 'Normalny') ?>"></span>
        <div class="mz-body">
          <a class="mz-title" href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
          <div class="mz-meta"><?= h($t['ws_name'] ?? '') ?><?= ($t['ws_name'] ?? '') !== '' ? ' › ' : '' ?><?= h($t['list_name'] ?? '') ?></div>
        </div>
        <?php if ($dl !== ''): ?><span class="mz-due <?= $dc ?>"><?= h($dl) ?></span><?php endif; ?>
        <a class="mz-open" href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>" aria-label="Otwórz i weź: <?= h($t['title']) ?>">Otwórz</a>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($recent_done): ?>
    <section class="mz-card mz-done" id="mz-done" aria-labelledby="mz-done-h">
      <h2 class="mz-card-hd" id="mz-done-h"><i class="bi bi-check-circle" aria-hidden="true" style="color:var(--tsk-green)"></i>Ostatnio ukończone<span class="mz-n"><?= count($recent_done) ?></span></h2>
      <?php foreach ($recent_done as $t):
        $days_ago = $t['completed_at'] ? (int)floor((time() - strtotime($t['completed_at'])) / 86400) : 0;
        $ago_txt  = $days_ago === 0 ? 'dziś' : ($days_ago === 1 ? 'wczoraj' : $days_ago . ' dni temu'); ?>
      <div class="mz-row">
        <i class="bi bi-check2" aria-hidden="true" style="color:var(--tsk-green)"></i>
        <div class="mz-body">
          <a class="mz-title" href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
          <div class="mz-meta"><?= h($t['ws_name'] ?? '') ?><?= ($t['ws_name'] ?? '') !== '' && ($t['list_name'] ?? '') !== '' ? ' › ' : '' ?><?= h($t['list_name'] ?? '') ?></div>
        </div>
        <span class="mz-due"><?= h($ago_txt) ?></span>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  </aside>
</div>

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

      var send = function (force) {
        return fetch(API, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'complete', id: parseInt(taskId, 10), _csrf: csrf, force: !!force })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          // Otwarte zależności — potwierdź i ponów z force
          return (!d.ok && d.blocked && !force && confirm(d.error + '\n\nMimo to oznaczyć jako ukończone?')) ? send(true) : d;
        });
      };
      send(false)
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
          if (!d.blocked) alert(d.error || d.msg || 'Błąd — spróbuj ponownie.');  // blocked = anulowane przez użytkownika
        }
      })
      .catch(function () { btn.disabled = false; });
    });
  });
})();


</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
