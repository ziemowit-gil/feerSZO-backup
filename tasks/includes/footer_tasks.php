</main><!-- /tsk-main -->
<footer class="tsk-footer" role="contentinfo">
  <span>Platforma NGO</span>
  <span style="display:flex;align-items:center;gap:.5rem">
    <?php
    $_o2 = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $_w2 = preg_split('/\s+/', trim($_o2));
    if (count($_w2) >= 2) {
        $ini = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8'), $_w2));
        echo '<span style="opacity:.55">' . h($ini) . '</span>';
    }
    try {
        require_once dirname(dirname(__DIR__)) . '/includes/version.php';
        $__v = app_version()['main'];
        echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;text-decoration:none;font-family:monospace">v' . h($__v) . '</a>';
    } catch (\Throwable $e) {}
    ?>
  </span>
</footer>

</body>
</html>
