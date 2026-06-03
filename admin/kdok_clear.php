<?php
/**
 * admin/kdok_clear.php — Czyszczenie danych systemu obiegu EOD Dokumentów Księgowych
 *
 * Wymaga potwierdzenia kodem IKAKS administratora.
 * Usuwa: dokumenty, kroki, historię, wygenerowane PDF-y, kolejkę KSeF.
 * Zachowuje: ustawienia, role, certyfikaty, użytkowników.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();
kdok_ksef_migrate();

$PAGE_TITLE = 'Czyszczenie danych obiegu — EOD';
$error      = null;
$success    = false;
$counts     = [];

// ── Zlicz bieżące dane ────────────────────────────────────────────────────────

function kdok_clear_counts(): array {
    return [
        'Dokumenty'       => (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_documents")['c']      ?? 0),
        'Kroki akceptacji' => (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_steps")['c']         ?? 0),
        'Historia obiegu' => (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_history")['c']        ?? 0),
        'Wygenerowane PDF' => (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_generated_pdf")['c'] ?? 0),
        'Kolejka KSeF'    => (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_ksef_queue")['c']     ?? 0),
    ];
}

$counts = kdok_clear_counts();

// ── POST: potwierdzenie i czyszczenie ─────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ikaks    = $_POST['ikaks']    ?? '';
    $confirm  = $_POST['confirm']  ?? '';
    $user     = current_user();

    if ($confirm !== 'WYCZYSC') {
        $error = 'Wpisz dokładnie "WYCZYSC" w polu potwierdzenia.';
    } elseif (!kdok_ikaks_has((int)$user['id'])) {
        $error = 'Nie masz ustawionego kodu IKAKS. Skontaktuj się z administratorem.';
    } elseif (!kdok_ikaks_verify((int)$user['id'], $ikaks)) {
        $error = 'Nieprawidłowy kod IKAKS.';
    } else {
        // ── Usuń pliki PDF z dysku ────────────────────────────────────────────
        $pdfs = kdok_all("SELECT file_path FROM kdok_generated_pdf");
        foreach ($pdfs as $pdf) {
            $full = UPLOAD_DIR . $pdf['file_path'];
            if (is_file($full)) @unlink($full);
        }
        // Usuń też oryginalne dokumenty
        $docs = kdok_all("SELECT file_path FROM kdok_documents WHERE file_path IS NOT NULL");
        foreach ($docs as $doc) {
            $full = UPLOAD_DIR . $doc['file_path'];
            if (is_file($full)) @unlink($full);
        }

        // ── Wyczyść tabele ────────────────────────────────────────────────────
        foreach (['kdok_ksef_queue', 'kdok_generated_pdf', 'kdok_history', 'kdok_steps', 'kdok_documents'] as $table) {
            try { kdok_exec("DELETE FROM {$table}"); } catch (\Throwable $e) {}
        }

        // ── Zresetuj sekwencję AUTO_INCREMENT (SQLite) ────────────────────────
        $kdb_type = org_setting('kdok_db_type');
        if ($kdb_type !== 'mysql') {
            foreach (['kdok_documents', 'kdok_steps', 'kdok_history', 'kdok_generated_pdf', 'kdok_ksef_queue'] as $t) {
                try { kdok_exec("DELETE FROM sqlite_sequence WHERE name=?", [$t]); } catch (\Throwable $_) {}
            }
        }

        // ── Log systemowy ─────────────────────────────────────────────────────
        $msg = "KDOK_CLEAR: Dane obiegu EOD wyczyszczone przez {$user['name']} (ID {$user['id']}) IP " . ($_SERVER['REMOTE_ADDR'] ?? '?');
        error_log($msg);

        $success = true;
        $counts  = kdok_clear_counts();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0 text-danger"><i class="bi bi-trash3"></i> Czyszczenie danych obiegu EOD</h4>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle-fill me-2"></i>
  <strong>Dane zostały wyczyszczone.</strong> System obiegu jest pusty.
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= h($error) ?></div>
<?php endif; ?>

<!-- Stan bieżący -->
<div class="card shadow-sm mb-4" style="max-width:600px">
  <div class="card-header fw-semibold"><i class="bi bi-database me-1"></i>Bieżące dane systemu obiegu</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <tbody>
        <?php foreach ($counts as $label => $cnt): ?>
        <tr>
          <td class="ps-3 py-2"><?= h($label) ?></td>
          <td class="fw-bold py-2 <?= $cnt > 0 ? 'text-danger' : 'text-success' ?>">
            <?= $cnt ?> <?= $cnt === 0 ? '<i class="bi bi-check-circle text-success"></i>' : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (array_sum($counts) === 0): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>System obiegu jest już pusty.</div>
<?php else: ?>

<!-- Formularz potwierdzenia -->
<div class="card border-danger shadow-sm" style="max-width:600px">
  <div class="card-header bg-danger text-white fw-semibold">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>Strefa zagrożenia — operacja nieodwracalna
  </div>
  <div class="card-body">
    <p class="mb-3">
      Ta operacja <strong>trwale usuwa</strong> wszystkie dokumenty, kroki akceptacji,
      historię obiegu, wygenerowane PDF-y i kolejkę KSeF.
      Zachowane zostają role, certyfikaty i ustawienia.
    </p>
    <p class="text-danger fw-semibold mb-3">
      <i class="bi bi-exclamation-octagon me-1"></i>Nie można tego cofnąć.
    </p>

    <form method="post" id="clearForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Kod IKAKS administratora</label>
        <input type="password" name="ikaks" class="form-control" autocomplete="off"
               placeholder="Wpisz swój kod IKAKS…" required>
        <div class="form-text">Wymagany do potwierdzenia tożsamości operatora.</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">
          Wpisz <code class="text-danger">WYCZYSC</code> aby potwierdzić
        </label>
        <input type="text" name="confirm" class="form-control" autocomplete="off"
               placeholder="WYCZYSC" required pattern="WYCZYSC">
      </div>

      <button type="submit" class="btn btn-danger"
              onclick="return confirm('OSTATNIE OSTRZEŻENIE: Trwale usunąć wszystkie dane obiegu EOD?')">
        <i class="bi bi-trash3 me-1"></i>Wyczyść dane systemu obiegu
      </button>
      <a href="<?= APP_URL ?>/admin/" class="btn btn-outline-secondary ms-2">Anuluj</a>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
