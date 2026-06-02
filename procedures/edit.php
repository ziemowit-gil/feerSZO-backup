<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/procedures.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin', 'editor');
require_module_enabled('procedures_enabled', 'Moduł procedur');

$id   = (int)($_GET['id'] ?? 0);
$proc = proc_get($id);
if (!$proc || $proc['status'] === 'deleted') {
    flash_set('error', 'Procedura nie istnieje.');
    header('Location: ' . APP_URL . '/procedures/index.php'); exit;
}

$PAGE_TITLE = 'Edycja: ' . $proc['title'];
$errors     = [];

$categories   = proc_get_categories();
$all_procs    = proc_get_all(['status' => 'active']);
$users        = proc_get_users_list();
$current_rels = array_column(proc_get_related($id), 'id');

// Prefill z bieżącej wersji
$row = [
    'title'       => $proc['title'],
    'content'     => $proc['content'],
    'category'    => $proc['category'],
    'owner_id'    => $proc['owner_id'],
    'related_ids' => $current_rels,
    'change_note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = [
        'title'       => trim($_POST['title']       ?? ''),
        'content'     => $_POST['content']           ?? '',
        'category'    => trim($_POST['category']     ?? ''),
        'owner_id'    => (int)($_POST['owner_id']    ?? 0) ?: null,
        'related_ids' => array_filter(array_map('intval', $_POST['related_ids'] ?? [])),
        'change_note' => trim($_POST['change_note']  ?? ''),
    ];

    if ($row['title'] === '') $errors[] = 'Tytuł jest wymagany.';

    if (!$errors) {
        $user_id = (int)current_user()['id'];
        proc_update($id, $row, $user_id, $row['change_note']);

        // Nowy załącznik (opcjonalny)
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $err = proc_upload_attachment($id, 'attachment', $user_id);
            if ($err) $errors[] = 'Plik: ' . $err;
        }

        if (!$errors) {
            log_system_action($user_id, 'proc_update', "Edytowano procedurę #$id: " . $row['title']);
            flash_set('success', 'Zmiany zapisane (wersja ' . ($proc['version'] + 1) . ').');
            header('Location: ' . APP_URL . '/procedures/view.php?id=' . $id); exit;
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/procedures/index.php">Procedury</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/procedures/view.php?id=<?= $id ?>"><?= h($proc['title']) ?></a></li>
    <li class="breadcrumb-item active">Edycja</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-3">
  <h4 class="fw-bold mb-0">
    <i class="bi bi-pencil-square text-primary me-2"></i>Edycja procedury
  </h4>
  <span class="badge bg-light text-muted border">v<?= $proc['version'] ?> → v<?= $proc['version'] + 1 ?></span>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-4">
  <!-- Lewa: treść -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <div class="mb-3">
          <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control"
                 value="<?= h($row['title']) ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">
            Treść <small class="text-muted fw-normal">(Markdown)</small>
          </label>
          <textarea name="content" id="proc-editor" class="form-control"
                    rows="18" style="font-family:monospace;font-size:.88rem;resize:vertical"><?= h($row['content']) ?></textarea>
        </div>

        <!-- Nota o zmianie -->
        <div class="mb-0">
          <label class="form-label fw-semibold" style="font-size:.85rem">
            Opis zmian <small class="text-muted fw-normal">(zapisywany w historii wersji)</small>
          </label>
          <input type="text" name="change_note" class="form-control form-control-sm"
                 value="<?= h($row['change_note']) ?>"
                 placeholder="np. Aktualizacja sekcji 3 — zmiana odpowiedzialności">
        </div>
      </div>
    </div>

    <!-- Podgląd historii -->
    <div class="card shadow-sm mt-3">
      <div class="card-header fw-semibold d-flex align-items-center justify-content-between" style="font-size:.85rem">
        <span><i class="bi bi-clock-history me-1 text-primary"></i>Historia wersji</span>
        <a href="<?= APP_URL ?>/procedures/view.php?id=<?= $id ?>" class="btn btn-xs btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Widok procedury
        </a>
      </div>
      <div class="card-body p-0">
        <?php $versions = proc_get_versions($id); ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0" style="font-size:.8rem">
            <thead class="table-light">
              <tr><th>Wersja</th><th>Zmiana</th><th>Autor</th><th>Data</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($versions, 0, 8) as $v): ?>
            <tr>
              <td><span class="badge bg-<?= $v['version'] === $proc['version'] ? 'primary' : 'light text-dark border' ?>">v<?= $v['version'] ?></span></td>
              <td><?= h($v['change_note'] ?: '—') ?></td>
              <td><?= h($v['changed_by_name'] ?? '—') ?></td>
              <td><?= date('d.m.Y H:i', strtotime($v['created_at'])) ?></td>
              <td>
                <a href="<?= APP_URL ?>/procedures/view.php?id=<?= $id ?>&v=<?= $v['version'] ?>"
                   class="btn btn-xs btn-outline-secondary btn-sm" target="_blank">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Prawa: meta -->
  <div class="col-lg-4">

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
        <div>
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
    <?php $other_procs = array_filter($all_procs, fn($p) => $p['id'] !== $id); ?>
    <?php if ($other_procs): ?>
    <div class="card shadow-sm mb-3" id="relations">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-diagram-3 me-1 text-primary"></i>Procedury powiązane
      </div>
      <div class="card-body" style="max-height:280px;overflow-y:auto">
        <?php foreach ($other_procs as $p): ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox"
                 name="related_ids[]" value="<?= $p['id'] ?>"
                 id="rel_<?= $p['id'] ?>"
                 <?= in_array((int)$p['id'], array_map('intval', $row['related_ids']), true) ? 'checked' : '' ?>>
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

    <!-- Nowy załącznik -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-paperclip me-1 text-primary"></i>Dodaj załącznik
      </div>
      <div class="card-body">
        <input type="file" name="attachment" class="form-control form-control-sm"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.png,.jpg,.jpeg,.gif,.webp,.zip,.txt,.csv">
        <div class="form-text">Maks. 15 MB</div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-1"></i>Zapisz zmiany (nowa wersja)
      </button>
      <a href="<?= APP_URL ?>/procedures/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
        Anuluj
      </a>
    </div>

  </div>
</div>
</form>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.js"></script>
<script>
new EasyMDE({
    element: document.getElementById('proc-editor'),
    spellChecker: false,
    toolbar: ['bold','italic','heading','|','quote','unordered-list','ordered-list','|','link','image','table','horizontal-rule','|','preview','side-by-side','fullscreen','|','guide'],
    status: ['lines','words'],
    minHeight: '380px',
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
