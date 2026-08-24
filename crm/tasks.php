<?php
/**
 * crm/tasks.php — zadania CRM.
 *
 * To NIE jest moduł Zadań. Zadanie CRM to przypomnienie przy kartotece albo
 * sprawie — oddzwonić, wysłać ofertę, dopytać o podpis. Nie ma obszaru, listy
 * ani tablicy; ma osobę, termin i haczyk do odhaczenia. Zob. includes/crm_tasks.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_tasks.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('contacts', 'read');
crm_migrate();
crm_tasks_migrate();

$PAGE_TITLE = 'CRM — Zadania';
$uid        = (int)(current_user()['id'] ?? 0);
$can_write  = crm_can('contacts', 'write');

// ── Akcje (POST → redirect, żeby odświeżenie nie powtórzyło operacji) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('danger', 'Brak uprawnień do zadań CRM.'); }
    else {
        $op = (string)($_POST['_op'] ?? '');
        $tid = (int)($_POST['task_id'] ?? 0);

        if ($op === 'add') {
            $r = crm_task_add([
                'title'       => (string)($_POST['title'] ?? ''),
                'contact_id'  => (int)($_POST['contact_id'] ?? 0),
                'case_id'     => (int)($_POST['case_id'] ?? 0),
                'due_date'    => (string)($_POST['due_date'] ?? ''),
                'priority'    => (string)($_POST['priority'] ?? 'medium'),
                'owner_id'    => (int)($_POST['owner_id'] ?? 0),
                'description' => (string)($_POST['description'] ?? ''),
            ]);
            if ($r['ok']) {
                $who = (int)($_POST['owner_id'] ?? 0);
                flash_set('success', $who && $who !== $uid ? 'Zadanie zlecone.' : 'Zadanie dodane.');
            } else {
                flash_set('danger', $r['error']);
            }
        } elseif ($op === 'done' || $op === 'undone') {
            crm_task_set_done($tid, $op === 'done');
            flash_set('success', $op === 'done' ? 'Zadanie odhaczone.' : 'Zadanie znów otwarte.');
        } elseif ($op === 'delete') {
            crm_task_delete($tid);
            flash_set('success', 'Zadanie usunięte.');
        } elseif ($op === 'update') {
            crm_task_update($tid, [
                'due_date' => (string)($_POST['due_date'] ?? ''),
                'owner_id' => (int)($_POST['owner_id'] ?? 0),
                'priority' => (string)($_POST['priority'] ?? 'medium'),
            ]);
            flash_set('success', 'Zadanie zmienione.');
        }
    }
    // Powrót tam, skąd przyszło zlecenie — okno zadania otwiera się też
    // z kartoteki i ze sprawy, a te ekrany mają swój własny kontekst pracy.
    $back = (string)($_POST['back'] ?? '');
    $safe = $back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//');
    header('Location: ' . ($safe ? $back : APP_URL . '/crm/tasks.php?' . http_build_query(array_filter([
        'scope' => $_POST['scope'] ?? null,
    ]))));
    exit;
}

// Same zadania mają dziś wspólny ekran z działaniami (crm/activities.php) — dwie
// listy rzeczy do zrobienia znaczyły dwa miejsca do sprawdzania. Ten adres zostaje
// dla linków zapisanych wcześniej i dla filtrowania po jednym kontakcie.
if (!isset($_GET['contact_id']) && ($_GET['keep'] ?? '') !== '1') {
    header('Location: ' . APP_URL . '/crm/activities.php');
    exit;
}

// ── Filtry ─────────────────────────────────────────────────────────────────
$scope  = in_array($_GET['scope'] ?? 'mine', ['mine', 'all', 'overdue', 'done'], true) ? $_GET['scope'] : 'mine';
$search = trim((string)($_GET['q'] ?? ''));
$hl     = (int)($_GET['hl'] ?? 0);

$f = ['q' => $search ?: null];
if ($scope === 'mine')    { $f['owner_id'] = $uid; $f['status'] = 'open'; }
if ($scope === 'all')     { $f['status'] = 'open'; }
if ($scope === 'overdue') { $f['overdue'] = 1;     $f['status'] = 'open'; }
if ($scope === 'done')    { $f['status'] = 'done'; }

$tasks  = crm_tasks_list($f);
$counts = crm_tasks_counts($uid);

include __DIR__ . '/includes/header_crm.php';
?>
<style>
/* Wiersz zadania: haczyk, treść, kontekst, termin — bez ozdobników */
.ct-row { display:flex; align-items:flex-start; gap:.6rem; padding:.5rem .7rem;
  border:1px solid var(--crm-border); border-radius:9px; background:#fff; margin-bottom:.3rem }
.ct-row:hover { background:#FAFBFC }
.ct-row.is-hl { border-color:var(--crm-primary); background:var(--crm-primary-bg) }
.ct-row.is-done .ct-title { text-decoration:line-through; color:#9CA3AF }
.ct-check { border:none; background:none; padding:0; line-height:1; font-size:1.05rem; color:#9CA3AF; cursor:pointer }
.ct-check:hover { color:var(--crm-primary) }
.ct-row.is-done .ct-check { color:#2E844A }
.ct-title { font-size:.87rem; font-weight:500; color:#111827 }
.ct-meta  { font-size:.74rem; color:#6B7280; display:flex; flex-wrap:wrap; gap:.15rem .5rem; margin-top:.1rem }
.ct-meta a { color:inherit }
.ct-due   { font-size:.74rem; white-space:nowrap; padding:.05rem .4rem; border-radius:2rem; background:#F3F4F6; color:#4B5563 }
.ct-due.is-late { background:#FEF2F2; color:#B91C1C; font-weight:600 }
.ct-prio { width:7px; height:7px; border-radius:50%; flex-shrink:0; margin-top:.42rem }
.ct-del { border:none; background:none; color:#D1D5DB; font-size:.8rem; padding:0 .2rem; cursor:pointer }
.ct-del:hover { color:#B91C1C }
</style>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-check2-square" style="color:var(--crm-primary)"></i> Zadania CRM</div>
    <div class="crm-page-subtitle">
      Przypomnienia przy kartotekach i sprawach — osobne od modułu
      <a href="<?= APP_URL ?>/tasks/index.php">Zadania</a>, który służy do pracy zespołowej.
    </div>
  </div>
  <?php if ($can_write): ?>
  <div class="crm-page-actions">
    <button class="btn btn-crm-primary btn-sm" data-bs-toggle="modal" data-bs-target="#crmTaskModal">
      <i class="bi bi-person-up me-1"></i>Zleć zadanie
    </button>
  </div>
  <?php endif; ?>
</div>

<div class="d-flex flex-wrap gap-1 mb-3">
  <?php
  $pills = [
      'mine'    => ['Moje',        'bi-person-check', $counts['mine']],
      'all'     => ['Wszystkie',   'bi-list-ul',      $counts['open']],
      'overdue' => ['Po terminie', 'bi-alarm',        $counts['overdue']],
      'done'    => ['Odhaczone',   'bi-check2-all',   null],
  ];
  foreach ($pills as $pk => [$plabel, $pico, $pn]):
      $on = $scope === $pk; ?>
  <a class="crm-chip<?= $on ? ' active' : '' ?>"
     href="?<?= http_build_query(array_filter(['scope' => $pk, 'q' => $search ?: null])) ?>"
     <?= $on ? 'aria-current="true"' : '' ?>>
    <i class="bi <?= $pico ?> me-1" aria-hidden="true"></i><?= h($plabel) ?>
    <?php if ($pn !== null): ?><span class="ms-1 fw-semibold"><?= (int)$pn ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>

  <form method="get" class="ms-auto d-flex gap-1">
    <input type="hidden" name="scope" value="<?= h($scope) ?>">
    <input name="q" class="form-control form-control-sm" style="max-width:220px" value="<?= h($search) ?>"
           placeholder="Szukaj w zadaniach…" aria-label="Szukaj w zadaniach" data-search-input>
    <button class="btn btn-crm-outline btn-sm"><i class="bi bi-search"></i></button>
  </form>
</div>

<?php if (!$tasks): ?>
<div class="crm-list-card text-center text-muted py-5">
  <i class="bi bi-check2-circle d-block mb-2" style="font-size:2rem;opacity:.25" aria-hidden="true"></i>
  <div style="font-size:.9rem">
    <?= $scope === 'done' ? 'Nic jeszcze nie zostało odhaczone.' : 'Brak zadań w tym widoku.' ?>
  </div>
  <?php if ($can_write && $scope !== 'done'): ?>
  <button class="btn btn-crm-outline btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#crmTaskModal">
    <i class="bi bi-plus-lg me-1"></i>Zleć pierwsze zadanie
  </button>
  <?php endif; ?>
</div>
<?php else: ?>
<?php foreach ($tasks as $t):
    $done = $t['status'] === 'done';
    [$due_txt, $late] = crm_task_due_label($t['due_date'] ?? null);
    $prio = CRM_TASK_PRIORITIES[$t['priority']] ?? CRM_TASK_PRIORITIES['medium']; ?>
<div class="ct-row<?= $done ? ' is-done' : '' ?><?= $hl === (int)$t['id'] ? ' is-hl' : '' ?>">
  <span class="ct-prio" style="background:<?= h($prio['color']) ?>"
        title="Pilność: <?= h($prio['label']) ?>" aria-hidden="true"></span>

  <?php if ($can_write): ?>
  <form method="post" class="d-inline">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_op" value="<?= $done ? 'undone' : 'done' ?>">
    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
    <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
    <button class="ct-check" title="<?= $done ? 'Cofnij odhaczenie' : 'Odhacz zadanie' ?>"
            aria-label="<?= $done ? 'Cofnij odhaczenie' : 'Odhacz' ?>: <?= h($t['title']) ?>">
      <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>" aria-hidden="true"></i>
    </button>
  </form>
  <?php else: ?>
  <i class="bi <?= $done ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?> mt-1" aria-hidden="true"></i>
  <?php endif; ?>

  <div class="flex-grow-1 overflow-hidden">
    <div class="ct-title"><?= h($t['title']) ?></div>
    <div class="ct-meta">
      <?php if (!empty($t['owner_name'])): ?>
      <span><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($t['owner_name']) ?></span>
      <?php endif; ?>
      <?php if (!empty($t['contact_id'])): ?>
      <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$t['contact_id'] ?>">
        <i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i><?= h($t['contact_name'] ?: 'kartoteka') ?>
      </a>
      <?php endif; ?>
      <?php if (!empty($t['case_id'])): ?>
      <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$t['case_id'] ?>">
        <i class="bi bi-briefcase me-1" aria-hidden="true"></i><?= h(mb_strimwidth((string)($t['case_title'] ?? 'sprawa'), 0, 40, '…')) ?>
      </a>
      <?php endif; ?>
      <?php if ($done && !empty($t['done_at'])): ?>
      <span>odhaczone <?= h(date('d.m.Y', strtotime((string)$t['done_at']))) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($t['description'])): ?>
    <div class="ct-meta" style="color:#9CA3AF"><?= h(mb_strimwidth((string)$t['description'], 0, 140, '…')) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($due_txt !== ''): ?>
  <span class="ct-due<?= $late ? ' is-late' : '' ?>"
        title="<?= $late ? 'Termin minął' : 'Termin' ?>"><?= h($due_txt) ?></span>
  <?php endif; ?>

  <?php if ($can_write): ?>
  <form method="post" class="d-inline" onsubmit="return confirm('Usunąć to zadanie?')">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_op" value="delete">
    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
    <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
    <button class="ct-del" title="Usuń zadanie" aria-label="Usuń zadanie: <?= h($t['title']) ?>">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($can_write) include __DIR__ . '/includes/task_modal.php'; ?>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
