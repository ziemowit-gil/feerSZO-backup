<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/webauthn.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();
webauthn_migrate();

// Role wymagające podpisu (opisywania dokumentów) — bez klucza WebAuthn użytkownik nie może ich wykonać
$signing_roles = ['meryt', 'formal', 'zatwierdza'];

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
$all_roles_map    = [];
$has_webauthn_map = [];
$has_ikaks_map    = [];
$missing_key_users = [];
foreach ($users as $u) {
    $all_roles_map[$u['id']]    = kdok_user_roles($u['id']);
    $has_webauthn_map[$u['id']] = webauthn_user_has_keys((int)$u['id']);
    $has_ikaks_map[$u['id']]    = kdok_ikaks_has((int)$u['id']);

    $has_signing_role = $u['role'] === 'admin' || array_intersect($all_roles_map[$u['id']], $signing_roles);
    if ($has_signing_role && !$has_webauthn_map[$u['id']] && !$has_ikaks_map[$u['id']]) {
        $missing_key_users[] = $u;
    }
}

$PAGE_TITLE = 'Role — EOD Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Role — EOD Dokumentów Księgowych</h4>
</div>
<?= flash_html() ?>

<div class="alert alert-info small">
  <strong>Admin</strong> ma wszystkie uprawnienia automatycznie.<br>
  Poniżej przypisz role dla pozostałych użytkowników.
</div>

<?php if ($missing_key_users): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle-fill"></i> Brak klucza WebAuthn i kodu IKAKS u <?= count($missing_key_users) ?>
  <?= count($missing_key_users) === 1 ? 'osoby' : 'osób' ?> z uprawnieniami do opisywania dokumentów:</strong>
  <div class="small mt-1">
    Opisywanie dokumentów (akceptacja merytoryczna/formalna/wypłaty) wymaga klucza WebAuthn albo — awaryjnie —
    kodu IKAKS. Poniższe osoby nie mają ani jednego, ani drugiego, więc nie będą mogły podejmować decyzji na swoich krokach.
  </div>
  <ul class="mb-0 mt-2">
    <?php foreach ($missing_key_users as $mu): ?>
    <li><?= h($mu['name']) ?> <span class="text-muted">(<?= h($mu['email']) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:900px">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle small">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>Rola sys.</th>
          <?php foreach (KDOK_ROLES as $r => $label): ?>
          <th class="text-center"><?= h($label) ?></th>
          <?php endforeach; ?>
          <th class="text-center">Klucz WebAuthn</th>
          <th class="text-center">IKAKS (awaryjnie)</th>
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
          <td class="text-center">
            <?php if ($has_webauthn_map[$u['id']]): ?>
            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Tak</span>
            <?php else: ?>
            <span class="badge bg-<?= $has_ikaks_map[$u['id']] ? 'warning text-dark' : 'danger' ?>"><i class="bi bi-x-lg"></i> Brak</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($has_ikaks_map[$u['id']]): ?>
            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Tak</span>
            <?php else: ?>
            <span class="badge bg-<?= $has_webauthn_map[$u['id']] ? 'secondary' : 'danger' ?>"><i class="bi bi-x-lg"></i> Brak</span>
            <?php endif; ?>
          </td>
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
