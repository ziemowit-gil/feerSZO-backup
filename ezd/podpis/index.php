<?php
/**
 * Moje dokumenty do podpisu + dokumenty czekające na potwierdzenie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$user_id   = (int)current_user()['id'];
$pending   = ezd_sign_requests_pending_for_user($user_id);
$awaiting  = ezd_sign_requests_awaiting_confirm($user_id);
$PAGE_TITLE = 'Dokumenty do podpisu';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Dokumenty do podpisu</li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">

  <!-- Do podpisania przeze mnie -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-pen text-warning"></i>
        <span class="fw-semibold" style="font-size:.9rem">Do podpisania przeze mnie</span>
        <?php if($pending): ?><span class="badge bg-warning text-dark ms-auto"><?= count($pending) ?></span><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if(!$pending): ?>
        <p class="text-muted p-3 mb-0" style="font-size:.85rem"><i class="bi bi-check-circle me-1"></i>Brak oczekujących próśb o podpis.</p>
        <?php else: ?>
        <table class="table table-hover mb-0" style="font-size:.83rem">
          <thead class="table-light">
            <tr>
              <th>Dokument</th><th>Koszulka</th><th>Od</th><th>Data prośby</th><th>Uwagi</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pending as $r): ?>
            <tr>
              <td class="fw-semibold"><?= h($r['zal_name']) ?></td>
              <td><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $r['sprawa_id'] ?>" class="font-monospace text-decoration-none"><?= h($r['znak_sprawy']) ?></a></td>
              <td><?= h($r['requested_by_name']) ?></td>
              <td><?= h(substr($r['requested_at'],0,16)) ?></td>
              <td class="text-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($r['notes']) ?></td>
              <td><a href="<?= APP_URL ?>/ezd/podpis/view.php?id=<?= $r['id'] ?>" class="btn btn-warning btn-sm"><i class="bi bi-pen me-1"></i>Podpisz</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Czekają na moje potwierdzenie -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-check2-square text-success"></i>
        <span class="fw-semibold" style="font-size:.9rem">Podpisane — czekają na moje potwierdzenie</span>
        <?php if($awaiting): ?><span class="badge bg-success ms-auto"><?= count($awaiting) ?></span><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if(!$awaiting): ?>
        <p class="text-muted p-3 mb-0" style="font-size:.85rem"><i class="bi bi-inbox me-1"></i>Brak dokumentów czekających na potwierdzenie.</p>
        <?php else: ?>
        <table class="table table-hover mb-0" style="font-size:.83rem">
          <thead class="table-light">
            <tr><th>Dokument</th><th>Koszulka</th><th>Podpisał</th><th>Podpisano</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach($awaiting as $r): ?>
            <tr>
              <td class="fw-semibold"><?= h($r['zal_name']) ?></td>
              <td><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $r['sprawa_id'] ?>" class="font-monospace text-decoration-none"><?= h($r['znak_sprawy']) ?></a></td>
              <td><?= h($r['requested_to_name']) ?></td>
              <td><?= h(substr($r['signed_at'] ?? '',0,16)) ?></td>
              <td><a href="<?= APP_URL ?>/ezd/podpis/view.php?id=<?= $r['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Potwierdź zwrot</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
