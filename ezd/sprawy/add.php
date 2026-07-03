<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł kancelarii');

$users  = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$teczki = ezd_teczki_all('open');

// Tryb podsprawy — parent_id z GET/POST
$parent_id = (int)($_GET['parent_id'] ?? $_POST['parent_id'] ?? 0);
$parent    = $parent_id ? ezd_sprawa_get($parent_id) : null;
$PAGE_TITLE = $parent ? 'Nowa podsprawa' : 'Nowa sprawa';

$_can_create = $parent
    ? ezd_sprawa_access($parent, (int)current_user()['id']) === 'write'
    : can_edit();
if (!$_can_create) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }

// pre-select teczka if passed via GET (podsprawa dziedziczy teczkę rodzica)
$preselect_teczka = $parent ? (int)$parent['teczka_id'] : (int)($_GET['teczka_id'] ?? 0);

$row = [
    'teczka_id'   => $preselect_teczka ?: '',
    'title'       => '',
    'description' => '',
    'status'      => 'open',
    'priority'    => 'normal',
    'owner_id'    => current_user()['id'],
    'deadline'    => '',
    'ciagla'      => 0,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'teczka_id'   => $parent ? (int)$parent['teczka_id'] : (int)($_POST['teczka_id'] ?? 0),
        'parent_id'   => $parent_id ?: null,
        'title'       => trim($_POST['title']         ?? ''),
        'description' => trim($_POST['description']   ?? ''),
        'status'      => $_POST['status']             ?? 'open',
        'priority'    => $_POST['priority']           ?? 'normal',
        'owner_id'    => (int)($_POST['owner_id']     ?? 0) ?: null,
        'deadline'    => $_POST['deadline']           ?? '',
        'ciagla'      => isset($_POST['ciagla']) ? 1 : 0,
    ];
    if (!$row['teczka_id']) $errors[] = 'Wybierz teczkę aktową.';
    if (!$row['title'])     $errors[] = 'Tytuł sprawy jest wymagany.';
    if (!in_array($row['status'],   array_keys(EZD_STATUSES_SPRAWA))) $errors[] = 'Nieprawidłowy status.';
    if (!in_array($row['priority'], array_keys(EZD_PRIORITIES)))      $errors[] = 'Nieprawidłowy priorytet.';

    if (!$errors) {
        try {
            $id = ezd_sprawa_create($row, (int)current_user()['id']);
            flash_set('success', 'Sprawa założona.');
            header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$id); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/index.php">Sprawy</a></li>
  <?php if($parent): ?><li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $parent_id ?>"><?= h($parent['znak_sprawy']) ?></a></li><?php endif; ?>
  <li class="breadcrumb-item active"><?= $parent ? 'Nowa podsprawa' : 'Nowa sprawa' ?></li>
</ol></nav>
<h4 class="fw-bold mb-3"><i class="bi bi-<?= $parent ? 'diagram-3' : 'folder-plus' ?> text-primary me-2"></i><?= $parent ? 'Nowa podsprawa' : 'Nowa sprawa' ?></h4>
<?php if($parent): ?>
<div class="alert alert-light border d-flex align-items-center gap-2 py-2" style="font-size:.83rem">
  <i class="bi bi-diagram-3 text-primary"></i>
  <span>Podsprawa sprawy nadrzędnej <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $parent_id ?>" class="font-monospace fw-semibold"><?= h($parent['znak_sprawy']) ?></a> — <?= h($parent['title']) ?></span>
</div>
<?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-7">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<?php if($parent): ?><input type="hidden" name="parent_id" value="<?= $parent_id ?>"><?php endif; ?>
<div class="card shadow-sm">
<div class="card-body">
  <!-- Teczka -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Teczka aktowa <span class="text-danger">*</span></label>
    <?php if($parent): ?>
    <input type="text" class="form-control" value="<?= h($parent['teczka_symbol'].' — '.$parent['teczka_title'].' ('.$parent['teczka_rok'].')') ?>" disabled>
    <div class="form-text">Podsprawa dziedziczy teczkę sprawy nadrzędnej.</div>
    <?php else: ?>
    <select name="teczka_id" class="form-select" required onchange="updateZnak(this)">
      <option value="">— wybierz teczkę —</option>
      <?php foreach($teczki as $t): ?>
      <option value="<?= $t['id'] ?>" data-symbol="<?= h($t['symbol']) ?>" data-rok="<?= $t['rok'] ?>"
              <?= (int)$row['teczka_id']===$t['id']?'selected':'' ?>>
        <?= h($t['symbol'].' — '.$t['title'].' ('.$t['rok'].')') ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  <!-- Podgląd znaku -->
  <div class="mb-3">
    <div class="alert alert-info py-2 px-3" style="font-size:.82rem">
      <i class="bi bi-info-circle me-1"></i>Znak sprawy zostanie nadany automatycznie:
      <strong id="znak-preview" class="font-monospace ms-1">SYMBOL.N.<?= date('Y') ?></strong>
    </div>
  </div>
  <!-- Tytuł -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł sprawy <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>"
           placeholder="np. Umowa z firmą XYZ na dostawę materiałów" required>
  </div>
  <!-- Opis -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Opis / uwagi</label>
    <textarea name="description" class="form-control" rows="3"
              placeholder="Krótki opis sprawy..."><?= h($row['description']) ?></textarea>
  </div>
  <!-- Status + Priorytet -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(EZD_STATUSES_SPRAWA as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Priorytet</label>
      <select name="priority" class="form-select">
        <?php foreach(EZD_PRIORITIES as $pv=>$pl): ?>
        <option value="<?= $pv ?>" <?= $row['priority']===$pv?'selected':'' ?>><?= h($pl['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <!-- Właściciel + Deadline -->
  <div class="row g-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Właściciel / referent</label>
      <select name="owner_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Termin</label>
      <input type="date" name="deadline" id="f-deadline" class="form-control" value="<?= h($row['deadline']) ?>" <?= !empty($row['ciagla'])?'disabled':'' ?>>
    </div>
  </div>
  <div class="form-check form-switch mt-3">
    <input class="form-check-input" type="checkbox" role="switch" name="ciagla" id="f-ciagla" value="1" <?= !empty($row['ciagla'])?'checked':'' ?>
           onchange="document.getElementById('f-deadline').disabled=this.checked; if(this.checked)document.getElementById('f-deadline').value='';">
    <label class="form-check-label fw-semibold" for="f-ciagla">Sprawa ciągła (stale otwarta)</label>
    <div class="form-text">Sprawa bez terminu zakończenia — nie podlega przypomnieniom o terminie i nie jest zamykana zwykłym zapisem (np. rejestr, ewidencja prowadzona na bieżąco).</div>
  </div>
</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Załóż sprawę</button>
  <?php if($parent): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $parent_id ?>" class="btn btn-outline-secondary">Anuluj</a>
  <?php elseif($preselect_teczka): ?>
  <a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $preselect_teczka ?>" class="btn btn-outline-secondary">Anuluj</a>
  <?php else: ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="btn btn-outline-secondary">Anuluj</a>
  <?php endif; ?>
</div>
</div>
</form>
</div></div>

<script>
function updateZnak(sel) {
    var opt = sel.options[sel.selectedIndex];
    var sym = opt.dataset.symbol || 'SYMBOL';
    var rok = opt.dataset.rok   || '<?= date('Y') ?>';
    document.getElementById('znak-preview').textContent = sym + '.N.' + rok;
}
// init
(function(){
    var sel = document.querySelector('[name=teczka_id]');
    if (sel && sel.value) updateZnak(sel);
})();
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
