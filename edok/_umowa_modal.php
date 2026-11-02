<?php
/**
 * edok/_umowa_modal.php — popup „Wskaż umowę” (Wpływ na podstawie umowy). Wspólny dla edok/add.php i edok/view.php.
 * Dołączać POZA formularzami/zakładkami (na końcu strony). JS: edokUmowaOpen(prefix) — prefix pól:
 *   #{prefix}_type, #{prefix}_id (hidden), #{prefix}_label (span), #{prefix}_check (checkbox, opcjonalnie).
 * Wybór wywołuje też window.edokUmowaPicked(prefix, umowa), jeśli zdefiniowane (np. podpowiedź źródła przychodu).
 */
?>
<div class="modal fade" id="edokUmowaModal" tabindex="-1" aria-labelledby="edokUmowaLabel">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="edokUmowaLabel"><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Wskaż umowę, z której wynika wpływ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small fw-semibold" for="edokUmowaQ">Szukaj w Rejestrze Umów</label>
        <input type="search" id="edokUmowaQ" class="form-control" autocomplete="off" placeholder="nr RU, numer umowy albo strona (np. NIW, Urząd Miasta, Kowalski)">
        <div class="form-text mb-2">Wszystkie umowy wszystkich typów (także bez numeru w Rejestrze Umów), najnowsze pierwsze. Wybrana umowa trafia do dokumentu jako „Umowa nr” z linkiem do karty.</div>
        <div id="edokUmowaRes" class="list-group" aria-live="polite"></div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var prefix = null, timer = null;
  function el(id) { return document.getElementById(id); }
  window.edokUmowaOpen = function (p) {
    prefix = p;
    var q = el('edokUmowaQ'); q.value = '';
    el('edokUmowaRes').innerHTML = '';
    bootstrap.Modal.getOrCreateInstance(el('edokUmowaModal')).show();
    setTimeout(function () { q.focus(); search(''); }, 300);
  };
  window.edokUmowaClear = function (p) {
    ['_type', '_id'].forEach(function (s) { var e = el(p + s); if (e) e.value = ''; });
    var l = el(p + '_label'); if (l) l.textContent = '';
    var c = el(p + '_check'); if (c) c.checked = false;
  };
  function pick(u) {
    if (!prefix) return;
    var t = el(prefix + '_type'), i = el(prefix + '_id'), l = el(prefix + '_label'), c = el(prefix + '_check');
    if (t) t.value = u.contract_type; if (i) i.value = u.id;
    if (l) l.textContent = u.nr_rejestru + ' — ' + (u.strona || u.numer_umowy) + ' (' + u.typ_label + ')';
    if (c) c.checked = true;
    if (typeof window.edokUmowaPicked === 'function') window.edokUmowaPicked(prefix, u);
    bootstrap.Modal.getInstance(el('edokUmowaModal')).hide();
  }
  function search(q) {
    fetch('<?= APP_URL ?>/edok/search_umowa.php?q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var box = el('edokUmowaRes'); box.innerHTML = '';
        if (!res.results || !res.results.length) { box.innerHTML = '<div class="list-group-item text-muted small">Brak umów pasujących do „' + q.replace(/</g, '&lt;') + '”.</div>'; return; }
        res.results.forEach(function (u) {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action';
          b.innerHTML = '<div class="d-flex justify-content-between gap-2"><span><strong class="font-monospace">' + u.nr_rejestru.replace(/</g, '&lt;') + '</strong> · ' + (u.strona || '').replace(/</g, '&lt;') + '</span><span class="small text-muted text-nowrap">' + u.typ_label.replace(/</g, '&lt;') + (u.status ? ' · ' + u.status.replace(/</g, '&lt;') : '') + '</span></div>' + (u.numer_umowy ? '<div class="small text-muted">umowa nr ' + u.numer_umowy.replace(/</g, '&lt;') + (u.data ? ' z ' + u.data : '') + '</div>' : '');
          b.addEventListener('click', function () { pick(u); });
          box.appendChild(b);
        });
      })
      .catch(function () { el('edokUmowaRes').innerHTML = '<div class="list-group-item text-danger small">Błąd sieci.</div>'; });
  }
  document.addEventListener('DOMContentLoaded', function () {
    var q = el('edokUmowaQ'); if (!q) return;
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { search(q.value.trim()); }, 250); });
  });
})();
</script>
