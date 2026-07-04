<?php
/* Współdzielony modal „Podgląd PDF" — wyskakujące okienko z iframe zamiast
   nawigacji do osobnej karty. Przyciski/linki .ezd-pdf-btn z data-url (adres
   pliku, wyświetlany inline) + opcjonalnie data-name (nazwa do tytułu okna).
   Guard: render raz na stronę. */
if (defined('EZD_PDF_MODAL_RENDERED')) return;
define('EZD_PDF_MODAL_RENDERED', 1);
?>
<div class="modal fade" id="pdfPreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="height:90vh">
      <div class="modal-header py-2">
        <h6 class="modal-title text-truncate mb-0" id="pdfPreviewTitle"><i class="bi bi-filetype-pdf text-danger me-2"></i>Podgląd PDF</h6>
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
          <a href="#" id="pdfPreviewOpenTab" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Otwórz w nowej karcie"><i class="bi bi-box-arrow-up-right"></i></a>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
      </div>
      <div class="modal-body p-0">
        <iframe id="pdfPreviewFrame" src="about:blank" title="Podgląd PDF" style="width:100%;height:100%;border:0"></iframe>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var modalEl = document.getElementById('pdfPreviewModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;
  var modal   = new bootstrap.Modal(modalEl);
  var frame   = document.getElementById('pdfPreviewFrame');
  var title   = document.getElementById('pdfPreviewTitle');
  var openTab = document.getElementById('pdfPreviewOpenTab');

  function esc(s){ return (''+(s==null?'':s)).replace(/[<>&]/g,function(c){return{'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}); }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.ezd-pdf-btn');
    if (!btn) return;
    var url = btn.dataset.url;
    if (!url) return;
    e.preventDefault();
    frame.src = url;
    openTab.href = url;
    title.innerHTML = '<i class="bi bi-filetype-pdf text-danger me-2"></i>' + (btn.dataset.name ? esc(btn.dataset.name) : 'Podgląd PDF');
    modal.show();
  });

  modalEl.addEventListener('hidden.bs.modal', function(){ frame.src = 'about:blank'; });
})();
</script>
