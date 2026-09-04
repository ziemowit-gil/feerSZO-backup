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

<style type="text/tailwindcss">
/* ══ Moje zadania ═══════════════════════════════════════════════════════════ */
.mz-wrap {
  --tz-line: #E5E9F0;
  --tz-muted: #5b6472;
  --tz-ink: #111827;
  --tz-50: rgba(5,150,105,.07);
  @apply tw-max-w-[1000px];
}

/* Hero */
.mz-hero {
  @apply tw-relative tw-rounded-2xl tw-overflow-hidden tw-mb-6 tw-bg-cover tw-bg-center;
  min-height: 130px;
  background-color: <?= h($_mz_brand) ?>;
}
.mz-hero__overlay {
  @apply tw-absolute tw-inset-0;
  background: linear-gradient(135deg, rgba(0,0,0,.45) 0%, rgba(0,0,0,.15) 100%);
}
.mz-hero__body {
  @apply tw-relative tw-z-[1] tw-flex tw-items-center tw-gap-[1.1rem] tw-py-6 tw-px-6;
}
.mz-hero__av {
  @apply tw-w-16 tw-h-16 tw-rounded-full tw-flex-shrink-0 tw-flex tw-items-center tw-justify-center tw-text-2xl tw-font-extrabold tw-overflow-hidden tw-select-none;
  color: <?= h($_mz_brand) ?>;
  background: rgba(255,255,255,.9); border: 3px solid rgba(255,255,255,.6);
}
.mz-hero__av img { @apply tw-w-full tw-h-full tw-object-cover tw-rounded-full; }
.mz-hero__info { @apply tw-flex-1 tw-min-w-0; }
.mz-hero__name { @apply tw-text-xl tw-font-extrabold tw-text-white tw-leading-tight;
  text-shadow: 0 1px 3px rgba(0,0,0,.4); }
.mz-hero__sub  { @apply tw-text-[.82rem] tw-mt-1; color: rgba(255,255,255,.8); }
.mz-hero__stats { @apply tw-flex tw-gap-5 tw-mt-[.65rem] tw-flex-wrap; }
.mz-hero__stat  { @apply tw-text-[.78rem]; color: rgba(255,255,255,.9); }
.mz-hero__stat strong { @apply tw-text-[1.05rem] tw-font-extrabold tw-block tw-leading-tight; }
.mz-hero__actions { @apply tw-flex tw-gap-2 tw-flex-shrink-0; }
.mz-hero__btn {
  @apply tw-text-white tw-rounded-lg tw-py-[.35rem] tw-px-[.65rem] tw-text-[.78rem] tw-cursor-pointer tw-flex tw-items-center tw-gap-[.3rem] tw-transition-colors;
  background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.35);
  backdrop-filter: blur(4px);
}
.mz-hero__btn:hover { background: rgba(255,255,255,.3); }
/* Formularz zdjęcia */
.mz-hero__photo-form {
  @apply tw-hidden tw-absolute tw-bottom-0 tw-left-0 tw-right-0 tw-z-10 tw-py-[.65rem] tw-px-5 tw-items-center tw-gap-[.65rem];
  background: rgba(0,0,0,.75); backdrop-filter: blur(6px);
}
.mz-hero__photo-form.visible { @apply tw-flex; }
.mz-hero__photo-inp {
  @apply tw-flex-1 tw-text-white tw-rounded-lg tw-py-[.35rem] tw-px-3 tw-text-[.81rem];
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3);
}
.mz-hero__photo-inp::placeholder { color: rgba(255,255,255,.5); }
.mz-hero__photo-inp:focus { outline: none; border-color: rgba(255,255,255,.7); }
@media (max-width: 600px) {
  .mz-hero__body   { @apply tw-flex-wrap; }
  .mz-hero__actions { @apply tw-absolute tw-top-[.85rem] tw-right-[.85rem]; }
  .mz-hero__stats  { @apply tw-gap-[.85rem]; }
}

/* KPI karty */
.mz-kpi-row { @apply tw-grid tw-grid-cols-4 tw-gap-[.9rem] tw-mb-6; }
@media(max-width:700px) { .mz-kpi-row { @apply tw-grid-cols-2; } }

.mz-kpi { @apply tw-bg-white tw-rounded-2xl tw-py-4 tw-px-[1.1rem] tw-flex tw-flex-col tw-gap-1;
  border: 1px solid var(--tz-line); box-shadow:0 1px 3px rgba(16,24,40,.07); }
.mz-kpi__val { @apply tw-text-3xl tw-font-extrabold tw-leading-none; }
.mz-kpi__lbl { @apply tw-text-[.78rem] tw-font-semibold tw-uppercase tw-tracking-wide; color:var(--tz-muted); }
.mz-kpi--ok   .mz-kpi__val { @apply tw-text-emerald-600; }
.mz-kpi--warn .mz-kpi__val { @apply tw-text-amber-600; }
.mz-kpi--red  .mz-kpi__val { @apply tw-text-red-600; }
.mz-kpi--neu  .mz-kpi__val { @apply tw-text-blue-500; }

/* Sekcja-karta */
.mz-card { @apply tw-bg-white tw-rounded-2xl tw-overflow-hidden tw-mb-5;
  border: 1px solid var(--tz-line); box-shadow:0 1px 3px rgba(16,24,40,.08); }
.mz-card__hd { @apply tw-py-[.85rem] tw-px-[1.1rem] tw-flex tw-items-center tw-gap-[.55rem] tw-font-bold tw-text-[.95rem];
  border-bottom:1px solid var(--tz-line); color:var(--tz-ink); }
.mz-card__hd i { color:var(--tsk-green); }
.mz-card__hd .badge-cnt { @apply tw-ml-auto tw-text-[.72rem] tw-font-bold tw-rounded-full tw-py-[.15em] tw-px-[.6em];
  background:var(--tz-50); color:var(--tsk-green); }

/* Wiersze zadań */
.mz-task-row { @apply tw-flex tw-items-center tw-gap-3 tw-py-[.7rem] tw-px-[1.1rem] tw-transition-colors;
  border-bottom:1px solid var(--tz-line); }
.mz-task-row:last-child { @apply tw-border-b-0; }
.mz-task-row:hover { background:var(--tz-50); }

.mz-dot { @apply tw-w-[10px] tw-h-[10px] tw-rounded-full tw-flex-shrink-0; }

.mz-task-body { @apply tw-flex-1 tw-min-w-0; }
.mz-task-title { @apply tw-font-semibold tw-text-[.92rem] tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis;
  color:var(--tz-ink); }
.mz-task-title a { @apply tw-text-inherit tw-no-underline; }
.mz-task-title a:hover { @apply tw-underline; }
.mz-task-meta  { @apply tw-text-xs tw-mt-[.1rem]; color:var(--tz-muted); }

.mz-due { @apply tw-text-[.77rem] tw-font-semibold tw-flex-shrink-0 tw-rounded-md tw-py-[.2em] tw-px-[.55em]; }
.mz-due--ok   { @apply tw-bg-green-50 tw-text-green-600; }
.mz-due--soon { @apply tw-bg-amber-50 tw-text-amber-700; }
.mz-due--over { @apply tw-bg-red-50 tw-text-red-600; }
.mz-due--none { @apply tw-bg-slate-100 tw-text-slate-400; }

.mz-done-btn { @apply tw-bg-transparent tw-rounded-full tw-w-7 tw-h-7 tw-flex tw-items-center tw-justify-center tw-cursor-pointer tw-text-slate-400 tw-flex-shrink-0 tw-transition-all;
  border:1px solid #d1d5db; }
.mz-done-btn:hover { @apply tw-border-emerald-600 tw-text-emerald-600 tw-bg-green-50; }
.mz-done-btn.done-ok { @apply tw-border-emerald-600 tw-bg-emerald-600 tw-text-white tw-pointer-events-none; }

/* Puste stany */
.mz-empty { @apply tw-py-8 tw-px-4 tw-text-center tw-text-[.9rem]; color:var(--tz-muted); }
.mz-empty i { @apply tw-text-3xl tw-text-slate-300 tw-block tw-mb-2; }

/* Ukończone (collapsible) */
.mz-done-row .mz-task-title { color:var(--tz-muted); @apply tw-line-through; }
</style>

<div class="tsk-main-content">
<div class="mz-wrap">

  <!-- Hero: tło marki lub zdjęcie usera -->
  <div class="mz-hero" id="mzHero">
    <div class="mz-hero__overlay" id="mzHeroOverlay"></div>
    <div class="mz-hero__body">
      <div class="mz-hero__av" id="mzHeroAv">
        <?= h($_mz_initials) ?>
      </div>
      <div class="mz-hero__info">
        <div class="mz-hero__name"><?= h($user['name'] ?? '') ?></div>
        <div class="mz-hero__sub"><?= h($user['email'] ?? '') ?></div>
        <div class="mz-hero__stats">
          <div class="mz-hero__stat">
            <strong><?= $kpi_active ?></strong>Aktywne
          </div>
          <?php if ($kpi_overdue): ?>
          <div class="mz-hero__stat" style="color:#fca5a5">
            <strong><?= $kpi_overdue ?></strong>Zaległe
          </div>
          <?php endif; ?>
          <div class="mz-hero__stat">
            <strong><?= $kpi_done_w ?></strong>Ukończone (7 dni)
          </div>
        </div>
      </div>
      <div class="mz-hero__actions">
        <button type="button" class="mz-hero__btn" id="mzHeroBgBtn"
                title="Zmień tło">
          <i class="bi bi-image" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <!-- Formularz ustawiania własnego zdjęcia -->
    <div class="mz-hero__photo-form" id="mzPhotoForm">
      <input type="url" class="mz-hero__photo-inp" id="mzPhotoUrl"
             placeholder="URL zdjęcia (https://...)">
      <button type="button" class="mz-hero__btn" id="mzPhotoSave">
        <i class="bi bi-check2" aria-hidden="true"></i>Ustaw
      </button>
      <button type="button" class="mz-hero__btn" id="mzPhotoClear">
        <i class="bi bi-x" aria-hidden="true"></i>Usuń
      </button>
      <button type="button" class="mz-hero__btn" id="mzPhotoClose">
        <i class="bi bi-chevron-down" aria-hidden="true"></i>
      </button>
    </div>
  </div>

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

/* --- Hero tła: localStorage mzHeroBg = '' | 'color' | url --- */
(function () {
  var BRAND   = '<?= addslashes($_mz_brand) ?>';
  var LS_KEY  = 'mzHeroBg';
  var hero    = document.getElementById('mzHero');
  var overlay = document.getElementById('mzHeroOverlay');
  var bgBtn   = document.getElementById('mzHeroBgBtn');
  var photoForm = document.getElementById('mzPhotoForm');
  var photoUrl  = document.getElementById('mzPhotoUrl');
  var photoSave = document.getElementById('mzPhotoSave');
  var photoClear = document.getElementById('mzPhotoClear');
  var photoClose = document.getElementById('mzPhotoClose');

  function applyBg(val) {
    if (!val || val === 'color') {
      hero.style.backgroundImage = '';
      hero.style.backgroundColor = BRAND;
      overlay.style.display = 'none';
    } else {
      hero.style.backgroundImage = 'url(' + val + ')';
      hero.style.backgroundColor = BRAND;
      overlay.style.display = '';
    }
  }

  var saved = localStorage.getItem(LS_KEY) || '';
  applyBg(saved);
  if (saved && saved !== 'color') {
    photoUrl.value = saved;
  }

  bgBtn.addEventListener('click', function () {
    photoForm.classList.toggle('visible');
  });

  photoSave.addEventListener('click', function () {
    var url = photoUrl.value.trim();
    if (!url) return;
    localStorage.setItem(LS_KEY, url);
    applyBg(url);
    photoForm.classList.remove('visible');
  });

  photoClear.addEventListener('click', function () {
    localStorage.removeItem(LS_KEY);
    photoUrl.value = '';
    applyBg('');
    photoForm.classList.remove('visible');
  });

  photoClose.addEventListener('click', function () {
    photoForm.classList.remove('visible');
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
