<?php
/**
 * tasks/settings/includes/workspaces_sidebar.php
 * Lista obszarów (lewa kolumna) — wydzielone z workspaces.php.
 * Oczekuje: $workspaces, $active_ws_id.
 */
?>
<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden">
  <div class="tw-bg-white tw-font-semibold tw-text-sm tw-py-2 tw-px-3 tw-border-b tw-border-slate-100">Obszary</div>
  <div class="list-group list-group-flush">
    <?php if (!$workspaces): ?>
    <div class="list-group-item tw-text-slate-400 tw-text-sm tw-py-3 tw-text-center">Brak obszarów.</div>
    <?php endif; ?>
    <?php foreach ($workspaces as $ws): ?>
    <a href="?ws=<?= $ws['id'] ?>"
       class="list-group-item list-group-item-action tw-flex tw-items-center tw-gap-2 tw-py-2
              <?= $ws['id'] == $active_ws_id ? 'active' : '' ?> <?= !$ws['is_active'] ? 'tw-opacity-55' : '' ?>">
      <span class="tw-w-[9px] tw-h-[9px] tw-rounded-full tw-shrink-0" style="background:<?= h($ws['color']) ?>"></span>
      <i class="bi <?= h($ws['icon']) ?> tw-text-[.85rem] tw-shrink-0"></i>
      <span class="tw-flex-1 tw-text-sm tw-truncate"><?= h($ws['name']) ?></span>
      <span class="tw-text-[.67rem] tw-bg-slate-100 tw-text-slate-500 tw-rounded-full tw-py-[.05rem] tw-px-[.4rem]">
        <?= (int)$ws['task_count'] ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
