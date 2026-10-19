<!--
 tasks/includes/detail_links.php — Powiązania zadania (koszulka EZD, kontakt/firma CRM, uczestnik TI, umowa).
 Wymaga: $id, $can_edit. Zapis zwykłym formularzem (bez JS — fragment ładowany do okna zadania):
 POST tasks/router.php/powiazania i …/powiazania/usun, powrót na listę z otwartym zadaniem.
-->
<?php
require_once dirname(__DIR__) . '/lib/tasksLinks.php';
$td_links = tasksLinksOf((int)$id);
if ($td_links || $can_edit):
  $td_csrf = csrf_token();
  $td_base = APP_URL . '/tasks/router.php/powiazania';
?>
<div class="td-section">
  <div class="td-label"><i class="bi bi-link-45deg" aria-hidden="true"></i>Powiązania</div>
  <?php if ($td_links): ?>
  <ul class="list-unstyled small mb-2">
    <?php foreach ($td_links as $td_l): $td_d = tasksLinksDescribe($td_l['entity_type'], $td_l['entity_ref']); ?>
    <li class="d-flex align-items-center gap-2 py-1">
      <span class="badge text-bg-light border"><?= h($td_d['label']) ?></span>
      <?php if (!$td_d['exists']): ?>
        <span class="text-muted">rekord usunięty</span>
      <?php elseif ($td_d['can']): ?>
        <a href="<?= h($td_d['url']) ?>" target="_blank" rel="noopener"><?= h($td_d['title'] ?: 'otwórz') ?></a>
      <?php else: ?>
        <span class="text-muted" title="Nie masz dostępu do tego rekordu">bez dostępu</span>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <form method="post" action="<?= h($td_base . '/usun') ?>" class="ms-auto">
        <input type="hidden" name="_csrf" value="<?= h($td_csrf) ?>">
        <input type="hidden" name="task_id" value="<?= (int)$id ?>">
        <input type="hidden" name="type" value="<?= h($td_l['entity_type']) ?>">
        <input type="hidden" name="ref" value="<?= h($td_l['entity_ref']) ?>">
        <button type="submit" class="btn-close" style="font-size:.55rem" aria-label="Usuń powiązanie: <?= h($td_d['label'] . ' ' . $td_d['title']) ?>"></button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($can_edit): ?>
  <form method="post" action="<?= h($td_base) ?>" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="_csrf" value="<?= h($td_csrf) ?>">
    <input type="hidden" name="task_id" value="<?= (int)$id ?>">
    <label class="visually-hidden" for="td-link-url-<?= (int)$id ?>">Adres karty do powiązania</label>
    <input type="url" class="form-control form-control-sm" style="flex:1 1 16rem" id="td-link-url-<?= (int)$id ?>" name="url" required
           placeholder="Wklej adres koszulki EZD, kontaktu CRM, umowy albo konta uczestnika">
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-link me-1" aria-hidden="true"></i>Powiąż</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; unset($td_links, $td_l, $td_d, $td_csrf, $td_base); ?>
