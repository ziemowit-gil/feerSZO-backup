  </div><!-- /content -->

  <footer class="border-top py-2 px-4 text-muted small bg-white d-flex justify-content-between align-items-center">
    <span><?= h(ORG_NAME) ?></span>
    <span class="d-flex align-items-center gap-3">
      <?php
        try {
            require_once __DIR__ . '/version.php';
            $__fv = app_version();
            echo '<a href="' . APP_URL . '/admin/version.php" class="text-muted text-decoration-none font-monospace" style="font-size:.72rem" title="' . htmlspecialchars($__fv['date']) . '">v' . htmlspecialchars($__fv['hash']) . '</a>';
        } catch (\Throwable $e) {}
      ?>
      <span>&copy; <?= date('Y') ?> Ziemowit Gil | dev@ziemowit.me</span>
    </span>
  </footer>

</div><!-- /main -->

<?php require_once __DIR__ . '/chat_widget.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
// Mobile sidebar toggle
const _tog = document.getElementById('sidebarToggle');
const _sb  = document.getElementById('sidebar');
if (_tog && _sb) _tog.addEventListener('click', () => _sb.classList.toggle('show'));

// ── Sidebar collapse — toggle .type-open on parent button ──────────────────
document.querySelectorAll('.sb-type-btn[data-bs-toggle="collapse"]').forEach(function(btn) {
  var target = document.querySelector(btn.dataset.bsTarget);
  if (!target) return;
  target.addEventListener('show.bs.collapse',  function() { btn.classList.add('type-open'); });
  target.addEventListener('hide.bs.collapse',  function() { btn.classList.remove('type-open'); });
});

// ── Automatyczny prefiks +48 dla pól .phone-48 ─────────────────────────────
(function() {
  function strip48(v) {
    var d = v.replace(/\D/g, '');
    if (d.length === 11 && d.startsWith('48')) return d.slice(2);
    return d.length === 9 ? d : v;
  }
  document.querySelectorAll('.phone-48').forEach(function(input) {
    // Usuń prefiks 48 z wartości zapisanej w bazie (wyświetlaj tylko 9 cyfr)
    input.value = strip48(input.value);
    // Przy wysyłaniu formularza doklejaj prefiks 48
    var frm = input.form || input.closest('form');
    if (frm) {
      frm.addEventListener('submit', function() {
        var d = input.value.replace(/\D/g, '');
        if (d.length === 9) input.value = '48' + d;
      });
    }
  });
})();
</script>
</body>
</html>
