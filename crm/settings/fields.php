<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) {
    flash_set('danger', 'Tylko administrator może zarządzać polami kontaktów.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();

$PAGE_TITLE = 'CRM — Pola kontaktów';

$FIELD_TYPES = [
    'text'     => 'Tekst (jeden wiersz)',
    'textarea' => 'Tekst (wiele wierszy)',
    'number'   => 'Liczba',
    'date'     => 'Data',
    'select'   => 'Lista wyboru',
    'url'      => 'URL / Link',
    'email'    => 'Adres e-mail',
    'checkbox' => 'Checkbox (tak/nie)',
];

$APPLIES_TO = [
    'both'        => 'Wszystkie kontakty',
    'osoba'       => 'Tylko osoby',
    'organizacja' => 'Tylko organizacje',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            flash_set('danger', 'Nazwa pola jest wymagana.');
            header('Location: ' . APP_URL . '/crm/settings/fields.php' . ($id ? "?edit=$id" : '?new=1'));
            exit;
        }
        $raw_opts = trim($_POST['options_raw'] ?? '');
        $options_json = '';
        if (($_POST['field_type'] ?? '') === 'select' && $raw_opts !== '') {
            $opts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw_opts))));
            $options_json = json_encode($opts, JSON_UNESCAPED_UNICODE);
        }
        CrmManager::saveFieldDef([
            'label'      => $label,
            'field_type' => $_POST['field_type'] ?? 'text',
            'options'    => $options_json,
            'applies_to' => $_POST['applies_to'] ?? 'both',
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            'group_id'   => (int)($_POST['group_id'] ?? 0) ?: null,
        ], $id ?: null);
        flash_set('success', $id ? 'Pole zaktualizowane.' : 'Pole dodane.');
        header('Location: ' . APP_URL . '/crm/settings/fields.php');
        exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { CrmManager::deleteFieldDef($id); flash_set('success', 'Pole usunięte wraz z wartościami.'); }
        header('Location: ' . APP_URL . '/crm/settings/fields.php');
        exit;
    }

    if ($op === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $def = CrmManager::getFieldDef($id);
        if ($def) CrmManager::saveFieldDef(array_merge($def, ['is_active' => $def['is_active'] ? 0 : 1]), $id);
        header('Location: ' . APP_URL . '/crm/settings/fields.php');
        exit;
    }
}

$edit_id  = (int)($_GET['edit'] ?? 0);
$show_new = isset($_GET['new']);
$edit_def = $edit_id ? CrmManager::getFieldDef($edit_id) : null;
$defs     = CrmManager::getFieldDefs('', false);

include __DIR__ . '/../includes/header_crm.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Pola kontaktów</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-layout-text-sidebar-reverse"></i></div>
  <div>
    <h1 class="crm-object-title">Dodatkowe pola kontaktów</h1>
    <div class="crm-object-count"><?= count($defs) ?> zdefiniowanych pól</div>
  </div>
  <div class="crm-object-actions d-flex gap-2">
    <?php if (!$show_new && !$edit_def): ?>
    <a href="?new=1" class="btn btn-sm btn-crm-primary">
      <i class="bi bi-plus-lg me-1"></i>Dodaj pole
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/settings/" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Ustawienia
    </a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($show_new || $edit_def):
  $f = $edit_def ?? ['label'=>'','field_type'=>'text','options'=>'','applies_to'=>'both','sort_order'=>0,'is_active'=>1];
  $raw_opts_val = '';
  if ($f['options']) {
      $decoded = json_decode($f['options'], true);
      if (is_array($decoded)) $raw_opts_val = implode("\n", $decoded);
  }
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:640px">
  <div class="card-header fw-semibold">
    <?= $edit_def ? 'Edytuj pole: <em>' . h($f['label']) . '</em>' : 'Nowe pole' ?>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save">
      <input type="hidden" name="id"    value="<?= (int)($f['id'] ?? 0) ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold" for="f_label">Nazwa pola <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="f_label" name="label"
               value="<?= h($f['label']) ?>" required
               placeholder="np. Budżet projektu, Data wygaśnięcia">
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="f_type">Typ pola</label>
          <select class="form-select" id="f_type" name="field_type">
            <?php foreach ($FIELD_TYPES as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $f['field_type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="f_applies">Dotyczy</label>
          <select class="form-select" id="f_applies" name="applies_to">
            <?php foreach ($APPLIES_TO as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $f['applies_to'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" for="f_group">Grupa pól</label>
          <?php $field_groups = crm_all("SELECT id, label FROM crm_field_groups WHERE is_active=1 ORDER BY sort_order, id"); ?>
          <select class="form-select" id="f_group" name="group_id">
            <option value="">— bez grupy —</option>
            <?php foreach ($field_groups as $fg): ?>
            <option value="<?= $fg['id'] ?>" <?= (int)($f['group_id'] ?? 0) === (int)$fg['id'] ? 'selected' : '' ?>>
              <?= h($fg['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mb-3" id="opts_wrap" <?= $f['field_type'] !== 'select' ? 'style="display:none"' : '' ?>>
        <label class="form-label fw-semibold" for="f_options">Opcje listy (jedna na linię lub po przecinku)</label>
        <textarea class="form-control font-monospace" id="f_options" name="options_raw"
                  rows="4" placeholder="Opcja 1&#10;Opcja 2&#10;Opcja 3"><?= h($raw_opts_val) ?></textarea>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label" for="f_sort">Kolejność</label>
          <input type="number" class="form-control" id="f_sort" name="sort_order"
                 value="<?= (int)$f['sort_order'] ?>" min="0" step="1">
          <div class="form-text">Mniejsza = wyżej.</div>
        </div>
        <div class="col-sm-8 d-flex align-items-center pt-4">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="f_active" name="is_active" <?= $f['is_active'] ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="f_active">Pole aktywne</label>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-crm-primary">
          <i class="bi bi-check-lg me-1"></i><?= $edit_def ? 'Zapisz zmiany' : 'Dodaj pole' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/settings/fields.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('f_type').addEventListener('change', function() {
  document.getElementById('opts_wrap').style.display = this.value === 'select' ? '' : 'none';
});
</script>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:860px">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-ul me-1"></i>Zdefiniowane pola
    <span class="badge bg-secondary ms-1"><?= count($defs) ?></span>
  </div>
  <?php if (!$defs): ?>
  <div class="card-body text-muted">Brak zdefiniowanych pól. <a href="?new=1">Dodaj pierwsze pole</a>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Nazwa</th><th>Typ</th><th>Dotyczy</th><th>Grupa</th><th>Kolejność</th><th>Status</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($defs as $d): ?>
        <tr class="<?= $d['is_active'] ? '' : 'text-muted' ?>">
          <td><?= (int)$d['id'] ?></td>
          <td class="fw-semibold"><?= h($d['label']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= h($FIELD_TYPES[$d['field_type']] ?? $d['field_type']) ?></span></td>
          <td><?= h($APPLIES_TO[$d['applies_to']] ?? $d['applies_to']) ?></td>
          <td class="text-muted small">
            <?php if ($d['group_id']): $grp = crm_one("SELECT label FROM crm_field_groups WHERE id=?",[(int)$d['group_id']]); ?>
            <?= $grp ? h($grp['label']) : '<span class="text-muted">—</span>' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= (int)$d['sort_order'] ?></td>
          <td>
            <?= $d['is_active']
              ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Aktywne</span>'
              : '<span class="badge bg-secondary-subtle text-secondary border">Ukryte</span>' ?>
          </td>
          <td class="text-end">
            <a href="?edit=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="toggle">
              <input type="hidden" name="id"    value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-sm py-0 px-2 me-1 <?= $d['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      title="<?= $d['is_active'] ? 'Ukryj' : 'Aktywuj' ?>">
                <i class="bi <?= $d['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
              </button>
            </form>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć pole wraz ze wszystkimi wartościami?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="delete">
              <input type="hidden" name="id"    value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="mt-3 text-muted small">
  <i class="bi bi-info-circle me-1"></i>
  Pola aktywne są widoczne w formularzach i na kartach kontaktów. Ukryte zachowują wartości.
</div>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
