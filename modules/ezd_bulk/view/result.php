<?php
/**
 * modules/ezd_bulk/view/result.php — podsumowanie ostatniej akcji masowej (raz, nad listą koszulek).
 * Pas Metro z grubą lewą krawędzią: ile zrobiono, ile pominięto i dlaczego.
 */
require_once dirname(__DIR__) . '/logic/ezdBulk.php';
$ezbRes = ezdBulkTakeResult();
if (!$ezbRes) return;
$ezbDone = count($ezbRes['done'] ?? []);
$ezbSkip = $ezbRes['skipped'] ?? [];
$ezbPl   = fn(int $n) => $n === 1 ? 'koszulkę' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 'koszulki' : 'koszulek');
?>
<style>
html body .ezb-result{display:flex;gap:.75rem;align-items:flex-start;background:var(--em-light,#f2f2f2);border-left:6px solid var(--m-green,#007a00);padding:.75rem 1rem;margin-bottom:1rem;font-size:.86rem;color:var(--em-ink,#111827)}
html body .ezb-result--warn{border-left-color:var(--m-orange,#c25100)}
html body .ezb-result ul{margin:.35rem 0 0;padding-left:1.1rem}
html body .ezb-result li{margin:.1rem 0}
html body .ezb-result .ezb-x{margin-left:auto;background:transparent;border:0;color:var(--em-ink,#111827);font-size:1rem;line-height:1;padding:.1rem .3rem;cursor:pointer}
html body .ezb-result .ezb-x:hover{background:var(--em-ink,#111827);color:#fff}
</style>
<div class="ezb-result<?= $ezbDone ? '' : ' ezb-result--warn' ?>" role="status" id="ezbResult">
  <i class="bi <?= $ezbDone ? 'bi-check2-square' : 'bi-exclamation-triangle' ?>" aria-hidden="true" style="font-size:1.2rem;color:<?= $ezbDone ? 'var(--m-green,#007a00)' : 'var(--m-orange,#c25100)' ?>"></i>
  <div>
    <strong><?= h($ezbRes['verb'] ?? 'Wykonano') ?> <?= $ezbDone ?> <?= $ezbPl($ezbDone) ?></strong><?= $ezbSkip ? ', pominięto ' . count($ezbSkip) . ':' : '.' ?>
    <?php if ($ezbSkip): ?>
    <ul>
      <?php foreach (array_slice($ezbSkip, 0, 30) as $sk): ?>
      <li><span class="ezx-znak"><?= h($sk['label']) ?></span><?= $sk['title'] !== '' ? ' ' . h(mb_strimwidth($sk['title'], 0, 70, '…')) : '' ?> — <em><?= h($sk['reason']) ?></em></li>
      <?php endforeach; ?>
      <?php if (count($ezbSkip) > 30): ?><li>…i <?= count($ezbSkip) - 30 ?> więcej</li><?php endif; ?>
    </ul>
    <?php endif; ?>
  </div>
  <button type="button" class="ezb-x" aria-label="Ukryj podsumowanie" onclick="this.parentNode.remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
