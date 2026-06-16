<?php
/**
 * Rejestracja przesyłki wpływającej w dzienniku podawczym (RPW).
 * Nadaje kolejny numer RPW; pozwala od razu wgrać skan i/lub przekazać referentowi.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/rpw/index.php'); exit; }

$PAGE_TITLE = 'Rejestracja przesyłki';
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$today = date('Y-m-d');

$row = [
    'data_wplywu'   => $today,
    'typ'           => 'list',
    'nadawca'       => '',
    'znak_obcy'     => '',
    'opis'          => '',
    'uwagi'         => '',
    'przekazano_do' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'data_wplywu'   => $_POST['data_wplywu'] ?: $today,
        'typ'           => $_POST['typ'] ?? 'list',
        'nadawca'       => trim($_POST['nadawca'] ?? ''),
        'znak_obcy'     => trim($_POST['znak_obcy'] ?? ''),
        'opis'          => trim($_POST['opis'] ?? ''),
        'uwagi'         => trim($_POST['uwagi'] ?? ''),
        'przekazano_do' => (int)($_POST['przekazano_do'] ?? 0) ?: null,
    ];
    if (!$row['opis']) $errors[] = 'Opis / przedmiot przesyłki jest wymagany.';
    if (!array_key_exists($row['typ'], EZD_RPW_TYPY)) $errors[] = 'Nieprawidłowy sposób doręczenia.';

    if (!$errors) {
        $uid = (int)current_user()['id'];
        $res = ezd_rpw_create($row, $uid);
        // Opcjonalny skan
        if (!empty($_FILES['scan']['tmp_name'])) {
            $err = ezd_rpw_scan_upload($res['id'], 'scan', $uid);
            if ($err) flash_set('error', 'Przesyłkę zapisano, ale skan odrzucono: ' . $err);
        }
        flash_set('success', 'Zarejestrowano przesyłkę RPW ' . $res['rpw_nr'] . '/' . $res['rok'] . '.');
        $next = $_POST['_next'] ?? 'view';
        if ($next === 'add') {
            header('Location:'.APP_URL.'/ezd/rpw/add.php'); exit;
        }
        header('Location:'.APP_URL.'/ezd/rpw/view.php?id='.$res['id']); exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
$next_nr = _ezd_next_rpw((int)date('Y'));
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpw/index.php">Dziennik podawczy</a></li>
  <li class="breadcrumb-item active">Nowa przesyłka</li>
</ol></nav>

<h4 class="fw-bold mb-1"><i class="bi bi-mailbox text-primary me-2"></i>Rejestracja przesyłki wpływającej</h4>
<div class="text-muted mb-3" style="font-size:.82rem">Następny numer: <code class="fw-bold" style="color:#1d4ed8">RPW <?= $next_nr ?>/<?= date('Y') ?></code></div>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm"><div class="card-body">

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label fw-semibold">Data wpływu <span class="text-danger">*</span></label>
      <input type="date" name="data_wplywu" class="form-control" value="<?= h($row['data_wplywu']) ?>" max="<?= $today ?>" required>
    </div>
    <div class="col-md-8">
      <label class="form-label fw-semibold">Sposób doręczenia</label>
      <select name="typ" class="form-select">
        <?php foreach(EZD_RPW_TYPY as $tv=>$ti): ?>
        <option value="<?= $tv ?>" <?= $row['typ']===$tv?'selected':'' ?>><?= h($ti['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-7">
      <label class="form-label fw-semibold">Nadawca</label>
      <input type="text" name="nadawca" class="form-control" value="<?= h($row['nadawca']) ?>" placeholder="Imię i nazwisko / instytucja">
    </div>
    <div class="col-md-5">
      <label class="form-label fw-semibold">Znak pisma nadawcy</label>
      <input type="text" name="znak_obcy" class="form-control" value="<?= h($row['znak_obcy']) ?>" placeholder="np. ZUS/123/2026">
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Opis / przedmiot przesyłki <span class="text-danger">*</span></label>
    <input type="text" name="opis" class="form-control" value="<?= h($row['opis']) ?>" placeholder="np. Faktura za usługi telekomunikacyjne" required>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Uwagi</label>
    <textarea name="uwagi" class="form-control" rows="2" placeholder="Opcjonalne: liczba załączników, stan przesyłki…"><?= h($row['uwagi']) ?></textarea>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label fw-semibold">Przekaż do referenta <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <select name="przekazano_do" class="form-select">
        <option value="">— pozostaw w koszulce —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['przekazano_do']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Skan przesyłki <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <input type="file" name="scan" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg">
      <small class="text-muted">Maks. 25 MB. Przy dekretacji do sprawy skan trafi do akt sprawy.</small>
    </div>
  </div>

</div>
<div class="card-footer d-flex gap-2 flex-wrap">
  <button type="submit" name="_next" value="view" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zarejestruj</button>
  <button type="submit" name="_next" value="add" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Zarejestruj i dodaj następną</button>
  <a href="<?= APP_URL ?>/ezd/rpw/index.php" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
