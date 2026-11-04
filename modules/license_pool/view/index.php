<?php
/** Pulpit puli licencji Fundacji. Wejście: $products, $imports, $actor, $ctx. */
$title = 'Pula licencji Fundacji';
include __DIR__ . '/_head.php';
$tot = ['seats' => 0, 'used' => 0];
foreach ($products as $p) { $tot['seats'] += (int)$p['seats_total']; $tot['used'] += (int)$p['seats_used']; }
?>
<h1 class="h3 mb-1">Pula licencji Fundacji</h1>
<p class="text-muted">Wspólna pula dla TI i IT SZO: licencje, które Fundacja ma na produkty. Importuj klucze z pliku TXT i przydzielaj je pracownikom, uczestnikom TI albo innym odbiorcom.</p>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="border p-3"><div class="fs-3"><?= count($products) ?></div><div class="small text-muted">produktów</div></div></div>
  <div class="col-6 col-md-3"><div class="border p-3"><div class="fs-3"><?= $tot['seats'] ?></div><div class="small text-muted">miejsc (ważnych licencji)</div></div></div>
  <div class="col-6 col-md-3"><div class="border p-3"><div class="fs-3"><?= $tot['used'] ?></div><div class="small text-muted">przydzielonych</div></div></div>
  <div class="col-6 col-md-3"><div class="border p-3"><div class="fs-3"><?= max(0, $tot['seats'] - $tot['used']) ?></div><div class="small text-muted">wolnych</div></div></div>
</div>

<div class="table-responsive mb-4">
<table class="table table-sm align-middle">
  <caption class="visually-hidden">Produkty w puli licencji</caption>
  <thead><tr><th scope="col">Produkt</th><th scope="col">Producent</th><th scope="col">Kategoria</th><th scope="col" class="text-end">Licencje</th><th scope="col" class="text-end">Miejsca (zajęte / razem)</th><th scope="col" class="text-end">Wolne</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): $free = max(0, (int)$p['seats_total'] - (int)$p['seats_used']); ?>
    <tr<?= $p['is_active'] ? '' : ' class="text-muted"' ?>>
      <td><a href="<?= h(route('lp.product', ['id' => $p['id']])) ?>"><?= h($p['name']) ?></a><?= $p['is_active'] ? '' : ' <span class="badge text-bg-secondary">wyłączony</span>' ?></td>
      <td><?= h($p['vendor']) ?></td><td><?= h($p['category']) ?></td>
      <td class="text-end"><?= (int)$p['licenses'] ?><?= $p['expired'] ? ' <span class="badge text-bg-warning" title="Wygasłe licencje">' . (int)$p['expired'] . ' wygasło</span>' : '' ?></td>
      <td class="text-end"><?= (int)$p['seats_used'] ?> / <?= (int)$p['seats_total'] ?></td>
      <td class="text-end"><span class="badge <?= $free > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $free ?></span></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= h(route('lp.product', ['id' => $p['id']])) ?>">Otwórz</a></td>
    </tr>
  <?php endforeach; if (!$products): ?>
    <tr><td colspan="7" class="text-muted">Pula jest pusta — <a href="<?= h(route('lp.import')) ?>">zaimportuj licencje z pliku TXT</a> albo dodaj produkt poniżej.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <h2 class="h5">Dodaj produkt</h2>
    <form method="post" action="<?= h(route('lp.product.save')) ?>" class="border p-3">
      <?= Router::csrfField() ?>
      <div class="mb-2"><label class="form-label" for="pn">Nazwa produktu</label><input id="pn" name="name" class="form-control" required maxlength="190"></div>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label" for="pv">Producent</label><input id="pv" name="vendor" class="form-control" maxlength="190"></div>
        <div class="col-md-6"><label class="form-label" for="pc">Kategoria</label><input id="pc" name="category" class="form-control" maxlength="190"></div>
      </div>
      <div class="my-2"><label class="form-label" for="pu">Link (logowanie / pobranie)</label><input id="pu" name="vendor_url" class="form-control" maxlength="500" placeholder="https://"></div>
      <button class="btn btn-primary">Dodaj produkt</button>
    </form>
  </div>
  <div class="col-lg-6">
    <h2 class="h5">Ostatnie importy</h2>
    <table class="table table-sm"><caption class="visually-hidden">Ostatnie importy z TXT</caption>
      <thead><tr><th scope="col">Data</th><th scope="col">Plik</th><th scope="col" class="text-end">Dodano</th><th scope="col" class="text-end">Duplikaty</th><th scope="col" class="text-end">Błędy</th></tr></thead>
      <tbody>
      <?php foreach ($imports as $i): ?>
        <tr><td><?= h(substr((string)$i['created_at'], 0, 16)) ?></td><td><?= h($i['filename']) ?><div class="small text-muted"><?= h($i['by_name'] ?? '') ?></div></td>
            <td class="text-end"><?= (int)$i['added'] ?></td><td class="text-end"><?= (int)$i['duplicates'] ?></td><td class="text-end"><?= (int)$i['invalid'] ?></td></tr>
      <?php endforeach; if (!$imports): ?><tr><td colspan="5" class="text-muted">Brak importów.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
