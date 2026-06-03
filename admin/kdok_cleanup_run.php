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

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $keep       = max(1, (int)($_POST['keep'] ?? 3));
    $older_days = max(7, (int)($_POST['older_days'] ?? 90));
    $result     = kdok_cleanup_old_generated($keep, $older_days);
    flash_set('success', 'Czyszczenie zakończone. Usunięto: ' . $result['deleted_files'] . ' plików, zwolniono: '
        . number_format($result['freed_bytes'] / 1024 / 1024, 2) . ' MB.');
}

// Statystyki
$gen_count = (int)(db_one("SELECT COUNT(*) AS c FROM kdok_generated_pdf")['c'] ?? 0);
$gen_size  = (int)(db_one("SELECT SUM(file_size) AS s FROM kdok_generated_pdf")['s'] ?? 0);
$doc_count = (int)(db_one("SELECT COUNT(*) AS c FROM kdok_documents")['c'] ?? 0);

$PAGE_TITLE = 'Czyszczenie — EOD Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-arrow-repeat"></i> Czyszczenie dokumentów księgowych</h4>
</div>
<?= flash_html() ?>

<div class="row g-3 mb-4" style="max-width:700px">
  <div class="col-sm-4">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold text-primary"><?= $doc_count ?></div>
      <div class="small text-muted">Dokumentów w bazie</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold text-info"><?= $gen_count ?></div>
      <div class="small text-muted">Wygenerowanych PDF</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold text-warning"><?= number_format($gen_size / 1024 / 1024, 1) ?> MB</div>
      <div class="small text-muted">Zajętość wygenerowanych PDF</div>
    </div>
  </div>
</div>

<?php if ($result && $result['errors']): ?>
<div class="alert alert-warning">
  <strong>Błędy podczas czyszczenia:</strong>
  <ul><?php foreach ($result['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:500px">
  <div class="card-header py-2"><strong>Uruchom czyszczenie</strong></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="mb-3">
        <label class="form-label">Zachowaj N najnowszych PDF na dokument</label>
        <input type="number" name="keep" class="form-control" value="3" min="1" max="20">
      </div>
      <div class="mb-3">
        <label class="form-label">Usuń pliki starsze niż (dni)</label>
        <input type="number" name="older_days" class="form-control" value="90" min="7">
        <div class="form-text">Dotyczy osieroconych plików (bez rekordu w bazie).</div>
      </div>
      <button type="submit" class="btn btn-warning">
        <i class="bi bi-trash3"></i> Uruchom czyszczenie
      </button>
    </form>
  </div>
</div>

<div class="mt-3 small text-muted">
  Czyszczenie automatyczne (cron): <code>php <?= h(dirname(__DIR__)) ?>/cron/kdok_cleanup.php</code>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
