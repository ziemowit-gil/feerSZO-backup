/**
 * contract-recovery.js
 * Logika modala kodu odzyskiwania dostępu.
 * Wymaga window.CVRecoveryConfig = { code, contractId, sendUrl }
 */
(function () {
var cfg = window.CVRecoveryConfig;
if (!cfg) return;

document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('recoveryModal');
  if (m) new bootstrap.Modal(m).show();
});

window.rcCopy = function () {
  if (!navigator.clipboard) {
    var el = document.getElementById('recovery-code-display');
    var range = document.createRange();
    range.selectNode(el);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
  } else {
    navigator.clipboard.writeText(cfg.code);
  }
  var info = document.getElementById('rc-copy-info');
  info.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Skopiowano do schowka';
  setTimeout(function () { info.textContent = ''; }, 3000);
};

window.rcSend = function (channel) {
  var btn  = document.getElementById('btn-rc-' + channel);
  var info = document.getElementById('rc-send-info');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wysyłanie…'; }
  info.textContent = '';

  var fd = new FormData();
  fd.append('id',      cfg.contractId);
  fd.append('channel', channel);
  fd.append('code',    cfg.code);

  fetch(cfg.sendUrl, { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (btn) btn.disabled = false;
      if (d.ok) {
        info.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + d.info + '</span>';
        if (btn) btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Wysłano';
      } else {
        info.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' + (d.error || 'Błąd wysyłki') + '</span>';
        if (btn) btn.innerHTML = channel === 'sms' ? '<i class="bi bi-chat-dots me-1"></i>Ponów SMS' : '<i class="bi bi-envelope me-1"></i>Ponów e-mail';
      }
    })
    .catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Błąd — ponów'; }
      info.innerHTML = '<span class="text-danger">Błąd połączenia</span>';
    });
};
})();
