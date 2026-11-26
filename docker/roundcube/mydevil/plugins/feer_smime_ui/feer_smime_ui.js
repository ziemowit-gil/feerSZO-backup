/**
 * feer_smime_ui — kłódka S/MIME: kliknięcie pokazuje/ukrywa szczegóły mimeshield (ramka nad treścią).
 */
window.rcmail && rcmail.addEventListener('init', function () {
  var lock = $('.feer-smime-lock');
  if (!lock.length) return;
  $('html').addClass('feer-smime-has-lock');

  var details = $('.mimeshield-status');
  if (!details.length) {
    lock.attr('aria-disabled', 'true');
    return;
  }
  var t = function (k) { return rcmail.gettext('feer_smime_ui.' + k); };
  var base = lock.attr('data-label');
  var sync = function (open) {
    lock.attr('aria-expanded', open ? 'true' : 'false')
      .attr('title', base + ' ' + t(open ? 'hide_details' : 'show_details'));
  };
  sync(false);

  lock.on('click', function () {
    var open = !$('html').hasClass('feer-smime-open');
    $('html').toggleClass('feer-smime-open', open);
    sync(open);
    if (open && details[0].scrollIntoView) {
      details[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  });
});

/**
 * Okno pisania: opcje S/MIME (mimeshield, feer_qsign) w wyskakującym oknie „Podpis cyfrowy”.
 * Fieldset #mimeshield-compose czeka w ukrytym #feer-smime-holder w formularzu; okno go
 * pożycza i oddaje przy zamknięciu, więc pola zawsze są w formularzu przy wysyłce.
 */
window.rcmail && rcmail.addEventListener('init', function () {
  if (rcmail.env.action !== 'compose') return;
  var fs = $('#mimeshield-compose');
  var btn = $('a.feer-smime-btn');
  if (!fs.length) {
    btn.remove();
    return;
  }
  var t = function (k) { return rcmail.gettext('feer_smime_ui.' + k); };
  var holder = $('<div id="feer-smime-holder" hidden>');
  fs.before(holder);
  holder.append(fs);
  $('html').addClass('feer-smime-popup');

  var dialog = null;

  function state() {
    var parts = [];
    if ($('#feer-qsign').prop('checked')) parts.push(t('state_qsign'));
    if ($('#mimeshield-sign').prop('checked')) parts.push(t('state_sign'));
    if ($('#mimeshield-encrypt').prop('checked')) parts.push(t('state_encrypt'));
    return parts;
  }

  function update() {
    var parts = state(), on = parts.length > 0;
    var desc = t('security_title') + ': ' + (on ? parts.join(', ') : t('state_off'));
    btn.toggleClass('feer-smime-on', on).attr({ title: desc, 'aria-pressed': on ? 'true' : 'false' });
    btn.find('.inner').text(t('security'));
    if (!btn.find('.feer-smime-state').length) btn.append($('<span class="voice feer-smime-state">'));
    btn.find('.feer-smime-state').text(desc);
  }

  function close() {
    var d = dialog;
    dialog = null;
    if (d) d.dialog('close');
  }

  rcmail.register_command('plugin.feer_smime_ui-security', function () {
    if (dialog) return;
    dialog = rcmail.show_popup_dialog(fs, t('security_title'), [{
      text: t('done'), 'class': 'mainaction',
      click: function () { close(); }
    }], {
      width: 480,
      close: function () {
        holder.append(fs);   // najpierw oddaj pola formularzowi, dopiero potem usuń okno
        $(this).remove();
        dialog = null;
        update();
        btn.focus();
      }
    });
  }, true);

  $(document).on('change', '#mimeshield-sign, #mimeshield-encrypt, #feer-qsign', update);
  // Przed wysyłką okno musi być zamknięte (pola wracają do formularza).
  rcmail.addEventListener('beforesend', function () { close(); });
  // feer_qsign i mimeshield mogą jeszcze dopisać wiersze — stan po ich init.
  setTimeout(update, 0);
});
