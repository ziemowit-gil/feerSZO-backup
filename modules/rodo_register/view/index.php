<?php
/** Pulpit Obsługi RODO. Zmienne: $d (liczniki), $open (otwarte wnioski), $breaches (otwarte naruszenia). */
$PAGE_TITLE = 'Obsługa RODO'; $active = 'rodo.index';
include __DIR__ . '/_head.php';
$tile = fn(string $l, $v, string $cls = '') => '<div class="col-6 col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">' . h($l) . '</div><div class="fs-2 fw-semibold ' . $cls . '">' . h((string)$v) . '</div></div></div></div>';
?>
<div class="row g-3 mb-4">
  <?= $tile('Otwarte wnioski osób', $d['wnioski_otwarte']) ?>
  <?= $tile('Wnioski po terminie', $d['wnioski_po_terminie'], $d['wnioski_po_terminie'] ? 'text-danger' : '') ?>
  <?= $tile('Otwarte naruszenia', $d['naruszenia_otwarte'], $d['naruszenia_otwarte'] ? 'text-danger' : '') ?>
  <?= $tile('Czynności jako szkic', $d['czynnosci_szkic']) ?>
  <?= $tile('Zbiory wymagające DPIA', $d['dpia_wymagane']) ?>
  <?= $tile('Podmioty bez umowy powierzenia', $d['podmioty_bez_umowy'], $d['podmioty_bez_umowy'] ? 'text-warning' : '') ?>
</div>
<?php if (!$d['klucz_osobny']): ?>
<div class="alert alert-warning"><strong>Szyfrowanie używa awaryjnie APP_KEY.</strong> Ustaw osobny klucz: <code>php cli/fieldCrypto.php keygen</code> i wklej wynik do <code>config.local.php</code> — zmiana APP_KEY nie unieważni wtedy zaszyfrowanych danych.</div>
<?php endif; ?>
<h2 class="h5">Wnioski do obsłużenia</h2>
<?php if (!$open): ?><p class="text-muted">Brak otwartych wniosków.</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Otwarte wnioski osób</caption>
  <thead><tr><th scope="col">Nr</th><th scope="col">Rodzaj</th><th scope="col">Termin</th><th scope="col">Status</th></tr></thead><tbody>
  <?php foreach ($open as $r): ?><tr>
    <td><a href="<?= h(route('rodo.request', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td><td><?= h(RODO_REQUEST_TYPES[$r['typ']] ?? $r['typ']) ?></td>
    <td><?= h($r['termin']) ?> <?php if ($r['po_terminie']): ?><span class="badge text-bg-danger">po terminie</span><?php else: ?><span class="text-muted small">(<?= (int)$r['dni'] ?> dni)</span><?php endif; ?></td>
    <td><span class="badge <?= h(RODO_REQUEST_STATUS[$r['status']][1]) ?>"><?= h(RODO_REQUEST_STATUS[$r['status']][0]) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<h2 class="h5 mt-4">Naruszenia w toku</h2>
<?php if (!$breaches): ?><p class="text-muted">Brak otwartych naruszeń.</p><?php else: ?>
<ul class="list-group"><?php foreach ($breaches as $b): ?>
  <li class="list-group-item d-flex justify-content-between"><a href="<?= h(route('rodo.breach', ['id' => $b['id']])) ?>">#<?= (int)$b['id'] ?> — wykryto <?= h($b['wykryto_at']) ?></a>
    <span>do UODO: <?= h($b['uodo_do']) ?> <?php if ($b['uodo_po_terminie']): ?><span class="badge text-bg-danger">po terminie</span><?php endif; ?></span></li>
<?php endforeach; ?></ul><?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
