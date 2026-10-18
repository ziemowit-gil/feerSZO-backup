<?php
/**
 * modules/ti_licencje_dydaktyczne/view/op_form.php — nowa operacja / wniosek (AM11–AM13, AM20–AM24) dla konta.
 * Pola z definicji OperationForm::requestFields(); AM20: sposób płatności (doliczenie do zajęć z kwotą albo rachunek i kwota),
 * AM12: plik dowodu zakupu. Zmienne: $acc, $form, $res, $resources, $errors, $old, $orgAccounts, $actor.
 */
require __DIR__ . '/_parts.php';
$title = $form->value . ' — ' . $form->short();
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div id="e-' . h($k) . '" class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="e-' . h($k) : '';
$am20 = $form === OperationForm::Am20;
?>
<div class="pivot"><a href="<?= h(route('ld.accounts')) ?>">Operacje na licencjach</a><a href="<?= h(route('ld.account', ['kind' => $acc['kind'], 'id' => $acc['id']])) ?>"><?= h($acc['name']) ?></a><a href="#" aria-current="page"><?= h($form->value) ?></a></div>
<h1><span class="sym" style="background:<?= h($form->color()) ?>"><?= h($form->value) ?></span> <?= h($form->title()) ?></h1>
<p class="text-secondary"><?= h($acc['name']) ?> · <?= h($acc['role']) ?> · numer sprawy: <b><?= h(LD_OP_PREFIX . '-' . $form->value . '/' . LD_OP_ORG_ID . '/NNNNNNXX/' . date('Y') . '/' . $acc['sub_id']) ?></b> ·
  <?= $form->needsApproval() ? 'decyzję podejmuje kierownik (decyzja cyfrowa)' : '<b>bez akceptacji kierownika</b> — osoba potwierdza wniosek przez link (e-mail + SMS), zmiana wchodzi po potwierdzeniu' ?> · realizacja do <?= LD_REALIZATION_DAYS ?> dni.</p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Popraw zaznaczone pola (<?= count($errors) ?>).</div><?php endif; ?>

<form method="post" action="<?= h(route('ld.op.new')) ?>" enctype="multipart/form-data" novalidate>
<?= Router::csrfField() ?>
<input type="hidden" name="konto" value="<?= h($acc['kind'] . ':' . $acc['id']) ?>">
<input type="hidden" name="form" value="<?= h($form->value) ?>">

<?php if ($form->needsResource()): ?>
<section class="panel accent">
  <h2 class="mt-0">Licencja, której dotyczy operacja</h2>
  <?php if ($res): ?>
    <input type="hidden" name="zasob" value="<?= h($res['key']) ?>">
    <p class="mb-0"><b><?= h($res['sku']) ?></b> · <?= h($res['vendor']) ?> · <?= h($res['type']) ?> · <?= h(trim(ldDate($res['assigned_at']) . ' — ' . ldDate($res['expires_at']), ' —')) ?>
      · płatnik: <b><?= h($res['funding']['payer']) ?></b> (<?= h($res['funding']['source']) ?>)</p>
    <p class="small text-secondary mb-0"><?= h(LicenseResources::vendorCheck($res['key'])) ?></p>
  <?php else: ?>
    <label class="form-label" for="zasob">Wybierz licencję</label>
    <select class="form-select<?= $inv('resource') ?>" id="zasob" name="zasob"><option value="">— wybierz —</option>
      <?php foreach ($resources as $r): ?><option value="<?= h($r['key']) ?>"><?= h($r['sku'] . ' — ' . $r['vendor'] . ' (' . $r['source'] . ')') ?></option><?php endforeach; ?></select><?= $err('resource') ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <h2 class="mt-0"><?= $form->lifecycle() ? 'Dyspozycja' : 'Wniosek' ?></h2>
  <div class="row g-3">
  <?php foreach ($form->requestFields() as $k => [$label, $type, $req, $opts]):
      if ($am20 && in_array($k, ['rachunek', 'potwierdzenie'], true)) continue;
      $id = 'f-' . $k; $lab = h($label) . ($req ? ' *' : '');
      $wide = $type === 'textarea' ? 'col-12' : 'col-md-6'; ?>
    <div class="<?= $wide ?>"<?= $am20 && $k === 'kwota' ? ' id="kwotaBox"' : '' ?>>
      <?php if ($type === 'textarea'): ?>
        <label class="form-label" for="<?= $id ?>"><?= $lab ?></label><textarea class="form-control<?= $inv($k) ?>" id="<?= $id ?>" name="<?= h($k) ?>" rows="3"><?= $v($k) ?></textarea>
      <?php elseif ($type === 'select'): ?>
        <label class="form-label" for="<?= $id ?>"><?= $lab ?></label><select class="form-select<?= $inv($k) ?>" id="<?= $id ?>" name="<?= h($k) ?>"><option value="">— wybierz —</option>
          <?php foreach ($opts as $ov => $ol): ?><option value="<?= h($ov) ?>"<?= (string)($old[$k] ?? '') === (string)$ov ? ' selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?></select>
      <?php elseif ($type === 'check'): ?>
        <div class="form-check"><input class="form-check-input<?= $inv($k) ?>" type="checkbox" id="<?= $id ?>" name="<?= h($k) ?>" value="1"<?= !empty($old[$k]) ? ' checked' : '' ?>><label class="form-check-label" for="<?= $id ?>"><?= $lab ?></label></div>
      <?php else: ?>
        <label class="form-label" for="<?= $id ?>"><?= $lab ?></label>
        <input class="form-control<?= $inv($k) ?>" id="<?= $id ?>" name="<?= h($k) ?>" value="<?= $v($k) ?>"<?= $type === 'date' ? ' type="date"' : ($type === 'money' ? ' inputmode="decimal" placeholder="0,00"' : '') ?>>
      <?php endif; ?>
      <?= $err($k) ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<?php if ($am20): $rw = (string)($old['rachunek_wybor'] ?? ($old['rachunek'] ?? '')); ?>
<section class="panel warn">
  <h2 class="mt-0">Płatność</h2>
  <p class="small text-secondary">Doliczenie do zajęć: kwota zostanie dopisana do rozliczenia uczestnika za miesiąc wejścia zmiany w życie (rozliczenia TI). Wpłata na rachunek: osoba (i płatnik) dostaje numer rachunku, kwotę i tytuł = numer sprawy.</p>
  <div class="row g-3" id="rachunekBox">
    <div class="col-md-6"><label class="form-label" for="f-rw">Rachunek do wpłat</label>
      <select class="form-select<?= $inv('rachunek') ?>" id="f-rw" name="rachunek_wybor"><option value="">— wybierz —</option>
        <?php foreach ($orgAccounts as $oa): ?><option value="<?= h($oa['nrb']) ?>"<?= $rw === $oa['nrb'] ? ' selected' : '' ?>><?= h($oa['label'] . ' — ' . LicenseOperationService::nrb($oa['nrb'])) ?></option><?php endforeach; ?>
        <option value="inny"<?= $rw === 'inny' ? ' selected' : '' ?>>inny rachunek…</option></select><?= $err('rachunek') ?></div>
    <div class="col-md-6"><label class="form-label" for="f-rachunek">Inny rachunek (NRB, 26 cyfr)</label><input class="form-control" id="f-rachunek" name="rachunek" value="<?= h(in_array($rw, array_column($orgAccounts, 'nrb'), true) ? '' : (string)($old['rachunek'] ?? '')) ?>" inputmode="numeric"></div>
  </div>
  <div class="row g-3 mt-1">
    <div class="col-md-6"><label class="form-label" for="f-pe">E-mail płatnika (gdy inny niż osoba — dostanie dane do płatności)</label><input class="form-control<?= $inv('platnik_email') ?>" type="email" id="f-pe" name="platnik_email" value="<?= $v('platnik_email') ?>"><?= $err('platnik_email') ?></div>
  </div>
  <div class="alert alert-light border mt-3 small mb-2"><?= h($form->statement()) ?></div>
  <p class="small mb-0"><b>Potwierdzenie wnioskodawcy</b> składa osoba przez link — dopiero wtedy zmiana wchodzi w życie.</p>
</section>
<script>
(function () {
  var t = document.getElementById('f-tryb'), box = document.getElementById('rachunekBox'), lab = document.querySelector('label[for="f-kwota"]');
  function sync() { box.hidden = t.value !== 'rachunek'; if (lab) lab.textContent = (t.value === 'rachunek' ? 'Kwota do wpłaty (zł)' : 'Kwota, o którą wzrośnie opłata za zajęcia (zł)') + ' *'; }
  t.addEventListener('change', sync); sync();
})();
</script>
<?php else: ?>
<section class="panel"><p class="small mb-0"><?= h($form->statement()) ?></p></section>
<?php endif; ?>

<?php if ($am20): $docs = array_values((array)($old['am20_1'] ?? [])); $docsTarget = max(3, count($docs) + 2); while (count($docs) < $docsTarget) $docs[] = ['typ' => '', 'numer' => '', 'data' => '', 'kwota' => '', 'zrodlo' => '']; ?>
<section class="panel" id="am20-1">
  <h2 class="mt-0"><span class="sym" style="background:#008a00">AM20-1</span> Wykaz dokumentów księgowych wystawionych dotąd przez FEER</h2>
  <p class="small text-secondary">Dokumenty związane z opłatą za tę licencję (faktury, faktury zbiorcze, korekty, dopłaty w rozliczeniach) z kwotami. Wiersze z rejestru faktur i rozliczeń TI
    są podpowiedzią — sprawdź je, popraw albo dopisz brakujące. Na wydruku AM20-1 pojawi się suma oraz data pierwszej i ostatniej faktury.</p>
  <?= $err('am20_1') ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-2">
    <thead><tr><th style="width:20%">Rodzaj</th><th>Numer dokumentu</th><th style="width:17%">Data wystawienia</th><th style="width:16%">Kwota brutto (zł)</th><th class="small text-secondary">Źródło</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $i => $dd): ?>
      <tr>
        <td><label class="visually-hidden" for="dt<?= $i ?>">Rodzaj dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" id="dt<?= $i ?>" name="am20_1[<?= $i ?>][typ]" value="<?= h($dd['typ']) ?>" list="am20-typy" placeholder="faktura"></td>
        <td><label class="visually-hidden" for="dn<?= $i ?>">Numer dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" id="dn<?= $i ?>" name="am20_1[<?= $i ?>][numer]" value="<?= h($dd['numer']) ?>"></td>
        <td><label class="visually-hidden" for="dd<?= $i ?>">Data dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" type="date" id="dd<?= $i ?>" name="am20_1[<?= $i ?>][data]" value="<?= h($dd['data']) ?>"></td>
        <td><label class="visually-hidden" for="dk<?= $i ?>">Kwota dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" id="dk<?= $i ?>" name="am20_1[<?= $i ?>][kwota]" value="<?= h($dd['kwota']) ?>" inputmode="decimal"></td>
        <td class="small text-secondary"><?= h($dd['zrodlo'] ?: 'ręcznie') ?><input type="hidden" name="am20_1[<?= $i ?>][zrodlo]" value="<?= h($dd['zrodlo'] ?: 'ręcznie') ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <datalist id="am20-typy"><option value="faktura"><option value="faktura zbiorcza"><option value="faktura korygująca"><option value="rachunek"><option value="nota księgowa"><option value="rozliczenie TI (dopłata)"></datalist>
  <div class="form-check"><input class="form-check-input" type="checkbox" id="am20b" name="am20_1_brak" value="1"<?= !empty($old['am20_1_brak']) ? ' checked' : '' ?>>
    <label class="form-check-label small" for="am20b">FEER nie wystawiała dotąd dokumentów księgowych za tę licencję</label></div>
  <p class="small text-secondary mb-0">Więcej wierszy: zapisz z błędem lub dopisz po ponownym otwarciu — puste wiersze są pomijane.</p>
</section>
<?php endif; ?>

<?php if ($form === OperationForm::Am12): ?>
<section class="panel">
  <label class="form-label" for="f-plik">Plik dowodu zakupu (PDF, JPG, PNG; do 15 MB)</label>
  <input class="form-control<?= $inv('proof') ?>" type="file" id="f-plik" name="dowod_plik" accept=".pdf,.jpg,.jpeg,.png"><?= $err('proof') ?>
</section>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
  <button class="btn btn-metro" type="submit"><?= $am20 ? 'Zapisz i wyślij osobie do potwierdzenia (e-mail + SMS)' : 'Złóż — do decyzji kierownika' ?></button>
  <a class="btn btn-outline-secondary" href="<?= h(route('ld.account', ['kind' => $acc['kind'], 'id' => $acc['id']])) ?>">Anuluj</a>
</div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
