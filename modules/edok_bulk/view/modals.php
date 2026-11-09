<?php
/**
 * modules/edok_bulk/view/modals.php — okna akcji zbiorczych listy EODoK + skrypt zaznaczania.
 * Każdy formularz [data-edb-ids] dostaje przy wysłaniu ukryte ids[] zaznaczonych dokumentów (.edok-sel).
 * Pola wyboru niosą data-sign / data-pay / data-export / data-bank (podpowiedź do podsumowania w oknie —
 * ostateczną kwalifikację robi serwer, pomijając resztę z powodem).
 * Po każdej zmianie zaznaczenia wysyła zdarzenie „edok-sel-change” (eksport przelewów i operacje banku w index.php).
 *
 * Wymaga: $edbBack (adres listy z filtrami).
 */
require_once dirname(__DIR__) . '/logic/edokBulk.php';
$edbUid     = (int)(current_user()['id'] ?? 0);
$edbPinSet  = edok_pin_is_set($edbUid);
$edbSession = edok_pin_session_until($edbUid) > 0;
$edbToday   = date('Y-m-d');
?>
<form id="edbZipForm" method="post" action="<?= h(edokBulkUrl('/zip')) ?>" data-edb-ids hidden>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="back" value="<?= h($edbBack) ?>">
</form>

<!-- Okno: akceptacja zbiorcza jednym PIN-em -->
<div class="modal fade edb-modal" id="edbSignModal" tabindex="-1" aria-labelledby="edbSignTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h(edokBulkUrl('/sign')) ?>" data-edb-ids>
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="edbSignTitle"><i class="bi bi-check2-all me-2" style="color:#007a00" aria-hidden="true"></i>Akceptuj zaznaczone dokumenty</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anuluj"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="back" value="<?= h($edbBack) ?>">
        <div class="edb-sum" data-edb-sum="sign"></div>
        <p class="small text-muted mb-3">W każdym dokumencie system zaakceptuje po kolei etapy, do których masz rolę — tak jak „Weryfikuj i podpisz wszystkie” na karcie dokumentu. Każdy etap trafia osobno do historii i na kartę akceptacji. Dokumenty z brakami, z decyzją „z uwagami” albo bez Twojego etapu zostaną pominięte z podaniem powodu.</p>
        <?php if (!$edbPinSet): ?>
        <div class="alert alert-warning py-2 small mb-0" role="alert"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>Nie masz ustawionego PIN-u EODoK. <a href="<?= APP_URL ?>/edok/ustaw_pin.php">Ustaw PIN</a>, żeby akceptować dokumenty.</div>
        <?php else: ?>
        <?php if ($edbSession): ?>
        <p class="small text-success mb-3"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Sesja PIN aktywna — PIN niepotrzebny.</p>
        <?php else: ?>
        <div class="mb-3">
          <label class="form-label" for="edbPin">PIN EODoK</label>
          <input type="password" class="form-control form-control-sm" id="edbPin" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required aria-describedby="edbPinHelp">
          <div id="edbPinHelp" class="form-text">Jeden PIN dla wszystkich zaznaczonych dokumentów. Błędny PIN przerywa akcję, zanim cokolwiek zostanie podpisane.</div>
        </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="edbSignNotes">Uwagi (opcjonalnie, trafią do każdego etapu)</label>
          <textarea class="form-control form-control-sm" id="edbSignNotes" name="notes" rows="2" maxlength="500"></textarea>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="confirm" value="1" id="edbSignConfirm" required>
          <label class="form-check-label small" for="edbSignConfirm">Oświadczam, że zweryfikowałem(-am) każdy z zaznaczonych dokumentów w zakresie etapów, które akceptuję.</label>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-success btn-sm"<?= $edbPinSet ? '' : ' disabled' ?>><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Akceptuj zaznaczone</button>
      </div>
    </form>
  </div>
</div>

<?php if (edokBulkCanPay()): ?>
<!-- Okno: oznaczenie jako opłacone -->
<div class="modal fade edb-modal" id="edbPaidModal" tabindex="-1" aria-labelledby="edbPaidTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h(edokBulkUrl('/paid')) ?>" data-edb-ids id="edbPaidForm">
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="edbPaidTitle"><i class="bi bi-cash-coin me-2" aria-hidden="true"></i>Oznacz zaznaczone jako opłacone</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anuluj"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="back" value="<?= h($edbBack) ?>">
        <div class="edb-sum" data-edb-sum="paid"></div>
        <p class="small text-muted mb-3">Status płatności zmieni się na „Opłacony” tak jak w Preliminarzu: lista płac — wszystkie pozycje wypłacone, rachunek do umowy zlecenie — „Zapłacony”. Data i forma zapłaty trafią do historii dokumentu. Cofnąć status „Opłacony” może tylko administrator (w Preliminarzu).</p>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" for="edbPaidOn">Data zapłaty</label>
            <input type="date" class="form-control form-control-sm" id="edbPaidOn" name="paid_on" value="<?= $edbToday ?>" max="<?= $edbToday ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label" for="edbForma">Forma zapłaty</label>
            <select class="form-select form-select-sm" id="edbForma" name="forma" required>
              <?php foreach (EDOK_FORMY_ZAPLATY as $k => $l): ?><option value="<?= h($k) ?>"<?= $k === 'przelew' ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div>
          <label class="form-label" for="edbPaidNotes">Uwagi (opcjonalnie)</label>
          <input type="text" class="form-control form-control-sm" id="edbPaidNotes" name="notes" maxlength="300" placeholder="np. paczka przelewów z 09.11">
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Oznacz jako opłacone</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var bar = document.getElementById('edbBar');
  if (!bar) return;
  var all = document.getElementById('edokSelAll');
  function cbs() { return Array.prototype.slice.call(document.querySelectorAll('.edok-sel')); }
  function checked() { return cbs().filter(function (c) { return c.checked; }); }
  function countAttr(attr) { return checked().filter(function (c) { return c.getAttribute(attr) === '1'; }).length; }

  function summary(kind) {
    var n = checked().length, txt = 'Zaznaczonych dokumentów: <strong>' + n + '</strong>.', warn = false, k;
    if (kind === 'sign') {
      k = countAttr('data-sign');
      txt += ' W obiegu: <strong>' + k + '</strong>.';
      if (k === 0) { txt += ' Żaden z zaznaczonych nie czeka na akceptację — nic nie zostanie podpisane.'; warn = true; }
      else if (k < n) { txt += ' Pozostałe (' + (n - k) + ') zostaną pominięte.'; warn = true; }
    }
    if (kind === 'paid') {
      k = countAttr('data-pay');
      txt += ' Zaakceptowane, nieopłacone wydatki: <strong>' + k + '</strong>.';
      if (k === 0) { txt += ' Nic nie zostanie oznaczone.'; warn = true; }
      else if (k < n) { txt += ' Pozostałe (' + (n - k) + ') zostaną pominięte (przychody, nie zaakceptowane, już opłacone).'; warn = true; }
    }
    return {html: txt, warn: warn};
  }

  function refresh() {
    var n = checked().length, total = cbs().length;
    document.getElementById('edbCount').textContent = n;
    bar.hidden = n === 0;
    if (all) { all.disabled = total === 0; all.checked = n > 0 && n === total; all.indeterminate = n > 0 && n < total; }
    document.dispatchEvent(new CustomEvent('edok-sel-change'));
  }

  document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('edok-sel')) refresh(); });
  if (all) all.addEventListener('change', function () { cbs().forEach(function (c) { c.checked = all.checked; }); refresh(); });
  document.getElementById('edbClear').addEventListener('click', function () { cbs().forEach(function (c) { c.checked = false; }); refresh(); });

  // Otwarcie okna: odśwież podsumowanie
  document.addEventListener('show.bs.modal', function (e) {
    var box = e.target.querySelector('[data-edb-sum]');
    if (!box) return;
    var s = summary(box.getAttribute('data-edb-sum'));
    box.innerHTML = s.html;
    box.classList.toggle('edb-sum--warn', s.warn);
  });

  var zipForm = document.getElementById('edbZipForm');
  if (zipForm) zipForm.edbConfirm = function (n) {
    return n <= 20 || confirm('Przygotować PDF-y ' + n + ' dokumentów? Dokumenty bez dokumentu końcowego dostaną świeży wydruk — to może potrwać minutę lub dłużej.');
  };
  var paidForm = document.getElementById('edbPaidForm');
  if (paidForm) paidForm.edbConfirm = function (n) {
    return confirm('Oznaczyć jako opłacone (spośród ' + n + ' zaznaczonych — tylko zaakceptowane, nieopłacone wydatki)?');
  };

  // Wysłanie: dopisz ids[] zaznaczonych dokumentów
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-edb-ids')) return;
    var sel = checked();
    if (!sel.length) { e.preventDefault(); alert('Zaznacz przynajmniej jeden dokument.'); return; }
    if (typeof f.edbConfirm === 'function' && !f.edbConfirm(sel.length)) { e.preventDefault(); return; }
    Array.prototype.slice.call(f.querySelectorAll('input[data-edb-id]')).forEach(function (i) { i.remove(); });
    sel.forEach(function (c) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value; i.setAttribute('data-edb-id', '1');
      f.appendChild(i);
    });
  }, true);

  refresh();
})();
</script>
