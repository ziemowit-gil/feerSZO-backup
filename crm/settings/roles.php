<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/permissions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do zarządzania rolami CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();
_permissions_init();

$PAGE_TITLE = 'CRM — Role użytkowników';

// Sub-moduły CRM wyświetlane w tabeli i formularzu ról
const CRM_SUB_MODULES = [
    'crm_eksport'    => ['label' => 'Eksport',    'flag' => 'can_read',  'icon' => 'bi-download'],
    'crm_import'     => ['label' => 'Import',     'flag' => 'can_write', 'icon' => 'bi-upload'],
    'crm_mailing'    => ['label' => 'Mailing',    'flag' => 'can_write', 'icon' => 'bi-send-fill'],
    'crm_ustawienia' => ['label' => 'Ustawienia', 'flag' => 'can_write', 'icon' => 'bi-gear-fill'],
];

function _crm_roles_all(): array {
    return db_all(
        "SELECT r.*,
            (SELECT COUNT(*) FROM users WHERE role = r.name) AS user_count,
            (SELECT can_read   FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_read,
            (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_write,
            (SELECT can_delete FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_delete,
            (SELECT can_read   FROM role_permissions WHERE role_id=r.id AND module='crm_eksport')    AS crm_eksport,
            (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm_import')     AS crm_import,
            (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm_mailing')    AS crm_mailing,
            (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm_ustawienia') AS crm_ustawienia
         FROM roles r WHERE r.crm_only=1 ORDER BY r.sort_order, r.id"
    );
}

function _crm_role_upsert(int $role_id, string $module, int $read, int $write, int $del): void {
    try {
        db()->prepare(
            "INSERT INTO role_permissions (role_id, module, can_read, can_write, can_delete)
             VALUES (?,?,?,?,?)
             ON CONFLICT(role_id, module) DO UPDATE SET can_read=excluded.can_read, can_write=excluded.can_write, can_delete=excluded.can_delete"
        )->execute([$role_id, $module, $read, $write, $del]);
    } catch (\Throwable $e) {
        if (db_one("SELECT id FROM role_permissions WHERE role_id=? AND module=?", [$role_id, $module])) {
            db()->prepare("UPDATE role_permissions SET can_read=?,can_write=?,can_delete=? WHERE role_id=? AND module=?")
                ->execute([$read, $write, $del, $role_id, $module]);
        } else {
            db_insert('role_permissions', ['role_id'=>$role_id,'module'=>$module,'can_read'=>$read,'can_write'=>$write,'can_delete'=>$del]);
        }
    }
}

function _crm_role_save_perms(int $role_id, int $read, int $write, int $del): void {
    _crm_role_upsert($role_id, 'crm', $read, $write, $del);
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
        $sub = [
            'crm_eksport'    => isset($_POST['sub_eksport'])    ? 1 : 0,
            'crm_import'     => isset($_POST['sub_import'])     ? 1 : 0,
            'crm_mailing'    => isset($_POST['sub_mailing'])    ? 1 : 0,
            'crm_ustawienia' => isset($_POST['sub_ustawienia']) ? 1 : 0,
        ];

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
            // Sub-moduły
            _crm_role_upsert($id, 'crm_eksport',    $sub['crm_eksport'],    $sub['crm_eksport'],    0);
            _crm_role_upsert($id, 'crm_import',     $sub['crm_import'],     $sub['crm_import'],     0);
            _crm_role_upsert($id, 'crm_mailing',    $sub['crm_mailing'],    $sub['crm_mailing'],    0);
            _crm_role_upsert($id, 'crm_ustawienia', $sub['crm_ustawienia'], $sub['crm_ustawienia'], 0);
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
            _crm_role_upsert($new_id, 'crm_eksport',    $sub['crm_eksport'],    $sub['crm_eksport'],    0);
            _crm_role_upsert($new_id, 'crm_import',     $sub['crm_import'],     $sub['crm_import'],     0);
            _crm_role_upsert($new_id, 'crm_mailing',    $sub['crm_mailing'],    $sub['crm_mailing'],    0);
            _crm_role_upsert($new_id, 'crm_ustawienia', $sub['crm_ustawienia'], $sub['crm_ustawienia'], 0);
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
        (SELECT can_delete FROM role_permissions WHERE role_id=r.id AND module='crm') AS crm_delete,
        (SELECT can_read   FROM role_permissions WHERE role_id=r.id AND module='crm_eksport')    AS sub_eksport,
        (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm_import')     AS sub_import,
        (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm_mailing')    AS sub_mailing,
        (SELECT can_write  FROM role_permissions WHERE role_id=r.id AND module='crm_ustawienia') AS sub_ustawienia
     FROM roles r WHERE r.id=? AND r.crm_only=1", [$edit_id]
) : null;

$crm_roles = _crm_roles_all();

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
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
  $f = $edit_row ?? ['display_name'=>'','description'=>'','sort_order'=>10,'crm_write'=>1,'crm_delete'=>0,'sub_eksport'=>0,'sub_import'=>0,'sub_mailing'=>1,'sub_ustawienia'=>0];
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
        <div class="border rounded p-3 bg-light-subtle d-flex flex-column gap-2">

          <div class="fw-semibold text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em">
            Kontakty (dostęp bazowy)
          </div>
          <div class="form-check ms-2 mb-0">
            <input class="form-check-input" type="checkbox" disabled checked>
            <label class="form-check-label small">Przeglądanie kontaktów <span class="text-muted">(zawsze)</span></label>
          </div>
          <div class="form-check ms-2 mb-0">
            <input class="form-check-input" type="checkbox" id="r_write" name="can_write" <?= $f['crm_write'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="r_write">
              Edycja kontaktów, notatki, tagi, relacje, aktywności
            </label>
          </div>
          <div class="form-check ms-2">
            <input class="form-check-input" type="checkbox" id="r_delete" name="can_delete" <?= $f['crm_delete'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="r_delete">
              Usuwanie kontaktów i notatek
            </label>
          </div>

          <hr class="my-1">
          <div class="fw-semibold text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em">
            Operacje rozszerzone
          </div>
          <div class="form-check ms-2 mb-0">
            <input class="form-check-input" type="checkbox" id="r_eksport" name="sub_eksport" <?= !empty($f['sub_eksport']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="r_eksport">
              <i class="bi bi-download text-muted me-1"></i>Eksport kontaktów do CSV / XLSX
            </label>
          </div>
          <div class="form-check ms-2 mb-0">
            <input class="form-check-input" type="checkbox" id="r_import" name="sub_import" <?= !empty($f['sub_import']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="r_import">
              <i class="bi bi-upload text-muted me-1"></i>Import kontaktów z CSV
            </label>
          </div>
          <div class="form-check ms-2 mb-0">
            <input class="form-check-input" type="checkbox" id="r_mailing" name="sub_mailing" <?= !empty($f['sub_mailing']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="r_mailing">
              <i class="bi bi-send-fill text-muted me-1"></i>Mailing masowy i komunikacja grupowa
            </label>
          </div>
          <div class="form-check ms-2">
            <input class="form-check-input" type="checkbox" id="r_ustaw" name="sub_ustawienia" <?= !empty($f['sub_ustawienia']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="r_ustaw">
              <i class="bi bi-gear-fill text-muted me-1"></i>Ustawienia CRM (statusy, pola, szablony, role)
            </label>
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
      <thead class="table-light" style="font-size:.78rem">
        <tr>
          <th>Nazwa roli</th>
          <th class="text-center" title="Przeglądanie kontaktów">Odczyt</th>
          <th class="text-center" title="Edycja kontaktów">Zapis</th>
          <th class="text-center" title="Usuwanie kontaktów">Usuń</th>
          <th class="text-center" title="Eksport do CSV/XLSX"><i class="bi bi-download"></i></th>
          <th class="text-center" title="Import z CSV"><i class="bi bi-upload"></i></th>
          <th class="text-center" title="Mailing masowy"><i class="bi bi-send-fill"></i></th>
          <th class="text-center" title="Ustawienia CRM"><i class="bi bi-gear-fill"></i></th>
          <th class="text-center">Użytkownicy</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody style="font-size:.82rem">
        <?php
        $yes = '<i class="bi bi-check-circle-fill text-success"></i>';
        $no  = '<i class="bi bi-dash text-muted"></i>';
        foreach ($crm_roles as $r): ?>
        <tr>
          <td>
            <span class="fw-semibold"><?= h($r['display_name']) ?></span>
            <?php if ($r['is_system']): ?>
            <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:.65rem">sys</span>
            <?php endif; ?>
            <?php if ($r['description']): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['description']) ?></div><?php endif; ?>
          </td>
          <td class="text-center"><?= $yes ?></td>
          <td class="text-center"><?= $r['crm_write']      ? $yes : $no ?></td>
          <td class="text-center"><?= $r['crm_delete']     ? $yes : $no ?></td>
          <td class="text-center"><?= $r['crm_eksport']    ? $yes : $no ?></td>
          <td class="text-center"><?= $r['crm_import']     ? $yes : $no ?></td>
          <td class="text-center"><?= $r['crm_mailing']    ? $yes : $no ?></td>
          <td class="text-center"><?= $r['crm_ustawienia'] ? $yes : $no ?></td>
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

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
