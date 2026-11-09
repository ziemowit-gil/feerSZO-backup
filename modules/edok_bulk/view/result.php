<?php
/**
 * modules/edok_bulk/view/result.php — podsumowanie ostatniej akcji zbiorczej (raz, nad listą EODoK).
 * Pas Metro z grubą lewą krawędzią: co zrobiono, co pominięto i dlaczego.
 */
require_once dirname(__DIR__) . '/logic/edokBulk.php';
$edbRes = edokBulkTakeResult();
if (!$edbRes) return;
$edbDone = $edbRes['done'] ?? [];
$edbSkip = $edbRes['skipped'] ?? [];
?>
<style>
html body .edb-result{display:flex;gap:.75rem;align-items:flex-start;background:#f2f2f2;border-left:6px solid #007a00;padding:.75rem 1rem;margin-bottom:1rem;font-size:.86rem;color:#111827}
html body .edb-result--warn{border-left-color:#c25100}
html body .edb-result ul{margin:.35rem 0 0;padding-left:1.1rem}
html body .edb-result li{margin:.1rem 0}
html body .edb-result .edb-x{margin-left:auto;background:transparent;border:0;color:#111827;font-size:1rem;line-height:1;padding:.1rem .3rem;cursor:pointer}
html body .edb-result .edb-x:hover,html body .edb-result .edb-x:focus-visible{background:#111827;color:#fff}
</style>
<div class="edb-result<?= $edbDone ? '' : ' edb-result--warn' ?>" role="status" id="edbResult">
  <i class="bi <?= $edbDone ? 'bi-check2-square' : 'bi-exclamation-triangle' ?>" aria-hidden="true" style="font-size:1.2rem;color:<?= $edbDone ? '#007a00' : '#c25100' ?>"></i>
  <div>
    <strong><?= h($edbRes['verb'] ?? 'Akcja zbiorcza') ?></strong> — wykonano: <strong><?= count($edbDone) ?></strong>, pominięto: <strong><?= count($edbSkip) ?></strong>.
    <?php if ($edbDone): ?>
    <details<?= count($edbDone) <= 10 ? ' open' : '' ?>><summary>Wykonano</summary>
      <ul><?php foreach (array_slice($edbDone, 0, 100) as $dn): ?><li><?= h($dn) ?></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
    <?php if ($edbSkip): ?>
    <details open><summary>Pominięto</summary>
      <ul>
        <?php foreach (array_slice($edbSkip, 0, 50) as $sk): ?>
        <li><code><?= h($sk['label']) ?></code><?= ($sk['title'] ?? '') !== '' ? ' ' . h(mb_strimwidth($sk['title'], 0, 70, '…')) : '' ?> — <em><?= h($sk['reason']) ?></em></li>
        <?php endforeach; ?>
        <?php if (count($edbSkip) > 50): ?><li>…i <?= count($edbSkip) - 50 ?> więcej</li><?php endif; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
  <button type="button" class="edb-x" aria-label="Ukryj podsumowanie" onclick="this.parentNode.remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
