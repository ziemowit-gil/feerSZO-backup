<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/person_picker.php';

require_role('admin','editor');
require_once dirname(dirname(__DIR__)) . '/includes/zlecenie_schema.php';

// Moduł w przygotowaniu — blokuj dodawanie/edycję
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('zlecenie') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('warning', 'Moduł zlecenie jest w przygotowaniu. Dodawanie i edycja są tymczasowo wyłączone.');
    header('Location: list.php'); exit;
}
$TYPE  = 'zlecenie';
$TABLE = 'umowy_zlecenie';
$id = intval($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono.'); }
if (contract_is_locked($row)) {
    flash_set('warning', 'Umowa jest zablokowana (zawarty aneks) — edycja niedostępna.');
    header('Location: view.php?id=' . $id); exit;
}
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/zlecenie/edit.php?id=' . $id);

if (!empty($row['person_id'])) {
    $person_row = person_by_id((int)$row['person_id']);
    $row['_person_name'] = $person_row['imie_nazwisko'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty($data['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($data['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        foreach (['zus_skladki','zwolnienie_wiek','wymagany_rachunek','m365_konto'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        foreach (['wynagrodzenie_brutto','stawka_kwota','liczba_godzin_planowana','zaliczka_podatek'] as $f) {
            if (($data[$f] ?? '') === '') $data[$f] = null;
        }
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        if ($plik_umowy) $data['plik_umowy'] = $plik_umowy;
        else $data['plik_umowy'] = $row['plik_umowy'];
        if ($plik_potw)  $data['plik_potwierdzenia'] = $plik_potw;
        else $data['plik_potwierdzenia'] = $row['plik_potwierdzenia'];

        $allowed = ['numer_umowy','status','imie_nazwisko','pesel','adres','email','seria_nr_dowodu','urzad_skarbowy',
            'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
            'rachunek_bankowy','przedmiot_zlecenia','data_zawarcia','data_rozpoczecia','data_zakonczenia',
            'wynagrodzenie_brutto','stawka_kwota','typ_stawki','liczba_godzin_planowana','sposob_rozliczenia',
            'termin_platnosci','zus_skladki','tytul_ubezpieczenia','zus_data_rejestracji','zus_data_wyrejestrowania','zwolnienie_wiek','zaliczka_podatek','kup',
            'numer_projektu','opiekun','wymagany_rachunek','data_zl_rachunku','data_rachunku','okres_rachunku','forma_podpisania',
            'platforma_el','id_dokumentu_el','epodpis_dostawca','epodpis_nr_certyfikatu','epodpis_data_waznosci',
            'plik_potwierdzenia','plik_umowy','uwagi',
            'm365_konto','m365_login','m365_user_id','m365_konto_aktywne','m365_data_utworzenia','m365_licencja_przypisana',
            'nr_roboczy','nr_system','nr_rejestru','person_id','org_unit_id',
            'podpisujacy_fundacja','podpisujacy_stanowisko'];
        $save = array_intersect_key($data, array_flip($allowed));
        // Puste pola z kluczem obcym → NULL (pusty string łamie FOREIGN KEY).
        foreach (['person_id','org_unit_id','org_position_id'] as $fk) {
            if (isset($save[$fk]) && $save[$fk] === '') $save[$fk] = null;
        }
        require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
        $diff = format_field_diff($row, $save);
        db_update($TABLE, $save, $id);

        // Weryfikacja utrwalenia — wykryj „cichy" brak zapisu (sukces bez zmian w bazie).
        $missed = contract_assert_saved($TABLE, $id, $save);
        if ($missed) {
            error_log('[zlecenie/edit] zapis nieutrwalony id=' . $id
                . ' pola=' . implode(',', $missed)
                . ' postVars=' . count($_POST)
                . ' db=' . (defined('DB_PATH') ? DB_PATH : '?'));
            flash_set('danger', 'Zmiany NIE zostały zapisane w bazie (' . count($missed)
                . ' pól nie utrwalono). Zgłoś to administratorowi — najczęstsze przyczyny to brak praw zapisu do pliku bazy lub odczyt z innej bazy niż zapis.');
            header('Location: edit.php?id=' . $id);
            exit;
        }

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

<style>
.wiz-stepper{display:flex;gap:.5rem;flex-wrap:wrap}
.wiz-tab{flex:1 1 0;min-width:140px;display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border-radius:10px;
  background:#f1f5f9;color:#64748b;font-weight:600;font-size:.88rem;cursor:pointer;border:1px solid transparent;transition:.15s;user-select:none}
.wiz-tab .wiz-num{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;
  background:#cbd5e1;color:#fff;font-size:.82rem;flex-shrink:0}
.wiz-tab.active{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.wiz-tab.active .wiz-num{background:#2563eb}
.wiz-tab.done{color:#15803d}
.wiz-tab.done .wiz-num{background:#16a34a}
</style>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Pasek kroków -->
<div class="card shadow-sm mb-3"><div class="card-body py-2">
  <div class="wiz-stepper" id="wizStepper">
    <div class="wiz-tab active" data-go="1"><span class="wiz-num"><span>1</span></span> Strony i podstawy</div>
    <div class="wiz-tab" data-go="2"><span class="wiz-num"><span>2</span></span> Podpisanie</div>
    <div class="wiz-tab" data-go="3"><span class="wiz-num"><span>3</span></span> Wykonanie</div>
    <div class="wiz-tab" data-go="4"><span class="wiz-num"><span>4</span></span> Rozliczenie</div>
  </div>
</div></div>

<!-- ═══════════ KROK 1 — STRONY I PODSTAWY ═══════════ -->
<div class="wiz-step" data-step="1">

<!-- DANE PODSTAWOWE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle text-primary"></i> Dane podstawowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Numer umowy *</label>
    <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy']) ?>" required></div>
  <div class="col-md-4 mb-3"><label class="form-label">Status *</label>
    <select name="status" class="form-select" required>
      <?php foreach(STATUS_LABELS as $k=>$v): if($k==='aneks') continue;
        $sel = ($row['status']??'')===$k?'selected':''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Proces: <strong>projekt → do podpisu → podpisana → w realizacji → do rozliczenia → zakończona</strong>.</div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Opiekun umowy</label>
    <input name="opiekun" class="form-control" value="<?= h($row['opiekun']) ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Przedmiot zlecenia</label>
  <textarea name="przedmiot_zlecenia" class="form-control" rows="3"><?= h($row['przedmiot_zlecenia']) ?></textarea></div>
<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Wynagrodzenie brutto (PLN)</label>
    <input name="wynagrodzenie_brutto" type="number" step="0.01" class="form-control fw-semibold" value="<?= h($row['wynagrodzenie_brutto']) ?>"></div>
  <div class="col-md-6 mb-3"><label class="form-label">Numer projektu / źródło finansowania</label>
    <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']) ?>"></div>
</div>
</div>
</div>

<!-- ZLECENIOBIORCA -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-person"></i> Zleceniobiorca</div>
<div class="card-body">
<!-- Person picker — wyszukiwanie + autouzupełnianie z kartoteki osób -->
<div class="mb-3">
  <?= person_picker($row, [
    'id'          => 'zlpp',
    'label'       => 'Wypełnij z kartoteki osób',
    'fill'        => [
      'imie_nazwisko'    => 'zl_imie_nazwisko',
      'pesel'            => 'zl_pesel',
      'email'            => 'zl_email',
      'seria_nr_dowodu'  => 'zl_seria',
      'urzad_skarbowy'   => 'zl_urzad',
      'rachunek_bankowy' => 'zl_rachunek',
    ],
    'addr_widget' => 'zlecenieAddrWidget',
  ]) ?>
  <div class="form-text">Wybierz osobę z rejestru — puste pola (imię, PESEL, e-mail, adres, nr konta…) uzupełnią się automatycznie.</div>
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
<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Imię i nazwisko</label>
    <input name="imie_nazwisko" id="zl_imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko']) ?>"><?= byli_check_field('imie_nazwisko') ?></div>
  <div class="col-md-3 mb-3"><label class="form-label">PESEL</label>
    <input name="pesel" id="zl_pesel" class="form-control" maxlength="11" value="<?= h($row['pesel']) ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Seria/nr dowodu</label>
    <input name="seria_nr_dowodu" id="zl_seria" class="form-control" value="<?= h($row['seria_nr_dowodu']) ?>"></div>
</div>
<div class="row">
  <div class="col-12 mb-3">
    <label class="form-label fw-semibold"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania / siedziby</label>
    <?= address_widget($row, ['copy_button' => true, 'autocomplete' => true, 'widget_id' => 'zlecenieAddrWidget']) ?>
  </div>
  <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontrahenta</label>
    <input type="email" name="email" id="zl_email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email'])?>"></div>
  <div class="col-md-6 mb-3"><label class="form-label">Urząd skarbowy</label>
    <input name="urzad_skarbowy" id="zl_urzad" class="form-control" value="<?= h($row['urzad_skarbowy']) ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Rachunek bankowy (nr konta)</label>
  <input name="rachunek_bankowy" id="zl_rachunek" class="form-control" value="<?= h($row['rachunek_bankowy']) ?>"></div>
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

<!-- Numery referencyjne -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-hash"></i> Numery referencyjne <span class="text-muted small fw-normal">— opcjonalne</span></div>
<div class="card-body"><div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr roboczy umowy</label>
    <input name="nr_roboczy" class="form-control" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="np. PR-2026-001">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr ogólny <span class="text-muted small">(webNGO)</span></label>
    <input name="nr_system" class="form-control" value="<?= h($row['nr_system']??'') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr rejestru <span class="text-muted small">RU/{nr}/{rok}/{inicjały}</span></label>
    <input name="nr_rejestru" class="form-control font-monospace"
      value="<?= h($row['nr_rejestru']??'') ?>"
      placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
  </div>
</div></div>
</div>

</div><!-- /krok 1 -->

<!-- ═══════════ KROK 2 — ① PODPISANIE ═══════════ -->
<div class="wiz-step d-none" data-step="2">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><span class="badge bg-primary me-1">1</span><i class="bi bi-pen"></i> Podpisanie</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Podpisujący ze strony Fundacji</label>
    <input name="podpisujacy_fundacja" class="form-control" value="<?= h($row['podpisujacy_fundacja'] ?? '') ?>" placeholder="Imię i nazwisko"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Stanowisko / funkcja</label>
    <input name="podpisujacy_stanowisko" class="form-control" value="<?= h($row['podpisujacy_stanowisko'] ?? '') ?>" placeholder="np. Prezes Zarządu"></div>
</div>
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

<!-- Plik umowy -->
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

</div><!-- /krok 2 -->

<!-- ═══════════ KROK 3 — ② WYKONANIE ═══════════ -->
<div class="wiz-step d-none" data-step="3">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><span class="badge bg-info me-1">2</span><i class="bi bi-play-circle"></i> Wykonanie / realizacja</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data rozpoczęcia</label>
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data zakończenia</label>
    <input name="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Liczba godzin (plan)</label>
    <input name="liczba_godzin_planowana" type="number" step="0.5" class="form-control" value="<?= h($row['liczba_godzin_planowana']) ?>"></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Typ stawki</label>
    <select name="typ_stawki" class="form-select">
      <option value="">—</option>
      <option value="godzinowo" <?= $row['typ_stawki']==='godzinowo'?'selected':'' ?>>Godzinowa</option>
      <option value="ryczalt" <?= $row['typ_stawki']==='ryczalt'?'selected':'' ?>>Ryczałt</option>
    </select></div>
  <div class="col-md-4 mb-3"><label class="form-label">Kwota stawki (PLN)</label>
    <input name="stawka_kwota" type="number" step="0.01" class="form-control" value="<?= h($row['stawka_kwota']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Sposób rozliczenia</label>
    <input name="sposob_rozliczenia" class="form-control" placeholder="np. miesięcznie, po wykonaniu" value="<?= h($row['sposob_rozliczenia'] ?? '') ?>"></div>
</div>
<hr class="my-2">
<div class="text-muted small fw-semibold mb-2"><i class="bi bi-shield-check"></i> ZUS / Ubezpieczenie</div>
<div class="row">
  <div class="col-md-4 mb-3 pt-2">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="zus_skladki" value="1" <?= $row['zus_skladki']?'checked':'' ?>>
      <label class="form-check-label">Składki ZUS</label></div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Tytuł ubezpieczenia</label>
    <input name="tytul_ubezpieczenia" class="form-control" value="<?= h($row['tytul_ubezpieczenia']) ?>"></div>
  <div class="col-md-4 mb-3 pt-2">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="zwolnienie_wiek" value="1" <?= $row['zwolnienie_wiek']?'checked':'' ?>>
      <label class="form-check-label">Zwolnienie &lt;26 lat</label></div></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data zgłoszenia do ZUS (ZUA/ZZA)</label>
    <input type="date" name="zus_data_rejestracji" class="form-control" value="<?= h($row['zus_data_rejestracji'] ?? '') ?>">
    <div class="form-text">Termin: 7 dni od rozpoczęcia. Wypełnienie wycisza przypomnienia.</div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data wyrejestrowania z ZUS (ZWUA)</label>
    <input type="date" name="zus_data_wyrejestrowania" class="form-control" value="<?= h($row['zus_data_wyrejestrowania'] ?? '') ?>">
    <div class="form-text">Termin: 7 dni od zakończenia. Wypełnienie wycisza przypomnienia.</div></div>
</div>
</div>
</div>

</div><!-- /krok 3 -->

<!-- ═══════════ KROK 4 — ③ ROZLICZENIE I FINALIZACJA ═══════════ -->
<div class="wiz-step d-none" data-step="4">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><span class="badge bg-success me-1">3</span><i class="bi bi-cash-coin"></i> Rozliczenie i rachunek</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="wymagany_rachunek" value="1" <?= $row['wymagany_rachunek']?'checked':'' ?>>
      <label class="form-check-label">Wymagany rachunek</label></div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Termin płatności</label>
    <input name="termin_platnosci" class="form-control" value="<?= h($row['termin_platnosci']) ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">KUP</label>
    <select name="kup" class="form-select">
      <option value="brak" <?= ($row['kup']??'brak')==='brak'?'selected':'' ?>>Brak</option>
      <option value="20" <?= $row['kup']==='20'?'selected':'' ?>>20%</option>
      <option value="50" <?= $row['kup']==='50'?'selected':'' ?>>50%</option>
    </select></div>
</div>
<div class="row">
  <div class="col-md-3 mb-3"><label class="form-label">Data złożenia rachunku</label>
    <input name="data_zl_rachunku" type="date" class="form-control" value="<?= h($row['data_zl_rachunku']) ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Data rachunku</label>
    <input name="data_rachunku" type="date" class="form-control" value="<?= h($row['data_rachunku'] ?? '') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Za jaki okres jest rachunek</label>
    <input name="okres_rachunku" class="form-control" placeholder="np. czerwiec 2026" value="<?= h($row['okres_rachunku'] ?? '') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Zaliczka podatek (PLN)</label>
    <input name="zaliczka_podatek" type="number" step="0.01" class="form-control" value="<?= h($row['zaliczka_podatek']) ?>"></div>
</div>
<div class="form-text">Pełny proces rozliczeń (rachunki, wysyłka do księgowego) prowadzisz z widoku umowy.</div>
</div>
</div>

<!-- Uwagi -->
<div class="mb-3"><label class="form-label">Uwagi</label>
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi']) ?></textarea></div>

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
</div>
</div>

</div><!-- /krok 4 -->

<!-- Nawigacja kreatora -->
<div class="d-flex gap-2 mt-3 mb-4 align-items-center">
  <button type="button" class="btn btn-outline-secondary" id="wizBack" style="display:none"><i class="bi bi-arrow-left"></i> Wstecz</button>
  <a href="view.php?id=<?= $id ?>" class="btn btn-link text-muted">Anuluj</a>
  <div class="ms-auto d-flex gap-2">
    <button type="button" class="btn btn-primary" id="wizNext">Dalej <i class="bi bi-arrow-right"></i></button>
    <button type="submit" class="btn btn-success" id="wizSave"><i class="bi bi-check-lg"></i> Zapisz zmiany</button>
  </div>
</div>
</form>

<script>
/* ── Kreator: nawigacja krokowa ─────────────────────────── */
(function(){
  var steps = Array.prototype.slice.call(document.querySelectorAll('.wiz-step'));
  var tabs  = Array.prototype.slice.call(document.querySelectorAll('.wiz-tab'));
  var total = steps.length, cur = 1;
  var back = document.getElementById('wizBack'),
      next = document.getElementById('wizNext');
  function show(n){
    cur = Math.max(1, Math.min(total, n));
    steps.forEach(function(s){ s.classList.toggle('d-none', +s.getAttribute('data-step') !== cur); });
    tabs.forEach(function(t){
      var k = +t.getAttribute('data-go');
      t.classList.toggle('active', k === cur);
      t.classList.toggle('done',   k <  cur);
    });
    back.style.display = cur > 1 ? '' : 'none';
    next.style.display = cur < total ? '' : 'none';
    window.scrollTo({top:0, behavior:'smooth'});
  }
  next.addEventListener('click', function(){ show(cur+1); });
  back.addEventListener('click', function(){ show(cur-1); });
  // W edycji można swobodnie skakać między krokami (dane już istnieją).
  tabs.forEach(function(t){
    t.addEventListener('click', function(){ show(+t.getAttribute('data-go')); });
  });
  show(1);
})();

/* ── Forma podpisania: pokaż pola zależne ────────────────── */
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
  var nameEl = document.querySelector('[name=imie_nazwisko]'); if (nameEl) nameEl.value = pelneNazwisko;
  var adres = d.adres || '';
  var streetFld  = document.querySelector('[name=addr_street]');
  var houseFld   = document.querySelector('[name=addr_house]');
  var flatFld    = document.querySelector('[name=addr_flat]');
  var postalFld  = document.querySelector('[name=addr_postal]');
  var cityFld    = document.querySelector('[name=addr_city]');
  if (streetFld && adres) {
    var mPostal = adres.match(/(\d{2}-\d{3})\s+(.+)$/);
    if (mPostal) {
      if (postalFld) postalFld.value = mPostal[1];
      if (cityFld)   cityFld.value   = mPostal[2].trim();
      adres = adres.replace(/,?\s*\d{2}-\d{3}\s+.+$/, '').trim();
    }
    var mHouse = adres.match(/^(.*?)\s+([\d][\w\/\-]*)$/);
    if (mHouse) {
      var mFlat = mHouse[2].match(/^(\d+)\/(\d+)$/);
      if (mFlat) { houseFld.value = mFlat[1]; if(flatFld) flatFld.value = mFlat[2]; }
      else houseFld.value = mHouse[2];
      streetFld.value = mHouse[1];
    } else {
      streetFld.value = adres;
    }
  }
  document.getElementById('ceidgResult').innerHTML = '<small class="text-success mt-1 d-block"><i class="bi bi-check-circle"></i> Pola uzupełnione.</small>';
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
