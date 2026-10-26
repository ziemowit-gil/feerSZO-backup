<?php
/**
 * modules/ti_training_contract/view/form.php — formularz umowy szkoleniowej TI (dołączane z dydaktyk/umowaSzkolenia.php).
 * Wejście: $data (dane umowy), $row (wiersz z bazy albo null), $id, $locked.
 * Kontekst: panel dydaktyka (domyślnie, umowaSzkolenia.php + dyd_token) albo Menu Umowy SZO (contracts/szk/edit.php):
 * $SZK_URL — adres strony formularza, $SZK_LIST — adres listy, $SZK_TOKEN — gotowe pole ukryte z tokenem CSRF.
 */
$SZK_URL   = $SZK_URL   ?? 'umowaSzkolenia.php';
$SZK_LIST  = $SZK_LIST  ?? $SZK_URL;
$SZK_TOKEN = $SZK_TOKEN ?? '<input type="hidden" name="_token" value="' . h(dyd_token()) . '">';
$_sep = strpos($SZK_URL, '?') === false ? '?' : '&amp;';
$_f = TiTrainingContract::fields();
$_dis = $locked ? ' disabled' : '';
?>
<p class="mb-2"><a href="<?= h($SZK_LIST) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wszystkie umowy</a></p>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Umowa o świadczenie usług szkoleniowych</h1>
<p class="mb-3">
  <?php if ($row && $row['numer_umowy'] !== ''): ?>
    <span class="font-monospace fw-semibold"><?= h($row['numer_umowy']) ?></span> · RU <span class="font-monospace"><?= h($row['nr_rejestru']) ?></span>
  <?php else: ?><span class="text-body-secondary">Projekt — numer nadaje „Zarejestruj”.</span><?php endif; ?>
  <?php if ($row && (int)$row['is_test'] === 1): ?><span class="badge text-bg-warning ms-1">test</span><?php endif; ?>
</p>

<?php if ($row): ?>
<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <a class="btn btn-outline-primary btn-sm" href="<?= h($SZK_URL) ?><?= $_sep ?>id=<?= (int)$id ?>&amp;docx=1"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz DOCX</a>
  <form method="post" class="d-flex align-items-end gap-2">
    <?= $SZK_TOKEN ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div><label class="form-label small mb-1" for="u-status">Status</label>
      <select class="form-select form-select-sm" id="u-status" name="status">
        <?php foreach (TiTrainingContractStore::statuses($row['status']) as $_s => $_sl): ?><option value="<?= h($_s) ?>" <?= $row['status'] === $_s ? 'selected' : '' ?>><?= h($_sl) ?></option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-outline-secondary btn-sm">Zmień status</button>
  </form>
  <?php if ((int)$row['is_test'] === 1 || $row['nr_rejestru'] === ''): ?>
  <form method="post" onsubmit="return confirm('Usunąć tę umowę?')">
    <?= $SZK_TOKEN ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <button class="btn btn-outline-danger btn-sm">Usuń</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($locked): ?><div class="alert alert-info">Umowa ma status „<?= h($row['status']) ?>” — treść jest zablokowana. Aby poprawić dane, zmień status na „projekt” albo „do podpisu”.</div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm" id="szk-form"><div class="card-body">
  <?= $SZK_TOKEN ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="client_id" value="<?= (int)($data['client_id'] ?? 0) ?>">
  <p class="small text-body-secondary">Puste pola zostaną w dokumencie jako linie do ręcznego uzupełnienia.</p>
  <nav aria-label="Kroki umowy" id="szk-stepper" hidden><ol class="list-unstyled d-flex flex-wrap gap-2 mb-3 p-0"></ol></nav>
  <?php foreach (TiTrainingContract::SECTIONS as $_sk => $_sl): ?>
  <fieldset class="mb-4" data-szk-step="<?= h($_sl) ?>" data-szk-key="<?= h($_sk) ?>"<?= $_dis ?>>
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
    <div class="mb-2"><label class="form-label small mb-1" for="u-grupa_id">Grupa zajęć (subkonto rozliczeń)</label>
      <select class="form-select form-select-sm" id="u-grupa_id" name="grupa_id" aria-describedby="u-grupa-h"><option value="0">— bez grupy —</option>
        <?php foreach (db_all("SELECT id, COALESCE(NULLIF(display_name,''), name) AS n FROM k30_ti_courses WHERE is_active = 1 OR id = " . (int)($data['grupa_id'] ?? 0) . " ORDER BY n") as $_g): ?>
        <option value="<?= (int)$_g['id'] ?>" <?= (int)($data['grupa_id'] ?? 0) === (int)$_g['id'] ? 'selected' : '' ?>><?= h($_g['n']) ?></option><?php endforeach; ?></select>
      <div class="form-text" id="u-grupa-h">Wpłaty znaczone na tę grupę trafiają na jej subkonto; lista zapisów sprawdza zapis uczestnika w tej grupie.</div></div>
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
  <section data-szk-step="Podsumowanie" data-szk-key="summary" aria-labelledby="szk-sum-h">
  <h2 class="h6 fw-bold border-bottom pb-1" id="szk-sum-h">Podsumowanie</h2>
  <dl class="row small mb-3" id="szk-summary"></dl>
  <?php if (!$locked): ?>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-primary" name="act" value="save"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
    <?php if (!$row || $row['nr_rejestru'] === ''): ?>
    <button class="btn btn-success" name="act" value="register" onclick="return confirm('Zarejestrować umowę? Zostanie nadany numer SZK i numer w Rejestrze Umów (RU) — tego nie da się cofnąć.')"><i class="bi bi-journal-check me-1" aria-hidden="true"></i>Zapisz i zarejestruj</button>
    <?php endif; ?>
    <button class="btn btn-outline-secondary" name="act" value="preview"><i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Podgląd DOCX bez zapisu</button>
  </div>
  <?php endif; ?>
  </section>
  <div class="d-flex justify-content-between mt-3 border-top pt-3" id="szk-nav" hidden>
    <button type="button" class="btn btn-outline-secondary" id="szk-prev"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wstecz</button>
    <span class="small text-body-secondary align-self-center" id="szk-pos" role="status"></span>
    <button type="button" class="btn btn-primary" id="szk-next">Dalej <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
  </div>
</div></form>
<script>
(function () {
  var form = document.getElementById('szk-form'); if (!form) return;
  var steps = Array.prototype.slice.call(form.querySelectorAll('[data-szk-step]'));
  if (steps.length < 2) return;
  var stepper = document.getElementById('szk-stepper'), list = stepper.querySelector('ol'),
      nav = document.getElementById('szk-nav'), prev = document.getElementById('szk-prev'), next = document.getElementById('szk-next'),
      pos = document.getElementById('szk-pos'), sum = document.getElementById('szk-summary'), cur = 0, btns = [];
  var fields = [['imie_nazwisko', 'Uczestnik'], ['pesel', 'PESEL'], ['temat', 'Szkolenie'], ['wymiar', 'Wymiar'], ['prowadzacy', 'Prowadzący'],
                ['wynagrodzenie', 'Wynagrodzenie (zł)'], ['kwota_platnik', 'Kwota płatnika (zł)'], ['kwota_kursant', 'Kwota uczestnika (zł)'], ['platnik', 'Płatnik'], ['data', 'Data zawarcia']];
  function label(s) { return s.getAttribute('data-szk-step'); }
  steps.forEach(function (s, i) {
    var li = document.createElement('li'), b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary'; b.textContent = (i + 1) + '. ' + label(s).replace(/\s*\(.*$/, '');
    b.addEventListener('click', function () { go(i, true); });
    li.appendChild(b); list.appendChild(li); btns.push(b);
    if (!s.hasAttribute('tabindex')) s.setAttribute('tabindex', '-1');
  });
  function summarize() {
    sum.innerHTML = '';
    fields.forEach(function (f) {
      var el = document.getElementById('u-' + f[0]); if (!el) return;
      var v = el.value.trim(), dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.className = 'col-sm-4'; dd.className = 'col-sm-8'; dt.textContent = f[1]; dd.textContent = v || '— (linia do uzupełnienia)';
      sum.appendChild(dt); sum.appendChild(dd);
    });
  }
  function go(i, focus) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, k) { s.hidden = k !== cur; btns[k].className = 'btn btn-sm ' + (k === cur ? 'btn-primary' : 'btn-outline-secondary'); if (k === cur) btns[k].setAttribute('aria-current', 'step'); else btns[k].removeAttribute('aria-current'); });
    if (steps[cur].getAttribute('data-szk-key') === 'summary') summarize();
    prev.disabled = cur === 0; next.hidden = cur === steps.length - 1;
    pos.textContent = 'Krok ' + (cur + 1) + ' z ' + steps.length + ': ' + label(steps[cur]);
    if (focus) { steps[cur].focus(); steps[cur].scrollIntoView({block: 'start'}); }
  }
  prev.addEventListener('click', function () { go(cur - 1, true); });
  next.addEventListener('click', function () { go(cur + 1, true); });
  stepper.hidden = false; nav.hidden = false;
  var start = 0;
  if (location.hash.indexOf('#krok-') === 0) start = parseInt(location.hash.slice(6), 10) - 1 || 0;
  go(start, false);
  form.addEventListener('submit', function () { steps.forEach(function (s) { s.hidden = false; }); });   // wszystkie pola idą w POST (ukryte też)
})();
</script>
