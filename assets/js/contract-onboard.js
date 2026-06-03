/**
 * contract-onboard.js
 * Logika modala onboardingowego wolontariusza.
 * Wymaga window.CVOnboardConfig = { csrf, appUrl, contractId, volUser, hasStep2 }
 */
(function () {
'use strict';

var cfg = window.CVOnboardConfig;
if (!cfg) return;

var currentStep = 1;

document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('onboardModal');
  if (m) bootstrap.Modal.getOrCreateInstance(m).show();
});

window.obNext = async function () {
  var btn = document.getElementById('ob-btn-next');
  btn.disabled = true;

  if (currentStep === 1) {
    var orgUnit = (document.getElementById('ob-org-unit') || {}).value;
    if (orgUnit) {
      try {
        var r = await fetch(cfg.appUrl + '/contracts/wolontariat/api_onboard.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ _csrf: cfg.csrf, contract_id: cfg.contractId, org_unit_id: parseInt(orgUnit) })
        }).then(function (r) { return r.json(); });
        if (!r.ok) { obError(r.error || 'Błąd zapisu.'); btn.disabled = false; return; }
      } catch (e) { obError('Błąd połączenia.'); btn.disabled = false; return; }
    }
    if (cfg.hasStep2) { obGoStep2(); } else { obFinish(); }

  } else if (currentStep === 2) {
    var checked = Array.from(document.querySelectorAll('.ob-task-check:checked')).map(function (el) { return parseInt(el.value); });
    if (checked.length) {
      try {
        for (var i = 0; i < checked.length; i++) {
          await fetch(cfg.appUrl + '/tasks/api/assign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf: cfg.csrf, task_id: checked[i], user_id: cfg.volUser, action: 'add' })
          }).then(function (r) { return r.json(); });
        }
      } catch (e) { obError('Błąd przypisania.'); btn.disabled = false; return; }
    }
    obFinish();
  }
  btn.disabled = false;
};

window.obNextStep = function () { obGoStep2(); };

function obGoStep2() {
  currentStep = 2;
  document.getElementById('onboard-step-1').style.display = 'none';
  document.getElementById('onboard-step-2').style.display = '';
  var i1 = document.getElementById('step-ind-1');
  var i2 = document.getElementById('step-ind-2');
  var l2 = document.getElementById('step-lbl-2');
  if (i1) { i1.style.background = '#16a34a'; i1.innerHTML = '<i class="bi bi-check2"></i>'; i1.removeAttribute('aria-current'); }
  if (i2) { i2.style.background = '#2563eb'; i2.style.color = '#fff'; i2.setAttribute('aria-current', 'step'); }
  if (l2) { l2.className = 'small fw-semibold'; }
  var btnNext = document.getElementById('ob-btn-next');
  if (btnNext) btnNext.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Przypisz i zakończ';
  var btnSkip = document.getElementById('ob-btn-skip');
  if (btnSkip) btnSkip.style.display = '';
}

function obFinish() {
  var modal = document.getElementById('onboardModal');
  if (modal) bootstrap.Modal.getInstance(modal)?.hide();
  var s = document.getElementById('ob-success');
  if (s) { s.textContent = 'Konfiguracja zapisana.'; s.classList.remove('d-none'); }
  var url = new URL(window.location.href);
  url.searchParams.delete('onboard');
  window.location.href = url.toString();
}

function obError(msg) {
  var e = document.getElementById('ob-error');
  if (e) { e.textContent = msg; e.classList.remove('d-none'); }
}
})();
