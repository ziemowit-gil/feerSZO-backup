/**
 * feer_cloud_save — klient: połączenie z chmurą FEER i „Zapisz w chmurze”.
 *
 * Połączenie (Nextcloud Login Flow v2) zawsze z okna głównego (top), bo podgląd
 * wiadomości jest w ramce. Okno logowania otwieramy synchronicznie w kliknięciu
 * (inaczej blokada wyskakujących okien), adres dostaje po odpowiedzi serwera.
 */
(function () {
  if (!window.rcmail) return;

  var top = (window.parent && window.parent !== window && window.parent.rcmail) ? window.parent : window;
  var isTop = top === window;

  // ── Okno główne: dialog + przepływ logowania ─────────────────────────────
  if (isTop) {
    var popup = null, timer = null, started = 0;

    function t(k) { return rcmail.gettext('feer_cloud_save.' + k); }

    function stopPoll() { if (timer) { clearInterval(timer); timer = null; } }

    function startFlow() {
      // okno w tym samym kliknięciu, adres później
      popup = window.open('', 'feer_cloud_login', 'popup,width=600,height=780');
      if (popup) {
        try { popup.document.title = t('connecttitle'); popup.document.body.innerHTML = '<p style="font:16px system-ui;padding:2rem">' + t('opening') + '</p>'; } catch (e) {}
      }
      rcmail.http_post('plugin.feer_cloud_connect', {}, rcmail.set_busy(true, 'loading'));
    }

    rcmail.addEventListener('plugin.feer_cloud_flow', function (p) {
      if (!p || p.status !== 'ok') {
        if (popup) popup.close();
        rcmail.display_message(t('connecterror'), 'error', 8000);
        return;
      }
      if (popup && !popup.closed) {
        popup.location.href = p.url;
      } else {
        // przeglądarka zablokowała okno: zwykły odnośnik
        rcmail.simple_dialog(
          $('<p>').append(document.createTextNode(t('popupblocked') + ' '),
            $('<a>').attr({ href: p.url, target: '_blank', rel: 'noopener' }).text(t('openlogin'))),
          t('connecttitle'), null, { cancel_button: 'close' });
      }
      rcmail.display_message(t('waiting'), 'loading', 20000);
      started = Date.now();
      stopPoll();
      timer = setInterval(function () {
        if (Date.now() - started > 19 * 60 * 1000) { stopPoll(); return; }
        rcmail.http_post('plugin.feer_cloud_poll', {}, false);
      }, 2500);
    });

    rcmail.addEventListener('plugin.feer_cloud_flow_done', function (p) {
      if (!p || p.status === 'wait') return;
      stopPoll();
      if (p.status === 'ok') {
        if (popup && !popup.closed) popup.close();
        rcmail.env.feer_cloud_connected = true;
        rcmail.display_message(t('connected'), 'confirmation', 8000);
      } else {
        rcmail.display_message(t(p.status === 'expired' ? 'connectexpired' : 'connecterror'), 'error', 8000);
      }
    });

    /** Okienko „Połącz pocztę z chmurą FEER”; reason = 'prompt' (po logowaniu) albo 'save'. */
    rcmail.feer_cloud_dialog = function (reason) {
      var body = $('<div class="feer-cloud-dialog">')
        .append($('<p>').text(t(reason === 'save' ? 'needconnect' : 'promptlead')))
        .append($('<ul>')
          .append($('<li>').text(t('benefit1')))
          .append($('<li>').text(t('benefit2')))
          .append($('<li>').text(t('benefit3'))))
        .append($('<p class="hint">').text(t('promptlogto')));

      var buttons = [{
        text: t('connectbutton'), 'class': 'mainaction',
        click: function () { startFlow(); $(this).dialog('close'); }
      }];
      if (reason !== 'save') {
        buttons.push({ text: t('later'), click: function () {
          rcmail.http_post('plugin.feer_cloud_prompt', { _answer: 'later' }, false); $(this).dialog('close'); } });
        buttons.push({ text: t('never'), click: function () {
          rcmail.http_post('plugin.feer_cloud_prompt', { _answer: 'never' }, false); $(this).dialog('close'); } });
      } else {
        buttons.push({ text: rcmail.gettext('cancel'), click: function () { $(this).dialog('close'); } });
      }
      rcmail.show_popup_dialog(body, t('connecttitle'), buttons, { width: 480 });
    };

    /** Zachęta do weryfikacji dwuetapowej (2FA dobrowolne). */
    rcmail.feer_2fa_dialog = function () {
      var body = $('<div class="feer-cloud-dialog">')
        .append($('<p>').text(t('tfalead')))
        .append($('<ul>')
          .append($('<li>').text(t('tfa1')))
          .append($('<li>').text(t('tfa2')))
          .append($('<li>').text(t('tfa3'))))
        .append($('<p class="hint">').text(t('tfahint')));
      rcmail.show_popup_dialog(body, t('tfatitle'), [
        { text: t('tfanow'), 'class': 'mainaction', click: function () {
            $(this).dialog('close');
            location.href = rcmail.url('settings/plugin.twofactor_gauthenticator'); } },
        { text: t('later'), click: function () {
            rcmail.http_post('plugin.feer_cloud_prompt', { _what: '2fa', _answer: 'later' }, false); $(this).dialog('close'); } }
      ], { width: 500 });
    };

    rcmail.addEventListener('init', function () {
      if (rcmail.env.feer_cloud_prompt) {
        setTimeout(function () { rcmail.feer_cloud_dialog('prompt'); }, 1200);
      } else if (rcmail.env.feer_2fa_prompt) {
        setTimeout(function () { rcmail.feer_2fa_dialog(); }, 1200);
      }
    });
  }

  // ── Okno pisania: „Z chmury” ──────────────────────────────────────────────
  rcmail.addEventListener('init', function () {
    if (rcmail.env.action !== 'compose') return;
    function t(k) { return rcmail.gettext('feer_cloud_save.' + k); }
    var dlg = null, cur = '';

    function size(n) {
      if (!n) return '';
      var u = ['B', 'kB', 'MB', 'GB'], i = 0;
      while (n >= 1024 && i < 3) { n /= 1024; i++; }
      return (i ? n.toFixed(1).replace('.', ',') : n) + ' ' + u[i];
    }

    function load(path) {
      cur = path || '';
      if (dlg) dlg.find('.feer-cloud-list').attr('aria-busy', 'true');
      rcmail.http_post('plugin.feer_cloud_list', { _path: cur }, rcmail.set_busy(true, 'loading'));
    }

    function open() {
      var box = $('<div class="feer-cloud-picker">')
        .append($('<nav class="feer-cloud-crumbs" aria-label="' + t('folder') + '">'))
        .append($('<ul class="feer-cloud-list listing" role="list" aria-live="polite">'));
      dlg = rcmail.show_popup_dialog(box, t('fromcloudtitle'),
        [{ text: rcmail.gettext('close'), click: function () { $(this).dialog('close'); } }],
        { width: 620, height: 520, close: function () { dlg = null; } });
      load(cur);
    }

    rcmail.register_command('plugin.feer_cloud_attach', open, true);

    rcmail.addEventListener('plugin.feer_cloud_list', function (p) {
      if (!dlg) return;
      var list = dlg.find('.feer-cloud-list').empty().attr('aria-busy', 'false');
      var crumbs = dlg.find('.feer-cloud-crumbs').empty();
      if (p.error) {
        list.append($('<li class="feer-cloud-msg">').text(t(p.error === 'auth' ? 'autherror' : 'listerror')));
        return;
      }
      var parts = p.path ? p.path.split('/') : [], acc = [];
      crumbs.append($('<a href="#">').text(t('cloudroot')).on('click', function (e) { e.preventDefault(); load(''); }));
      parts.forEach(function (x) {
        acc.push(x); var to = acc.join('/');
        crumbs.append(document.createTextNode(' / '))
          .append($('<a href="#">').text(x).on('click', function (e) { e.preventDefault(); load(to); }));
      });
      if (!p.items || !p.items.length) {
        list.append($('<li class="feer-cloud-msg">').text(t('emptyfolder')));
      }
      (p.items || []).forEach(function (it) {
        var full = (p.path ? p.path + '/' : '') + it.name;
        var a = $('<a href="#">').addClass(it.dir ? 'feer-dir' : 'feer-file')
          .append($('<span class="feer-name">').text(it.name))
          .append($('<span class="feer-size">').text(it.dir ? '' : size(it.size)))
          .attr('title', it.dir ? t('openfolder') : t('attachfile'))
          .on('click', function (e) {
            e.preventDefault();
            if (it.dir) { load(full); return; }
            if ($(this).hasClass('feer-done')) return;
            $(this).addClass('feer-done').attr('aria-disabled', 'true');
            var uploadid = 'feercloud' + new Date().getTime() + Math.floor(Math.random() * 1000);
            rcmail.add2attachment_list(uploadid, { name: it.name, html: rcmail.gettext('uploading'), classname: 'uploading', complete: false });
            rcmail.http_post('plugin.feer_cloud_attach', { _id: rcmail.env.compose_id, _path: full, _uploadid: uploadid }, false);
          });
        list.append($('<li>').append(a));
      });
    });

    rcmail.addEventListener('plugin.feer_cloud_attached', function (p) {
      rcmail.display_message(t('attached'), 'confirmation', 3000);
    });

    rcmail.addEventListener('plugin.feer_cloud_save_connect', function () {
      if (dlg) dlg.dialog('close');
      if (rcmail.feer_cloud_dialog) rcmail.feer_cloud_dialog('save');
    });
  });

  // ── Podgląd wiadomości (ramka albo okno): menu załącznika ────────────────
  rcmail.addEventListener('init', function () {
    if (rcmail.env.action !== 'show' && rcmail.env.action !== 'preview') return;

    var current = null;
    var count = Object.keys(rcmail.env.attachments || {}).length;

    rcmail.addEventListener('beforemenu-open', function (p) {
      if (p && p.menu === 'attachmentmenu') {
        current = p.id;
        rcmail.enable_command('plugin.feer_cloud_save', true);
        rcmail.enable_command('plugin.feer_cloud_save_all', count > 1);
        $('#attachmenucloudall').closest('li').toggle(count > 1);
      }
    });

    function save(all) {
      if (!rcmail.env.uid || (!all && !current)) return;
      var lock = rcmail.set_busy(true, 'feer_cloud_save.saving');
      rcmail.http_post('plugin.feer_cloud_save', {
        _uid: rcmail.env.uid,
        _mbox: rcmail.env.mailbox,
        _part: all ? '' : current,
        _all: all ? 1 : 0
      }, lock);
    }

    rcmail.register_command('plugin.feer_cloud_save', function () { save(false); });
    rcmail.register_command('plugin.feer_cloud_save_all', function () { save(true); });

    // Brak połączenia: od razu okienko połączenia w oknie głównym
    rcmail.addEventListener('plugin.feer_cloud_save_connect', function () {
      if (top.rcmail.feer_cloud_dialog) top.rcmail.feer_cloud_dialog('save');
    });
  });
})();
