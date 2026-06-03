<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
kdok_migrate();

if (!is_admin() && !kdok_has_role('zatwierdza')) {
    http_response_code(403);
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger m-4">Brak uprawnień.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$months_pl = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
$years_range = range((int)date('Y') - 3, (int)date('Y') + 1);

$PAGE_TITLE = 'Pobierz ZIP — EOD Dokumentów Księgowych';

// POST — wygeneruj i wyślij ZIP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $miesiac = (int)($_POST['miesiac'] ?? 0);
    $rok     = (int)($_POST['rok']     ?? 0);

    if ($miesiac < 1 || $miesiac > 12 || $rok < 2000 || $rok > 2100) {
        $error = 'Nieprawidłowy miesiąc lub rok.';
    } else {
        try {
            $zip_path = kdok_zip_month($miesiac, $rok);
            $zip_name = 'EOD_DK_' . sprintf('%04d_%02d', $rok, $miesiac) . '.zip';

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '"');
            header('Content-Length: ' . filesize($zip_path));
            header('Cache-Control: no-store');
            readfile($zip_path);
            @unlink($zip_path);
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

// GET z parametrami — pre-fill
$pre_miesiac = (int)($_GET['miesiac'] ?? (int)date('n'));
$pre_rok     = (int)($_GET['rok']     ?? (int)date('Y'));

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-file-zip"></i> Pobierz ZIP PDF z miesiąca</h4>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:480px">
  <div class="card-body">
    <p class="text-muted small mb-3">
      Pobierz archiwum ZIP zawierające finalne PDF-y wszystkich
      <strong>zaakceptowanych</strong> dokumentów z wybranego miesiąca.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-3 mb-3">
        <div class="col-sm-7">
          <label class="form-label fw-semibold">Miesiąc</label>
          <select name="miesiac" class="form-select" required>
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $pre_miesiac === $m ? 'selected' : '' ?>><?= $months_pl[$m] ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-sm-5">
          <label class="form-label fw-semibold">Rok</label>
          <select name="rok" class="form-select" required>
            <?php foreach ($years_range as $yr): ?>
            <option value="<?= $yr ?>" <?= $pre_rok === $yr ? 'selected' : '' ?>><?= $yr ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-success">
        <i class="bi bi-download"></i> Generuj i pobierz ZIP
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
