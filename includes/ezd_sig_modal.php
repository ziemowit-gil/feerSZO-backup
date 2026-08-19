<?php
/* Współdzielony modal „Podpis elektroniczny" + walidacja kryptograficzna (AJAX).
   Przyciski .ezd-sig-btn z data-validate (pełny URL endpointu) lub data-zal
   (załącznik EZD) + data-file/data-type/data-signer/... Guard: render raz na stronę.
   Gdy DSS włączony: przycisk „Waliduj EU DSS" pojawia się obok OpenSSL. */
if (defined('EZD_SIG_MODAL_RENDERED')) return;
define('EZD_SIG_MODAL_RENDERED', 1);
if (!function_exists('dss_is_enabled')) require_once dirname(__DIR__) . '/includes/dss_client.php';
$_dss_on = function_exists('dss_is_enabled') && dss_is_enabled();
?>
<div class="modal fade" id="sigModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-patch-check-fill text-success me-2"></i>Podpis elektroniczny</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body" style="font-size:.85rem">
        <div class="text-muted mb-2" id="sig-file" style="font-size:.8rem"></div>
        <dl class="row mb-0" style="row-gap:.4rem">
          <dt class="col-4 col-sm-3 text-muted fw-normal">Rodzaj</dt><dd class="col-8 col-sm-9 mb-0" id="sig-type">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">Podpisał(a)</dt><dd class="col-8 col-sm-9 mb-0" id="sig-signer">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">Data podpisu</dt><dd class="col-8 col-sm-9 mb-0" id="sig-date">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">Powód</dt><dd class="col-8 col-sm-9 mb-0" id="sig-reason">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">Miejsce</dt><dd class="col-8 col-sm-9 mb-0" id="sig-location">—</dd>
        </dl>
        <div class="alert alert-light border mt-3 py-2" style="font-size:.76rem">
          <i class="bi bi-info-circle me-1"></i><span id="sig-note"></span>
        </div>
        <div id="sig-validation" class="mt-2" style="display:none"></div>
        <div id="sig-dss-result" class="mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-success" id="sig-validate-btn">
          <i class="bi bi-shield-check me-1"></i>Waliduj (OpenSSL)
        </button>
        <?php if ($_dss_on): ?>
        <button type="button" class="btn btn-sm btn-primary" id="sig-dss-btn">
          <i class="bi bi-patch-check me-1"></i>Waliduj EU DSS
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var modalEl = document.getElementById('sigModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;
  var modal = new bootstrap.Modal(modalEl);
  var curValidate = null;
  function setRow(id, val, fb){ var el=document.getElementById(id); el.textContent = (val && (''+val).trim()!=='') ? val : (fb||'—'); }
  function esc(s){ return (''+(s==null?'':s)).replace(/[<>&]/g,function(c){return{'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}); }

  var curDss = null;

  document.querySelectorAll('.ezd-sig-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      curValidate = btn.dataset.validate
        || (btn.dataset.zal ? ('<?= APP_URL ?>/ezd/validate_signature.php?zal_id=' + encodeURIComponent(btn.dataset.zal)) : null);
      curDss = btn.dataset.zal
        ? ('<?= APP_URL ?>/ezd/dss_validate.php?zal_id=' + encodeURIComponent(btn.dataset.zal))
        : (btn.dataset.dss || null);
      document.getElementById('sig-file').textContent = btn.dataset.file || '';
      setRow('sig-type', btn.dataset.type);
      setRow('sig-signer', btn.dataset.signer, 'nie podano w pliku');
      setRow('sig-date', btn.dataset.date, 'nie podano w pliku');
      setRow('sig-reason', btn.dataset.reason);
      setRow('sig-location', btn.dataset.location);
      document.getElementById('sig-note').innerHTML =
        (btn.dataset.note && btn.dataset.note.trim()!=='' ? (esc(btn.dataset.note) + ' · ') : '')
        + 'Dane odczytane z pliku. Kliknij przycisk walidacji, aby sprawdzić certyfikat i integralność.';
      var v  = document.getElementById('sig-validation');    v.style.display='none';  v.innerHTML='';
      var vd = document.getElementById('sig-dss-result');
      if (vd) { vd.style.display='none'; vd.innerHTML=''; }
      var vb = document.getElementById('sig-validate-btn');
      vb.disabled = !curValidate; vb.innerHTML = '<i class="bi bi-shield-check me-1"></i>Waliduj (OpenSSL)';
      var db_ = document.getElementById('sig-dss-btn');
      if (db_) db_.disabled = !curDss;
      modal.show();
    });
  });

  document.getElementById('sig-validate-btn').addEventListener('click', function(){
    if (!curValidate) return;
    var btn = this, v = document.getElementById('sig-validation');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Walidacja…';
    v.style.display='block';
    v.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Trwa walidacja kryptograficzna…</div>';
    fetch(curValidate, {headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){ renderValidation(d, v); })
      .catch(function(){ v.innerHTML = '<div class="alert alert-danger py-2 mb-0">Błąd połączenia podczas walidacji.</div>'; })
      .finally(function(){ btn.disabled=false; btn.innerHTML='<i class="bi bi-shield-check me-1"></i>Waliduj ponownie'; });
  });

  function renderValidation(d, v){
    if (!d || d.error){ v.innerHTML = '<div class="alert alert-danger py-2 mb-0">'+esc(d&&d.error?d.error:'Błąd walidacji.')+'</div>'; return; }
    var map = {
      verified:['success','bi-check-circle-fill','Integralność potwierdzona — dokument nie był zmieniany po podpisaniu.'],
      unverified:['danger','bi-x-circle-fill','Integralność NIE potwierdzona — dokument mógł zostać zmieniony lub podpis nie pasuje.'],
      no_tool:['secondary','bi-dash-circle','Integralności nie sprawdzono (brak narzędzia openssl na serwerze).'],
      'n/d':['secondary','bi-dash-circle','Weryfikacja integralności niedostępna dla tego formatu.']
    };
    var intg = map[d.integrity] || map['n/d'];
    var html = '<div class="text-muted mb-1" style="font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em">OpenSSL / lokalna weryfikacja</div>';
    html += '<div class="alert alert-'+intg[0]+' py-2"><i class="bi '+intg[1]+' me-1"></i>'+intg[2]+'</div>';
    if (d.certs && d.certs.length){
      d.certs.forEach(function(c){
        var vb = c.valid_now===true ? '<span class="badge bg-success">ważny</span>'
               : (c.valid_now===false ? '<span class="badge bg-danger">poza okresem ważności</span>' : '');
        var soon = (c.days_left!==null && c.days_left>=0 && c.days_left<30) ? ' <span class="badge bg-warning text-dark">wygasa za '+c.days_left+' dni</span>' : '';
        html += '<div class="border rounded-3 p-2 mb-2" style="font-size:.82rem">'
          + '<div class="fw-semibold">'+esc(c.cn)+(c.o?(' <span class="text-muted">· '+esc(c.o)+'</span>'):'')+' '+vb+soon+'</div>'
          + '<div class="text-muted" style="font-size:.74rem">Wystawca: '+esc(c.issuer)+'</div>'
          + '<div class="text-muted" style="font-size:.74rem">Ważność: '+esc(c.from)+' – '+esc(c.to)+(c.serial?(' · nr '+esc(c.serial)):'')+'</div>'
          + '</div>';
      });
    } else {
      html += '<div class="text-muted" style="font-size:.8rem">Nie odczytano danych certyfikatu.</div>';
    }
    if (d.messages && d.messages.length){
      html += '<div class="alert alert-light border py-2 mt-2 mb-0" style="font-size:.74rem">'+d.messages.map(esc).join('<br>')+'</div>';
    }
    if (!d.openssl_cli){
      html += '<div class="text-muted mt-2" style="font-size:.72rem">Pełny łańcuch zaufania zweryfikuj w walidatorze, np. <a href="https://weryfikacjapodpisu.pl" target="_blank" rel="noopener">weryfikacjapodpisu.pl</a>.</div>';
    }
    v.innerHTML = html;
  }

  // ── EU DSS ──────────────────────────────────────────────────────────────────
  var dssBtn = document.getElementById('sig-dss-btn');
  if (dssBtn) {
    dssBtn.addEventListener('click', function(){
      if (!curDss) return;
      var btn = this;
      var vd = document.getElementById('sig-dss-result');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>EU DSS…';
      vd.style.display = 'block';
      vd.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Walidacja EU DSS (LOTL)…</div>';
      fetch(curDss, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(d){ renderDssResult(d, vd); })
        .catch(function(){ vd.innerHTML = '<div class="alert alert-danger py-2 mb-0">Błąd połączenia z serwerem EU DSS.</div>'; })
        .finally(function(){ btn.disabled=false; btn.innerHTML='<i class="bi bi-patch-check me-1"></i>Waliduj EU DSS'; });
    });
  }

  function renderDssResult(d, v) {
    if (!d || !d.ok) {
      v.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-1"></i>'
        + esc(d && d.error ? d.error : 'Błąd walidacji EU DSS.') + '</div>';
      return;
    }

    var indClasses = {'TOTAL_PASSED':'success','TOTAL_FAILED':'danger','INDETERMINATE':'warning'};
    var indIcons   = {'TOTAL_PASSED':'bi-check-circle-fill','TOTAL_FAILED':'bi-x-circle-fill','INDETERMINATE':'bi-exclamation-triangle-fill'};
    var indLabels  = {'TOTAL_PASSED':'Podpis ważny','TOTAL_FAILED':'Podpis nieważny','INDETERMINATE':'Wynik nieokreślony'};
    var qualLabels = {
      'QESIG':         'Kwalifikowany podpis elektroniczny (QES)',
      'QESEAL':        'Kwalifikowana pieczęć elektroniczna (QESeal)',
      'ADESIG_QC':     'AdES z certyfikatem kwalifikowanym (AdES/QC)',
      'ADESSIG_QC':    'AdES z certyfikatem kwalifikowanym (AdES/QC)',
      'ADES_QC':       'AdES z certyfikatem kwalifikowanym (AdES/QC)',
      'ADESIG':        'Podpis zaawansowany (AdES)',
      'ADESSIG':       'Podpis zaawansowany (AdES)',
      'NOT_ADES':      'Niekwalifikowany / poza zakresem eIDAS',
    };

    var html = '<div class="text-muted mb-1" style="font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em">'
      + 'EU DSS — weryfikacja eIDAS'
      + (d.validation_time ? ' <span class="fw-normal">('+esc(d.validation_time)+')</span>' : '')
      + '</div>';

    if (!d.signatures || !d.signatures.length) {
      html += '<div class="alert alert-warning py-2">EU DSS nie wykrył podpisów w tym pliku.</div>';
      v.innerHTML = html; return;
    }

    d.signatures.forEach(function(sig, idx) {
      var indKey  = (sig.indication || '').replace(/-/g,'_');
      var cls     = indClasses[indKey]  || 'secondary';
      var ico     = indIcons[indKey]    || 'bi-dash-circle';
      var lbl     = indLabels[indKey]   || sig.indication || '—';
      var qualLbl = qualLabels[(sig.qualification||'').toUpperCase()] || (sig.qualification || '');
      var qualCls = sig.qualification && sig.qualification.indexOf('QES') === 0 ? 'success'
                  : (sig.qualification && sig.qualification.indexOf('ADES') === 0 ? 'info' : 'secondary');

      html += '<div class="border rounded-3 p-2 mb-2">';
      if (d.signatures.length > 1) {
        html += '<div class="fw-semibold mb-1" style="font-size:.78rem">Podpis #'+(idx+1)
              + (sig.is_timestamp ? ' (znacznik czasu)' : '') + '</div>';
      }
      html += '<div class="alert alert-'+cls+' py-2 mb-2 d-flex align-items-center gap-2">'
            + '<i class="bi '+ico+'"></i><strong>'+esc(lbl)+'</strong>';
      if (sig.sub_indication) html += ' <span class="small text-muted">('+esc(sig.sub_indication)+')</span>';
      html += '</div>';

      if (sig.qualification) {
        html += '<div class="mb-2"><span class="badge bg-'+qualCls+'">'
              + esc(qualLbl || sig.qualification) + '</span></div>';
      }

      var rows = [];
      if (sig.format)       rows.push(['Format', sig.format]);
      if (sig.signed_by)    rows.push(['Podpisał(a)', sig.signed_by]);
      if (sig.signing_time) rows.push(['Data podpisu', sig.signing_time]);
      if (sig.best_time && sig.best_time !== sig.signing_time) rows.push(['Najlepszy czas', sig.best_time]);
      if (sig.trust_anchor) rows.push(['Wystawca CA (LOTL)', sig.trust_anchor]);

      if (rows.length) {
        html += '<dl class="row mb-0" style="font-size:.78rem;row-gap:.2rem">';
        rows.forEach(function(r){
          html += '<dt class="col-5 col-sm-4 text-muted fw-normal">'+esc(r[0])+'</dt>'
                + '<dd class="col-7 col-sm-8 mb-0">'+esc(r[1])+'</dd>';
        });
        html += '</dl>';
      }

      if (sig.errors && sig.errors.length) {
        html += '<div class="alert alert-danger py-1 mt-2 mb-0" style="font-size:.76rem">'
              + '<i class="bi bi-exclamation-octagon me-1"></i>'
              + sig.errors.map(function(e){ return esc(typeof e==='object'?(e.message||e.value||JSON.stringify(e)):e); }).join('<br>')
              + '</div>';
      }
      if (sig.warnings && sig.warnings.length) {
        html += '<div class="alert alert-warning py-1 mt-2 mb-0" style="font-size:.76rem">'
              + '<i class="bi bi-exclamation-triangle me-1"></i>'
              + sig.warnings.map(function(w){ return esc(typeof w==='object'?(w.message||w.value||JSON.stringify(w)):w); }).join('<br>')
              + '</div>';
      }
      html += '</div>';
    });

    v.innerHTML = html;
  }
})();
</script>
