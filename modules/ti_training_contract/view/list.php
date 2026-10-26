<?php
/**
 * modules/ti_training_contract/view/list.php — lista umów szkoleniowych TI (dołączane z dydaktyk/umowaSzkolenia.php).
 */
$_rows = TiTrainingContractStore::list();
$_tests = TiTrainingContractStore::countTest();
$_clients = TiTrainingContractStore::clients();
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 fw-bold mb-0 me-auto"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Umowy o świadczenie usług szkoleniowych</h1>
  <a class="btn btn-primary btn-sm" href="umowaSzkolenia.php?nowa=1"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowa umowa</a>
</div>
<p class="small text-body-secondary">Umowa SZK/NNN/RRRR z uczestnikiem (to samo co typ „Szkolenie” w Menu Umowy; dawne numery TI/UMSZ zostają) (Załączniki 1–3: program, klauzula RODO, wzór odstąpienia).
  „Zarejestruj” nadaje numer umowy i numer w Rejestrze Umów (RU). Do tego czasu umowa jest projektem z numerem „XXX”.</p>

<?php if ($_clients): ?>
<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <input type="hidden" name="nowa" value="1">
  <div><label class="form-label small mb-1" for="u-client">Nowa umowa z danymi uczestnika</label>
    <select class="form-select form-select-sm" id="u-client" name="client" style="min-width:16rem">
      <?php foreach ($_clients as $_c): ?><option value="<?= (int)$_c['id'] ?>"><?= h($_c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-outline-primary btn-sm">Utwórz</button>
</form>
<?php endif; ?>

<?php if (!$_rows): ?>
  <div class="alert alert-light border">Nie ma jeszcze żadnej umowy.</div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <caption class="visually-hidden">Umowy szkoleniowe</caption>
  <thead><tr><th scope="col">Numer</th><th scope="col">RU</th><th scope="col">Uczestnik</th><th scope="col">Szkolenie</th><th scope="col">Data</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($_rows as $_r): ?>
    <tr>
      <td class="font-monospace small"><?= $_r['numer_umowy'] !== '' ? h($_r['numer_umowy']) : '<span class="text-body-secondary">projekt</span>' ?>
        <?php if ((int)$_r['is_test'] === 1): ?><span class="badge text-bg-warning ms-1">test</span><?php endif; ?></td>
      <td class="font-monospace small"><?= h($_r['nr_rejestru']) ?></td>
      <td><a href="umowaSzkolenia.php?id=<?= (int)$_r['id'] ?>"><?= h($_r['imie_nazwisko'] !== '' ? $_r['imie_nazwisko'] : '(bez nazwiska)') ?></a></td>
      <td class="small"><?= h($_r['temat']) ?></td>
      <td class="small text-nowrap"><?= $_r['data_zawarcia'] !== '' ? h(date('d.m.Y', strtotime($_r['data_zawarcia']))) : '' ?></td>
      <td class="small"><?= h($_r['status']) ?></td>
      <td class="text-nowrap"><a class="btn btn-outline-secondary btn-sm" href="umowaSzkolenia.php?id=<?= (int)$_r['id'] ?>&amp;docx=1" aria-label="Pobierz DOCX: <?= h($_r['imie_nazwisko']) ?>"><i class="bi bi-file-earmark-word" aria-hidden="true"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<details class="mt-4">
  <summary class="small fw-semibold">Dane przykładowe (testy)</summary>
  <div class="d-flex flex-wrap gap-2 mt-2">
    <form method="post"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="act" value="seed">
      <button class="btn btn-outline-secondary btn-sm">Dodaj 2 przykładowe umowy testowe</button></form>
    <?php if ($_tests): ?>
    <form method="post" onsubmit="return confirm('Usunąć wszystkie umowy testowe (<?= (int)$_tests ?>)?')"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="act" value="purge">
      <button class="btn btn-outline-danger btn-sm">Usuń umowy testowe (<?= (int)$_tests ?>)</button></form>
    <?php endif; ?>
    <a class="btn btn-link btn-sm" href="umowaSzkolenia.php?nowa=1&amp;przyklad=K">Formularz z danymi przykładowymi (Pani)</a>
    <a class="btn btn-link btn-sm" href="umowaSzkolenia.php?nowa=1&amp;przyklad=M">(Pan)</a>
  </div>
  <p class="small text-body-secondary mt-2 mb-0">Umowy testowe dostają numery z „TEST” (SZK/TEST-…, RU/TEST-…) i nie zajmują numerów w prawdziwym Rejestrze Umów.
    Z wiersza poleceń: <code>php cli/seedUmowaSzkolenia.php</code>.</p>
</details>
