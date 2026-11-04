<?php
/** Import licencji z TXT: formularz i podgląd przed zapisem. Wejście: $products, $preview (lub null), $token, $actor, $ctx. */
$title = 'Import licencji z TXT';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-3">Import licencji z TXT</h1>
<?php if (!$preview): ?>
<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" action="<?= h(route('lp.import.preview')) ?>" enctype="multipart/form-data" class="border p-3">
      <?= Router::csrfField() ?>
      <div class="mb-3"><label class="form-label" for="pid">Produkt (dla linii bez nagłówka <code>[Produkt]</code>)</label>
        <select id="pid" name="product_id" class="form-select"><option value="">— wskazany w pliku nagłówkami [Produkt] —</option>
          <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="mb-3"><label class="form-label" for="txt">Plik TXT (UTF-8 lub Windows-1250, maks. 2 MB)</label><input id="txt" type="file" name="txt" accept=".txt,text/plain" class="form-control"></div>
      <div class="mb-3"><label class="form-label" for="paste">…albo wklej listę</label><textarea id="paste" name="paste" rows="6" class="form-control font-monospace" spellcheck="false"></textarea></div>
      <button class="btn btn-primary">Pokaż podgląd</button>
      <span class="small text-muted ms-2">Nic nie zostanie zapisane, dopóki nie potwierdzisz podglądu.</span>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="border p-3 small">
      <strong>Format pliku</strong>
<pre class="mb-2 mt-2" style="white-space:pre-wrap"># komentarz
[Adobe Creative Cloud]
AAAA-BBBB-CCCC-DDDD
EEEE-FFFF-GGGG-HHHH ; 5 ; 2027-06-30 ; zakup 2026

[JetBrains All Products]
KLUCZ-1 ; 1 ; 31.12.2027</pre>
      Kolumny (średnik, tabulator lub <code>|</code>): <b>klucz</b>; liczba miejsc (domyślnie 1); ważność (RRRR-MM-DD lub DD.MM.RRRR); uwaga.
      Klucze już istniejące w produkcie są pomijane jako duplikaty. Brakujące produkty zostaną utworzone.
    </div>
  </div>
</div>
<?php else: $c = $preview['counts']; ?>
<div class="border p-3 mb-3">
  <strong>Podgląd:</strong> do dodania <span class="badge text-bg-success"><?= (int)$c['ok'] ?></span>,
  duplikaty <span class="badge text-bg-secondary"><?= (int)$c['dup'] ?></span>,
  błędne linie <span class="badge <?= $c['bad'] ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= (int)$c['bad'] ?></span>
  <?php if ($preview['new_products']): ?><div class="mt-2">Nowe produkty zostaną utworzone: <b><?= h(implode(', ', $preview['new_products'])) ?></b></div><?php endif; ?>
</div>
<?php if ($preview['errors']): ?>
<div class="alert alert-danger" role="alert"><strong>Błędne linie (zostaną pominięte):</strong>
  <ul class="mb-0"><?php foreach (array_slice($preview['errors'], 0, 50) as $e): ?><li>linia <?= (int)$e['line'] ?>: <?= h($e['msg']) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="table-responsive mb-3" style="max-height:420px;overflow:auto">
<table class="table table-sm"><caption class="visually-hidden">Podgląd importowanych licencji (kody zamaskowane)</caption>
  <thead><tr><th scope="col">Linia</th><th scope="col">Produkt</th><th scope="col">Klucz</th><th scope="col">Miejsca</th><th scope="col">Ważność</th><th scope="col">Uwaga</th><th scope="col">Status</th></tr></thead>
  <tbody>
  <?php foreach (array_slice($preview['rows'], 0, 300) as $r): ?>
    <tr class="<?= $r['duplicate'] ? 'text-muted' : '' ?>"><td><?= (int)$r['line'] ?></td><td><?= h($r['product']) ?><?= $r['product_id'] ? '' : ' <span class="badge text-bg-info">nowy</span>' ?></td>
      <td class="font-monospace">…<?= h(mb_substr($r['code'], -4)) ?></td><td><?= (int)$r['seats'] ?></td><td><?= h($r['valid_until'] ?? '—') ?></td><td><?= h($r['note']) ?></td>
      <td><?= $r['duplicate'] ? 'duplikat — pominięty' : 'dodany' ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (count($preview['rows']) > 300): ?><p class="small text-muted">Pokazano 300 z <?= count($preview['rows']) ?> linii.</p><?php endif; ?>
</div>
<form method="post" action="<?= h(route('lp.import.commit')) ?>" class="d-flex gap-2">
  <?= Router::csrfField() ?><input type="hidden" name="token" value="<?= h($token) ?>">
  <button class="btn btn-primary" <?= $c['ok'] ? '' : 'disabled' ?>>Zapisz do puli (<?= (int)$c['ok'] ?>)</button>
  <a class="btn btn-outline-secondary" href="<?= h(route('lp.import')) ?>">Anuluj</a>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
