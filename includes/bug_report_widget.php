<?php
/**
 * includes/bug_report_widget.php — Modal zgłoszenia błędu (partial).
 * Dołączany do każdego nagłówka modułu, PO zamknięciu <header>.
 * Wymaga: current_user(), org_setting(), APP_URL, Bootstrap 5 JS.
 */
if (!function_exists('helpdesk_migrate')) {
    require_once __DIR__ . '/helpdesk.php';
}
helpdesk_migrate();

$_brw_user = current_user();
if (!$_brw_user || org_setting('bug_report_enabled') === '0') return;
?>
<div class="modal fade" id="bugReportModal" tabindex="-1"
     aria-labelledby="bugReportModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2"
           style="background:#fef2f2;border-bottom:1px solid #fecaca">
        <h5 class="modal-title fw-semibold" id="bugReportModalLabel"
            style="font-size:.95rem;color:#dc2626">
          <i class="bi bi-bug-fill me-2"></i>Zgłoś błąd / sugestię
        </h5>
        <button type="button" class="btn-close btn-sm"
                data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body pb-2">
        <div id="bugReportSuccess"
             class="alert alert-success d-none py-2" role="alert"
             style="font-size:.875rem">
          <i class="bi bi-check-circle-fill me-2"></i>
          Zgłoszenie przesłane. Numer: <strong id="bugReportNumber"></strong>
          — <a id="bugReportLink" href="#" target="_blank">otwórz ticket</a>
        </div>
        <div id="bugReportError"
             class="alert alert-danger d-none py-2" role="alert"
             style="font-size:.875rem"></div>
        <div id="bugReportForm">
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:.85rem">Typ zgłoszenia</label>
            <div class="d-flex gap-2 flex-wrap" role="radiogroup" aria-label="Typ zgłoszenia">
              <label class="btn btn-sm btn-outline-danger active" style="font-size:.8rem">
                <input type="radio" name="bugReportType" value="blad" class="d-none" checked>
                <i class="bi bi-bug me-1"></i>Błąd
              </label>
              <label class="btn btn-sm btn-outline-primary" style="font-size:.8rem">
                <input type="radio" name="bugReportType" value="sugestia" class="d-none">
                <i class="bi bi-lightbulb me-1"></i>Sugestia
              </label>
              <label class="btn btn-sm btn-outline-success" style="font-size:.8rem">
                <input type="radio" name="bugReportType" value="nowa_funkcja" class="d-none">
                <i class="bi bi-stars me-1"></i>Nowa funkcja
              </label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold"
                   style="font-size:.85rem">Adres strony</label>
            <input type="text" id="bugReportUrl"
                   class="form-control form-control-sm font-monospace"
                   readonly style="background:#f8fafc;font-size:.78rem">
          </div>
          <div class="mb-2">
            <label for="bugReportDesc" class="form-label fw-semibold"
                   style="font-size:.85rem">
              Opis <span class="text-danger">*</span>
            </label>
            <textarea id="bugReportDesc"
                      class="form-control form-control-sm" rows="4"
                      placeholder="Opisz problem, sugestię lub pomysł na nową funkcję…"
                      maxlength="2000"
                      style="font-size:.875rem;resize:vertical"></textarea>
            <div class="form-text" style="font-size:.74rem">
              Zgłoszony przez:
              <strong><?= h($_brw_user['name']) ?></strong>
              (<?= h($_brw_user['email']) ?>)
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2" id="bugReportFooter">
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-danger"
                id="bugReportSubmit">
          <i class="bi bi-send me-1"></i>Wyślij zgłoszenie
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('bugReportModal');
  if (!el) return;
  var urlInp = document.getElementById('bugReportUrl');
  var desc   = document.getElementById('bugReportDesc');
  var submit = document.getElementById('bugReportSubmit');
  var ok     = document.getElementById('bugReportSuccess');
  var err    = document.getElementById('bugReportError');
  var form   = document.getElementById('bugReportForm');
  var footer = document.getElementById('bugReportFooter');
  var typeRadios = document.querySelectorAll('[name="bugReportType"]');
  var ENDPOINT = '<?= APP_URL ?>/helpdesk/bug_report.php';

  typeRadios.forEach(function(r){
    r.addEventListener('change', function(){
      typeRadios.forEach(function(x){ x.closest('label').classList.remove('active'); });
      r.closest('label').classList.add('active');
    });
  });

  el.addEventListener('show.bs.modal', function(){
    urlInp.value = window.location.href;
    desc.value   = '';
    typeRadios.forEach(function(r){ r.closest('label').classList.remove('active'); });
    typeRadios[0].checked = true;
    typeRadios[0].closest('label').classList.add('active');
    ok.classList.add('d-none');
    err.classList.add('d-none');
    form.style.display   = '';
    footer.style.display = '';
    submit.disabled = false;
    submit.innerHTML = '<i class="bi bi-send me-1"></i>Wyślij zgłoszenie';
  });
  el.addEventListener('shown.bs.modal', function(){ desc.focus(); });

  submit.addEventListener('click', function(){
    err.classList.add('d-none');
    var d = desc.value.trim();
    if (d.length < 5) {
      err.textContent = 'Opis jest zbyt krótki — napisz co najmniej kilka słów.';
      err.classList.remove('d-none');
      desc.focus();
      return;
    }
    submit.disabled = true;
    submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wysyłanie…';
    var typeVal = 'blad';
    typeRadios.forEach(function(r){ if (r.checked) typeVal = r.value; });
    var body = new URLSearchParams();
    body.append('type', typeVal);
    body.append('page_url', urlInp.value);
    body.append('description', d);
    fetch(ENDPOINT, {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data.ok) {
        form.style.display = footer.style.display = 'none';
        ok.classList.remove('d-none');
        document.getElementById('bugReportNumber').textContent = data.number;
        document.getElementById('bugReportLink').href = data.url;
      } else {
        submit.disabled = false;
        submit.innerHTML = '<i class="bi bi-send me-1"></i>Wyślij zgłoszenie';
        err.textContent = data.error || 'Nieznany błąd.';
        err.classList.remove('d-none');
      }
    })
    .catch(function(){
      submit.disabled = false;
      submit.innerHTML = '<i class="bi bi-send me-1"></i>Wyślij zgłoszenie';
      err.textContent = 'Błąd połączenia — spróbuj ponownie.';
      err.classList.remove('d-none');
    });
  });
  desc.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); submit.click(); }
  });
})();
</script>
