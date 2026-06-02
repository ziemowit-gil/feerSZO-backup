<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_dzielo', 'Ten typ umowy');
$PAGE_TITLE = 'Umowy o dzieło';
$TYPE = 'dzielo';
$TABLE = 'umowy_dzielo';

// Filtry
$search  = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$page    = max(1, intval($_GET['page'] ?? 1));
$per     = 20;

$where = '1=1';
$params = [];
if ($search) { $where .= " AND (numer_umowy LIKE ? OR imie_nazwisko LIKE ? OR opis_dziela LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($status) { $where .= " AND status = ?"; $params[] = $status; }

$total = db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'];
$pag   = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query(array_filter(['q'=>$search,'status'=>$status])));
$rows  = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY created_at DESC LIMIT {$per} OFFSET {$pag['offset']}", $params);

$statuses = ['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-brush text-primary"></i> Umowy o dzieło
    <span class="badge bg-secondary ms-1"><?= $total ?></span>
  </h4>
  <?php if (can_edit()): ?>
  <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/add.php" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Nowa umowa
  </a>
  <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form class="row g-2" method="get">
      <div class="col-md-5"><input name="q" class="form-control" placeholder="Szukaj (numer, nazwisko, opis dzieła...)" value="<?= h($search) ?>"></div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">Wszystkie statusy</option>
          <?php foreach($statuses as $k=>$v): ?>
          <option value="<?= h($k) ?>" <?= $status===$k?'selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button></div>
      <?php if ($search||$status): ?><div class="col-auto"><a href="?" class="btn btn-outline-secondary">Wyczyść</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover contracts-table mb-0">
      <thead class="table-light">
        <tr>
          <th>Numer</th><th>Nr rejestru</th><th>Wykonawca</th><th>Opis dzieła</th>
          <th>Data zawarcia</th><th>Termin oddania</th><th>Brutto</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="fw-semibold"><?= h($r['numer_umowy']) ?></td>
          <td class="font-monospace small"><?= h($r['nr_rejestru'] ?? '') ?: '—' ?></td>
          <td><?= h($r['imie_nazwisko']) ?></td>
          <td class="text-truncate" style="max-width:200px"><?= h($r['opis_dziela']) ?></td>
          <td><?= date_pl($r['data_zawarcia']) ?></td>
          <td><?= date_pl($r['termin_oddania']) ?></td>
          <td><?= money($r['wynagrodzenie_brutto']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
            <?php if (can_edit()): ?>
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (is_admin() || (can_edit() && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
            <form method="post" action="<?= APP_URL ?>/contracts/delete.php" class="d-inline"
                  onsubmit="return confirm('Usunąć umowę <?= h($r['numer_umowy'] ?? '#'.$r['id']) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="type"  value="<?= $TYPE ?>">
              <input type="hidden" name="id"    value="<?= $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Brak umów</td></tr>
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
