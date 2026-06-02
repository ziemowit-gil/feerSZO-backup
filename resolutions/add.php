<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resolutions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
require_module_enabled('resolutions_enabled', 'Moduł Uchwały i Zarządzenia');
if (!can_write('resolutions')) { http_response_code(403); die('Brak uprawnień.'); }

$PAGE_TITLE = 'Nowy dokument';
$errors = [];
$users  = res_users_list();
$cats   = res_categories();

$type = $_GET['type'] ?? 'uchwala';
if (!in_array($type, ['uchwala','zarzadzenie','decyzja'])) $type = 'uchwala';

$row = [
    'type'      => $type,
    'number'    => '',
    'date'      => date('Y-m-d'),
    'title'     => '',
    'body'      => '',
    'category'  => '',
    'status'    => 'draft',
    'signed_by' => '',
    'tags'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'type'      => $_POST['type']     ?? 'uchwala',
        'number'    => trim($_POST['number'] ?? ''),
        'date'      => $_POST['date']     ?? date('Y-m-d'),
        'title'     => trim($_POST['title'] ?? ''),
        'body'      => $_POST['body']     ?? '',
        'category'  => trim($_POST['category'] ?? ''),
        'status'    => $_POST['status']   ?? 'draft',
        'signed_by' => (int)($_POST['signed_by'] ?? 0) ?: null,
        'tags'      => trim($_POST['tags'] ?? ''),
    ];

    if (!$row['title']) $errors[] = 'Tytuł jest wymagany.';
    if (!$row['date'])  $errors[] = 'Data jest wymagana.';

    if (!$errors) {
        $uid = (int)current_user()['id'];
        $id  = res_create($row, $uid);

        if (!empty($_FILES['attachment']['tmp_name'])) {
            $err = res_upload($id, 'attachment');
            if ($err) $errors[] = 'Plik: ' . $err;
        }

        if (!$errors) {
            log_system_action($uid, 'res_create', "Dodano dokument #$id: " . $row['title']);
            flash_set('success', 'Dokument został dodany.');
            header('Location: ' . APP_URL . '/resolutions/view.php?id=' . $id); exit;
        }
    }
}

[$tlabel, $ticon, $tcolor] = res_type_label($row['type']);
include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/resolutions/index.php">Uchwały i Zarządzenia</a></li>
    <li class="breadcrumb-item active">Nowy dokument</li>
  </ol>
</nav>
<h4 class="fw-bold mb-3">
  <i class="bi <?= $ticon ?> text-<?= $tcolor ?> me-2"></i>Nowy dokument
</h4>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<div class="row g-4">

  <!-- Lewa -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body p-4">

        <!-- Typ dokumentu -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Typ dokumentu <span class="text-danger">*</span></label>
          <div class="d-flex gap-2 flex-wrap">
            <?php foreach (['uchwala'=>['Uchwała','bi-hammer','primary'],
                             'zarzadzenie'=>['Zarządzenie','bi-person-gear','warning'],
                             'decyzja'=>['Decyzja','bi-clipboard-check','info']] as $v=>[$l,$i,$c]): ?>
            <div>
              <input type="radio" class="btn-check" name="type" id="type_<?= $v ?>"
                     value="<?= $v ?>" <?= $row['type']===$v?'checked':'' ?>>
              <label class="btn btn-outline-<?= $c ?>" for="type_<?= $v ?>">
                <i class="bi <?= $i ?> me-1"></i><?= $l ?>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-5">
            <label class="form-label fw-semibold">Numer
              <small class="text-muted fw-normal">(auto-generowany jeśli pusty)</small>
            </label>
            <input type="text" name="number" class="form-control" value="<?= h($row['number']) ?>"
                   placeholder="np. U/01/2025">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Data <span class="text-danger">*</span></label>
            <input type="date" name="date" class="form-control" value="<?= h($row['date']) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" class="form-select">
              <option value="draft"  <?= $row['status']==='draft' ?'selected':'' ?>>📝 Projekt</option>
              <option value="active" <?= $row['status']==='active'?'selected':'' ?>>✅ Aktywna</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" value="<?= h($row['title']) ?>"
                 placeholder="np. w sprawie przyjęcia regulaminu wynagradzania" autofocus required>
        </div>

        <div class="mb-0">
          <label class="form-label fw-semibold">
            Treść <small class="text-muted fw-normal">(Markdown)</small>
          </label>
          <textarea name="body" id="res-editor" class="form-control"
                    rows="18" style="font-family:monospace;font-size:.88rem;resize:vertical"
                    placeholder="§ 1&#10;Treść uchwały / zarządzenia…"><?= h($row['body']) ?></textarea>
          <div class="form-text">Obsługiwany Markdown: **pogrubienie**, # nagłówki, - listy, § paragrafy</div>
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
                 placeholder="np. Statutowa, Finansowa, Kadrowa, IT">
          <datalist id="cat-list">
            <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:.83rem;font-weight:600">Podpisał(a)</label>
          <select name="signed_by" class="form-select form-select-sm">
            <option value="">— brak —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)$row['signed_by']===(int)$u['id']?'selected':'' ?>>
              <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-0">
          <label class="form-label" style="font-size:.83rem;font-weight:600">Tagi
            <small class="text-muted fw-normal">(oddzielone przecinkami)</small>
          </label>
          <input type="text" name="tags" class="form-control form-control-sm"
                 value="<?= h($row['tags']) ?>"
                 placeholder="np. budżet, 2025, zarząd">
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-paperclip me-1 text-primary"></i>Skan / Załącznik
      </div>
      <div class="card-body">
        <input type="file" name="attachment" class="form-control form-control-sm"
               accept=".pdf,.doc,.docx,.odt,.png,.jpg,.jpeg,.zip">
        <div class="form-text">Maks. 20 MB · PDF, DOC, PNG…</div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Utwórz dokument
      </button>
      <a href="<?= APP_URL ?>/resolutions/index.php" class="btn btn-outline-secondary">Anuluj</a>
    </div>
  </div>
</div>
</form>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.js"></script>
<script>
new EasyMDE({
  element: document.getElementById('res-editor'), spellChecker: false, autofocus: false,
  toolbar: ['bold','italic','heading','|','quote','unordered-list','ordered-list','|',
            'link','table','horizontal-rule','|','preview','side-by-side','fullscreen','|','guide'],
  minHeight: '400px', status: ['lines','words'],
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
