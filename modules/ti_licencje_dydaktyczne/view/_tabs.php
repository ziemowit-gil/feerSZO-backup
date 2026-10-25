<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_tabs.php — pasek zakładek (WAI-ARIA tabs) z deep-linkiem #klucz i strzałkami.
 * Wejście: $_tabs = [klucz => [etykieta, licznik|null, kolor znaczka|'']], $_tabDefault, $_tabsLabel.
 * Panele: <div class="ld-tabpanel" id="ldp-KLUCZ" data-ld-panel="KLUCZ" role="tabpanel" aria-labelledby="ldt-KLUCZ" [hidden]>.
 * Skok z linku: data-ld-goto="klucz". Ten sam wzór co zakładki sprawy (show.php).
 */
$_tabDefault = $_tabDefault ?? array_key_first($_tabs);
?>
<style>
  .ld-tabs { display: flex; flex-wrap: wrap; gap: 0; border-bottom: 3px solid #1ba1e2; margin: 1rem 0; }
  .ld-tabs button { background: transparent; border: 0; padding: .55rem 1.1rem; font-size: 1rem; font-weight: 600; color: #3f3f3f; cursor: pointer; border-radius: 0; display: inline-flex; align-items: center; gap: .45rem; }
  .ld-tabs button:hover { background: #eef7fc; }
  .ld-tabs button:focus-visible { outline: 3px solid #0050ef; outline-offset: -3px; }
  .ld-tabs button[aria-selected="true"] { background: #1ba1e2; color: #fff; }
  .ld-tabs .ld-tn { font-size: .78rem; font-weight: 700; min-width: 1.5rem; padding: .05rem .4rem; text-align: center; background: #647687; color: #fff; }
  .ld-tabs .ld-tn.is-alert { background: #a20025; }
  .ld-tabs button[aria-selected="true"] .ld-tn { background: #fff; color: #0b5d83; }
  .ld-tabpanel[hidden] { display: none; }
</style>
<div class="ld-tabs" role="tablist" aria-label="<?= h($_tabsLabel ?? 'Sekcje') ?>">
  <?php foreach ($_tabs as $k => [$lbl, $n, $alert]): ?>
    <button type="button" role="tab" id="ldt-<?= h($k) ?>" data-ld-tab="<?= h($k) ?>" aria-controls="ldp-<?= h($k) ?>" aria-selected="<?= $k === $_tabDefault ? 'true' : 'false' ?>"><?= h($lbl) ?><?php if ($n !== null): ?><span class="ld-tn<?= $alert && $n > 0 ? ' is-alert' : '' ?>"><?= (int)$n ?><span class="visually-hidden"> pozycji</span></span><?php endif; ?></button>
  <?php endforeach; ?>
</div>
<script>
(function () {
  var tabs = document.querySelectorAll('[data-ld-tab]'), panels = document.querySelectorAll('[data-ld-panel]'), def = <?= json_encode($_tabDefault) ?>;
  function show(name, push) {
    if (!document.querySelector('[data-ld-panel="' + name + '"]')) name = def;
    tabs.forEach(function (b) { var on = b.getAttribute('data-ld-tab') === name; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-ld-panel') !== name; });
    if (push && history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (b, i) {
    b.addEventListener('click', function () { show(b.getAttribute('data-ld-tab'), true); });
    b.addEventListener('keydown', function (e) {   // strzałki przełączają zakładki (wzorzec WAI-ARIA tabs)
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; n.focus(); show(n.getAttribute('data-ld-tab'), true); e.preventDefault();
    });
  });
  document.querySelectorAll('[data-ld-goto]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-ld-goto'), true); document.getElementById('ldt-' + a.getAttribute('data-ld-goto')).scrollIntoView({ behavior: 'smooth', block: 'start' }); }); });
  show((location.hash || '').replace('#', ''), false);
  window.addEventListener('hashchange', function () { show((location.hash || '').replace('#', ''), false); });
})();
</script>
