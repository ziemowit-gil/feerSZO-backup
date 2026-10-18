<?php
/**
 * modules/ti_dop/view/form.php — nowy wniosek DOP1 / DOP2: wybór uczestnika, pola wzoru (sekcje I–IV), sposób podpisu
 * (link — e-mail + SMS, albo wersja papierowa ze skanem). Zmienne: $form, $c (uczestnik albo null), $clients, $q, $errors, $old.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Nowy wniosek ' . $form->value;
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div id="e-' . h($k) . '" class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="e-' . h($k) : '';
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="#" aria-current="page"><?= h($title) ?></a></div>
<h1><span class="sym" style="background:<?= h($form->color()) ?>"><?= h($form->value) ?></span> <?= h($form->title()) ?></h1>
<?php if (!$c): ?>
<section class="panel accent"><h2 class="mt-0">Uczestnik</h2>
  <form method="get" action="wnioskiDop.php" class="d-flex gap-2" role="search"><input type="hidden" name="_route" value="/nowy"><input type="hidden" name="form" value="<?= h($form->value) ?>">
    <label class="visually-hidden" for="q">Szukaj uczestnika</label><input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="imię, nazwisko, e-mail" autofocus><button class="btn btn-metro dark">Szukaj</button></form>
  <ul class="list-unstyled mt-2 mb-0"><?php foreach ($clients as $cl): ?><li class="py-1"><a class="btn btn-sm btn-metro" href="<?= h(route('dop.new', ['form' => $form->value, 'klient' => $cl['id']])) ?>">Wybierz</a> <?= h($cl['name']) ?> <span class="small text-secondary"><?= h($cl['email']) ?></span></li><?php endforeach; ?></ul>
</section>
<?php else: ?>
<div class="tiles">
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1.2rem;font-weight:600"><?= h($c['name']) ?></span><span class="l">Uczestnik #<?= (int)$c['id'] ?></span></div>
  <div class="tile" style="background:<?= $c['credit'] > 0 ? '#008a00' : '#647687' ?>"><span class="n" style="font-size:1.3rem"><?= h(number_format($c['credit'], 2, ',', ' ')) ?></span><span class="l">Nadpłata / saldo subkonta (zł)</span></div>
  <div class="tile" style="background:<?= $c['debt'] > 0 ? '#a20025' : '#647687' ?>"><span class="n" style="font-size:1.3rem"><?= h(number_format($c['debt'], 2, ',', ' ')) ?></span><span class="l">Niedopłata (zł)</span></div>
  <div class="tile" style="background:#6a00ff"><span class="n" style="font-size:.95rem;font-weight:600"><?= h($c['nrb'] ?: '—') ?></span><span class="l">Subkonto</span></div>
</div>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Popraw zaznaczone pola (<?= count($errors) ?>).</div><?php endif; ?>
<form method="post" action="<?= h(route('dop.new')) ?>" enctype="multipart/form-data" novalidate><?= Router::csrfField() ?>
<input type="hidden" name="form" value="<?= h($form->value) ?>"><input type="hidden" name="klient" value="<?= (int)$c['id'] ?>">
<section class="panel"><div class="row g-3"><div class="col-md-4"><label class="form-label" for="place">Miejscowość wypełnienia *</label><input class="form-control<?= $inv('place') ?>" id="place" name="place" value="<?= $v('place') ?>"><?= $err('place') ?></div>
  <div class="col-md-8 small text-secondary align-self-end">Data wypełnienia: dziś (<?= h(ldDate(ldToday())) ?>). Numer wniosku nada system: <?= h($form->value) ?>/NNNNNN/<?= date('Y') ?>.</div></div></section>
<?php foreach ($form->sections() as $roman => $secTitle):
  $fs = array_filter($form->fields(), fn($x) => $x[4] === $roman);
  if (!$fs) continue; ?>
<section class="panel"><h2 class="mt-0">Sekcja <?= h($roman) ?>: <?= h($secTitle) ?></h2>
  <?php if (($form === DopForm::Dop1 && $roman === 'II') || ($form === DopForm::Dop2 && $roman === 'III')): ?><p class="small"><?= h($form->statement()) ?></p><?php endif; ?>
  <div class="row g-3">
  <?php foreach ($fs as $k => [$label, $type, $req, $opts]): $id = 'f-' . $k; $lab = h($label) . ($req ? ' *' : ''); ?>
    <div class="<?= $type === 'check' ? 'col-12' : 'col-md-6' ?>">
      <?php if ($type === 'check'): ?><div class="form-check"><input class="form-check-input<?= $inv($k) ?>" type="checkbox" id="<?= $id ?>" name="<?= h($k) ?>" value="1"<?= !empty($old[$k]) ? ' checked' : '' ?>><label class="form-check-label" for="<?= $id ?>"><?= $lab ?></label></div>
      <?php elseif ($type === 'select'): ?><label class="form-label" for="<?= $id ?>"><?= $lab ?></label><select class="form-select<?= $inv($k) ?>" id="<?= $id ?>" name="<?= h($k) ?>"><?php foreach ($opts as $ov => $ol): ?><option value="<?= h($ov) ?>"<?= (string)($old[$k] ?? '') === (string)$ov ? ' selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?></select>
      <?php else: ?><label class="form-label" for="<?= $id ?>"><?= $lab ?></label><input class="form-control<?= $inv($k) ?>" id="<?= $id ?>" name="<?= h($k) ?>" value="<?= $v($k) ?>"<?= $type === 'date' ? ' type="date"' : ($type === 'money' ? ' inputmode="decimal"' : ($type === 'iban' ? ' inputmode="numeric" placeholder="PL __ ____ ____ ____ ____ ____ ____"' : '')) ?>><?php endif; ?>
      <?= $err($k) ?>
    </div>
  <?php endforeach; ?></div>
</section>
<?php endforeach; ?>
<section class="panel warn"><h2 class="mt-0">Podpis wnioskodawcy</h2>
  <div class="form-check"><input class="form-check-input" type="radio" name="sign_mode" id="sm1" value="link"<?= ($old['sign_mode'] ?? 'link') !== 'paper' ? ' checked' : '' ?>><label class="form-check-label" for="sm1"><b>Elektronicznie</b> — wyślij wnioskodawcy link (e-mail + SMS) do sprawdzenia i podpisu</label></div>
  <div class="form-check"><input class="form-check-input" type="radio" name="sign_mode" id="sm2" value="paper"<?= ($old['sign_mode'] ?? '') === 'paper' ? ' checked' : '' ?>><label class="form-check-label" for="sm2"><b>Wersja papierowa</b> — wniosek podpisany odręcznie (wgraj skan teraz albo później)</label></div>
  <?= $err('sign_mode') ?>
  <div class="mt-2"><label class="form-label small" for="skan">Skan podpisanego wniosku (PDF, JPG, PNG — przy wersji papierowej)</label><input class="form-control" type="file" id="skan" name="skan" accept=".pdf,.jpg,.jpeg,.png"></div>
</section>
<div class="d-flex gap-2 mb-4"><button class="btn btn-metro" type="submit">Złóż wniosek <?= h($form->value) ?></button><a class="btn btn-outline-secondary" href="<?= h(route('dop.index')) ?>">Anuluj</a></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
