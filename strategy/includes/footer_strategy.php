<?php
/** strategy/includes/footer_strategy.php */
?>
</main>
<footer class="strat-footer" role="contentinfo">
  <span>Strategia Rozwoju NGO <?= defined('APP_VERSION') ? '· v' . APP_VERSION : '' ?></span>
  <span>
    <a href="<?= APP_URL ?>/strategy/index.php" class="text-muted text-decoration-none me-3">Dashboard</a>
    <a href="<?= APP_URL ?>/strategy/spheres/index.php" class="text-muted text-decoration-none me-3">Sfery</a>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/strategy/admin/settings.php" class="text-muted text-decoration-none">Ustawienia</a>
    <?php endif; ?>
  </span>
</footer>
</div><!-- /strat-shell -->
</body>
</html>
