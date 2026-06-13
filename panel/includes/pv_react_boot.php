<?php
/**
 * panel/includes/pv_react_boot.php — Wspólny loader React dla wysp panelu.
 *
 * Ładuje React 18 + ReactDOM + htm z CDN (bez kroku budowania), asynchronicznie
 * i NIEZALEŻNIE od kolejności w DOM. Wyspy rejestrują się przez:
 *
 *   window.pvReact(function (React, ReactDOM, html) { ... mount ... });
 *
 * Callbacki czekają w kolejce i uruchamiają się dopiero, gdy React jest gotowy
 * — więc wyspa renderowana PRZED tym, jak załaduje się CDN, też zadziała.
 * Gdy CDN/JS zawiedzie, kolejka nie odpala i zostają fallbacki serwerowe.
 *
 * Emitowany raz na żądanie (require_once + guard).
 */
if (!empty($GLOBALS['__pv_react_booted'])) return;
$GLOBALS['__pv_react_booted'] = true;
?>
<script>
(function () {
  if (window.pvReact) return;
  var queue = [];
  function ready() { return window.React && window.ReactDOM && window.htm; }
  function flush() {
    if (!ready()) return;
    var html = window.htm.bind(window.React.createElement);
    while (queue.length) {
      var cb = queue.shift();
      try { cb(window.React, window.ReactDOM, html); } catch (e) { /* wyspa pada cicho — fallback zostaje */ }
    }
  }
  window.pvReact = function (cb) { queue.push(cb); flush(); };

  if (ready()) { return; } // React już obecny (np. inna powłoka)

  function load(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = src; s.crossOrigin = 'anonymous';
      s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }
  load('https://cdn.jsdelivr.net/npm/react@18.3.1/umd/react.production.min.js')
    .then(function () { return load('https://cdn.jsdelivr.net/npm/react-dom@18.3.1/umd/react-dom.production.min.js'); })
    .then(function () { return load('https://cdn.jsdelivr.net/npm/htm@3.1.1/dist/htm.umd.js'); })
    .then(flush)
    .catch(function () { /* CDN niedostępne → fallbacki serwerowe zostają */ });
})();
</script>
