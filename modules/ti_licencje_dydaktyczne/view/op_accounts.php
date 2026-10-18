<?php
/**
 * modules/ti_licencje_dydaktyczne/view/op_accounts.php — „Licencje › Operacje”: wyszukiwarka kont (uczestnicy i pracownicy),
 * kolejka operacji (do decyzji / w realizacji), puste wzory AM11–AM24.
 * Zmienne: $q, $found, $list, $status, $counts, $actor, $canApprove.
 */
require __DIR__ . '/_parts.php';
$title = 'Operacje na licencjach';
include __DIR__ . '/_head.php';
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Sprawy AM5 / AM7</a><a href="<?= h(route('ld.accounts')) ?>" aria-current="page">Operacje na licencjach</a></div>
<h1>Operacje na licencjach <small class="text-secondary fs-6">AM11–AM13 · AM20–AM24</small></h1>
<p class="text-secondary">Zarządzanie już przypisanymi licencjami i zasobami cyfrowymi osoby: zmiana płatnika (AM20 — bez akceptacji: doliczenie do zajęć albo wpłata na rachunek),
  zmiana planu, zawieszenie, cofnięcie, przedłużenie oraz wnioski o licencję z budżetu FEER, zwrot kosztów i licencję finansowaną zewnętrznie.
  Decyzja cyfrowa (stempel: ID, czas, IP), wydruk PDF, realizacja automatyczna albo zadaniem dla IT.</p>

<nav class="tiles" aria-label="Kolejka">
  <a class="tile" style="background:#f0a30a" href="<?= h(route('ld.accounts', ['status' => 'pending'])) ?>"><span class="n"><?= (int)$counts['pending'] ?></span><span class="l">Czeka na decyzję</span></a>
  <a class="tile" style="background:#1ba1e2" href="<?= h(route('ld.accounts', ['status' => 'approved'])) ?>"><span class="n"><?= (int)$counts['approved'] ?></span><span class="l">W realizacji (IT / finanse)</span></a>
  <a class="tile" style="background:#647687" href="<?= h(route('ld.accounts')) ?>"><span class="n"><i class="bi bi-list-ul" aria-hidden="true"></i></span><span class="l">Wszystkie</span></a>
</nav>

<section class="panel accent">
  <h2 class="mt-0">Znajdź konto</h2>
  <form method="get" action="licencjeDydaktyczne.php" class="d-flex gap-2" role="search">
    <input type="hidden" name="_route" value="/konta">
    <label class="visually-hidden" for="q">Imię, nazwisko lub e-mail</label>
    <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="imię, nazwisko, e-mail (uczestnik albo pracownik / dydaktyk)" autofocus>
    <button class="btn btn-metro dark">Szukaj</button>
  </form>
  <?php if ($q !== ''): ?>
    <?php if (!$found): ?><p class="text-secondary mt-2 mb-0">Nic nie znaleziono.</p><?php else: ?>
    <ul class="list-unstyled mt-2 mb-0">
      <?php foreach ($found as $f): ?><li class="py-1"><a href="<?= h(route('ld.account', ['kind' => $f['kind'], 'id' => $f['id']])) ?>"><b><?= h($f['name']) ?></b></a>
        <span class="text-secondary small"><?= h($f['role']) ?><?= $f['email'] ? ' · ' . h($f['email']) : '' ?></span></li><?php endforeach; ?>
    </ul><?php endif; ?>
  <?php endif; ?>
</section>

<div class="table-responsive panel p-0">
<table class="table table-hover mb-0">
  <thead><tr><th>Nr sprawy</th><th>Formularz</th><th>Osoba</th><th>Licencja</th><th>Złożono</th><th>Stan</th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="6" class="text-secondary p-3">Brak operacji<?= $status !== '' ? ' w tym stanie' : '' ?>.</td></tr><?php endif; ?>
  <?php foreach ($list as $o): ?>
    <tr>
      <td class="small text-nowrap"><a href="<?= h(route('ld.op', ['id' => $o['id']])) ?>"><?= h($o['number']) ?></a></td>
      <td><span class="sym" style="background:<?= h($o['formEnum']->color()) ?>"><?= h($o['formEnum']->value) ?></span> <span class="small"><?= h($o['formEnum']->short()) ?></span></td>
      <td><a href="<?= h(route('ld.account', ['kind' => $o['account_kind'], 'id' => $o['account_id']])) ?>"><?= h($o['account_name']) ?></a></td>
      <td class="small"><?= h($o['state']['sku'] ?? ($o['request']['produkt'] ?? $o['request']['licencja'] ?? '')) ?></td>
      <td class="small text-nowrap"><?= h(ldDt($o['created_at'])) ?></td>
      <td><?= ldChip($o['statusEnum']->label(), $o['statusEnum']->color()) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<section class="panel">
  <h2 class="mt-0">Wzory do druku</h2>
  <ul class="list-unstyled mb-0 small">
    <?php foreach (OperationForm::cases() as $f): ?><li class="mb-1"><span class="sym" style="background:<?= h($f->color()) ?>"><?= h($f->value) ?></span> <a href="<?= h(route('ld.op.blank', ['form' => $f->value])) ?>" target="_blank" rel="noopener"><?= h($f->title()) ?></a></li><?php endforeach; ?>
  </ul>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
