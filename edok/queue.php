<?php
/**
 * edok/queue.php — Kolejka do opisu: zbiorczy upload plików + lista plików czekających
 * na opisanie. „Opisz” otwiera edok/add.php?queue=ID z podpiętym skanem; po złożeniu
 * dokumentu do obiegu wpis znika z kolejki.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_role('upload');
edok_migrate();

$user = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $files = $_FILES['files'] ?? null;
        $note  = trim($_POST['note'] ?? '');
        $ok = 0;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($name === '' && ($files['error'][$i] ?? 0) === UPLOAD_ERR_NO_FILE) continue;
                [$rel, $err] = edok_queue_store_upload([
                    'name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i],
                ]);
                if ($rel === null) { $errors[] = $name . ': ' . $err . '.'; continue; }
                db_insert('edok_queue', [
                    'file_path'     => $rel,
                    'orig_name'     => $name,
                    'file_size'     => is_file(UPLOAD_DIR . $rel) ? filesize(UPLOAD_DIR . $rel) : null,
                    'note'          => $note,
                    'uploaded_by'   => (int)$user['id'],
                    'uploader_name' => $user['name'] ?? '',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $ok++;
            }
        }
        if ($ok) flash_set('success', "Dodano do kolejki: {$ok}." . ($errors ? ' Pominięto: ' . count($errors) . '.' : ''));
        elseif (!$errors) $errors[] = 'Nie wybrano żadnych plików.';
        if ($ok && !$errors) { header('Location: ' . APP_URL . '/edok/queue.php'); exit; }
        if ($ok) { $flash_errors = $errors; }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = db_one("SELECT * FROM edok_queue WHERE id = ?", [$id]);
        // Usuwać może dodający albo admin — plik jest wspólny dla całej kolejki.
        if ($row && (is_admin() || (int)$row['uploaded_by'] === (int)$user['id'])) {
            @unlink(UPLOAD_DIR . $row['file_path']);
            db_exec("DELETE FROM edok_queue WHERE id = ?", [$id]);
            flash_set('success', 'Usunięto plik z kolejki.');
        } else {
            flash_set('danger', 'Nie możesz usunąć tego pliku.');
        }
        header('Location: ' . APP_URL . '/edok/queue.php');
        exit;
    }
}

$items = edok_queue_list();
$PAGE_TITLE = 'Kolejka do opisu — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-inboxes"></i> Kolejka do opisu
    <?php if ($items): ?><span class="badge bg-primary ms-1"><?= count($items) ?></span><?php endif; ?></h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm mb-4" style="max-width:760px">
  <div class="card-body">
    <h6 class="card-title">Wgraj wiele plików naraz</h6>
    <p class="small text-muted">Pliki trafią do kolejki. Każdy opiszesz osobno przyciskiem „Opisz” — dopiero wtedy dostanie numer EODoK i wejdzie do obiegu akceptacji.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="upload">
      <div class="mb-3">
        <label class="form-label" for="files">Pliki (PDF, JPG, PNG, DOCX, max 20 MB każdy)</label>
        <input type="file" name="files[]" id="files" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx" multiple required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="note">Notatka do partii <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="text" name="note" id="note" class="form-control" maxlength="200" placeholder="np. faktury z poczty, wrzesień">
      </div>
      <button class="btn btn-primary"><i class="bi bi-upload"></i> Wgraj do kolejki</button>
    </form>
  </div>
</div>

<?php if (!$items): ?>
<div class="alert alert-secondary">Kolejka jest pusta.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm align-middle">
  <thead><tr><th>Plik</th><th>Notatka</th><th>Wgrał(a)</th><th>Data</th><th class="text-end">Rozmiar</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): ?>
    <tr>
      <td><a href="<?= APP_URL ?>/uploads/<?= h($it['file_path']) ?>" target="_blank"><i class="bi bi-file-earmark"></i> <?= h($it['orig_name']) ?></a></td>
      <td class="small"><?= h($it['note']) ?></td>
      <td class="small"><?= h($it['uploader_name']) ?></td>
      <td class="small"><?= h(substr($it['created_at'], 0, 16)) ?></td>
      <td class="small text-end"><?= $it['file_size'] ? h(number_format($it['file_size'] / 1024, 0, ',', ' ')) . ' KB' : '—' ?></td>
      <td class="text-end text-nowrap">
        <a href="<?= APP_URL ?>/edok/add.php?queue=<?= (int)$it['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square"></i> Opisz</a>
        <?php if (is_admin() || (int)$it['uploaded_by'] === (int)$user['id']): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten plik z kolejki?');">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
          <button class="btn btn-sm btn-outline-danger" aria-label="Usuń z kolejki"><i class="bi bi-trash3"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
