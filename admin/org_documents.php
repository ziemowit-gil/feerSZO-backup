<?php
/**
 * admin/org_documents.php — Zarządzanie „Dokumentami organizacji".
 * Admin/edytor wgrywa pliki (statut, regulaminy, formularze, wzory),
 * które wszyscy zalogowani (w tym wolontariusze) mogą przeglądać i pobierać.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_docs.php';

require_role('admin', 'editor');
require_module_enabled('org_documents_enabled', 'Moduł dokumentów organizacji');

$PAGE_TITLE = 'Dokumenty organizacji';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $uid    = (int)(current_user()['id'] ?? 0);

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') $errors[] = 'Podaj tytuł dokumentu.';
        $up = null;
        if (!$errors) {
            try { $up = org_docs_upload('file'); }
            catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        }
        if (!$errors) {
            org_docs_create([
                'title'         => $title,
                'description'   => trim($_POST['description'] ?? ''),
                'category'      => trim($_POST['category'] ?? ''),
                'filename'      => $up['filename'],
                'original_name' => $up['original_name'],
                'mime_type'     => $up['mime_type'],
                'file_size'     => $up['file_size'],
                'is_active'     => isset($_POST['is_active']) ? 1 : 1,
            ], $uid);
            flash_set('success', 'Dokument „' . $title . '" został dodany.');
            header('Location: org_documents.php'); exit;
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $doc = org_docs_get($id);
        if ($doc) {
            $data = [
                'title'       => trim($_POST['title'] ?? $doc['title']),
                'description' => trim($_POST['description'] ?? ''),
                'category'    => trim($_POST['category'] ?? ''),
                'is_active'   => isset($_POST['is_active']) ? 1 : 0,
            ];
            // Opcjonalna podmiana pliku
            if (!empty($_FILES['file']['name'])) {
                try {
                    $up = org_docs_upload('file');
                    if ($doc['filename']) { $old = rtrim(UPLOAD_DIR,'/').'/'.ORGDOC_UPLOAD_SUBDIR.$doc['filename']; if (is_file($old)) @unlink($old); }
                    $data['filename'] = $up['filename'];
                    $data['original_name'] = $up['original_name'];
                    $data['mime_type'] = $up['mime_type'];
                    $data['file_size'] = $up['file_size'];
                } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
            }
            if (!$errors && $data['title'] === '') $errors[] = 'Tytuł nie może być pusty.';
            if (!$errors) {
                org_docs_update($id, $data);
                flash_set('success', 'Dokument zaktualizowany.');
                header('Location: org_documents.php'); exit;
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $doc = org_docs_get($id);
        if ($doc) { org_docs_update($id, ['is_active' => $doc['is_active'] ? 0 : 1]); flash_set('success', 'Zmieniono widoczność dokumentu.'); }
        header('Location: org_documents.php'); exit;
    }

    if ($action === 'delete') {
        org_docs_delete((int)($_POST['id'] ?? 0));
        flash_set('success', 'Dokument usunięty.');
        header('Location: org_documents.php'); exit;
    }
}

$docs       = org_docs_all();           // wszystkie (admin)
$categories = org_docs_categories();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-folder2-open me-2"></i><?= h($PAGE_TITLE) ?></h4>
  <span class="badge bg-secondary"><?= count($docs) ?></span>
</div>

<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<p class="text-muted small">Pliki widoczne dla wszystkich zalogowanych (statut, regulaminy, formularze, wzory).
Wolontariusze znajdą je w panelu: <em>Wsparcie → Dokumenty organizacji</em>.</p>

<!-- Lista -->
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Dokumenty</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>Tytuł</th><th>Kategoria</th><th>Plik</th><th>Status</th><th class="text-end">Akcje</th></tr></thead>
      <tbody>
        <?php if (!$docs): ?><tr><td colspan="5" class="text-center text-muted py-4">Brak dokumentów. Dodaj pierwszy poniżej.</td></tr><?php endif; ?>
        <?php foreach ($docs as $d): ?>
        <tr class="<?= $d['is_active'] ? '' : 'opacity-50' ?>">
          <td>
            <div class="fw-semibold"><?= h($d['title']) ?></div>
            <?php if ($d['description']): ?><div class="small text-muted text-truncate" style="max-width:320px"><?= h($d['description']) ?></div><?php endif; ?>
          </td>
          <td class="small"><?= h($d['category'] ?: '—') ?></td>
          <td class="small text-nowrap">
            <a href="<?= APP_URL ?>/org_documents/serve.php?id=<?= (int)$d['id'] ?>&download">
              <i class="bi <?= h(org_docs_file_icon($d['original_name'])) ?> me-1"></i><?= h(mb_substr($d['original_name'], 0, 26, 'UTF-8')) ?>
            </a>
            <span class="text-muted">· <?= h(org_docs_filesize_human((int)$d['file_size'])) ?></span>
          </td>
          <td><span class="badge bg-<?= $d['is_active'] ? 'success' : 'secondary' ?>"><?= $d['is_active'] ? 'Widoczny' : 'Ukryty' ?></span></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#edit<?= (int)$d['id'] ?>" title="Edytuj"><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" title="<?= $d['is_active']?'Ukryj':'Pokaż' ?>"><i class="bi bi-<?= $d['is_active']?'eye-slash':'eye' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć dokument i plik?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Usuń"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Dodaj nowy -->
<div class="card">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle me-1 text-success"></i>Dodaj dokument</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="create">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required maxlength="200" placeholder="np. Statut Fundacji">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Kategoria</label>
        <input type="text" name="category" class="form-control" list="orgdoc-cats" placeholder="np. Statut, Regulaminy">
        <datalist id="orgdoc-cats"><?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Plik <span class="text-danger">*</span></label>
        <input type="file" name="file" class="form-control" required>
      </div>
      <div class="col-12">
        <label class="form-label">Opis <span class="text-muted small">(opcjonalnie)</span></label>
        <textarea name="description" class="form-control" rows="2" placeholder="Krótki opis dokumentu"></textarea>
      </div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Dodaj dokument</button>
        <span class="form-text ms-2">Dozwolone: <?= h(implode(', ', ORGDOC_ALLOWED_EXT)) ?>; maks. <?= ORGDOC_MAX_SIZE/1048576 ?> MB.</span>
      </div>
    </form>
  </div>
</div>

<!-- Modale edycji -->
<?php foreach ($docs as $d): ?>
<div class="modal fade" id="edit<?= (int)$d['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <div class="modal-header"><h5 class="modal-title">Edytuj: <?= h($d['title']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label fw-semibold">Tytuł</label><input type="text" name="title" class="form-control" value="<?= h($d['title']) ?>" required></div>
          <div class="mb-2"><label class="form-label">Kategoria</label><input type="text" name="category" class="form-control" value="<?= h($d['category']) ?>" list="orgdoc-cats"></div>
          <div class="mb-2"><label class="form-label">Opis</label><textarea name="description" class="form-control" rows="2"><?= h($d['description']) ?></textarea></div>
          <div class="mb-2"><label class="form-label">Podmień plik <span class="text-muted small">(opcjonalnie)</span></label><input type="file" name="file" class="form-control"><div class="form-text">Obecny: <?= h($d['original_name']) ?></div></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="act<?= (int)$d['id'] ?>" <?= $d['is_active']?'checked':'' ?>><label class="form-check-label" for="act<?= (int)$d['id'] ?>">Widoczny dla użytkowników</label></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary">Zapisz</button></div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
