<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$PAGE_TITLE = 'Struktura — Grupy pól';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            flash_set('danger', 'Nazwa grupy jest wymagana.');
            header('Location: ' . APP_URL . '/org/field_groups.php' . ($id ? "?edit=$id" : '?new=1'));
            exit;
        }
        org_field_group_save([
            'label'         => $label,
            'icon'          => trim($_POST['icon'] ?? '') ?: 'bi-card-list',
            'applies_to'    => $_POST['applies_to'] ?? 'both',
            'sort_order'    => (int)($_POST['sort_order'] ?? 0),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            'visible_roles' => (array)($_POST['visible_roles'] ?? []),
            'edit_roles'    => (array)($_POST['edit_roles'] ?? []),
        ], $id ?: null);
        flash_set('success', $id ? 'Grupa zaktualizowana.' : 'Grupa dodana.');
        header('Location: ' . APP_URL . '/org/field_groups.php');
        exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { org_field_group_delete($id); flash_set('success', 'Grupa usunięta (pola pozostają, bez przypisania).'); }
        header('Location: ' . APP_URL . '/org/field_groups.php');
        exit;
    }
}

$edit_id   = (int)($_GET['edit'] ?? 0);
$show_new  = isset($_GET['new']);
$edit_grp  = $edit_id ? org_field_group_get($edit_id) : null;
$all_roles = org_all_roles();
$groups    = org_field_groups_all(false);

include dirname(__DIR__) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/fields.php">Pola definiowane</a></li>
  <li class="breadcrumb-item active">Grupy pól</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-layers text-primary me-2"></i>Grupy pól</h4>
    <div class="text-muted" style="font-size:.78rem;margin-top:.1rem">Sekcje grupujące pola definiowane w formularzach i na kartach jednostek</div>
  </div>
  <?php if (!$show_new && !$edit_grp): ?>
  <a href="?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Dodaj grupę</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($show_new || $edit_grp):
  $g = $edit_grp ?? ['label'=>'','icon'=>'bi-card-list','applies_to'=>'both','sort_order'=>0,'is_active'=>1,'visible_roles'=>'','edit_roles'=>''];
  $g_vis  = json_decode($g['visible_roles'] ?? '', true) ?: [];
  $g_edit = json_decode($g['edit_roles']    ?? '', true) ?: [];
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:680px">
  <div class="card-header fw-semibold"><?= $edit_grp ? 'Edytuj grupę: <em>'.h($g['label']).'</em>' : 'Nowa grupa' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save">
      <input type="hidden" name="id"    value="<?= (int)($g['id'] ?? 0) ?>">

      <div class="row g-3 mb-3">
        <div class="col-sm-8">
          <label class="form-label fw-semibold" for="g_label">Nazwa grupy <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="g_label" name="label" value="<?= h($g['label']) ?>" required placeholder="np. Dane finansowe, Umowa o współpracy">
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="g_icon">Ikona (Bootstrap Icons)</label>
          <input type="text" class="form-control font-monospace" id="g_icon" name="icon" value="<?= h($g['icon']) ?>" placeholder="bi-card-list">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="g_applies">Dotyczy</label>
          <select class="form-select" id="g_applies" name="applies_to">
            <?php foreach (ORG_FIELD_APPLIES as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $g['applies_to']===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label" for="g_sort">Kolejność</label>
          <input type="number" class="form-control" id="g_sort" name="sort_order" value="<?= (int)$g['sort_order'] ?>" min="0">
        </div>
        <div class="col-sm-3 d-flex align-items-center pt-4">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="g_active" name="is_active" <?= $g['is_active']?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="g_active">Aktywna</label>
          </div>
        </div>
      </div>

      <hr class="my-3">
      <div class="mb-1 fw-semibold d-flex align-items-center gap-2" style="font-size:.9rem"><i class="bi bi-shield-lock text-primary"></i> Uprawnienia do grupy</div>
      <p class="text-muted small mb-3">Puste = wszyscy. Ograniczenie grupy ukrywa wszystkie jej pola przed rolami spoza listy.</p>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-eye text-secondary me-1"></i>Kto może <u>widzieć</u>?</label>
          <div class="border rounded p-2" style="background:#FAFAFA">
            <?php foreach ($all_roles as $rn=>$rl): ?>
            <div class="form-check mb-1">
              <input type="checkbox" class="form-check-input" id="gvis_<?= h($rn) ?>" name="visible_roles[]" value="<?= h($rn) ?>" <?= in_array($rn,$g_vis,true)?'checked':'' ?>>
              <label class="form-check-label" for="gvis_<?= h($rn) ?>" style="font-size:.85rem"><?= h($rl) ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-pencil-square text-secondary me-1"></i>Kto może <u>edytować</u>?</label>
          <div class="border rounded p-2" style="background:#FAFAFA">
            <?php foreach ($all_roles as $rn=>$rl): ?>
            <div class="form-check mb-1">
              <input type="checkbox" class="form-check-input" id="gedit_<?= h($rn) ?>" name="edit_roles[]" value="<?= h($rn) ?>" <?= in_array($rn,$g_edit,true)?'checked':'' ?>>
              <label class="form-check-label" for="gedit_<?= h($rn) ?>" style="font-size:.85rem"><?= h($rl) ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $edit_grp?'Zapisz zmiany':'Dodaj grupę' ?></button>
        <a href="<?= APP_URL ?>/org/field_groups.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:760px">
  <div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-list-ul me-1"></i>Grupy <span class="badge bg-secondary ms-1"><?= count($groups) ?></span></div>
  <?php if (!$groups): ?>
  <div class="card-body text-muted">Brak grup. <a href="?new=1">Dodaj pierwszą grupę</a>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>#</th><th>Nazwa</th><th>Dotyczy</th><th>Pól</th><th>Status</th><th class="text-end">Akcje</th></tr></thead>
      <tbody>
        <?php foreach ($groups as $g):
          $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM org_field_defs WHERE group_id=?", [(int)$g['id']])['c'] ?? 0);
        ?>
        <tr class="<?= $g['is_active']?'':'text-muted' ?>">
          <td><?= (int)$g['id'] ?></td>
          <td class="fw-semibold"><i class="bi <?= h($g['icon']) ?> me-1 text-primary"></i><?= h($g['label']) ?></td>
          <td class="small"><?= h(ORG_FIELD_APPLIES[$g['applies_to']] ?? $g['applies_to']) ?></td>
          <td><span class="badge bg-light text-secondary border"><?= $cnt ?></span></td>
          <td><?= $g['is_active']?'<span class="badge bg-success-subtle text-success border border-success-subtle">Aktywna</span>':'<span class="badge bg-secondary-subtle text-secondary border">Ukryta</span>' ?></td>
          <td class="text-end" style="white-space:nowrap">
            <a href="<?= APP_URL ?>/org/fields.php?new=1" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1" title="Dodaj pole"><i class="bi bi-plus-lg"></i></a>
            <a href="?edit=<?= (int)$g['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć grupę? Pola pozostaną bez przypisania do grupy.')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
