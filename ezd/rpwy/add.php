<?php
/**
 * Rejestracja przesyłki wychodzącej w książce nadawczej (RPW-W).
 * Może powstać „od zera" albo z pisma wychodzącego (?pismo_id=).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();
if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/rpwy/index.php'); exit; }

$today    = date('Y-m-d');
$pismo_id = (int)($_GET['pismo_id'] ?? $_POST['pismo_id'] ?? 0);
$pismo    = $pismo_id ? ezd_pismo_get($pismo_id) : null;

if ($pismo && ($dup = ezd_rpwy_for_pismo($pismo_id))) {
    flash_set('info', 'To pismo ma już wpis w książce nadawczej — ' . ezd_rpwy_label($dup) . '.');
    header('Location:'.APP_URL.'/ezd/rpwy/view.php?id='.(int)$dup['id']); exit;
}

// Pisma wychodzące bez wpisu w książce nadawczej — do wyboru, gdy nie przyszliśmy z pisma
$kandydaci = $pismo ? [] : db_all(
    "SELECT p.id, p.sygnatura, p.title, p.odbiorca, p.data_wysylki, p.rodzaj_medium, s.znak_sprawy
     FROM ezd_pisma p
     LEFT JOIN ezd_sprawy s ON s.id = p.sprawa_id
     WHERE p.kierunek='wychodzace'
       AND NOT EXISTS (SELECT 1 FROM ezd_rpwy w WHERE w.pismo_id = p.id)
     ORDER BY p.id DESC LIMIT 200"
);

$row = [
    'pismo_id'     => $pismo_id ?: '',
    'data_wysylki' => $pismo && $pismo['data_wysylki'] ? $pismo['data_wysylki'] : $today,
    'sposob'       => $pismo ? (['email'=>'email','epuap'=>'edoreczenia'][$pismo['rodzaj_medium'] ?? ''] ?? 'zwykly') : 'zwykly',
    'odbiorca'     => $pismo ? ($pismo['odbiorca'] ?? '') : '',
    'adres'        => '',
    'ade'          => '',
    'nr_nadania'   => '',
    'liczba_szt'   => 1,
    'koszt'        => '',
    'termin_dni'   => 0,
    'uwagi'        => '',
    'status'       => 'przygotowana',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($row) as $k) {
        if (isset($_POST[$k])) $row[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
    }
    $row['data_wysylki'] = $row['data_wysylki'] ?: $today;

    $errors = ezd_rpwy_validate($row);
    if (!in_array($row['status'], ['przygotowana','nadana'], true)) $row['status'] = 'przygotowana';

    // Powiązanie z pismem (z parametru lub z listy)
    $link_id = (int)$row['pismo_id'];
    if ($link_id) {
        $lp = ezd_pismo_get($link_id);
        if (!$lp)                              $errors[] = 'Wskazane pismo nie istnieje.';
        elseif ($lp['kierunek'] !== 'wychodzace') $errors[] = 'Do książki nadawczej można wpisać tylko pismo wychodzące.';
        elseif (ezd_rpwy_for_pismo($link_id))  $errors[] = 'To pismo ma już wpis w książce nadawczej.';
        else $row['sprawa_id'] = (int)$lp['sprawa_id'];
    }

    if (!$errors) {
        $uid = (int)current_user()['id'];
        $res = ezd_rpwy_create($row, $uid);
        if (!empty($_FILES['epo']['tmp_name'])) {
            $err = ezd_rpwy_epo_upload($res['id'], 'epo', $uid);
            if ($err) flash_set('error', 'Wpis zapisano, ale dowodu doręczenia nie przyjęto: ' . $err);
        }
        flash_set('success', 'Zarejestrowano przesyłkę RPW-W ' . $res['rpwy_nr'] . '/' . $res['rok'] . '.');
        if (($_POST['_next'] ?? '') === 'add') { header('Location:'.APP_URL.'/ezd/rpwy/add.php'); exit; }
        header('Location:'.APP_URL.'/ezd/rpwy/view.php?id='.$res['id']); exit;
    }
}

$PAGE_TITLE = 'Nowa wysyłka';
include dirname(dirname(__DIR__)) . '/includes/header.php';
$next_nr = _ezd_next_rpwy((int)substr($row['data_wysylki'], 0, 4) ?: (int)date('Y'));
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpwy/index.php">Książka nadawcza</a></li>
  <li class="breadcrumb-item active">Nowa wysyłka</li>
</ol></nav>

<h4 class="fw-bold mb-1"><i class="bi bi-send text-primary me-2"></i>Rejestracja przesyłki wychodzącej</h4>
<div class="text-muted mb-3" style="font-size:.82rem">Następny numer: <code class="fw-bold" style="color:#1d4ed8">RPW-W <?= $next_nr ?>/<?= substr($row['data_wysylki'],0,4) ?></code></div>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm"><div class="card-body">

  <?php if ($pismo): ?>
  <input type="hidden" name="pismo_id" value="<?= (int)$pismo_id ?>">
  <div class="alert alert-primary py-2 mb-3" style="font-size:.82rem">
    <i class="bi bi-link-45deg me-1"></i>Wysyłka pisma
    <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$pismo_id ?>" class="font-monospace fw-semibold"><?= h($pismo['sygnatura']) ?></a>
    — <?= h($pismo['title']) ?>
  </div>
  <?php else: ?>
  <div class="mb-3">
    <label class="form-label fw-semibold" for="pismo_id">Pismo wychodzące <span class="text-muted fw-normal">(opcjonalnie)</span></label>
    <select name="pismo_id" id="pismo_id" class="form-select">
      <option value="">— przesyłka bez pisma w EZD —</option>
      <?php foreach($kandydaci as $k): ?>
      <option value="<?= (int)$k['id'] ?>" <?= (int)$row['pismo_id']===(int)$k['id']?'selected':'' ?>
              data-odbiorca="<?= h($k['odbiorca']) ?>">
        <?= h($k['sygnatura']) ?> — <?= h(mb_substr($k['title'], 0, 60)) ?><?= $k['odbiorca'] ? ' (' . h(mb_substr($k['odbiorca'],0,30)) . ')' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
    <small class="text-muted">Lista zawiera pisma wychodzące, które nie mają jeszcze wpisu w książce nadawczej.</small>
  </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label fw-semibold" for="data_wysylki">Data nadania <span class="text-danger">*</span></label>
      <input type="date" name="data_wysylki" id="data_wysylki" class="form-control" value="<?= h($row['data_wysylki']) ?>" required>
    </div>
    <div class="col-md-8">
      <label class="form-label fw-semibold" for="sposob">Sposób wysyłki</label>
      <select name="sposob" id="sposob" class="form-select">
        <?php foreach(EZD_RPWY_SPOSOBY as $sv=>$si): ?>
        <option value="<?= $sv ?>" <?= $row['sposob']===$sv?'selected':'' ?>><?= h($si['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="odbiorca">Odbiorca <span class="text-danger">*</span></label>
      <input type="text" name="odbiorca" id="odbiorca" class="form-control" value="<?= h($row['odbiorca']) ?>" placeholder="Imię i nazwisko / instytucja" required>
    </div>
    <div class="col-md-7">
      <label class="form-label fw-semibold" for="adres">Adres</label>
      <input type="text" name="adres" id="adres" class="form-control" value="<?= h($row['adres']) ?>" placeholder="ul., kod, miejscowość">
    </div>
  </div>

  <div class="mb-3" id="ade-wrap" style="<?= $row['sposob']==='edoreczenia'?'':'display:none' ?>">
    <label class="form-label fw-semibold" for="ade">Adres do doręczeń elektronicznych (ADE) odbiorcy <span class="text-danger">*</span></label>
    <input type="text" name="ade" id="ade" class="form-control font-monospace" value="<?= h($row['ade']) ?>" placeholder="AE:PL-00000-00000-XXXXX-00">
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="nr_nadania">Numer nadania / ID dowodu</label>
      <input type="text" name="nr_nadania" id="nr_nadania" class="form-control font-monospace" value="<?= h($row['nr_nadania']) ?>" placeholder="np. 00259007734567890123">
    </div>
    <div class="col-md-3">
      <label class="form-label fw-semibold" for="liczba_szt">Liczba przesyłek</label>
      <input type="number" name="liczba_szt" id="liczba_szt" class="form-control" min="1" value="<?= (int)$row['liczba_szt'] ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold" for="koszt">Opłata (zł)</label>
      <input type="text" name="koszt" id="koszt" class="form-control" value="<?= h($row['koszt']) ?>" placeholder="np. 10,90" inputmode="decimal">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold" for="termin_dni">Termin od doręczenia</label>
      <div class="input-group">
        <input type="number" name="termin_dni" id="termin_dni" class="form-control" min="0" max="365" value="<?= (int)$row['termin_dni'] ?>">
        <span class="input-group-text">dni</span>
      </div>
      <small class="text-muted">0 = bez terminu. Liczony od dnia potwierdzonego doręczenia.</small>
    </div>
    <div class="col-md-7">
      <label class="form-label fw-semibold" for="epo">Dowód doręczenia <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <input type="file" name="epo" id="epo" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.xml,.txt">
      <small class="text-muted">Skan ZPO albo dowód doręczenia/wysłania z e-Doręczeń. Maks. 25 MB.</small>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold" for="uwagi">Uwagi</label>
    <textarea name="uwagi" id="uwagi" class="form-control" rows="2" placeholder="np. przesyłka za pobraniem, 3 załączniki"><?= h($row['uwagi']) ?></textarea>
  </div>

  <div>
    <label class="form-label fw-semibold" for="status">Stan wpisu</label>
    <select name="status" id="status" class="form-select">
      <option value="przygotowana" <?= $row['status']==='przygotowana'?'selected':'' ?>>Przygotowana — czeka na nadanie</option>
      <option value="nadana"       <?= $row['status']==='nadana'?'selected':'' ?>>Nadana — przesyłka wyszła</option>
    </select>
  </div>

</div>
<div class="card-footer d-flex gap-2 flex-wrap">
  <button type="submit" name="_next" value="view" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zarejestruj</button>
  <button type="submit" name="_next" value="add" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Zarejestruj i dodaj następną</button>
  <a href="<?= APP_URL ?>/ezd/rpwy/index.php" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<script>
(function () {
  var sel = document.getElementById('sposob');
  var ade = document.getElementById('ade-wrap');
  if (sel && ade) {
    sel.addEventListener('change', function () {
      ade.style.display = (sel.value === 'edoreczenia') ? '' : 'none';
    });
  }
  // Podpowiedź odbiorcy z wybranego pisma
  var pis = document.getElementById('pismo_id');
  var odb = document.getElementById('odbiorca');
  if (pis && odb) {
    pis.addEventListener('change', function () {
      var o = pis.options[pis.selectedIndex];
      var v = o && o.getAttribute('data-odbiorca');
      if (v && !odb.value.trim()) odb.value = v;
    });
  }
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
