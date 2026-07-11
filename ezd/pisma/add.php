<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');

$sprawa_id = (int)($_GET['sprawa_id'] ?? 0);
$sprawa    = ezd_sprawa_get($sprawa_id);
if (!$sprawa) { flash_set('error','Koszulka nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if (ezd_sprawa_access($sprawa, (int)current_user()['id']) !== 'write') { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
if ($sprawa['status'] === 'closed' && !is_admin()) {
    flash_set('error','Koszulka jest zamknięta.'); header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$sprawa_id); exit;
}

$PAGE_TITLE = 'Nowe pismo — '.$sprawa['znak_sprawy'];
$users = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$today = date('Y-m-d');

$kierunek_init = $_GET['kierunek'] ?? 'przychodzace';
if (!array_key_exists($kierunek_init, EZD_KIERUNKI)) $kierunek_init = 'przychodzace';
$row = [
    'kierunek'    => $kierunek_init,
    'title'       => '',
    'tresc'       => '',
    'nadawca'     => '',
    'odbiorca'    => '',
    'data_pisma'  => $today,
    'data_wplywu' => $today,
    'data_wysylki'=> '',
    'status'      => 'nowe',
    'owner_id'    => current_user()['id'],
    'rodzaj_medium' => 'papier',
];
$errors = [];

// Prefill z szablonu pisma (?szablon_id=) — korespondencja / mail merge
$szablony_pism = ezd_szablony_all(true, 'pismo');
$szablon_uzyty = 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($szid = (int)($_GET['szablon_id'] ?? 0))) {
    $sz = ezd_szablon_get($szid);
    if ($sz && $sz['aktywny']) {
        $ctx = ezd_szablon_context($sprawa);
        $t = ezd_szablon_render($sz['tytul_wzor'], $ctx);
        if ($t !== '') $row['title'] = $t;
        $row['tresc']         = ezd_szablon_render($sz['tresc_wzor'], $ctx);
        $row['kierunek']      = $sz['kierunek'];
        $row['rodzaj_medium'] = $sz['rodzaj_medium'];
        $szablon_uzyty        = $szid;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'sprawa_id'    => $sprawa_id,
        'kierunek'     => $_POST['kierunek']    ?? 'przychodzace',
        'title'        => trim($_POST['title']  ?? ''),
        'tresc'        => $_POST['tresc']       ?? '',
        'nadawca'      => trim($_POST['nadawca']?? ''),
        'odbiorca'     => trim($_POST['odbiorca']?? ''),
        'data_pisma'   => $_POST['data_pisma']  ?? '',
        'data_wplywu'  => $_POST['data_wplywu'] ?? '',
        'data_wysylki' => $_POST['data_wysylki']?? '',
        'status'       => $_POST['status']      ?? 'nowe',
        'owner_id'     => (int)($_POST['owner_id']??0) ?: null,
        'rodzaj_medium'=> $_POST['rodzaj_medium'] ?? 'papier',
    ];
    if (!$row['title']) $errors[] = 'Tytuł pisma jest wymagany.';
    if (!in_array($row['kierunek'], array_keys(EZD_KIERUNKI))) $errors[] = 'Nieprawidłowy kierunek.';

    if (!$errors) {
        try {
            $pid = ezd_pismo_create($row, (int)current_user()['id']);
            flash_set('success','Pismo dodane.');
            header('Location:'.APP_URL.'/ezd/pisma/view.php?id='.$pid); exit;
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
  <li class="breadcrumb-item active">Nowe pismo</li>
</ol></nav>
<h4 class="fw-bold mb-1"><i class="bi bi-envelope-plus text-primary me-2"></i>Nowe pismo</h4>
<div class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-folder2 me-1"></i>Sprawa: <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h($sprawa['title']) ?></div>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<?php if($szablony_pism): ?>
<div class="card shadow-sm mb-3 border-primary border-opacity-25">
  <div class="card-body py-2">
    <form method="get" action="<?= APP_URL ?>/ezd/pisma/add.php" class="d-flex align-items-center gap-2 flex-wrap">
      <input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>">
      <label class="fw-semibold small mb-0"><i class="bi bi-file-earmark-text text-primary me-1"></i>Wstaw z szablonu:</label>
      <select name="szablon_id" class="form-select form-select-sm" style="max-width:360px" onchange="this.form.submit()">
        <option value="">— wybierz szablon —</option>
        <?php foreach($szablony_pism as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $szablon_uzyty===(int)$s['id']?'selected':'' ?>><?= h($s['nazwa']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if($szablon_uzyty): ?><span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Wstawiono</span><?php endif; ?>
      <a href="<?= APP_URL ?>/ezd/pisma/seria.php?sprawa_id=<?= $sprawa_id ?>" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-envelope-paper me-1"></i>Tryb seryjny</a>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row"><div class="col-lg-8">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="card shadow-sm">
<div class="card-body">

  <!-- Kierunek pisma -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Kierunek <span class="text-danger">*</span></label>
    <div class="d-flex gap-2 flex-wrap">
      <?php foreach(EZD_KIERUNKI as $kv=>$kl): ?>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="kierunek" id="k_<?= $kv ?>" value="<?= $kv ?>"
               <?= $row['kierunek']===$kv?'checked':'' ?> onchange="toggleKierunek(this)">
        <label class="form-check-label" for="k_<?= $kv ?>"><i class="bi <?= $kl['icon'] ?> me-1"></i><?= h($kl['label']) ?></label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Tytuł -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł / przedmiot pisma <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>"
           placeholder="np. Oferta cenowa nr 123/2026" required>
  </div>

  <!-- Rodzaj medium -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Rodzaj medium</label>
    <select name="rodzaj_medium" class="form-select" style="max-width:280px">
      <?php foreach(EZD_MEDIA as $mv=>$ml): ?>
      <option value="<?= $mv ?>" <?= $row['rodzaj_medium']===$mv?'selected':'' ?>><?= h($ml['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Nadawca / Odbiorca -->
  <div class="row g-3 mb-3">
    <div class="col-6" id="field-nadawca">
      <label class="form-label fw-semibold">Nadawca</label>
      <input type="text" name="nadawca" class="form-control" value="<?= h($row['nadawca']) ?>" placeholder="Nazwa / firma nadawcy">
    </div>
    <div class="col-6" id="field-odbiorca">
      <label class="form-label fw-semibold">Odbiorca</label>
      <input type="text" name="odbiorca" class="form-control" value="<?= h($row['odbiorca']) ?>" placeholder="Nazwa / firma odbiorcy">
    </div>
  </div>

  <!-- Daty -->
  <div class="row g-3 mb-3">
    <div class="col-4">
      <label class="form-label fw-semibold" id="lbl-data-pisma">Data pisma</label>
      <input type="date" name="data_pisma" class="form-control" value="<?= h($row['data_pisma']) ?>">
    </div>
    <div class="col-4" id="field-data-wplywu">
      <label class="form-label fw-semibold">Data wpływu</label>
      <input type="date" name="data_wplywu" class="form-control" value="<?= h($row['data_wplywu']) ?>">
    </div>
    <div class="col-4" id="field-data-wysylki">
      <label class="form-label fw-semibold">Data wysyłki</label>
      <input type="date" name="data_wysylki" class="form-control" value="<?= h($row['data_wysylki']) ?>">
    </div>
  </div>

  <!-- Status + Właściciel -->
  <div class="row g-3 mb-3">
    <div class="col-5">
      <label class="form-label fw-semibold">Status</label>
      <select name="status" class="form-select">
        <?php foreach(['nowe'=>'Nowe','w_toku'=>'W toku','odpowiedziano'=>'Odpowiedziano','archiwum'=>'Archiwum'] as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $row['status']===$sv?'selected':'' ?>><?= h($sl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-7">
      <label class="form-label fw-semibold">Referent</label>
      <select name="owner_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$row['owner_id']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Treść (notatka) -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Treść / notatka</label>
    <textarea name="tresc" class="form-control" rows="5"
              placeholder="Opcjonalne: streszczenie, dyspozycje, dodatkowe informacje…"><?= h($row['tresc']) ?></textarea>
  </div>

</div>
<div class="card-footer d-flex gap-2">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Dodaj pismo</button>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</div>
</form>
</div></div>

<script>
function toggleKierunek(radio) {
    // brak dynamicznych ukryć — wszystkie pola zawsze widoczne
}
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
