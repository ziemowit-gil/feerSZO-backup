<?php
/**
 * _syllabus_ref.php — wymagania i kryteria oceniania z sylabusa przedmiotu.
 *
 * Jeden fragment używany w trzech miejscach: w zakładce Sylabus (pełny wykaz),
 * w protokole i w e-dzienniku (jako podręczna ściąga przy wystawianiu ocen).
 * Wcześniej te pozycje istniały tylko w module administracyjnym i na wydruku
 * PDF sylabusa, więc prowadzący ich nie widział.
 *
 * Wejście:
 *   $cur_course           — kurs, którego sylabus pokazujemy,
 *   $syl_ref_kinds        — które rodzaje pozycji (domyślnie wymagania + kryteria),
 *   $syl_ref_collapsed    — true = zwinięte w <details> (przy wpisywaniu ocen),
 *   $syl_ref_title        — nagłówek karty (opcjonalnie).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_syllabus.php';

$syl_ref_kinds     = $syl_ref_kinds     ?? ['requirement', 'criterion'];
$syl_ref_collapsed = $syl_ref_collapsed ?? false;
$syl_ref_title     = $syl_ref_title     ?? 'Wymagania i kryteria oceniania';

$syl_ref = ti_course_syllabus((int)$cur_course);
$syl_ref_items = [];
if ($syl_ref) {
    foreach ($syl_ref_kinds as $_k) {
        $rows = ti_syllabus_items((int)$syl_ref['id'], $_k, true);
        if ($rows) $syl_ref_items[$_k] = $rows;
    }
}

// Bez sylabusa albo bez pozycji nie pokazujemy pustej karty — poza zakładką
// Sylabus, gdzie brak treści też jest informacją.
if (!$syl_ref_items && $syl_ref_collapsed) return;
?>

<?php if ($syl_ref_collapsed): ?>
<div class="card">
  <div class="card-body py-2">
    <details>
      <summary class="small fw-semibold" style="cursor:pointer">
        <i class="bi bi-award me-1" aria-hidden="true"></i><?= h($syl_ref_title) ?>
        <span class="fw-normal text-body-secondary">— z sylabusa „<?= h($syl_ref['title']) ?>”</span>
      </summary>
      <div class="mt-2">
        <?php include __DIR__ . '/_syllabus_ref_body.php'; ?>
      </div>
    </details>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span><?= h($syl_ref_title) ?></span>
    <?php if ($syl_ref): ?>
    <span class="ms-auto small fw-normal text-body-secondary">
      z sylabusa „<?= h($syl_ref['title']) ?>”, wersja <?= h($syl_ref['version']) ?>
    </span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$syl_ref): ?>
    <p class="small text-body-secondary mb-0">
      Przedmiot tego kursu nie ma sylabusa wzorcowego — wymagania i kryteria oceniania
      prowadzi administracja w module Zajęć TI.
    </p>
    <?php elseif (!$syl_ref_items): ?>
    <p class="small text-body-secondary mb-0">
      Sylabus „<?= h($syl_ref['title']) ?>” nie ma jeszcze wpisanych wymagań ani kryteriów oceniania.
    </p>
    <?php else: ?>
    <?php include __DIR__ . '/_syllabus_ref_body.php'; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
