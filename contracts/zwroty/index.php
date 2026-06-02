<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/zwroty_kosztow.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Zwroty kosztów wolontariatu';

$fm = new FinanceManager();

// Filtry
$filters = [
    'status' => $_GET['status'] ?? '',
    'q'      => trim($_GET['q'] ?? ''),
    'rok'    => (int)($_GET['rok'] ?? date('Y')),
];
$rows = $fm->listAll($filters);

// Statystyki ogólne
try {
    $stats = [];
    foreach (array_keys(FinanceManager::STATUSES) as $s) {
        $r = db_one("SELECT COUNT(*) AS c, COALESCE(SUM(kwota),0) AS suma FROM zwroty_kosztow WHERE status=? AND nr_rok=?", [$s, $filters['rok']]);
        $stats[$s] = ['c' => (int)$r['c'], 'suma' => (float)$r['suma']];
    }
} catch (\Throwable $e) { $stats = []; }

$lata = [];
try {
    $lata = array_column(db_all("SELECT DISTINCT nr_rok AS r FROM zwroty_kosztow ORDER BY r DESC"), 'r');
    if (!in_array((int)date('Y'), $lata)) array_unshift($lata, (int)date('Y'));
} catch (\Throwable $e) { $lata = [(int)date('Y')]; }

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <div class="rounded-3 p-2 bg-success bg-opacity-10 text-success">
    <i class="bi bi-receipt-cutoff fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Zwroty kosztów wolontariatu</h4>
    <div class="text-muted small">Rejestr wniosków o zwrot kosztów poniesionych przez wolontariuszy</div>
  </div>
  <div class="ms-auto">
    <a href="add.php" class="btn btn-success btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowy wniosek
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Karty statystyk ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $stat_cards = [
      ['oczekuje',    'bi-hourglass-split',  'Oczekuje'],
      ['weryfikacja', 'bi-search',            'Weryfikacja'],
      ['zatwierdzony','bi-check-circle',      'Zatwierdzone'],
      ['do_wyplaty',  'bi-send',              'Do wypłaty'],
      ['wyplacono',   'bi-cash-stack',        'Wypłacono'],
      ['odrzucony',   'bi-x-circle',          'Odrzucone'],
  ];
  foreach ($stat_cards as [$s, $icon, $lbl]):
      $cfg = FinanceManager::STATUSES[$s];
      $d = $stats[$s] ?? ['c'=>0,'suma'=>0];
  ?>
  <div class="col-md-4 col-lg-2">
    <a href="?status=<?= $s ?>&rok=<?= $filters['rok'] ?>"
       class="card shadow-sm text-decoration-none <?= $filters['status']===$s ? 'border-2 border-'.$cfg['color'] : '' ?>">
      <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi <?= $icon ?> text-<?= $cfg['color'] ?>"></i>
          <span class="small fw-semibold text-<?= $cfg['color'] ?>"><?= $lbl ?></span>
        </div>
        <div class="fw-bold fs-5 text-dark"><?= $d['c'] ?></div>
        <?php if ($d['suma']): ?>
        <div class="text-muted" style="font-size:.72rem"><?= number_format($d['suma'],2,',',' ') ?> PLN</div>
        <?php endif; ?>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Filtry ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-body py-2">
  <form method="get" class="row g-2 align-items-center">
    <div class="col-md-4">
      <input type="text" name="q" class="form-control form-control-sm"
             placeholder="Szukaj: nr wniosku, tytuł, wolontariusz…"
             value="<?= h($filters['q']) ?>">
    </div>
    <div class="col-md-2">
      <select name="status" class="form-select form-select-sm">
        <option value="">Wszystkie statusy</option>
        <?php foreach (FinanceManager::STATUSES as $k => $v): ?>
        <option value="<?= $k ?>" <?= $filters['status']===$k ? 'selected':'' ?>><?= h($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="rok" class="form-select form-select-sm">
        <?php foreach ($lata as $r): ?>
        <option value="<?= $r ?>" <?= $filters['rok']===$r?'selected':''?>><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
      <a href="index.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
    </div>
  </form>
</div>
</div>

<!-- ── Tabela wniosków ────────────────────────────────────────────────── -->
<?php if ($rows): ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0" style="font-size:.875rem">
  <thead class="table-light">
    <tr>
      <th>Numer wniosku</th>
      <th>Wolontariusz / Umowa</th>
      <th>Tytuł wydatku</th>
      <th class="text-end">Kwota</th>
      <th class="text-center">Data wydatku</th>
      <th class="text-center">Status</th>
      <th class="text-center">Złożony</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><?= zwroty_nr_html($row['nr_wniosku'] ?? '—') ?></td>
    <td>
      <div class="fw-semibold"><?= h($row['wolontariusz'] ?? '—') ?></div>
      <?php if ($row['numer_umowy']): ?>
      <div class="small text-muted">
        <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $row['umowa_id'] ?>"
           class="text-decoration-none text-muted">
          <?= h($row['numer_umowy']) ?>
        </a>
      </div>
      <?php endif; ?>
    </td>
    <td>
      <div><?= h($row['tytul']) ?></div>
      <?php if ($row['kategoria']): ?>
      <div class="small text-muted"><?= h(zwroty_kategoria_label($row['kategoria'])) ?></div>
      <?php endif; ?>
    </td>
    <td class="text-end fw-semibold text-nowrap">
      <?= number_format((float)$row['kwota'], 2, ',', ' ') ?> <?= h($row['waluta']) ?>
    </td>
    <td class="text-center small text-muted text-nowrap">
      <?= $row['data_wydatku'] ? date('d.m.Y', strtotime($row['data_wydatku'])) : '—' ?>
    </td>
    <td class="text-center"><?= zwroty_status_badge($row['status']) ?></td>
    <td class="text-center small text-muted text-nowrap">
      <?= $row['created_at'] ? date('d.m.Y', strtotime($row['created_at'])) : '—' ?>
    </td>
    <td class="text-end text-nowrap">
      <a href="view.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-eye"></i>
      </a>
      <?php if (is_admin()): ?>
      <?= delete_btn('zwroty_kosztow', (int)$row['id'], $row['nr_wniosku'] ?? $row['tytul'] ?? '#'.$row['id']) ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($rows): ?>
<div class="card-footer text-muted small d-flex justify-content-between">
  <span><?= count($rows) ?> wniosków</span>
  <span>Suma: <strong><?= number_format(array_sum(array_column($rows,'kwota')),2,',',' ') ?> PLN</strong></span>
</div>
<?php endif; ?>
</div>
<?php else: ?>
<div class="alert alert-secondary d-flex gap-3 align-items-center">
  <i class="bi bi-inbox fs-4 text-muted"></i>
  <div>
    Brak wniosków <?= $filters['status'] ? 'o statusie „'.h(FinanceManager::STATUSES[$filters['status']]['label'] ?? $filters['status']).'"' : '' ?>.
    <a href="add.php" class="ms-2">Złóż pierwszy wniosek</a>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
