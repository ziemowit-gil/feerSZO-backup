<?php /** rozliczenia/_foot.php — zamknięcie layoutu modułu Rozliczenia (jak panel dydaktyka). */ ?>
</main>
<script>
// Panel boczny na mobile: otwieranie/zamykanie + overlay + Escape.
(function () {
  var sb = document.getElementById('rzSidebar');
  var ov = document.getElementById('rzSbOverlay');
  var bt = document.getElementById('rzSbToggle');
  if (!sb || !ov || !bt) return;
  function open()  { sb.classList.add('open');  ov.hidden = false; ov.classList.add('show');  bt.setAttribute('aria-expanded', 'true'); }
  function close() { sb.classList.remove('open'); ov.classList.remove('show'); ov.hidden = true; bt.setAttribute('aria-expanded', 'false'); }
  bt.addEventListener('click', function () { sb.classList.contains('open') ? close() : open(); });
  ov.addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sb.classList.contains('open')) close(); });
})();
</script>
<?php include dirname(__DIR__) . '/karty30/ti/kursant/_layout_foot.php'; ?>
