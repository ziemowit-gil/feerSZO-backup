/**
 * feer_theme — zwijana lewa szyna (#layout-menu): przycisk „Zwiń menu” na dole, po zwinięciu
 * same ikony (nazwa w podpowiedzi i dla czytnika ekranu). Stan per przeglądarka (localStorage).
 */
(function () {
  var KEY = 'feer_menu_collapsed';
  var read = function () { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } };
  var write = function (v) { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) {} };
  // Klasa od razu, żeby szyna nie mignęła szeroka przed init
  if (read()) document.documentElement.classList.add('feer-menu-collapsed');

  window.rcmail && rcmail.addEventListener('init', function () {
    var menu = $('#taskmenu');
    var bottom = menu.find('.special-buttons');
    if (!menu.length || !bottom.length) return;
    var pl = String(rcmail.env.locale || '').indexOf('pl') === 0;
    var txt = { collapse: pl ? 'Zwiń menu' : 'Collapse menu', expand: pl ? 'Rozwiń menu' : 'Expand menu' };

    // Podpowiedź z nazwą przy każdym przycisku szyny (widoczna, gdy etykiety są schowane)
    menu.find('a').each(function () {
      var a = $(this), name = $.trim(a.find('span.inner').text());
      if (name && !a.attr('title')) a.attr('title', name);
    });

    var btn = $('<a href="#" role="button" class="feer-menu-toggle"><span class="inner"></span></a>');
    bottom.prepend(btn);
    var sync = function () {
      var on = $('html').hasClass('feer-menu-collapsed');
      btn.attr({ 'aria-pressed': on ? 'true' : 'false', title: on ? txt.expand : txt.collapse })
        .find('.inner').text(on ? txt.expand : txt.collapse);
    };
    sync();
    btn.on('click', function (e) {
      e.preventDefault();
      var on = !$('html').hasClass('feer-menu-collapsed');
      $('html').toggleClass('feer-menu-collapsed', on);
      write(on);
      sync();
      $(window).trigger('resize'); // Elastic przelicza szerokości list i edytora
    });
  });
})();
