<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$filter_status = $_GET['status'] ?? '';
$filter_q      = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filter_status && isset(EDOK_STATUSES[$filter_status])) {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}
if ($filter_q !== '') {
    $where[]  = '(number LIKE ? OR title LIKE ? OR kontrahent_nazwa LIKE ? OR nr_faktury LIKE ?)';
    $q = '%' . $filter_q . '%';
    array_push($params, $q, $q, $q, $q);
}

$docs = db_all(
    "SELECT * FROM edok_documents WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 200",
    $params
);
foreach ($docs as &$d) {
    $d['steps'] = [];
    foreach (db_all("SELECT step_key, status FROM edok_steps WHERE doc_id = ?", [$d['id']]) as $s) {
        $d['steps'][$s['step_key']] = $s['status'];
    }
}
unset($d);

$PAGE_TITLE = 'EODoK — Elektroniczny Obieg Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-journal-check"></i> EODoK — Elektroniczny Obieg Dokumentów Księgowych</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/transfers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-right"></i> Przelewy własne</a>
    <?php if (is_admin() || edok_has_role('upload')): ?>
    <a href="<?= APP_URL ?>/edok/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nowy dokument</a>
    <?php endif; ?>
  </div>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small mb-1">Status</label>
    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszystkie</option>
      <?php foreach (EDOK_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Szukaj</label>
    <input type="text" name="q" class="form-control form-control-sm" value="<?= h($filter_q) ?>" placeholder="numer, kontrahent, tytuł…">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Szukaj</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Numer</th>
        <th>Kontrahent</th>
        <th>Tytuł</th>
        <th class="text-end">Kwota brutto</th>
        <th>Status</th>
        <?php foreach (EDOK_STEPS as $sk => $sl): ?>
        <th class="text-center" title="<?= h($sl) ?>"><?= h(mb_substr($sl, 0, 1)) ?>.</th>
        <?php endforeach; ?>
        <th>Dodano</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$docs): ?>
      <tr><td colspan="12" class="text-center text-muted py-4">Brak dokumentów.</td></tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc): ?>
      <tr>
        <td><code><?= h($doc['number']) ?></code></td>
        <td><?= h($doc['kontrahent_nazwa']) ?></td>
        <td><?= h($doc['title']) ?></td>
        <td class="text-end font-monospace"><?= h($doc['kwota_brutto']) ?> <?= h($doc['waluta']) ?></td>
        <td><?= edok_status_badge($doc['status']) ?></td>
        <?php foreach (array_keys(EDOK_STEPS) as $sk): ?>
        <td class="text-center">
          <?php $st = $doc['steps'][$sk] ?? null; ?>
          <?php if ($st === 'ok'): ?>
          <i class="bi bi-check-circle-fill text-success" title="Zatwierdzone"></i>
          <?php elseif ($st === 'uwagi'): ?>
          <i class="bi bi-exclamation-circle-fill text-warning" title="Z uwagami"></i>
          <?php elseif ($st === 'odrzucono'): ?>
          <i class="bi bi-x-circle-fill text-danger" title="Odrzucono"></i>
          <?php else: ?>
          <i class="bi bi-circle text-muted" title="Oczekuje"></i>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
        <td><?= date_pl($doc['created_at']) ?></td>
        <td class="text-nowrap">
          <a href="<?= APP_URL ?>/edok/view.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
