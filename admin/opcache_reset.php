<?php
/**
 * admin/opcache_reset.php — resetuje OPcache na żądanie admina.
 *
 * Potrzebne gdy php.prod.ini ma opcache.validate_timestamps=0 (prod):
 * graceful Apache reload nie czyści SHM — trzeba to wywołać z wewnątrz
 * procesu Apache (CLI opcache_reset() nie trafia do SHM Apache).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('403'); }

$reset = false;
$info  = null;
if (function_exists('opcache_get_status')) {
    $info = opcache_get_status(false);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $reset = true;
    }
    flash_set($reset ? 'success' : 'error',
              $reset ? 'OPcache wyczyszczony.' : 'Funkcja opcache_reset() niedostępna (CLI lub brak rozszerzenia).');
    header('Location: ' . APP_URL . '/admin/opcache_reset.php'); exit;
}

$PAGE_TITLE = 'Reset OPcache';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-lightning-charge text-warning me-2"></i>Reset OPcache</h4>
</div>
<?= flash_html() ?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <p class="mb-2 text-muted small">
      Produkcyjne <code>php.prod.ini</code> ma <code>opcache.validate_timestamps=0</code> — po <code>git pull</code>
      Apache serwuje stare pliki PHP z cache. Reset czyści OPcache wewnątrz tego procesu Apache.
    </p>
    <?php if ($info): ?>
    <ul class="list-unstyled small mb-3">
      <li>Pliki w cache: <strong><?= number_format($info['opcache_statistics']['num_cached_scripts'] ?? 0) ?></strong></li>
      <li>Trafienia cache: <strong><?= number_format($info['opcache_statistics']['hits'] ?? 0) ?></strong></li>
      <li>Zużycie pamięci: <strong><?= round(($info['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 1) ?> MB</strong></li>
    </ul>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <button class="btn btn-warning">
        <i class="bi bi-lightning-charge-fill me-1"></i>Wyczyść OPcache teraz
      </button>
    </form>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
