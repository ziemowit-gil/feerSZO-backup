<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';

require_role('admin');
$PAGE_TITLE = 'Serwisy IT';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $name = trim($_POST['name'] ?? '');
        if (!$slug || !$name) { flash_set('danger','Slug i nazwa są wymagane.'); }
        else {
            db_insert('it_services', [
                'slug'       => $slug,
                'name'       => $name,
                'icon'       => trim($_POST['icon'] ?? 'bi-server'),
                'color'      => trim($_POST['color'] ?? '#fd7e14'),
                'is_active'  => 1,
                'sort_order' => (int)($_POST['sort_order'] ?? 99),
                'notes'      => trim($_POST['notes'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', "Serwis \"{$name}\" dodany.");
        }
    } elseif ($action === 'toggle') {
        $sid = intval($_POST['id']);
        $cur = db_one("SELECT is_active FROM it_services WHERE id=?", [$sid]);
        if ($cur) {
            db_update('it_services', ['is_active' => $cur['is_active'] ? 0 : 1], $sid);
            flash_set('success', 'Status serwisu zaktualizowany.');
        }
    } elseif ($action === 'update') {
        $sid = intval($_POST['id']);
        db_update('it_services', [
            'name'       => trim($_POST['name'] ?? ''),
            'icon'       => trim($_POST['icon'] ?? 'bi-server'),
            'color'      => trim($_POST['color'] ?? '#fd7e14'),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'notes'      => trim($_POST['notes'] ?? '') ?: null,
        ], $sid);
        flash_set('success', 'Serwis zaktualizowany.');
    }

    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

$services = db_all("SELECT s.*, (SELECT COUNT(*) FROM it_accounts a WHERE a.service_id=s.id) AS account_count FROM it_services s ORDER BY sort_order, name");

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4" style="max-width:800px">

  <div class="d-flex align-items-center gap-3 mb-3">
    <div style="width:40px;height:40px;border-radius:10px;background:#fff3e0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fd7e14;flex-shrink:0">
      <i class="bi bi-gear-fill"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Serwisy IT</h1>
      <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/it/index.php">IT</a></li>
        <li class="breadcrumb-item active">Serwisy</li>
      </ol></nav>
    </div>
  </div>

  <?= flash_get() ?>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 fw-semibold">Zdefiniowane serwisy</div>
    <div class="table-responsive">
      <table class="table mb-0 small">
        <thead class="table-light">
          <tr><th>Ikona</th><th>Nazwa</th><th>Slug</th><th>Kolejność</th><th>Kont</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($services as $s): ?>
          <tr>
            <td><i class="bi <?= h($s['icon']) ?>" style="color:<?= h($s['color']) ?>;font-size:1.1rem"></i></td>
            <td>
              <form method="post" class="d-inline" id="edit-svc-<?= $s['id'] ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              </form>
              <span class="fw-semibold"><?= h($s['name']) ?></span>
              <?php if ($s['notes']): ?><div class="text-muted" style="font-size:.72rem"><?= h($s['notes']) ?></div><?php endif; ?>
            </td>
            <td class="font-monospace text-muted"><?= h($s['slug']) ?></td>
            <td class="text-muted"><?= $s['sort_order'] ?></td>
            <td><span class="badge bg-secondary"><?= $s['account_count'] ?></span></td>
            <td>
              <?php if ($s['is_active']): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">aktywny</span>
              <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border">nieaktywny</span>
              <?php endif; ?>
            </td>
            <td class="d-flex gap-1">
              <button type="button" class="btn btn-xs btn-outline-secondary"
                      style="font-size:.7rem;padding:2px 8px"
                      data-bs-toggle="modal" data-bs-target="#edit-modal-<?= $s['id'] ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button class="btn btn-xs <?= $s['is_active'] ? 'btn-outline-warning':'btn-outline-success' ?>"
                        style="font-size:.7rem;padding:2px 8px">
                  <?= $s['is_active'] ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
                </button>
              </form>
            </td>
          </tr>

          <!-- Edit modal -->
          <div class="modal fade" id="edit-modal-<?= $s['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-sm">
              <form method="post" class="modal-content">
                <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                <div class="modal-header py-2"><h6 class="modal-title">Edytuj: <?= h($s['name']) ?></h6>
                  <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div class="mb-2"><label class="form-label small mb-1">Nazwa</label>
                    <input type="text" name="name" value="<?= h($s['name']) ?>" class="form-control form-control-sm" required></div>
                  <div class="mb-2"><label class="form-label small mb-1">Ikona Bootstrap</label>
                    <input type="text" name="icon" value="<?= h($s['icon']) ?>" class="form-control form-control-sm font-monospace"></div>
                  <div class="mb-2"><label class="form-label small mb-1">Kolor</label>
                    <input type="color" name="color" value="<?= h($s['color']) ?>" class="form-control form-control-sm form-control-color"></div>
                  <div class="mb-2"><label class="form-label small mb-1">Kolejność</label>
                    <input type="number" name="sort_order" value="<?= $s['sort_order'] ?>" class="form-control form-control-sm"></div>
                  <div class="mb-2"><label class="form-label small mb-1">Notatka</label>
                    <input type="text" name="notes" value="<?= h($s['notes'] ?? '') ?>" class="form-control form-control-sm"></div>
                </div>
                <div class="modal-footer py-2">
                  <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                  <button type="submit" class="btn btn-sm btn-warning">Zapisz</button>
                </div>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Dodaj serwis -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-2 fw-semibold">Dodaj nowy serwis</div>
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div class="col-md-2">
          <label class="form-label small mb-1">Slug <span class="text-danger">*</span></label>
          <input type="text" name="slug" class="form-control form-control-sm font-monospace" placeholder="np. slack" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1">Nazwa <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control form-control-sm" placeholder="np. Slack" required>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1">Ikona Bootstrap</label>
          <input type="text" name="icon" value="bi-server" class="form-control form-control-sm font-monospace">
        </div>
        <div class="col-md-1">
          <label class="form-label small mb-1">Kolor</label>
          <input type="color" name="color" value="#fd7e14" class="form-control form-control-sm form-control-color">
        </div>
        <div class="col-md-1">
          <label class="form-label small mb-1">Kolejność</label>
          <input type="number" name="sort_order" value="99" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-sm btn-warning">
            <i class="bi bi-plus-circle me-1"></i> Dodaj
          </button>
        </div>
      </form>
    </div>
  </div>

</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
