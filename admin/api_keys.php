<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/api_auth.php';

require_role('admin');
api_auth_migrate();

$PAGE_TITLE = 'Klucze API';

// All available permissions
const API_PERMISSIONS = [
    'volunteers:read' => 'Wolontariusze — odczyt',
    'contracts:read'  => 'Umowy — odczyt',
    'tasks:read'      => 'Zadania — odczyt',
    'users:read'      => 'Użytkownicy — odczyt',
];

$errors    = [];
$new_plain = null; // shown once after creation

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Create new key ────────────────────────────────────────────────────
    if ($action === 'create') {
        $name = trim($_POST['key_name'] ?? '');
        if ($name === '') {
            $errors[] = 'Podaj nazwę klucza.';
        }

        $perms = [];
        foreach (array_keys(API_PERMISSIONS) as $p) {
            if (!empty($_POST['perm_' . str_replace(':', '_', $p)])) {
                $perms[] = $p;
            }
        }
        if (empty($perms)) {
            $errors[] = 'Wybierz co najmniej jedno uprawnienie.';
        }

        if (!$errors) {
            $plain    = bin2hex(random_bytes(32)); // 64-char hex key
            $hash     = hash('sha256', $plain);
            $user     = current_user();
            $created  = date('Y-m-d H:i:s');

            db_insert('api_keys', [
                'key_hash'    => $hash,
                'name'        => $name,
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'created_at'  => $created,
                'is_active'   => 1,
                'created_by'  => $user['id'] ?? null,
            ]);

            $new_plain = $plain;
            flash_set('success', 'Klucz API "' . $name . '" został utworzony.');
        }
    }

    // ── Revoke key ────────────────────────────────────────────────────────
    if ($action === 'revoke') {
        $id = (int)($_POST['key_id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE api_keys SET is_active = 0 WHERE id = ?")
                ->execute([$id]);
            flash_set('success', 'Klucz API został unieważniony.');
            header('Location: ' . APP_URL . '/admin/api_keys.php');
            exit;
        }
    }
}

// ── Load all keys ──────────────────────────────────────────────────────────
$keys = db_all(
    "SELECT id, name, permissions, last_used_at, created_at, is_active, created_by
       FROM api_keys
      ORDER BY created_at DESC"
);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-key me-2"></i><?= h($PAGE_TITLE) ?></h4>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($new_plain): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Zapisz klucz &mdash; nie zostanie pokazany ponownie:</strong>
    <div class="mt-2">
      <code class="user-select-all fs-6 d-block p-2 bg-white border rounded"><?= h($new_plain) ?></code>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
<?php endif; ?>

<?php
// ── Existing keys table ────────────────────────────────────────────────────
if ($keys):
?>
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Istniejące klucze</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Nazwa</th>
          <th>Uprawnienia</th>
          <th>Ostatnie użycie</th>
          <th>Utworzony</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($keys as $k): ?>
          <?php
            $perms_arr = json_decode($k['permissions'] ?? '[]', true);
            if (!is_array($perms_arr)) $perms_arr = [];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($k['name']) ?></td>
            <td>
              <?php if ($perms_arr): ?>
                <?php foreach ($perms_arr as $p): ?>
                  <span class="badge bg-secondary me-1"><?= h($p) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">brak</span>
              <?php endif; ?>
            </td>
            <td><?= $k['last_used_at'] ? h(date_pl($k['last_used_at'])) : '<span class="text-muted">nigdy</span>' ?></td>
            <td><?= h(date_pl($k['created_at'])) ?></td>
            <td>
              <?php if ($k['is_active']): ?>
                <span class="badge bg-success">Aktywny</span>
              <?php else: ?>
                <span class="badge bg-secondary">Unieważniony</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($k['is_active']): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Czy na pewno chcesz unieważnić ten klucz?')">
                  <?= csrf_token() ?>
                  <input type="hidden" name="action"   value="revoke">
                  <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-slash-circle me-1"></i>Unieważnij
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

<?php
// ── Create new key form ────────────────────────────────────────────────────
?>
<div class="card">
  <div class="card-header bg-white fw-semibold">Utwórz nowy klucz API</div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <?= csrf_token() ?>
      <input type="hidden" name="action" value="create">

      <div class="col-md-6">
        <label for="key_name" class="form-label">Nazwa klucza <span class="text-danger">*</span></label>
        <input type="text" id="key_name" name="key_name" class="form-control"
               placeholder="np. Integracja z aplikacją mobilną"
               value="<?= h($_POST['key_name'] ?? '') ?>" required maxlength="200">
        <div class="form-text">Opis pomocny przy zarządzaniu kluczami.</div>
      </div>

      <div class="col-12">
        <label class="form-label">Uprawnienia <span class="text-danger">*</span></label>
        <div class="row g-2">
          <?php foreach (API_PERMISSIONS as $perm => $label): ?>
            <?php $field = 'perm_' . str_replace(':', '_', $perm); ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       id="<?= h($field) ?>"
                       name="<?= h($field) ?>"
                       value="1"
                       <?= !empty($_POST[$field]) ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= h($field) ?>">
                  <code class="small"><?= h($perm) ?></code><br>
                  <span class="text-muted small"><?= h($label) ?></span>
                </label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-plus-circle me-1"></i>Utwórz klucz
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
