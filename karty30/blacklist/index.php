<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Czarna lista — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add' && $can_write) {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');
        if ($client_id) {
            try {
                db()->prepare("INSERT OR IGNORE INTO k30_blacklist (client_id, reason, added_by) VALUES (?,?,?)")
                    ->execute([$client_id, $reason ?: null, current_user()['id'] ?? null]);
                flash_set('success', 'Beneficjent dodany do czarnej listy.');
            } catch (\Exception $e) {
                flash_set('danger', 'Błąd: ' . $e->getMessage());
            }
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'remove' && $can_write) {
        $bl_id = (int)($_POST['bl_id'] ?? 0);
        db()->prepare("DELETE FROM k30_blacklist WHERE id=?")->execute([$bl_id]);
        flash_set('success', 'Wpis usunięty z czarnej listy.');
        header('Location: index.php');
        exit;
    }
}

$rows = db_all(
    "SELECT bl.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
            u.name AS added_by_name
     FROM k30_blacklist bl
     LEFT JOIN k30_clients c ON c.id=bl.client_id
     LEFT JOIN users u ON u.id=bl.added_by
     ORDER BY bl.created_at DESC"
);

// Clients not on blacklist for add form
$not_blacklisted = db_all(
    "SELECT id, name FROM k30_clients WHERE id NOT IN (SELECT client_id FROM k30_blacklist) ORDER BY name"
);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item active">Czarna lista</li>
  </ol>
</nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-slash-circle text-danger me-2"></i>Czarna lista</h4>
    <div class="text-muted small"><?= count($rows) ?> wpis(ów)</div>
  </div>
</div>

<?php if ($can_write && $not_blacklisted): ?>
<div class="card shadow-sm mb-4" style="max-width:500px">
  <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-plus-circle me-1"></i>Dodaj do czarnej listy</div>
  <div class="card-body">
    <form method="post" class="d-flex flex-column gap-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="add">
      <select name="client_id" class="form-select form-select-sm" required>
        <option value="">— Wybierz beneficjenta —</option>
        <?php foreach ($not_blacklisted as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="reason" class="form-control form-control-sm" placeholder="Powód (opcjonalnie)">
      <button type="submit" class="btn btn-danger btn-sm align-self-start"><i class="bi bi-slash-circle me-1"></i>Dodaj</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Beneficjent</th>
          <th class="d-none d-md-table-cell">Powód</th>
          <th class="d-none d-sm-table-cell">Dodał</th>
          <th class="d-none d-lg-table-cell">Data</th>
          <?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$r['client_id'] ?>" class="text-decoration-none fw-semibold"><?= h($r['client_name']) ?></a>
            <div class="text-muted" style="font-size:.75rem"><?= $r['client_email'] ? h($r['client_email']) : '' ?> <?= $r['client_phone'] ? '· '.h($r['client_phone']) : '' ?></div>
          </td>
          <td class="d-none d-md-table-cell"><?= $r['reason'] ? h($r['reason']) : '<span class="text-muted">—</span>' ?></td>
          <td class="d-none d-sm-table-cell"><?= $r['added_by_name'] ? h($r['added_by_name']) : '—' ?></td>
          <td class="d-none d-lg-table-cell"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
          <?php if ($can_write): ?>
          <td class="text-end">
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="remove">
              <input type="hidden" name="bl_id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                      onclick="return confirm('Usunąć z czarnej listy?')">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Czarna lista jest pusta.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
