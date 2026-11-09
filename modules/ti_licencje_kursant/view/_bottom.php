<?php
if (!isset($org)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu
/** modules/ti_licencje_kursant/view/_bottom.php — stopka panelu licencji (domyka _top.php): pasek dostępności, kopiowanie i odsłanianie kluczy. */
?>
</main>
</div>
<div class="pk-footer"><?= h($org) ?> · Panel licencji · pytania: napisz do prowadzącego albo do biura</div>
<div style="position:absolute;left:-9999px" id="lk-live" aria-live="polite"></div>
<script>
(function () {
  var root = document.documentElement, live = document.getElementById('lk-live');
  function mark() {
    document.querySelectorAll('[data-lp-contrast]').forEach(function (b) { var on = (root.dataset.contrast || 'normal') === b.dataset.lpContrast; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    document.querySelectorAll('[data-lp-fontsize]').forEach(function (b) { var on = (root.dataset.fontsize || 'md') === b.dataset.lpFontsize; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  }
  document.querySelectorAll('[data-lp-contrast]').forEach(function (b) { b.addEventListener('click', function () { root.dataset.contrast = b.dataset.lpContrast; try { localStorage.setItem('lp_contrast', b.dataset.lpContrast); } catch (e) {} mark(); }); });
  document.querySelectorAll('[data-lp-fontsize]').forEach(function (b) { b.addEventListener('click', function () { root.dataset.fontsize = b.dataset.lpFontsize; try { localStorage.setItem('lp_fontsize', b.dataset.lpFontsize); } catch (e) {} mark(); }); });
  mark();
  document.querySelectorAll('.lk-copy').forEach(function (b) {
    b.addEventListener('click', function () { navigator.clipboard.writeText(b.getAttribute('data-copy')); if (live) live.textContent = 'Skopiowano.'; b.firstChild.textContent = 'Skopiowano'; });
  });
  document.querySelectorAll('.lk-reveal').forEach(function (b) {
    b.addEventListener('click', function () {
      var s = b.parentElement.querySelector('.lk-secret'), show = s.dataset.shown !== '1';
      s.textContent = show ? s.getAttribute('data-secret') : '••••••••'; s.dataset.shown = show ? '1' : '0';
      b.firstChild.textContent = show ? 'Ukryj' : 'Pokaż'; b.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });
})();
</script>
</body>
</html>
