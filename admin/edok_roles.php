<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
edok_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $roles   = $_POST['roles'] ?? [];

    if ($user_id) {
        db()->prepare("DELETE FROM edok_user_roles WHERE user_id = ?")->execute([$user_id]);
        foreach ($roles as $role) {
            if (isset(EDOK_ROLES[$role])) {
                db()->prepare("INSERT OR IGNORE INTO edok_user_roles (user_id, role) VALUES (?, ?)")->execute([$user_id, $role]);
            }
        }
        flash_set('success', 'Uprawnienia zaktualizowane.');
    }
    header('Location: ' . APP_URL . '/admin/edok_roles.php');
    exit;
}

$users = db_all("SELECT id, name, email, role FROM users WHERE is_active = 1 ORDER BY name");
$all_roles_map = [];
foreach ($users as $u) {
    $all_roles_map[$u['id']] = edok_user_roles((int)$u['id']);
}

$PAGE_TITLE = 'Role — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Role — EODoK (Elektroniczny Obieg Dokumentów Księgowych)</h4>
</div>
<p class="text-muted small">Etapy są sekwencyjne — administrator ma dostęp do wszystkich automatycznie. Jedna osoba może mieć kilka ról, ale system ostrzega, gdy ta sama osoba jest wnioskodawcą i decyduje o etapie 1 lub 5.</p>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Użytkownik</th>
        <?php foreach (EDOK_ROLES as $rk => $rl): ?>
        <th class="text-center" title="<?= h($rl) ?>" style="writing-mode:vertical-rl;text-orientation:mixed;font-weight:600"><?= h($rl) ?></th>
        <?php endforeach; ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <?= h($u['name']) ?> <span class="text-muted small"><?= h($u['email']) ?></span>
          <?php if ($u['role'] === 'admin'): ?><span class="badge bg-dark ms-1">admin — pełny dostęp</span><?php endif; ?>
        </td>
        <form method="post" id="f<?= $u['id'] ?>">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        </form>
        <?php foreach (EDOK_ROLES as $rk => $rl): ?>
        <td class="text-center">
          <input type="checkbox" form="f<?= $u['id'] ?>" name="roles[]" value="<?= h($rk) ?>"
            <?= in_array($rk, $all_roles_map[$u['id']], true) ? 'checked' : '' ?>
            <?= $u['role'] === 'admin' ? 'disabled' : '' ?>
            onchange="this.form.requestSubmit()">
        </td>
        <?php endforeach; ?>
        <td></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
