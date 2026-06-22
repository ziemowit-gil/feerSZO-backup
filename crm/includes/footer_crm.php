<?php
/**
 * crm/includes/footer_crm.php — zamyka shell + main otwarte przez header_crm.php.
 */
$_org_name_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>
</main><!-- /crm-content -->

<?php
try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['main'] ?? ''; }
catch (\Throwable $e) { $__v = defined('APP_VERSION') ? APP_VERSION : ''; }
?>
<footer class="crm-footer" role="contentinfo">
  <span class="crm-footer-brand">
    <span class="crm-footer-logo" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></span>
    <strong>SZU</strong>
    <span class="crm-footer-mod">Moduł CRM</span>
    <?php if ($_org_name_f): ?>
    <span class="crm-footer-sep" aria-hidden="true">·</span>
    <span class="crm-footer-org"><?= h($_org_name_f) ?></span>
    <?php endif; ?>
  </span>
  <span class="crm-footer-meta">
    <span class="crm-footer-name">System Zarządzania Umowami</span>
    <?php if ($__v): ?>
    <a href="<?= APP_URL ?>/admin/version.php" class="crm-footer-ver" title="Informacje o wersji">v<?= h($__v) ?></a>
    <?php endif; ?>
    <span class="crm-footer-sep" aria-hidden="true">·</span>
    <span>&copy; <?= date('Y') ?></span>
  </span>
</footer>

</div><!-- /crm-shell -->

<script>
(function () {
  var btn     = document.getElementById('crmSidebarToggle');
  var sidebar = document.getElementById('crmSidebar');

  // Mobile: hamburger toggle sidebara
  if (btn && sidebar) {
    btn.addEventListener('click', function () {
      var open = sidebar.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? 'Zamknij nawigację' : 'Otwórz nawigację');
    });
    document.addEventListener('click', function (e) {
      if (!sidebar.contains(e.target) && !btn.contains(e.target) && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Otwórz nawigację');
      }
    });
  }

  // aria-current="page" na aktywnym linku nawigacyjnym
  document.querySelectorAll('.crm-nav-item.active').forEach(function (a) {
    a.setAttribute('aria-current', 'page');
  });
})();
</script>
</body>
</html>
