/*
 * modules/it_portal/public/lightbox.js — powiększanie zrzutów ekranu w artykułach Portalu Usług IT (Pomoc IT).
 * Polecenie 2026-11-14: „dodaj lightbox do pomocy IT”.
 *
 * Zrzut ekranu (blok „Zrzut ekranu”: figure.itp-shot > a[data-itp-zoom]) i obraz w linku do obrazu otwierają okno
 * <dialog> z obrazem na cały ekran. Bez JavaScriptu (albo w przeglądarce bez <dialog>) link dalej otwiera obraz
 * w nowej karcie.
 * WCAG: okno modalne (fokus w oknie, Esc zamyka, fokus wraca na zrzut), nazwa okna = podpis albo tekst alternatywny,
 * przyciski ≥ 44 px z nazwami, ←/→ i przyciski „Poprzedni / Następny” między zrzutami strony, licznik ogłaszany
 * czytnikom, „Rzeczywisty rozmiar” (aria-pressed) do czytania drobnych szczegółów, animacja tylko bez
 * prefers-reduced-motion. Kliknięcie w tło zamyka okno.
 */
(function () {
  'use strict';
  if (typeof HTMLDialogElement === 'undefined') return;

  var isImageHref = function (a) {
    return /\.(png|jpe?g|gif|webp|avif)(\?|#|$)/i.test(a.getAttribute('href') || '') || /\/img\//.test(a.pathname || '');
  };
  var links = Array.prototype.filter.call(document.querySelectorAll('#tresc a[href]'), function (a) {
    if (a.hasAttribute('data-itp-zoom')) return true;
    var img = a.querySelector('img');
    return img && a.children.length === 1 && isImageHref(a);
  });
  if (!links.length) return;

  var dlg = document.createElement('dialog');
  dlg.className = 'itp-lb';
  dlg.setAttribute('aria-labelledby', 'itp-lb-title');
  dlg.innerHTML =
    '<div class="itp-lb__bar">' +
      '<p class="itp-lb__title" id="itp-lb-title"></p>' +
      '<span class="itp-lb__count" aria-live="polite"></span>' +
      '<button type="button" class="itp-lb__btn" data-act="size" aria-pressed="false">Rzeczywisty rozmiar</button>' +
      '<a class="itp-lb__btn" data-act="open" target="_blank" rel="noopener">Otwórz w nowej karcie</a>' +
      '<button type="button" class="itp-lb__btn itp-lb__close" data-act="close" aria-label="Zamknij powiększenie">' +
        '<span aria-hidden="true">×</span></button>' +
    '</div>' +
    '<div class="itp-lb__stage"><img class="itp-lb__img" alt=""></div>' +
    '<button type="button" class="itp-lb__nav itp-lb__prev" data-act="prev" aria-label="Poprzedni zrzut ekranu"><span aria-hidden="true">‹</span></button>' +
    '<button type="button" class="itp-lb__nav itp-lb__next" data-act="next" aria-label="Następny zrzut ekranu"><span aria-hidden="true">›</span></button>';
  document.body.appendChild(dlg);

  var q = function (sel) { return dlg.querySelector(sel); };
  var img = q('.itp-lb__img'), title = q('.itp-lb__title'), count = q('.itp-lb__count');
  var sizeBtn = q('[data-act="size"]'), openLink = q('[data-act="open"]'), closeBtn = q('[data-act="close"]');
  var prev = q('[data-act="prev"]'), next = q('[data-act="next"]');
  var current = 0, bodyOverflow = '';
  var many = links.length > 1;
  prev.hidden = next.hidden = count.hidden = !many;

  var labelOf = function (a) {
    var fig = a.closest('figure');
    var cap = fig && fig.querySelector('figcaption');
    var im = a.querySelector('img');
    return (cap && cap.textContent.trim()) || (im && im.getAttribute('alt')) || 'Zrzut ekranu';
  };

  var setFull = function (on) {
    dlg.classList.toggle('is-full', on);
    sizeBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
  };

  var show = function (i) {
    current = (i + links.length) % links.length;
    var a = links[current], im = a.querySelector('img');
    setFull(false);
    img.src = a.href;
    img.alt = (im && im.getAttribute('alt')) || '';
    title.textContent = labelOf(a);
    count.textContent = 'Zrzut ' + (current + 1) + ' z ' + links.length;
    openLink.href = a.href;
    if (!dlg.open) {
      bodyOverflow = document.documentElement.style.overflow;
      document.documentElement.style.overflow = 'hidden';
      dlg.showModal();
    }
    closeBtn.focus();
  };

  links.forEach(function (a, i) {
    a.setAttribute('aria-haspopup', 'dialog');
    a.setAttribute('title', 'Powiększ zrzut ekranu');
    a.addEventListener('click', function (e) {
      if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;   // nowa karta / okno — jak dotąd
      e.preventDefault();
      show(i);
    });
  });

  dlg.addEventListener('click', function (e) {
    var act = e.target.closest('[data-act]');
    if (act) {
      var what = act.getAttribute('data-act');
      if (what === 'close') dlg.close();
      else if (what === 'prev') show(current - 1);
      else if (what === 'next') show(current + 1);
      else if (what === 'size') setFull(!dlg.classList.contains('is-full'));
      return;
    }
    if (e.target === img) { setFull(!dlg.classList.contains('is-full')); return; }
    if (e.target === dlg || e.target.classList.contains('itp-lb__stage')) dlg.close();   // tło
  });

  dlg.addEventListener('keydown', function (e) {
    if (!many || e.altKey || e.ctrlKey || e.metaKey) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); show(current - 1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); show(current + 1); }
  });

  dlg.addEventListener('close', function () {
    document.documentElement.style.overflow = bodyOverflow;
    img.removeAttribute('src');
    if (links[current]) links[current].focus();
  });
})();
