<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$PAGE_TITLE = 'Struktura — Pola definiowane';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            flash_set('danger', 'Nazwa pola jest wymagana.');
            header('Location: ' . APP_URL . '/org/fields.php' . ($id ? "?edit=$id" : '?new=1'));
            exit;
        }
        $options_json = '';
        if (($_POST['field_type'] ?? '') === 'select') {
            $raw  = trim($_POST['options_raw'] ?? '');
            $opts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw))));
            $options_json = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : '';
        }
        org_field_def_save([
            'label'         => $label,
            'field_type'    => $_POST['field_type'] ?? 'text',
            'options'       => $options_json,
            'applies_to'    => $_POST['applies_to'] ?? 'both',
            'group_id'      => (int)($_POST['group_id'] ?? 0) ?: null,
            'sort_order'    => (int)($_POST['sort_order'] ?? 0),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            'visible_roles' => (array)($_POST['visible_roles'] ?? []),
            'edit_roles'    => (array)($_POST['edit_roles'] ?? []),
        ], $id ?: null);
        flash_set('success', $id ? 'Pole zaktualizowane.' : 'Pole dodane.');
        header('Location: ' . APP_URL . '/org/fields.php');
        exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { org_field_def_delete($id); flash_set('success', 'Pole usunięte wraz z wartościami.'); }
        header('Location: ' . APP_URL . '/org/fields.php');
        exit;
    }

    if ($op === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $def = org_field_def_get($id);
        if ($def) {
            org_field_def_save(array_merge($def, [
                'is_active'     => $def['is_active'] ? 0 : 1,
                'visible_roles' => json_decode($def['visible_roles'] ?? '', true) ?: [],
                'edit_roles'    => json_decode($def['edit_roles'] ?? '', true) ?: [],
            ]), $id);
        }
        header('Location: ' . APP_URL . '/org/fields.php');
        exit;
    }
}

$edit_id   = (int)($_GET['edit'] ?? 0);
$show_new  = isset($_GET['new']);
$edit_def  = $edit_id ? org_field_def_get($edit_id) : null;
$all_roles = org_all_roles();
$defs      = org_field_defs('', false);
$groups    = [];
foreach (org_field_groups_all(false) as $g) $groups[(int)$g['id']] = $g;

include dirname(__DIR__) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <li class="breadcrumb-item active">Pola definiowane</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-layout-text-sidebar-reverse text-primary me-2"></i>Pola definiowane</h4>
    <div class="text-muted" style="font-size:.78rem;margin-top:.1rem">Własne pola jednostek (wewnętrznych i zewnętrznych) z uprawnieniami per&nbsp;rola</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (!$show_new && !$edit_def): ?>
    <a href="?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Dodaj pole</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/org/field_groups.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-layers me-1"></i>Grupy pól</a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($show_new || $edit_def):
  $f = $edit_def ?? ['label'=>'','field_type'=>'text','options'=>'','applies_to'=>'both','group_id'=>0,'sort_order'=>0,'is_active'=>1,'visible_roles'=>'','edit_roles'=>''];
  $raw_opts_val = '';
  if ($f['options']) { $dec = json_decode($f['options'], true); if (is_array($dec)) $raw_opts_val = implode("\n", $dec); }
  $f_vis  = json_decode($f['visible_roles'] ?? '', true) ?: [];
  $f_edit = json_decode($f['edit_roles']    ?? '', true) ?: [];
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:680px">
  <div class="card-header fw-semibold"><?= $edit_def ? 'Edytuj pole: <em>'.h($f['label']).'</em>' : 'Nowe pole' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save">
      <input type="hidden" name="id"    value="<?= (int)($f['id'] ?? 0) ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold" for="f_label">Nazwa pola <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="f_label" name="label" value="<?= h($f['label']) ?>" required
               placeholder="np. Numer umowy ramowej, Region działania">
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="f_type">Typ pola</label>
          <select class="form-select" id="f_type" name="field_type">
            <?php foreach (ORG_FIELD_TYPES as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $f['field_type']===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="f_applies">Dotyczy</label>
          <select class="form-select" id="f_applies" name="applies_to">
            <?php foreach (ORG_FIELD_APPLIES as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $f['applies_to']===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="f_group">Grupa pól</label>
          <select class="form-select" id="f_group" name="group_id">
            <option value="">— bez grupy —</option>
            <?php foreach ($groups as $g): if (!$g['is_active']) continue; ?>
            <option value="<?= (int)$g['id'] ?>" <?= (int)($f['group_id']??0)===(int)$g['id']?'selected':'' ?>><?= h($g['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mb-3" id="opts_wrap" <?= $f['field_type']!=='select'?'style="display:none"':'' ?>>
        <label class="form-label fw-semibold" for="f_options">Opcje listy (jedna na linię lub po przecinku)</label>
        <textarea class="form-control font-monospace" id="f_options" name="options_raw" rows="4"
                  placeholder="Opcja 1&#10;Opcja 2&#10;Opcja 3"><?= h($raw_opts_val) ?></textarea>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label" for="f_sort">Kolejność</label>
          <input type="number" class="form-control" id="f_sort" name="sort_order" value="<?= (int)$f['sort_order'] ?>" min="0" step="1">
          <div class="form-text">Mniejsza = wyżej.</div>
        </div>
        <div class="col-sm-8 d-flex align-items-center pt-4">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="f_active" name="is_active" <?= $f['is_active']?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="f_active">Pole aktywne</label>
          </div>
        </div>
      </div>

      <hr class="my-3">
      <div class="mb-1 fw-semibold d-flex align-items-center gap-2" style="font-size:.9rem">
        <i class="bi bi-shield-lock text-primary"></i> Uprawnienia dostępu do pola
      </div>
      <p class="text-muted small mb-3">Zostaw puste = <strong>wszyscy</strong> mogą widzieć / edytować. Zaznacz role, by ograniczyć.</p>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-eye text-secondary me-1"></i>Kto może <u>widzieć</u>?</label>
          <div class="border rounded p-2" style="background:#FAFAFA">
            <?php foreach ($all_roles as $rn=>$rl): ?>
            <div class="form-check mb-1">
              <input type="checkbox" class="form-check-input" id="vis_<?= h($rn) ?>" name="visible_roles[]" value="<?= h($rn) ?>" <?= in_array($rn,$f_vis,true)?'checked':'' ?>>
              <label class="form-check-label" for="vis_<?= h($rn) ?>" style="font-size:.85rem"><?= h($rl) ?> <span class="text-muted font-monospace" style="font-size:.75rem">(<?= h($rn) ?>)</span></label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Puste = wszyscy z dostępem do modułu.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-pencil-square text-secondary me-1"></i>Kto może <u>edytować</u>?</label>
          <div class="border rounded p-2" style="background:#FAFAFA">
            <?php foreach ($all_roles as $rn=>$rl): ?>
            <div class="form-check mb-1">
              <input type="checkbox" class="form-check-input" id="edit_<?= h($rn) ?>" name="edit_roles[]" value="<?= h($rn) ?>" <?= in_array($rn,$f_edit,true)?'checked':'' ?>>
              <label class="form-check-label" for="edit_<?= h($rn) ?>" style="font-size:.85rem"><?= h($rl) ?> <span class="text-muted font-monospace" style="font-size:.75rem">(<?= h($rn) ?>)</span></label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Puste = kto widzi, ten edytuje.</div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $edit_def?'Zapisz zmiany':'Dodaj pole' ?></button>
        <a href="<?= APP_URL ?>/org/fields.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('f_type').addEventListener('change', function(){
  document.getElementById('opts_wrap').style.display = this.value === 'select' ? '' : 'none';
});
</script>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:920px">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-ul me-1"></i>Zdefiniowane pola <span class="badge bg-secondary ms-1"><?= count($defs) ?></span>
  </div>
  <?php if (!$defs): ?>
  <div class="card-body text-muted">Brak zdefiniowanych pól. <a href="?new=1">Dodaj pierwsze pole</a>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>#</th><th>Nazwa</th><th>Typ</th><th>Dotyczy</th><th>Grupa</th>
            <th class="d-none d-lg-table-cell">Dostęp</th><th>Status</th><th class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($defs as $d): ?>
        <tr class="<?= $d['is_active']?'':'text-muted' ?>">
          <td><?= (int)$d['id'] ?></td>
          <td class="fw-semibold"><?= h($d['label']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= h(ORG_FIELD_TYPES[$d['field_type']] ?? $d['field_type']) ?></span></td>
          <td class="small"><?= h(ORG_FIELD_APPLIES[$d['applies_to']] ?? $d['applies_to']) ?></td>
          <td class="text-muted small"><?= $d['group_id'] && isset($groups[(int)$d['group_id']]) ? h($groups[(int)$d['group_id']]['label']) : '—' ?></td>
          <td class="d-none d-lg-table-cell" style="min-width:140px">
            <?php $vr=json_decode($d['visible_roles']??'',true)?:[]; $er=json_decode($d['edit_roles']??'',true)?:[];
            if (!$vr && !$er): ?><span class="text-muted small">wszyscy</span><?php else:
              foreach ($vr as $rn): ?><span class="badge bg-info-subtle text-info border border-info-subtle me-1" style="font-size:.68rem"><i class="bi bi-eye"></i> <?= h($all_roles[$rn]??$rn) ?></span><?php endforeach;
              foreach ($er as $rn): ?><span class="badge bg-warning-subtle text-warning border border-warning-subtle me-1" style="font-size:.68rem"><i class="bi bi-pencil"></i> <?= h($all_roles[$rn]??$rn) ?></span><?php endforeach;
            endif; ?>
          </td>
          <td><?= $d['is_active']?'<span class="badge bg-success-subtle text-success border border-success-subtle">Aktywne</span>':'<span class="badge bg-secondary-subtle text-secondary border">Ukryte</span>' ?></td>
          <td class="text-end" style="white-space:nowrap">
            <a href="?edit=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-sm py-0 px-2 me-1 <?= $d['is_active']?'btn-outline-warning':'btn-outline-success' ?>" title="<?= $d['is_active']?'Ukryj':'Aktywuj' ?>"><i class="bi <?= $d['is_active']?'bi-eye-slash':'bi-eye' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pole wraz ze wszystkimi wartościami?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
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

<div class="mt-3 text-muted small"><i class="bi bi-info-circle me-1"></i>Pola aktywne pojawiają się w formularzach jednostek i na ich kartach. Ukryte zachowują wartości.</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
