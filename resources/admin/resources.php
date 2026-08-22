<?php
/**
 * resources/admin/resources.php — CRUD zasobów organizacji.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/resources.php';

require_login();
ika_require();
if (!is_admin()) { flash_set('danger','Brak uprawnień.'); header('Location: '.APP_URL.'/resources/'); exit; }
resources_migrate();

$PAGE_TITLE = 'Zarządzanie zasobami';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'category_id'       => ((int)($_POST['category_id'] ?? 0)) ?: null,
            'name'              => trim($_POST['name'] ?? ''),
            'description'       => trim($_POST['description'] ?? ''),
            'location'          => trim($_POST['location'] ?? ''),
            'capacity'          => ((int)($_POST['capacity'] ?? 0)) ?: null,
            'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
            'dysponent_user_id' => ((int)($_POST['dysponent_user_id'] ?? 0)) ?: null,
            'is_active'         => isset($_POST['is_active']) ? 1 : 0,
            'k30_enabled'       => isset($_POST['k30_enabled'])  ? 1 : 0,
            'sort_order'        => (int)($_POST['sort_order'] ?? 0),
        ];
        if (!$data['name']) { flash_set('danger','Nazwa zasobu jest wymagana.'); header('Location: resources.php?edit='.$id); exit; }
        $new_id = res_save($data, $id ?: null);

        // Zapisz godziny dostępności
        foreach (array_keys(RES_DAYS) as $day) {
            $closed = isset($_POST['avail_closed'][$day]);
            $open   = $_POST['avail_open'][$day]  ?? '08:00';
            $close  = $_POST['avail_close'][$day] ?? '16:00';
            res_availability_save($new_id, $day, $open, $close, $closed);
        }

        flash_set('success', $id ? 'Zasób zaktualizowany.' : 'Zasób dodany.');
        header('Location: resources.php?edit=' . $new_id); exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        res_delete($id);
        flash_set('success', 'Zasób usunięty.');
        header('Location: resources.php'); exit;
    }

    if ($op === 'save_field') {
        $resource_id = (int)($_POST['resource_id'] ?? 0);
        $field_id    = (int)($_POST['field_id'] ?? 0);
        $label       = trim($_POST['label'] ?? '');
        if (!$label) { flash_set('danger','Nazwa pola jest wymagana.'); header('Location: resources.php?edit='.$resource_id); exit; }
        $raw_opts = trim($_POST['options_raw'] ?? '');
        $opts_json = '';
        if (($_POST['field_type'] ?? '') === 'select' && $raw_opts) {
            $opts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw_opts))));
            $opts_json = json_encode($opts, JSON_UNESCAPED_UNICODE);
        }
        $fdata = [
            'resource_id' => $resource_id,
            'category_id' => null,
            'label'       => $label,
            'field_type'  => $_POST['field_type'] ?? 'text',
            'options'     => $opts_json,
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
            'sort_order'  => (int)($_POST['sort_order'] ?? 0),
            'is_active'   => 1,
        ];
        if ($field_id) {
            unset($fdata['resource_id'],$fdata['category_id']);
            $set = []; $params = [];
            foreach ($fdata as $k => $v) { $set[] = "$k=?"; $params[] = $v; }
            $params[] = $field_id;
            db()->prepare("UPDATE resource_field_defs SET " . implode(',', $set) . " WHERE id=?")->execute($params);
        } else {
            db_insert('resource_field_defs', $fdata);
        }
        flash_set('success', 'Pole zapisane.');
        header('Location: resources.php?edit=' . $resource_id); exit;
    }

    if ($op === 'delete_field') {
        $field_id    = (int)($_POST['field_id'] ?? 0);
        $resource_id = (int)($_POST['resource_id'] ?? 0);
        db()->prepare("DELETE FROM resource_field_defs WHERE id=?")->execute([$field_id]);
        flash_set('success', 'Pole usunięte.');
        header('Location: resources.php?edit=' . $resource_id); exit;
    }
}

$edit_id  = (int)($_GET['edit'] ?? 0);
$show_new = isset($_GET['new']);
$edit_row = $edit_id ? res_get($edit_id) : null;
$resources_list = res_list(0, false);
$categories     = res_categories(false);
$editors        = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");

$FIELD_TYPES = ['text'=>'Tekst','textarea'=>'Tekst długi','number'=>'Liczba','date'=>'Data',
                'select'=>'Lista wyboru','checkbox'=>'Tak/Nie','url'=>'Link'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(dirname(__DIR__)) . '/panel/includes/pv_ui.php';

$_actions = (!$show_new && !$edit_row)
    ? '<a href="?new=1" class="tz-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj zasób</a> ' : '';
$_actions .= '<a href="categories.php" class="tz-btn tz-btn--ghost"><i class="bi bi-tags" aria-hidden="true"></i>Kategorie</a>';

pv_page_header('Zasoby organizacji', [
    'icon'    => 'bi-box',
    'sub'     => 'Sale, sprzęt i pozostałe zasoby dostępne do rezerwacji',
    'back'    => ['url' => APP_URL . '/resources/admin/index.php', 'label' => 'Rezerwacje'],
    'actions' => $_actions,
]);
?>

<?= flash_html() ?>

<?php if ($show_new || $edit_row):
  $f = $edit_row ?? ['name'=>'','description'=>'','location'=>'','capacity'=>null,'category_id'=>null,
                     'requires_approval'=>0,'dysponent_user_id'=>null,'is_active'=>1,'k30_enabled'=>0,'sort_order'=>0];
  $field_defs  = $edit_row ? db_all("SELECT * FROM resource_field_defs WHERE resource_id=? ORDER BY sort_order,id", [$edit_id]) : [];
  $availability= $edit_row ? res_availability($edit_id) : [];
?>
<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><?= $edit_row ? 'Edytuj: '.h($f['name']) : 'Nowy zasób' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="save">
          <input type="hidden" name="id"    value="<?= (int)($f['id']??0) ?>">

          <div class="row g-3 mb-3">
            <div class="col-sm-8">
              <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" value="<?= h($f['name']) ?>" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold">Kategoria</label>
              <select class="form-select" name="category_id">
                <option value="">— brak —</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)($f['category_id']??0)===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Opis</label>
            <textarea class="form-control" name="description" rows="2"><?= h($f['description']) ?></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label">Lokalizacja</label>
              <input type="text" class="form-control" name="location" value="<?= h($f['location']) ?>" placeholder="np. Sala A, piętro 2">
            </div>
            <div class="col-sm-3">
              <label class="form-label">Pojemność (os.)</label>
              <input type="number" class="form-control" name="capacity" value="<?= h($f['capacity']??'') ?>" min="1">
            </div>
            <div class="col-sm-3">
              <label class="form-label">Kolejność</label>
              <input type="number" class="form-control" name="sort_order" value="<?= (int)$f['sort_order'] ?>" min="0">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Dysponent (osoba zatwierdzająca u siebie)</label>
            <select class="form-select" name="dysponent_user_id">
              <option value="">— brak —</option>
              <?php foreach ($editors as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= (int)($f['dysponent_user_id']??0)===(int)$e['id']?'selected':'' ?>><?= h($e['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="req_app" name="requires_approval"
                       <?= $f['requires_approval']?'checked':'' ?>>
                <label class="form-check-label fw-semibold" for="req_app">Wymaga zatwierdzenia</label>
              </div>
              <div class="form-text">Sala i zasoby specjalne wymagają akceptacji admina i dysponenta.</div>
            </div>
            <div class="col-sm-6">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="is_act" name="is_active"
                       <?= $f['is_active']?'checked':'' ?>>
                <label class="form-check-label" for="is_act">Zasób aktywny</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="k30_en" name="k30_enabled"
                       <?= ($f['k30_enabled']??0)?'checked':'' ?>>
                <label class="form-check-label" for="k30_en">
                  <i class="bi bi-card-list text-primary me-1"></i>Może być użyty w modelu Karty
                </label>
              </div>
              <div class="form-text" style="font-size:.73rem">Zasób pojawi się w systemie rezerwacji podczas dodawania terminu Dydaktyka 3.</div>
            </div>
          </div>

          <!-- Godziny dostępności -->
          <hr class="my-3">
          <div class="fw-semibold mb-2 small text-uppercase text-muted" style="letter-spacing:.07em">
            <i class="bi bi-clock me-1"></i>Godziny dostępności (per dzień tygodnia)
          </div>
          <div class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
              <thead class="table-light">
                <tr>
                  <th>Dzień</th>
                  <th class="text-center" style="width:70px">Zamknięty</th>
                  <th>Od</th>
                  <th>Do</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (RES_DAYS as $dow => $day_name):
                  $a = $availability[$dow] ?? null;
                  $is_closed = $a ? (bool)$a['is_closed'] : false;
                  $t_open    = $a ? $a['time_open']  : '08:00';
                  $t_close   = $a ? $a['time_close'] : '16:00';
                ?>
                <tr id="avail_row_<?= $dow ?>">
                  <td class="fw-semibold"><?= h($day_name) ?></td>
                  <td class="text-center">
                    <input type="checkbox" name="avail_closed[<?= $dow ?>]" value="1"
                           <?= $is_closed?'checked':'' ?>
                           onchange="toggleAvailRow(<?= $dow ?>, this.checked)"
                           class="form-check-input">
                  </td>
                  <td>
                    <input type="time" name="avail_open[<?= $dow ?>]"
                           class="form-control form-control-sm avail_times_<?= $dow ?>"
                           value="<?= h($t_open) ?>"
                           <?= $is_closed?'disabled':'' ?> style="width:110px">
                  </td>
                  <td>
                    <input type="time" name="avail_close[<?= $dow ?>]"
                           class="form-control form-control-sm avail_times_<?= $dow ?>"
                           value="<?= h($t_close) ?>"
                           <?= $is_closed?'disabled':'' ?> style="width:110px">
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <script>
          function toggleAvailRow(dow, closed) {
            document.querySelectorAll('.avail_times_' + dow).forEach(function(el) {
              el.disabled = closed;
              el.style.opacity = closed ? '.4' : '';
            });
          }
          </script>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz zasób</button>
            <a href="resources.php" class="btn btn-outline-secondary">Anuluj</a>
            <?php if ($edit_row): ?>
            <form method="post" class="ms-auto d-inline" onsubmit="return confirm('Usunąć zasób wraz z rezerwacjami?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="delete">
              <input type="hidden" name="id"    value="<?= (int)$f['id'] ?>">
              <button type="submit" class="btn btn-outline-danger">Usuń zasób</button>
            </form>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($edit_row): ?>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        Dodatkowe pola formularza
        <span class="badge bg-secondary ms-1"><?= count($field_defs) ?></span>
      </div>
      <div class="card-body">
        <!-- Lista istniejących pól -->
        <?php foreach ($field_defs as $fd): ?>
        <div class="d-flex align-items-center gap-2 mb-2 p-2 border rounded">
          <div class="flex-grow-1" style="font-size:.83rem">
            <span class="fw-semibold"><?= h($fd['label']) ?></span>
            <span class="text-muted ms-1"><?= h($FIELD_TYPES[$fd['field_type']] ?? $fd['field_type']) ?></span>
            <?php if ($fd['is_required']): ?><span class="text-danger ms-1">*</span><?php endif; ?>
          </div>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pole?')">
            <input type="hidden" name="_csrf"        value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"          value="delete_field">
            <input type="hidden" name="field_id"     value="<?= (int)$fd['id'] ?>">
            <input type="hidden" name="resource_id"  value="<?= $edit_id ?>">
            <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2">
              <i class="bi bi-trash"></i>
            </button>
          </form>
        </div>
        <?php endforeach; ?>

        <!-- Dodaj pole -->
        <hr class="my-3">
        <div class="fw-semibold small mb-2">Dodaj nowe pole</div>
        <form method="post">
          <input type="hidden" name="_csrf"        value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"          value="save_field">
          <input type="hidden" name="resource_id"  value="<?= $edit_id ?>">
          <input type="hidden" name="field_id"     value="0">
          <div class="mb-2">
            <input type="text" class="form-control form-control-sm" name="label" placeholder="Nazwa pola" required>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-7">
              <select class="form-select form-select-sm" name="field_type" id="ftype_new">
                <?php foreach ($FIELD_TYPES as $k=>$v): ?>
                <option value="<?= h($k) ?>"><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-3">
              <input type="number" class="form-control form-control-sm" name="sort_order" value="0" min="0" placeholder="Kol.">
            </div>
            <div class="col-2 d-flex align-items-center">
              <div class="form-check" title="Wymagane">
                <input class="form-check-input" type="checkbox" name="is_required" id="ftrequired">
                <label class="form-check-label small" for="ftrequired">Wym.</label>
              </div>
            </div>
          </div>
          <div id="opts_new" style="display:none" class="mb-2">
            <textarea class="form-control form-control-sm" name="options_raw" rows="3"
                      placeholder="Opcja 1&#10;Opcja 2&#10;Opcja 3"></textarea>
          </div>
          <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="bi bi-plus me-1"></i>Dodaj pole
          </button>
        </form>
        <script>
        document.getElementById('ftype_new').addEventListener('change', function() {
          document.getElementById('opts_new').style.display = this.value === 'select' ? '' : 'none';
        });
        </script>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Lista zasobów -->
<div class="card border-0 shadow-sm" style="max-width:900px">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    Wszystkie zasoby <span class="badge bg-secondary ms-1"><?= count($resources_list) ?></span>
  </div>
  <?php if (!$resources_list): ?>
  <div class="card-body text-muted">Brak zasobów. <a href="?new=1">Dodaj pierwszy</a>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.87rem">
      <thead class="table-light">
        <tr><th>Zasób</th><th>Kategoria</th><th>Lokalizacja</th><th class="text-center">Zgoda</th><th>Dysponent</th><th>Status</th><th class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($resources_list as $r): ?>
        <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
          <td class="fw-semibold"><?= h($r['name']) ?></td>
          <td>
            <?php if ($r['cat_icon']): ?>
            <i class="bi <?= h($r['cat_icon']) ?>" style="color:<?= h($r['cat_color']) ?>"></i>
            <?php endif; ?>
            <?= h($r['cat_name'] ?? '—') ?>
          </td>
          <td><?= h($r['location'] ?: '—') ?></td>
          <td class="text-center">
            <?= $r['requires_approval']
              ? '<i class="bi bi-shield-check text-warning" title="Wymaga zatwierdzenia"></i>'
              : '<i class="bi bi-lightning-charge text-success" title="Automatycznie"></i>' ?>
          </td>
          <td><?= h($r['dysponent_name'] ?? '—') ?></td>
          <td><?= $r['is_active']
            ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Aktywny</span>'
            : '<span class="badge bg-secondary-subtle text-secondary border">Ukryty</span>' ?></td>
          <td class="text-end">
            <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">
              <i class="bi bi-pencil"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
