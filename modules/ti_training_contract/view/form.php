<?php
/**
 * modules/ti_training_contract/view/form.php — formularz umowy szkoleniowej TI (dołączane z dydaktyk/umowaSzkolenia.php).
 * Wejście: $data (dane umowy), $row (wiersz z bazy albo null), $id, $locked.
 */
$_f = TiTrainingContract::fields();
$_dis = $locked ? ' disabled' : '';
?>
<p class="mb-2"><a href="umowaSzkolenia.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wszystkie umowy</a></p>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Umowa o świadczenie usług szkoleniowych</h1>
<p class="mb-3">
  <?php if ($row && $row['numer_umowy'] !== ''): ?>
    <span class="font-monospace fw-semibold"><?= h($row['numer_umowy']) ?></span> · RU <span class="font-monospace"><?= h($row['nr_rejestru']) ?></span>
  <?php else: ?><span class="text-body-secondary">Projekt — numer nadaje „Zarejestruj”.</span><?php endif; ?>
  <?php if ($row && (int)$row['is_test'] === 1): ?><span class="badge text-bg-warning ms-1">test</span><?php endif; ?>
</p>

<?php if ($row): ?>
<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <a class="btn btn-outline-primary btn-sm" href="umowaSzkolenia.php?id=<?= (int)$id ?>&amp;docx=1"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz DOCX</a>
  <form method="post" class="d-flex align-items-end gap-2">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div><label class="form-label small mb-1" for="u-status">Status</label>
      <select class="form-select form-select-sm" id="u-status" name="status">
        <?php foreach (TiTrainingContractStore::statuses($row['status']) as $_s => $_sl): ?><option value="<?= h($_s) ?>" <?= $row['status'] === $_s ? 'selected' : '' ?>><?= h($_sl) ?></option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-outline-secondary btn-sm">Zmień status</button>
  </form>
  <?php if ((int)$row['is_test'] === 1 || $row['nr_rejestru'] === ''): ?>
  <form method="post" onsubmit="return confirm('Usunąć tę umowę?')">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <button class="btn btn-outline-danger btn-sm">Usuń</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($locked): ?><div class="alert alert-info">Umowa ma status „<?= h($row['status']) ?>” — treść jest zablokowana. Aby poprawić dane, zmień status na „projekt” albo „do podpisu”.</div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="client_id" value="<?= (int)($data['client_id'] ?? 0) ?>">
  <p class="small text-body-secondary">Puste pola zostaną w dokumencie jako linie do ręcznego uzupełnienia.</p>
  <?php foreach (TiTrainingContract::SECTIONS as $_sk => $_sl): ?>
  <fieldset class="mb-4"<?= $_dis ?>>
    <legend class="h6 fw-bold border-bottom pb-1"><?= h($_sl) ?></legend>
    <?php if ($_sk === 'strony'): ?>
    <div class="mb-2"><span class="form-label d-block mb-1" id="u-plec-l">Forma w umowie</span>
      <div role="radiogroup" aria-labelledby="u-plec-l">
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="plec" id="u-k" value="K" <?= ($data['plec'] ?? 'K') === 'K' ? 'checked' : '' ?>><label class="form-check-label" for="u-k">Pani (Kursantka)</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="plec" id="u-m" value="M" <?= ($data['plec'] ?? '') === 'M' ? 'checked' : '' ?>><label class="form-check-label" for="u-m">Pan (Kursant)</label></div>
    </div></div>
    <?php elseif ($_sk === 'szkolenie'): ?>
    <div class="mb-2"><span class="form-label d-block mb-1" id="u-fin-l">Finansowanie</span>
      <div role="radiogroup" aria-labelledby="u-fin-l">
      <?php foreach (TiTrainingContract::FUNDING as $_k => $_l): ?>
      <div class="form-check"><input class="form-check-input" type="radio" name="finansowanie" id="u-f-<?= h($_k) ?>" value="<?= h($_k) ?>" <?= ($data['finansowanie'] ?? 'publiczne') === $_k ? 'checked' : '' ?>><label class="form-check-label" for="u-f-<?= h($_k) ?>"><?= h($_l) ?></label></div>
      <?php endforeach; ?>
    </div></div>
    <?php elseif ($_sk === 'fundacja' && ($_org = array_filter(TiTrainingContract::orgDefaults(), fn($v) => $v !== ''))): ?>
    <p class="small mb-2"><button type="button" class="btn btn-sm btn-outline-primary" id="u-org-fill" data-org="<?= h(json_encode($_org, JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-building-down" aria-hidden="true"></i> Uzupełnij z danych Fundacji</button>
      <span class="text-body-secondary ms-1" role="status">z ustawień organizacji: <?= h(implode(', ', array_map(fn($k) => mb_strtolower($_f[$k][0]), array_keys($_org)))) ?></span></p>
    <script>document.getElementById('u-org-fill').addEventListener('click', function () {
      var d = JSON.parse(this.dataset.org), n = 0;
      Object.keys(d).forEach(function (k) { var el = document.getElementById('u-' + k); if (el && el.value !== d[k]) { el.value = d[k]; n++; } });
      this.nextElementSibling.textContent = n ? 'Uzupełniono pól: ' + n + ' — zapisz umowę.' : 'Dane są już aktualne.';
    });</script>
    <?php endif; ?>
    <div class="row g-2">
    <?php foreach ($_f as $_k => [$_lbl, , $_sec, $_type]): if ($_sec !== $_sk) continue; ?>
      <div class="<?= $_type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
        <label class="form-label small mb-1" for="u-<?= h($_k) ?>"><?= h($_lbl) ?></label>
        <?php if ($_type === 'textarea'): ?>
          <textarea class="form-control form-control-sm" id="u-<?= h($_k) ?>" name="<?= h($_k) ?>" rows="2"><?= h((string)($data[$_k] ?? '')) ?></textarea>
        <?php else: ?>
          <input class="form-control form-control-sm" id="u-<?= h($_k) ?>" name="<?= h($_k) ?>" type="<?= $_type === 'date' ? 'date' : 'text' ?>" value="<?= h((string)($data[$_k] ?? '')) ?>"<?= in_array($_k, ['pesel', 'imie_nazwisko', 'adres', 'email', 'telefon'], true) ? ' autocomplete="off"' : '' ?><?= $_k === 'pesel' ? ' inputmode="numeric" maxlength="11"' : '' ?>>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </fieldset>
  <?php endforeach; ?>
  <?php if (!$locked): ?>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-primary" name="act" value="save"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
    <?php if (!$row || $row['nr_rejestru'] === ''): ?>
    <button class="btn btn-success" name="act" value="register" onclick="return confirm('Zarejestrować umowę? Zostanie nadany numer TI/UMSZ i numer w Rejestrze Umów (RU) — tego nie da się cofnąć.')"><i class="bi bi-journal-check me-1" aria-hidden="true"></i>Zapisz i zarejestruj</button>
    <?php endif; ?>
    <button class="btn btn-outline-secondary" name="act" value="preview"><i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Podgląd DOCX bez zapisu</button>
  </div>
  <?php endif; ?>
</div></form>
