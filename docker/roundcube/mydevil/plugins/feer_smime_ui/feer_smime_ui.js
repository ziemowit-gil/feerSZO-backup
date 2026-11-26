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
