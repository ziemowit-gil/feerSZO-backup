<?php
/**
 * strategy/spheres/index.php — Zarządzanie Sferami Pożytku Publicznego.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/strategy.php';

require_login();
if (!can_edit() && !is_admin()) {
    flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/strategy/index.php'); exit;
}
$PAGE_TITLE = 'Sfery Pożytku Publicznego';

$id = (int)($_GET['id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'toggle') {
        $tid = (int)($_POST['sphere_id'] ?? 0);
        if ($tid) {
            $s = db_one("SELECT is_active FROM public_benefit_spheres WHERE id=?", [$tid]);
            if ($s) db()->prepare("UPDATE public_benefit_spheres SET is_active=? WHERE id=?")->execute([$s['is_active']?0:1, $tid]);
        }
        flash_set('success', 'Status zmieniony.');
        header('Location: ' . APP_URL . '/strategy/spheres/index.php'); exit;
    }

    if ($action === 'delete') {
        $tid = (int)($_POST['sphere_id'] ?? 0);
        $used = db_one("SELECT COUNT(*) AS c FROM strategy_objectives WHERE sphere_id=?", [$tid]);
        if (($used['c']??0) > 0) {
            flash_set('error', 'Nie można usunąć — sfera ma przypisane cele.');
        } else {
            db()->prepare("DELETE FROM public_benefit_spheres WHERE id=?")->execute([$tid]);
            flash_set('success', 'Sfera usunięta.');
        }
        header('Location: ' . APP_URL . '/strategy/spheres/index.php'); exit;
    }

    // save
    $kod   = trim($_POST['kod'] ?? '');
    $nazwa = trim($_POST['nazwa'] ?? '');
    $opis  = trim($_POST['opis'] ?? '');
    $kolor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $_POST['kolor']??'') ? $_POST['kolor'] : '#2563eb';
    $ikona = trim($_POST['ikona'] ?? 'bi-globe2');
    $sort  = (int)($_POST['sort_order'] ?? 0);

    if (!$kod)   $errors[] = 'Kod sfery jest wymagany.';
    if (!$nazwa) $errors[] = 'Nazwa sfery jest wymagana.';

    if (!$errors) {
        if ($id) {
            db()->prepare("UPDATE public_benefit_spheres SET kod=?,nazwa=?,opis=?,kolor=?,ikona=?,sort_order=? WHERE id=?")
               ->execute([$kod,$nazwa,$opis,$kolor,$ikona,$sort,$id]);
            flash_set('success', 'Sfera zaktualizowana.');
        } else {
            db_insert('public_benefit_spheres', ['kod'=>$kod,'nazwa'=>$nazwa,'opis'=>$opis,'kolor'=>$kolor,'ikona'=>$ikona,'sort_order'=>$sort,'is_active'=>1]);
            flash_set('success', 'Sfera dodana.');
        }
        header('Location: ' . APP_URL . '/strategy/spheres/index.php'); exit;
    }
}

$sphere  = $id ? db_one("SELECT * FROM public_benefit_spheres WHERE id=?", [$id]) : null;
$spheres = db_all("SELECT s.*, (SELECT COUNT(*) FROM strategy_objectives o WHERE o.sphere_id=s.id) AS obj_count
                   FROM public_benefit_spheres s ORDER BY sort_order, kod");

include dirname(__DIR__) . '/includes/header_strategy.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <h1 style="font-size:1.35rem;font-weight:800;margin:0">
    <i class="bi bi-globe2 me-2" style="color:var(--strat-accent)"></i>Sfery Pożytku Publicznego
  </h1>
  <button class="btn btn-sm ms-auto"
          style="background:var(--strat-accent);color:#fff;border:none"
          data-bs-toggle="modal" data-bs-target="#sphereModal"
          onclick="resetForm()">
    <i class="bi bi-plus-lg me-1"></i>Dodaj sferę
  </button>
</div>

<?= flash_html() ?>
<?php if (!empty($errors)): ?>
<div class="strat-alert strat-alert-danger" role="alert">
  <i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;flex-shrink:0;margin-top:.15rem"></i>
  <ul class="mb-0 ps-3"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm">
<table class="table table-hover align-middle mb-0" style="font-size:.86rem">
  <thead class="table-light">
    <tr>
      <th style="width:70px">Kod</th>
      <th>Nazwa</th>
      <th style="width:70px" class="text-center">Cele</th>
      <th style="width:90px" class="text-center">Status</th>
      <th style="width:80px"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($spheres as $s): ?>
    <tr>
      <td>
        <span class="badge fw-semibold" style="background:<?= h($s['kolor']) ?>">
          <?= h($s['kod']) ?>
        </span>
      </td>
      <td>
        <i class="bi <?= h($s['ikona']) ?> me-1" style="color:<?= h($s['kolor']) ?>"></i>
        <strong><?= h($s['nazwa']) ?></strong>
        <?php if ($s['opis']): ?><div class="text-muted small"><?= h(mb_substr($s['opis'],0,80)) ?></div><?php endif; ?>
      </td>
      <td class="text-center"><span class="badge bg-secondary"><?= (int)$s['obj_count'] ?></span></td>
      <td class="text-center">
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="toggle">
          <input type="hidden" name="sphere_id" value="<?= $s['id'] ?>">
          <button type="submit" class="btn btn-sm <?= $s['is_active']?'btn-success':'btn-outline-secondary' ?> py-0 px-2">
            <?= $s['is_active']?'Aktywna':'Ukryta' ?>
          </button>
        </form>
      </td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                onclick="editSphere(<?= htmlspecialchars(json_encode($s),ENT_QUOTES) ?>)">
          <i class="bi bi-pencil"></i>
        </button>
        <?php if ((int)$s['obj_count'] === 0): ?>
        <form method="post" class="d-inline ms-1" onsubmit="return confirm('Usunąć?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="sphere_id" value="<?= $s['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$spheres): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">Brak sfer.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<!-- Modal -->
<div class="modal fade" id="sphereModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sphereModalTitle">Nowa sfera</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="sphereForm" action="<?= APP_URL ?>/strategy/spheres/index.php">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-4">
              <label class="form-label fw-semibold small">Kod *</label>
              <input name="kod" id="f_kod" class="form-control form-control-sm font-monospace" placeholder="SP-01" maxlength="10" required>
            </div>
            <div class="col-8">
              <label class="form-label fw-semibold small">Nazwa *</label>
              <input name="nazwa" id="f_nazwa" class="form-control form-control-sm" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Opis</label>
              <textarea name="opis" id="f_opis" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold small">Kolor</label>
              <input name="kolor" id="f_kolor" type="color" class="form-control form-control-sm form-control-color" value="#2563eb">
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold small">Ikona (bi-*)</label>
              <input name="ikona" id="f_ikona" class="form-control form-control-sm" placeholder="bi-globe2" value="bi-globe2">
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold small">Kolejność</label>
              <input name="sort_order" id="f_sort" type="number" class="form-control form-control-sm" value="0" min="0">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm"
                  style="background:var(--strat-accent);color:#fff;border:none">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function resetForm() {
  document.getElementById('sphereModalTitle').textContent = 'Nowa sfera';
  document.getElementById('sphereForm').action = '<?= APP_URL ?>/strategy/spheres/index.php';
  ['kod','nazwa','opis'].forEach(f => { var el = document.getElementById('f_' + f); if(el) el.value=''; });
  document.getElementById('f_ikona').value = 'bi-globe2';
  document.getElementById('f_kolor').value = '#2563eb';
  document.getElementById('f_sort').value  = '0';
}
function editSphere(s) {
  document.getElementById('sphereModalTitle').textContent = 'Edytuj sferę';
  document.getElementById('sphereForm').action = '<?= APP_URL ?>/strategy/spheres/index.php?id=' + s.id;
  document.getElementById('f_kod').value   = s.kod;
  document.getElementById('f_nazwa').value = s.nazwa;
  document.getElementById('f_opis').value  = s.opis || '';
  document.getElementById('f_kolor').value = s.kolor || '#2563eb';
  document.getElementById('f_ikona').value = s.ikona || 'bi-globe2';
  document.getElementById('f_sort').value  = s.sort_order || 0;
  new bootstrap.Modal(document.getElementById('sphereModal')).show();
}
</script>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
