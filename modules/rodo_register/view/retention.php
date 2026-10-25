<?php
/** Retencja — reguły i raport kandydatów (bez automatycznego kasowania). Zmienne: $rows. */
$PAGE_TITLE = 'Retencja danych'; $active = 'rodo.retention';
include __DIR__ . '/_head.php';
?>
<p class="text-muted">Raport pokazuje, ile wierszy jest starszych niż ustalony okres przechowywania. <strong>Nic nie jest usuwane automatycznie</strong> — decyzję o usunięciu lub anonimizacji podejmuje człowiek (CRM ma osobny mechanizm: <code>includes/crm_retention.php</code>).</p>
<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Reguły retencji</caption>
<thead><tr><th scope="col">Zbiór</th><th scope="col">Tabela.kolumna daty</th><th scope="col" class="text-end">Okres (mies.)</th><th scope="col" class="text-end">Wierszy</th><th scope="col" class="text-end">Po terminie</th><th scope="col">Uwagi</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= h($r['zbior']) ?></td><td><code><?= h($r['tabela'] . '.' . $r['kolumna_daty']) ?></code></td><td class="text-end"><?= (int)$r['miesiecy'] ?></td>
  <?php if ($r['blad']): ?><td colspan="2" class="text-danger"><?= h($r['blad']) ?></td><?php else: ?><td class="text-end"><?= (int)$r['razem'] ?></td><td class="text-end <?= $r['kandydatow'] ? 'fw-bold text-warning' : '' ?>"><?= (int)$r['kandydatow'] ?></td><?php endif; ?>
  <td class="small"><?= h($r['uwagi']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post" action="<?= h(route('rodo.retention.save')) ?>" class="row g-2 align-items-end"><?= csrf_field() ?>
  <div class="col-md-2"><label class="form-label" for="r-z">Zbiór</label><input id="r-z" name="zbior" class="form-control form-control-sm"></div>
  <div class="col-md-3"><label class="form-label" for="r-t">Tabela</label><input id="r-t" name="tabela" class="form-control form-control-sm" required></div>
  <div class="col-md-2"><label class="form-label" for="r-c">Kolumna daty</label><input id="r-c" name="kolumna_daty" class="form-control form-control-sm" value="created_at" required></div>
  <div class="col-md-1"><label class="form-label" for="r-m">Mies.</label><input id="r-m" type="number" min="1" name="miesiecy" class="form-control form-control-sm" value="12"></div>
  <div class="col-md-3"><label class="form-label" for="r-u">Uwagi</label><input id="r-u" name="uwagi" class="form-control form-control-sm"></div>
  <div class="col-md-1"><button class="btn btn-sm btn-primary" type="submit">Dodaj</button></div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
