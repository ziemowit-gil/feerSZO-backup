<?php
/**
 * resources/admin/index.php — Panel zarządzania rezerwacjami (admin).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/resources.php';

require_login();
ika_require();
resources_migrate();

$is_admin    = is_admin() || can_write('resources');
$user        = current_user();
$is_dysponent = !$is_admin;

if (!$is_admin && !$is_dysponent) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/resources/'); exit;
}

$PAGE_TITLE = 'Zarządzanie rezerwacjami';

$status_filter  = $_GET['status'] ?? '';
$date_from      = $_GET['date_from'] ?? '';
$date_to        = $_GET['date_to']   ?? '';

// Admini widzą wszystkie, dysponent tylko swoje
if ($is_admin) {
    $reservations = res_all_reservations($status_filter, $date_from, $date_to);
    // Pending dla admina
    $pending_admin = array_filter($reservations, fn($r) => in_array($r['status'], ['zlozony','pending_admin']));
} else {
    $reservations = res_reservations_pending_dysponent((int)$user['id']);
    $pending_admin = [];
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
.res-status-badge { display:inline-block; padding:.2em .55em; border-radius:6px; font-size:.75rem; font-weight:600; }
</style>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-calendar-check-fill text-primary me-1"></i>Rezerwacje zasobów</h4>
  <div class="ms-auto d-flex gap-2">
    <?php if ($is_admin): ?>
    <a href="<?= APP_URL ?>/resources/admin/resources.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-box me-1"></i>Zasoby
    </a>
    <a href="<?= APP_URL ?>/resources/admin/categories.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-tags me-1"></i>Kategorie
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/resources/" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-box-arrow-left me-1"></i>Lista zasobów
    </a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($is_admin && $pending_admin): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
  <span><strong><?= count($pending_admin) ?></strong> rezerwacji oczekuje na Twoją decyzję.</span>
</div>
<?php endif; ?>

<!-- Filtry (tylko admin) -->
<?php if ($is_admin): ?>
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Status</label>
    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszystkie</option>
      <?php foreach (RES_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $status_filter===$k?'selected':'' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Od</label>
    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Do</label>
    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
  </div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filtruj</button></div>
  <?php if ($status_filter || $date_from || $date_to): ?>
  <div class="col-auto"><a href="?" class="btn btn-sm btn-link text-muted">Resetuj</a></div>
  <?php endif; ?>
</form>
<?php endif; ?>

<?php if (!$reservations): ?>
<div class="alert alert-info">Brak rezerwacji do wyświetlenia.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.87rem">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Zasób</th>
          <th>Wnioskodawca</th>
          <th>Termin</th>
          <th>Cel</th>
          <th>Status</th>
          <th>Data</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reservations as $r): ?>
        <tr class="<?= in_array($r['status'],['zlozony','pending_admin','pending_dysponent'])?'table-warning':'' ?>">
          <td class="text-muted"><?= (int)$r['id'] ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <i class="bi <?= h($r['cat_icon']??'bi-box') ?>" style="color:<?= h($r['cat_color']??'#666') ?>"></i>
              <span class="fw-semibold"><?= h($r['res_name']) ?></span>
            </div>
          </td>
          <td><?= h($r['user_name']) ?></td>
          <td class="text-nowrap">
            <?= h($r['date_from']) ?>
            <?php if ($r['date_to']!==$r['date_from']): ?> – <?= h($r['date_to']) ?><?php endif; ?>
          </td>
          <td style="max-width:180px">
            <span class="text-truncate d-block" style="max-width:160px"><?= h($r['purpose']) ?></span>
          </td>
          <td><?= res_status_badge($r['status']) ?></td>
          <td class="text-muted"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/resources/view.php?id=<?= (int)$r['id'] ?>"
               class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2">
              <?= in_array($r['status'],['zlozony','pending_admin','pending_dysponent'])
                ? '<i class="bi bi-check2-circle"></i>'
                : '<i class="bi bi-eye"></i>' ?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
