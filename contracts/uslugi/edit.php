<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin','editor');
$TYPE  = 'uslugi';
$TABLE = 'umowy_uslugi';
$id = intval($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row)) {
    flash_set('error', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . '/contracts/' . $TYPE . '/list.php'); exit;
}
if (contract_is_locked($row)) {
    flash_set('warning', 'Umowa jest zablokowana (zawarty aneks) — edycja niedostępna.');
    header('Location: view.php?id=' . $id); exit;
}
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/uslugi/edit.php?id=' . $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty($data['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($data['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        // Pola checkboxowe
        foreach (['czas_nieokreslony','wymagana_faktura','wymagany_protokol'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Puste pola liczbowe
        foreach (['wartosc_netto','wartosc_brutto','stawka_vat','termin_platnosci_dni','kwota_rozliczona'] as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }
        // Upload – zachowaj stary plik jeśli nie przesłano nowego
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $data['plik_umowy']          = $plik_umowy  ?: $row['plik_umowy'];
        $data['plik_potwierdzenia']  = $plik_potw   ?: $row['plik_potwierdzenia'];

        $new_zal = handle_upload('zalaczniki', $TYPE);
        $data['zalaczniki'] = $new_zal ?: $row['zalaczniki'];

        $allowed = ['numer_umowy','status','nazwa_wykonawcy','nip_pesel','adres','email','telefon','rachunek_lub_faktura',
            'przedmiot_uslugi','zakres_uslug','data_zawarcia','data_rozpoczecia','data_zakonczenia',
            'czas_nieokreslony','okres_wypowiedzenia','wartosc_netto','wartosc_brutto','stawka_vat',
            'waluta','harmonogram_platnosci','termin_platnosci_dni','numer_projektu','wymagana_faktura',
            'opiekun','wymagany_protokol','data_odbioru','status_rozliczenia','kwota_rozliczona','data_rozliczenia',
            'forma_podpisania','platforma_el',
            'id_dokumentu_el','plik_potwierdzenia','plik_umowy','zalaczniki','uwagi',
            'nr_roboczy','nr_system','nr_rejestru'];
        $save = array_intersect_key($data, array_flip($allowed));
        $save['updated_at'] = date('Y-m-d H:i:s');
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
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div>
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
  <div class="col-md-4 mb-3"><label class="form-label">Numer umowy *</label>
    <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy']) ?>" required></div>
  <div class="col-md-4 mb-3"><label class="form-label">Status *</label>
    <select name="status" class="form-select" required>
      <?php foreach(['projekt','do podpisu','podpisana','w realizacji','zawieszona','do rozliczenia','zakończona','rozwiązana','anulowana'] as $s):
        $sel = $row['status']===$s?'selected':''; ?>
      <option value="<?= h($s) ?>" <?= $sel ?>><?= h(ucfirst($s)) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-4 mb-3"><label class="form-label">Opiekun umowy</label>
    <input name="opiekun" class="form-control" value="<?= h($row['opiekun']) ?>"></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data rozpoczęcia</label>
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data zakończenia</label>
    <input name="data_zakonczenia" type="date" class="form-control" id="data_zakonczenia" value="<?= h($row['data_zakonczenia']) ?>"
      <?= $row['czas_nieokreslony'] ? 'disabled' : '' ?>>
  </div>
</div>
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="czas_nieokreslony" id="czas_nieokreslony" value="1"
        <?= $row['czas_nieokreslony']?'checked':'' ?>>
      <label class="form-check-label" for="czas_nieokreslony">Czas nieokreślony</label>
    </div>
  </div>
  <div class="col-md-4 mb-3"><label class="form-label">Okres wypowiedzenia</label>
    <input name="okres_wypowiedzenia" class="form-control" value="<?= h($row['okres_wypowiedzenia']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Numer projektu</label>
    <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']) ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Przedmiot usługi</label>
  <textarea name="przedmiot_uslugi" class="form-control" rows="2"><?= h($row['przedmiot_uslugi']) ?></textarea></div>
<div class="mb-3"><label class="form-label">Zakres usług</label>
  <textarea name="zakres_uslug" class="form-control" rows="3"><?= h($row['zakres_uslug']) ?></textarea></div>
</div>
</div>

<!-- DANE WYKONAWCY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-building"></i> Wykonawca</div>
<div class="card-body">

<!-- Wyszukiwarki -->
<div class="row g-2 mb-3 pb-3 border-bottom">
  <div class="col-md-6">
    <label class="form-label small text-muted mb-1">JDG — szukaj po NIP w CEIDG</label>
    <div class="input-group input-group-sm">
      <input type="text" id="ceidgNipInput" class="form-control" placeholder="NIP (10 cyfr)" maxlength="13">
      <button type="button" class="btn btn-outline-primary" id="ceidgBtn" onclick="ceidgSearch()">
        <i class="bi bi-search"></i> CEIDG
      </button>
    </div>
    <div id="ceidgResult"></div>
  </div>
  <div class="col-md-6">
    <label class="form-label small text-muted mb-1">Spółka / org. — szukaj po nr KRS</label>
    <div class="input-group input-group-sm">
      <input type="text" id="krsInput" class="form-control" placeholder="Nr KRS (10 cyfr)" maxlength="10">
      <button type="button" class="btn btn-outline-secondary" id="krsBtn" onclick="krsSearch()">
        <i class="bi bi-search"></i> KRS
      </button>
    </div>
    <div id="krsResult"></div>
  </div>
</div>

<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Nazwa wykonawcy</label>
    <input name="nazwa_wykonawcy" class="form-control" value="<?= h($row['nazwa_wykonawcy']) ?>"><?= byli_check_field('nazwa_wykonawcy') ?></div>
  <div class="col-md-6 mb-3"><label class="form-label">NIP / PESEL</label>
    <input name="nip_pesel" class="form-control" value="<?= h($row['nip_pesel']) ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Adres</label>
  <input name="adres" class="form-control" value="<?= h($row['adres']) ?>"></div>
<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontrahenta</label>
    <input type="email" name="email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email'])?>"></div>
  <div class="col-md-6 mb-3"><label class="form-label">Telefon kontaktowy</label>
    <input name="telefon" class="form-control" placeholder="np. +48 600 100 200" value="<?= h($row['telefon'] ?? '')?>"></div>
</div>
<div class="mb-3"><label class="form-label">Rachunek bankowy / dane do faktury</label>
  <input name="rachunek_lub_faktura" class="form-control" value="<?= h($row['rachunek_lub_faktura']) ?>"></div>
</div>
</div>

<!-- FINANSOWE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-currency-exchange"></i> Warunki finansowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-3 mb-3"><label class="form-label">Wartość netto (PLN)</label>
    <input name="wartosc_netto" type="number" step="0.01" class="form-control" value="<?= h($row['wartosc_netto']) ?>"></div>
  <div class="col-md-2 mb-3"><label class="form-label">VAT (%)</label>
    <input name="stawka_vat" type="number" step="0.01" min="0" max="100" class="form-control" value="<?= h($row['stawka_vat']) ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Wartość brutto (PLN)</label>
    <input name="wartosc_brutto" type="number" step="0.01" class="form-control" value="<?= h($row['wartosc_brutto']) ?>"></div>
  <div class="col-md-2 mb-3"><label class="form-label">Waluta</label>
    <input name="waluta" class="form-control" maxlength="3" value="<?= h($row['waluta'] ?: 'PLN') ?>"></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Harmonogram płatności</label>
    <select name="harmonogram_platnosci" class="form-select">
      <option value="">—</option>
      <option value="jednorazowo" <?= $row['harmonogram_platnosci']==='jednorazowo'?'selected':'' ?>>Jednorazowo</option>
      <option value="miesięcznie" <?= $row['harmonogram_platnosci']==='miesięcznie'?'selected':'' ?>>Miesięcznie</option>
      <option value="transze" <?= $row['harmonogram_platnosci']==='transze'?'selected':'' ?>>Transze</option>
    </select></div>
  <div class="col-md-4 mb-3"><label class="form-label">Termin płatności (dni)</label>
    <input name="termin_platnosci_dni" type="number" min="0" class="form-control" value="<?= h($row['termin_platnosci_dni']) ?>"></div>
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="wymagana_faktura" id="wymagana_faktura" value="1" <?= $row['wymagana_faktura']?'checked':'' ?>>
      <label class="form-check-label" for="wymagana_faktura">Wymagana faktura VAT</label>
    </div>
  </div>
</div>
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="wymagany_protokol" id="wymagany_protokol" value="1" <?= $row['wymagany_protokol']?'checked':'' ?>>
      <label class="form-check-label" for="wymagany_protokol">Wymagany protokół odbioru</label>
    </div>
  </div>
  <div class="col-md-4 mb-3"><label class="form-label">Data odbioru</label>
    <input name="data_odbioru" type="date" class="form-control" value="<?= h($row['data_odbioru']) ?>"></div>
</div>
<hr class="my-2">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Stan rozliczenia</label>
    <select name="status_rozliczenia" class="form-select">
      <?php foreach(['nierozliczone'=>'Nierozliczone','częściowo'=>'Częściowo rozliczone','rozliczone'=>'Rozliczone'] as $k=>$v):
        $sel = ($row['status_rozliczenia'] ?? 'nierozliczone')===$k?'selected':''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-4 mb-3"><label class="form-label">Kwota rozliczona</label>
    <input name="kwota_rozliczona" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['kwota_rozliczona'] ?? '') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data rozliczenia</label>
    <input name="data_rozliczenia" type="date" class="form-control" value="<?= h($row['data_rozliczenia'] ?? '') ?>"></div>
</div>
</div>
</div>

<!-- PODPISANIE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-pen"></i> Podpisanie</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Forma podpisania</label>
    <select name="forma_podpisania" class="form-select" id="forma_podpisania">
      <option value="">—</option>
      <option value="papierowa" <?= $row['forma_podpisania']==='papierowa'?'selected':'' ?>>Papierowa</option>
      <option value="elektroniczna" <?= $row['forma_podpisania']==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
    </select></div>
</div>
<div id="el_fields" class="row" style="display:<?= $row['forma_podpisania']==='elektroniczna'?'':'none' ?>">
  <div class="col-md-4 mb-3"><label class="form-label">Platforma</label>
    <input name="platforma_el" class="form-control" value="<?= h($row['platforma_el']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">ID dokumentu</label>
    <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Nowy plik potwierdzenia</label>
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

<div class="mb-3"><label class="form-label">Uwagi</label>
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi']) ?></textarea></div>

</div><!-- /col-lg-8 -->

<!-- SIDEBAR: Pliki -->
<div class="col-lg-4">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Plik umowy</div>
<div class="card-body">
  <?php if ($row['plik_umowy']): ?>
  <div class="mb-2"><?= upload_link($row['plik_umowy']) ?></div>
  <label class="form-label small text-muted">Zastąp nowym plikiem:</label>
  <?php else: ?>
  <label class="form-label">Plik umowy (PDF/DOCX)</label>
  <?php endif; ?>
  <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
</div>
</div>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-files"></i> Załączniki</div>
<div class="card-body">
  <?php if ($row['zalaczniki']): ?>
  <div class="mb-2"><?= upload_link($row['zalaczniki']) ?></div>
  <label class="form-label small text-muted">Zastąp nowym plikiem:</label>
  <?php else: ?>
  <label class="form-label">Załącznik (PDF/DOCX/XLSX/ZIP)</label>
  <?php endif; ?>
  <input name="zalaczniki" type="file" class="form-control" accept=".pdf,.docx,.xlsx,.zip">
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
document.getElementById('forma_podpisania').addEventListener('change', function() {
  var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
document.getElementById('czas_nieokreslony').addEventListener('change', function() {
  var el = document.getElementById('data_zakonczenia');
  el.disabled = this.checked;
  if (this.checked) el.value = '';
});

async function ceidgSearch() {
  var nip = document.getElementById('ceidgNipInput').value.replace(/\D/g, '');
  var btn = document.getElementById('ceidgBtn');
  var res = document.getElementById('ceidgResult');
  if (!nip || nip.length !== 10) { res.innerHTML = '<small class="text-danger">NIP musi mieć 10 cyfr.</small>'; return; }
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  res.innerHTML = '';
  try {
    var r = await fetch('<?= APP_URL ?>/api/ceidg.php?nip=' + nip);
    var d = await r.json();
    if (d.error) {
      res.innerHTML = '<div class="alert alert-warning py-2 mt-1 mb-0 small"><i class="bi bi-exclamation-triangle"></i> ' + d.error + '</div>';
    } else {
      var badge = d.aktywna ? '<span class="badge bg-success">Aktywna</span>' : '<span class="badge bg-secondary">' + (d.status || '') + '</span>';
      res.innerHTML = '<div class="border rounded p-2 mt-1 bg-white small"><div class="d-flex justify-content-between align-items-start gap-2"><div>' +
        '<strong>' + d.nazwa + '</strong><br>NIP: ' + d.nip + (d.regon ? ' | REGON: ' + d.regon : '') + '<br>' +
        (d.adres ? d.adres + '<br>' : '') + badge +
        '</div><button type="button" class="btn btn-sm btn-success flex-shrink-0" onclick=\'ceidgFill(' + JSON.stringify(d) + ')\'>' +
        '<i class="bi bi-arrow-down-circle"></i> Uzupełnij</button></div></div>';
    }
  } catch(e) { res.innerHTML = '<div class="alert alert-danger py-2 mt-1 mb-0 small">Błąd komunikacji z serwerem.</div>'; }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-search"></i> CEIDG';
}
function ceidgFill(d) {
  document.querySelector('[name=nazwa_wykonawcy]').value = d.nazwa || '';
  document.querySelector('[name=nip_pesel]').value       = d.nip   || '';
  document.querySelector('[name=adres]').value           = d.adres || '';
  document.getElementById('ceidgResult').innerHTML = '<small class="text-success mt-1 d-block"><i class="bi bi-check-circle"></i> Pola uzupełnione.</small>';
}

async function krsSearch() {
  var krs = document.getElementById('krsInput').value.replace(/\D/g, '');
  var btn = document.getElementById('krsBtn');
  var res = document.getElementById('krsResult');
  if (!krs) { res.innerHTML = '<small class="text-danger">Podaj numer KRS.</small>'; return; }
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  res.innerHTML = '';
  try {
    var r = await fetch('<?= APP_URL ?>/api/krs.php?krs=' + krs);
    var d = await r.json();
    if (d.error) {
      res.innerHTML = '<div class="alert alert-warning py-2 mt-1 mb-0 small"><i class="bi bi-exclamation-triangle"></i> ' + d.error + '</div>';
    } else {
      res.innerHTML = '<div class="border rounded p-2 mt-1 bg-white small"><div class="d-flex justify-content-between align-items-start gap-2"><div>' +
        '<strong>' + d.nazwa + '</strong><br>' +
        (d.forma_prawna ? d.forma_prawna + '<br>' : '') +
        'KRS: ' + d.krs + (d.nip ? ' | NIP: ' + d.nip : '') + (d.regon ? ' | REGON: ' + d.regon : '') + '<br>' +
        (d.adres ? d.adres + '<br>' : '') +
        '<span class="badge bg-info text-dark">' + (d.rejestr_label || '') + '</span>' +
        '</div><button type="button" class="btn btn-sm btn-success flex-shrink-0" onclick=\'krsFill(' + JSON.stringify(d) + ')\'>' +
        '<i class="bi bi-arrow-down-circle"></i> Uzupełnij</button></div></div>';
    }
  } catch(e) { res.innerHTML = '<div class="alert alert-danger py-2 mt-1 mb-0 small">Błąd komunikacji z serwerem.</div>'; }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-search"></i> KRS';
}

function krsFill(d) {
  document.querySelector('[name=nazwa_wykonawcy]').value = d.nazwa || '';
  document.querySelector('[name=nip_pesel]').value       = d.nip   || '';
  document.querySelector('[name=adres]').value           = d.adres || '';
  document.getElementById('krsResult').innerHTML = '<small class="text-success mt-1 d-block"><i class="bi bi-check-circle"></i> Pola uzupełnione.</small>';
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
