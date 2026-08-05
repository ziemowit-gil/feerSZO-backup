<?php
/**
 * includes/ezd_rsign.php — Modal „Podpisz kwalifikowanym podpisem" dla EZD.
 *
 * Integracja z lokalną aplikacją podpisującą (PEM-HEART, rSign, mSzafir…)
 * przez lokalny REST na localhost:{port}.
 * Przycisk: <button class="ezd-rsign-btn"
 *               data-zal-id="123"
 *               data-zal-name="plik.pdf">
 *
 * Konfiguracja w ustawieniach EZD (admin/ezd_settings.php):
 *   ezd_rsign_port       — port lokalny (PEM-HEART: 7778, rSign/proCertum: 52117)
 *   ezd_rsign_api_base   — pełna ścieżka endpointu podpisu (np. /api/sign)
 *   ezd_rsign_data_field — pole JSON z podpisanym dokumentem (signedData lub data)
 */
if (defined('EZD_RSIGN_MODAL_RENDERED')) return;
define('EZD_RSIGN_MODAL_RENDERED', 1);

$_rsign_port       = (int)(org_setting('ezd_rsign_port') ?: 7778);
$_rsign_sign_path  = '/' . ltrim(org_setting('ezd_rsign_api_base') ?: '/api/sign', '/');
$_rsign_data_field = preg_replace('/[^a-zA-Z0-9_]/', '', org_setting('ezd_rsign_data_field') ?: 'signedData');
?>
<div class="modal fade" id="rsignModal" tabindex="-1" aria-hidden="true" aria-labelledby="rsignModalLabel">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="rsignModalLabel">
          <i class="bi bi-pen-fill text-primary me-2"></i>Podpisz rSign (PAdES)
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body p-0">

        <!-- Plik -->
        <div class="px-3 pt-3 pb-2 border-bottom bg-light" style="font-size:.8rem">
          <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
          <span id="rsign-filename" class="text-muted">—</span>
        </div>

        <!-- Kroki -->
        <div class="px-3 py-3">
          <ol class="list-unstyled mb-0" id="rsign-steps" style="font-size:.85rem">
            <li class="d-flex align-items-start gap-2 mb-2" id="rsign-step-1">
              <span class="rsign-step-icon mt-1"></span>
              <div>
                <strong>Sprawdź rSign Desktop</strong>
                <div class="text-muted rsign-step-detail" style="font-size:.75rem"></div>
              </div>
            </li>
            <li class="d-flex align-items-start gap-2 mb-2" id="rsign-step-2">
              <span class="rsign-step-icon mt-1"></span>
              <div>
                <strong>Pobierz dokument z serwera</strong>
                <div class="text-muted rsign-step-detail" style="font-size:.75rem"></div>
              </div>
            </li>
            <li class="d-flex align-items-start gap-2 mb-2" id="rsign-step-3">
              <span class="rsign-step-icon mt-1"></span>
              <div>
                <strong>Podpisz w rSign Desktop</strong>
                <div class="text-muted rsign-step-detail" style="font-size:.75rem">Podaj PIN do karty/tokenu w oknie rSign.</div>
              </div>
            </li>
            <li class="d-flex align-items-start gap-2" id="rsign-step-4">
              <span class="rsign-step-icon mt-1"></span>
              <div>
                <strong>Zapisz podpisaną wersję w EZD</strong>
                <div class="text-muted rsign-step-detail" style="font-size:.75rem"></div>
              </div>
            </li>
          </ol>

          <div id="rsign-result" class="mt-3" style="display:none"></div>

          <!-- Komunikat o braku rSign -->
          <div id="rsign-no-app" class="alert alert-warning py-2 mt-3 mb-0" style="display:none;font-size:.8rem">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Aplikacja podpisująca nie odpowiada</strong> na porcie <strong><?= (int)$_rsign_port ?></strong>.<br>
            <ul class="mb-0 ps-3 mt-1">
              <li>Uruchom aplikację (PEM-HEART, rSign, mSzafir itp.).</li>
              <li>Podłącz token USB lub kartę kwalifikowaną.</li>
              <li>Sprawdź że lokalny serwer API jest włączony w ustawieniach aplikacji.</li>
              <li>Jeśli port jest inny — zmień go w <a href="<?= APP_URL ?>/admin/ezd_settings.php" target="_blank">Ustawieniach EZD</a>.</li>
            </ul>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-primary" id="rsign-start-btn">
          <i class="bi bi-play-fill me-1"></i>Podpisz
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';
  if (typeof bootstrap === 'undefined') return;

  var PORT       = <?= (int)$_rsign_port ?>;
  var SIGN_PATH  = <?= json_encode($_rsign_sign_path) ?>;
  var DATA_FIELD = <?= json_encode($_rsign_data_field) ?>;
  var BASE_URL   = '<?= APP_URL ?>';

  // ── Stan ────────────────────────────────────────────────────────────────────
  var state = { zalId: 0, zalName: '', csrf: '', docData: '' };

  // ── DOM helpers ──────────────────────────────────────────────────────────────
  function stepEl(n){ return document.getElementById('rsign-step-' + n); }
  function stepIcon(n){ return stepEl(n).querySelector('.rsign-step-icon'); }
  function stepDetail(n){ return stepEl(n).querySelector('.rsign-step-detail'); }

  var ICON_IDLE    = '<i class="bi bi-circle text-secondary" style="font-size:.9rem"></i>';
  var ICON_SPIN    = '<span class="spinner-border spinner-border-sm text-primary" style="width:.9rem;height:.9rem"></span>';
  var ICON_OK      = '<i class="bi bi-check-circle-fill text-success" style="font-size:.9rem"></i>';
  var ICON_ERR     = '<i class="bi bi-x-circle-fill text-danger" style="font-size:.9rem"></i>';
  var ICON_SKIP    = '<i class="bi bi-dash-circle text-secondary" style="font-size:.9rem"></i>';

  function resetSteps() {
    for (var i = 1; i <= 4; i++) {
      stepIcon(i).innerHTML = ICON_IDLE;
      var d = stepDetail(i);
      if (i !== 3) d.textContent = '';
    }
    document.getElementById('rsign-result').style.display = 'none';
    document.getElementById('rsign-no-app').style.display = 'none';
    document.getElementById('rsign-start-btn').disabled = false;
  }

  function setStep(n, state, detail) {
    var icons = {ok: ICON_OK, err: ICON_ERR, spin: ICON_SPIN, skip: ICON_SKIP, idle: ICON_IDLE};
    stepIcon(n).innerHTML = icons[state] || ICON_IDLE;
    if (detail !== undefined) stepDetail(n).textContent = detail;
  }

  function showResult(ok, html) {
    var el = document.getElementById('rsign-result');
    el.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0" style="font-size:.8rem">' + html + '</div>';
    el.style.display = 'block';
  }

  function esc(s){ return (''+s).replace(/[<>&"]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c];}); }

  // ── Inicjalizacja modalu ──────────────────────────────────────────────────────
  var modalEl = document.getElementById('rsignModal');
  var modal   = new bootstrap.Modal(modalEl);

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.ezd-rsign-btn');
    if (!btn) return;
    state.zalId   = parseInt(btn.dataset.zalId || btn.dataset.zal || '0', 10);
    state.zalName = btn.dataset.zalName || btn.dataset.name || '';
    document.getElementById('rsign-filename').textContent = state.zalName || 'plik.pdf';
    resetSteps();
    modal.show();
  });

  // ── Główna logika podpisu ─────────────────────────────────────────────────────
  document.getElementById('rsign-start-btn').addEventListener('click', function() {
    if (!state.zalId) return;
    this.disabled = true;
    document.getElementById('rsign-no-app').style.display = 'none';
    resetSteps();
    doSign();
  });

  async function doSign() {
    // ── Krok 1: Probe — sprawdź czy lokalna aplikacja działa ─────────────────
    setStep(1, 'spin');
    var appOk = false;
    // Próbuj HEAD/OPTIONS na endpoint podpisu — najbardziej niezawodne
    var probeUrls = [
      'http://localhost:' + PORT + SIGN_PATH,
      'http://localhost:' + PORT + '/',
    ];
    for (var pi = 0; pi < probeUrls.length && !appOk; pi++) {
      try {
        var pr = await fetchWithTimeout(probeUrls[pi], { method: 'HEAD', mode: 'cors' }, 3000);
        appOk = true;
      } catch(_) {
        try {
          var pr2 = await fetchWithTimeout(probeUrls[pi], { method: 'GET', mode: 'cors' }, 3000);
          appOk = true;
        } catch(_2) {}
      }
    }

    if (!appOk) {
      setStep(1, 'err', 'Brak odpowiedzi na porcie ' + PORT + ' — aplikacja nie działa lub port jest inny.');
      setStep(2, 'skip'); setStep(3, 'skip'); setStep(4, 'skip');
      document.getElementById('rsign-no-app').style.display = 'block';
      document.getElementById('rsign-start-btn').disabled = false;
      return;
    }
    setStep(1, 'ok', 'Aplikacja odpowiada na porcie ' + PORT + '.');

    // ── Krok 2: Pobierz dokument z serwera ───────────────────────────────────
    setStep(2, 'spin');
    var docRes;
    try {
      var r2 = await fetch(BASE_URL + '/ezd/sign.php?zal_id=' + state.zalId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      docRes = await r2.json();
    } catch(e) {
      setStep(2, 'err', 'Błąd sieci: ' + e.message);
      setStep(3, 'skip'); setStep(4, 'skip');
      showResult(false, 'Nie udało się pobrać dokumentu z serwera.');
      document.getElementById('rsign-start-btn').disabled = false;
      return;
    }
    if (docRes.error) {
      setStep(2, 'err', docRes.error);
      setStep(3, 'skip'); setStep(4, 'skip');
      showResult(false, esc(docRes.error));
      document.getElementById('rsign-start-btn').disabled = false;
      return;
    }
    state.csrf    = docRes.csrf;
    state.docData = docRes.data;
    var sizeKB    = Math.round((docRes.size || docRes.data.length * 0.75) / 1024);
    setStep(2, 'ok', docRes.name + ' (' + sizeKB + ' KB) pobrano.');

    // ── Krok 3: Wyślij do lokalnej aplikacji podpisującej ────────────────────
    setStep(3, 'spin', 'Oczekiwanie na PIN / zatwierdzenie w aplikacji…');
    var signedData;
    try {
      var r3 = await fetchWithTimeout(
        'http://localhost:' + PORT + SIGN_PATH,
        {
          method: 'POST',
          mode: 'cors',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            data:            state.docData,
            document:        state.docData,   // PEM-HEART może używać "document"
            fileName:        docRes.name,
            filename:        docRes.name,
            format:          'PAdES-BASELINE-B',
            signatureFormat: 'PAdES-BASELINE-B',
            reason:          'Podpisano elektronicznie — EZD',
            location:        'Polska',
          })
        },
        120000  // 2 min — użytkownik musi wpisać PIN
      );
      var r3json = await r3.json();
      if (!r3.ok || r3json.error || r3json.status === 'ERROR') {
        throw new Error(r3json.error || r3json.message || r3json.errorMessage || ('HTTP ' + r3.status));
      }
      // Obsługa różnych nazw pola z podpisanym dokumentem
      signedData = r3json[DATA_FIELD] || r3json.data || r3json.signedData || r3json.document || r3json.signedDocument;
      if (!signedData) {
        throw new Error('Odpowiedź aplikacji nie zawiera podpisanego dokumentu. Pola w odpowiedzi: ' + Object.keys(r3json).join(', '));
      }
    } catch(e) {
      setStep(3, 'err', e.message);
      setStep(4, 'skip');
      showResult(false, '<strong>Błąd aplikacji podpisującej:</strong> ' + esc(e.message)
        + '<br><small>Sprawdź PIN, czy token jest podłączony, i spróbuj ponownie.</small>');
      document.getElementById('rsign-start-btn').disabled = false;
      return;
    }

    var signedName = docRes.name.replace(/\.pdf$/i, '') + '_podpisany.pdf';
    setStep(3, 'ok', 'Dokument podpisany.');

    // ── Krok 4: Wyślij podpisany dokument na serwer ──────────────────────────
    setStep(4, 'spin');
    var saveRes;
    try {
      var r4 = await fetch(BASE_URL + '/ezd/sign.php?zal_id=' + state.zalId, {
        method: 'POST',
        headers: {
          'Content-Type':    'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          signed_data: signedData,
          signed_name: signedName,
          csrf:        state.csrf,
        })
      });
      saveRes = await r4.json();
    } catch(e) {
      setStep(4, 'err', 'Błąd sieci: ' + e.message);
      showResult(false, 'Nie udało się przesłać podpisanego dokumentu na serwer.');
      document.getElementById('rsign-start-btn').disabled = false;
      return;
    }

    if (!saveRes.ok) {
      setStep(4, 'err', saveRes.error || 'Błąd serwera.');
      showResult(false, esc(saveRes.error || 'Nieznany błąd serwera.'));
      document.getElementById('rsign-start-btn').disabled = false;
      return;
    }

    setStep(4, 'ok', 'Zapisano jako v' + saveRes.version + ' — podpisał: ' + esc(saveRes.signer) + '.');
    var intgBadge = saveRes.integrity === 'verified'
      ? '<span class="badge bg-success ms-1">integralność OK</span>'
      : (saveRes.integrity === 'unverified'
        ? '<span class="badge bg-danger ms-1">integralność ?</span>'
        : '');
    showResult(true,
      '<i class="bi bi-patch-check-fill me-1"></i><strong>Podpisano pomyślnie.</strong> '
      + esc(signedName) + ' — v' + saveRes.version + ' — ' + esc(saveRes.signer) + intgBadge
      + '<br><small>Odśwież stronę, aby zobaczyć nową wersję załącznika.</small>'
    );
    document.getElementById('rsign-start-btn').innerHTML =
      '<i class="bi bi-arrow-clockwise me-1"></i>Odśwież stronę';
    document.getElementById('rsign-start-btn').disabled = false;
    document.getElementById('rsign-start-btn').onclick = function(){ location.reload(); };
  }

  // ── Pomocnik: fetch z timeoutem ──────────────────────────────────────────────
  function fetchWithTimeout(url, opts, ms) {
    var ctrl = new AbortController();
    var tid  = setTimeout(function(){ ctrl.abort(); }, ms);
    return fetch(url, Object.assign({}, opts, { signal: ctrl.signal }))
      .finally(function(){ clearTimeout(tid); });
  }
})();
</script>
