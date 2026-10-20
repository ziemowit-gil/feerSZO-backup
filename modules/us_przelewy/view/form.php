<?php
/**
 * modules/us_przelewy/view/form.php — nowe zobowiązanie podatkowe / karta zobowiązania (edycja, status, historia).
 * Zmienne: $z (wiersz albo dane formularza), $dup (zobowiązania za ten sam okres), $forms, $historia, $edokDocs,
 * $edokDoc (powiązany dokument EODoK), $rachunek (rachunek US z ustawień). Kontroler: create/show/store/update.
 */
$id = (int)($z['id'] ?? 0);
$status = (string)($z['status'] ?? 'do_zaplaty');
$editable = $status === 'do_zaplaty';
$code = (string)($z['formularz'] ?? '');
$cur = $forms[$code] ?? null;
if (!$cur && $id) $cur = ['label' => $z['sfp'] . ' (wyłączony w słowniku)', 'sfp' => $z['sfp'], 'okres' => $z['okres_typ'], 'termin_dzien' => 20, 'termin_mies' => 1];
$okresLabel = $id ? uspOkresLabel($z['okres_typ'], (int)$z['okres_rok'], (int)$z['okres_nr']) : '';
$PAGE_TITLE = $id ? 'Zobowiązanie ' . $z['sfp'] . ' ' . $okresLabel : 'Nowe zobowiązanie podatkowe';
include dirname(__DIR__, 3) . '/includes/header.php';
$miesiace = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
$rachVal = preg_replace('/\D/', '', (string)($z['rachunek'] ?? '')) ?: $rachunek;
$today = date('Y-m-d');
// Dane do podglądu tytułu i domyślnego terminu w przeglądarce.
$jsForms = [];
foreach ($forms as $k => $fo) $jsForms[$k] = ['sfp' => $fo['sfp'], 'okres' => $fo['okres'], 'dzien' => $fo['termin_dzien'], 'mies' => $fo['termin_mies']];
$swieta = array_merge(uspSwieta((int)date('Y')), uspSwieta((int)date('Y') + 1), uspSwieta((int)date('Y') - 1));
?>
<div class="mx-auto" style="max-width: 920px">
  <nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h(route('usp.index')) ?>">← Przelewy do US</a></nav>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-bank2 text-primary" aria-hidden="true"></i> <?= h($PAGE_TITLE) ?></h1>
    <?php if ($id): ?><span class="badge <?= h(USP_STATUSY[$status]['badge'] ?? 'text-bg-light') ?>"><?= h(USP_STATUSY[$status]['label'] ?? $status) ?></span><?php endif; ?>
  </div>
  <?= flash_html() ?>

  <?php if ($id && !$editable): ?>
  <div class="alert alert-info py-2 small">
    <?php if ($status === 'zlecony'): ?>Plik przelewu wygenerowano <?= h(date('d.m.Y H:i', strtotime((string)$z['zlecono_at']))) ?> (<?= h($z['plik_format']) ?>, z rachunku …<?= h(substr((string)$z['rachunek_z'], -4)) ?>)<?php if ($z['plik_path']): ?> — <a href="<?= h(route('usp.plik', ['id' => $id])) ?>">pobierz plik</a><?php endif; ?>. Po wykonaniu przelewu oznacz zapłatę.
    <?php elseif ($status === 'zaplacony'): ?>Zapłacono <?= h(date('d.m.Y', strtotime((string)$z['data_zaplaty']))) ?>.
    <?php else: ?>Zobowiązanie anulowane.<?php endif; ?>
    Edycja danych jest możliwa po cofnięciu do statusu „Do zapłaty”.
  </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" action="<?= h($id ? route('usp.update', ['id' => $id]) : route('usp.store')) ?>" class="card shadow-sm mb-3">
    <?= Router::csrfField() ?>
    <fieldset class="card-body" <?= $editable ? '' : 'disabled' ?>>
      <legend class="visually-hidden">Dane zobowiązania</legend>
      <div class="row g-3">
        <div class="col-md-6">
          <label for="u-form" class="form-label fw-semibold small">Rodzaj zobowiązania</label>
          <select id="u-form" name="formularz" class="form-select" required>
            <?php if ($cur && !isset($forms[$code])): ?><option value="<?= h($code) ?>" selected><?= h($cur['label']) ?></option><?php endif; ?>
            <?php foreach ($forms as $k => $fo): ?><option value="<?= h($k) ?>" <?= $k === $code ? 'selected' : '' ?>><?= h($fo['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label for="u-rok" class="form-label fw-semibold small">Rok okresu</label>
          <input id="u-rok" name="okres_rok" type="number" min="2000" max="<?= (int)date('Y') + 1 ?>" class="form-control" required value="<?= (int)($z['okres_rok'] ?? date('Y')) ?>">
        </div>
        <div class="col-md-3" id="u-nr-wrap">
          <label for="u-nr" class="form-label fw-semibold small" id="u-nr-label">Miesiąc</label>
          <select id="u-nr" name="okres_nr" class="form-select" data-value="<?= (int)($z['okres_nr'] ?? 0) ?>"></select>
        </div>
        <div class="col-md-4">
          <label for="u-kwota" class="form-label fw-semibold small">Kwota (zł)</label>
          <input id="u-kwota" name="kwota" class="form-control font-monospace text-end" inputmode="decimal" required placeholder="0,00" value="<?= h((string)($z['kwota'] ?? '')) ?>">
        </div>
        <div class="col-md-4">
          <label for="u-termin" class="form-label fw-semibold small">Termin płatności</label>
          <input id="u-termin" name="termin" type="date" class="form-control" required value="<?= h((string)($z['termin'] ?? '')) ?>" aria-describedby="u-termin-h">
          <div id="u-termin-h" class="form-text">Domyślnie ustawowy (przesunięty z weekendu i święta). <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="u-termin-def">Przywróć domyślny</button></div>
        </div>
        <div class="col-md-4">
          <label for="u-txt" class="form-label fw-semibold small">Opis w tytule (/TXT/, opcjonalnie)</label>
          <input id="u-txt" name="txt" class="form-control" maxlength="34" value="<?= h((string)($z['txt'] ?? '')) ?>" placeholder="np. korekta, odsetki">
        </div>
        <div class="col-12">
          <label for="u-rach" class="form-label fw-semibold small">Rachunek urzędu skarbowego</label>
          <input id="u-rach" name="rachunek" class="form-control font-monospace" value="<?= h($rachVal !== '' ? edok_nrb_format($rachVal) : '') ?>" aria-describedby="u-rach-h">
          <div id="u-rach-h" class="form-text">Domyślnie z ustawień modułu (mikrorachunek podatkowy). Zmień tylko dla podatku płaconego na inny rachunek.</div>
        </div>
        <div class="col-12">
          <span class="form-label fw-semibold small d-block">Tytuł przelewu (podgląd)</span>
          <div class="font-monospace small border rounded px-2 py-1 bg-body-tertiary" id="u-tytul" aria-live="polite"><?= $id ? h(uspTytul($z)) : '' ?></div>
          <div class="form-text">Identyfikator: NIP organizacji <?= h(uspNip() ?: '— brak w Ustawieniach organizacji —') ?>. Odbiorca: <?= h(uspOdbiorca()) ?>.</div>
        </div>
        <div class="col-md-6">
          <label for="u-edok" class="form-label fw-semibold small">Powiązany dokument EODoK (opcjonalnie)</label>
          <select id="u-edok" name="edok_doc_id" class="form-select">
            <option value="">— brak —</option>
            <?php if ($edokDoc && !in_array((int)$edokDoc['id'], array_map(fn($d) => (int)$d['id'], $edokDocs), true)): ?>
            <option value="<?= (int)$edokDoc['id'] ?>" selected><?= h($edokDoc['number'] . ' — ' . $edokDoc['title']) ?></option>
            <?php endif; ?>
            <?php foreach ($edokDocs as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($z['edok_doc_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['number'] . ' — ' . mb_strimwidth((string)($d['title'] ?: $d['kontrahent_nazwa']), 0, 60, '...')) ?></option><?php endforeach; ?>
          </select>
          <?php if ($edokDoc): ?><div class="form-text"><a href="<?= h(APP_URL) ?>/edok/view.php?id=<?= (int)$edokDoc['id'] ?>">Otwórz <?= h($edokDoc['number']) ?></a></div><?php endif; ?>
        </div>
        <div class="col-md-6">
          <label for="u-zal" class="form-label fw-semibold small">Załącznik (deklaracja, wyliczenie)</label>
          <input id="u-zal" name="zalacznik" type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx">
          <?php if (!empty($z['zalacznik_path'])): ?><div class="form-text"><a href="<?= h(route('usp.plik', ['id' => $id, 'co' => 'zalacznik'])) ?>"><i class="bi bi-paperclip" aria-hidden="true"></i> <?= h($z['zalacznik_nazwa'] ?: 'załącznik') ?></a> — nowy plik zastąpi obecny.</div><?php endif; ?>
        </div>
        <div class="col-12">
          <label for="u-uwagi" class="form-label fw-semibold small">Uwagi</label>
          <textarea id="u-uwagi" name="uwagi" class="form-control" rows="2"><?= h((string)($z['uwagi'] ?? '')) ?></textarea>
        </div>
      </div>

      <?php if ($dup): ?>
      <div class="alert alert-danger py-2 small mt-3">
        <strong>Za ten okres jest już zobowiązanie tego rodzaju:</strong>
        <?php foreach ($dup as $d): ?> <a href="<?= h(route('usp.show', ['id' => $d['id']])) ?>"><?= h($d['kwota']) ?> zł, <?= h(USP_STATUSY[$d['status']]['label'] ?? $d['status']) ?></a>;<?php endforeach; ?>
        <div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="confirm_dup" value="1" id="u-dup"><label class="form-check-label" for="u-dup">To kolejna wpłata za ten okres (np. po korekcie deklaracji)</label></div>
      </div>
      <?php endif; ?>

      <?php if ($editable): ?>
      <div class="mt-3"><button type="submit" class="btn btn-primary"><?= $id ? 'Zapisz zmiany' : 'Dodaj zobowiązanie' ?></button></div>
      <?php endif; ?>
    </fieldset>
  </form>

  <?php if ($id): ?>
  <section class="card shadow-sm mb-3" aria-labelledby="u-st-h">
    <div class="card-body">
      <h2 class="h6" id="u-st-h">Status</h2>
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <?php if (in_array($status, ['do_zaplaty', 'zlecony'], true)): ?>
        <form method="post" action="<?= h(route('usp.status', ['id' => $id])) ?>" class="d-flex gap-2 align-items-end">
          <?= Router::csrfField() ?><input type="hidden" name="status" value="zaplacony">
          <div><label for="u-dz" class="form-label small mb-1">Data zapłaty</label><input type="date" id="u-dz" name="data_zaplaty" class="form-control form-control-sm" value="<?= h($today) ?>" max="<?= h($today) ?>" required></div>
          <button class="btn btn-sm btn-success" type="submit">Oznacz jako zapłacone</button>
        </form>
        <?php endif; ?>
        <?php if ($status !== 'do_zaplaty'): ?>
        <form method="post" action="<?= h(route('usp.status', ['id' => $id])) ?>"><?= Router::csrfField() ?><input type="hidden" name="status" value="do_zaplaty">
          <button class="btn btn-sm btn-outline-secondary" type="submit">Cofnij do „Do zapłaty”</button></form>
        <?php endif; ?>
        <?php if ($status === 'do_zaplaty'): ?>
        <form method="post" action="<?= h(route('usp.status', ['id' => $id])) ?>" onsubmit="return confirm('Anulować to zobowiązanie?')"><?= Router::csrfField() ?><input type="hidden" name="status" value="anulowany">
          <button class="btn btn-sm btn-outline-danger" type="submit">Anuluj zobowiązanie</button></form>
        <?php endif; ?>
      </div>
      <p class="small text-body-secondary mt-2 mb-0">Plik przelewu generujesz na liście (można zaznaczyć kilka zobowiązań naraz) — zobowiązanie przechodzi wtedy w status „Zlecony”.</p>
    </div>
  </section>

  <?php if ($historia): ?>
  <section class="card shadow-sm" aria-labelledby="u-hist-h">
    <div class="card-body">
      <h2 class="h6" id="u-hist-h">Historia</h2>
      <ul class="list-unstyled small mb-0">
        <?php foreach ($historia as $hh): ?>
        <li class="mb-1"><span class="text-body-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$hh['created_at']))) ?> · <?= h($hh['user_name']) ?></span> — <?= h($hh['opis']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function () {
  var forms = <?= json_encode($jsForms, JSON_UNESCAPED_UNICODE) ?>;
  var swieta = <?= json_encode($swieta) ?>;
  var nip = <?= json_encode(uspNip()) ?>;
  var miesiace = <?= json_encode($miesiace, JSON_UNESCAPED_UNICODE) ?>;
  var sel = document.getElementById('u-form'), rok = document.getElementById('u-rok'), nr = document.getElementById('u-nr');
  var termin = document.getElementById('u-termin'), txt = document.getElementById('u-txt'), out = document.getElementById('u-tytul');
  var isNew = <?= $id ? 'false' : 'true' ?>;
  function fo() { return forms[sel.value] || { sfp: '', okres: 'M', dzien: 20, mies: 1 }; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fillNr() {
    var f = fo(), keep = parseInt(nr.value || nr.dataset.value || '0', 10);
    document.getElementById('u-nr-wrap').style.display = f.okres === 'R' ? 'none' : '';
    document.getElementById('u-nr-label').textContent = f.okres === 'K' ? 'Kwartał' : 'Miesiąc';
    nr.innerHTML = '';
    var n = f.okres === 'K' ? 4 : 12;
    for (var i = 1; i <= n; i++) {
      var o = document.createElement('option'); o.value = i;
      o.textContent = f.okres === 'K' ? ['I', 'II', 'III', 'IV'][i - 1] + ' kwartał' : pad(i) + ' — ' + miesiace[i - 1];
      if (i === keep) o.selected = true;
      nr.appendChild(o);
    }
    nr.required = f.okres !== 'R';
  }
  function okresKod() {
    var f = fo(), rr = pad(parseInt(rok.value || '0', 10) % 100), n = parseInt(nr.value || '0', 10);
    return f.okres === 'R' ? rr + 'R' : rr + f.okres + pad(n);
  }
  function terminDef() {
    var f = fo(), y = parseInt(rok.value || '0', 10), n = parseInt(nr.value || '0', 10);
    if (!y) return '';
    var endM = f.okres === 'K' ? n * 3 : (f.okres === 'R' ? 12 : n);
    var base = new Date(y, endM + f.mies - 1, 1, 12);
    var last = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate();
    var d = new Date(base.getFullYear(), base.getMonth(), Math.min(Math.max(1, f.dzien), last), 12);
    var iso = function (x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()); };
    while (d.getDay() === 0 || d.getDay() === 6 || swieta.indexOf(iso(d)) >= 0) d.setDate(d.getDate() + 1);
    return iso(d);
  }
  function tytul() {
    var t = '/TI/N' + nip + '/OKR/' + okresKod() + '/SFP/' + fo().sfp;
    var x = (txt.value || '').trim().replace(/\//g, '-').replace(/\s+/g, ' ');
    if (x) t += '/TXT/' + x;
    out.textContent = t.substring(0, 140);
  }
  function changed(resetTermin) { if (resetTermin) termin.value = terminDef(); tytul(); }
  sel.addEventListener('change', function () { fillNr(); changed(true); });
  rok.addEventListener('input', function () { changed(true); });
  nr.addEventListener('change', function () { changed(true); });
  txt.addEventListener('input', tytul);
  document.getElementById('u-termin-def').addEventListener('click', function () { termin.value = terminDef(); });
  fillNr();
  if (isNew && !termin.value) termin.value = terminDef();
  tytul();
})();
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
