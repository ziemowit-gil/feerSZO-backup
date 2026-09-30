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

    if ($action === 'upload' && !empty($_POST['_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        $f = $_FILES['file'] ?? null;
        if (!$f) { echo json_encode(['ok' => false, 'error' => 'brak pliku']); exit; }
        [$rel, $err] = edok_queue_store_upload($f);
        if ($rel === null) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
        db_insert('edok_queue', [
            'file_path' => $rel, 'orig_name' => $f['name'],
            'file_size' => is_file(UPLOAD_DIR . $rel) ? filesize(UPLOAD_DIR . $rel) : null,
            'note' => trim($_POST['note'] ?? ''),
            'uploaded_by' => (int)$user['id'], 'uploader_name' => $user['name'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

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
    <div id="dz" class="border border-2 border-dashed rounded p-4 text-center mb-3" tabindex="0" role="button"
         aria-label="Upuść pliki tutaj lub kliknij, aby wybrać" style="border-style:dashed!important;cursor:pointer">
      <i class="bi bi-cloud-arrow-up fs-1 text-primary"></i>
      <div class="fw-semibold">Przeciągnij i upuść pliki tutaj</div>
      <div class="small text-muted">albo kliknij, aby wybrać · PDF, JPG, PNG, DOCX · max 20 MB każdy</div>
      <input type="file" id="dz_input" class="d-none" accept=".pdf,.jpg,.jpeg,.png,.docx" multiple>
    </div>
    <div class="mb-3">
      <label class="form-label" for="note">Notatka do partii <span class="text-muted fw-normal">(opcjonalnie)</span></label>
      <input type="text" name="note" id="note" class="form-control" maxlength="200" placeholder="np. faktury z poczty, wrzesień">
    </div>
    <ul id="dz_list" class="list-group mb-0" aria-live="polite"></ul>
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
<script>
(function () {
  var dz = document.getElementById('dz'), input = document.getElementById('dz_input'), list = document.getElementById('dz_list');
  var csrf = <?= json_encode(csrf_token()) ?>, ok = 0, pending = 0, ext = /\.(pdf|jpe?g|png|docx)$/i;
  dz.addEventListener('click', function () { input.click(); });
  dz.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
  ['dragenter', 'dragover'].forEach(function (t) { dz.addEventListener(t, function (e) { e.preventDefault(); dz.classList.add('bg-primary-subtle'); }); });
  ['dragleave', 'drop'].forEach(function (t) { dz.addEventListener(t, function (e) { e.preventDefault(); dz.classList.remove('bg-primary-subtle'); }); });
  dz.addEventListener('drop', function (e) { add(e.dataTransfer.files); });
  input.addEventListener('change', function () { add(input.files); input.value = ''; });
  // Upuszczenie pliku obok strefy nie może otworzyć go w karcie.
  ['dragover', 'drop'].forEach(function (t) { window.addEventListener(t, function (e) { e.preventDefault(); }); });

  function add(files) { Array.prototype.forEach.call(files, send); }
  function send(f) {
    var li = document.createElement('li'); li.className = 'list-group-item d-flex justify-content-between align-items-center small';
    var name = document.createElement('span'); name.textContent = f.name;
    var st = document.createElement('span'); st.className = 'text-muted'; li.append(name, st); list.appendChild(li);
    if (!ext.test(f.name)) return fail('niedozwolony typ pliku');
    if (f.size > 20 * 1024 * 1024) return fail('większy niż 20 MB');
    function fail(m) { st.textContent = m; st.className = 'text-danger'; }
    pending++;
    var fd = new FormData();
    fd.append('_csrf', csrf); fd.append('action', 'upload'); fd.append('_ajax', '1');
    fd.append('note', document.getElementById('note').value); fd.append('file', f);
    var x = new XMLHttpRequest(); x.open('POST', location.href);
    x.upload.onprogress = function (e) { if (e.lengthComputable) st.textContent = Math.round(e.loaded / e.total * 100) + '%'; };
    x.onload = function () {
      var r = {}; try { r = JSON.parse(x.responseText); } catch (e) {}
      if (r.ok) { st.textContent = 'dodano'; st.className = 'text-success'; ok++; } else fail(r.error || 'błąd serwera');
      done();
    };
    x.onerror = function () { fail('błąd sieci'); done(); };
    x.send(fd);
  }
  function done() { if (--pending === 0 && ok) setTimeout(function () { location.reload(); }, 1200); }
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
