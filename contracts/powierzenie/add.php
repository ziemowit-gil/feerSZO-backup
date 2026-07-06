<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/powierzenie_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin', 'editor');
require_module_enabled('contract_powierzenie', 'Ten typ umowy');
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/powierzenie/add.php');
$PAGE_TITLE = 'Nowa umowa powierzenia zadania publicznego';
$TYPE  = 'powierzenie';
$TABLE = 'umowy_powierzenie';
$errors = [];
$row = [
    'numer_umowy'    => next_contract_number($TYPE),
    'status'         => 'projekt',
    'waluta'         => 'PLN',
    'forma_zlecenia' => 'powierzenie',
];

$formy_zlecenia = ['powierzenie' => 'Powierzenie (100% dotacji)', 'wsparcie' => 'Wsparcie (z wkładem własnym)'];
$tryby = ['konkurs' => 'Otwarty konkurs ofert', 'art19a' => 'Tryb pozakonkursowy (art. 19a — mały grant)', 'inny' => 'Inny tryb'];
$statuses = ['projekt' => 'Projekt', 'podpisana' => 'Podpisana', 'w realizacji' => 'W realizacji',
             'do rozliczenia' => 'Do rozliczenia', 'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    if (empty($row['numer_umowy']))   $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))        $errors[] = 'Status jest wymagany.';
    if (empty($row['nazwa_zadania'])) $errors[] = 'Nazwa zadania publicznego jest wymagana.';

    if (!$errors) {
        // Upload plików
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zalaczniki = handle_upload('zalaczniki', $TYPE);
        if ($plik_umowy) $row['plik_umowy']         = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia'] = $plik_potw;
        if ($zalaczniki) $row['zalaczniki']         = $zalaczniki;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        // Puste pola liczbowe → null
        foreach (['kwota_dotacji', 'wklad_wlasny', 'wklad_osobowy', 'calkowity_koszt'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }

        $allowed = [
            'numer_umowy', 'status', 'nazwa_zadania', 'sfera_zadania', 'forma_zlecenia',
            'tryb_zlecenia', 'nazwa_konkursu', 'zakres_rzeczowy', 'rezultaty',
            'organ_zlecajacy', 'organ_reprezentacja', 'organ_adres', 'email',
            'kwota_dotacji', 'wklad_wlasny', 'wklad_osobowy', 'calkowity_koszt', 'waluta',
            'rachunek_dotacji', 'transze', 'koszty_kwalifikowane',
            'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'termin_wykorzystania', 'termin_sprawozdania',
            'numer_projektu', 'opiekun', 'forma_podpisania', 'platforma_el',
            'id_dokumentu_el', 'epodpis_dostawca', 'epodpis_nr_certyfikatu', 'epodpis_data_waznosci',
            'plik_potwierdzenia', 'plik_umowy', 'zalaczniki', 'uwagi',
            'nr_roboczy', 'nr_system', 'nr_rejestru',
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
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['organ_zlecajacy'] ?? '', current_user()['id']);
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
  <h4 class="mb-0"><i class="bi bi-bank text-primary"></i> Nowa umowa powierzenia zadania publicznego</h4>
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
      <?php foreach ($statuses as $k => $v):
        $sel = ($row['status'] ?? '') === $k ? 'selected' : ''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Forma zlecenia</label>
    <select name="forma_zlecenia" class="form-select" id="forma_zlecenia">
      <?php foreach ($formy_zlecenia as $k => $v):
        $sel = ($row['forma_zlecenia'] ?? 'powierzenie') === $k ? 'selected' : ''; ?>
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

<!-- ZADANIE PUBLICZNE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-clipboard-check"></i> Zadanie publiczne</div>
<div class="card-body">
<div class="mb-3">
  <label class="form-label">Nazwa zadania publicznego *</label>
  <input name="nazwa_zadania" class="form-control" value="<?= h($row['nazwa_zadania'] ?? '') ?>" required>
</div>
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Sfera pożytku publicznego</label>
    <input name="sfera_zadania" class="form-control" list="sfery_list" value="<?= h($row['sfera_zadania'] ?? '') ?>"
      placeholder="np. ochrona i promocja zdrowia">
    <datalist id="sfery_list">
      <option value="Pomoc społeczna"></option>
      <option value="Działalność na rzecz osób niepełnosprawnych"></option>
      <option value="Ochrona i promocja zdrowia"></option>
      <option value="Nauka, edukacja, oświata i wychowanie"></option>
      <option value="Kultura, sztuka, ochrona dóbr kultury"></option>
      <option value="Wspieranie i upowszechnianie kultury fizycznej"></option>
      <option value="Ekologia i ochrona zwierząt"></option>
      <option value="Działalność na rzecz dzieci i młodzieży"></option>
      <option value="Przeciwdziałanie uzależnieniom i patologiom społecznym"></option>
    </datalist>
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Tryb zlecenia</label>
    <select name="tryb_zlecenia" class="form-select">
      <option value="">— wybierz —</option>
      <?php foreach ($tryby as $k => $v):
        $sel = ($row['tryb_zlecenia'] ?? '') === $k ? 'selected' : ''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3 mb-3">
    <label class="form-label">Nr / nazwa konkursu</label>
    <input name="nazwa_konkursu" class="form-control" value="<?= h($row['nazwa_konkursu'] ?? '') ?>">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Zakres rzeczowy zadania</label>
  <textarea name="zakres_rzeczowy" class="form-control" rows="3"><?= h($row['zakres_rzeczowy'] ?? '') ?></textarea>
</div>
<div class="mb-3">
  <label class="form-label">Zakładane rezultaty</label>
  <textarea name="rezultaty" class="form-control" rows="2"><?= h($row['rezultaty'] ?? '') ?></textarea>
</div>
</div>
</div>

<!-- ORGAN ZLECAJĄCY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-building"></i> Zleceniodawca (organ administracji)</div>
<div class="card-body">
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Organ zlecający</label>
    <input name="organ_zlecajacy" class="form-control" value="<?= h($row['organ_zlecajacy'] ?? '') ?>"
      placeholder="np. Gmina Miasta X / Urząd Marszałkowski…">
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">Reprezentowany przez</label>
    <input name="organ_reprezentacja" class="form-control" value="<?= h($row['organ_reprezentacja'] ?? '') ?>"
      placeholder="imię, nazwisko, stanowisko">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Adres organu</label>
  <input name="organ_adres" class="form-control" value="<?= h($row['organ_adres'] ?? '') ?>">
</div>
<div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontaktowy</label>
  <input type="email" name="email" class="form-control" placeholder="np. kontakt@urzad.gov.pl" value="<?= h($row['email']??'')?>"></div>
</div>
</div>

<!-- FINANSOWANIE / DOTACJA -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-cash-coin"></i> Finansowanie / dotacja</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Kwota dotacji</label>
    <input name="kwota_dotacji" type="number" step="0.01" class="form-control" value="<?= h($row['kwota_dotacji'] ?? '') ?>">
  </div>
  <div class="col-md-2 mb-3">
    <label class="form-label">Waluta</label>
    <select name="waluta" class="form-select">
      <?php foreach (['PLN', 'EUR', 'USD'] as $cur):
        $sel = ($row['waluta'] ?? 'PLN') === $cur ? 'selected' : ''; ?>
      <option value="<?= $cur ?>" <?= $sel ?>><?= $cur ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">Wyodrębniony rachunek bankowy dotacji</label>
    <input name="rachunek_dotacji" class="form-control font-monospace" value="<?= h($row['rachunek_dotacji'] ?? '') ?>">
  </div>
</div>
<div class="row" id="wsparcie_fields" style="display:<?= ($row['forma_zlecenia'] ?? '') === 'wsparcie' ? '' : 'none' ?>">
  <div class="col-md-4 mb-3">
    <label class="form-label">Wkład własny finansowy</label>
    <input name="wklad_wlasny" type="number" step="0.01" class="form-control" value="<?= h($row['wklad_wlasny'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Wkład osobowy / rzeczowy</label>
    <input name="wklad_osobowy" type="number" step="0.01" class="form-control" value="<?= h($row['wklad_osobowy'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Całkowity koszt zadania</label>
    <input name="calkowity_koszt" type="number" step="0.01" class="form-control" value="<?= h($row['calkowity_koszt'] ?? '') ?>">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Harmonogram / transze płatności</label>
  <textarea name="transze" class="form-control" rows="2" placeholder="np. I transza 60% po podpisaniu, II transza 40% po rozliczeniu"><?= h($row['transze'] ?? '') ?></textarea>
</div>
<div class="mb-3">
  <label class="form-label">Koszty kwalifikowane / kosztorys</label>
  <textarea name="koszty_kwalifikowane" class="form-control" rows="3"><?= h($row['koszty_kwalifikowane'] ?? '') ?></textarea>
</div>
</div>
</div>

<!-- TERMINY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-calendar3"></i> Terminy</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Realizacja od</label>
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia'] ?? '') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Realizacja do</label>
    <input name="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia'] ?? '') ?>">
  </div>
</div>
<div class="row">
  <div class="col-md-6 mb-3">
    <label class="form-label">Termin wykorzystania dotacji</label>
    <input name="termin_wykorzystania" type="date" class="form-control" value="<?= h($row['termin_wykorzystania'] ?? '') ?>">
  </div>
  <div class="col-md-6 mb-3">
    <label class="form-label">Termin złożenia sprawozdania końcowego</label>
    <input name="termin_sprawozdania" type="date" class="form-control" value="<?= h($row['termin_sprawozdania'] ?? '') ?>">
  </div>
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
<div id="epodpis_fields" class="row" style="display:<?= ($row['forma_podpisania'] ?? '') === 'epodpis_kwalifikowany' ? '' : 'none' ?>">
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
// Wkład własny — tylko dla formy „wsparcie"
document.getElementById('forma_zlecenia').addEventListener('change', function () {
    document.getElementById('wsparcie_fields').style.display = this.value === 'wsparcie' ? '' : 'none';
});
// Forma podpisania — pola elektroniczne
document.getElementById('forma_podpisania').addEventListener('change', function () {
    var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
