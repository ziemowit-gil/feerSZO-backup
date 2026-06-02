<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resolutions.php';

require_login();
require_module_enabled('resolutions_enabled', 'Moduł Uchwały i Zarządzenia');

$PAGE_TITLE = 'Uchwały i Zarządzenia';

$filters = [
    'type'     => $_GET['type']     ?? '',
    'status'   => $_GET['status']   ?? '',
    'category' => $_GET['category'] ?? '',
    'year'     => $_GET['year']     ?? '',
    'q'        => trim($_GET['q']   ?? ''),
];

$items      = res_get_all($filters);
$stats      = res_stats();
$categories = res_categories();
$years      = res_years();

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-hammer text-primary me-2"></i>Uchwały i Zarządzenia</h4>
  <?php if (can_write('resolutions')): ?>
  <a href="<?= APP_URL ?>/resolutions/add.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nowy dokument
  </a>
  <?php endif; ?>
</div>

<!-- Karty statystyk per typ -->
<div class="row g-3 mb-3">
  <?php
  $types_meta = [
    'uchwala'     => ['Uchwały',      'bi-hammer',         'primary'],
    'zarzadzenie' => ['Zarządzenia',  'bi-person-gear',    'warning'],
    'decyzja'     => ['Decyzje',      'bi-clipboard-check','info'],
  ];
  $total = 0;
  foreach ($types_meta as $t => [$label, $icon, $color]):
    $cnt = array_sum($stats[$t] ?? []);
    $total += $cnt;
  ?>
  <div class="col-6 col-md-3">
    <a href="?type=<?= $t ?>" class="card shadow-sm text-decoration-none <?= $filters['type']===$t ? 'border-'.$color.' border-2' : '' ?>">
      <div class="card-body p-3 d-flex align-items-center gap-3">
        <div class="bg-<?= $color ?> bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:40px;height:40px">
          <i class="bi <?= $icon ?> text-<?= $color ?>"></i>
        </div>
        <div>
          <div class="fw-bold fs-5"><?= $cnt ?></div>
          <div class="text-muted" style="font-size:.75rem"><?= $label ?></div>
          <div style="font-size:.7rem">
            <?= $stats[$t]['active'] ?? 0 ?> aktywnych
            <?php if ($stats[$t]['draft'] ?? 0): ?>
            · <span class="text-warning"><?= $stats[$t]['draft'] ?> projektów</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
  <div class="col-6 col-md-3">
    <a href="?" class="card shadow-sm text-decoration-none <?= !$filters['type'] ? 'border-dark border-2' : '' ?>">
      <div class="card-body p-3 d-flex align-items-center gap-3">
        <div class="bg-dark bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:40px;height:40px">
          <i class="bi bi-collection text-dark"></i>
        </div>
        <div>
          <div class="fw-bold fs-5"><?= $total ?></div>
          <div class="text-muted" style="font-size:.75rem">Wszystkie</div>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-sm-6 col-md-4">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Szukaj (tytuł, numer, tagi…)" value="<?= h($filters['q']) ?>">
      </div>
      <div class="col-6 col-md-2">
        <select name="type" class="form-select form-select-sm">
          <option value="">Typ</option>
          <option value="uchwala"     <?= $filters['type']==='uchwala'    ?'selected':'' ?>>Uchwała</option>
          <option value="zarzadzenie" <?= $filters['type']==='zarzadzenie'?'selected':'' ?>>Zarządzenie</option>
          <option value="decyzja"     <?= $filters['type']==='decyzja'    ?'selected':'' ?>>Decyzja</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">Status</option>
          <option value="draft"    <?= $filters['status']==='draft'   ?'selected':'' ?>>Projekt</option>
          <option value="active"   <?= $filters['status']==='active'  ?'selected':'' ?>>Aktywna</option>
          <option value="archived" <?= $filters['status']==='archived'?'selected':'' ?>>Archiwum</option>
        </select>
      </div>
      <?php if ($years): ?>
      <div class="col-6 col-md-2">
        <select name="year" class="form-select form-select-sm">
          <option value="">Rok</option>
          <?php foreach ($years as $y): ?>
          <option <?= $filters['year']===$y?'selected':'' ?>><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($categories): ?>
      <div class="col-6 col-md-2">
        <select name="category" class="form-select form-select-sm">
          <option value="">Kategoria</option>
          <?php foreach ($categories as $c): ?>
          <option <?= $filters['category']===$c?'selected':'' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if (array_filter($filters)): ?>
        <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Lista -->
<?php if (!$items): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-folder2-open fs-1 d-block mb-2 opacity-25"></i>
  Brak dokumentów<?= array_filter($filters) ? ' spełniających kryteria' : '' ?>.
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
  <table class="table table-hover mb-0 align-middle" style="font-size:.85rem">
    <thead class="table-light">
      <tr>
        <th>Numer</th>
        <th>Data</th>
        <th>Typ</th>
        <th>Tytuł</th>
        <th>Kategoria</th>
        <th>Status</th>
        <th>Podpisał(a)</th>
        <th style="width:36px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $res):
      [$tlabel, $ticon, $tcolor] = res_type_label($res['type']);
      [$slabel, $scolor]         = res_status_label($res['status']);
    ?>
    <tr class="cursor-pointer" onclick="location.href='<?= APP_URL ?>/resolutions/view.php?id=<?= $res['id'] ?>'">
      <td class="font-monospace" style="font-size:.78rem;white-space:nowrap"><?= h($res['number'] ?: '—') ?></td>
      <td class="text-nowrap"><?= h(date_pl($res['date'])) ?></td>
      <td>
        <span class="badge bg-<?= $tcolor ?> bg-opacity-15 text-<?= $tcolor ?>">
          <i class="bi <?= $ticon ?> me-1"></i><?= $tlabel ?>
        </span>
      </td>
      <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <strong><?= h($res['title']) ?></strong>
        <?php if ($res['tags']): ?>
        <div style="font-size:.7rem;color:#94a3b8"><?= h($res['tags']) ?></div>
        <?php endif; ?>
      </td>
      <td><?= $res['category'] ? '<span class="badge bg-light text-dark border">'.h($res['category']).'</span>' : '' ?></td>
      <td><span class="badge bg-<?= $scolor ?> bg-opacity-15 text-<?= $scolor ?> border border-<?= $scolor ?> border-opacity-25"><?= $slabel ?></span></td>
      <td class="text-muted" style="font-size:.78rem"><?= h($res['signer_name'] ?? '—') ?></td>
      <td>
        <?php if ($res['attachment']): ?>
        <a href="<?= APP_URL ?>/resolutions/download.php?id=<?= $res['id'] ?>"
           class="text-muted" onclick="event.stopPropagation()" title="Pobierz">
          <i class="bi bi-paperclip"></i>
        </a>
        <?php endif; ?>
      </td>
      <td class="text-end text-nowrap" onclick="event.stopPropagation()">
        <?php if (can_write('resolutions') && (is_admin() || (int)($res['created_by'] ?? 0) === (int)current_user()['id'])): ?>
        <?= delete_btn('resolutions', (int)$res['id'], $res['number'] ?? $res['title'] ?? '#'.$res['id']) ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<div class="text-muted small mt-2">Znaleziono: <strong><?= count($items) ?></strong> dokumentów</div>
<?php endif; ?>

<style>.cursor-pointer { cursor:pointer; }</style>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
