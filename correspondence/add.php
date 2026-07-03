<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/correspondence.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
require_module_enabled('correspondence_enabled', 'Moduł Korespondencja');
if (!can_write('correspondence')) { http_response_code(403); die('Brak uprawnień.'); }

$PAGE_TITLE = 'Nowa korespondencja';
$errors = [];
$users  = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
$cats   = corr_categories();

// Pre-fill z pisma EZD (gdy kliknięto "Utwórz w Korespondencji" z EZD)
$from_ezd_pismo = null;
if (!empty($_GET['from_ezd']) && module_enabled('ezd_enabled')) {
    require_once dirname(__DIR__) . '/includes/ezd.php';
    $from_ezd_pismo = ezd_pismo_get((int)$_GET['from_ezd']);
}

$row = [
    'direction'   => $from_ezd_pismo
        ? (str_contains($from_ezd_pismo['kierunek'], 'wychod') ? 'outgoing' : 'incoming')
        : 'incoming',
    'number'       => '',
    'date'         => $from_ezd_pismo['data_pisma'] ?? date('Y-m-d'),
    'correspondent'=> $from_ezd_pismo
        ? ($from_ezd_pismo['nadawca'] ?: $from_ezd_pismo['odbiorca'])
        : '',
    'subject'      => $from_ezd_pismo['title'] ?? '',
    'description'  => $from_ezd_pismo['tresc'] ?? '',
    'category'     => '',
    'status'       => 'new',
    'handled_by'   => '',
    'medium'       => 'papier',
];
$from_ezd_id = $from_ezd_pismo ? (int)$from_ezd_pismo['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'direction'    => $_POST['direction']    ?? 'incoming',
        'number'       => trim($_POST['number']  ?? ''),
        'date'         => $_POST['date']         ?? date('Y-m-d'),
        'correspondent'=> trim($_POST['correspondent'] ?? ''),
        'subject'      => trim($_POST['subject'] ?? ''),
        'description'  => $_POST['description']  ?? '',
        'category'     => trim($_POST['category'] ?? ''),
        'status'       => $_POST['status']       ?? 'new',
        'handled_by'   => (int)($_POST['handled_by'] ?? 0) ?: null,
        'medium'       => array_key_exists($_POST['medium'] ?? '', CORR_MEDIA) ? $_POST['medium'] : 'papier',
    ];

    if (!$row['subject'])      $errors[] = 'Temat jest wymagany.';
    if (!$row['date'])         $errors[] = 'Data jest wymagana.';
    if (!$row['correspondent'])$errors[] = 'Nadawca/Odbiorca jest wymagany.';

    if (!$errors) {
        $uid = (int)current_user()['id'];
        $id  = corr_create($row, $uid);

        if (!empty($_FILES['attachment']['tmp_name'])) {
            $err = corr_upload($id, 'attachment', $uid);
            if ($err) $errors[] = 'Plik: ' . $err;
        }

        if (!$errors) {
            // Połącz z pismem EZD jeśli zaimportowano
            $from_ezd_id = (int)($_POST['from_ezd_id'] ?? 0);
            $auto_msg = '';
            if ($from_ezd_id && module_enabled('ezd_enabled')) {
                db()->prepare("UPDATE correspondence SET ezd_pismo_id=? WHERE id=?")->execute([$from_ezd_id, $id]);
                db()->prepare("UPDATE ezd_pisma SET corr_id=? WHERE id=?")->execute([$id, $from_ezd_id]);
            } elseif (corr_ezd_auto_enabled()) {
                // Automatyczna rejestracja w Kancelarii EZD (dziennik korespondencji)
                if (corr_auto_register($id, $uid)) $auto_msg = ' Zarejestrowano w Kancelarii EZD.';
            }
            log_system_action($uid, 'corr_create', "Dodano korespondencję #$id: " . $row['subject']);
            flash_set('success', 'Korespondencja została dodana.' . $auto_msg);
            header('Location: ' . APP_URL . '/correspondence/view.php?id=' . $id); exit;
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/correspondence/index.php">Korespondencja</a></li>
    <li class="breadcrumb-item active">Nowa</li>
  </ol>
</nav>
<h4 class="fw-bold mb-3"><i class="bi bi-plus-circle text-primary me-2"></i>Nowa korespondencja</h4>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<?php if ($from_ezd_id): ?>
<input type="hidden" name="from_ezd_id" value="<?= $from_ezd_id ?>">
<div class="alert alert-info d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-building-gear flex-shrink-0"></i>
  <span>Importowane z pisma EZD: <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $from_ezd_id ?>"><?= h($from_ezd_pismo['sygnatura']) ?></a> — pola zostały wstępnie wypełnione.</span>
</div>
<?php endif; ?>
<div class="row g-4">

  <!-- Lewa -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body p-4">

        <!-- Kierunek -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Kierunek <span class="text-danger">*</span></label>
          <div class="d-flex gap-3">
            <?php foreach (['incoming' => ['Przychodząca','bi-arrow-down-circle-fill','success'],
                             'outgoing' => ['Wychodząca','bi-arrow-up-circle-fill','primary']] as $val => [$lbl,$ico,$col]): ?>
            <div class="flex-fill">
              <input type="radio" class="btn-check" name="direction" id="dir_<?= $val ?>"
                     value="<?= $val ?>" <?= $row['direction']===$val?'checked':'' ?>>
              <label class="btn btn-outline-<?= $col ?> w-100" for="dir_<?= $val ?>">
                <i class="bi <?= $ico ?> me-1"></i><?= $lbl ?>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Numer <small class="text-muted">(opcjonalny)</small></label>
            <input type="text" name="number" class="form-control" value="<?= h($row['number']) ?>"
                   placeholder="np. KOR/001/2025">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Data <span class="text-danger">*</span></label>
            <input type="date" name="date" class="form-control" value="<?= h($row['date']) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['new'=>'Nowa','in_progress'=>'W trakcie','replied'=>'Odpowiedziano','closed'=>'Zamknięta','archived'=>'Archiwum'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $row['status']===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Rodzaj medium</label>
          <select name="medium" class="form-select" style="max-width:280px">
            <?php foreach (CORR_MEDIA as $mv=>$ml): ?>
            <option value="<?= $mv ?>" <?= $row['medium']===$mv?'selected':'' ?>><?= h($ml['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Nadawca / Odbiorca <span class="text-danger">*</span></label>
          <input type="text" name="correspondent" class="form-control" value="<?= h($row['correspondent']) ?>"
                 placeholder="Nazwa firmy, instytucji lub osoby" required autofocus>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Temat <span class="text-danger">*</span></label>
          <input type="text" name="subject" class="form-control" value="<?= h($row['subject']) ?>"
                 placeholder="Krótki opis treści pisma" required>
        </div>

        <div class="mb-0">
          <label class="form-label fw-semibold">Opis / Treść <small class="text-muted">(Markdown)</small></label>
          <textarea name="description" id="corr-editor" class="form-control"
                    rows="12" style="font-family:monospace;font-size:.88rem;resize:vertical"
                    placeholder="Szczegółowy opis, notatki, treść pisma…"><?= h($row['description']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- Prawa -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-sliders me-1 text-primary"></i>Szczegóły
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" style="font-size:.83rem;font-weight:600">Kategoria</label>
          <input type="text" name="category" class="form-control form-control-sm"
                 list="cat-list" value="<?= h($row['category']) ?>"
                 placeholder="np. ZUS, Urząd, Sąd, Kontrahent">
          <datalist id="cat-list">
            <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="mb-0">
          <label class="form-label" style="font-size:.83rem;font-weight:600">Prowadzi sprawę</label>
          <select name="handled_by" class="form-select form-select-sm">
            <option value="">— brak —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)$row['handled_by']===(int)$u['id']?'selected':'' ?>>
              <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-paperclip me-1 text-primary"></i>Załącznik
      </div>
      <div class="card-body">
        <input type="file" name="attachment" class="form-control form-control-sm"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.png,.jpg,.jpeg,.eml,.msg,.zip,.txt">
        <div class="form-text">Maks. 20 MB · PDF, DOC, EML, MSG…</div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Dodaj korespondencję
      </button>
      <a href="<?= APP_URL ?>/correspondence/index.php" class="btn btn-outline-secondary">Anuluj</a>
    </div>
  </div>
</div>
</form>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.js"></script>
<script>
new EasyMDE({ element: document.getElementById('corr-editor'), spellChecker: false, autofocus: false,
  toolbar: ['bold','italic','|','unordered-list','ordered-list','|','link','table','|','preview','guide'],
  minHeight: '260px', status: ['lines','words'] });
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
