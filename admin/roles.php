<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

require_role('admin');
if (!defined('TZ_ADMIN_CHROME')) {
    header('Location: ' . APP_URL . '/tozsamosc/role.php');
    exit;
}
$PAGE_TITLE = 'Role i uprawnienia';
$errors = [];

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Update role permissions
    if ($action === 'update_permissions') {
        $role_id = intval($_POST['role_id'] ?? 0);
        if ($role_id) {
            $perms = $_POST['perms'] ?? [];
            db()->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
            $ins = db()->prepare(
                "INSERT INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)"
            );
            foreach (array_keys(PERMISSION_MODULES) as $mod) {
                $mp = $perms[$mod] ?? [];
                $ins->execute([
                    $role_id,
                    $mod,
                    isset($mp['read'])   ? 1 : 0,
                    isset($mp['write'])  ? 1 : 0,
                    isset($mp['delete']) ? 1 : 0,
                ]);
            }
            flash_set('success', 'Uprawnienia roli zostały zaktualizowane.');
        }
        header('Location: roles.php');
        exit;
    }

    // Add new role
    if ($action === 'add_role') {
        $name         = trim($_POST['name'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');
        $description  = trim($_POST['description'] ?? '');

        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            $errors[] = 'Identyfikator roli może zawierać tylko małe litery, cyfry i podkreślnik.';
        }
        if (!$display_name) {
            $errors[] = 'Podaj nazwę wyświetlaną roli.';
        }
        if (!$errors) {
            $exists = db_one("SELECT id FROM roles WHERE name = ?", [$name]);
            if ($exists) {
                $errors[] = 'Rola o tym identyfikatorze już istnieje.';
            } else {
                $max_order = db()->query("SELECT MAX(sort_order) FROM roles")->fetchColumn();
                db()->prepare(
                    "INSERT INTO roles (name, display_name, description, is_system, sort_order) VALUES (?,?,?,0,?)"
                )->execute([$name, $display_name, $description, (int)$max_order + 1]);
                $new_role_id = (int)db()->lastInsertId();
                // Default permissions: read on all non-admin modules
                $ins = db()->prepare(
                    "INSERT INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,1,0,0)"
                );
                foreach (array_keys(PERMISSION_MODULES) as $mod) {
                    if ($mod !== 'admin') {
                        $ins->execute([$new_role_id, $mod]);
                    }
                }
                flash_set('success', 'Rola "' . $display_name . '" została dodana.');
                header('Location: roles.php');
                exit;
            }
        }
    }

    // Delete role
    if ($action === 'delete_role') {
        $role_id = intval($_POST['role_id'] ?? 0);
        if ($role_id) {
            $role = db_one("SELECT * FROM roles WHERE id = ?", [$role_id]);
            if (!$role) {
                flash_set('danger', 'Rola nie istnieje.');
            } elseif ($role['is_system']) {
                flash_set('danger', 'Nie można usunąć systemowej roli.');
            } else {
                $user_count = (int)db()->prepare("SELECT COUNT(*) FROM users WHERE role = ?")->execute([$role['name']]) ? 0 : 0;
                // Proper count:
                $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
                $stmt->execute([$role['name']]);
                $user_count = (int)$stmt->fetchColumn();
                if ($user_count > 0) {
                    flash_set('danger', 'Nie można usunąć roli przypisanej do użytkowników (' . $user_count . ').');
                } else {
                    db()->prepare("DELETE FROM roles WHERE id = ?")->execute([$role_id]);
                    flash_set('success', 'Rola została usunięta.');
                }
            }
        }
        header('Location: roles.php');
        exit;
    }

    // Rename/edit role metadata
    if ($action === 'rename_role') {
        $role_id      = intval($_POST['role_id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        if ($role_id && $display_name) {
            db()->prepare("UPDATE roles SET display_name = ?, description = ? WHERE id = ?")
                ->execute([$display_name, $description, $role_id]);
            flash_set('success', 'Dane roli zostały zaktualizowane.');
        }
        header('Location: roles.php');
        exit;
    }
}

$roles = roles_all();

// Build permissions map indexed by role_id
$perms_by_role = [];
foreach ($roles as $role) {
    $perms_by_role[$role['id']] = role_permissions($role['name']);
}

$TZ_ACTIVE = 'administracja';
include dirname(__DIR__) . '/tozsamosc/_head.php';
?>
<style>.tz-wrap{max-width:1200px}</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-shield-lock text-primary"></i> Role i uprawnienia</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/admin/ezd_access_matrix.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-archive-fill" style="color:#b45309"></i> Macierz EZD
    </a>
    <a href="<?= APP_URL ?>/admin/access_matrix.php" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-grid-3x3-gap"></i> Macierz uprawnień (wydruk)
    </a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- LEFT: roles with permissions grids -->
<div class="col-lg-8">

<?php foreach ($roles as $role):
    $rp = $perms_by_role[$role['id']] ?? [];
?>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <?php if ($role['is_system']): ?>
    <i class="bi bi-lock-fill text-warning" title="Rola systemowa — nie można usunąć"></i>
    <?php endif; ?>
    <span class="fw-semibold"><?= h($role['display_name']) ?></span>
    <code class="small text-muted ms-1"><?= h($role['name']) ?></code>
    <?php if ($role['user_count'] > 0): ?>
    <span class="badge bg-secondary ms-1"><?= intval($role['user_count']) ?> użytkowników</span>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2">
      <!-- Edit name/description button -->
      <button class="btn btn-sm btn-outline-secondary"
              data-bs-toggle="modal"
              data-bs-target="#editRoleModal"
              data-role-id="<?= intval($role['id']) ?>"
              data-display-name="<?= h($role['display_name']) ?>"
              data-description="<?= h($role['description']) ?>">
        <i class="bi bi-pencil"></i> Edytuj dane
      </button>
      <?php if (!$role['is_system']): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="delete_role">
        <input type="hidden" name="role_id" value="<?= intval($role['id']) ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Usunąć rolę <?= h(addslashes($role['display_name'])) ?>?')"
                <?= $role['user_count'] > 0 ? 'disabled title="Rola ma przypisanych użytkowników"' : '' ?>>
          <i class="bi bi-trash"></i>
        </button>
      </form>
      <?php else: ?>
      <button class="btn btn-sm btn-outline-danger" disabled title="Rola systemowa — nie można usunąć">
        <i class="bi bi-lock"></i>
      </button>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($role['description']): ?>
  <div class="card-body py-2 px-3 border-bottom bg-light">
    <small class="text-muted"><?= h($role['description']) ?></small>
  </div>
  <?php endif; ?>
  <div class="card-body p-0">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_permissions">
      <input type="hidden" name="role_id" value="<?= intval($role['id']) ?>">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:50%">Moduł</th>
              <th class="text-center">Odczyt</th>
              <th class="text-center">Zapis</th>
              <th class="text-center">Usuń</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach (PERMISSION_MODULES as $mod => $label):
            $mp       = $rp[$mod] ?? [];
            $has_read   = !empty($mp['can_read']);
            $has_write  = !empty($mp['can_write']);
            $has_delete = !empty($mp['can_delete']);
          ?>
          <tr>
            <td><?= h($label) ?> <small class="text-muted">(<?= h($mod) ?>)</small></td>
            <td class="text-center">
              <input type="checkbox" name="perms[<?= $mod ?>][read]" value="1"
                     class="form-check-input" <?= $has_read ? 'checked' : '' ?>>
            </td>
            <td class="text-center">
              <input type="checkbox" name="perms[<?= $mod ?>][write]" value="1"
                     class="form-check-input" <?= $has_write ? 'checked' : '' ?>>
            </td>
            <td class="text-center">
              <input type="checkbox" name="perms[<?= $mod ?>][delete]" value="1"
                     class="form-check-input" <?= $has_delete ? 'checked' : '' ?>>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-save"></i> Zapisz uprawnienia
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

</div><!-- /col-lg-8 -->

<!-- RIGHT: Add new role -->
<div class="col-lg-4">
<div class="card shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-plus-circle"></i> Dodaj nową rolę</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_role">
      <div class="mb-3">
        <label class="form-label">Identyfikator (slug) *</label>
        <input name="name" class="form-control font-monospace"
               placeholder="np. koordynator_grantow"
               value="<?= h($_POST['name'] ?? '') ?>"
               pattern="[a-z0-9_]+" required>
        <div class="form-text">Tylko małe litery, cyfry i podkreślnik. Nie można zmienić po dodaniu.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Nazwa wyświetlana *</label>
        <input name="display_name" class="form-control"
               placeholder="np. Koordynator grantów"
               value="<?= h($_POST['display_name'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Opis</label>
        <textarea name="description" class="form-control" rows="2"
                  placeholder="Krótki opis roli i jej uprawnień"><?= h($_POST['description'] ?? '') ?></textarea>
      </div>
      <div class="alert alert-info small py-2 mb-3">
        <i class="bi bi-info-circle"></i>
        Nowa rola domyślnie otrzymuje uprawnienie do odczytu wszystkich modułów (oprócz <em>Administracji</em>).
        Możesz dostosować uprawnienia po dodaniu roli.
      </div>
      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-plus-lg"></i> Dodaj rolę
      </button>
    </form>
  </div>
</div>

<div class="card shadow-sm mt-3">
  <div class="card-header fw-semibold">Informacje</div>
  <div class="card-body small">
    <p class="mb-2">
      <i class="bi bi-lock-fill text-warning"></i>
      <strong>Role systemowe</strong> (admin, editor, viewer) nie mogą być usunięte, ale można edytować ich uprawnienia.
    </p>
    <p class="mb-2">
      <strong>Odczyt</strong> — przeglądanie listy i szczegółów rekordów modułu.
    </p>
    <p class="mb-2">
      <strong>Zapis</strong> — tworzenie i edycja rekordów.
    </p>
    <p class="mb-0">
      <strong>Usuń</strong> — usuwanie rekordów.
    </p>
  </div>
</div>
</div>

</div><!-- /row -->

<!-- Modal: edit role metadata -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj dane roli</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="editRoleForm">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="rename_role">
          <input type="hidden" name="role_id" id="editRoleId" value="">
          <div class="mb-3">
            <label class="form-label">Nazwa wyświetlana *</label>
            <input name="display_name" id="editRoleDisplayName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Opis</label>
            <textarea name="description" id="editRoleDescription" class="form-control" rows="3"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('editRoleModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('editRoleId').value          = btn.dataset.roleId;
    document.getElementById('editRoleDisplayName').value = btn.dataset.displayName;
    document.getElementById('editRoleDescription').value = btn.dataset.description;
});
</script>

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>
