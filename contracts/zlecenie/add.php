<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';

require_role('admin','editor');
require_module_enabled('contract_zlecenie', 'Ten typ umowy');

// Moduł w przygotowaniu — blokuj dodawanie/edycję
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('zlecenie') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('warning', 'Moduł zlecenie jest w przygotowaniu. Dodawanie i edycja są tymczasowo wyłączone.');
    header('Location: list.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/zlecenie/add.php');
$PAGE_TITLE = 'Nowa umowa zlecenie';
$TYPE  = 'zlecenie';
$TABLE = 'umowy_zlecenie';
$errors = [];
$row = ['numer_umowy' => next_contract_number($TYPE), 'status' => 'projekt'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    if (empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        // Pola checkboxowe
        foreach (['zus_skladki','zwolnienie_wiek','wymagany_rachunek','m365_konto'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Upload
        $plik_umowy   = handle_upload('plik_umowy', $TYPE);
        $plik_potw    = handle_upload('plik_potwierdzenia', $TYPE);
        if ($plik_umowy) $row['plik_umowy'] = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia'] = $plik_potw;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        // Usuń puste pola liczbowe
        foreach (['wynagrodzenie_brutto','stawka_kwota','liczba_godzin_planowana','zaliczka_podatek'] as $f) {
            if ($row[$f] === '') $row[$f] = null;
        }
        // Tylko kolumny z tabeli
        $allowed = ['numer_umowy','status','imie_nazwisko','pesel','adres','email','seria_nr_dowodu','urzad_skarbowy',
            'rachunek_bankowy','przedmiot_zlecenia','data_zawarcia','data_rozpoczecia','data_zakonczenia',
            'wynagrodzenie_brutto','stawka_kwota','typ_stawki','liczba_godzin_planowana','sposob_rozliczenia',
            'termin_platnosci','zus_skladki','tytul_ubezpieczenia','zus_data_rejestracji','zus_data_wyrejestrowania','zwolnienie_wiek','zaliczka_podatek','kup',
            'numer_projektu','opiekun','wymagany_rachunek','data_zl_rachunku','forma_podpisania',
            'platforma_el','id_dokumentu_el','plik_potwierdzenia','plik_umowy','uwagi','created_by','created_at','updated_at',
            'm365_konto','m365_login','m365_user_id','m365_konto_aktywne','m365_data_utworzenia','m365_licencja_przypisana',
            'nr_roboczy','nr_system','nr_rejestru','person_id','org_unit_id'];
        $data = array_intersect_key($row, array_flip($allowed));

        assign_nr_rejestru($data);
        $id = db_insert($TABLE, $data);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'create', 'Dodano: ' . ($data['numer_umowy'] ?? ''));
        if (isset($_POST['nie_mam_drukarki'])) {
            require_once dirname(dirname(__DIR__)) . '/contracts/includes/pdf_queue.php';
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['imie_nazwisko'] ?? '', current_user()['id']);
        }
        try {
            require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
            crm_migrate();
            CrmManager::autoCreateContractCase($TYPE, $id, $data['numer_umowy'] ?? '', $data, (int)(current_user()['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[crm_case_auto] ' . $e->getMessage());
        }
        flash_set('success', 'Umowa zlecenie została dodana.');
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}");
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-plus-circle text-primary"></i> Nowa umowa zlecenie</h4>
  <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
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
    <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy']??'') ?>" required></div>
  <div class="col-md-4 mb-3"><label class="form-label">Status *</label>
    <select name="status" class="form-select" required>
      <?php foreach(['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $k=>$v):
        $sel = ($row['status']??'')===$k?'selected':''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-4 mb-3"><label class="form-label">Opiekun umowy</label>
    <input name="opiekun" class="form-control" value="<?= h($row['opiekun']??'') ?>"></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data rozpoczęcia</label>
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data zakończenia</label>
    <input name="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia']??'') ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Przedmiot zlecenia</label>
  <textarea name="przedmiot_zlecenia" class="form-control" rows="3"><?= h($row['przedmiot_zlecenia']??'') ?></textarea></div>
<div class="mb-3"><label class="form-label">Numer projektu / źródło finansowania</label>
  <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']??'') ?>"></div>
</div>
</div>

<!-- DANE ZLECENIOBIORCY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-person"></i> Zleceniobiorca</div>
<div class="card-body">
<div class="row mb-2">
  <div class="col-md-5">
    <label class="form-label small text-muted">Szybkie uzupełnienie z CEIDG (dla JDG)</label>
    <div class="input-group input-group-sm">
      <span class="input-group-text"><i class="bi bi-building-check"></i></span>
      <input type="text" id="ceidgNipInput" class="form-control" placeholder="NIP działalności (10 cyfr)" maxlength="13">
      <button type="button" class="btn btn-outline-primary" id="ceidgBtn" onclick="ceidgSearch()">
        <i class="bi bi-search"></i> CEIDG
      </button>
    </div>
    <div id="ceidgResult"></div>
  </div>
</div>
<!-- Person picker -->
<div class="mb-3">
  <label class="form-label fw-semibold">Osoba powiązana w rejestrze</label>
  <div class="input-group">
    <input type="text" id="person_search" class="form-control"
           placeholder="Szukaj po imieniu, PESEL lub email…"
           value="<?= h($row['_person_name'] ?? '') ?>"
           autocomplete="off">
    <a href="<?= APP_URL ?>/persons/add.php" class="btn btn-outline-secondary" target="_blank" title="Dodaj nową osobę">
      <i class="bi bi-person-plus"></i>
    </a>
  </div>
  <input type="hidden" name="person_id" id="person_id" value="<?= h($row['person_id'] ?? '') ?>">
  <div id="person_results" class="list-group mt-1" style="display:none;position:absolute;z-index:1000;max-width:500px"></div>
  <div class="form-text">Opcjonalnie: wybierz istniejącą osobę lub <a href="<?= APP_URL ?>/persons/add.php" target="_blank">dodaj nową</a>.</div>
</div>
<!-- Pozycja w strukturze -->
<div class="mb-3">
  <label class="form-label">Komórka organizacyjna</label>
  <select name="org_unit_id" class="form-select">
    <option value="">— wybierz —</option>
    <?php
    try {
      $units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name");
      foreach ($units as $pos):
        $sel = ($row['org_unit_id'] ?? '') == $pos['id'] ? 'selected' : '';
    ?>
    <option value="<?= h($pos['id']) ?>" <?= $sel ?>><?= h($pos['name']) ?></option>
    <?php endforeach; } catch(\Throwable $e) {} ?>
  </select>
</div>
<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Imię i nazwisko</label>
    <input name="imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko']??'') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">PESEL</label>
    <input name="pesel" class="form-control" maxlength="11" pattern="\d{11}" value="<?= h($row['pesel']??'') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Seria i nr dowodu</label>
    <input name="seria_nr_dowodu" class="form-control" value="<?= h($row['seria_nr_dowodu']??'') ?>"></div>
</div>
<div class="row">
  <div class="col-md-8 mb-3"><label class="form-label">Adres zamieszkania</label>
    <input name="adres" class="form-control" value="<?= h($row['adres']??'') ?>"></div>
  <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontrahenta</label>
    <input type="email" name="email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email']??'')?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Urząd skarbowy</label>
    <input name="urzad_skarbowy" class="form-control" value="<?= h($row['urzad_skarbowy']??'') ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Rachunek bankowy</label>
  <input name="rachunek_bankowy" class="form-control" placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy']??'') ?>"></div>
<div class="mb-2">
  <label class="form-label">Email do logowania w panelu</label>
  <div class="input-group">
    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
    <input name="m365_login" type="email" class="form-control"
      value="<?= h($row['m365_login'] ?? '') ?>"
      placeholder="imie.nazwisko@feer.org.pl  lub  prywatny@email.com">
  </div>
  <div class="form-text">Adres Microsoft 365 (<code>@feer.org.pl</code>) lub prywatny e-mail — umożliwia dostęp do panelu umów.</div>
</div>
</div>
</div>

<!-- WYNAGRODZENIE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-currency-exchange"></i> Wynagrodzenie</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Wynagrodzenie brutto (PLN)</label>
    <input name="wynagrodzenie_brutto" type="number" step="0.01" class="form-control" value="<?= h($row['wynagrodzenie_brutto']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Kwota stawki (PLN)</label>
    <input name="stawka_kwota" type="number" step="0.01" class="form-control" value="<?= h($row['stawka_kwota']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Typ stawki</label>
    <select name="typ_stawki" class="form-select">
      <option value="">—</option>
      <option value="godzinowo" <?= ($row['typ_stawki']??'')==='godzinowo'?'selected':'' ?>>Godzinowa</option>
      <option value="ryczalt" <?= ($row['typ_stawki']??'')==='ryczalt'?'selected':'' ?>>Ryczałt</option>
    </select></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Liczba godzin (planowana)</label>
    <input name="liczba_godzin_planowana" type="number" step="0.5" class="form-control" value="<?= h($row['liczba_godzin_planowana']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Termin płatności</label>
    <input name="termin_platnosci" class="form-control" placeholder="np. 14 dni od dostarczenia rachunku" value="<?= h($row['termin_platnosci']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Koszty uzyskania przychodu</label>
    <select name="kup" class="form-select">
      <option value="brak">Brak / standardowe</option>
      <option value="20" <?= ($row['kup']??'')==='20'?'selected':'' ?>>20% KUP</option>
      <option value="50" <?= ($row['kup']??'')==='50'?'selected':'' ?>>50% KUP (prawa autorskie)</option>
    </select></div>
</div>
<div class="col-md-4 mb-3"><label class="form-label">Zaliczka na podatek (PLN)</label>
  <input name="zaliczka_podatek" type="number" step="0.01" class="form-control" value="<?= h($row['zaliczka_podatek']??'') ?>"></div>
</div>
</div>

<!-- ZUS -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> ZUS / Ubezpieczenie</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="zus_skladki" id="zus_skladki" value="1" <?= !empty($row['zus_skladki'])?'checked':'' ?>>
      <label class="form-check-label" for="zus_skladki">Podlega składkom ZUS</label>
    </div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Tytuł ubezpieczenia ZUS</label>
    <input name="tytul_ubezpieczenia" class="form-control" value="<?= h($row['tytul_ubezpieczenia']??'') ?>"></div>
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="zwolnienie_wiek" id="zwolnienie_wiek" value="1" <?= !empty($row['zwolnienie_wiek'])?'checked':'' ?>>
      <label class="form-check-label" for="zwolnienie_wiek">Zwolnienie — student/uczeń do 26 lat</label>
    </div></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data zgłoszenia do ZUS (ZUA/ZZA)</label>
    <input type="date" name="zus_data_rejestracji" class="form-control" value="<?= h($row['zus_data_rejestracji']??'') ?>">
    <div class="form-text">Termin: 7 dni od rozpoczęcia. Wypełnienie wycisza przypomnienia o rejestracji.</div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data wyrejestrowania z ZUS (ZWUA)</label>
    <input type="date" name="zus_data_wyrejestrowania" class="form-control" value="<?= h($row['zus_data_wyrejestrowania']??'') ?>">
    <div class="form-text">Termin: 7 dni od zakończenia. Wypełnienie wycisza przypomnienia o wyrejestrowaniu.</div></div>
</div>
</div>
</div>

<!-- RACHUNEK I FORMA PODPISANIA -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-pen"></i> Rachunek i podpisanie</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="wymagany_rachunek" id="wymagany_rachunek" value="1" <?= !empty($row['wymagany_rachunek'])?'checked':'' ?>>
      <label class="form-check-label" for="wymagany_rachunek">Wymagany rachunek do umowy</label>
    </div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data złożenia rachunku</label>
    <input name="data_zl_rachunku" type="date" class="form-control" value="<?= h($row['data_zl_rachunku']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Forma podpisania</label>
    <select name="forma_podpisania" class="form-select" id="forma_podpisania">
      <option value="">—</option>
      <option value="papierowa" <?= ($row['forma_podpisania']??'')==='papierowa'?'selected':'' ?>>Papierowa</option>
      <option value="elektroniczna" <?= ($row['forma_podpisania']??'')==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= (($row['forma_podpisania'] ?? '') === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
    </select></div>
</div>
<div id="el_fields" class="row" style="display:<?= ($row['forma_podpisania']??'')==='elektroniczna'?'':'none' ?>">
  <div class="col-md-4 mb-3"><label class="form-label">Platforma</label>
    <input name="platforma_el" class="form-control" placeholder="Autenti / inny" value="<?= h($row['platforma_el']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">ID dokumentu w systemie</label>
    <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Plik potwierdzenia (PDF)</label>
    <input name="plik_potwierdzenia" type="file" class="form-control" accept=".pdf"></div>
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
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi']??'') ?></textarea></div>

<!-- M365 -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-microsoft"></i> Microsoft 365</div>
<div class="card-body">
  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="m365_konto" id="m365_konto" value="1" <?= !empty($row['m365_konto'])?'checked':'' ?>>
    <label class="form-check-label" for="m365_konto">Konto M365 zostało utworzone</label>
  </div>
  <div id="m365_manual_fields" style="display:<?= !empty($row['m365_konto'])?'':'none' ?>">
    <div class="row">
      <div class="col-md-6 mb-2">
        <label class="form-label small">User ID (Azure AD)</label>
        <input name="m365_user_id" class="form-control form-control-sm font-monospace" value="<?= h($row['m365_user_id']??'') ?>">
      </div>
    </div>
  </div>
  <script>document.getElementById('m365_konto').addEventListener('change',function(){document.getElementById('m365_manual_fields').style.display=this.checked?'':'none'});</script>
  <small class="text-muted">Konto można też <a href="#">utworzyć automatycznie</a> po zapisaniu umowy z widoku szczegółów.</small>
</div>
</div>

</div><!-- /col-lg-8 -->

<!-- SIDEBAR: Pliki -->
<div class="col-lg-4">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Pliki</div>
<div class="card-body">
  <div class="mb-3">
    <label class="form-label">Plik umowy (PDF, max 20MB)</label>
    <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
  </div>
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
document.getElementById('forma_podpisania').addEventListener('change', function() {
  var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
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
        '<strong>' + d.nazwa + '</strong><br>NIP: ' + d.nip + '<br>' +
        (d.adres ? d.adres + '<br>' : '') + badge +
        '</div><button type="button" class="btn btn-sm btn-success flex-shrink-0" onclick=\'ceidgFill(' + JSON.stringify(d) + ')\'>' +
        '<i class="bi bi-arrow-down-circle"></i> Uzupełnij</button></div></div>';
    }
  } catch(e) { res.innerHTML = '<div class="alert alert-danger py-2 mt-1 mb-0 small">Błąd komunikacji z serwerem.</div>'; }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-search"></i> CEIDG';
}
function ceidgFill(d) {
  var pelneNazwisko = (d.imie && d.nazwisko) ? d.imie + ' ' + d.nazwisko : d.nazwa || '';
  document.querySelector('[name=imie_nazwisko]').value = pelneNazwisko;
  document.querySelector('[name=adres]').value         = d.adres || '';
  document.getElementById('ceidgResult').innerHTML = '<small class="text-success mt-1 d-block"><i class="bi bi-check-circle"></i> Pola uzupełnione.</small>';
}
</script>

<script>
(function() {
  var searchInput = document.getElementById('person_search');
  var hiddenId    = document.getElementById('person_id');
  var results     = document.getElementById('person_results');
  if (!searchInput) return;
  var timer;
  searchInput.addEventListener('input', function() {
    clearTimeout(timer);
    var q = this.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }
    timer = setTimeout(function() {
      fetch('<?= APP_URL ?>/persons/search.php?q=' + encodeURIComponent(q))
        .then(r => r.json()).then(function(data) {
          results.innerHTML = '';
          if (!data.length) {
            results.innerHTML = '<div class="list-group-item text-muted small">Nie znaleziono. <a href="<?= APP_URL ?>/persons/add.php" target="_blank">Dodaj nową osobę</a>.</div>';
          } else {
            data.forEach(function(p) {
              var btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'list-group-item list-group-item-action small';
              btn.innerHTML = '<strong>' + p.imie_nazwisko + '</strong>'
                + (p.pesel ? ' <span class="text-muted">' + p.pesel.substring(0,6) + '…</span>' : '')
                + (p.email ? ' <span class="text-muted">' + p.email + '</span>' : '');
              btn.addEventListener('click', function() {
                hiddenId.value    = p.id;
                searchInput.value = p.imie_nazwisko;
                results.style.display = 'none';
              });
              results.appendChild(btn);
            });
          }
          results.style.display = '';
        }).catch(function() {});
    }, 250);
  });
  document.addEventListener('click', function(e) {
    if (!results.contains(e.target) && e.target !== searchInput) {
      results.style.display = 'none';
    }
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
