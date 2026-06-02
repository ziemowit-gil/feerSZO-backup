<?php
/**
 * resources/index.php — Lista zasobów do rezerwacji (panel wolontariusza/pracownika).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resources.php';

require_login();
resources_migrate();

$PAGE_TITLE = 'Rezerwacja zasobów';
$user       = current_user();
$cat_filter = (int)($_GET['cat'] ?? 0);
$categories = res_categories();
$resources  = res_list($cat_filter);

// Oczekujące rezerwacje bieżącego użytkownika
$my_pending = array_filter(
    res_reservations_for_user((int)$user['id']),
    fn($r) => !in_array($r['status'], ['odmowa','anulowana','rezerwacja'])
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-calendar-check-fill text-primary me-1"></i>Rezerwacja zasobów</h4>
  <a href="<?= APP_URL ?>/resources/my.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-list-check me-1"></i>Moje rezerwacje
    <?php if ($my_pending): ?>
    <span class="badge bg-warning text-dark ms-1"><?= count($my_pending) ?></span>
    <?php endif; ?>
  </a>
</div>

<!-- Filtr kategorii -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <a href="?" class="btn btn-sm <?= !$cat_filter ? 'btn-primary' : 'btn-outline-secondary' ?>">
    <i class="bi bi-grid me-1"></i>Wszystkie
  </a>
  <?php foreach ($categories as $cat): ?>
  <a href="?cat=<?= (int)$cat['id'] ?>"
     class="btn btn-sm <?= $cat_filter === (int)$cat['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
    <i class="bi <?= h($cat['icon']) ?> me-1"></i><?= h($cat['name']) ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$resources): ?>
<div class="alert alert-info">Brak dostępnych zasobów do rezerwacji.</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($resources as $r): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex flex-column gap-2">
        <div class="d-flex align-items-start gap-2">
          <div class="flex-shrink-0 rounded d-flex align-items-center justify-content-center"
               style="width:40px;height:40px;background:<?= h($r['cat_color'] ?? '#6366f1') ?>18;color:<?= h($r['cat_color'] ?? '#6366f1') ?>;font-size:1.2rem">
            <i class="bi <?= h($r['cat_icon'] ?? 'bi-box') ?>"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= h($r['name']) ?></div>
            <div class="text-muted small"><?= h($r['cat_name'] ?? '') ?></div>
          </div>
          <?php if ($r['requires_approval']): ?>
          <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                style="font-size:.68rem">Wymaga zgody</span>
          <?php else: ?>
          <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"
                style="font-size:.68rem">Bezpośrednio</span>
          <?php endif; ?>
        </div>

        <?php if ($r['description']): ?>
        <p class="text-muted small mb-0"><?= nl2br(h($r['description'])) ?></p>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap mt-auto" style="font-size:.78rem">
          <?php if ($r['location']): ?>
          <span class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= h($r['location']) ?></span>
          <?php endif; ?>
          <?php if ($r['capacity']): ?>
          <span class="text-muted"><i class="bi bi-people me-1"></i><?= (int)$r['capacity'] ?> os.</span>
          <?php endif; ?>
          <?php if ($r['dysponent_name']): ?>
          <span class="text-muted"><i class="bi bi-person-check me-1"></i><?= h($r['dysponent_name']) ?></span>
          <?php endif; ?>
        </div>

        <a href="<?= APP_URL ?>/resources/reserve.php?id=<?= (int)$r['id'] ?>"
           class="btn btn-sm btn-primary mt-1">
          <i class="bi bi-calendar-plus me-1"></i>Zarezerwuj
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
