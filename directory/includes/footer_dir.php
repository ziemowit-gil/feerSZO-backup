<?php
$_org_name_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>
</main><!-- /dirMain -->

<footer class="dir-footer" role="contentinfo">
  <span>
    <i class="bi bi-person-lines-fill me-1" aria-hidden="true" style="color:var(--dir-primary)"></i>
    <strong>Katalog współpracowników</strong>
    <?php if ($_org_name_f): ?> · <?= h($_org_name_f) ?><?php endif; ?>
  </span>
  <span>&copy; <?= date('Y') ?> Rejestr Umów NGO &nbsp;<?php try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['hash']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;opacity:.45;font-size:.7rem;font-family:monospace;text-decoration:none">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?></span>
</footer>

</div><!-- /dir-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  'use strict';
  var btn     = document.getElementById('dirSidebarToggle');
  var sidebar = document.getElementById('dirSidebar');
  if (!btn || !sidebar) return;

  btn.addEventListener('click', function () {
    var open = sidebar.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Zamknij menu nawigacyjne' : 'Otwórz menu nawigacyjne');
  });
  document.addEventListener('click', function (e) {
    if (!sidebar.contains(e.target) && !btn.contains(e.target) && sidebar.classList.contains('open')) {
      sidebar.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-label', 'Otwórz menu nawigacyjne');
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebar.classList.contains('open')) {
      sidebar.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      btn.focus();
    }
  });
})();
</script>
</body>
</html>
