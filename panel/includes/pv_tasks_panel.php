<?php
/**
 * panel/includes/pv_tasks_panel.php — Panel „Moje zadania" (Bootstrap + waniliowy JS).
 *
 * Markup renderowany serwerowo: przypisane zadania z przyciskiem „Ukończ" oraz
 * dostępne zadania z przyciskiem „Weź". Optymistyczne akcje (bez przeładowania)
 * obsługuje wspólny pv_enhance.php ([data-pv-tasks]) — reużywa endpointów
 * /tasks/api/task.php i /tasks/api/claim.php oraz podgląd w Bootstrapowym
 * offcanvas (/tasks/detail.php). Gdy JS zawiedzie, tytuły są linkami do giełdy.
 *
 * Wymaga w zasięgu: $_pv_tasks_mine, $_pv_tasks_open (array), $_open_tasks_total,
 *   $_task_inbox_unread, $csrf_panel, APP_URL, h().
 */
$_pv_tasks_mine = $_pv_tasks_mine ?? [];
$_pv_tasks_open = $_pv_tasks_open ?? [];

$_pri_dot = [4 => '#dc2626', 3 => '#f59e0b', 2 => '#3b82f6', 1 => '#94a3b8'];

/** Etykieta terminu: [txt, kolor, overdue]. */
$_due = function (?string $ymd): ?array {
    if (!$ymd) return null;
    $d = strtotime($ymd . ' 00:00:00');
    if (!$d) return null;
    $today = strtotime('today');
    $diff  = (int)round(($d - $today) / 86400);
    if ($diff < 0)   return ['Po terminie', '#dc2626', true];
    if ($diff === 0) return ['Dzisiaj', '#d97706', false];
    if ($diff === 1) return ['Jutro', '#d97706', false];
    return [date('d.m', $d), $diff <= 3 ? '#d97706' : '#94a3b8', false];
};
?>
<div id="panel-tasks-card"
     data-pv-tasks
     data-base="<?= h(rtrim(APP_URL, '/')) ?>"
     data-csrf="<?= h($csrf_panel) ?>">
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2 px-3">
      <i class="bi bi-table text-success" aria-hidden="true"></i>
      <h2 class="h6 fw-bold mb-0 flex-grow-1">Moje zadania<span data-task-mine-count><?= $_pv_tasks_mine ? ' (' . count($_pv_tasks_mine) . ')' : '' ?></span></h2>
      <?php if ($_task_inbox_unread > 0): ?>
      <a href="<?= APP_URL ?>/tasks/inbox.php" class="badge bg-primary text-decoration-none"
         aria-label="<?= (int)$_task_inbox_unread ?> nieprzeczytanych wiadomości">
        <i class="bi bi-chat me-1" aria-hidden="true"></i><?= (int)$_task_inbox_unread ?> nowych
      </a>
      <?php endif; ?>
      <?php if ($_pv_tasks_open): ?>
      <span class="badge" style="background:#dcfce7;color:#15803d;font-size:.72rem"><?= count($_pv_tasks_open) ?> dostępnych</span>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm btn-outline-success ms-1" aria-label="Otwórz giełdę zadań">
        <i class="bi bi-grid-3x2-gap me-1" aria-hidden="true"></i>Zadania
      </a>
    </div>
    <div class="card-body p-3">
      <?php if (!$_pv_tasks_mine && !$_pv_tasks_open): ?>
      <div class="text-center py-3 text-muted">
        <i class="bi bi-inbox d-block mb-2" style="font-size:1.8rem;opacity:.25" aria-hidden="true"></i>
        <p class="small mb-2">Brak zadań — wszystko ogarnięte! 🎉</p>
        <a href="<?= APP_URL ?>/tasks/index.php?status=open" class="btn btn-sm btn-outline-success">
          <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Przeglądaj dostępne zadania
        </a>
      </div>
      <?php else: ?>

      <?php if ($_pv_tasks_mine): ?>
      <div data-task-mine-section>
        <p class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem">Przypisane do mnie</p>
        <ul class="list-unstyled mb-2" role="list" aria-label="Moje zadania" data-task-mine-list>
          <?php foreach ($_pv_tasks_mine as $t):
            $di = $_due($t['due_date'] ?? null);
          ?>
          <li role="listitem" data-task-row data-task-mine data-task-title="<?= h($t['title']) ?>"
              style="display:flex;align-items:center;gap:.65rem;padding:.55rem 0;border-bottom:1px solid #f8fafc">
            <span style="width:9px;height:9px;border-radius:50%;background:<?= $_pri_dot[(int)($t['priority'] ?? 1)] ?? '#94a3b8' ?>;flex-shrink:0" aria-hidden="true"></span>
            <div style="flex:1;min-width:0">
              <button type="button" data-task-detail="<?= (int)$t['id'] ?>"
                      style="all:unset;cursor:pointer;font-size:.86rem;font-weight:600;color:#0f172a;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                      aria-label="Otwórz szczegóły: <?= h($t['title']) ?>"><?= h($t['title']) ?></button>
              <div style="font-size:.72rem;color:#94a3b8;display:flex;align-items:center;gap:.35rem">
                <span style="width:6px;height:6px;border-radius:50%;background:<?= h($t['ws_color'] ?? '#cbd5e1') ?>" aria-hidden="true"></span>
                <?= h(($t['ws_name'] ?? '') . (!empty($t['list_name']) ? ' › ' . $t['list_name'] : '')) ?>
              </div>
            </div>
            <?php if ($di): ?>
            <span style="font-size:.72rem;font-weight:600;white-space:nowrap;color:<?= $di[1] ?>">
              <?php if ($di[2]): ?><i class="bi bi-alarm" aria-hidden="true"></i> <?php endif; ?><?= h($di[0]) ?>
            </span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" data-task-complete="<?= (int)$t['id'] ?>"
                    style="font-size:.72rem;white-space:nowrap;flex-shrink:0"
                    aria-label="Oznacz jako ukończone: <?= h($t['title']) ?>">
              <i class="bi bi-check2" aria-hidden="true"></i>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($_pv_tasks_open): ?>
      <p class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin:.5rem 0 .3rem">Dostępne — możesz wziąć</p>
      <ul class="list-unstyled mb-0" role="list" aria-label="Dostępne zadania do wzięcia">
        <?php foreach ($_pv_tasks_open as $t):
          $di = $_due($t['due_date'] ?? null);
        ?>
        <li role="listitem" data-task-row data-task-title="<?= h($t['title']) ?>"
            style="display:flex;align-items:center;gap:.65rem;padding:.5rem;border-bottom:1px solid #f8fafc;background:#fafffe;border-radius:6px">
          <span style="width:9px;height:9px;border-radius:50%;background:<?= $_pri_dot[(int)($t['priority'] ?? 1)] ?? '#94a3b8' ?>;flex-shrink:0" aria-hidden="true"></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:.85rem;font-weight:500;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($t['title']) ?></div>
            <div style="font-size:.71rem;color:#94a3b8">
              <span style="width:6px;height:6px;border-radius:50%;background:<?= h($t['ws_color'] ?? '#cbd5e1') ?>;display:inline-block;margin-right:.2rem" aria-hidden="true"></span>
              <?= h($t['ws_name'] ?? '') ?><?= $di ? ' · ' . h($di[0]) : '' ?>
            </div>
          </div>
          <button type="button" class="btn btn-sm py-0 px-2" data-task-claim="<?= (int)$t['id'] ?>"
                  style="background:#059669;color:#fff;border:none;font-size:.74rem;white-space:nowrap;flex-shrink:0"
                  aria-label="Weź zadanie: <?= h($t['title']) ?>">
            <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Weź
          </button>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- SR announce -->
<div id="pv-tasks-sr" aria-live="polite" aria-atomic="true" class="visually-hidden"></div>

<!-- Offcanvas szczegółów zadania -->
<div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="panelTaskOffcanvas"
     role="dialog" aria-labelledby="panelTaskOffcanvasLabel" aria-modal="true">
  <div class="offcanvas-header border-bottom py-2">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="panelTaskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-success" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body p-0 overflow-auto" id="panelTaskOffcanvasBody" aria-live="polite" aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/pv_enhance.php'; ?>
