<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Konsultacje — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

$search    = trim($_GET['q']         ?? '');
$status    = $_GET['status']         ?? '';
$month     = (int)($_GET['month']    ?? 0);
$year      = (int)($_GET['year']     ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per       = 25;

if (!$year)  $year  = (int)date('Y');
if (!$month) $month = 0; // 0 = all months

$where  = '1=1';
$params = [];

if ($search) {
    $where .= " AND c.name LIKE ?";
    $params[] = "%$search%";
}
if ($status) {
    $where .= " AND co.status=?";
    $params[] = $status;
}
if ($month) {
    $where .= " AND strftime('%Y-%m', co.consultation_datetime)=?";
    $params[] = sprintf('%04d-%02d', $year, $month);
} else {
    $where .= " AND strftime('%Y', co.consultation_datetime)=?";
    $params[] = (string)$year;
}
if ($client_id) {
    $where .= " AND co.client_id=?";
    $params[] = $client_id;
}

$total  = (int)(db_one("SELECT COUNT(*) AS n FROM k30_consultations co LEFT JOIN k30_clients c ON c.id=co.client_id WHERE $where", $params)['n'] ?? 0);
$offset = ($page - 1) * $per;
$rows   = db_all(
    "SELECT co.*, c.name AS client_name, u.name AS consultant_name
     FROM k30_consultations co
     LEFT JOIN k30_clients c ON c.id=co.client_id
     LEFT JOIN users u ON u.id=co.consultant_id
     WHERE $where ORDER BY co.consultation_datetime DESC LIMIT $per OFFSET $offset",
    $params
);

$pag_url = APP_URL . '/karty30/consultations/index.php?' . http_build_query(array_filter(['q'=>$search,'status'=>$status,'month'=>$month?:null,'year'=>$year,'client_id'=>$client_id?:null]));
$pag     = paginate($total, $per, $page, $pag_url);

$years = [];
for ($y = (int)date('Y') + 1; $y >= 2020; $y--) $years[] = $y;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item active">Konsultacje</li>
  </ol>
</nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-clipboard2-check text-secondary me-2"></i>Konsultacje</h4>
    <div class="text-muted small">Łącznie: <?= $total ?></div>
  </div>
  <?php if ($can_write): ?>
  <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-clipboard2-plus me-1"></i>Nowa konsultacja</a>
  <?php endif; ?>
</div>

<!-- Status pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'year'=>$year,'month'=>$month?:null])) ?>"
     class="badge <?= !$status ? 'bg-dark' : 'bg-light text-dark border' ?> text-decoration-none py-2 px-3" style="font-size:.75rem">Wszystkie</a>
  <?php foreach (K30_CONSULTATION_STATUSES as $sk => $sv): ?>
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'status'=>$sk,'year'=>$year,'month'=>$month?:null])) ?>"
     class="text-decoration-none" style="display:inline-flex;align-items:center;padding:.3rem .75rem;border-radius:2rem;font-size:.75rem;font-weight:600;background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border:1.5px solid <?= $status===$sk?$sv['color']:'transparent' ?>">
    <?= $sv['label'] ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="mb-3 d-flex flex-wrap gap-2 align-items-center">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
  <div class="input-group input-group-sm" style="max-width:220px">
    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
    <input name="q" class="form-control" placeholder="Beneficjent…" value="<?= h($search) ?>">
  </div>
  <select name="year" class="form-select form-select-sm" style="width:auto">
    <?php foreach ($years as $y): ?>
    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
    <?php endforeach; ?>
  </select>
  <select name="month" class="form-select form-select-sm" style="width:auto">
    <option value="0" <?= !$month ? 'selected' : '' ?>>Cały rok</option>
    <?php $mnames = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
    for ($mm = 1; $mm <= 12; $mm++): ?>
    <option value="<?= $mm ?>" <?= $month === $mm ? 'selected' : '' ?>><?= $mnames[$mm] ?></option>
    <?php endfor; ?>
  </select>
  <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
  <?php if ($search||$status): ?>
  <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
  <?php endif; ?>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Data</th>
          <th>Beneficjent</th>
          <th class="d-none d-md-table-cell">Konsultant</th>
          <th class="d-none d-sm-table-cell">Czas</th>
          <th>Status</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= date('d.m.Y', strtotime($r['consultation_datetime'])) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= date('H:i', strtotime($r['consultation_datetime'])) ?></div>
          </td>
          <td>
            <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$r['client_id'] ?>" class="text-decoration-none fw-semibold"><?= h($r['client_name']) ?></a>
          </td>
          <td class="d-none d-md-table-cell"><?= $r['consultant_name'] ? h($r['consultant_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="d-none d-sm-table-cell"><?= $r['duration_minutes'] ? (int)$r['duration_minutes'] . ' min' : '—' ?></td>
          <td><?= k30_status_badge($r['status'], 'consultation') ?></td>
          <td class="text-end" style="white-space:nowrap">
            <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-eye"></i></a>
            <?php if ($can_write): ?>
            <a href="edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak konsultacji.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">Znaleziono: <strong><?= $total ?></strong></small>
    <?php if ($pag['pages'] > 1) echo pagination_html($pag); ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
