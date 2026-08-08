<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$month = max(1, min(12, $month));

$month_str = sprintf('%04d-%02d', $year, $month);

$rows = db_all(
    "SELECT co.*, c.name AS client_name, c.date_of_birth, c.gender, c.address,
            u.name AS consultant_name
     FROM k30_consultations co
     LEFT JOIN k30_clients c ON c.id=co.client_id
     LEFT JOIN users u ON u.id=co.consultant_id
     WHERE co.status='completed' AND strftime('%Y-%m', co.consultation_datetime)=?
     ORDER BY co.consultation_datetime",
    [$month_str]
);

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="konsultacje_' . $month_str . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['Lp.','Beneficjent','Data urodzenia','Data konsultacji','Czas trwania (min)','Konsultant','SHA1'], ';');
    $i = 1;
    foreach ($rows as $r) {
        fputcsv($out, [
            $i++,
            $r['client_name'],
            $r['date_of_birth'] ?? '',
            date('d.m.Y H:i', strtotime($r['consultation_datetime'])),
            $r['duration_minutes'] ?? '',
            $r['consultant_name'] ?? '',
            $r['sha1sum'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

$PAGE_TITLE = 'Raport miesięczny — Dydaktyka 3';

$total_minutes = array_sum(array_column($rows, 'duration_minutes'));
$total_hours   = round($total_minutes / 60, 2);

$mnames = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
$years  = [];
for ($y = (int)date('Y') + 1; $y >= 2020; $y--) $years[] = $y;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="index.php">Raporty</a></li>
    <li class="breadcrumb-item active">Miesięczny</li>
  </ol>
</nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-calendar-month text-success me-2"></i>Raport miesięczny</h4>
    <div class="text-muted small"><?= $mnames[$month] ?> <?= $year ?> — <?= count($rows) ?> konsultacji, <?= $total_hours ?> h</div>
  </div>
  <?php if ($rows): ?>
  <a href="?year=<?= $year ?>&month=<?= $month ?>&export=csv" class="btn btn-outline-success btn-sm">
    <i class="bi bi-download me-1"></i>Eksport CSV
  </a>
  <?php endif; ?>
</div>

<!-- Period selector -->
<form method="get" class="d-flex gap-2 mb-4 align-items-center">
  <select name="year" class="form-select form-select-sm" style="width:auto">
    <?php foreach ($years as $y): ?>
    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
    <?php endforeach; ?>
  </select>
  <select name="month" class="form-select form-select-sm" style="width:auto">
    <?php for ($mm = 1; $mm <= 12; $mm++): ?>
    <option value="<?= $mm ?>" <?= $month === $mm ? 'selected' : '' ?>><?= $mnames[$mm] ?></option>
    <?php endfor; ?>
  </select>
  <button class="btn btn-primary btn-sm">Pokaż</button>
</form>

<?php if ($rows): ?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between" style="font-size:.85rem">
    <span>Zatwierdzone konsultacje — <?= $mnames[$month] ?> <?= $year ?></span>
    <span class="text-muted">Razem: <?= $total_hours ?> h</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Lp.</th>
          <th>Beneficjent</th>
          <th class="d-none d-md-table-cell">Data urodzenia</th>
          <th>Data konsultacji</th>
          <th class="d-none d-sm-table-cell">Czas (min)</th>
          <th class="d-none d-lg-table-cell">Konsultant</th>
          <th class="d-none d-xl-table-cell">SHA1</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$r['client_id'] ?>" class="text-decoration-none fw-semibold"><?= h($r['client_name']) ?></a></td>
          <td class="d-none d-md-table-cell"><?= $r['date_of_birth'] ? date('d.m.Y', strtotime($r['date_of_birth'])) : '—' ?></td>
          <td><?= date('d.m.Y H:i', strtotime($r['consultation_datetime'])) ?></td>
          <td class="d-none d-sm-table-cell"><?= $r['duration_minutes'] ?? '—' ?></td>
          <td class="d-none d-lg-table-cell"><?= $r['consultant_name'] ? h($r['consultant_name']) : '—' ?></td>
          <td class="d-none d-xl-table-cell"><code style="font-size:.65rem"><?= $r['sha1sum'] ? substr($r['sha1sum'], 0, 12) . '…' : '—' ?></code></td>
          <td><a href="<?= APP_URL ?>/karty30/consultations/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light">
        <tr>
          <th colspan="4" class="text-end">Łącznie minut:</th>
          <th><?= $total_minutes ?> min (<?= $total_hours ?> h)</th>
          <th colspan="3"></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Brak zatwierdzonych konsultacji w <?= $mnames[$month] ?> <?= $year ?>.</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
