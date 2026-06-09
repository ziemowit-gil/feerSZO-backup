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
  <span style="color:#9ca3af;font-size:.78rem;display:flex;align-items:center;gap:.6rem">
    <?php
    $_short_org = $_org_name_f ? mb_strtoupper(preg_replace('/[^A-ZŁŚŻŹĆĄĘÓŃ\s]/u','',mb_substr($_org_name_f,0,40,'UTF-8')), 'UTF-8') : '';
    // Skrót: akronimy słów lub pierwsze 20 znaków
    $_words = preg_split('/\s+/', trim($_org_name_f));
    $_short = count($_words) >= 2 ? implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8'), $_words)) : mb_substr($_org_name_f,0,12,'UTF-8');
    if ($_short) echo '<span style="opacity:.6">' . h($_short) . '</span>';
    ?>
    Platforma NGO
    <?php try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['main']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;opacity:.5;font-family:monospace;font-size:.7rem;text-decoration:none">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?>
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
