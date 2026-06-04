<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin');

$logs = db_all(
    "SELECT l.*, u.email AS user_email
     FROM rodo_deletion_log l
     LEFT JOIN users u ON u.id = l.deleted_by_id
     ORDER BY l.deleted_at DESC"
);

$PAGE_TITLE = 'Log usunięć upoważnień RODO';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/rodo/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-journal-x text-danger me-2"></i>Log usunięć upoważnień RODO</h4>
    <div class="text-muted small">Audit trail — art. 5 ust. 2 RODO (zasada rozliczalności)</div>
  </div>
</div>

<div class="alert alert-info py-2 small d-flex gap-2 mb-3">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    Log zawiera zapis każdego trwałego usunięcia upoważnienia z rejestru.
    Dane osobowe (PESEL) zostają usunięte razem z upoważnieniem —
    w logu przechowywana jest jedynie informacja o fakcie usunięcia, kto i dlaczego.
  </div>
</div>

<?php if (!$logs): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-journal-check" style="font-size:2rem"></i>
  <div class="mt-2">Brak odnotowanych usunięć</div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr>
          <th>Data usunięcia</th>
          <th>Nr upoważnienia</th>
          <th>Osoba (imię i nazwisko)</th>
          <th>Nr umowy</th>
          <th>Powód</th>
          <th>Usunął</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td class="text-nowrap fw-semibold text-danger">
            <?= date('d.m.Y H:i', strtotime($l['deleted_at'])) ?>
          </td>
          <td class="font-monospace small"><?= h($l['auth_number']) ?></td>
          <td><?= h($l['auth_person']) ?></td>
          <td class="text-muted small"><?= h($l['auth_contract'] ?: '—') ?></td>
          <td class="text-muted">
            <?= $l['reason'] ? h(mb_substr($l['reason'], 0, 80)) . (mb_strlen($l['reason']) > 80 ? '…' : '') : '<span class="text-danger small">brak powodu</span>' ?>
          </td>
          <td class="small text-muted">
            <?= h($l['deleted_by_name']) ?>
            <?php if ($l['user_email']): ?>
            <div style="font-size:.75rem"><?= h($l['user_email']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted small mt-2 text-end"><?= count($logs) ?> rekordów</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
