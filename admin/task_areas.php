<?php
/**
 * admin/task_areas.php — Zarządzanie Obszarami zadań.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_role('admin');
task_areas_migrate();

$PAGE_TITLE = 'Obszary zadań';

$errors  = [];
$success = '';

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'add') {
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#6c757d');
        $icon  = trim($_POST['icon']  ?? 'bi-layers');
        if (!$name) {
            $errors[] = 'Nazwa obszaru jest wymagana.';
        } else {
            db_insert('task_areas', [
                'name'       => $name,
                'color'      => $color,
                'icon'       => $icon,
                'created_by' => (int)current_user()['id'],
            ]);
            $success = 'Obszar "' . $name . '" dodany.';
        }
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = db_one("SELECT is_active FROM task_areas WHERE id=?", [$id]);
            if ($row) {
                $new = $row['is_active'] ? 0 : 1;
                db()->prepare("UPDATE task_areas SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$new, $id]);
                $success = 'Status zaktualizowany.';
            }
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("UPDATE tasks SET area_id=NULL WHERE area_id=?")->execute([$id]);
            db()->prepare("DELETE FROM task_areas WHERE id=?")->execute([$id]);
            $success = 'Obszar usunięty.';
        }
    }

    header('Location: ' . APP_URL . '/admin/task_areas.php' . ($success ? '?ok=1' : ''));
    exit;
}

if (!empty($_GET['ok'])) $success = 'Zapisano.';

$areas = db_all("SELECT * FROM task_areas ORDER BY name");

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Obszary zadań</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-layers me-2 text-primary"></i>Obszary zadań</h4>
</div>

<?php if ($success): ?>
<div class="alert alert-success py-2"><?= h($success) ?></div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
<div class="alert alert-danger py-2"><?= h($e) ?></div>
<?php endforeach; ?>

<!-- Dodaj nowy -->
<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold py-2">Nowy obszar</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="add">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold">Nazwa <span class="text-danger">*</span></label>
        <input name="name" class="form-control form-control-sm" placeholder="np. Fundraising" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold">Kolor</label>
        <input type="color" name="color" class="form-control form-control-sm form-control-color" value="#4f46e5">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold">Ikona Bootstrap Icons</label>
        <input name="icon" class="form-control form-control-sm" placeholder="bi-layers" value="bi-layers">
      </div>
      <div class="col-sm-2">
        <button class="btn btn-primary btn-sm w-100">
          <i class="bi bi-plus-lg me-1"></i>Dodaj
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Lista -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold py-2">Zdefiniowane obszary (<?= count($areas) ?>)</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Obszar</th>
          <th>Ikona</th>
          <th>Zadań</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$areas): ?>
        <tr><td colspan="5" class="text-muted text-center py-4">Brak obszarów.</td></tr>
        <?php endif; ?>
        <?php foreach ($areas as $a): ?>
        <?php $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM tasks WHERE area_id=? AND deleted_at IS NULL", [$a['id']])['c'] ?? 0); ?>
        <tr>
          <td>
            <span class="badge" style="background:<?= h($a['color']) ?>">
              <i class="bi <?= h($a['icon']) ?> me-1"></i><?= h($a['name']) ?>
            </span>
          </td>
          <td><code class="small"><?= h($a['icon']) ?></code></td>
          <td><?= $cnt ?></td>
          <td>
            <?= $a['is_active']
              ? '<span class="badge bg-success">Aktywny</span>'
              : '<span class="badge bg-secondary">Nieaktywny</span>' ?>
          </td>
          <td class="text-end">
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="toggle">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button class="btn btn-outline-secondary btn-xs btn-sm py-0 px-2" title="Włącz/wyłącz">
                <i class="bi bi-toggle-<?= $a['is_active'] ? 'on text-success' : 'off' ?>"></i>
              </button>
            </form>
            <?php if ($cnt === 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć obszar?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button class="btn btn-outline-danger btn-sm py-0 px-2">
                <i class="bi bi-trash3"></i>
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

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
