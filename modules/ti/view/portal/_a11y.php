<?php
/**
 * modules/ti/view/portal/_a11y.php — pasek dostępności (portal TI, konto kandydata, formularz zapisów):
 * wysoki kontrast, rozmiar tekstu (A / A+ / A++), większe odstępy, podkreślenie linków, deklaracja dostępności.
 * Ustawienia w localStorage „ppA11y” (przeglądarka użytkownika); klasy na <html> nakłada skrypt z _a11yHead.php
 * jeszcze przed wyświetleniem strony. Przyciski z aria-pressed, obsługa klawiaturą, komunikat dla czytnika ekranu.
 */
?>
<div class="pp-a11y" role="region" aria-label="Ułatwienia dostępu">
  <button type="button" data-pp-a11y="contrast" aria-pressed="false"><i class="bi bi-circle-half" aria-hidden="true"></i> Kontrast</button>
  <span class="pp-a11y-sep" aria-hidden="true"></span>
  <span class="pp-a11y-lbl" id="pp-size-lbl">Tekst:</span>
  <span role="group" aria-labelledby="pp-size-lbl" class="pp-a11y-sizes">
    <button type="button" data-pp-size="0" aria-pressed="true" aria-label="Tekst normalny">A</button>
    <button type="button" data-pp-size="1" aria-pressed="false" aria-label="Tekst większy" style="font-size:1.15em">A</button>
    <button type="button" data-pp-size="2" aria-pressed="false" aria-label="Tekst największy" style="font-size:1.3em">A</button>
  </span>
  <span class="pp-a11y-sep" aria-hidden="true"></span>
  <button type="button" data-pp-a11y="spacing" aria-pressed="false"><i class="bi bi-text-paragraph" aria-hidden="true"></i> Odstępy</button>
  <button type="button" data-pp-a11y="links" aria-pressed="false"><i class="bi bi-link-45deg" aria-hidden="true"></i> Podkreśl linki</button>
  <a href="https://feer.org.pl/deklaracja-dostepnosci/">Deklaracja dostępności</a>
  <span class="pp-sr" aria-live="polite" id="pp-a11y-live"></span>
</div>
<script>
(function () {
  var KEY = 'ppA11y', root = document.documentElement, live = document.getElementById('pp-a11y-live');
  function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
  function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }
  function apply(s) {
    root.classList.toggle('pp-contrast', !!s.contrast);
    root.classList.toggle('pp-spacing', !!s.spacing);
    root.classList.toggle('pp-links', !!s.links);
    root.setAttribute('data-pp-size', String(s.size || 0));
    document.querySelectorAll('[data-pp-a11y]').forEach(function (b) { b.setAttribute('aria-pressed', s[b.getAttribute('data-pp-a11y')] ? 'true' : 'false'); });
    document.querySelectorAll('[data-pp-size]').forEach(function (b) { b.setAttribute('aria-pressed', String(s.size || 0) === b.getAttribute('data-pp-size') ? 'true' : 'false'); });
  }
  var s = load(); apply(s);
  var names = { contrast: 'Wysoki kontrast', spacing: 'Większe odstępy', links: 'Podkreślenie linków' };
  document.querySelectorAll('[data-pp-a11y]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-pp-a11y'); s[k] = !s[k]; save(s); apply(s);
      if (live) live.textContent = names[k] + (s[k] ? ' włączone' : ' wyłączone');
    });
  });
  document.querySelectorAll('[data-pp-size]').forEach(function (b) {
    b.addEventListener('click', function () {
      s.size = +b.getAttribute('data-pp-size'); save(s); apply(s);
      if (live) live.textContent = ['Tekst normalny', 'Tekst większy', 'Tekst największy'][s.size];
    });
  });
})();
</script>
