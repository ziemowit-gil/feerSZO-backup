<?php
/** modules/ti_dop/view/dop5_show.php — karta DOP5: dane, załączniki, podpisy (link / skan), PDF. Zmienne: $r. */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$d = $r['d']; $id = (int)$r['id'];
$title = $r['number'];
include __DIR__ . '/_head.php';
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="<?= h(route('dop5.index')) ?>">DOP5</a><a href="#" aria-current="page"><?= h($r['number']) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h1 class="me-auto mb-0"><span class="sym" style="background:#1d1d1d">DOP5</span> <?= h($r['number']) ?></h1>
  <a class="btn btn-metro dark" href="<?= h(route('dop5.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener">PDF</a></div>
<div class="tiles">
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1.3rem"><?= h(number_format((float)$d['kwota'], 2, ',', ' ')) ?> zł</span><span class="l">na dzień <?= h(ldDate($d['as_of'])) ?></span></div>
  <div class="tile" style="background:<?= $r['status'] === 'signed' ? '#008a00' : ($r['status'] === 'cancelled' ? '#647687' : '#f0a30a') ?>"><span class="n" style="font-size:1rem;font-weight:600"><?= h(['signing' => 'czeka na podpisy', 'signed' => 'podpisane', 'cancelled' => 'wycofane'][$r['status']] ?? $r['status']) ?></span><span class="l"><?= $r['sign_mode'] === 'paper' ? 'wersja papierowa' : 'podpis przez link' ?></span></div>
</div>
<div class="row g-3">
<section class="col-lg-7"><div class="panel h-100"><dl class="row small mb-0">
  <dt class="col-sm-4">Podmiot</dt><dd class="col-sm-8"><?= h($d['org']['name']) ?>, <?= h($d['org']['address']) ?> · NIP <?= h($d['org']['nip']) ?> · REGON <?= h($d['org']['regon']) ?> · KRS <?= h($d['org']['krs']) ?></dd>
  <dt class="col-sm-4">Umowa / projekt</dt><dd class="col-sm-8"><?= h($d['umowa_nr']) ?> — <?= h($d['tytul']) ?></dd>
  <dt class="col-sm-4">Rachunek</dt><dd class="col-sm-8"><?= h($d['rachunek']) ?> <?= h($d['rachunek_opis']) ?></dd>
  <?php if ($d['dop2'] !== ''): ?><dt class="col-sm-4">Transza DOP2</dt><dd class="col-sm-8"><?= h($d['dop2']) ?></dd><?php endif; ?>
  <dt class="col-sm-4">Załączniki</dt><dd class="col-sm-8"><?php foreach ($r['filesList'] as $i => $f): ?><div><a href="<?= h(route('dop5.file', ['id' => $id, 'i' => $i])) ?>" target="_blank" rel="noopener"><?= h($f['label'] . ': ' . $f['name']) ?></a></div><?php endforeach; ?><?= $r['filesList'] ? '' : '—' ?>
    <?php if ($r['scan_path']): ?><div><a href="<?= h(route('dop5.file', ['id' => $id, 'i' => 'skan'])) ?>" target="_blank" rel="noopener">skan podpisanego dokumentu</a></div><?php endif; ?></dd>
</dl></div></section>
<section class="col-lg-5"><div class="panel h-100"><h2 class="mt-0">Podpisy</h2>
  <ul class="list-unstyled small"><?php foreach ($r['signers'] as $sg): ?><li class="mb-2"><b><?= h($sg['name']) ?></b> — <?= h($sg['role'] === 'ksiegowy' ? 'główny księgowy / skarbnik' : $sg['function_title']) ?> <span class="text-secondary"><?= h($sg['basis']) ?></span><br>
    <?= $sg['signed_at'] ? ldChip('podpisano ' . ldDt($sg['signed_at']), '#008a00') : ldChip('czeka', '#f0a30a') ?>
    <?php if (!$sg['signed_at'] && $r['status'] === 'signing' && $sg['email'] !== ''): ?> <?= ldPost(route('dop5.link', ['id' => $id, 'sid' => $sg['id']]), 'Wyślij link ponownie', 'btn-outline-secondary') ?><?php endif; ?></li><?php endforeach; ?></ul>
  <?php if ($r['status'] === 'signing'): ?>
    <form method="post" action="<?= h(route('dop5.scan', ['id' => $id])) ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
      <div class="flex-fill"><label class="form-label small mb-0" for="sk">Skan dokumentu podpisanego odręcznie</label><input class="form-control form-control-sm" type="file" id="sk" name="skan" accept=".pdf,.jpg,.jpeg,.png"></div><button class="btn btn-sm btn-metro dark">Wgraj</button></form>
    <div class="mt-2"><?= ldPost(route('dop5.cancel', ['id' => $id]), 'Wycofaj', 'btn-outline-danger', [], 'Wycofać DOP5?') ?></div>
  <?php endif; ?>
</div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
