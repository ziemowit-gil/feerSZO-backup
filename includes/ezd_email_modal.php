<?php
/* Współdzielony modal „Podgląd e-maila" (.eml / .msg).
   Elementy z klasą .ezd-email-btn i atrybutem data-id="<id_zalacznika>"
   otwierają modal, pobierają JSON z email_view.php i ładują treść w iframe.
   Guard: renderowany raz na stronę. */
if (defined('EZD_EMAIL_MODAL_RENDERED')) return;
define('EZD_EMAIL_MODAL_RENDERED', 1);
?>
<div class="modal fade" id="emailPreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="height:92vh;display:flex;flex-direction:column">

      <!-- Nagłówek modala -->
      <div class="modal-header py-2 flex-shrink-0">
        <h6 class="modal-title text-truncate mb-0" id="emailModalTitle">
          <i class="bi bi-envelope-open text-primary me-2"></i>
          <span id="emailModalSubject">Podgląd wiadomości</span>
        </h6>
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
          <a href="#" id="emailModalDownload" class="btn btn-sm btn-outline-secondary"
             title="Pobierz oryginał"><i class="bi bi-download"></i></a>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
      </div>

      <!-- Panel nagłówków wiadomości (styl Outlook) -->
      <div id="emailHeaderPanel" class="flex-shrink-0 border-bottom px-3 py-2"
           style="background:#f8fafc;font-size:.78rem;display:none">
        <dl class="mb-0 row g-0" style="row-gap:.15rem">
          <dt class="col-auto pe-2 text-muted fw-semibold" style="min-width:3.5rem">Od:</dt>
          <dd class="col mb-0" id="emailHdrFrom">—</dd>

          <div class="w-100"></div>

          <dt class="col-auto pe-2 text-muted fw-semibold" style="min-width:3.5rem">Do:</dt>
          <dd class="col mb-0" id="emailHdrTo">—</dd>

          <div class="w-100" id="emailHdrCcRow" style="display:none"></div>
          <dt class="col-auto pe-2 text-muted fw-semibold" id="emailHdrCcLbl"
              style="min-width:3.5rem;display:none">DW:</dt>
          <dd class="col mb-0" id="emailHdrCc" style="display:none">—</dd>

          <div class="w-100"></div>

          <dt class="col-auto pe-2 text-muted fw-semibold" style="min-width:3.5rem">Data:</dt>
          <dd class="col mb-0" id="emailHdrDate">—</dd>
        </dl>

        <!-- Załączniki wewnątrz wiadomości e-mail -->
        <div id="emailInnerAttachments" class="mt-2" style="display:none">
          <div class="d-flex flex-wrap gap-1" id="emailAttachList"></div>
        </div>
      </div>

      <!-- Spinner ładowania -->
      <div id="emailLoadingPanel" class="flex-grow-1 d-flex align-items-center justify-content-center">
        <div class="text-center text-muted">
          <div class="spinner-border spinner-border-sm mb-2" role="status"></div>
          <div style="font-size:.8rem">Ładowanie wiadomości…</div>
        </div>
      </div>

      <!-- Błąd -->
      <div id="emailErrorPanel" class="flex-grow-1 d-flex align-items-center justify-content-center"
           style="display:none!important">
        <div class="text-center text-danger" style="font-size:.85rem">
          <i class="bi bi-exclamation-triangle-fill fs-4 mb-2 d-block"></i>
          <span id="emailErrorText">Błąd ładowania</span>
        </div>
      </div>

      <!-- Treść wiadomości w iframe (izolacja XSS) -->
      <iframe id="emailBodyFrame"
              sandbox="allow-same-origin"
              referrerpolicy="no-referrer"
              src="about:blank"
              title="Treść wiadomości e-mail"
              style="flex:1 1 auto;border:0;width:100%;display:none"></iframe>

    </div>
  </div>
</div>

<script>
(function () {
  var modalEl = document.getElementById('emailPreviewModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;

  var modal       = new bootstrap.Modal(modalEl);
  var subjEl      = document.getElementById('emailModalSubject');
  var dlBtn       = document.getElementById('emailModalDownload');
  var hdrPanel    = document.getElementById('emailHeaderPanel');
  var loadPanel   = document.getElementById('emailLoadingPanel');
  var errPanel    = document.getElementById('emailErrorPanel');
  var bodyFrame   = document.getElementById('emailBodyFrame');
  var hdrFrom     = document.getElementById('emailHdrFrom');
  var hdrTo       = document.getElementById('emailHdrTo');
  var hdrCcRow    = document.getElementById('emailHdrCcRow');
  var hdrCcLbl    = document.getElementById('emailHdrCcLbl');
  var hdrCc       = document.getElementById('emailHdrCc');
  var hdrDate     = document.getElementById('emailHdrDate');
  var attPanel    = document.getElementById('emailInnerAttachments');
  var attList     = document.getElementById('emailAttachList');

  // Formatowanie adresu e-mail
  function fmtAddr(a) {
    if (!a) return '—';
    var name  = (a.name  || '').trim();
    var email = (a.email || '').trim();
    if (name && email) return esc(name) + ' &lt;<span class="text-muted">' + esc(email) + '</span>&gt;';
    return esc(name || email || '—');
  }

  function fmtAddrList(arr) {
    if (!arr || !arr.length) return '—';
    return arr.map(fmtAddr).join('; ');
  }

  function fmtDate(ts) {
    if (!ts) return '—';
    var d = new Date(ts * 1000);
    var pad = function (n) { return ('0' + n).slice(-2); };
    return d.getDate() + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
      + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function esc(s) {
    return ('' + (s == null ? '' : s))
      .replace(/[<>&"]/g, function (c) {
        return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c];
      });
  }

  function fileIcon(name) {
    var ext = (name || '').split('.').pop().toLowerCase();
    var map = {
      pdf: 'bi-filetype-pdf text-danger',
      doc: 'bi-filetype-docx text-primary', docx: 'bi-filetype-docx text-primary',
      xls: 'bi-filetype-xlsx text-success', xlsx: 'bi-filetype-xlsx text-success',
      png: 'bi-filetype-png text-info',  jpg: 'bi-file-image text-info',
      jpeg: 'bi-file-image text-info',   gif: 'bi-file-image text-info',
      zip: 'bi-file-zip text-warning',   txt: 'bi-filetype-txt text-secondary',
      msg: 'bi-envelope text-primary',   eml: 'bi-envelope text-primary',
    };
    return 'bi ' + (map[ext] || 'bi-file-earmark text-secondary');
  }

  function humanSize(bytes) {
    if (!bytes) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
  }

  function setState(state) {
    loadPanel.style.display  = state === 'loading' ? '' : 'none';
    errPanel.style.display   = state === 'error'   ? '' : 'none';
    bodyFrame.style.display  = state === 'ready'   ? '' : 'none';
    hdrPanel.style.display   = state === 'ready'   ? '' : 'none';
  }

  function openEmail(id, originalName) {
    var baseUrl = '<?= APP_URL ?>/ezd/email_view.php?id=' + encodeURIComponent(id);

    subjEl.textContent = originalName || 'Wiadomość e-mail';
    dlBtn.href = '<?= APP_URL ?>/ezd/serve.php?id=' + encodeURIComponent(id) + '&dl=1';
    setState('loading');
    hdrPanel.style.display = 'none';
    bodyFrame.src = 'about:blank';
    modal.show();

    fetch(baseUrl + '&_ajax=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) {
          document.getElementById('emailErrorText').textContent = d.error || 'Błąd parsowania';
          setState('error');
          return;
        }

        // Wypełnij nagłówki
        subjEl.textContent = d.subject || originalName || '(bez tematu)';
        hdrFrom.innerHTML  = fmtAddr({ name: d.from_name, email: d.from_email });
        hdrTo.innerHTML    = fmtAddrList(d.to);
        hdrDate.textContent = fmtDate(d.date);

        // DW
        if (d.cc && d.cc.length) {
          hdrCcRow.style.display = '';
          hdrCcLbl.style.display = '';
          hdrCc.style.display    = '';
          hdrCc.innerHTML = fmtAddrList(d.cc);
        } else {
          hdrCcRow.style.display = 'none';
          hdrCcLbl.style.display = 'none';
          hdrCc.style.display    = 'none';
        }

        // Załączniki w wiadomości
        attList.innerHTML = '';
        if (d.attachments && d.attachments.length) {
          d.attachments.forEach(function (a) {
            attList.innerHTML +=
              '<span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1" style="font-size:.7rem;font-weight:400">'
              + '<i class="' + fileIcon(a.name) + '"></i>'
              + esc(a.name)
              + ' <span class="text-muted">(' + humanSize(a.size) + ')</span>'
              + '</span>';
          });
          attPanel.style.display = '';
        } else {
          attPanel.style.display = 'none';
        }

        // Załaduj treść w iframe
        bodyFrame.src = baseUrl + '&_body=1';
        setState('ready');
      })
      .catch(function () {
        document.getElementById('emailErrorText').textContent = 'Błąd połączenia z serwerem';
        setState('error');
      });
  }

  // Delegacja kliknięć na .ezd-email-btn
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ezd-email-btn');
    if (!btn) return;
    e.preventDefault();
    openEmail(btn.dataset.id, btn.dataset.name || '');
  });

  // Wyczyść po zamknięciu
  modalEl.addEventListener('hidden.bs.modal', function () {
    bodyFrame.src = 'about:blank';
    setState('loading');
  });
})();
</script>
