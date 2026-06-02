</main><!-- /tsk-main -->
<footer style="text-align:right;padding:.4rem 1.5rem;font-size:.7rem;color:#94a3b8;background:#f8fafc;border-top:1px solid #e2e8f0">
  <?php try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['hash']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;text-decoration:none;font-family:monospace">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
