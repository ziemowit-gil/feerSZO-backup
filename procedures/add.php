<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin', 'editor');
require_module_enabled('procedures_enabled', 'Moduł procedur');

$PAGE_TITLE = 'Nowa procedura';
$errors     = [];
$row        = ['title' => '', 'content' => '', 'category' => '', 'owner_id' => ''];

$categories = proc_get_categories();
$all_procs  = proc_get_all(['status' => 'active']);
$users      = proc_get_users_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'title'       => trim($_POST['title']    ?? ''),
        'content'     => $_POST['content']       ?? '',
        'category'    => trim($_POST['category'] ?? ''),
        'owner_id'    => (int)($_POST['owner_id'] ?? 0) ?: null,
        'related_ids' => array_filter(array_map('intval', $_POST['related_ids'] ?? [])),
    ];

    if ($row['title'] === '') $errors[] = 'Tytuł jest wymagany.';

    if (!$errors) {
        $user_id = (int)current_user()['id'];
        $id = proc_create($row, $user_id);

        // Załącznik (opcjonalny)
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $err = proc_upload_attachment($id, 'attachment', $user_id);
            if ($err) $errors[] = 'Plik: ' . $err;
        }

        if (!$errors) {
            log_system_action($user_id, 'proc_create', "Utworzono procedurę #$id: " . $row['title']);
            flash_set('success', 'Procedura została dodana.');
            header('Location: ' . APP_URL . '/procedures/view.php?id=' . $id); exit;
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/procedures/index.php">Procedury</a></li>
    <li class="breadcrumb-item active">Nowa procedura</li>
  </ol>
</nav>

<h4 class="fw-bold mb-3">
  <i class="bi bi-journal-plus text-primary me-2"></i>Nowa procedura
</h4>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-4">
  <!-- Lewa: główna treść -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <div class="mb-3">
          <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control"
                 value="<?= h($row['title']) ?>"
                 placeholder="np. Procedura obiegu dokumentów finansowych"
                 autofocus required>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">
            Treść <small class="text-muted fw-normal">(Markdown)</small>
          </label>
          <textarea name="content" id="proc-editor" class="form-control"
                    rows="18" style="font-family:monospace;font-size:.88rem;resize:vertical"
                    placeholder="Wpisz treść procedury w formacie Markdown..."><?= h($row['content']) ?></textarea>
          <div class="form-text">
            Obsługiwany Markdown: **pogrubienie**, *kursywa*, # nagłówki, - listy, `kod`, > cytat, tabele, ---
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Prawa: meta + relacje + załącznik -->
  <div class="col-lg-4">

    <!-- Metadane -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-sliders me-1 text-primary"></i>Ustawienia
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" style="font-size:.83rem;font-weight:600">Kategoria</label>
          <input type="text" name="category" class="form-control form-control-sm"
                 list="cat-list" value="<?= h($row['category']) ?>"
                 placeholder="np. HR, Finanse, IT…">
          <datalist id="cat-list">
            <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="mb-0">
          <label class="form-label" style="font-size:.83rem;font-weight:600">Osoba odpowiedzialna</label>
          <select name="owner_id" class="form-select form-select-sm">
            <option value="">— brak —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)$row['owner_id'] === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Procedury powiązane -->
    <?php if (count($all_procs) > 0): ?>
    <div class="card shadow-sm mb-3" id="relations">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-diagram-3 me-1 text-primary"></i>Procedury powiązane
      </div>
      <div class="card-body" style="max-height:260px;overflow-y:auto">
        <div class="mb-2" style="font-size:.75rem;color:#64748b">
          Zaznacz procedury powiązane z tą (relacja działa w obu kierunkach):
        </div>
        <?php $selected_related = $row['related_ids'] ?? []; ?>
        <?php foreach ($all_procs as $p): ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox"
                 name="related_ids[]" value="<?= $p['id'] ?>"
                 id="rel_<?= $p['id'] ?>"
                 <?= in_array($p['id'], $selected_related, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="rel_<?= $p['id'] ?>" style="font-size:.8rem">
            <?= h($p['title']) ?>
            <?php if ($p['category']): ?>
            <span class="text-muted">(<?= h($p['category']) ?>)</span>
            <?php endif; ?>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Załącznik -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-paperclip me-1 text-primary"></i>Załącznik (opcjonalnie)
      </div>
      <div class="card-body">
        <input type="file" name="attachment" class="form-control form-control-sm"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.png,.jpg,.jpeg,.gif,.webp,.zip,.txt,.csv">
        <div class="form-text">Maks. 15 MB · PDF, DOC, XLS, PNG, ZIP…</div>
      </div>
    </div>

    <!-- Zapisz -->
    <div class="d-grid gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Utwórz procedurę
      </button>
      <a href="<?= APP_URL ?>/procedures/index.php" class="btn btn-outline-secondary">
        Anuluj
      </a>
    </div>

  </div>
</div>
</form>

<!-- EasyMDE -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.js"></script>
<script>
var easyMDE = new EasyMDE({
    element: document.getElementById('proc-editor'),
    spellChecker: false,
    autofocus: false,
    placeholder: 'Wpisz treść procedury w formacie Markdown...',
    toolbar: ['bold','italic','heading','|','quote','unordered-list','ordered-list','|','link','image','table','horizontal-rule','|','preview','side-by-side','fullscreen','|','guide'],
    status: ['lines','words'],
    minHeight: '400px',
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
