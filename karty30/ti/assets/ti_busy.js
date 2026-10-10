/*
 * Okienko ładowania dla całego panelu TI.
 *  - każda wysyłka formularza pokazuje okienko (tekst z data-busy, domyślnie „Zapisuję…”),
 *  - pomijane: formularze z atrybutem target (np. pobieranie w nowej karcie) i data-nobusy,
 *  - nie pokazuje się, gdy inline onsubmit anulował wysyłkę (np. odrzucone potwierdzenie),
 *  - ręcznie: window.tiBusy.show('tekst') / window.tiBusy.hide().
 * Zabezpieczenie: okienko samo znika po 30 s i przy powrocie do strony.
 */
(function () {
  var box = null, timer = null;

  function show(text) {
    if (box) return;
    box = document.createElement('div');
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    box.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;'
      + 'align-items:center;justify-content:center;z-index:2000;';
    box.innerHTML = '<div style="background:#fff;border-radius:12px;padding:1.5rem 2rem;text-align:center;'
      + 'box-shadow:0 10px 30px rgba(0,0,0,.2);max-width:90vw">'
      + '<div class="spinner-border text-primary" aria-hidden="true"></div>'
      + '<div class="mt-3 fw-semibold" style="font-size:.95rem"></div></div>';
    box.querySelector('.fw-semibold').textContent = text || 'Proszę czekać…';
    document.body.appendChild(box);
    timer = setTimeout(hide, 30000);
  }

  function hide() {
    if (timer) { clearTimeout(timer); timer = null; }
    if (box) { box.remove(); box = null; }
  }

  window.tiBusy = { show: show, hide: hide };

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || f.getAttribute('target') || f.hasAttribute('data-nobusy')) return;
    show(f.getAttribute('data-busy') || 'Zapisuję…');
  });

  window.addEventListener('pageshow', hide);
})();
