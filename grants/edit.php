<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/grants.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Edytuj grant';
$errors = [];

$id = (int)($_GET['id'] ?? 0);
$grant = grant_by_id($id);
if (!$grant) {
    flash_set('danger', 'Grant nie istnieje.');
    header('Location: ' . APP_URL . '/grants/index.php');
    exit;
}

$row = $grant;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;

    if (empty(trim($row['nazwa'] ?? '')))   $errors[] = 'Nazwa jest wymagana.';
    if (empty(trim($row['donator'] ?? ''))) $errors[] = 'Donator jest wymagany.';
    if (empty($row['status']))              $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        $obszary = array_values(array_filter((array)($_POST['obszar_tematyczny'] ?? [])));

        $allowed = ['nazwa','donator','program','nr_umowy','nr_wewnetrzny','status',
                    'kwota_wnioskowana','kwota_przyznana','waluta',
                    'data_zlozenia','data_od','data_do','data_rozliczenia',
                    'cel_strategiczny','koordynator_id','opis','uwagi'];
        $data = array_intersect_key($row, array_flip($allowed));
        $data['obszar_tematyczny'] = json_encode($obszary);

        foreach (['kwota_wnioskowana','kwota_przyznana','koordynator_id',
                  'data_zlozenia','data_od','data_do','data_rozliczenia'] as $f) {
            if (isset($data[$f]) && trim((string)$data[$f]) === '') $data[$f] = null;
        }

        db_update('grants', $data, $id);
        flash_set('success', 'Grant został zaktualizowany.');
        header('Location: ' . APP_URL . '/grants/view.php?id=' . $id);
        exit;
    }
}

$obszary_selected = json_decode($row['obszar_tematyczny'] ?? '[]', true) ?: [];
$obszary_opts = ['edukacja','kultura','zdrowie','sport','ekologia','prawa człowieka','inne'];
$users = db_all("SELECT id, name FROM users WHERE role IN ('admin','editor') ORDER BY name");
$grant_statuses = ['pomysł','złożony','oczekuje','przyznany','w realizacji','rozliczany','zamknięty','odrzucony'];

include dirname(__DIR__) . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-pencil-square text-primary"></i> Edytuj grant</h4>
  <div class="d-flex gap-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye"></i> Podgląd</a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- 1. Identyfikacja -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Identyfikacja</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Nazwa <span class="text-danger">*</span></label>
      <input name="nazwa" class="form-control" value="<?= h($row['nazwa'] ?? '') ?>" required autofocus>
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label">Donator <span class="text-danger">*</span></label>
      <input name="donator" class="form-control" value="<?= h($row['donator'] ?? '') ?>" required>
    </div>
  </div>
  <div class="row">
    <div class="col-md-4 mb-3">
      <label class="form-label">Program</label>
      <input name="program" class="form-control" value="<?= h($row['program'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label">Nr umowy</label>
      <input name="nr_umowy" class="form-control font-monospace" value="<?= h($row['nr_umowy'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label">Nr wewnętrzny</label>
      <input name="nr_wewnetrzny" class="form-control font-monospace" value="<?= h($row['nr_wewnetrzny'] ?? '') ?>">
    </div>
  </div>
  <div class="row">
    <div class="col-md-4 mb-3">
      <label class="form-label">Status <span class="text-danger">*</span></label>
      <select name="status" class="form-select" required>
        <?php foreach ($grant_statuses as $s): ?>
        <option value="<?= h($s) ?>" <?= ($row['status'] ?? '') === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>
</div>

<!-- 2. Finansowanie -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-currency-euro"></i> Finansowanie</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-4 mb-3">
      <label class="form-label">Kwota wnioskowana</label>
      <input name="kwota_wnioskowana" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['kwota_wnioskowana'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label">Kwota przyznana</label>
      <input name="kwota_przyznana" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['kwota_przyznana'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label">Waluta</label>
      <select name="waluta" class="form-select">
        <?php foreach (['PLN','EUR','USD'] as $w): ?>
        <option value="<?= $w ?>" <?= ($row['waluta'] ?? 'PLN') === $w ? 'selected' : '' ?>><?= $w ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>
</div>

<!-- 3. Harmonogram -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-calendar-range"></i> Harmonogram</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-3 mb-3">
      <label class="form-label">Data złożenia</label>
      <input name="data_zlozenia" data-flatpickr class="form-control" value="<?= h($row['data_zlozenia'] ?? '') ?>" placeholder="RRRR-MM-DD">
    </div>
    <div class="col-md-3 mb-3">
      <label class="form-label">Data od</label>
      <input name="data_od" data-flatpickr class="form-control" value="<?= h($row['data_od'] ?? '') ?>" placeholder="RRRR-MM-DD">
    </div>
    <div class="col-md-3 mb-3">
      <label class="form-label">Data do</label>
      <input name="data_do" data-flatpickr class="form-control" value="<?= h($row['data_do'] ?? '') ?>" placeholder="RRRR-MM-DD">
    </div>
    <div class="col-md-3 mb-3">
      <label class="form-label">Data rozliczenia</label>
      <input name="data_rozliczenia" data-flatpickr class="form-control" value="<?= h($row['data_rozliczenia'] ?? '') ?>" placeholder="RRRR-MM-DD">
    </div>
  </div>
</div>
</div>

<!-- 4. Organizacja -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-people"></i> Organizacja</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Koordynator</label>
      <select name="koordynator_id" id="koordynator-select" class="form-select">
        <option value="">— brak —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= ($row['koordynator_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label">Cel strategiczny</label>
    <textarea name="cel_strategiczny" class="form-control" rows="2"><?= h($row['cel_strategiczny'] ?? '') ?></textarea>
  </div>
  <div class="mb-3">
    <label class="form-label d-block">Obszar tematyczny</label>
    <div class="d-flex flex-wrap gap-3">
      <?php foreach ($obszary_opts as $o): ?>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="obszar_tematyczny[]"
               value="<?= h($o) ?>" id="ob_<?= h($o) ?>"
               <?= in_array($o, $obszary_selected) ? 'checked' : '' ?>>
        <label class="form-check-label" for="ob_<?= h($o) ?>"><?= h(ucfirst($o)) ?></label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label">Opis</label>
    <textarea name="opis" class="form-control" rows="3"><?= h($row['opis'] ?? '') ?></textarea>
  </div>
  <div class="mb-3">
    <label class="form-label">Uwagi</label>
    <textarea name="uwagi" class="form-control" rows="2"><?= h($row['uwagi'] ?? '') ?></textarea>
  </div>
</div>
</div>

<div class="d-flex gap-2 mb-4">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Zapisz zmiany</button>
  <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</form>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/pl.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<script>
flatpickr('[data-flatpickr]', {locale: 'pl', dateFormat: 'Y-m-d', allowInput: true});
new TomSelect('#koordynator-select', {allowEmptyOption: true});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
