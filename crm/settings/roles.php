<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/permissions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) {
    flash_set('danger', 'Tylko administrator może zarządzać rolami CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();
_permissions_init();

$PAGE_TITLE = 'CRM — Role użytkowników';

function _crm_roles_all(): array {
    return db_all(
        "SELECT r.*,
            (SELECT COUNT(*) FROM users WHERE role = r.name) AS user_count,
            (SELECT can_read   FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_read,
            (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_write,
            (SELECT can_delete FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_delete
         FROM roles r WHERE r.crm_only=1 ORDER BY r.sort_order, r.id"
    );
}

function _crm_role_save_perms(int $role_id, int $read, int $write, int $del): void {
    try {
        db()->prepare(
            "INSERT INTO role_permissions (role_id, module, can_read, can_write, can_delete)
             VALUES (?,?,?,?,?)
             ON CONFLICT(role_id, module) DO UPDATE SET can_read=excluded.can_read, can_write=excluded.can_write, can_delete=excluded.can_delete"
        )->execute([$role_id, 'crm', $read, $write, $del]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT id FROM role_permissions WHERE role_id=? AND module='crm'", [$role_id]);
        if ($ex) {
            db()->prepare("UPDATE role_permissions SET can_read=?,can_write=?,can_delete=? WHERE role_id=? AND module='crm'")
                ->execute([$read, $write, $del, $role_id]);
        } else {
            db_insert('role_permissions', ['role_id'=>$role_id,'module'=>'crm','can_read'=>$read,'can_write'=>$write,'can_delete'=>$del]);
        }
    }
}

$BASE_URL = APP_URL . '/crm/settings/roles.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $sort_order   = (int)($_POST['sort_order'] ?? 10);
        $can_write    = isset($_POST['can_write'])  ? 1 : 0;
        $can_delete   = isset($_POST['can_delete']) ? 1 : 0;

        if ($display_name === '') {
            flash_set('danger', 'Nazwa roli jest wymagana.');
            header('Location: ' . $BASE_URL . ($id ? "?edit=$id" : '?new=1'));
            exit;
        }

        if ($id) {
            $existing = db_one("SELECT * FROM roles WHERE id=? AND crm_only=1", [$id]);
            if (!$existing) { flash_set('danger', 'Rola nie istnieje.'); header('Location: ' . $BASE_URL); exit; }
            db()->prepare("UPDATE roles SET display_name=?,description=?,sort_order=? WHERE id=?")
                ->execute([$display_name, $description, $sort_order, $id]);
            _crm_role_save_perms($id, 1, $can_write, $can_delete);
            flash_set('success', 'Rola zaktualizowana.');
        } else {
            $base = 'crm_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($display_name));
            $base = substr(trim($base, '_'), 0, 40);
            $name = $base; $suffix = 2;
            while (db_one("SELECT id FROM roles WHERE name=?", [$name])) $name = $base . '_' . $suffix++;
            db()->prepare("INSERT INTO roles (name, display_name, description, is_system, crm_only, sort_order) VALUES (?,?,?,0,1,?)")
                ->execute([$name, $display_name, $description, $sort_order]);
            $new_id = (int)db()->lastInsertId();
            _crm_role_save_perms($new_id, 1, $can_write, $can_delete);
            flash_set('success', 'Rola "' . h($display_name) . '" utworzona (nazwa techniczna: <code>' . h($name) . '</code>).');
        }
        header('Location: ' . $BASE_URL);
        exit;
    }

    if ($op === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $role = db_one("SELECT * FROM roles WHERE id=? AND crm_only=1 AND is_system=0", [$id]);
        if (!$role) { flash_set('danger', 'Nie można usunąć tej roli.'); header('Location: ' . $BASE_URL); exit; }
        $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE role=?", [$role['name']])['c'] ?? 0);
        if ($cnt > 0) { flash_set('danger', "Rola ma {$cnt} użytkowników — najpierw zmień im rolę."); header('Location: ' . $BASE_URL); exit; }
        db()->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);
        flash_set('success', 'Rola usunięta.');
        header('Location: ' . $BASE_URL);
        exit;
    }
}

$edit_id  = (int)($_GET['edit'] ?? 0);
$show_new = isset($_GET['new']);
$edit_row = $edit_id ? db_one(
    "SELECT r.*,
        (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_write,
        (SELECT can_delete FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_delete
     FROM roles r WHERE r.id=? AND r.crm_only=1", [$edit_id]
) : null;

$crm_roles = _crm_roles_all();

include __DIR__ . '/../includes/header_crm.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Role CRM</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-people-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Role CRM</h1>
    <div class="crm-object-count"><?= count($crm_roles) ?> ról zdefiniowanych</div>
  </div>
  <div class="crm-object-actions d-flex gap-2">
    <?php if (!$show_new && !$edit_row): ?>
    <a href="?new=1" class="btn btn-sm btn-crm-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowa rola
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/settings/" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Ustawienia
    </a>
  </div>
</div>

<?= flash_html() ?>

<div class="alert alert-info small d-flex gap-2 align-items-start mb-4" style="max-width:820px">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    Użytkownik z rolą CRM po zalogowaniu trafia wyłącznie do modułu CRM — nie widzi systemu głównego.
    Możesz mu dodatkowo ograniczyć dostęp do wybranych grup kontaktów w widoku danej grupy.
    Rolę przypisujesz w <a href="<?= APP_URL ?>/admin/users.php">zarządzaniu użytkownikami</a>.
  </div>
</div>

<?php if ($show_new || $edit_row):
  $f = $edit_row ?? ['display_name'=>'','description'=>'','sort_order'=>10,'crm_write'=>1,'crm_delete'=>0];
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:580px">
  <div class="card-header fw-semibold">
    <?= $edit_row ? 'Edytuj rolę: <em>' . h($f['display_name']) . '</em>' : 'Nowa rola CRM' ?>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save">
      <input type="hidden" name="id"    value="<?= (int)($f['id'] ?? 0) ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold" for="r_display">Nazwa roli <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="r_display" name="display_name"
               value="<?= h($f['display_name']) ?>" required
               placeholder="np. CRM Handlowiec, CRM Obsługa klienta">
        <?php if ($edit_row): ?>
        <div class="form-text text-muted">
          Nazwa techniczna: <code><?= h($f['name']) ?></code>
          <?= ($f['is_system'] ?? 0) ? '(systemowa)' : '' ?>
        </div>
        <?php else: ?>
        <div class="form-text">Nazwa techniczna generowana automatycznie (np. <code>crm_handlowiec</code>).</div>
        <?php endif; ?>
      </div>

      <div class="mb-3">
        <label class="form-label" for="r_desc">Opis</label>
        <input type="text" class="form-control" id="r_desc" name="description"
               value="<?= h($f['description']) ?>" placeholder="Krótki opis przeznaczenia roli">
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold">Uprawnienia w CRM</label>
        <div class="border rounded p-3 bg-light-subtle">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" disabled checked>
            <label class="form-check-label">Odczyt <span class="text-muted small">(zawsze włączony)</span></label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="r_write" name="can_write" <?= $f['crm_write'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="r_write">Zapis — tworzenie i edycja kontaktów, wysyłanie wiadomości</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="r_delete" name="can_delete" <?= $f['crm_delete'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="r_delete">Usuwanie — kontaktów, notatek, tagów</label>
          </div>
        </div>
      </div>

      <div class="mb-3" style="max-width:180px">
        <label class="form-label" for="r_sort">Kolejność</label>
        <input type="number" class="form-control" id="r_sort" name="sort_order"
               value="<?= (int)$f['sort_order'] ?>" min="0" step="1">
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-crm-primary">
          <i class="bi bi-check-lg me-1"></i><?= $edit_row ? 'Zapisz zmiany' : 'Utwórz rolę' ?>
        </button>
        <a href="<?= $BASE_URL ?>" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:860px">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-ul me-1"></i>Zdefiniowane role CRM
    <span class="badge bg-secondary ms-1"><?= count($crm_roles) ?></span>
  </div>
  <?php if (!$crm_roles): ?>
  <div class="card-body text-muted">Brak ról CRM. <a href="?new=1">Utwórz pierwszą</a>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Nazwa</th><th>Nazwa techniczna</th>
          <th class="text-center">Odczyt</th><th class="text-center">Zapis</th><th class="text-center">Usuwanie</th>
          <th class="text-center">Użytkownicy</th><th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($crm_roles as $r): ?>
        <tr>
          <td>
            <span class="fw-semibold"><?= h($r['display_name']) ?></span>
            <?php if ($r['is_system']): ?>
            <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:.7rem">systemowa</span>
            <?php endif; ?>
            <?php if ($r['description']): ?><div class="text-muted small"><?= h($r['description']) ?></div><?php endif; ?>
          </td>
          <td><code class="text-muted"><?= h($r['name']) ?></code></td>
          <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
          <td class="text-center"><?= $r['crm_write']  ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?></td>
          <td class="text-center"><?= $r['crm_delete'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?></td>
          <td class="text-center">
            <?php if ($r['user_count'] > 0): ?>
            <a href="<?= APP_URL ?>/admin/users.php" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none"><?= (int)$r['user_count'] ?></a>
            <?php else: ?><span class="text-muted">0</span><?php endif; ?>
          </td>
          <td class="text-end">
            <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <?php if (!$r['is_system']): ?>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć rolę <?= h(addslashes($r['display_name'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="delete">
              <input type="hidden" name="id"    value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
            </form>
            <?php else: ?>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Systemowa — nie można usunąć">
              <i class="bi bi-lock"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
