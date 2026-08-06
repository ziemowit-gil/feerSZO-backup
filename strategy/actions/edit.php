<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/grants.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Edytuj działanie';
$errors = [];

$id = (int)($_GET['id'] ?? 0);
$action = action_by_id($id);
if (!$action) {
    flash_set('danger', 'Działanie nie istnieje.');
    header('Location: ' . APP_URL . '/strategy/actions/index.php');
    exit;
}

$row = $action;
$existing_grants     = action_grants_for($id);
$existing_indicators = grant_action_indicators_for($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;

    if (empty(trim($row['nazwa'] ?? ''))) $errors[] = 'Nazwa jest wymagana.';
    if (empty($row['typ']))               $errors[] = 'Typ jest wymagany.';
    if (empty($row['status']))            $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        $allowed = ['nazwa','typ','opis','status','koordynator_id',
                    'data_od','data_do','cykliczne','czestotliwosc',
                    'lokalizacja','forma','link_online','wlasne_dzialanie',
                    'korzysci_tytul','korzysci','dla_kogo'];
        $data = array_intersect_key($row, array_flip($allowed));

        $data['cykliczne']        = isset($row['cykliczne']) ? 1 : 0;
        $data['wlasne_dzialanie'] = isset($row['wlasne_dzialanie']) ? 1 : 0;

        foreach (['koordynator_id','data_od','data_do','czestotliwosc','link_online','lokalizacja'] as $f) {
            if (isset($data[$f]) && trim((string)$data[$f]) === '') $data[$f] = null;
        }

        db_update('actions', $data, $id);

        // Replace action_grants
        db()->prepare("DELETE FROM action_grants WHERE action_id = ?")->execute([$id]);
        $grant_ids   = $_POST['grant_id'] ?? [];
        $grant_pcts  = $_POST['udzial_procent'] ?? [];
        $grant_kwoty = $_POST['grant_kwota'] ?? [];
        foreach ($grant_ids as $i => $gid) {
            $gid = (int)$gid;
            if (!$gid) continue;
            db_insert('action_grants', [
                'action_id'      => $id,
                'grant_id'       => $gid,
                'udzial_procent' => trim($grant_pcts[$i] ?? '') !== '' ? (float)$grant_pcts[$i] : null,
                'kwota'          => trim($grant_kwoty[$i] ?? '') !== '' ? (float)$grant_kwoty[$i] : null,
            ]);
        }

        // Replace indicators
        db()->prepare("DELETE FROM action_indicators WHERE action_id = ?")->execute([$id]);
        $ind_nazwy  = $_POST['ind_nazwa'] ?? [];
        $ind_plan   = $_POST['ind_planowana'] ?? [];
        $ind_real   = $_POST['ind_realizowana'] ?? [];
        foreach ($ind_nazwy as $i => $naz) {
            $naz = trim($naz);
            if ($naz === '') continue;
            db_insert('action_indicators', [
                'action_id'          => $id,
                'nazwa'              => $naz,
                'wartosc_planowana'  => trim($ind_plan[$i] ?? '') !== '' ? (float)$ind_plan[$i] : null,
                'wartosc_realizowana'=> trim($ind_real[$i] ?? '') !== '' ? (float)$ind_real[$i] : 0,
            ]);
        }

        flash_set('success', 'Działanie zostało zaktualizowane.');
        header('Location: ' . APP_URL . '/strategy/actions/view.php?id=' . $id);
        exit;
    }
}

$users  = db_all("SELECT id, name FROM users WHERE role IN ('admin','editor') ORDER BY name");
$grants = db_all("SELECT id, nazwa, donator FROM grants ORDER BY nazwa");

$action_statuses = ['planowane','w_przygotowaniu','w_trakcie','zawieszone','zakończone','anulowane'];
$action_types    = ['warsztat','konferencja','kampania','wsparcie_indywidualne','szkolenie','spotkanie','inne'];

include dirname(__DIR__) . '/includes/header_strategy.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-pencil-square text-primary"></i> Edytuj działanie</h4>
  <div class="d-flex gap-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye"></i> Podgląd</a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" id="action-form">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- 1. Podstawowe -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Podstawowe</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Nazwa <span class="text-danger">*</span></label>
      <input name="nazwa" class="form-control" value="<?= h($row['nazwa'] ?? '') ?>" required autofocus>
    </div>
    <div class="col-md-3 mb-3">
      <label class="form-label">Typ <span class="text-danger">*</span></label>
      <select name="typ" class="form-select" required>
        <?php foreach ($action_types as $t): ?>
        <option value="<?= h($t) ?>" <?= ($row['typ'] ?? '') === $t ? 'selected' : '' ?>><?= h(str_replace('_',' ', ucfirst($t))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 mb-3">
      <label class="form-label">Status <span class="text-danger">*</span></label>
      <select name="status" class="form-select" required>
        <?php foreach ($action_statuses as $s): ?>
        <option value="<?= h($s) ?>" <?= ($row['status'] ?? '') === $s ? 'selected' : '' ?>><?= h(str_replace('_',' ', ucfirst($s))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Koordynator</label>
      <select name="koordynator_id" id="koord-select" class="form-select">
        <option value="">— brak —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= ($row['koordynator_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label">Opis</label>
    <textarea name="opis" class="form-control" rows="3"><?= h($row['opis'] ?? '') ?></textarea>
  </div>
</div>
</div>

<!-- 2. Harmonogram -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-calendar-range"></i> Harmonogram</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-3 mb-3">
      <label class="form-label">Data od</label>
      <input name="data_od" data-flatpickr class="form-control" value="<?= h($row['data_od'] ?? '') ?>" placeholder="RRRR-MM-DD">
    </div>
    <div class="col-md-3 mb-3">
      <label class="form-label">Data do</label>
      <input name="data_do" data-flatpickr class="form-control" value="<?= h($row['data_do'] ?? '') ?>" placeholder="RRRR-MM-DD">
    </div>
  </div>
  <div class="mb-3">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="cykliczne" id="cykliczne-cb" value="1"
             <?= !empty($row['cykliczne']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="cykliczne-cb">Działanie cykliczne</label>
    </div>
  </div>
  <div id="czestotliwosc-row" class="mb-3" style="<?= empty($row['cykliczne']) ? 'display:none' : '' ?>">
    <label class="form-label">Częstotliwość</label>
    <select name="czestotliwosc" class="form-select" style="max-width:250px">
      <option value="">— wybierz —</option>
      <?php foreach (['tygodniowo','miesiecznie','kwartalnie'] as $c): ?>
      <option value="<?= $c ?>" <?= ($row['czestotliwosc'] ?? '') === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
</div>

<!-- 3. Miejsce -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-geo-alt"></i> Miejsce</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-4 mb-3">
      <label class="form-label">Lokalizacja</label>
      <input name="lokalizacja" class="form-control" value="<?= h($row['lokalizacja'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label">Forma</label>
      <select name="forma" id="forma-select" class="form-select">
        <?php foreach (['stacjonarne','online','hybrydowe'] as $f): ?>
        <option value="<?= $f ?>" <?= ($row['forma'] ?? '') === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 mb-3" id="link-online-row" style="<?= in_array($row['forma'] ?? '', ['online','hybrydowe']) ? '' : 'display:none' ?>">
      <label class="form-label">Link online</label>
      <input name="link_online" type="url" class="form-control" value="<?= h($row['link_online'] ?? '') ?>">
    </div>
  </div>
</div>
</div>

<!-- 3b. Korzyści i grupa docelowa -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-stars"></i> Korzyści i grupa docelowa</div>
<div class="card-body">
  <div class="row">
    <div class="col-md-6 mb-3">
      <div class="d-flex gap-2 align-items-center mb-1">
        <label class="form-label mb-0 fw-semibold">Korzyści</label>
        <select name="korzysci_tytul" class="form-select form-select-sm" style="width:auto">
          <option value="Korzyści"       <?= ($row['korzysci_tytul'] ?? 'Korzyści') === 'Korzyści'   ? 'selected' : '' ?>>Korzyści</option>
          <option value="Co zyskasz"     <?= ($row['korzysci_tytul'] ?? '') === 'Co zyskasz'         ? 'selected' : '' ?>>Co zyskasz</option>
        </select>
      </div>
      <textarea name="korzysci" class="form-control" rows="5"
                placeholder="Każda korzyść w nowej linii lub jako ciągły opis…"><?= h($row['korzysci'] ?? '') ?></textarea>
      <div class="form-text">Każda korzyść w osobnej linii = lista punktowana w podglądzie.</div>
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label fw-semibold">Dla kogo</label>
      <textarea name="dla_kogo" class="form-control" rows="5"
                placeholder="Każda grupa w nowej linii lub jako ciągły opis…"><?= h($row['dla_kogo'] ?? '') ?></textarea>
      <div class="form-text">Każda grupa docelowa w osobnej linii = lista punktowana w podglądzie.</div>
    </div>
  </div>
</div>
</div>

<!-- 4. Finansowanie -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-currency-euro"></i> Finansowanie</div>
<div class="card-body">
  <div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="wlasne_dzialanie" id="wlasne-cb" value="1"
           <?= !empty($row['wlasne_dzialanie']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="wlasne-cb">
      <i class="bi bi-star-fill text-warning"></i> Działanie własne (bez grantu)
    </label>
  </div>

  <div id="grant-section">
    <div id="grant-rows"></div>
    <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addGrantRow()">
      <i class="bi bi-plus-lg"></i> Dodaj źródło finansowania
    </button>
  </div>
</div>
</div>

<!-- 5. Wskaźniki -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-bar-chart"></i> Wskaźniki</div>
<div class="card-body">
  <div id="indicator-rows"></div>
  <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addIndicatorRow()">
    <i class="bi bi-plus-lg"></i> Dodaj wskaźnik
  </button>
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
new TomSelect('#koord-select', {allowEmptyOption: true});

document.getElementById('cykliczne-cb').addEventListener('change', function() {
    document.getElementById('czestotliwosc-row').style.display = this.checked ? '' : 'none';
});

document.getElementById('forma-select').addEventListener('change', function() {
    var show = this.value === 'online' || this.value === 'hybrydowe';
    document.getElementById('link-online-row').style.display = show ? '' : 'none';
});

document.getElementById('wlasne-cb').addEventListener('change', function() {
    document.getElementById('grant-section').style.display = this.checked ? 'none' : '';
});
if (document.getElementById('wlasne-cb').checked) {
    document.getElementById('grant-section').style.display = 'none';
}

var grantsData = <?= json_encode(array_map(fn($g) => ['id' => $g['id'], 'text' => $g['nazwa'] . ' (' . $g['donator'] . ')'], $grants)) ?>;

var grantRowIdx = 0;
function addGrantRow(grantId, grantText, pct, kwota) {
    grantRowIdx++;
    var idx = grantRowIdx;
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 grant-row';
    row.id = 'grant-row-' + idx;

    var selectId = 'grant-select-' + idx;
    var optionsHtml = '<option value="">— wybierz grant —</option>';
    grantsData.forEach(function(g) {
        var sel = (grantId && g.id == grantId) ? ' selected' : '';
        optionsHtml += '<option value="' + g.id + '"' + sel + '>' + g.text + '</option>';
    });

    row.innerHTML = '<div class="col-md-5"><select name="grant_id[]" id="' + selectId + '" class="form-select">' + optionsHtml + '</select></div>'
        + '<div class="col-md-2"><input type="number" name="udzial_procent[]" class="form-control" placeholder="%" min="0" max="100" step="0.1" value="' + (pct || '') + '"></div>'
        + '<div class="col-md-3"><input type="number" name="grant_kwota[]" class="form-control" placeholder="Kwota PLN" step="0.01" value="' + (kwota || '') + '"></div>'
        + '<div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.grant-row\').remove()"><i class="bi bi-trash"></i></button></div>';

    document.getElementById('grant-rows').appendChild(row);
    new TomSelect('#' + selectId, {allowEmptyOption: true});
}

var indIdx = 0;
function addIndicatorRow(nazwa, planowana, realizowana) {
    indIdx++;
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2';
    row.innerHTML = '<div class="col-md-5"><input type="text" name="ind_nazwa[]" class="form-control" placeholder="Nazwa wskaźnika" value="' + (nazwa || '') + '"></div>'
        + '<div class="col-md-2"><input type="number" name="ind_planowana[]" class="form-control" placeholder="Planowana" step="0.01" value="' + (planowana || '') + '"></div>'
        + '<div class="col-md-2"><input type="number" name="ind_realizowana[]" class="form-control" placeholder="Zrealizowana" step="0.01" value="' + (realizowana || '') + '"></div>'
        + '<div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.row\').remove()"><i class="bi bi-trash"></i></button></div>';
    document.getElementById('indicator-rows').appendChild(row);
}

// Pre-fill existing data
<?php foreach ($existing_grants as $eg): ?>
addGrantRow(<?= (int)$eg['grant_id'] ?>, '', <?= json_encode($eg['udzial_procent']) ?>, <?= json_encode($eg['kwota']) ?>);
<?php endforeach; ?>

<?php foreach ($existing_indicators as $ei): ?>
addIndicatorRow(<?= json_encode($ei['nazwa']) ?>, <?= json_encode($ei['wartosc_planowana']) ?>, <?= json_encode($ei['wartosc_realizowana']) ?>);
<?php endforeach; ?>
</script>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
