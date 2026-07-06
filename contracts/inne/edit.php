<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin', 'editor');
$TYPE  = 'inne';
$TABLE = 'umowy_inne';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row)) {
    flash_set('error', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . '/contracts/' . $TYPE . '/list.php'); exit;
}
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/inne/edit.php?id=' . $id);

$typy_umow = ['najem' => 'Najem', 'użyczenie' => 'Użyczenie', 'darowizna' => 'Darowizna', 'partnerstwo' => 'Partnerstwo', 'NDA' => 'NDA', 'licencja' => 'Licencja', 'inne' => 'Inne'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty($data['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($data['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        foreach (['czas_nieokreslony', 'dzialania_cykliczne'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        if ($data['czas_nieokreslony']) {
            $data['data_zakonczenia'] = null;
        }

        foreach (['wartosc_umowy'] as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }

        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zalaczniki = handle_upload('zalaczniki', $TYPE);

        $data['plik_umowy']         = $plik_umowy ?: $row['plik_umowy'];
        $data['plik_potwierdzenia'] = $plik_potw  ?: $row['plik_potwierdzenia'];
        $data['zalaczniki']         = $zalaczniki  ?: $row['zalaczniki'];

        $allowed = [
            'numer_umowy', 'status', 'typ_umowy', 'strona_umowy', 'pesel_nip_krs', 'adres','email',
            'przedmiot_umowy', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'czas_nieokreslony', 'okres_wypowiedzenia', 'wartosc_umowy', 'waluta',
            'warunki_finansowe', 'numer_projektu', 'opiekun', 'dzialania_cykliczne',
            'dzialania_opis', 'data_przegladu', 'forma_podpisania', 'platforma_el',
            'id_dokumentu_el', 'plik_potwierdzenia', 'plik_umowy', 'zalaczniki', 'uwagi',
            'nr_roboczy', 'nr_system', 'nr_rejestru',
        ];
        $save = array_intersect_key($data, array_flip($allowed));
        require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
        $diff = format_field_diff($row, $save);
        db_update($TABLE, $save, $id);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'edit', $diff ?: 'Edytowano umowę');
        flash_set('success', 'Zmiany zapisane.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    $row = array_merge($row, $_POST);
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-pencil text-primary"></i> Edycja: <?= h($row['numer_umowy']) ?></h4>
  <div class="d-flex gap-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Podgląd</a>
    <a href="list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Numery referencyjne -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-hash"></i> Numery referencyjne</div>
<div class="card-body"><div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr roboczy umowy</label>
    <input name="nr_roboczy" class="form-control" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="np. PR-2026-001">
    <div class="form-text">Numer roboczy w projekcie.</div>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr ogólny <span class="text-muted small">(webNGO, opcjonalne)</span></label>
    <input name="nr_system" class="form-control" value="<?= h($row['nr_system']??'') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr rejestru <span class="text-muted small">RU/{nr}/{rok}/{inicjały}</span></label>
    <input name="nr_rejestru" class="form-control font-monospace"
      value="<?= h($row['nr_rejestru']??'') ?>"
      placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
    <div class="form-text">Zostaw puste — zostanie nadany automatycznie.</div>
  </div>
</div></div>
</div>

<div class="row">
<div class="col-lg-8">

<!-- DANE PODSTAWOWE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Dane podstawowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Numer umowy *</label>
    <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy']) ?>" required>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Status *</label>
    <select name="status" class="form-select" required>
      <?php foreach (['projekt', 'podpisana', 'w realizacji', 'zakończona', 'rozwiązana', 'anulowana'] as $s):
        $sel = $row['status'] === $s ? 'selected' : ''; ?>
      <option value="<?= h($s) ?>" <?= $sel ?>><?= h(ucfirst($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Typ umowy</label>
    <select name="typ_umowy" class="form-select">
      <option value="">— wybierz —</option>
      <?php foreach ($typy_umow as $k => $v):
        $sel = ($row['typ_umowy'] ?? '') === $k ? 'selected' : ''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Opiekun umowy</label>
    <input name="opiekun" class="form-control" value="<?= h($row['opiekun']) ?>">
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">Numer projektu</label>
    <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']) ?>">
  </div>
</div>
</div>
</div>

<!-- STRONA UMOWY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Strona umowy</div>
<div class="card-body">
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Nazwa / strona umowy</label>
    <input name="strona_umowy" class="form-control" value="<?= h($row['strona_umowy']) ?>">
    <?= byli_check_field('strona_umowy') ?>
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">PESEL / NIP / KRS</label>
    <input name="pesel_nip_krs" class="form-control" value="<?= h($row['pesel_nip_krs']) ?>">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Adres</label>
  <input name="adres" class="form-control" value="<?= h($row['adres']) ?>">
</div>
  <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontrahenta</label>
    <input type="email" name="email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email'])?>"></div>
</div>
</div>

<!-- PRZEDMIOT I DATY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Przedmiot i daty</div>
<div class="card-body">
<div class="mb-3">
  <label class="form-label">Przedmiot umowy</label>
  <textarea name="przedmiot_umowy" class="form-control" rows="3"><?= h($row['przedmiot_umowy']) ?></textarea>
</div>
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']) ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Data rozpoczęcia</label>
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']) ?>">
  </div>
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check mt-2">
      <input class="form-check-input" type="checkbox" name="czas_nieokreslony" id="czas_nieokreslony" value="1"
        <?= $row['czas_nieokreslony'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="czas_nieokreslony">Czas nieokreślony</label>
    </div>
  </div>
</div>
<div class="row" id="data_zakonczenia_row" style="display:<?= $row['czas_nieokreslony'] ? 'none' : '' ?>">
  <div class="col-md-4 mb-3">
    <label class="form-label">Data zakończenia</label>
    <input name="data_zakonczenia" id="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia']) ?>">
  </div>
  <div class="col-md-8 mb-3">
    <label class="form-label">Okres wypowiedzenia</label>
    <input name="okres_wypowiedzenia" class="form-control" placeholder="np. 30 dni" value="<?= h($row['okres_wypowiedzenia']) ?>">
  </div>
</div>
</div>
</div>

<!-- FINANSOWE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Warunki finansowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-5 mb-3">
    <label class="form-label">Wartość umowy</label>
    <input name="wartosc_umowy" type="number" step="0.01" class="form-control" value="<?= h($row['wartosc_umowy']) ?>">
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Waluta</label>
    <select name="waluta" class="form-select">
      <?php foreach (['PLN', 'EUR', 'USD', 'GBP', 'CHF'] as $cur):
        $sel = ($row['waluta'] ?: 'PLN') === $cur ? 'selected' : ''; ?>
      <option value="<?= $cur ?>" <?= $sel ?>><?= $cur ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Warunki finansowe / opis płatności</label>
  <textarea name="warunki_finansowe" class="form-control" rows="3"><?= h($row['warunki_finansowe']) ?></textarea>
</div>
</div>
</div>

<!-- DZIAŁANIA CYKLICZNE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Działania cykliczne</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check mt-2">
      <input class="form-check-input" type="checkbox" name="dzialania_cykliczne" id="dzialania_cykliczne" value="1"
        <?= $row['dzialania_cykliczne'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="dzialania_cykliczne">Działania cykliczne</label>
    </div>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Data przeglądu / odnowienia</label>
    <input name="data_przegladu" type="date" class="form-control" value="<?= h($row['data_przegladu']) ?>">
  </div>
</div>
<div class="mb-3" id="dzialania_opis_row" style="display:<?= $row['dzialania_cykliczne'] ? '' : 'none' ?>">
  <label class="form-label">Opis działań cyklicznych</label>
  <textarea name="dzialania_opis" class="form-control" rows="3"><?= h($row['dzialania_opis']) ?></textarea>
</div>
</div>
</div>

<!-- PODPISANIE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Forma podpisania</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Forma podpisania</label>
    <select name="forma_podpisania" class="form-select" id="forma_podpisania">
      <option value="">—</option>
      <option value="papierowa" <?= $row['forma_podpisania'] === 'papierowa' ? 'selected' : '' ?>>Papierowa</option>
      <option value="elektroniczna" <?= $row['forma_podpisania'] === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
    </select>
  </div>
</div>
<div id="el_fields" class="row" style="display:<?= $row['forma_podpisania'] === 'elektroniczna' ? '' : 'none' ?>">
  <div class="col-md-4 mb-3">
    <label class="form-label">Platforma</label>
    <input name="platforma_el" class="form-control" value="<?= h($row['platforma_el']) ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">ID dokumentu</label>
    <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el']) ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nowy plik potwierdzenia</label>
    <input name="plik_potwierdzenia" type="file" class="form-control" accept=".pdf">
    <?php if ($row['plik_potwierdzenia']): ?>
    <div class="mt-1"><?= upload_link($row['plik_potwierdzenia']) ?></div>
    <?php endif; ?>
  </div>
</div>
<div id="epodpis_fields" class="row" style="display:none">
  <div class="col-md-4 mb-3"><label class="form-label">Dostawca podpisu (TSP)</label>
    <input name="epodpis_dostawca" class="form-control" placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca'] ?? '') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Numer seryjny certyfikatu</label>
    <input name="epodpis_nr_certyfikatu" class="form-control font-monospace" value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Ważność certyfikatu</label>
    <input name="epodpis_data_waznosci" type="date" class="form-control" value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>"></div>
</div>
</div>
</div>

<div class="mb-3">
  <label class="form-label">Uwagi</label>
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi']) ?></textarea>
</div>

</div><!-- /col-lg-8 -->

<!-- SIDEBAR -->
<div class="col-lg-4">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Pliki</div>
<div class="card-body">
  <?php if ($row['plik_umowy']): ?>
  <div class="mb-2"><?= upload_link($row['plik_umowy']) ?></div>
  <label class="form-label small text-muted">Zastąp nowym plikiem:</label>
  <?php else: ?>
  <label class="form-label">Plik umowy (PDF/DOCX)</label>
  <?php endif; ?>
  <input name="plik_umowy" type="file" class="form-control mb-3" accept=".pdf,.docx">

  <label class="form-label">Załączniki</label>
  <?php if ($row['zalaczniki']): ?>
  <div class="mb-1"><?= upload_link($row['zalaczniki']) ?></div>
  <?php endif; ?>
  <input name="zalaczniki" type="file" class="form-control" accept=".pdf,.docx,.jpg,.png">
</div>
</div>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-lock"></i> Dostęp</div>
<div class="card-body">
  <?= contract_access_field_html(contract_access_user_ids($TYPE, $id)) ?>
</div>
</div>
</div>

</div><!-- /row -->

<div class="d-flex gap-2 mb-4">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Zapisz zmiany</button>
  <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</form>

<script>
document.getElementById('czas_nieokreslony').addEventListener('change', function () {
    document.getElementById('data_zakonczenia_row').style.display = this.checked ? 'none' : '';
    if (this.checked) document.getElementById('data_zakonczenia').value = '';
});
document.getElementById('dzialania_cykliczne').addEventListener('change', function () {
    document.getElementById('dzialania_opis_row').style.display = this.checked ? '' : 'none';
});
document.getElementById('forma_podpisania').addEventListener('change', function () {
    var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
