<?php
/**
 * resources/my.php — Moje rezerwacje (panel wolontariusza).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resources.php';

require_login();
ika_require();
resources_migrate();

$PAGE_TITLE = 'Moje rezerwacje';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $res_id = (int)($_POST['reservation_id'] ?? 0);
    if ($action === 'cancel' && $res_id) {
        $r = res_reservation_get($res_id);
        if ($r && (int)$r['user_id'] === (int)$user['id'] && !in_array($r['status'], ['odmowa','anulowana','rezerwacja'])) {
            res_change_status($res_id, 'anulowana', 'Anulowana przez wnioskodawcę');
            flash_set('success', 'Rezerwacja anulowana.');
        }
    }
    header('Location: ' . APP_URL . '/resources/my.php');
    exit;
}

$reservations = res_reservations_for_user((int)$user['id']);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-list-check text-primary me-1"></i>Moje rezerwacje</h4>
  <a href="<?= APP_URL ?>/resources/" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-plus-lg me-1"></i>Nowa rezerwacja
  </a>
</div>

<?= flash_html() ?>

<?php if (!$reservations): ?>
<div class="alert alert-info">Nie masz jeszcze żadnych rezerwacji. <a href="<?= APP_URL ?>/resources/">Zarezerwuj zasób</a>.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.88rem">
      <thead class="table-light">
        <tr>
          <th>Zasób</th>
          <th>Termin</th>
          <th>Cel</th>
          <th>Status</th>
          <th>Złożono</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reservations as $r): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <i class="bi <?= h($r['cat_icon'] ?? 'bi-box') ?>"
                 style="color:<?= h($r['cat_color'] ?? '#666') ?>"></i>
              <span class="fw-semibold"><?= h($r['res_name']) ?></span>
            </div>
          </td>
          <td class="text-nowrap">
            <?= h($r['date_from']) ?>
            <?php if ($r['date_to'] !== $r['date_from']): ?> – <?= h($r['date_to']) ?><?php endif; ?>
            <?php if ($r['time_from']): ?>
            <div class="text-muted small"><?= h($r['time_from']) ?><?= $r['time_to'] ? ' – ' . h($r['time_to']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td style="max-width:200px">
            <span class="text-truncate d-block" style="max-width:180px"><?= h($r['purpose']) ?></span>
          </td>
          <td><?= res_status_badge($r['status']) ?></td>
          <td class="text-muted"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/resources/view.php?id=<?= (int)$r['id'] ?>"
               class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 me-1">
              <i class="bi bi-eye"></i>
            </a>
            <?php if (!in_array($r['status'], ['odmowa','anulowana','rezerwacja'])): ?>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Anulować tę rezerwację?')">
              <input type="hidden" name="_csrf"           value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action"         value="cancel">
              <input type="hidden" name="reservation_id"  value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2">
                <i class="bi bi-x-lg"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
.res-status-badge { display:inline-block; padding:.18em .55em; border-radius:6px; font-size:.75rem; font-weight:600; }
</style>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
