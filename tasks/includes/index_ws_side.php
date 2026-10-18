<?php
/**
 * tasks/includes/index_ws_side.php — kolumna obszarów roboczych po lewej (lista zadań).
 * Wymaga z index.php: $workspaces (task_user_workspaces), $ws_id, $view_mode.
 * Na wąskim ekranie kolumna zamienia się w poziomy, przewijany pasek nad listą.
 */
$_wss_view = $view_mode === 'kanban' ? '&view=kanban' : '';
?>
<nav class="tk-ws-side" aria-label="Obszary robocze">
  <div class="tk-ws-side-h">Obszary</div>
  <ul class="tk-ws-side-list">
    <?php foreach ($workspaces as $_wss): $_wss_on = (int)$_wss['id'] === (int)$ws_id; ?>
    <li>
      <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= (int)$_wss['id'] ?><?= $_wss_view ?>"
         class="tk-ws-side-link<?= $_wss_on ? ' active' : '' ?>"<?= $_wss_on ? ' aria-current="page"' : '' ?>
         style="--ws:<?= h($_wss['color'] ?: '#5f5f5f') ?>">
        <i class="bi <?= h($_wss['icon'] ?: 'bi-folder') ?>" aria-hidden="true"></i>
        <span class="tk-ws-side-name"><?= h($_wss['name']) ?></span>
        <span class="tk-ws-side-cnt" aria-label="<?= (int)$_wss['task_count'] ?> zadań"><?= (int)$_wss['task_count'] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!empty($_tsk_is_any_leader)): ?>
  <a href="<?= APP_URL ?>/tasks/settings/workspaces.php" class="tk-ws-side-manage">
    <i class="bi bi-sliders" aria-hidden="true"></i>Zarządzaj obszarami
  </a>
  <?php endif; ?>
</nav>
