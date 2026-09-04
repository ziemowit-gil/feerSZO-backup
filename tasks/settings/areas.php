<?php
/**
 * tasks/settings/areas.php
 * Zarządzanie obszarami zadań — dostępne dla adminów systemu.
 * Przeniesione z admin/task_areas.php do modułu Zadania.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid       = (int)(current_user()['id'] ?? 0);
$sys_admin = is_admin();

if (!$sys_admin) {
    flash_set('error', 'Zarządzanie obszarami wymaga uprawnień administratora.');
    header('Location: ' . APP_URL . '/tasks/dashboard.php'); exit;
}

task_areas_migrate();

$SELF = APP_URL . '/tasks/settings/areas.php';

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'add') {
        $name  = trim($_POST['name']  ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#4f46e5';
        $icon  = preg_replace('/[^a-z0-9\-]/', '', $_POST['icon'] ?? '') ?: 'bi-layers';

        if (!$name) {
            flash_set('error', 'Nazwa obszaru jest wymagana.');
        } else {
            db_insert('task_areas', [
                'name'       => $name,
                'color'      => $color,
                'icon'       => $icon,
                'created_by' => $uid,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', 'Obszar „' . $name . '" dodany.');
        }
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = db_one("SELECT is_active FROM task_areas WHERE id=?", [$id]);
            if ($row) {
                $new = $row['is_active'] ? 0 : 1;
                db()->prepare("UPDATE task_areas SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$new, $id]);
                flash_set('success', 'Status obszaru zmieniony.');
            }
        }
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM tasks WHERE area_id=? AND deleted_at IS NULL", [$id])['c'] ?? 0);
            if ($cnt > 0) {
                flash_set('error', 'Nie można usunąć obszaru przypisanego do zadań.');
            } else {
                db()->prepare("UPDATE tasks SET area_id=NULL WHERE area_id=?")->execute([$id]);
                db()->prepare("DELETE FROM task_areas WHERE id=?")->execute([$id]);
                flash_set('success', 'Obszar usunięty.');
            }
        }
        header('Location: ' . $SELF); exit;
    }

    header('Location: ' . $SELF); exit;
}

// ── Dane ──────────────────────────────────────────────────────────────────
$areas = db_all(
    "SELECT ta.*, u.name AS creator_name,
            (SELECT COUNT(*) FROM tasks t WHERE t.area_id=ta.id AND t.deleted_at IS NULL) AS task_count
     FROM task_areas ta
     LEFT JOIN users u ON u.id=ta.created_by
     ORDER BY ta.name"
);

$PAGE_TITLE       = 'Obszary zadań';
$TASKS_BREADCRUMB = 'Obszary';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<?= flash_html() ?>

<div class="tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-2 tw-mb-4">
  <div>
    <h1 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
      <i class="bi bi-layers tw-text-blue-600" aria-hidden="true"></i>Obszary zadań
    </h1>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Etykiety kategoryzujące zadania niezależnie od obszarów roboczych</p>
  </div>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#areaModal"
          onclick="openAreaModal()">
    <i class="bi bi-plus-lg me-1"></i>Nowy obszar
  </button>
</div>

<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3 tw-w-[200px]">Obszar</th>
          <th>Ikona</th>
          <th class="text-center tw-w-20">Zadań</th>
          <th class="text-center tw-w-[90px]">Status</th>
          <th class="tw-w-[120px]"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$areas): ?>
        <tr><td colspan="5" class="text-center text-muted small py-4">
          Brak obszarów. Dodaj pierwszy.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($areas as $a): ?>
        <tr>
          <td class="ps-3">
            <span class="badge px-2 py-1" style="background:<?= h($a['color']) ?>;font-size:.78rem">
              <i class="bi <?= h($a['icon']) ?> me-1" aria-hidden="true"></i><?= h($a['name']) ?>
            </span>
          </td>
          <td><code class="small text-muted"><?= h($a['icon']) ?></code></td>
          <td class="text-center">
            <span class="badge bg-secondary bg-opacity-25 text-secondary"><?= (int)$a['task_count'] ?></span>
          </td>
          <td class="text-center">
            <span class="badge <?= $a['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $a['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
            </span>
          </td>
          <td class="pe-3">
            <div class="d-flex gap-1 justify-content-end">
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="toggle">
                <input type="hidden" name="id"      value="<?= $a['id'] ?>">
                <button class="btn <?= $a['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        style="padding:.18rem .45rem;font-size:.72rem"
                        aria-label="<?= $a['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?> obszar <?= h($a['name']) ?>">
                  <i class="bi bi-<?= $a['is_active'] ? 'pause' : 'play' ?>"></i>
                </button>
              </form>
              <?php if ((int)$a['task_count'] === 0): ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Usunąć obszar «<?= h(addslashes($a['name'])) ?>»?')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id"      value="<?= $a['id'] ?>">
                <button class="btn btn-outline-danger"
                        style="padding:.18rem .45rem;font-size:.72rem"
                        aria-label="Usuń obszar <?= h($a['name']) ?>">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: nowy obszar -->
<div class="modal fade" id="areaModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold">Nowy obszar</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" name="name" id="am-name" class="form-control form-control-sm"
                   required maxlength="100" placeholder="np. Fundraising">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Kolor</label>
            <input type="color" name="color" id="am-color"
                   class="form-control form-control-sm form-control-color" value="#4f46e5"
                   oninput="updateAreaPreview()">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Ikona <span class="text-muted fw-normal">(Bootstrap Icons)</span></label>
            <input type="text" name="icon" id="am-icon" class="form-control form-control-sm"
                   value="bi-layers" placeholder="bi-layers" oninput="updateAreaPreview()">
          </div>
          <div class="mb-0">
            <label class="form-label small fw-semibold">Podgląd</label><br>
            <span id="am-preview" class="badge px-2 py-1" style="background:#4f46e5;font-size:.82rem">
              <i id="am-prev-icon" class="bi bi-layers me-1"></i><span id="am-prev-name">Podgląd</span>
            </span>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Dodaj
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openAreaModal() {
    document.getElementById('am-name').value  = '';
    document.getElementById('am-color').value = '#4f46e5';
    document.getElementById('am-icon').value  = 'bi-layers';
    updateAreaPreview();
}

function updateAreaPreview() {
    const color = document.getElementById('am-color').value;
    const icon  = document.getElementById('am-icon').value.trim() || 'bi-layers';
    const name  = document.getElementById('am-name').value.trim() || 'Podgląd';
    document.getElementById('am-preview').style.background = color;
    document.getElementById('am-prev-icon').className      = 'bi ' + icon + ' me-1';
    document.getElementById('am-prev-name').textContent    = name;
}

document.getElementById('am-name')?.addEventListener('input', updateAreaPreview);
</script>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
