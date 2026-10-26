<?php
/** contracts/szk/ustawienia.php — Ustawienia umów SZK: domyślny model opłat, raty, termin płatności, wzorzec nazwy pliku. Tylko admin. */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractFees.php';

require_login();
if (!is_admin()) { flash_set('error', 'Ustawienia umów szkoleniowych są dostępne tylko dla administratora.'); header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    TiSzkFees::saveSettings($_POST);
    flash_set('success', 'Zapisano ustawienia umów szkoleniowych.');
    header('Location: ustawienia.php'); exit;
}
$S = TiSzkFees::settings();
$PAGE_TITLE = 'Ustawienia umów szkoleniowych';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-gear text-primary"></i> Ustawienia umów szkoleniowych (SZK)</h4>
<form method="post" class="card shadow-sm" style="max-width:720px"><div class="card-body">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <div class="mb-3"><label class="form-label" for="s-model">Domyślny model naliczania opłat</label>
    <select class="form-select" id="s-model" name="model"><?php foreach (TiSzkFees::MODELS as $k => $l): ?><option value="<?= h($k) ?>" <?= $S['model'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label class="form-label" for="s-raty">Domyślna liczba rat</label><input class="form-control" id="s-raty" type="number" min="1" max="60" name="raty" value="<?= (int)$S['raty'] ?>"></div>
    <div class="col-md-6"><label class="form-label" for="s-dni">Termin płatności (dni od zawarcia)</label><input class="form-control" id="s-dni" type="number" min="0" max="365" name="dni" value="<?= (int)$S['dni'] ?>"></div>
  </div>
  <div class="mb-3"><label class="form-label" for="s-fn">Nazwa pliku DOCX umowy</label>
    <input class="form-control font-monospace" id="s-fn" name="filename" value="<?= h($S['filename']) ?>" aria-describedby="s-fn-h">
    <div class="form-text" id="s-fn-h">Litery bez polskich znaków, cyfry, _ i -. Zmienne: {nr} numer umowy, {nazwisko} uczestnik, {data} data zawarcia, {temat} temat szkolenia. Rozszerzenie .docx dodawane automatycznie.</div></div>
  <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
  <a class="btn btn-link" href="list.php">Wróć do listy</a>
</div></form>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
