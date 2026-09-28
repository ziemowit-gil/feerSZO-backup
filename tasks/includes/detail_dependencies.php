<!--
 tasks/includes/detail_dependencies.php — Zależności zadania („czeka na” / „blokuje”).
 Wymaga: $blockers, $blocking, $dep_candidates, $can_edit, $id. Zapis: tasks/api/dependency.php.
-->
<?php if ($blockers || $blocking || $can_edit): ?>
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-diagram-2" aria-hidden="true"></i>Zależności
    <?php $td_open_blk = count(array_filter($blockers, fn($b) => !$b['completed_at'] && !$b['is_done_state'])); ?>
    <?php if ($td_open_blk): ?>
    <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem" title="Nieukończone zadania, na które czeka to zadanie">czeka na <?= $td_open_blk ?></span>
    <?php endif; ?>
  </div>

  <div class="td-files-sub">Czeka na</div>
  <ul class="td-dep-list" aria-label="Zadania, na które czeka to zadanie">
    <?php foreach ($blockers as $b):
      $b_done = $b['completed_at'] || $b['is_done_state']; ?>
    <li class="td-dep-row">
      <i class="bi <?= $b_done ? 'bi-check-circle-fill text-success' : 'bi-hourglass-split text-warning' ?>" aria-hidden="true"></i>
      <button type="button" class="td-dep-link" onclick="openTask(<?= (int)$b['id'] ?>)"><?= h($b['title']) ?></button>
      <span class="text-muted small"><?= $b_done ? 'ukończone' : h($b['list_name']) ?></span>
      <?php if ($can_edit): ?>
      <button type="button" class="btn-close ms-auto" style="font-size:.55rem"
              onclick="tdDepRemove(<?= (int)$b['id'] ?>)" aria-label="Usuń zależność od <?= h($b['title']) ?>"></button>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
    <?php if (!$blockers): ?><li class="text-muted small">Brak — zadanie można kończyć niezależnie.</li><?php endif; ?>
  </ul>

  <?php if ($can_edit && $dep_candidates): ?>
  <div class="d-flex gap-1 mt-1">
    <label class="visually-hidden" for="td-dep-add">Dodaj zadanie, na które czeka to zadanie</label>
    <select id="td-dep-add" class="form-select form-select-sm">
      <option value="">+ Dodaj „czeka na”…</option>
      <?php foreach ($dep_candidates as $c): ?>
      <option value="<?= (int)$c['id'] ?>"><?= h(mb_strimwidth($c['title'], 0, 70, '…')) ?><?= $c['completed_at'] ? ' (ukończone)' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tdDepAdd()">Dodaj</button>
  </div>
  <?php endif; ?>

  <?php if ($blocking): ?>
  <div class="td-files-sub mt-2">Blokuje</div>
  <ul class="td-dep-list" aria-label="Zadania, które czekają na to zadanie">
    <?php foreach ($blocking as $b): ?>
    <li class="td-dep-row">
      <i class="bi bi-arrow-return-right text-muted" aria-hidden="true"></i>
      <button type="button" class="td-dep-link" onclick="openTask(<?= (int)$b['id'] ?>)"><?= h($b['title']) ?></button>
      <span class="text-muted small"><?= $b['completed_at'] ? 'ukończone' : h($b['list_name']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
