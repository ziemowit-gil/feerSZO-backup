<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/person_picker.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin','editor');
require_module_enabled('contract_zlecenie', 'Ten typ umowy');
require_once dirname(dirname(__DIR__)) . '/includes/zlecenie_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/rodo.php';

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

    // Zapis roboczy — luźniejsza walidacja, domyślny status „projekt".
    $is_draft = isset($_POST['zapisz_roboczo']);
    if ($is_draft && empty($row['status'])) $row['status'] = 'projekt';
    if (empty($row['numer_umowy'])) $row['numer_umowy'] = next_contract_number($TYPE);

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
            'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
            'rachunek_bankowy','przedmiot_zlecenia','data_zawarcia','data_rozpoczecia','data_zakonczenia',
            'wynagrodzenie_brutto','stawka_kwota','typ_stawki','liczba_godzin_planowana','sposob_rozliczenia',
            'termin_platnosci','zus_skladki','tytul_ubezpieczenia','zus_data_rejestracji','zus_data_wyrejestrowania','zwolnienie_wiek','zaliczka_podatek','kup',
            'numer_projektu','opiekun','wymagany_rachunek','data_zl_rachunku','data_rachunku','okres_rachunku','forma_podpisania',
            'platforma_el','id_dokumentu_el','epodpis_dostawca','epodpis_nr_certyfikatu','epodpis_data_waznosci',
            'plik_potwierdzenia','plik_umowy','uwagi','created_by','created_at','updated_at',
            'm365_konto','m365_login','m365_user_id','m365_konto_aktywne','m365_data_utworzenia','m365_licencja_przypisana',
            'nr_roboczy','nr_system','nr_rejestru','person_id','org_unit_id',
            'podpisujacy_fundacja','podpisujacy_stanowisko'];
        $data = array_intersect_key($row, array_flip($allowed));
        // Puste pola z kluczem obcym → NULL (pusty string łamie FOREIGN KEY).
        foreach (['person_id','org_unit_id','org_position_id'] as $fk) {
            if (isset($data[$fk]) && $data[$fk] === '') $data[$fk] = null;
        }

        assign_nr_rejestru($data);
        $id = db_insert($TABLE, $data);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);

        // Upoważnienie RODO (opcjonalnie — wraz z umową)
        if (!empty($_POST['rodo_grant'])) {
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/rodo.php';
                rodo_migrate();
                rodo_quick_create($TYPE, (int)$id, [
                    'numer_umowy'      => $data['numer_umowy']   ?? '',
                    'data_zawarcia'    => $data['data_zawarcia'] ?? '',
                    'imie_nazwisko'    => $data['imie_nazwisko'] ?? '',
                    'pesel'            => $data['pesel']         ?? '',
                    'scope_items'      => $_POST['scope_items']  ?? [],
                    'scope_custom'     => $_POST['rodo_scope_custom']     ?? '',
                    'authorized_until' => $_POST['rodo_authorized_until'] ?? '',
                ]);
            } catch (\Throwable $e) { error_log('[zlecenie/add rodo] ' . $e->getMessage()); }
        }

        // Weryfikacja utrwalenia — wykryj „cichy" brak zapisu (sukces bez danych w bazie).
        $missed = contract_assert_saved($TABLE, $id, $data);
        if ($missed) {
            error_log('[zlecenie/add] zapis nieutrwalony id=' . $id
                . ' pola=' . implode(',', $missed)
                . ' postVars=' . count($_POST)
                . ' db=' . (defined('DB_PATH') ? DB_PATH : '?'));
            flash_set('danger', 'Umowa NIE została poprawnie zapisana w bazie (' . count($missed)
                . ' pól nie utrwalono). Zgłoś to administratorowi — sprawdź prawa zapisu do pliku bazy.');
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/add.php");
            exit;
        }

        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'create', 'Dodano: ' . ($data['numer_umowy'] ?? ''));
        if (isset($_POST['nie_mam_drukarki'])) {
            require_once dirname(dirname(__DIR__)) . '/contracts/includes/pdf_queue.php';
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['imie_nazwisko'] ?? '', current_user()['id']);
        }
        if (!$is_draft) {
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
                crm_migrate();
                CrmManager::autoCreateContractCase($TYPE, $id, $data['numer_umowy'] ?? '', $data, (int)(current_user()['id'] ?? 0));
            } catch (\Throwable $e) {
                error_log('[crm_case_auto] ' . $e->getMessage());
            }
        }
        if ($is_draft) {
            flash_set('info', 'Zapisano roboczo — możesz wrócić i dokończyć umowę.');
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/edit.php?id={$id}");
            exit;
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
.wiz-tab.done .wiz-num::before{content:"✓"}
.wiz-tab.done .wiz-num span{display:none}
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

<!-- DANE PODSTAWOWE (najważniejsze) -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle text-primary"></i> Dane podstawowe</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Numer umowy *</label>
    <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy']??'') ?>" required></div>
  <div class="col-md-4 mb-3"><label class="form-label">Status *</label>
    <select name="status" class="form-select" required>
      <?php foreach(STATUS_LABELS as $k=>$v): if($k==='aneks') continue;
        $sel = ($row['status']??'projekt')===$k?'selected':''; ?>
      <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Proces: <strong>projekt → do podpisu → podpisana → w realizacji → do rozliczenia → zakończona</strong>.</div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Opiekun umowy</label>
    <input name="opiekun" class="form-control" value="<?= h($row['opiekun']??'') ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Przedmiot zlecenia</label>
  <textarea name="przedmiot_zlecenia" class="form-control" rows="3"><?= h($row['przedmiot_zlecenia']??'') ?></textarea></div>
<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Wynagrodzenie brutto (PLN)</label>
    <input name="wynagrodzenie_brutto" type="number" step="0.01" class="form-control fw-semibold" value="<?= h($row['wynagrodzenie_brutto']??'') ?>"></div>
  <div class="col-md-6 mb-3"><label class="form-label">Numer projektu / źródło finansowania</label>
    <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']??'') ?>"></div>
</div>
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
  <div class="form-text">Wybierz osobę z rejestru — dane (imię, PESEL, e-mail, adres) uzupełnią się automatycznie. Opcjonalne.</div>
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
    <input name="imie_nazwisko" id="zl_imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko']??'') ?>"><?= byli_check_field('imie_nazwisko') ?></div>
  <div class="col-md-3 mb-3"><label class="form-label">PESEL</label>
    <input name="pesel" id="zl_pesel" class="form-control" maxlength="11" pattern="\d{11}" value="<?= h($row['pesel']??'') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Seria i nr dowodu</label>
    <input name="seria_nr_dowodu" id="zl_seria" class="form-control" value="<?= h($row['seria_nr_dowodu']??'') ?>"></div>
</div>
<div class="mb-3">
  <label class="form-label fw-semibold"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania / siedziby</label>
  <?= address_widget($row, ['copy_button' => true, 'autocomplete' => true, 'widget_id' => 'zlecenieAddrWidget']) ?>
</div>
<div class="row">
  <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail kontrahenta</label>
    <input type="email" name="email" id="zl_email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email']??'')?>"></div>
  <div class="col-md-6 mb-3"><label class="form-label">Urząd skarbowy</label>
    <input name="urzad_skarbowy" id="zl_urzad" class="form-control" value="<?= h($row['urzad_skarbowy']??'') ?>"></div>
</div>
<div class="mb-3"><label class="form-label">Rachunek bankowy (nr konta)</label>
  <input name="rachunek_bankowy" id="zl_rachunek" class="form-control" placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy']??'') ?>"></div>
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

<!-- Numery referencyjne (drugorzędne) -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-hash"></i> Numery referencyjne <span class="text-muted small fw-normal">— opcjonalne</span></div>
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

</div><!-- /krok 1 -->

<!-- ═══════════ KROK 2 — ① PODPISANIE ═══════════ -->
<div class="wiz-step d-none" data-step="2">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><span class="badge bg-primary me-1">1</span><i class="bi bi-pen"></i> Podpisanie</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Data zawarcia</label>
    <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Podpisujący ze strony Fundacji</label>
    <input name="podpisujacy_fundacja" class="form-control" value="<?= h($row['podpisujacy_fundacja']??'') ?>" placeholder="np. Jan Prezes"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Stanowisko podpisującego</label>
    <input name="podpisujacy_stanowisko" class="form-control" value="<?= h($row['podpisujacy_stanowisko']??'') ?>" placeholder="np. Prezes Zarządu"></div>
</div>
<div class="row">
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

<!-- Plik umowy -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Plik umowy</div>
<div class="card-body">
  <label class="form-label">Plik umowy (PDF/DOCX, max 20MB)</label>
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
    <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Data zakończenia</label>
    <input name="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Liczba godzin (planowana)</label>
    <input name="liczba_godzin_planowana" type="number" step="0.5" class="form-control" value="<?= h($row['liczba_godzin_planowana']??'') ?>"></div>
</div>
<div class="row">
  <div class="col-md-4 mb-3"><label class="form-label">Typ stawki</label>
    <select name="typ_stawki" class="form-select">
      <option value="">—</option>
      <option value="godzinowo" <?= ($row['typ_stawki']??'')==='godzinowo'?'selected':'' ?>>Godzinowa</option>
      <option value="ryczalt" <?= ($row['typ_stawki']??'')==='ryczalt'?'selected':'' ?>>Ryczałt</option>
    </select></div>
  <div class="col-md-4 mb-3"><label class="form-label">Kwota stawki (PLN)</label>
    <input name="stawka_kwota" type="number" step="0.01" class="form-control" value="<?= h($row['stawka_kwota']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Sposób rozliczenia</label>
    <input name="sposob_rozliczenia" class="form-control" placeholder="np. miesięcznie, po wykonaniu" value="<?= h($row['sposob_rozliczenia']??'') ?>"></div>
</div>
<hr class="my-2">
<div class="text-muted small fw-semibold mb-2"><i class="bi bi-shield-check"></i> ZUS / Ubezpieczenie</div>
<div class="row">
  <div class="col-md-4 mb-3 pt-2">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="zus_skladki" id="zus_skladki" value="1" <?= !empty($row['zus_skladki'])?'checked':'' ?>>
      <label class="form-check-label" for="zus_skladki">Podlega składkom ZUS</label>
    </div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Tytuł ubezpieczenia ZUS</label>
    <input name="tytul_ubezpieczenia" class="form-control" value="<?= h($row['tytul_ubezpieczenia']??'') ?>"></div>
  <div class="col-md-4 mb-3 pt-2">
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

</div><!-- /krok 3 -->

<!-- ═══════════ KROK 4 — ③ ROZLICZENIE I FINALIZACJA ═══════════ -->
<div class="wiz-step d-none" data-step="4">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><span class="badge bg-success me-1">3</span><i class="bi bi-cash-coin"></i> Rozliczenie i rachunek</div>
<div class="card-body">
<div class="row">
  <div class="col-md-4 mb-3 pt-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="wymagany_rachunek" id="wymagany_rachunek" value="1" <?= !empty($row['wymagany_rachunek'])?'checked':'' ?>>
      <label class="form-check-label" for="wymagany_rachunek">Wymagany rachunek do umowy</label>
    </div></div>
  <div class="col-md-4 mb-3"><label class="form-label">Termin płatności</label>
    <input name="termin_platnosci" class="form-control" placeholder="np. 14 dni od dostarczenia rachunku" value="<?= h($row['termin_platnosci']??'') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Koszty uzyskania przychodu</label>
    <select name="kup" class="form-select">
      <option value="brak">Brak / standardowe</option>
      <option value="20" <?= ($row['kup']??'')==='20'?'selected':'' ?>>20% KUP</option>
      <option value="50" <?= ($row['kup']??'')==='50'?'selected':'' ?>>50% KUP (prawa autorskie)</option>
    </select></div>
</div>
<div class="row">
  <div class="col-md-3 mb-3"><label class="form-label">Data złożenia rachunku</label>
    <input name="data_zl_rachunku" type="date" class="form-control" value="<?= h($row['data_zl_rachunku']??'') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Data rachunku</label>
    <input name="data_rachunku" type="date" class="form-control" value="<?= h($row['data_rachunku']??'') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Za jaki okres jest rachunek</label>
    <input name="okres_rachunku" class="form-control" placeholder="np. czerwiec 2026" value="<?= h($row['okres_rachunku']??'') ?>"></div>
  <div class="col-md-3 mb-3"><label class="form-label">Zaliczka na podatek (PLN)</label>
    <input name="zaliczka_podatek" type="number" step="0.01" class="form-control" value="<?= h($row['zaliczka_podatek']??'') ?>"></div>
</div>
<div class="form-text">Pełny proces rozliczeń (rachunki, wysyłka do księgowego) prowadzisz z widoku umowy po jej zapisaniu.</div>
</div>
</div>

<!-- Uwagi -->
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

<!-- Dostęp -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-lock"></i> Dostęp</div>
<div class="card-body">
  <?= contract_access_field_html([]) ?>
</div>
</div>

<div class="form-check mb-3">
  <input class="form-check-input" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
  <label class="form-check-label text-muted" for="nie_mam_drukarki">
    <i class="bi bi-printer"></i> Nie mam drukarki — zapisz umowę jako PDF do późniejszego wydruku
  </label>
</div>

<?php rodo_grant_form_section(); ?>

</div><!-- /krok 4 -->

<!-- Nawigacja kreatora -->
<div class="d-flex gap-2 mt-3 mb-4 align-items-center">
  <button type="button" class="btn btn-outline-secondary" id="wizBack" style="display:none"><i class="bi bi-arrow-left"></i> Wstecz</button>
  <a href="list.php" class="btn btn-link text-muted">Anuluj</a>
  <div class="ms-auto d-flex gap-2">
    <button type="submit" name="zapisz_roboczo" value="1" formnovalidate class="btn btn-outline-primary" id="wizDraft"
            title="Zapisz niekompletną umowę jako wersję roboczą i wróć do niej później">
      <i class="bi bi-save"></i> Zapisz roboczo
    </button>
    <button type="button" class="btn btn-primary" id="wizNext">Dalej <i class="bi bi-arrow-right"></i></button>
    <button type="submit" class="btn btn-success" id="wizSave" style="display:none"><i class="bi bi-check-lg"></i> Zapisz umowę</button>
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
      next = document.getElementById('wizNext'),
      save = document.getElementById('wizSave');
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
    save.style.display = cur === total ? '' : 'none';
    window.scrollTo({top:0, behavior:'smooth'});
  }
  function validStep(){
    var fields = steps[cur-1].querySelectorAll('input,select,textarea');
    for (var i=0;i<fields.length;i++){
      if (!fields[i].checkValidity()){ fields[i].reportValidity(); return false; }
    }
    return true;
  }
  next.addEventListener('click', function(){ if (validStep()) show(cur+1); });
  back.addEventListener('click', function(){ show(cur-1); });
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      var target = +t.getAttribute('data-go');
      if (target > cur && !validStep()) return;
      show(target);
    });
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
  var setVal = function(sel, val){ var el = document.querySelector(sel); if (el) el.value = val; };
  setVal('[name=imie_nazwisko]', pelneNazwisko);
  // CEIDG zwraca adres jako jeden ciąg — wpisujemy do pola „Ulica" (do ręcznego rozbicia).
  setVal('[name=addr_street]', d.adres || '');
  document.getElementById('ceidgResult').innerHTML = '<small class="text-success mt-1 d-block"><i class="bi bi-check-circle"></i> Pola uzupełnione — sprawdź rozbicie adresu.</small>';
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
