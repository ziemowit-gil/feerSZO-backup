<?php
/**
 * modules/ezd_bulk/view/modals.php — okna akcji masowych listy koszulek + skrypt zaznaczania.
 * Każdy formularz [data-ezb-ids] dostaje przy wysłaniu ukryte ids[] zaznaczonych koszulek (.bulk-cb).
 *
 * Wymaga: $ezbBack (adres listy z filtrami). Okno przerejestrowania (#bulkModal) zostaje w index.php.
 */
require_once dirname(__DIR__) . '/logic/ezdBulk.php';
$ezbToday = date('Y-m-d');
$ezbUsers = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
?>
<!-- Okno: zamknięcie zbiorcze -->
<div class="modal fade ezb-modal" id="ezbCloseModal" tabindex="-1" aria-labelledby="ezbCloseTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h(ezdBulkUrl('/close')) ?>" data-ezb-ids>
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="ezbCloseTitle"><i class="bi bi-lock-fill me-2" style="color:var(--m-red,#a20025)" aria-hidden="true"></i>Zamknij zaznaczone koszulki</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anuluj"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="back" value="<?= h($ezbBack) ?>">
        <div class="ezb-sum" data-ezb-sum="close"></div>
        <p class="small text-muted mb-3">Po zamknięciu dodawanie treści i edycja koszulek zostaną zablokowane, a oczekujące dekretacje zakończone — tak samo jak przy zamykaniu pojedynczej koszulki.</p>
        <div class="mb-3">
          <label class="form-label" for="ezbClosedOn">Data zamknięcia</label>
          <input type="date" class="form-control form-control-sm" id="ezbClosedOn" name="closed_on" value="<?= $ezbToday ?>" max="<?= $ezbToday ?>" required>
        </div>
        <div>
          <label class="form-label" for="ezbCloseNote">Notatka / powód (opcjonalnie)</label>
          <textarea class="form-control form-control-sm" id="ezbCloseNote" name="note" rows="3" maxlength="500" placeholder="Domyślnie: „Zamknięcie zbiorcze z listy koszulek.”"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Zamknij zaznaczone</button>
      </div>
    </form>
  </div>
</div>

<!-- Okno: zmiana prowadzącego -->
<div class="modal fade ezb-modal" id="ezbOwnerModal" tabindex="-1" aria-labelledby="ezbOwnerTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h(ezdBulkUrl('/owner')) ?>" data-ezb-ids>
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="ezbOwnerTitle"><i class="bi bi-person-gear me-2" aria-hidden="true"></i>Zmień prowadzącego</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anuluj"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="back" value="<?= h($ezbBack) ?>">
        <div class="ezb-sum" data-ezb-sum="edit"></div>
        <label class="form-label" for="ezbOwner">Nowy prowadzący</label>
        <select class="form-select form-select-sm" id="ezbOwner" name="owner_id" required>
          <option value="">— wybierz —</option>
          <option value="none">(bez prowadzącego)</option>
          <?php foreach ($ezbUsers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zmień w zaznaczonych</button>
      </div>
    </form>
  </div>
</div>

<!-- Okno: termin -->
<div class="modal fade ezb-modal" id="ezbDeadlineModal" tabindex="-1" aria-labelledby="ezbDeadlineTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h(ezdBulkUrl('/deadline')) ?>" data-ezb-ids>
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="ezbDeadlineTitle"><i class="bi bi-calendar-event me-2" aria-hidden="true"></i>Ustaw termin</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anuluj"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="back" value="<?= h($ezbBack) ?>">
        <div class="ezb-sum" data-ezb-sum="deadline"></div>
        <label class="form-label" for="ezbDeadline">Termin</label>
        <input type="date" class="form-control form-control-sm mb-2" id="ezbDeadline" name="deadline">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="ezbDeadlineClear" name="clear" value="1"
                 onchange="document.getElementById('ezbDeadline').disabled=this.checked">
          <label class="form-check-label small" for="ezbDeadlineClear">Usuń termin z zaznaczonych koszulek</label>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz w zaznaczonych</button>
      </div>
    </form>
  </div>
</div>

<!-- Okno: pilne -->
<div class="modal fade ezb-modal" id="ezbUrgentModal" tabindex="-1" aria-labelledby="ezbUrgentTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h(ezdBulkUrl('/urgent')) ?>" data-ezb-ids>
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="ezbUrgentTitle"><i class="bi bi-exclamation-triangle me-2" style="color:var(--m-red,#a20025)" aria-hidden="true"></i>Oznacz jako pilne</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Anuluj"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="back" value="<?= h($ezbBack) ?>">
        <div class="ezb-sum" data-ezb-sum="urgent"></div>
        <p class="small text-muted mb-0">„Pilne” ustawia priorytet <strong>Pilny</strong>; zdjęcie przywraca priorytet <strong>Normalny</strong>.</p>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" name="mode" value="off" class="btn btn-outline-secondary btn-sm"><i class="bi bi-flag me-1" aria-hidden="true"></i>Zdejmij pilne</button>
        <button type="submit" name="mode" value="on" class="btn btn-danger btn-sm"><i class="bi bi-flag-fill me-1" aria-hidden="true"></i>Oznacz pilne</button>
      </div>
    </form>
  </div>
</div>

<!-- Eksport CSV zaznaczonych (bez okna — od razu plik) -->
<form id="ezbCsvForm" method="post" action="<?= h(ezdBulkUrl('/csv')) ?>" data-ezb-ids hidden>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="back" value="<?= h($ezbBack) ?>">
</form>

<script>
(function () {
  var bar = document.getElementById('ezbBar');
  if (!bar) return;
  var all = document.getElementById('bulkAll');
  function cbs() { return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb')); }
  function checked() { return cbs().filter(function (c) { return c.checked; }); }
  function pl(n) { return n === 1 ? 'koszulka' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'koszulki' : 'koszulek'); }

  // Podsumowanie w oknach: ile zaznaczono i ile zostanie pominiętych z góry (ciągłe, zamknięte)
  function summary(kind) {
    var sel = checked(), n = sel.length;
    var ciagle = sel.filter(function (c) { return c.dataset.ciagla === '1'; }).length;
    var zamk = sel.filter(function (c) { return c.dataset.status === 'closed'; }).length;
    var txt = 'Zaznaczono: <strong>' + n + '</strong> ' + pl(n) + '.', warn = false, skip = [];
    if (kind === 'close') {
      if (ciagle) skip.push(ciagle + ' ' + (ciagle === 1 ? 'ciągła' : 'ciągłych'));
      if (zamk) skip.push(zamk + ' już ' + (zamk === 1 ? 'zamknięta' : 'zamkniętych'));
      if (skip.length) { txt += ' Zostaną pominięte: ' + skip.join(', ') + ' — oraz te, których nie możesz zamknąć.'; warn = true; }
      else txt += ' Pominięte zostaną koszulki, których nie możesz zamknąć.';
    }
    if (kind === 'edit') {
      txt += ' Zmiana jak w edycji pojedynczej koszulki — pominięte zostaną te bez prawa edycji'
        + (zamk ? ' oraz zamknięte (' + zamk + '), jeśli nie jesteś administratorem.' : '.');
      warn = zamk > 0;
    }
    if (kind === 'deadline' || kind === 'urgent') {
      txt += ' Pominięte zostaną koszulki bez prawa edycji' + (zamk ? ', zamknięte (' + zamk + ', jeśli nie jesteś administratorem)' : '')
        + (kind === 'deadline' && ciagle ? ' oraz ciągłe (' + ciagle + ') — nie mają terminu.' : '.');
      warn = zamk > 0 || (kind === 'deadline' && ciagle > 0);
    }
    return {html: txt, warn: warn};
  }

  function refresh() {
    var n = checked().length, total = cbs().length;
    document.getElementById('ezbCount').textContent = n;
    bar.hidden = n === 0;
    if (all) { all.checked = n > 0 && n === total; all.indeterminate = n > 0 && n < total; }
    var mc = document.getElementById('bulkModalCount'); if (mc) mc.textContent = n;
  }

  document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('bulk-cb')) refresh(); });
  if (all) all.addEventListener('change', function () { cbs().forEach(function (c) { c.checked = all.checked; }); refresh(); });
  document.getElementById('ezbClear').addEventListener('click', function () { cbs().forEach(function (c) { c.checked = false; }); refresh(); });

  // Otwarcie okna: odśwież podsumowanie
  document.addEventListener('show.bs.modal', function (e) {
    var box = e.target.querySelector('[data-ezb-sum]');
    if (!box) return;
    var s = summary(box.getAttribute('data-ezb-sum'));
    box.innerHTML = s.html;
    box.classList.toggle('ezb-sum--warn', s.warn);
  });

  // Wysłanie: dopisz ids[] zaznaczonych koszulek
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-ezb-ids')) return;
    var sel = checked();
    if (!sel.length) { e.preventDefault(); alert('Zaznacz przynajmniej jedną koszulkę.'); return; }
    if (typeof f.ezbConfirm === 'function' && !f.ezbConfirm(sel.length)) { e.preventDefault(); return; }
    Array.prototype.slice.call(f.querySelectorAll('input[data-ezb-id]')).forEach(function (i) { i.remove(); });
    sel.forEach(function (c) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value; i.setAttribute('data-ezb-id', '1');
      f.appendChild(i);
    });
  }, true);

  // Przerejestrowanie (okno w index.php): potwierdzenie z nazwą celu
  var rf = document.getElementById('bulkForm');
  if (rf) rf.ezbConfirm = function (n) {
    var sel = rf.querySelector('select[name="target"]');
    if (!sel || sel.value === '') { alert('Wskaż, gdzie przenieść.'); return false; }
    return confirm('Przenieść ' + n + ' zaznaczonych koszulek do:\n' + sel.options[sel.selectedIndex].text.trim() + '\n\nKażda otrzyma NOWY znak sprawy. Operacja nieodwracalna.');
  };

  refresh();
})();
</script>
