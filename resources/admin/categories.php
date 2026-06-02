<?php
/**
 * resources/admin/categories.php — CRUD kategorii zasobów.
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

$PAGE_TITLE = 'Kategorie zasobów';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $icon  = trim($_POST['icon'] ?? 'bi-box');
        $color = trim($_POST['color'] ?? '#6366f1');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if (!$name) { flash_set('danger','Nazwa jest wymagana.'); header('Location: categories.php?edit='.$id); exit; }
        if ($id) {
            db()->prepare("UPDATE resource_categories SET name=?,icon=?,color=?,sort_order=?,is_active=? WHERE id=?")
               ->execute([$name,$icon,$color,$sort,$active,$id]);
        } else {
            db_insert('resource_categories', ['name'=>$name,'icon'=>$icon,'color'=>$color,'sort_order'=>$sort,'is_active'=>1]);
        }
        flash_set('success', $id ? 'Kategoria zaktualizowana.' : 'Kategoria dodana.');
        header('Location: categories.php'); exit;
    }
    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM resource_categories WHERE id=?")->execute([$id]);
        flash_set('success', 'Kategoria usunięta.');
        header('Location: categories.php'); exit;
    }
}

$edit_id  = (int)($_GET['edit'] ?? 0);
$show_new = isset($_GET['new']);
$edit_row = $edit_id ? db_one("SELECT * FROM resource_categories WHERE id=?", [$edit_id]) : null;
$cats     = db_all("SELECT c.*, (SELECT COUNT(*) FROM resources r WHERE r.category_id=c.id) AS res_count FROM resource_categories c ORDER BY c.sort_order, c.name");

$ICONS = ['bi-box','bi-door-open-fill','bi-pc-display','bi-router-fill','bi-camera-video-fill','bi-easel-fill',
          'bi-car-front-fill','bi-bicycle','bi-tools','bi-lightning-charge-fill','bi-building','bi-tv'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-tags text-primary me-1"></i>Kategorie zasobów</h4>
  <div class="ms-auto d-flex gap-2">
    <?php if (!$show_new && !$edit_row): ?>
    <a href="?new=1" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Dodaj</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/resources/admin/" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Rezerwacje
    </a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($show_new || $edit_row):
  $f = $edit_row ?? ['name'=>'','icon'=>'bi-box','color'=>'#6366f1','sort_order'=>0,'is_active'=>1];
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:540px">
  <div class="card-header fw-semibold"><?= $edit_row ? 'Edytuj kategorię' : 'Nowa kategoria' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save">
      <input type="hidden" name="id"    value="<?= (int)($f['id']??0) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="name" value="<?= h($f['name']) ?>" required>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Kolor</label>
          <input type="color" class="form-control form-control-color" name="color" value="<?= h($f['color']) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Kolejność</label>
          <input type="number" class="form-control" name="sort_order" value="<?= (int)$f['sort_order'] ?>" min="0">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Ikona Bootstrap Icons</label>
        <input type="text" class="form-control font-monospace" name="icon" id="iconInput" value="<?= h($f['icon']) ?>">
        <div class="d-flex flex-wrap gap-1 mt-2">
          <?php foreach ($ICONS as $ic): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 icon-pick"
                  data-icon="<?= h($ic) ?>" title="<?= h($ic) ?>">
            <i class="bi <?= h($ic) ?>"></i>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($edit_row): ?>
      <div class="mb-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="is_active" id="ca" <?= $f['is_active']?'checked':'' ?>>
          <label class="form-check-label" for="ca">Aktywna</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Zapisz</button>
        <a href="categories.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.icon-pick').forEach(b => {
  b.addEventListener('click', () => { document.getElementById('iconInput').value = b.dataset.icon; });
});
</script>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:700px">
  <table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light">
      <tr><th>Ikona</th><th>Nazwa</th><th class="text-center">Zasoby</th><th>Kol.</th><th>Status</th><th class="text-end">Akcje</th></tr>
    </thead>
    <tbody>
      <?php foreach ($cats as $c): ?>
      <tr>
        <td><span style="color:<?= h($c['color']) ?>;font-size:1.2rem"><i class="bi <?= h($c['icon']) ?>"></i></span></td>
        <td class="fw-semibold"><?= h($c['name']) ?></td>
        <td class="text-center"><?= (int)$c['res_count'] ?></td>
        <td><?= (int)$c['sort_order'] ?></td>
        <td><?= $c['is_active'] ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Aktywna</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Ukryta</span>' ?></td>
        <td class="text-end">
          <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"><i class="bi bi-pencil"></i></a>
          <?php if (!$c['res_count']): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć kategorię?')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"   value="delete">
            <input type="hidden" name="id"    value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
          </form>
          <?php else: ?>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Usuń najpierw zasoby tej kategorii"><i class="bi bi-lock"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
