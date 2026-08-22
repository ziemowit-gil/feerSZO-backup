<?php
$_pv_org_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>
</main>
<footer class="pv-footer" role="contentinfo">
  <div class="pv-footer-inner">
  <span><i class="bi bi-person-circle me-1" style="color:var(--vol-color)" aria-hidden="true"></i>Panel wolontariusza<?= $_pv_org_f ? ' · '.h($_pv_org_f) : '' ?></span>
  <span style="font-size:.78rem;display:flex;align-items:center;gap:.5rem">
    <?php $_w=preg_split('/\s+/',trim($_pv_org_f??'')); echo count($_w)>=2?'<span style="opacity:.5">'.h(implode('',array_map(fn($w)=>mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8'),$_w))).'</span>':''; ?>
    Platforma NGO
    <?php try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['main']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;opacity:.4;font-family:monospace;font-size:.7rem;text-decoration:none">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?>
  </span>
  </div>
</footer>
</body>
</html>
