<?php
/**
 * _syllabus_ref_body.php — same listy wymagań i kryteriów (bez obudowy karty).
 * Wejście: $syl_ref_items (kind => wiersze) z _syllabus_ref.php.
 */
?>
<?php foreach ($syl_ref_items as $_kind => $_rows): $_meta = TI_SYLLABUS_KINDS[$_kind] ?? ['label' => $_kind, 'icon' => 'bi-dot']; ?>
<div class="mb-2">
  <div class="small fw-semibold mb-1">
    <i class="bi <?= h($_meta['icon']) ?> me-1" aria-hidden="true"></i><?= h($_meta['label']) ?>
    <span class="badge bg-secondary ms-1"><?= count($_rows) ?></span>
  </div>
  <ol class="small mb-0">
    <?php foreach ($_rows as $_it): ?>
    <li class="mb-1">
      <strong><?= h($_it['title']) ?></strong>
      <?php if (trim((string)$_it['description']) !== ''): ?>
      <div class="text-body-secondary"><?= nl2br(h($_it['description'])) ?></div>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endforeach; ?>
