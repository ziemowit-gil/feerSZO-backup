<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
kdok_migrate();

$PAGE_TITLE = 'EOD Dokumentów Księgowych';

// Filtry
$filter_type    = $_GET['type']    ?? '';
$filter_status  = $_GET['status']  ?? '';
$filter_q       = trim($_GET['q']  ?? '');
$filter_miesiac = (int)($_GET['miesiac'] ?? 0);
$filter_rok     = (int)($_GET['rok']     ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 20;

$where = ['1=1'];
$params = [];

if ($filter_type) {
    $where[] = 'type = ?';
    $params[] = $filter_type;
}
if ($filter_status) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}
if ($filter_q) {
    $where[] = '(title LIKE ? OR number LIKE ? OR description LIKE ?)';
    $params[] = "%$filter_q%";
    $params[] = "%$filter_q%";
    $params[] = "%$filter_q%";
}
if ($filter_miesiac && $filter_rok) {
    $where[] = '((miesiac IS NOT NULL AND rok IS NOT NULL AND miesiac = ? AND rok = ?) OR (miesiac IS NULL AND CAST(SUBSTR(created_at,6,2) AS INTEGER) = ? AND CAST(SUBSTR(created_at,1,4) AS INTEGER) = ?))';
    $params[] = $filter_miesiac; $params[] = $filter_rok;
    $params[] = $filter_miesiac; $params[] = $filter_rok;
}

$where_sql = implode(' AND ', $where);

$total = (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_documents WHERE $where_sql", $params)['c'] ?? 0);
$pag   = paginate($total, $per_page, $page, '?');
$docs  = kdok_all(
    "SELECT * FROM kdok_documents WHERE $where_sql ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $pag['offset']])
);

// Powiąż kroki
foreach ($docs as &$doc) {
    $steps = kdok_all(
        "SELECT step_type, status FROM kdok_steps WHERE doc_id = ?",
        [$doc['id']]
    );
    $doc['steps'] = array_column($steps, 'status', 'step_type');
}
unset($doc);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-file-earmark-check"></i> EOD Dokumentów Księgowych</h4>
  <div class="d-flex gap-2">
    <?php if (is_admin() || kdok_has_role('zatwierdza')): ?>
    <a href="<?= APP_URL ?>/ksiegowosc/zip.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-file-zip"></i> Pobierz ZIP miesiąca
    </a>
    <?php endif; ?>
    <?php if (kdok_has_role('upload')): ?>
    <a href="<?= APP_URL ?>/ksiegowosc/add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg"></i> Nowy dokument
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<?php
$months_pl = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
$years_range = range((int)date('Y') - 3, (int)date('Y') + 1);
?>
<form method="get" class="row g-2 mb-3">
  <div class="col-sm-3">
    <select name="type" class="form-select form-select-sm">
      <option value="">Wszystkie typy</option>
      <?php foreach (KDOK_TYPES as $k => $t): ?>
      <option value="<?= h($k) ?>" <?= $filter_type === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach (KDOK_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <select name="miesiac" class="form-select form-select-sm">
      <option value="">Miesiąc</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $filter_miesiac === $m ? 'selected' : '' ?>><?= $months_pl[$m] ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="col-sm-1">
    <select name="rok" class="form-select form-select-sm">
      <option value="">Rok</option>
      <?php foreach ($years_range as $yr): ?>
      <option value="<?= $yr ?>" <?= $filter_rok === $yr ? 'selected' : '' ?>><?= $yr ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Szukaj…" value="<?= h($filter_q) ?>">
  </div>
  <div class="col-auto d-flex gap-1 align-items-center flex-wrap">
    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i></button>
    <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
    <?php if ($filter_miesiac && $filter_rok && (is_admin() || kdok_has_role('zatwierdza'))): ?>
    <a href="<?= APP_URL ?>/ksiegowosc/zip.php?miesiac=<?= $filter_miesiac ?>&rok=<?= $filter_rok ?>"
       class="btn btn-sm btn-outline-success" title="Pobierz ZIP PDF zaakceptowanych z <?= $months_pl[$filter_miesiac] ?> <?= $filter_rok ?>">
      <i class="bi bi-file-zip"></i> ZIP <?= $months_pl[$filter_miesiac] ?> <?= $filter_rok ?>
    </a>
    <?php endif; ?>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle small">
      <thead class="table-light">
        <tr>
          <th>Numer</th>
          <th>Typ</th>
          <th>Tytuł</th>
          <th>Status</th>
          <th class="text-center">Mer.</th>
          <th class="text-center">Fml.</th>
          <th class="text-center">Wyp.</th>
          <th>Dodano</th>
          <th>Dodał</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$docs): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Brak dokumentów.</td></tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc): ?>
        <tr>
          <td><code><?= h($doc['number']) ?></code></td>
          <td><i class="<?= h(KDOK_TYPES[$doc['type']]['icon'] ?? 'bi-file') ?>"></i> <?= h(KDOK_TYPES[$doc['type']]['label'] ?? $doc['type']) ?></td>
          <td><?= h($doc['title']) ?></td>
          <td><?= kdok_status_badge($doc['status']) ?></td>
          <?php foreach (['meryt','formal','zatwierdza'] as $step): ?>
          <td class="text-center">
            <?php $s = $doc['steps'][$step] ?? 'oczekuje'; ?>
            <?php if ($s === 'ok'): ?>
              <i class="bi bi-check-circle-fill text-success" title="Zatwierdzone"></i>
            <?php elseif ($s === 'uwagi'): ?>
              <i class="bi bi-exclamation-circle-fill text-warning" title="Z uwagami"></i>
            <?php else: ?>
              <i class="bi bi-circle text-muted" title="Oczekuje"></i>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td><?= date_pl($doc['created_at']) ?></td>
          <td><?= h($doc['creator_name']) ?></td>
          <td>
            <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?= pagination_html($pag, '?type=' . urlencode($filter_type) . '&status=' . urlencode($filter_status) . '&q=' . urlencode($filter_q) . '&miesiac=' . $filter_miesiac . '&rok=' . $filter_rok . '&') ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
