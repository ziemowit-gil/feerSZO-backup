<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin', 'editor');
require_module_enabled('contract_inne', 'Ten typ umowy');
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/inne/add.php');
$PAGE_TITLE = 'Nowa inna umowa';
$TYPE  = 'inne';
$TABLE = 'umowy_inne';
$errors = [];
$row = [
    'numer_umowy'       => next_contract_number($TYPE),
    'status'            => 'projekt',
    'waluta'            => 'PLN',
    'czas_nieokreslony' => 0,
    'dzialania_cykliczne' => 0,
];

$typy_umow = ['najem' => 'Najem', 'użyczenie' => 'Użyczenie', 'darowizna' => 'Darowizna', 'partnerstwo' => 'Partnerstwo', 'NDA' => 'NDA', 'licencja' => 'Licencja', 'inne' => 'Inne'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    if (empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        // Pola checkboxowe
        foreach (['czas_nieokreslony', 'dzialania_cykliczne'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Jeśli czas nieokreślony, wyczyść datę zakończenia
        if ($row['czas_nieokreslony']) {
            $row['data_zakonczenia'] = null;
        }

        // Upload plików
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zalaczniki = handle_upload('zalaczniki', $TYPE);
        if ($plik_umowy) $row['plik_umowy']          = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia']  = $plik_potw;
        if ($zalaczniki) $row['zalaczniki']           = $zalaczniki;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        // Puste pola liczbowe → null
        foreach (['wartosc_umowy'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }

        $allowed = [
            'numer_umowy', 'status', 'typ_umowy', 'strona_umowy', 'pesel_nip_krs', 'adres','email',
            'przedmiot_umowy', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'czas_nieokreslony', 'okres_wypowiedzenia', 'wartosc_umowy', 'waluta',
            'warunki_finansowe', 'numer_projektu', 'opiekun', 'dzialania_cykliczne',
            'dzialania_opis', 'data_przegladu', 'forma_podpisania', 'platforma_el',
            'id_dokumentu_el', 'plik_potwierdzenia', 'plik_umowy', 'zalaczniki',
            'uwagi', 'nr_roboczy', 'nr_system', 'nr_rejestru',
            'created_by', 'created_at', 'updated_at',
        ];
        $data = array_intersect_key($row, array_flip($allowed));

        assign_nr_rejestru($data);
        $id = db_insert($TABLE, $data);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'create', 'Dodano: ' . ($data['numer_umowy'] ?? ''));
        if (isset($_POST['nie_mam_drukarki'])) {
            require_once dirname(dirname(__DIR__)) . '/contracts/includes/pdf_queue.php';
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['strona_umowy'] ?? '', current_user()['id']);
        }
        try {
            require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
            crm_migrate();
            CrmManager::autoCreateContractCase($TYPE, $id, $data['numer_umowy'] ?? '', $data, (int)(current_user()['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[crm_case_auto] ' . $e->getMessage());
        }
        flash_set('success', 'Umowa została dodana.');
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}");
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-plus-circle text-primary"></i> Nowa inna umowa</h4>
  <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
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
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Dane podstawowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Numer umowy *</label>
    <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy'] ?? '') ?>" required>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Status *</label>
    <select name="status" class="form-select" required>
      <?php foreach (['projekt' => 'Projekt', 'podpisana' => 'Podpisana', 'w realizacji' => 'W realizacji', 'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'] as $k => $v):
        $sel = ($row['status'] ?? '') === $k ? 'selected' : ''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
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
    <input name="opiekun" class="form-control" value="<?= h($row['opiekun'] ?? '') ?>">
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">Numer projektu / źródło finansowania</label>
    <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu'] ?? '') ?>">
  </div>
</div>
</div>
</div>

<!-- STRONA UMOWY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-building"></i> Strona umowy</div>
<div class="card-body">
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Nazwa / strona umowy</label>
    <input name="strona_umowy" class="form-control" value="<?= h($row['strona_umowy'] ?? '') ?>">
    <?= byli_check_field('strona_umowy') ?>
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">PESEL / NIP / KRS</label>
    <input name="pesel_nip_krs" class="form-control" value="<?= h($row['pesel_nip_krs'] ?? '') ?>">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Adres</label>
  <input name="adres" class="form-control" value="<?= h($row['adres'] ?? '') ?>">
</div>
  <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontrahenta</label>
    <input type="email" name="email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email']??'')?>"></div>
</div>
</div>

<!-- PRZEDMIOT I DATY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-calendar3"></i> Przedmiot i daty</div>
<div class="card-body">
<div class="mb-3">
  <label class="form-label">Przedmiot umowy</label>
  <textarea name="przedmiot_umowy" class="form-control" rows="3"><?= h($row['przedmiot_umowy'] ?? '') ?></textarea>
</div>
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Data rozpoczęcia</label>
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check mt-2">
      <input class="form-check-input" type="checkbox" name="czas_nieokreslony" id="czas_nieokreslony" value="1"
        <?= !empty($row['czas_nieokreslony']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="czas_nieokreslony">Czas nieokreślony</label>
    </div>
  </div>
</div>
<div class="row" id="data_zakonczenia_row" style="display:<?= !empty($row['czas_nieokreslony']) ? 'none' : '' ?>">
  <div class="col-md-4 mb-3">
    <label class="form-label">Data zakończenia</label>
    <input name="data_zakonczenia" id="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia'] ?? '') ?>">
  </div>
  <div class="col-md-8 mb-3">
    <label class="form-label">Okres wypowiedzenia</label>
    <input name="okres_wypowiedzenia" class="form-control" placeholder="np. 30 dni" value="<?= h($row['okres_wypowiedzenia'] ?? '') ?>">
  </div>
</div>
</div>
</div>

<!-- FINANSOWE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-currency-exchange"></i> Warunki finansowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-5 mb-3">
    <label class="form-label">Wartość umowy</label>
    <input name="wartosc_umowy" type="number" step="0.01" class="form-control" value="<?= h($row['wartosc_umowy'] ?? '') ?>">
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Waluta</label>
    <select name="waluta" class="form-select">
      <?php foreach (['PLN', 'EUR', 'USD', 'GBP', 'CHF'] as $cur):
        $sel = ($row['waluta'] ?? 'PLN') === $cur ? 'selected' : ''; ?>
      <option value="<?= $cur ?>" <?= $sel ?>><?= $cur ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Warunki finansowe / opis płatności</label>
  <textarea name="warunki_finansowe" class="form-control" rows="3"><?= h($row['warunki_finansowe'] ?? '') ?></textarea>
</div>
</div>
</div>

<!-- DZIAŁANIA CYKLICZNE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-arrow-repeat"></i> Działania cykliczne</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check mt-2">
      <input class="form-check-input" type="checkbox" name="dzialania_cykliczne" id="dzialania_cykliczne" value="1"
        <?= !empty($row['dzialania_cykliczne']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="dzialania_cykliczne">Umowa zawiera działania cykliczne</label>
    </div>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Data przeglądu / odnowienia</label>
    <input name="data_przegladu" type="date" class="form-control" value="<?= h($row['data_przegladu'] ?? '') ?>">
  </div>
</div>
<div class="mb-3" id="dzialania_opis_row" style="display:<?= !empty($row['dzialania_cykliczne']) ? '' : 'none' ?>">
  <label class="form-label">Opis działań cyklicznych</label>
  <textarea name="dzialania_opis" class="form-control" rows="3"><?= h($row['dzialania_opis'] ?? '') ?></textarea>
</div>
</div>
</div>

<!-- PODPISANIE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-pen"></i> Forma podpisania</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Forma podpisania</label>
    <select name="forma_podpisania" class="form-select" id="forma_podpisania">
      <option value="">—</option>
      <option value="papierowa" <?= ($row['forma_podpisania'] ?? '') === 'papierowa' ? 'selected' : '' ?>>Papierowa</option>
      <option value="elektroniczna" <?= ($row['forma_podpisania'] ?? '') === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= (($row['forma_podpisania'] ?? '') === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
    </select>
  </div>
</div>
<div id="el_fields" class="row" style="display:<?= ($row['forma_podpisania'] ?? '') === 'elektroniczna' ? '' : 'none' ?>">
  <div class="col-md-4 mb-3">
    <label class="form-label">Platforma</label>
    <input name="platforma_el" class="form-control" placeholder="Autenti / inny" value="<?= h($row['platforma_el'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">ID dokumentu w systemie</label>
    <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Plik potwierdzenia (PDF)</label>
    <input name="plik_potwierdzenia" type="file" class="form-control" accept=".pdf">
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
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi'] ?? '') ?></textarea>
</div>

</div><!-- /col-lg-8 -->

<!-- SIDEBAR -->
<div class="col-lg-4">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Pliki</div>
<div class="card-body">
  <div class="mb-3">
    <label class="form-label">Plik umowy (PDF/DOCX, max 20MB)</label>
    <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
  </div>
  <div class="mb-3">
    <label class="form-label">Załączniki</label>
    <input name="zalaczniki" type="file" class="form-control" accept=".pdf,.docx,.jpg,.png">
  </div>
</div>
</div>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-lock"></i> Dostęp</div>
<div class="card-body">
  <?= contract_access_field_html([]) ?>
</div>
</div>
</div>

</div><!-- /row -->

<div class="form-check mb-3">
  <input class="form-check-input" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
  <label class="form-check-label text-muted" for="nie_mam_drukarki">
    <i class="bi bi-printer"></i> Nie mam drukarki — zapisz umowę jako PDF do późniejszego wydruku
  </label>
</div>
<div class="d-flex gap-2 mt-2 mb-4">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Zapisz umowę</button>
  <a href="list.php" class="btn btn-outline-secondary">Anuluj</a>
</div>
</form>

<script>
// Czas nieokreślony — ukryj/pokaż datę zakończenia
document.getElementById('czas_nieokreslony').addEventListener('change', function () {
    document.getElementById('data_zakonczenia_row').style.display = this.checked ? 'none' : '';
    if (this.checked) document.getElementById('data_zakonczenia').value = '';
});
// Działania cykliczne — opis
document.getElementById('dzialania_cykliczne').addEventListener('change', function () {
    document.getElementById('dzialania_opis_row').style.display = this.checked ? '' : 'none';
});
// Forma podpisania — pola elektroniczne
document.getElementById('forma_podpisania').addEventListener('change', function () {
    var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
