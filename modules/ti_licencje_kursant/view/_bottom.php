<?php /** modules/ti_licencje_kursant/view/_bottom.php — stopka panelu „Moje licencje” (domyka _top.php) + kopiowanie / odsłanianie kluczy. */ ?>
</main>
<footer class="tw-max-w-4xl tw-mx-auto tw-px-4 tw-pb-8 tw-text-xs tw-text-m-steel"><?= h($org) ?> · Moje licencje · pytania: napisz do prowadzącego w panelu</footer>
<script>
(function () {
  var live = document.getElementById('lk-live');
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
