<?php
/**
 * karty30/ti/_wup_pdf_preview.php — reużywalny modal podglądu PDF sprawozdania WUP
 * + delegowany handler. Przechwytuje submit formularza z atrybutem [data-wup-pdf],
 * generuje PDF przez fetch (inline), pokazuje go w iframe i udostępnia „Pobierz PDF".
 * Bez JS/Bootstrapa → zwykłe wysłanie formularza (pobranie pliku) jako fallback.
 * Dołączany na pełnej stronie raportu oraz na stronie Raporty (dla podglądu w modalu).
 */
?>
<div class="modal fade" id="wupPdfModal" tabindex="-1" aria-hidden="true" aria-labelledby="wupPdfLbl">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="height:90vh">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="wupPdfLbl"><i class="bi bi-file-earmark-pdf text-danger me-2"></i>Podgląd PDF — sprawozdanie WUP</h2>
        <div class="ms-auto d-flex align-items-center gap-2">
          <a href="#" id="wupPdfDownload" class="btn btn-danger btn-sm"><i class="bi bi-download me-1"></i>Pobierz PDF</a>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
      </div>
      <div class="modal-body p-0" style="position:relative;background:#525659">
        <div id="wupPdfLoading" class="text-center text-white-50 py-5"><div class="spinner-border" role="status"></div><div class="mt-2 small">Generowanie PDF…</div></div>
        <iframe id="wupPdfFrame" title="Podgląd PDF sprawozdania WUP" style="width:100%;height:100%;border:0;display:none"></iframe>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  if (window.__wupPdfInit) return; window.__wupPdfInit = true;   // inicjalizacja tylko raz
  document.addEventListener('submit', function (e) {
    var f = e.target && e.target.closest ? e.target.closest('form[data-wup-pdf]') : null;
    if (!f) return;
    if (!window.bootstrap) return;   // brak Bootstrapa → natywne wysłanie = pobranie (fallback)
    e.preventDefault();
    var modalEl = document.getElementById('wupPdfModal');
    var modal   = bootstrap.Modal.getOrCreateInstance(modalEl);
    var frame   = document.getElementById('wupPdfFrame');
    var load    = document.getElementById('wupPdfLoading');
    var dl      = document.getElementById('wupPdfDownload');
    var g = function (n) { var el = f.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
    var fname = 'sprawozdanie_WUP_' + (g('from') || '') + '_' + (g('to') || '') + '.pdf';
    frame.style.display = 'none'; load.style.display = 'block';
    load.innerHTML = '<div class="spinner-border" role="status"></div><div class="mt-2 small">Generowanie PDF…</div>';
    modal.show();
    var fd = new FormData(f); fd.set('disp', 'inline');   // wymuś podgląd inline (Content-Type: application/pdf)
    fetch(f.action || location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (blob) {
        var url = URL.createObjectURL(blob.type === 'application/pdf' ? blob : new Blob([blob], { type: 'application/pdf' }));
        frame.src = url; frame.style.display = 'block'; load.style.display = 'none';
        dl.onclick = function (ev) { ev.preventDefault(); var a = document.createElement('a'); a.href = url; a.download = fname; document.body.appendChild(a); a.click(); a.remove(); };
      })
      .catch(function (err) { load.innerHTML = '<div class="alert alert-danger m-3">Nie udało się wygenerować PDF: ' + err.message + '</div>'; });
  });
})();
</script>
