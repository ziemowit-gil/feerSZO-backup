<?php
/**
 * crm/includes/footer_crm.php — zamyka shell + main otwarte przez header_crm.php.
 */
$_org_name_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>
</main><!-- /crm-content -->

<footer class="crm-footer" role="contentinfo">
  <span>
    <i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>
    <strong>CRM</strong>
    <?php if ($_org_name_f): ?> · <?= h($_org_name_f) ?><?php endif; ?>
  </span>
  <span style="color:#D1D5DB">&copy; <?= date('Y') ?> Rejestr Umów NGO &nbsp;<?php try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['hash']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;opacity:.5;font-size:.7rem;font-family:monospace;text-decoration:none">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?></span>
</footer>

</div><!-- /crm-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobile: hamburger toggle sidebara
(function() {
  var btn = document.getElementById('crmSidebarToggle');
  var sidebar = document.getElementById('crmSidebar');
  if (btn && sidebar) {
    btn.addEventListener('click', function() {
      sidebar.classList.toggle('open');
    });
    // Zamknij po kliknięciu poza sidebarem
    document.addEventListener('click', function(e) {
      if (!sidebar.contains(e.target) && !btn.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }
})();
</script>
</body>
</html>
