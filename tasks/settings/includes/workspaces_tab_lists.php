<?php
/**
 * tasks/settings/includes/workspaces_tab_lists.php
 * Zakładka "Kolumny" — wydzielone z workspaces.php.
 * Oczekuje: $lists, $active_ws_id.
 */
?>
<div class="card border-0 shadow-sm mb-2">
  <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
    <span class="small fw-semibold">Kolejność i konfiguracja kolumn</span>
    <button class="btn btn-sm btn-outline-primary btn-sm"
            onclick="openListModal(0,<?= $active_ws_id ?>)">
      <i class="bi bi-plus-lg me-1"></i>Dodaj kolumnę
    </button>
  </div>
  <div class="list-group list-group-flush" id="lists-sortable">
    <?php foreach ($lists as $list): ?>
    <div class="list-group-item tw-flex tw-items-center tw-gap-3 tw-py-2"
         data-list-id="<?= $list['id'] ?>">
      <i class="bi bi-grip-vertical tw-text-slate-400 tw-cursor-grab tw-text-[.85rem]"></i>
      <span class="tw-w-[10px] tw-h-[10px] tw-rounded-[2px] tw-shrink-0" style="background:<?= h($list['color']?:'#e2e8f0') ?>"></span>
      <span class="tw-flex-1 tw-text-sm tw-font-semibold">
        <?= h($list['name']) ?>
        <?php if ($list['is_done_state']): ?>
        <i class="bi bi-check-circle-fill tw-text-green-600 tw-ml-1 tw-text-[.72rem]"></i>
        <?php endif; ?>
        <?php if ($list['wip_limit']): ?>
        <span class="tw-text-slate-400 tw-font-normal tw-ml-1 tw-text-[.72rem]">WIP:<?= $list['wip_limit'] ?></span>
        <?php endif; ?>
      </span>
      <span class="badge bg-secondary bg-opacity-25 text-secondary tw-text-[.65rem]">
        <?= (int)$list['task_count'] ?>
      </span>
      <div class="tw-flex tw-gap-1 tw-shrink-0">
        <button class="btn btn-outline-secondary tw-py-[.18rem] tw-px-[.45rem] tw-text-[.72rem]"
                onclick="openListModal(<?= $list['id'] ?>,<?= $active_ws_id ?>,'<?= h(addslashes($list['name'])) ?>','<?= h($list['color']) ?>',<?= $list['is_done_state'] ?>,<?= $list['wip_limit']?:'null' ?>)">
          <i class="bi bi-pencil"></i>
        </button>
        <form method="post" class="d-inline" onsubmit="return confirmDelete(<?= $list['task_count'] ?>)">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="delete_list">
          <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
          <input type="hidden" name="list_id" value="<?= $list['id'] ?>">
          <button class="btn btn-outline-danger tw-py-[.18rem] tw-px-[.45rem] tw-text-[.72rem]">
            <i class="bi bi-trash"></i>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<form method="post" id="reorder-form">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="reorder_lists">
  <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
  <input type="hidden" name="order"   id="lists-order-input" value="">
  <button type="submit" id="save-order-btn" class="btn btn-sm btn-success d-none">
    <i class="bi bi-check2 me-1"></i>Zapisz kolejność
  </button>
</form>
