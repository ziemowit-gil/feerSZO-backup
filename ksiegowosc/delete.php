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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $reason = trim($_POST['reason'] ?? '');
    if (mb_strlen($reason) < 10) {
        $errors[] = 'Podaj powód usunięcia (minimum 10 znaków).';
    }

    if (!$errors) {
        $user         = current_user();
        $user_name    = ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '');
        $history      = kdok_get_history($id);

        // Wygeneruj protokół PDF i zapisz na dysk
        $pdf_dir = UPLOAD_DIR . 'kdok_deletion_protocols/';
        if (!is_dir($pdf_dir)) mkdir($pdf_dir, 0755, true);

        $pdf_filename = 'protokol_usuniecia_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $doc['number'])
            . '_' . date('Ymd_His') . '.pdf';
        $pdf_full_path = $pdf_dir . $pdf_filename;
        $pdf_rel_path  = 'kdok_deletion_protocols/' . $pdf_filename;

        try {
            $pdf = kdok_build_deletion_protocol_pdf($doc, $history, $reason, trim($user_name) ?: ($user['email'] ?? 'nieznany'));
            $pdf->Output('F', $pdf_full_path);
        } catch (\Throwable $e) {
            $errors[] = 'Nie udało się wygenerować protokołu PDF: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $pdf_sha256 = hash_file('sha256', $pdf_full_path) ?: '';
        $pdf_size   = filesize($pdf_full_path) ?: 0;

        // Zapisz do rejestru usunięć
        kdok_insert('kdok_deletion_log', [
            'doc_id'            => $id,
            'doc_number'        => $doc['number'],
            'doc_title'         => $doc['title'],
            'doc_type'          => $doc['type'],
            'doc_status'        => $doc['status'],
            'doc_kwota'         => $doc['kwota'] ?? '',
            'deleted_by'        => (int)$user['id'],
            'deleted_by_name'   => trim($user_name) ?: ($user['email'] ?? ''),
            'reason'            => $reason,
            'protocol_pdf_path' => $pdf_rel_path,
            'protocol_pdf_sha256' => $pdf_sha256,
            'protocol_pdf_size' => $pdf_size,
        ]);

        // Usuń pliki fizyczne dokumentu
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

        $log_id = (int)(kdok_one("SELECT id FROM kdok_deletion_log WHERE doc_id=? AND protocol_pdf_path=? ORDER BY id DESC LIMIT 1",
            [$id, $pdf_rel_path])['id'] ?? 0);

        flash_set('success',
            'Dokument ' . h($doc['number']) . ' został usunięty. '
            . '<a href="' . APP_URL . '/ksiegowosc/deletion_log.php?download=' . $log_id . '" class="alert-link">Pobierz protokół PDF</a>');
        header('Location: ' . APP_URL . '/ksiegowosc/index.php');
        exit;
    }
}

$PAGE_TITLE = 'Usuń dokument — ' . $doc['number'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0">Usuń dokument</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card border-danger shadow-sm" style="max-width:640px">
  <div class="card-header bg-danger text-white">
    <i class="bi bi-trash-fill"></i> <strong>Trwałe usunięcie dokumentu</strong>
  </div>
  <div class="card-body">
    <p class="mb-1"><strong>Dokument:</strong> <?= h($doc['number']) ?> — <?= h($doc['title']) ?></p>
    <p class="mb-3 text-muted small">
      Zostaną usunięte: plik PDF, historia obiegu oraz wszystkie decyzje.
      Tej operacji nie można cofnąć. Przed usunięciem zostanie wygenerowany protokół PDF.
    </p>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id"    value="<?= $id ?>">

      <div class="mb-3">
        <label for="reason" class="form-label fw-semibold">
          Powód usunięcia <span class="text-danger">*</span>
        </label>
        <textarea name="reason" id="reason" class="form-control" rows="4"
          placeholder="Opisz przyczynę usunięcia dokumentu (wymagane, min. 10 znaków)…"
          required minlength="10"><?= h($_POST['reason'] ?? '') ?></textarea>
        <div class="form-text">
          Powód zostanie zapisany w rejestrze usunięć i dołączony do protokołu PDF.
        </div>
      </div>

      <div class="d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-danger">
          <i class="bi bi-trash"></i> Usuń i wygeneruj protokół PDF
        </button>
        <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $id ?>" class="btn btn-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
