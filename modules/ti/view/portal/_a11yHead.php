<?php
/**
 * modules/ti/view/portal/_a11yHead.php — w <head>: wczesne nałożenie ustawień dostępności (bez mignięcia)
 * i style trybów: wysoki kontrast, rozmiar tekstu (skalowanie rem), odstępy, podkreślenie linków, mniej animacji.
 */
?>
<script>
(function () { try { var s = JSON.parse(localStorage.getItem('ppA11y') || '{}') || {}, r = document.documentElement;
  if (s.contrast) r.classList.add('pp-contrast'); if (s.spacing) r.classList.add('pp-spacing'); if (s.links) r.classList.add('pp-links');
  r.setAttribute('data-pp-size', String(s.size || 0)); } catch (e) {} })();
</script>
<style>
  /* Rozmiar tekstu: rem skaluje też Bootstrap i Tailwind */
  html[data-pp-size="1"] { font-size: 118.75%; }
  html[data-pp-size="2"] { font-size: 137.5%; }
  .pp-sr { position: absolute !important; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .pp-a11y { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .25rem .5rem; font-size: .85rem; padding: .5rem 0 0; }
  .pp-a11y button { background: transparent; border: 1px solid transparent; border-radius: .5rem; padding: .25rem .55rem; color: #3d4654; font: inherit; cursor: pointer; line-height: 1.2; min-height: 32px; }
  .pp-a11y button:hover { background: #fff; border-color: #d8e2f3; }
  .pp-a11y button[aria-pressed="true"] { background: #1d68f2; color: #fff; border-color: #1d68f2; }
  .pp-a11y a { color: #3d4654; padding: .25rem .4rem; }
  .pp-a11y-sizes { display: inline-flex; gap: .15rem; align-items: baseline; }
  .pp-a11y-lbl { color: #3d4654; }
  .pp-a11y-sep { width: 1px; height: 1.1rem; background: #c9d4e8; }
  /* Fokus z klawiatury — gruby i widoczny na każdym tle */
  :focus-visible { outline: 3px solid #1d68f2 !important; outline-offset: 2px !important; box-shadow: 0 0 0 6px #fff !important; }
  /* Odstępy (WCAG 1.4.12) */
  html.pp-spacing body { line-height: 1.9 !important; letter-spacing: .06em !important; word-spacing: .16em !important; }
  html.pp-spacing p, html.pp-spacing li, html.pp-spacing label { margin-bottom: 1em; }
  /* Podkreślenie linków */
  html.pp-links a { text-decoration: underline !important; text-underline-offset: .18em; }
  /* Wysoki kontrast: żółte / białe na czarnym */
  html.pp-contrast, html.pp-contrast body { background: #000 !important; color: #fff !important; }
  html.pp-contrast *:not(img):not(svg):not(path) { background-color: #000 !important; color: #fff !important; border-color: #fff !important; box-shadow: none !important; text-shadow: none !important; }
  html.pp-contrast a, html.pp-contrast a * { color: #ffeb3b !important; text-decoration: underline !important; }
  html.pp-contrast button, html.pp-contrast .pp-submit, html.pp-contrast .pp-btn-acc, html.pp-contrast .pp-btn-log, html.pp-contrast [type=submit],
  html.pp-contrast .btn, html.pp-contrast .k-btn, html.pp-contrast .zp-btn-primary, html.pp-contrast .zp-btn-ghost { border: 2px solid #ffeb3b !important; color: #ffeb3b !important; }
  html.pp-contrast [aria-pressed="true"], html.pp-contrast [aria-current="page"] { background-color: #ffeb3b !important; color: #000 !important; }
  html.pp-contrast [aria-pressed="true"] *, html.pp-contrast [aria-current="page"] * { color: #000 !important; background-color: transparent !important; }
  html.pp-contrast input, html.pp-contrast textarea, html.pp-contrast select { border: 2px solid #fff !important; }
  html.pp-contrast :focus-visible { outline-color: #ffeb3b !important; box-shadow: 0 0 0 6px #000 !important; }
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; scroll-behavior: auto !important; } }
</style>
