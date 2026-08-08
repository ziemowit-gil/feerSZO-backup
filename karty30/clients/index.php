<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Beneficjenci — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

$search = trim($_GET['q']     ?? '');
$status = $_GET['status']     ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;

$where  = '1=1';
$params = [];
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($status) {
    $where .= " AND c.status=?";
    $params[] = $status;
}

$total  = (int)(db_one("SELECT COUNT(*) AS n FROM k30_clients c WHERE $where", $params)['n'] ?? 0);
$offset = ($page - 1) * $per;
$rows   = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM k30_schedules s WHERE s.client_id=c.id AND s.status IN ('preliminary','confirmed')) AS pending_schedules,
            (SELECT COUNT(*) FROM k30_consultations co WHERE co.client_id=c.id AND co.status='completed') AS total_consultations,
            (CASE WHEN EXISTS(SELECT 1 FROM k30_blacklist bl WHERE bl.client_id=c.id) THEN 1 ELSE 0 END) AS is_blacklisted
     FROM k30_clients c WHERE $where ORDER BY c.name LIMIT $per OFFSET $offset",
    $params
);

// Liczby statusów
$status_counts = [];
$status_total  = 0;
foreach (K30_CLIENT_STATUSES as $sk => $sv) {
    $cnt = (int)(db_one("SELECT COUNT(*) AS n FROM k30_clients WHERE status=?", [$sk])['n'] ?? 0);
    $status_counts[$sk] = $cnt;
    $status_total += $cnt;
}

$pag_url = APP_URL . '/karty30/clients/index.php?' . http_build_query(array_filter(['q' => $search, 'status' => $status]));
$pag     = paginate($total, $per, $page, $pag_url);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item active">Beneficjenci</li>
  </ol>
</nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>Beneficjenci</h4>
    <div class="text-muted small">Łącznie: <?= $status_total ?> osób</div>
  </div>
  <?php if ($can_write): ?>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nowy beneficjent</a>
  <?php endif; ?>
</div>

<!-- Status pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?<?= http_build_query(array_filter(['q' => $search])) ?>"
     class="badge <?= !$status ? 'bg-dark' : 'bg-light text-dark border' ?> text-decoration-none py-2 px-3" style="font-size:.75rem">
    Wszyscy <?= $status_total ?>
  </a>
  <?php foreach (K30_CLIENT_STATUSES as $sk => $sv): if (!($status_counts[$sk] ?? 0)) continue; ?>
  <a href="?<?= http_build_query(array_filter(['q' => $search, 'status' => $sk])) ?>"
     class="text-decoration-none" style="display:inline-flex;align-items:center;padding:.3rem .75rem;border-radius:2rem;font-size:.75rem;font-weight:600;background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border:1.5px solid <?= $status === $sk ? $sv['color'] : 'transparent' ?>">
    <?= $sv['label'] ?> <?= $status_counts[$sk] ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="mb-3 d-flex gap-2 align-items-center">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
  <div class="input-group input-group-sm" style="max-width:350px">
    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
    <input name="q" class="form-control" placeholder="Szukaj po nazwisku, emailu, telefonie…" value="<?= h($search) ?>">
  </div>
  <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i></button>
  <?php if ($search || $status): ?>
  <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
  <?php endif; ?>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Beneficjent</th>
          <th class="d-none d-md-table-cell">Status</th>
          <th class="d-none d-lg-table-cell">Godziny</th>
          <th class="d-none d-sm-table-cell">Terminy</th>
          <th class="d-none d-lg-table-cell">Konsultacje</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $sc        = K30_CLIENT_STATUSES[$r['status']] ?? K30_CLIENT_STATUSES['enrolled'];
          $remaining = max(0, (float)$r['available_hours'] - (float)$r['used']);
        ?>
        <tr>
          <td>
            <div class="fw-semibold">
              <?= h($r['name']) ?>
              <?php if ($r['is_blacklisted']): ?>
              <span class="badge bg-danger ms-1" style="font-size:.65rem">czarna lista</span>
              <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.75rem"><?= h($r['email'] ?? '') ?> <?= $r['phone'] ? '· ' . h($r['phone']) : '' ?></div>
          </td>
          <td class="d-none d-md-table-cell">
            <span style="display:inline-flex;align-items:center;padding:.18rem .6rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
              <?= $sc['label'] ?>
            </span>
          </td>
          <td class="d-none d-lg-table-cell">
            <div style="font-size:.82rem"><?= number_format($remaining, 1) ?> / <?= number_format((float)$r['available_hours'], 1) ?> h</div>
            <?php if ($r['available_hours'] > 0): ?>
            <div style="height:4px;background:#F3F4F6;border-radius:2px;width:80px;margin-top:2px">
              <div style="height:4px;border-radius:2px;background:#2E844A;width:<?= min(100, round($r['used'] / $r['available_hours'] * 100)) ?>%"></div>
            </div>
            <?php endif; ?>
          </td>
          <td class="d-none d-sm-table-cell">
            <?php if ($r['pending_schedules']): ?>
            <span class="badge bg-warning text-dark"><?= (int)$r['pending_schedules'] ?></span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="d-none d-lg-table-cell"><?= (int)$r['total_consultations'] ?></td>
          <td class="text-end" style="white-space:nowrap">
            <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-eye"></i></a>
            <?php if ($can_write): ?>
            <a href="edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <a href="<?= APP_URL ?>/karty30/schedules/add.php?client_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-success py-0 px-2" title="Dodaj termin"><i class="bi bi-calendar-plus"></i></a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak beneficjentów<?= $search || $status ? ' dla tych filtrów' : '' ?>.</td></tr>
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
