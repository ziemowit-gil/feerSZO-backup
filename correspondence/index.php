<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/correspondence.php';

require_login();
require_module_enabled('correspondence_enabled', 'Moduł Korespondencja');

$PAGE_TITLE = 'Korespondencja';

$filters = [
    'direction' => $_GET['direction'] ?? '',
    'status'    => $_GET['status']    ?? '',
    'category'  => $_GET['category']  ?? '',
    'q'         => trim($_GET['q']    ?? ''),
    'date_from' => $_GET['date_from'] ?? '',
    'date_to'   => $_GET['date_to']   ?? '',
];

$items      = corr_get_all($filters);
$stats      = corr_stats();
$categories = corr_categories();
$ezd_enabled = module_enabled('ezd_enabled');

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-mailbox2 text-primary me-2"></i>Korespondencja</h4>
  <?php if (can_write('correspondence')): ?>
  <a href="<?= APP_URL ?>/correspondence/add.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nowa korespondencja
  </a>
  <?php endif; ?>
</div>

<!-- Karty statystyk -->
<div class="row g-3 mb-3">
  <?php
  $stat_cards = [
    ['Łącznie',       $stats['total'],                                                              'bg-primary',   'bi-mailbox2'],
    ['Przychodząca',  array_sum($stats['incoming'] ?? []),                                          'bg-success',   'bi-arrow-down-circle-fill'],
    ['Wychodząca',    array_sum($stats['outgoing'] ?? []),                                          'bg-info',      'bi-arrow-up-circle-fill'],
    ['Nowe/Otwarte',  ($stats['incoming']['new']??0)+($stats['outgoing']['new']??0),                'bg-warning',   'bi-envelope-open'],
    ['Zamknięte',     ($stats['incoming']['closed']??0)+($stats['outgoing']['closed']??0),          'bg-secondary', 'bi-envelope-check'],
  ];
  foreach ($stat_cards as [$label, $val, $bg, $icon]): ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card shadow-sm text-center py-2">
      <div class="card-body p-2">
        <div class="<?= $bg ?> bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-1"
             style="width:36px;height:36px">
          <i class="bi <?= $icon ?> text-<?= str_replace('bg-','', $bg) ?>"></i>
        </div>
        <div class="fw-bold"><?= $val ?></div>
        <div class="text-muted" style="font-size:.72rem"><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-sm-6 col-md-3">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Szukaj (temat, korespondent, numer…)" value="<?= h($filters['q']) ?>">
      </div>
      <div class="col-6 col-sm-4 col-md-2">
        <select name="direction" class="form-select form-select-sm">
          <option value="">Kierunek</option>
          <option value="incoming" <?= $filters['direction']==='incoming'?'selected':'' ?>>Przychodząca</option>
          <option value="outgoing" <?= $filters['direction']==='outgoing'?'selected':'' ?>>Wychodząca</option>
        </select>
      </div>
      <div class="col-6 col-sm-4 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">Status</option>
          <?php foreach (['new'=>'Nowa','in_progress'=>'W trakcie','replied'=>'Odpowiedziano','closed'=>'Zamknięta','archived'=>'Archiwum'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $filters['status']===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($categories): ?>
      <div class="col-6 col-sm-4 col-md-2">
        <select name="category" class="form-select form-select-sm">
          <option value="">Kategoria</option>
          <?php foreach ($categories as $c): ?>
          <option <?= $filters['category']===$c?'selected':'' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-sm-4 col-md-2">
        <input type="date" name="date_from" class="form-control form-control-sm"
               value="<?= h($filters['date_from']) ?>" title="Data od">
      </div>
      <div class="col-6 col-sm-4 col-md-2">
        <input type="date" name="date_to" class="form-control form-control-sm"
               value="<?= h($filters['date_to']) ?>" title="Data do">
      </div>
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
  <i class="bi bi-mailbox2 fs-1 d-block mb-2 opacity-25"></i>
  Brak korespondencji<?= array_filter($filters) ? ' spełniającej kryteria' : '' ?>.
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
  <table class="table table-hover mb-0 align-middle" style="font-size:.85rem">
    <thead class="table-light">
      <tr>
        <th style="width:36px"></th>
        <th>Numer</th>
        <th>Data</th>
        <th>Korespondent</th>
        <th>Temat</th>
        <th>Kategoria</th>
        <th>Status</th>
        <th>Prowadzi</th>
        <th style="width:36px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it):
      [$dlabel, $dicon, $dcolor] = corr_direction_label($it['direction']);
      [$slabel, $scolor] = corr_status_label($it['status']);
    ?>
    <tr class="cursor-pointer" onclick="location.href='<?= APP_URL ?>/correspondence/view.php?id=<?= $it['id'] ?>'">
      <td class="text-center">
        <i class="bi <?= $dicon ?> text-<?= $dcolor ?>" title="<?= $dlabel ?>"></i>
      </td>
      <td class="font-monospace text-muted" style="font-size:.78rem"><?= h($it['number'] ?: '—') ?></td>
      <td class="text-nowrap"><?= h(date_pl($it['date'])) ?></td>
      <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= h($it['correspondent']) ?>
      </td>
      <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <strong><?= h($it['subject']) ?></strong>
      </td>
      <td><?= $it['category'] ? '<span class="badge bg-light text-dark border">'.h($it['category']).'</span>' : '' ?></td>
      <td><span class="badge bg-<?= $scolor ?> bg-opacity-15 text-<?= $scolor ?> border border-<?= $scolor ?> border-opacity-25"><?= $slabel ?></span></td>
      <td class="text-muted" style="font-size:.78rem"><?= h($it['handler_name'] ?? '—') ?></td>
      <td class="text-nowrap">
        <?php if ($it['attachment']): ?>
        <a href="<?= APP_URL ?>/correspondence/download.php?id=<?= $it['id'] ?>"
           class="text-muted me-1" onclick="event.stopPropagation()" title="Pobierz załącznik">
          <i class="bi bi-paperclip"></i>
        </a>
        <?php endif; ?>
        <?php if ($ezd_enabled && !empty($it['ezd_pismo_id'])): ?>
        <i class="bi bi-building-gear text-success" title="Zarejestrowane w EZD"></i>
        <?php endif; ?>
      </td>
      <td class="text-end" onclick="event.stopPropagation()">
        <?php if (can_write('correspondence') && (is_admin() || (int)($it['created_by'] ?? 0) === (int)current_user()['id'])): ?>
        <?= delete_btn('correspondence', (int)$it['id'], $it['subject'] ?? '#'.$it['id']) ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<div class="text-muted small mt-2">Znaleziono: <strong><?= count($items) ?></strong> pozycji</div>
<?php endif; ?>

<style>.cursor-pointer { cursor:pointer; }</style>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
