/**
 * dydApprovalModal(opts) — popup „podgląd → decyzja" dla zatwierdzeń i masowych zmian w panelu dydaktyka.
 *   opts.previewUrl  GET → JSON {error?, summary, items[], warnings[], blockers[], ids[], subject?, html?, recipients[], files[], links[]}
 *   opts.postUrl     POST (form) — pola: _token, course, ids[], send_mail + opts.pairs
 *   opts.pairs       [[name,value],…] dodatkowe pola formularza
 *   opts.title, opts.confirmLabel (z wysyłką), opts.confirmNoMailLabel
 * Modal jest dokładany do <body> (nigdy w zakładce); bez blockers przycisk jest aktywny.
 */
window.dydApprovalModal = function (o) {
  var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; };
  var kb = function (n) { return !n ? 'generowany automatycznie' : (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'); };
  var old = document.getElementById('dydApprModal'); if (old) old.remove();
  var el = document.createElement('div');
  el.id = 'dydApprModal'; el.className = 'modal fade'; el.tabIndex = -1; el.setAttribute('aria-labelledby', 'dydApprT'); el.setAttribute('aria-hidden', 'true');
  el.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><form method="post" action="' + esc(o.postUrl) + '">'
    + '<div class="modal-header"><h2 class="modal-title h5" id="dydApprT">' + esc(o.title || 'Zatwierdzenie') + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>'
    + '<div class="modal-body" id="dydApprBody" aria-live="polite">Ładowanie podglądu…</div>'
    + '<div class="modal-footer" id="dydApprFoot"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button></div>'
    + (o.pairs || []).map(function (p) { return '<input type="hidden" name="' + esc(p[0]) + '" value="' + esc(p[1]) + '">'; }).join('')
    + '<span id="dydApprIds"></span></form></div></div>';
  document.body.appendChild(el);
  bootstrap.Modal.getOrCreateInstance(el).show();
  fetch(o.previewUrl, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
    var b = document.getElementById('dydApprBody'), f = document.getElementById('dydApprFoot');
    if (d.error) { b.innerHTML = '<div class="alert alert-danger mb-0">' + esc(d.error) + '</div>'; return; }
    var h = '<p>' + esc(d.summary) + '</p>';
    if ((d.blockers || []).length) h += '<div class="alert alert-danger" role="alert"><strong><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Nie można wykonać:</strong><ul class="mb-0">' + d.blockers.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>';
    if ((d.warnings || []).length) h += '<div class="alert alert-warning" role="alert"><strong><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Uwaga:</strong><ul class="mb-0">' + d.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>';
    if ((d.items || []).length) h += '<details class="small mb-2"><summary>Terminy, których to dotyczy (' + d.items.length + ')</summary><ul class="mb-0">' + d.items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul></details>';
    var hasMail = !!d.html;
    if (hasMail) {
      h += '<h3 class="h6">Podgląd wiadomości e-mail</h3><p class="small mb-1"><strong>Temat:</strong> ' + esc(d.subject) + '</p>'
        + '<iframe title="Podgląd treści e-maila" sandbox="" style="width:100%;height:260px;border:1px solid #ccc;border-radius:6px;background:#fff" srcdoc="' + esc(d.html).replace(/"/g, '&quot;') + '"></iframe>';
      h += '<h3 class="h6 mt-3">Załączniki (' + (d.files || []).length + ')</h3>';
      h += (d.files || []).length ? '<ul class="small">' + d.files.map(function (x) { return '<li><i class="bi bi-paperclip me-1" aria-hidden="true"></i>' + esc(x.name) + (x.exists ? ' (' + kb(x.size) + ')' : ' — <span class="text-danger">brak pliku na dysku</span>') + '</li>'; }).join('') + '</ul>' : '<p class="small text-body-secondary">Brak załączników.</p>';
      if ((d.links || []).length) h += '<p class="small mb-1">Linki w mailu:</p><ul class="small">' + d.links.map(function (l) { return '<li>' + esc(l.title || l.url) + '</li>'; }).join('') + '</ul>';
      h += '<details class="small"><summary>Odbiorcy (' + (d.recipients || []).length + ')</summary><ul class="mb-0">' + d.recipients.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></details>';
      h += '<div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="send_mail" value="1" id="dydApprSend"' + ((d.recipients || []).length ? ' checked' : '') + '><label class="form-check-label" for="dydApprSend">Wyślij powyższy e-mail do kursantów (i opiekunów małoletnich)</label></div>';
    } else {
      h += '<p class="small text-body-secondary">Ta zmiana nie wysyła e-maila (żadna widoczna dla kursantów lekcja nie jest zmieniana).</p>';
    }
    b.innerHTML = h;
    document.getElementById('dydApprIds').innerHTML = (d.ids || []).map(function (i) { return '<input type="hidden" name="ids[]" value="' + esc(i) + '">'; }).join('');
    if ((d.blockers || []).length) return;
    f.insertAdjacentHTML('beforeend', '<button type="submit" class="btn btn-success"></button>');
    var sb = f.querySelector('button[type=submit]'), cb = document.getElementById('dydApprSend');
    function lbl() {
      var send = cb && cb.checked;
      sb.innerHTML = '<i class="bi bi-' + (send ? 'send' : 'check2') + ' me-1" aria-hidden="true"></i>' + esc(send ? (o.confirmLabel || 'Zatwierdź i wyślij') : (hasMail ? (o.confirmNoMailLabel || 'Zatwierdź bez wysyłki') : (o.confirmNoMailLabel || 'Zatwierdź')));
    }
    if (cb) cb.addEventListener('change', lbl); lbl();
  }).catch(function () { document.getElementById('dydApprBody').innerHTML = '<div class="alert alert-danger mb-0">Nie udało się pobrać podglądu.</div>'; });
};
