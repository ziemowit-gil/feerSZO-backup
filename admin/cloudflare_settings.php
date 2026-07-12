<?php
/**
 * admin/cloudflare_settings.php — Konfiguracja tokenu API Cloudflare (zarządzanie DNS).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cloudflare.php';

require_role('admin');
$PAGE_TITLE = 'Cloudflare DNS — ustawienia';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        // Sekret — nie nadpisuj pustym (zostaw dotychczasowy)
        if (trim($_POST['api_token'] ?? '') !== '') cloudflare_save_setting('api_token', trim($_POST['api_token']));
        flash_set('success', 'Ustawienia Cloudflare zapisane.');
        header('Location: cloudflare_settings.php'); exit;
    }

    if ($action === 'test') {
        $r = cloudflare_test_connection();
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: cloudflare_settings.php'); exit;
    }
}

$cfg = [
    'has_token' => cloudflare_setting('api_token') !== '',
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-globe2 text-primary me-2"></i>Cloudflare DNS — ustawienia</h4>
  <span class="badge <?= cloudflare_configured() ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= cloudflare_configured() ? 'Skonfigurowane' : 'Wymaga konfiguracji' ?>
  </span>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-key me-2"></i>Token API</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="save">

          <div class="mb-3">
            <label class="form-label fw-semibold" for="cf_token">API Token <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="api_token" id="cf_token"
                   placeholder="<?= $cfg['has_token'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'wklej token API Cloudflare' ?>">
            <div class="form-text">
              Utwórz token w panelu Cloudflare → Profil → API Tokens → „Create Token", z uprawnieniami
              <code>Zone:Read</code> oraz <code>DNS:Edit</code> dla stref, którymi ma zarządzać ten moduł.
              Nie używaj Global API Key.
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz</button>
            <?php if (cloudflare_configured()): ?>
            <button type="submit" form="cf_test" class="btn btn-outline-secondary"><i class="bi bi-wifi me-1"></i>Testuj połączenie</button>
            <a href="cloudflare_dns.php" class="btn btn-outline-primary"><i class="bi bi-hdd-network me-1"></i>Przejdź do zarządzania DNS</a>
            <?php endif; ?>
          </div>
        </form>
        <form id="cf_test" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="test">
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle me-2"></i>O module</div>
      <div class="card-body small text-muted">
        <p>Po skonfigurowaniu tokenu przejdź do
          <a href="cloudflare_dns.php">Cloudflare DNS</a> (dostępne też z menu IT), aby przeglądać
          strefy i zarządzać rekordami DNS (A, AAAA, CNAME, TXT, MX, NS, SRV, CAA) bezpośrednio z panelu.</p>
        <p class="mb-0">Zmiany rekordów są wykonywane od razu na koncie Cloudflare — nie ma etapu „szkicu".</p>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
