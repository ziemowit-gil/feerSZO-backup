/**
 * feer_qsign — podpis kwalifikowany wiadomości przez agenta SzoCert (PEM-HEART, CAdES).
 * Przebieg: Wyślij → serwer zwraca token → pobierz część MIME → agent (potwierdzenie,
 * PEM-HEART) → serwer sprawdza CMS → Wyślij ponownie z tokenem.
 */
window.rcmail && rcmail.addEventListener('init', function () {
  if (rcmail.env.action !== 'compose') return;

  var t = function (k) { return rcmail.gettext('feer_qsign.' + k); };
  var agent = String(rcmail.env.feer_qsign_agent || 'http://127.0.0.1:52118').replace(/\/+$/, '');
  var box = $('#feer-qsign'), row = $('#feer-qsign-row'), status = $('#feer-qsign-status'), tokenField = $('#feer-qsign-token');
  if (!box.length) return;

  // Hidden token field and status must be inside the compose form, next to the option.
  var ms = $('#mimeshield-compose');
  if (ms.length) {
    var last = ms.children('.form-group').last();
    (last.length ? last : ms.children('legend').first()).after(row);
    row.after(status);
    status.after($('#feer-pades-row'));
  }
  tokenField.appendTo(rcmail.gui_objects.messageform || $('form[name=form]'));

  var msSign = $('#mimeshield-sign'), msEnc = $('#mimeshield-encrypt');
  var resending = false, jobId = null, dialog = null, cancelled = false;

  function setStatus(text, level) {
    status.prop('hidden', !text).text(text || '').attr('data-level', level || '');
  }

  function agentFetch(path, opts) {
    opts = opts || {};
    return fetch(agent + path, $.extend({ mode: 'cors', credentials: 'omit', cache: 'no-store', targetAddressSpace: 'loopback' }, opts))
      .then(function (r) { return r.json(); });
  }

  function ping() {
    setStatus(t('checking'));
    return agentFetch('/qsign/ping').then(function (r) {
      if (!r.ok) throw new Error();
      setStatus(r.signer_installed ? t('agentok') : t('nopemheart'), r.signer_installed ? 'ok' : 'warning');
      return r;
    }, function () {
      setStatus(t('noagent'), 'error');
      throw new Error(t('noagent'));
    });
  }

  // Dwa podpisy naraz (mimeshield + kwalifikowany) są niedozwolone — zostaje jeden.
  box.on('change', function () {
    tokenField.val('');
    if (this.checked) {
      if (msSign.prop('checked') || msEnc.prop('checked')) {
        msSign.prop('checked', false).trigger('change');
        msEnc.prop('checked', false).trigger('change');
        rcmail.display_message(t('mimeshieldoff'), 'notice');
      }
      ping().catch(function () {});
    } else {
      setStatus('');
    }
  });
  msSign.add(msEnc).on('change', function () {
    if (this.checked && box.prop('checked')) {
      box.prop('checked', false).trigger('change');
    }
  });

  // Każde wysłanie przez użytkownika (nie nasze ponowienie) zaczyna podpis od nowa —
  // treść mogła się zmienić po nieudanej wysyłce.
  rcmail.addEventListener('beforesend', function () {
    if (!resending) tokenField.val('');
    resending = false;
  });

  rcmail.addEventListener('plugin.feer_qsign_reset', function () {
    tokenField.val('');
    closeDialog();
  });

  // ── okno postępu ──────────────────────────────────────────────────────────
  var STEPS = ['step_agent', 'step_confirm', 'step_pemheart', 'step_verify', 'step_send'];

  function openDialog() {
    var list = $('<ol class="feer-qsign-steps">');
    $.each(STEPS, function (i, k) {
      list.append($('<li>').attr('data-step', k).append($('<span class="feer-qsign-mark" aria-hidden="true">')).append($('<span>').text(t(k))));
    });
    var content = $('<div class="feer-qsign-dialog">')
      .append($('<p>').text(t('intro')))
      .append(list)
      .append($('<p class="feer-qsign-msg" role="status" aria-live="polite">'));
    cancelled = false;
    dialog = rcmail.show_popup_dialog(content, t('title'), [{
      text: rcmail.gettext('cancel'), 'class': 'cancel',
      click: function () { cancel(); }
    }], { width: 520, close: function () { if (dialog) cancel(); } });
  }

  function dlg() { return dialog ? dialog : $(); }

  function step(k, state, msg) {
    var li = dlg().find('li[data-step="' + k + '"]');
    li.removeClass('active done error').addClass(state);
    if (state === 'active') {
      li.prevAll().removeClass('active').addClass('done');
    }
    if (msg !== undefined) dlg().find('.feer-qsign-msg').text(msg).toggleClass('error', state === 'error');
  }

  function closeDialog() {
    var d = dialog;
    dialog = null;
    if (d) {
      try { d.dialog('close'); } catch (e) {}
    }
  }

  function cancel() {
    cancelled = true;
    if (jobId) agentFetch('/qsign/cancel?id=' + encodeURIComponent(jobId), { method: 'POST' }).catch(function () {});
    jobId = null;
    tokenField.val('');
    closeDialog();
  }

  function fail(k, msg) {
    step(k, 'error', msg);
    dlg().closest('.ui-dialog').find('.ui-dialog-buttonpane .cancel').text(rcmail.gettext('close'));
    jobId = null;
  }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function serverFetch(action, opts) {
    var url = rcmail.url('plugin.feer_qsign-' + action, opts.query || {});
    return fetch(url, {
      method: opts.body ? 'POST' : 'GET', credentials: 'same-origin', body: opts.body,
      headers: { 'X-Roundcube-Request': rcmail.env.request_token }
    }).then(function (r) { return r.json(); });
  }

  // ── PAdES: podpis załączników PDF od razu, przed wysyłką ─────────────────
  var padesStatus = $('#feer-pades-status'), padesBusy = false;

  function padesSay(text, level) {
    padesStatus.prop('hidden', !text).text(text || '').attr('data-level', level || '');
  }

  function markSigned(id, signer) {
    var li = $('#rcmfile' + id);
    li.addClass('feer-pades-signed').attr('title', t('pades_badge') + ': ' + signer);
    if (!li.find('.feer-pades-badge').length) {
      li.find('.attachment-name, a.filename').first().append($('<span class="feer-pades-badge">').text(' ✓ ' + t('pades_badge')));
    }
  }

  $('#feer-pades-btn').on('click', function () {
    if (padesBusy) return;
    var compose = { _id: rcmail.env.compose_id };
    padesBusy = true;
    padesSay(t('checking'));
    var done = 0, failed = null;
    ping().then(function () {
      return serverFetch('pdfs', { query: compose });
    }).then(function (r) {
      if (!r.ok) throw new Error(r.error);
      $.each(r.files, function (i, f) { if (f.signed) markSigned(f.id, ''); });
      var todo = $.grep(r.files, function (f) { return !f.signed; });
      if (!todo.length) {
        padesSay(t(r.files.length ? 'pades_allsigned' : 'pades_none'), 'warning');
        return;
      }
      // po kolei: PEM-HEART obsługuje jeden plik naraz
      return todo.reduce(function (chain, f) {
        return chain.then(function () {
          padesSay(t('pades_file') + ' ' + f.name + ' — ' + t('pades_hint'));
          return serverFetch('pdf', { query: $.extend({ _file: f.id }, compose) }).then(function (r) {
            if (!r.ok) throw new Error(r.error);
            return agentFetch('/qsign/sign', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ kind: 'pades', filename: f.name, data_b64: r.data_b64, subject: $('#compose-subject').val() || '' })
            });
          }).then(function (r) {
            if (!r.ok) throw new Error(r.error || t('agenterror'));
            var id = r.job_id;
            var poll = function () {
              return agentFetch('/qsign/job?id=' + encodeURIComponent(id)).then(function (j) {
                if (j.status === 'pending') return wait(1500).then(poll);
                if (j.status !== 'done') throw new Error(j.error || t('agenterror'));
                return j;
              });
            };
            return poll();
          }).then(function (j) {
            var fd = new FormData();
            fd.append('_id', rcmail.env.compose_id);
            fd.append('_file', f.id);
            fd.append('_pdf', j.pdf_b64);
            return serverFetch('pdfstore', { body: fd });
          }).then(function (r) {
            if (!r.ok) throw new Error(f.name + ': ' + r.error);
            markSigned(f.id, r.signer);
            done++;
            if (!r.incremental) rcmail.display_message(f.name + ': ' + t('pades_rewritten'), 'warning', 10000);
          });
        });
      }, Promise.resolve());
    }).catch(function (e) {
      failed = (e && e.message) || t('agenterror');
    }).then(function () {
      padesBusy = false;
      if (failed) padesSay(failed, 'error');
      else if (done) padesSay(t('pades_done').replace('$n', done), 'ok');
    });
  });

  rcmail.addEventListener('plugin.feer_qsign_sign', function (data) {
    var token = data.token, current = 'step_agent';
    openDialog();
    step(current, 'active', '');

    ping().then(function () {
      return serverFetch('entity', { query: { _qtoken: token } });
    }).then(function (r) {
      if (!r.ok) throw new Error(r.error);
      current = 'step_confirm';
      step(current, 'active', t('confirmhint'));
      return agentFetch('/qsign/sign', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ data_b64: r.data_b64, from: data.from, to: data.to, subject: data.subject })
      });
    }).then(function (r) {
      if (!r.ok) throw new Error(r.error || t('agenterror'));
      jobId = r.job_id;
      current = 'step_pemheart';
      step(current, 'active', t('pemhearthint'));
      var poll = function () {
        if (cancelled) throw new Error('cancelled');
        return agentFetch('/qsign/job?id=' + encodeURIComponent(jobId)).then(function (j) {
          if (j.status === 'pending') return wait(1500).then(poll);
          if (j.status !== 'done') throw new Error(j.error || t('agenterror'));
          return j;
        });
      };
      return poll();
    }).then(function (j) {
      jobId = null;
      current = 'step_verify';
      step(current, 'active', t('signedby') + ' ' + j.signer);
      var fd = new FormData();
      fd.append('_qtoken', token);
      fd.append('_cms', j.cms_b64);
      return serverFetch('store', { body: fd });
    }).then(function (r) {
      if (!r.ok) throw new Error(r.error);
      current = 'step_send';
      step(current, 'active', t('signedby') + ' ' + r.signer);
      tokenField.val(token);
      closeDialog();
      resending = true;
      rcmail.command('send', { nocheck: true });
    }).catch(function (e) {
      if (cancelled || (e && e.message === 'cancelled')) return;
      fail(current, (e && e.message) || t('agenterror'));
    });
  });
});
