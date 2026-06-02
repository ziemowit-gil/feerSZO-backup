<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $roles   = $_POST['roles'] ?? [];

    if ($user_id) {
        // Usuń stare i wstaw nowe
        db()->prepare("DELETE FROM kdok_user_roles WHERE user_id = ?")->execute([$user_id]);
        foreach ($roles as $role) {
            if (isset(KDOK_ROLES[$role])) {
                db()->prepare("INSERT OR IGNORE INTO kdok_user_roles (user_id, role) VALUES (?, ?)")
                     ->execute([$user_id, $role]);
            }
        }
        flash_set('success', 'Uprawnienia zaktualizowane.');
    }
    header('Location: ' . APP_URL . '/admin/ksiegowosc_roles.php');
    exit;
}

$users    = db_all("SELECT id, name, email, role FROM users WHERE is_active = 1 ORDER BY name");
$all_roles_map = [];
foreach ($users as $u) {
    $all_roles_map[$u['id']] = kdok_user_roles($u['id']);
}

$PAGE_TITLE = 'Role — eObieg DK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Role — eObieg DK</h4>
</div>
<?= flash_html() ?>

<div class="alert alert-info small">
  <strong>Admin</strong> ma wszystkie uprawnienia automatycznie.<br>
  Poniżej przypisz role dla pozostałych użytkowników.
</div>

<div class="card shadow-sm" style="max-width:800px">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle small">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>Rola sys.</th>
          <?php foreach (KDOK_ROLES as $r => $label): ?>
          <th class="text-center"><?= h($label) ?></th>
          <?php endforeach; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <?php if ($u['role'] === 'admin') continue; // admin ma all ?>
      <tr>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <td><strong><?= h($u['name']) ?></strong><br><span class="text-muted"><?= h($u['email']) ?></span></td>
          <td><span class="badge bg-secondary"><?= h($u['role']) ?></span></td>
          <?php foreach (KDOK_ROLES as $r => $label): ?>
          <td class="text-center">
            <input type="checkbox" name="roles[]" value="<?= $r ?>"
              class="form-check-input"
              <?= in_array($r, $all_roles_map[$u['id']], true) ? 'checked' : '' ?>>
          </td>
          <?php endforeach; ?>
          <td>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i></button>
          </td>
        </form>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3 small text-muted">
  <strong>Opis ról:</strong>
  <ul>
    <?php foreach (KDOK_ROLES as $r => $label): ?>
    <li><code><?= $r ?></code> — <?= h($label) ?></li>
    <?php endforeach; ?>
  </ul>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
