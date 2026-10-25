<?php
/** Rejestr czynności przetwarzania (art. 30). Zmienne: $rows. */
$PAGE_TITLE = 'Rejestr czynności przetwarzania'; $active = 'rodo.activities';
include __DIR__ . '/_head.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <p class="text-muted mb-0">Rejestr z art. 30 RODO. Wpisy startowe pochodzą z analizy kodu — <strong>potwierdź je i zatwierdź</strong> (podstawy prawne i terminy usunięcia to propozycje).</p>
  <div class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm" href="<?= h(route('rodo.report', ['typ' => 'czynnosci'])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Drukuj rejestr</a>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h(route('rodo.activities.csv')) ?>"><i class="bi bi-download" aria-hidden="true"></i> Eksport CSV (dla UODO)</a>
  <a class="btn btn-primary btn-sm" href="<?= h(route('rodo.activity', ['id' => 0])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowa czynność</a></div>
</div>
<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Czynności przetwarzania</caption>
<thead><tr><th scope="col">Zbiór</th><th scope="col">Forma zbioru</th><th scope="col">Podstawa</th><th scope="col">Dane szczególne</th><th scope="col">DPIA</th><th scope="col">Status</th><th scope="col">Przegląd</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr>
  <td><a href="<?= h(route('rodo.activity', ['id' => $a['id']])) ?>"><?= h($a['zbior']) ?></a></td><td class="small"><?= h(['elektroniczna'=>'elektroniczna','papierowa'=>'papierowa','mieszana'=>'mieszana'][$a['forma']] ?? $a['forma']) ?></td><td class="small"><?= h($a['podstawa']) ?></td>
  <td><?= $a['szczegolne'] ? '<span class="badge text-bg-warning">tak</span>' : 'nie' ?></td><td><?= h($a['dpia']) ?></td>
  <td><span class="badge <?= $a['status'] === 'zatwierdzone' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h($a['status']) ?></span></td>
  <td class="small"><?= h($a['przegladniete_at'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
