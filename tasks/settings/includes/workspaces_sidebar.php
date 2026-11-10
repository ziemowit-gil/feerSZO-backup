<?php
/**
 * tasks/settings/includes/workspaces_sidebar.php
 * Lista obszarów (lewa kolumna) — wydzielone z workspaces.php.
 * Oczekuje: $workspaces, $active_ws_id.
 */
?>
<?php /* Ten sam wygląd co kolumna „Obszary” na liście zadań (klasy tk-ws-side*, kafelek w kolorze obszaru) */ ?>
<nav class="tk-ws-side" aria-label="Obszary do zarządzania">
  <div class="tk-ws-side-h">Obszary</div>
  <?php if (!$workspaces): ?>
  <p class="tk-ws-side-empty">Brak obszarów.</p>
  <?php endif; ?>
  <ul class="tk-ws-side-list">
    <?php foreach ($workspaces as $ws): $_on = (int)$ws['id'] === (int)$active_ws_id; $_c = (string)($ws['color'] ?: '#5f5f5f'); ?>
    <li>
      <a href="?ws=<?= (int)$ws['id'] ?>"
         class="tk-ws-side-link<?= $_on ? ' active' : '' ?><?= empty($ws['is_active']) ? ' is-inactive' : '' ?>"<?= $_on ? ' aria-current="page"' : '' ?>
         style="--ws:<?= h($_c) ?>;--ws-ink:<?= task_ws_ink($_c) ?>" title="<?= h($ws['name']) ?>">
        <span class="tk-ws-tile" aria-hidden="true"><i class="bi <?= h($ws['icon'] ?: 'bi-folder') ?>"></i></span>
        <span class="tk-ws-side-name"><?= h($ws['name']) ?><?php if (empty($ws['is_active'])): ?><span class="visually-hidden"> (nieaktywny)</span><?php endif; ?></span>
        <span class="tk-ws-side-cnt<?= (int)$ws['task_count'] ? '' : ' is-zero' ?>"><span class="visually-hidden">, zadań: </span><?= (int)$ws['task_count'] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</nav>
