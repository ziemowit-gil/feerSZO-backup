<?php
/**
 * resources/admin/categories.php — CRUD kategorii zasobów.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/modules/srs/logic/srs.php';

require_login();
ika_require();
if (!is_admin()) { flash_set('danger','Brak uprawnień.'); header('Location: '.APP_URL.'/modules/srs/'); exit; }
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

include dirname(__DIR__, 3) . '/includes/header.php';
require_once dirname(__DIR__, 3) . '/panel/includes/pv_ui.php';

pv_page_header('Kategorie zasobów', [
    'icon'    => 'bi-tags',
    'sub'     => 'Grupy zasobów widoczne jako filtr na liście rezerwacji',
    'back'    => ['url' => APP_URL . '/modules/srs/admin/index.php', 'label' => 'Rezerwacje'],
    'actions' => (!$show_new && !$edit_row)
        ? '<a href="?new=1" class="tz-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj kategorię</a>' : '',
]);
?>

<div class="pv-wrap" style="max-width:900px">

<?= flash_html() ?>

<?php if ($show_new || $edit_row):
  $f = $edit_row ?? ['name'=>'','icon'=>'bi-box','color'=>'#6366f1','sort_order'=>0,'is_active'=>1];
?>
<div class="tz-card mb-4" style="max-width:540px">
  <div class="tz-card__hd"><i class="bi bi-tags" aria-hidden="true"></i><?= $edit_row ? 'Edytuj kategorię' : 'Nowa kategoria' ?></div>
  <div class="tz-card__bd">
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

<div class="tz-card mb-0">
  <div class="tz-card__hd"><i class="bi bi-list-ul" aria-hidden="true"></i>Wszystkie kategorie <span class="tz-badge tz-badge--muted ms-1"><?= count($cats) ?></span></div>
  <?php if (!$cats): ?>
  <div class="tz-empty">
    <i class="bi bi-tags" aria-hidden="true"></i>
    <p class="mb-0">Brak kategorii. <a href="?new=1">Dodaj pierwszą</a>.</p>
  </div>
  <?php else: ?>
  <div class="pv-table-wrap" style="border:0;border-radius:0">
    <table class="pv-table">
      <caption class="visually-hidden">Kategorie zasobów</caption>
      <thead>
        <tr>
          <th scope="col">Ikona</th><th scope="col">Nazwa</th><th scope="col" class="text-center">Zasoby</th>
          <th scope="col">Kol.</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cats as $c): ?>
        <tr>
          <td><span style="color:<?= h($c['color']) ?>;font-size:1.2rem"><i class="bi <?= h($c['icon']) ?>" aria-hidden="true"></i></span></td>
          <td class="fw-semibold"><?= h($c['name']) ?></td>
          <td class="text-center"><?= (int)$c['res_count'] ?></td>
          <td><?= (int)$c['sort_order'] ?></td>
          <td>
            <?= $c['is_active']
              ? '<span class="tz-badge tz-badge--success">Aktywna</span>'
              : '<span class="tz-badge tz-badge--muted">Ukryta</span>' ?>
          </td>
          <td class="text-end">
            <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"
               aria-label="Edytuj kategorię <?= h($c['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
            <?php if (!$c['res_count']): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć kategorię?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="delete">
              <input type="hidden" name="id"    value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                      aria-label="Usuń kategorię <?= h($c['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </form>
            <?php else: ?>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Usuń najpierw zasoby tej kategorii">
              <i class="bi bi-lock" aria-hidden="true"></i>
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

</div><!-- /pv-wrap -->

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
