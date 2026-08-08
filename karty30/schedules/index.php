<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Harmonogram — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

$search    = trim($_GET['q']         ?? '');
$status    = $_GET['status']         ?? '';
$date_from = $_GET['date_from']      ?? '';
$date_to   = $_GET['date_to']        ?? '';
$client_id = (int)($_GET['client_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per       = 25;

$where  = '1=1';
$params = [];

if ($search) {
    $where .= " AND c.name LIKE ?";
    $params[] = "%$search%";
}
if ($status) {
    $where .= " AND s.status=?";
    $params[] = $status;
}
if ($date_from) {
    $where .= " AND DATE(s.start_time)>=?";
    $params[] = $date_from;
}
if ($date_to) {
    $where .= " AND DATE(s.start_time)<=?";
    $params[] = $date_to;
}
if ($client_id) {
    $where .= " AND s.client_id=?";
    $params[] = $client_id;
}

$total  = (int)(db_one("SELECT COUNT(*) AS n FROM k30_schedules s LEFT JOIN k30_clients c ON c.id=s.client_id WHERE $where", $params)['n'] ?? 0);
$offset = ($page - 1) * $per;
$rows   = db_all(
    "SELECT s.*, c.name AS client_name, c.phone AS client_phone,
            u.name AS consultant_name
     FROM k30_schedules s
     LEFT JOIN k30_clients c ON c.id=s.client_id
     LEFT JOIN users u ON u.id=s.assigned_to
     WHERE $where ORDER BY s.start_time DESC LIMIT $per OFFSET $offset",
    $params
);

$pag_url = APP_URL . '/karty30/schedules/index.php?' . http_build_query(array_filter(['q'=>$search,'status'=>$status,'date_from'=>$date_from,'date_to'=>$date_to,'client_id'=>$client_id?:null]));
$pag     = paginate($total, $per, $page, $pag_url);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item active">Harmonogram</li>
  </ol>
</nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-calendar3 text-success me-2"></i>Harmonogram wizyt</h4>
    <div class="text-muted small">Łącznie: <?= $total ?> terminów</div>
  </div>
  <div class="d-flex gap-2">
    <a href="calendar.php" class="btn btn-outline-info btn-sm"><i class="bi bi-calendar-week me-1"></i>Kalendarz</a>
    <?php if ($can_write): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Nowy termin</a>
    <?php endif; ?>
  </div>
</div>

<!-- Status pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'date_from'=>$date_from,'date_to'=>$date_to])) ?>"
     class="badge <?= !$status ? 'bg-dark' : 'bg-light text-dark border' ?> text-decoration-none py-2 px-3" style="font-size:.75rem">Wszystkie</a>
  <?php foreach (K30_SCHEDULE_STATUSES as $sk => $sv): ?>
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'status'=>$sk,'date_from'=>$date_from,'date_to'=>$date_to])) ?>"
     class="text-decoration-none" style="display:inline-flex;align-items:center;gap:.25rem;padding:.3rem .75rem;border-radius:2rem;font-size:.75rem;font-weight:600;background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border:1.5px solid <?= $status===$sk?$sv['color']:'transparent' ?>">
    <i class="bi <?= $sv['icon'] ?>"></i><?= $sv['label'] ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="mb-3 d-flex flex-wrap gap-2 align-items-center">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
  <div class="input-group input-group-sm" style="max-width:250px">
    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
    <input name="q" class="form-control" placeholder="Beneficjent…" value="<?= h($search) ?>">
  </div>
  <input name="date_from" type="date" class="form-control form-control-sm" style="width:auto" value="<?= h($date_from) ?>" placeholder="Od">
  <input name="date_to"   type="date" class="form-control form-control-sm" style="width:auto" value="<?= h($date_to) ?>"   placeholder="Do">
  <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
  <?php if ($search||$status||$date_from||$date_to): ?>
  <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
  <?php endif; ?>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Data / czas</th>
          <th>Beneficjent</th>
          <th class="d-none d-md-table-cell">Konsultant</th>
          <th class="d-none d-sm-table-cell">Czas</th>
          <th>Status</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $s):
          $dt = new DateTime($s['start_time']);
          $isToday = $dt->format('Y-m-d') === date('Y-m-d');
        ?>
        <tr>
          <td>
            <div class="fw-semibold <?= $isToday ? 'text-primary' : '' ?>"><?= $dt->format('d.m.Y') ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= $dt->format('H:i') ?></div>
          </td>
          <td>
            <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$s['client_id'] ?>" class="text-decoration-none fw-semibold"><?= h($s['client_name']) ?></a>
            <?php if ($s['client_phone']): ?><div class="text-muted" style="font-size:.75rem"><?= h($s['client_phone']) ?></div><?php endif; ?>
          </td>
          <td class="d-none d-md-table-cell"><?= $s['consultant_name'] ? h($s['consultant_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="d-none d-sm-table-cell"><?= (int)$s['duration_minutes'] ?> min</td>
          <td><?= k30_status_badge($s['status']) ?></td>
          <td class="text-end" style="white-space:nowrap">
            <a href="view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-eye"></i></a>
            <?php if ($can_write): ?>
            <a href="edit.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak terminów.</td></tr>
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
