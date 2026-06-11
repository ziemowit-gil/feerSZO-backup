<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once __DIR__ . '/_table.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
migracja_webngo_ensure_table();

$PAGE_TITLE = 'Migracje z webNGO';
$search  = trim($_GET['q']      ?? '');
$status  = trim($_GET['status'] ?? '');
$typ     = trim($_GET['typ']    ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$per     = 25;

$where  = '1=1';
$params = [];
if ($search) {
    $where .= " AND (numer_umowy LIKE ? OR webngo_numer_umowy LIKE ? OR imie_nazwisko LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status) { $where .= ' AND status = ?';            $params[] = $status; }
if ($typ)    { $where .= ' AND typ_umowy_zrodla = ?';  $params[] = $typ; }

$total = db_one("SELECT COUNT(*) AS c FROM umowy_migracja_webngo WHERE {$where}", $params)['c'] ?? 0;
$pag   = paginate($total, $per, $page,
    APP_URL . '/contracts/migracja_webngo/list.php?' .
    http_build_query(array_filter(['q' => $search, 'status' => $status, 'typ' => $typ])));
$rows  = db_all(
    "SELECT * FROM umowy_migracja_webngo WHERE {$where} ORDER BY created_at DESC LIMIT {$per} OFFSET {$pag['offset']}",
    $params
);

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-arrow-left-right text-warning"></i> Migracje z webNGO
    <span class="badge bg-secondary ms-1"><?= $total ?></span>
  </h4>
  <?php if (can_edit()): ?>
  <a href="add.php" class="btn btn-warning fw-semibold">
    <i class="bi bi-plus-lg"></i> Nowa migracja
  </a>
  <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form class="row g-2" method="get">
      <div class="col-md-4">
        <input name="q" class="form-control" placeholder="Szukaj (numer, nazwisko...)"
               value="<?= h($search) ?>">
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select">
          <option value="">Wszystkie statusy</option>
          <?php foreach (MIGRACJA_STATUSY as $k => $d): ?>
          <option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="typ" class="form-select">
          <option value="">Wszystkie typy</option>
          <?php foreach (MIGRACJA_TYPY as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $typ === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
      </div>
      <?php if ($search || $status || $typ): ?>
      <div class="col-auto">
        <a href="?" class="btn btn-outline-secondary">Wyczyść</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Nr migracji</th>
          <th>Nr webNGO</th>
          <th>Typ</th>
          <th>Osoba</th>
          <th>Powód</th>
          <th>Data</th>
          <th>Nowa umowa</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="fw-semibold font-monospace small"><?= h($r['numer_umowy']) ?></td>
          <td class="font-monospace small"><?= h($r['webngo_numer_umowy']) ?></td>
          <td><small><?= h(MIGRACJA_TYPY[$r['typ_umowy_zrodla']] ?? $r['typ_umowy_zrodla']) ?></small></td>
          <td><?= h($r['imie_nazwisko'] ?? '—') ?></td>
          <td><small class="text-muted"><?= h(MIGRACJA_POWODY[$r['powod_migracji']] ?? $r['powod_migracji']) ?></small></td>
          <td><small><?= date_pl($r['data_migracji']) ?></small></td>
          <td>
            <?php if ($r['nowy_numer_umowy']): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">
                <?= h($r['nowy_numer_umowy']) ?>
              </span>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td><?= migracja_status_badge($r['status']) ?></td>
          <td class="text-end">
            <a href="view.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-primary" title="Podgląd">
              <i class="bi bi-eye"></i>
            </a>
            <a href="print.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-secondary" target="_blank" title="Drukuj potwierdzenie">
              <i class="bi bi-printer"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-4">
            <i class="bi bi-inbox me-1"></i> Brak zarejestrowanych migracji
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pag['pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <small class="text-muted">Łącznie: <?= $total ?></small>
    <?= pagination_html($pag) ?>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
