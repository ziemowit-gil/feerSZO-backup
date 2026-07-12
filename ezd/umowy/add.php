<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko'); ezd_require_access();
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }

$sprawa_id = (int)($_GET['sprawa_id'] ?? 0);
$sprawa    = ezd_sprawa_get($sprawa_id);
if (!$sprawa) { flash_set('error','Koszulka nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if ($sprawa['status'] === 'closed' && !is_admin()) {
    flash_set('error','Koszulka jest zamknięta.'); header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$sprawa_id); exit;
}

$PAGE_TITLE = 'Nowa umowa — '.$sprawa['znak_sprawy'];
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$today = date('Y-m-d');

$row = [
    'typ'               => 'umowa',
    'title'             => '',
    'strona'            => '',
    'wartosc'           => '',
    'waluta'            => 'PLN',
    'data_zawarcia'     => $today,
    'data_od'           => '',
    'data_do'           => '',
    'warunki_platnosci' => '',
    'status'            => 'projekt',
    'owner_id'          => current_user()['id'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'sprawa_id'         => $sprawa_id,
        'typ'               => $_POST['typ']               ?? 'umowa',
        'title'             => trim($_POST['title']        ?? ''),
        'strona'            => trim($_POST['strona']       ?? ''),
        'wartosc'           => $_POST['wartosc']           ?? '',
        'waluta'            => $_POST['waluta']            ?? 'PLN',
        'data_zawarcia'     => $_POST['data_zawarcia']     ?? '',
        'data_od'           => $_POST['data_od']           ?? '',
        'data_do'           => $_POST['data_do']           ?? '',
        'warunki_platnosci' => $_POST['warunki_platnosci'] ?? '',
        'status'            => $_POST['status']            ?? 'projekt',
        'owner_id'          => (int)($_POST['owner_id']   ?? 0) ?: null,
    ];
    if (!$row['title']) $errors[] = 'Tytuł umowy jest wymagany.';
    if (!in_array($row['typ'], array_keys(EZD_UMOWA_TYPY))) $errors[] = 'Nieprawidłowy typ.';

    if (!$errors) {
        try {
            $uid = ezd_umowa_create($row, (int)current_user()['id']);
            flash_set('success','Umowa dodana.');
            header('Location:'.APP_URL.'/ezd/umowy/view.php?id='.$uid); exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Nowa umowa</li>
</ol></nav>
<h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-plus text-primary me-2"></i>Nowa umowa</h4>
<div class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-folder2 me-1"></i>Sprawa: <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h($sprawa['title']) ?></div>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <!-- Typ + Status -->
  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Typ dokumentu <span class="text-danger">*</span></label>
      <select name="typ" class="form-select">
        <?php foreach(EZD_UMOWA_TYPY as $tv=>$tl): ?>
        <option value="<?= $tv ?>" <?= $row['typ']===$tv?'selected':'' ?>><?= h($tl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(['projekt'=>'Projekt','aktywna'=>'Aktywna','wygasla'=>'Wygasła','rozwiazana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Tytuł -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł / nazwa umowy <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>"
           placeholder="np. Umowa zlecenie z Janem Kowalskim" required>
  </div>

  <!-- Strona umowy -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Strona umowy (kontrahent)</label>
    <input type="text" name="strona" class="form-control" value="<?= h($row['strona']) ?>"
           placeholder="Imię i nazwisko lub nazwa firmy">
  </div>

  <!-- Wartość + Waluta -->
  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Wartość brutto</label>
      <input type="number" name="wartosc" class="form-control" value="<?= h($row['wartosc']) ?>"
             step="0.01" min="0" placeholder="np. 5000.00">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Waluta</label>
      <select name="waluta" class="form-select">
        <?php foreach(['PLN','EUR','USD','GBP','CHF'] as $w): ?>
        <option value="<?= $w ?>" <?= $row['waluta']===$w?'selected':'' ?>><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Daty -->
  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold">Data zawarcia</label>
      <input type="date" name="data_zawarcia" class="form-control" value="<?= h($row['data_zawarcia']) ?>">
    </div>
    <div class="col-4">
      <label class="form-label fw-semibold">Obowiązuje od</label>
      <input type="date" name="data_od" class="form-control" value="<?= h($row['data_od']) ?>">
    </div>
    <div class="col-4">
      <label class="form-label fw-semibold">Obowiązuje do</label>
      <input type="date" name="data_do" class="form-control" value="<?= h($row['data_do']) ?>">
    </div>
  </div>

  <!-- Warunki płatności -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Warunki płatności / uwagi</label>
    <textarea name="warunki_platnosci" class="form-control" rows="3"
              placeholder="np. Płatność 14 dni od faktury…"><?= h($row['warunki_platnosci']) ?></textarea>
  </div>

  <!-- Referent -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Referent</label>
    <select name="owner_id" class="form-select">
      <option value="">— brak —</option>
      <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
    </select>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Dodaj umowę</button>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
