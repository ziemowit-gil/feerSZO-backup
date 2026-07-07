<?php
/**
 * vpn/index.php — Panel użytkownika: wniosek o dostęp do VPN + dane po akceptacji.
 * Nie może być bramkowany przez VPN (tu użytkownik dopiero prosi o dostęp).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/vpn.php';

require_login();
require_module_enabled('vpn_enabled', 'Moduł VPN');

$u   = current_user();
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        vpn_request($uid, trim($_POST['reason'] ?? ''));
        flash_set('success', 'Wniosek o dostęp do VPN został złożony. Powiadomimy Cię po decyzji administratora.');
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/vpn/index.php'); exit;
}

$cur = vpn_current_for_user($uid);
$PAGE_TITLE = 'Dostęp VPN';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:720px">
  <h1 class="h4 mb-3"><i class="bi bi-shield-lock me-2"></i>Dostęp do VPN</h1>
  <?= flash_html() ?>

  <?php if ($cur): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="fw-semibold">Status wniosku</span>
          <?= vpn_status_badge($cur['status']) ?>
        </div>
        <div class="small text-muted">Złożono: <?= h(substr((string)$cur['requested_at'], 0, 16)) ?></div>
        <?php if ($cur['reason']): ?>
          <hr><div class="small"><strong>Twoje uzasadnienie:</strong><br><span style="white-space:pre-wrap"><?= h($cur['reason']) ?></span></div>
        <?php endif; ?>
        <?php if (in_array($cur['status'], ['odrzucony','cofniety'], true) && trim((string)$cur['decision_note']) !== ''): ?>
          <hr><div class="small text-danger"><strong>Uwagi administratora:</strong><br><span style="white-space:pre-wrap"><?= h($cur['decision_note']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($cur && $cur['status'] === 'aktywny'): ?>
    <div class="card shadow-sm border-success mb-3">
      <div class="card-header bg-success-subtle fw-semibold"><i class="bi bi-check-circle me-1"></i>Twój dostęp VPN jest aktywny</div>
      <div class="card-body">
        <?php if ($cur['vpn_username']): ?>
          <div class="mb-2"><span class="text-muted small">Nazwa użytkownika VPN:</span><br><code><?= h($cur['vpn_username']) ?></code></div>
        <?php endif; ?>
        <?php if (trim((string)$cur['instrukcja']) !== ''): ?>
          <div class="mb-2"><span class="text-muted small">Instrukcja:</span><div style="white-space:pre-wrap"><?= h($cur['instrukcja']) ?></div></div>
        <?php endif; ?>
        <?php if (trim((string)$cur['vpn_config']) !== ''): ?>
          <a href="<?= APP_URL ?>/vpn/config.php" class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Pobierz konfigurację VPN</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($cur && $cur['status'] === 'oczekuje'): ?>
    <div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>Twój wniosek oczekuje na decyzję administratora.</div>
  <?php endif; ?>

  <?php if (vpn_can_request($uid)): ?>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">Złóż wniosek o dostęp do VPN</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Uzasadnienie <span class="text-muted small">(do czego potrzebujesz VPN)</span></label>
            <textarea name="reason" class="form-control" rows="4" required placeholder="np. Zdalny dostęp do Wirtualnego biurka i zasobów sieciowych"></textarea>
          </div>
          <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Złóż wniosek</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
