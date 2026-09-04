</div>
<div class="pk-footer">
  &copy; <?= date('Y') ?> <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> — Portal Kontrahenta
</div>
<script>
(function () {
  var root = document.documentElement;
  function apply(contrast, fontsize) {
    root.setAttribute('data-contrast', contrast);
    root.setAttribute('data-fontsize', fontsize);
    document.querySelectorAll('[data-pk-contrast]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-pk-contrast') === contrast);
    });
    document.querySelectorAll('[data-pk-fontsize]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-pk-fontsize') === fontsize);
    });
  }
  var contrast, fontsize;
  try {
    contrast = localStorage.getItem('pk_contrast') || 'normal';
    fontsize = localStorage.getItem('pk_fontsize') || 'md';
  } catch (e) { contrast = 'normal'; fontsize = 'md'; }
  apply(contrast, fontsize);

  document.querySelectorAll('[data-pk-contrast]').forEach(function (b) {
    b.addEventListener('click', function () {
      contrast = b.getAttribute('data-pk-contrast');
      try { localStorage.setItem('pk_contrast', contrast); } catch (e) {}
      apply(contrast, fontsize);
    });
  });
  document.querySelectorAll('[data-pk-fontsize]').forEach(function (b) {
    b.addEventListener('click', function () {
      fontsize = b.getAttribute('data-pk-fontsize');
      try { localStorage.setItem('pk_fontsize', fontsize); } catch (e) {}
      apply(contrast, fontsize);
    });
  });
})();
</script>
</body>
</html>
