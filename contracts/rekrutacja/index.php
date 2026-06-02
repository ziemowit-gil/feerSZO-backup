<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Rekrutacja wolontariuszy';

$rm = new VolunteerModuleManager();

// Filtry
$filters = [
    'status' => $_GET['status'] ?? '',
    'q'      => trim($_GET['q'] ?? ''),
];

// Paginacja
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$total    = $rm->countOffers($filters['status'], $filters['q']);
$pag      = paginate($total, $per_page, $page, 'index.php?' . http_build_query(array_diff_key($_GET, ['page' => 0])));
$offers   = $rm->listOffers($filters['status'], $filters['q'], $per_page, $pag['offset']);

// Statystyki per status
$stats = [];
foreach (array_keys(VolunteerModuleManager::OFFER_STATUSES) as $s) {
    $r = db_one("SELECT COUNT(*) AS c FROM volunteer_offers WHERE status = ?", [$s]);
    $stats[$s] = (int)($r['c'] ?? 0);
}
$stats_apps = [];
foreach (array_keys(VolunteerModuleManager::OFFER_STATUSES) as $s) {
    $r = db_one(
        "SELECT COUNT(*) AS c FROM volunteer_applications a
         JOIN volunteer_offers o ON o.id = a.volunteer_offer_id WHERE o.status = ?", [$s]
    );
    $stats_apps[$s] = (int)($r['c'] ?? 0);
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <div class="rounded-3 p-2 bg-primary bg-opacity-10 text-primary">
    <i class="bi bi-person-plus-fill fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Rekrutacja wolontariuszy</h4>
    <div class="text-muted small">Ogłoszenia i zgłoszenia kandydatów na wolontariuszy</div>
  </div>
  <div class="ms-auto">
    <a href="add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowe ogłoszenie
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Karty statystyk ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $stat_cards = [
      ['draft',  'bi-pencil-square', 'Szkice'],
      ['active', 'bi-megaphone',     'Aktywne'],
      ['closed', 'bi-archive',       'Zamknięte'],
  ];
  foreach ($stat_cards as [$s, $icon, $lbl]):
      $cfg = VolunteerModuleManager::OFFER_STATUSES[$s];
      $active_cls = $filters['status'] === $s ? 'border-2 border-' . $cfg['color'] : '';
  ?>
  <div class="col-md-4">
    <a href="?status=<?= $s ?><?= $filters['q'] ? '&q=' . urlencode($filters['q']) : '' ?>"
       class="card shadow-sm text-decoration-none <?= $active_cls ?>">
      <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi <?= $icon ?> text-<?= $cfg['color'] ?>"></i>
          <span class="small fw-semibold text-<?= $cfg['color'] ?>"><?= $lbl ?></span>
        </div>
        <div class="fw-bold fs-5 text-dark"><?= $stats[$s] ?> ogłoszeń</div>
        <div class="text-muted" style="font-size:.72rem"><?= $stats_apps[$s] ?> zgłoszeń</div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Filtry ──────────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-body py-2">
  <form method="get" class="row g-2 align-items-center">
    <div class="col-md-5">
      <input type="text" name="q" class="form-control form-control-sm"
             placeholder="Szukaj ogłoszenia…"
             value="<?= h($filters['q']) ?>">
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select form-select-sm">
        <option value="">Wszystkie statusy</option>
        <?php foreach (VolunteerModuleManager::OFFER_STATUSES as $s => $cfg): ?>
        <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>>
          <?= h($cfg['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-search me-1"></i>Filtruj
      </button>
      <?php if ($filters['status'] || $filters['q']): ?>
      <a href="index.php" class="btn btn-sm btn-outline-secondary ms-1">
        <i class="bi bi-x-lg"></i>
      </a>
      <?php endif; ?>
    </div>
  </form>
</div>
</div>

<!-- ── Lista ogłoszeń ─────────────────────────────────────────────────────── -->
<?php if (!$offers): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-inbox fs-1 d-block mb-2"></i>
  Brak ogłoszeń<?= $filters['status'] || $filters['q'] ? ' spełniających kryteria' : '' ?>.
  <?php if (!$filters['status'] && !$filters['q']): ?>
  <div class="mt-2">
    <a href="add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Utwórz pierwsze ogłoszenie
    </a>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Ogłoszenie</th>
      <th class="text-center">Status</th>
      <th class="text-center">Zgłoszenia</th>
      <th class="text-center d-none d-md-table-cell">Nowe</th>
      <th class="text-center d-none d-lg-table-cell">Limit</th>
      <th class="text-center d-none d-lg-table-cell">Opublikowane</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($offers as $o): ?>
    <tr>
      <td>
        <div class="fw-semibold">
          <a href="view.php?id=<?= $o['id'] ?>" class="text-decoration-none text-dark">
            <?= h($o['title']) ?>
          </a>
        </div>
        <?php if ($o['content']): ?>
        <div class="text-muted small text-truncate" style="max-width:35ch">
          <?= h(strip_tags(mb_substr($o['content'], 0, 120))) ?>
        </div>
        <?php endif; ?>
      </td>
      <td class="text-center"><?= rekrutacja_offer_badge($o['status']) ?></td>
      <td class="text-center fw-bold"><?= (int)$o['app_count'] ?></td>
      <td class="text-center d-none d-md-table-cell">
        <?php if ((int)$o['new_count'] > 0): ?>
        <span class="badge bg-primary"><?= (int)$o['new_count'] ?></span>
        <?php else: ?>
        <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td class="text-center d-none d-lg-table-cell">
        <?= $o['max_candidates'] ? h($o['max_candidates']) : '<span class="text-muted">—</span>' ?>
      </td>
      <td class="text-center d-none d-lg-table-cell">
        <span class="text-muted small">
          <?= $o['published_at'] ? date_pl($o['published_at']) : '—' ?>
        </span>
      </td>
      <td class="text-end">
        <a href="view.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-eye me-1"></i>Szczegóły
        </a>
        <a href="add.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-secondary ms-1">
          <i class="bi bi-pencil"></i>
        </a>
        <?php if (is_admin() || (can_edit() && (int)($o['created_by'] ?? 0) === (int)current_user()['id'])): ?>
        <?= delete_btn('volunteer_offers', (int)$o['id'], $o['title'] ?? '#'.$o['id']) ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>

<?php if ($pag['pages'] > 1): ?>
<div class="mt-3 d-flex justify-content-end">
  <?= pagination_html($pag) ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
