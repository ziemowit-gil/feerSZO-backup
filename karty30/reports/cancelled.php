<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Odwołane terminy — Karty 30';

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$type      = $_GET['type']      ?? '';

$where  = "s.status IN ('cancelled_by_feer','cancelled_by_client','no_show','cancelled')";
$params = [];

if ($date_from) {
    $where .= " AND DATE(s.start_time)>=?";
    $params[] = $date_from;
}
if ($date_to) {
    $where .= " AND DATE(s.start_time)<=?";
    $params[] = $date_to;
}
if ($type && in_array($type, ['cancelled_by_feer','cancelled_by_client','no_show','cancelled'])) {
    $where .= " AND s.status=?";
    $params[] = $type;
}

$rows = db_all(
    "SELECT s.*, c.name AS client_name, c.phone AS client_phone, u.name AS consultant_name
     FROM k30_schedules s
     LEFT JOIN k30_clients c ON c.id=s.client_id
     LEFT JOIN users u ON u.id=s.assigned_to
     WHERE $where ORDER BY s.start_time DESC",
    $params
);

// Group by type
$by_type = [];
foreach ($rows as $r) {
    $by_type[$r['status']] = ($by_type[$r['status']] ?? 0) + 1;
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item"><a href="index.php">Raporty</a></li>
    <li class="breadcrumb-item active">Odwołane terminy</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-x-circle text-danger me-2"></i>Odwołane terminy</h4>

<!-- Filter form -->
<form method="get" class="d-flex flex-wrap gap-2 mb-4 align-items-center">
  <input name="date_from" type="date" class="form-control form-control-sm" style="width:auto" value="<?= h($date_from) ?>">
  <span class="text-muted small">do</span>
  <input name="date_to" type="date" class="form-control form-control-sm" style="width:auto" value="<?= h($date_to) ?>">
  <select name="type" class="form-select form-select-sm" style="width:auto">
    <option value="">Wszystkie typy</option>
    <option value="cancelled_by_feer"   <?= $type==='cancelled_by_feer'   ?'selected':'' ?>>Odwołana przez FEER</option>
    <option value="cancelled_by_client" <?= $type==='cancelled_by_client' ?'selected':'' ?>>Odwołana przez beneficjenta</option>
    <option value="no_show"             <?= $type==='no_show'             ?'selected':'' ?>>Nie pojawił się</option>
    <option value="cancelled"           <?= $type==='cancelled'           ?'selected':'' ?>>Odwołana (inne)</option>
  </select>
  <button class="btn btn-primary btn-sm">Filtruj</button>
  <?php if ($type): ?><a href="?date_from=<?= h($date_from) ?>&date_to=<?= h($date_to) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a><?php endif; ?>
</form>

<!-- Summary pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($by_type as $st => $cnt):
    $sv = K30_SCHEDULE_STATUSES[$st] ?? ['label'=>$st,'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-dash'];
  ?>
  <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .75rem;border-radius:2rem;font-size:.75rem;font-weight:600;background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>">
    <i class="bi <?= $sv['icon'] ?>"></i><?= $sv['label'] ?>: <?= $cnt ?>
  </span>
  <?php endforeach; ?>
  <?php if ($rows): ?>
  <span class="badge bg-secondary">Łącznie: <?= count($rows) ?></span>
  <?php endif; ?>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Data terminu</th>
          <th>Beneficjent</th>
          <th class="d-none d-md-table-cell">Konsultant</th>
          <th>Typ odwołania</th>
          <th class="d-none d-lg-table-cell">Powód</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <div><?= date('d.m.Y', strtotime($r['start_time'])) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= date('H:i', strtotime($r['start_time'])) ?></div>
          </td>
          <td>
            <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$r['client_id'] ?>" class="text-decoration-none fw-semibold"><?= h($r['client_name']) ?></a>
            <?php if ($r['client_phone']): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['client_phone']) ?></div><?php endif; ?>
          </td>
          <td class="d-none d-md-table-cell"><?= $r['consultant_name'] ? h($r['consultant_name']) : '—' ?></td>
          <td><?= k30_status_badge($r['status']) ?></td>
          <td class="d-none d-lg-table-cell"><?= $r['cancel_reason'] ? h($r['cancel_reason']) : '<span class="text-muted">—</span>' ?></td>
          <td><a href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak odwołanych terminów w wybranym zakresie.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
