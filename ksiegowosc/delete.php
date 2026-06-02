<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = kdok_get($id);
if (!$doc) { http_response_code(404); die('Nie znaleziono.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Usuń pliki fizyczne
    if ($doc['file_path']) {
        $p = UPLOAD_DIR . $doc['file_path'];
        if (is_file($p)) unlink($p);
    }

    foreach (kdok_all("SELECT file_path FROM kdok_generated_pdf WHERE doc_id = ?", [$id]) as $g) {
        $p = UPLOAD_DIR . $g['file_path'];
        if (is_file($p)) unlink($p);
    }

    // Usuń z bazy KDOK
    foreach (['kdok_generated_pdf', 'kdok_steps', 'kdok_history'] as $t) {
        kdok_exec("DELETE FROM {$t} WHERE doc_id = ?", [$id]);
    }
    kdok_exec("DELETE FROM kdok_documents WHERE id = ?", [$id]);

    flash_set('success', 'Dokument ' . $doc['number'] . ' został usunięty.');
    header('Location: ' . APP_URL . '/ksiegowosc/index.php');
    exit;
}

$PAGE_TITLE = 'Usuń dokument';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0">Usuń dokument</h4>
</div>
<div class="alert alert-danger" style="max-width:600px">
  <p><strong>Na pewno chcesz usunąć?</strong></p>
  <p>Dokument: <strong><?= h($doc['number']) ?></strong> — <?= h($doc['title']) ?></p>
  <p>Usunięte zostaną: plik PDF, historia obiegu oraz wszystkie decyzje. Tej operacji nie można cofnąć.</p>
  <form method="post" class="d-flex gap-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Tak, usuń</button>
    <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $id ?>" class="btn btn-secondary">Anuluj</a>
  </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
