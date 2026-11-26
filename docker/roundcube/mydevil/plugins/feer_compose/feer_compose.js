/**
 * feer_compose — przestawia okno pisania (Elastic): „Wyślij” obok pól nagłówka, S/MIME pod tematem.
 */
window.rcmail && rcmail.addEventListener('init', function () {
  if (rcmail.env.action !== 'compose') return;
  var t = function (k) { return rcmail.gettext('feer_compose.' + k); };
  var headers = $('#compose-headers');
  var fields = headers.children('.compose-headers');
  var send = $('#layout-content .formbuttons .send').first();
  if (!headers.length || !fields.length) return;

  $('body').addClass('feer-compose');

  // 1) „Wyślij” na górze po lewej od pól (jak w Outlooku)
  if (send.length) {
    var top = $('<div class="feer-compose-top">');
    fields.before(top);
    top.append($('<div class="feer-compose-send">').append(send)).append(fields);
  }

  // 2) S/MIME (mimeshield) w pasku pod tematem
  var ms = $('#mimeshield-compose');
  if (ms.length) {
    var bar = $('<div class="feer-smime-bar" role="group">').attr('aria-label', t('smime'));
    headers.append(bar);
    bar.append(ms);
    ms.find('legend, h3').first().addClass('feer-smime-legend');

    // Brak certyfikatu: jasny komunikat i link do „Certyfikat FEER”
    var hint = $('<div class="feer-smime-nocert" role="status" hidden>')
      .append($('<span>').text(t('nocert') + ' '));
    if (rcmail.env.feer_cert_url) {
      hint.append($('<a>').attr('href', rcmail.env.feer_cert_url).text(t('getcert')));
    }
    bar.append(hint);
    var check = function () {
      var st = $('#mimeshield-status').text() + ' ' + ms.find('.mimeshield-warning, .warning').text();
      var sign = $('#mimeshield-sign');
      var noCert = /brak certyfikatu|no certificate|no usable/i.test(st) || (sign.length && sign.prop('disabled') && !sign.prop('checked'));
      hint.prop('hidden', !noCert);
      bar.toggleClass('feer-smime-missing', !!noCert);
    };
    check();
    var target = document.getElementById('mimeshield-compose');
    if (target && window.MutationObserver) {
      new MutationObserver(check).observe(target, { subtree: true, childList: true, characterData: true, attributes: true });
    }
    $('#_from').on('change', function () { setTimeout(check, 300); });
  }
});
