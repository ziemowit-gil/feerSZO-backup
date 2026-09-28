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
    // Zamknięcie/rozwiązanie wymaga rozliczenia (zaliczka + hologramy) — jak przy szybkiej zmianie statusu.
    // Wiersz scalony z formularzem, żeby potwierdzenie rozliczenia zaliczki w tym samym zapisie się liczyło.
    if (!empty($data['status']) && $data['status'] !== ($row['status'] ?? '')) {
        require_once dirname(dirname(__DIR__)) . '/includes/contract_transitions.php';
        if (in_array($data['status'], ContractSettlementGuard::CLOSING_STATUSES, true)
            && ($why = ContractSettlementGuard::closeBlocker($TYPE, array_merge($row, $data, ['id' => $id])))) {
            $errors[] = $why;
        }
    }

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
            'podpisujacy_fundacja','podpisujacy_stanowisko',
            'w_ramach_is','is_nazwa','is_adres','is_numer_umowy','klauzula_rodo',
            'is_uprawnienia_nr','is_dyplom_nr','is_dopuszczenie'];
        $save = array_intersect_key($data, array_flip($allowed));
        // Puste pola z kluczem obcym → NULL (pusty string łamie FOREIGN KEY).
        foreach (['person_id','org_unit_id','org_position_id'] as $fk) {
            if (isset($save[$fk]) && $save[$fk] === '') $save[$fk] = null;
        }
        require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
        $diff = format_field_diff($row, $save);
        db_update($TABLE, $save, $id);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);

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

<div class="tw-max-w-6xl tw-mx-auto">

<div class="tw-flex tw-items-center tw-justify-between tw-mb-4 tw-flex-wrap tw-gap-2">
  <h1 class="tw-text-xl tw-font-semibold tw-text-slate-800 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-pencil tw-text-blue-600"></i> Edycja: <?= h($row['numer_umowy']) ?>
  </h1>
  <div class="tw-flex tw-gap-2">
    <a href="view.php?id=<?= $id ?>" class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-1.5 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50">
      <i class="bi bi-eye"></i> Podgląd
    </a>
    <a href="list.php" class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-1.5 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50">
      <i class="bi bi-arrow-left"></i> Lista
    </a>
  </div>
</div>

<?php if ($errors): ?>
<div class="tw-rounded-lg tw-border tw-border-red-200 tw-bg-red-50 tw-text-red-800 tw-px-4 tw-py-3 tw-mb-4 tw-text-sm">
  <ul class="tw-mb-0 tw-pl-4 tw-list-disc"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/cv_ui.php'; cv_ui_assets(); ?>
<form method="post" enctype="multipart/form-data" novalidate x-data="tabbedContractForm(4)" @submit="onSubmit($event)"
      data-cc-key="zlecenie-edit" class="cv-v2 tw-bg-white tw-rounded-2xl tw-border tw-border-slate-200 tw-shadow-sm tw-overflow-hidden">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Zakładki -->
<div class="cv-tabbar cv-formtabs" role="tablist" aria-label="Sekcje formularza umowy">
  <?php $_tabs = ['Strony i podstawy', 'Podpisanie', 'Wykonanie', 'Rozliczenie']; ?>
  <?php foreach ($_tabs as $_ti => $_tlabel): $_tn = $_ti + 1; ?>
  <button type="button" role="tab" class="nav-link" :class="tab===<?= $_tn ?> && 'active'"
          :aria-selected="(tab===<?= $_tn ?>).toString()" @click="goTab(<?= $_tn ?>)"><?= h($_tlabel) ?></button>
  <?php endforeach; ?>
</div>

<!-- TAB 1: Strony i podstawy -->
<div data-tab-pane="1" x-show="tab===1" x-cloak class="tw-p-6">

  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Dane podstawowe</h3></div>
  <div class="esec-body">
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="numer_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer umowy <span class="tw-text-red-500">*</span></label>
      <input id="numer_umowy" name="numer_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['numer_umowy']) ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach(STATUS_LABELS as $k=>$v): if($k==='aneks') continue;
          $sel = ($row['status']??'')===$k?'selected':''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Proces: <strong>projekt → do podpisu → podpisana → w realizacji → do rozliczenia → zakończona</strong>.</p>
    </div>
    <div>
      <label for="opiekun" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun umowy</label>
      <input id="opiekun" name="opiekun" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['opiekun']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="przedmiot_zlecenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Przedmiot zlecenia</label>
      <textarea id="przedmiot_zlecenia" name="przedmiot_zlecenia" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['przedmiot_zlecenia']) ?></textarea>
    </div>
    <div>
      <label for="wynagrodzenie_brutto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wynagrodzenie brutto (PLN)</label>
      <input id="wynagrodzenie_brutto" name="wynagrodzenie_brutto" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['wynagrodzenie_brutto']) ?>">
    </div>
    <div class="sm:tw-col-span-2">
      <label for="numer_projektu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer projektu / źródło finansowania</label>
      <input id="numer_projektu" name="numer_projektu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['numer_projektu']) ?>">
    </div>
  </div>

  <!-- Instytucja Szkoleniowa — pozostawione jako natywny komponent Bootstrap (collapse) -->
  <div class="tw-mb-4">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-building-check text-primary"></i>
        <span>Instytucja Szkoleniowa</span>
        <div class="ms-auto form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="w_ramach_is_toggle"
                 name="w_ramach_is" value="1" <?= !empty($row['w_ramach_is']) ? 'checked' : '' ?>
                 data-bs-toggle="collapse" data-bs-target="#is-fields">
          <label class="form-check-label fw-normal" for="w_ramach_is_toggle">w ramach IS</label>
        </div>
      </div>
      <div class="collapse<?= !empty($row['w_ramach_is']) ? ' show' : '' ?>" id="is-fields">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="is_nazwa">Nazwa Instytucji Szkoleniowej</label>
            <input type="text" class="form-control" id="is_nazwa" name="is_nazwa"
                   value="<?= h($row['is_nazwa'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="is_numer_umowy">Nr umowy z IS</label>
            <input type="text" class="form-control" id="is_numer_umowy" name="is_numer_umowy"
                   value="<?= h($row['is_numer_umowy'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="is_adres">Adres Instytucji Szkoleniowej</label>
            <textarea class="form-control" id="is_adres" name="is_adres" rows="2"><?= h($row['is_adres'] ?? '') ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="is_uprawnienia_nr">Nr uprawnień</label>
            <input type="text" class="form-control" id="is_uprawnienia_nr" name="is_uprawnienia_nr"
                   value="<?= h($row['is_uprawnienia_nr'] ?? '') ?>"
                   placeholder="np. AWF/123/2022">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="is_dyplom_nr">Nr dyplomu</label>
            <input type="text" class="form-control" id="is_dyplom_nr" name="is_dyplom_nr"
                   value="<?= h($row['is_dyplom_nr'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="is_dopuszczenie">Dopuszczenie / uwagi</label>
            <input type="text" class="form-control" id="is_dopuszczenie" name="is_dopuszczenie"
                   value="<?= h($row['is_dopuszczenie'] ?? '') ?>">
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="klauzula_rodo" name="klauzula_rodo"
                     value="1" <?= !empty($row['klauzula_rodo']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="klauzula_rodo">Klauzula informacyjna RODO podpisana</label>
            </div>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>

  </div></section>
  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Zleceniobiorca</h3></div>
  <div class="esec-body">

  <div class="tw-mb-4">
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
    <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Wybierz osobę z rejestru — puste pola (imię, PESEL, e-mail, adres, nr konta…) uzupełnią się automatycznie.</p>
  </div>

  <div class="tw-mb-4">
    <label for="org_unit_id" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Komórka organizacyjna</label>
    <select id="org_unit_id" name="org_unit_id" class="tw-w-full sm:tw-w-1/2 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
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

  <div class="tw-mb-4">
    <label for="ceidgNipInput" class="tw-block tw-text-xs tw-font-medium tw-text-slate-500 tw-mb-1">Szybkie uzupełnienie z CEIDG (dla JDG)</label>
    <div class="tw-flex tw-gap-2 tw-max-w-md">
      <input type="text" id="ceidgNipInput" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-1.5 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="NIP działalności (10 cyfr)" maxlength="13">
      <button type="button" id="ceidgBtn" onclick="ceidgSearch()"
              class="tw-inline-flex tw-items-center tw-gap-1 tw-whitespace-nowrap tw-rounded-lg tw-border tw-border-blue-300 tw-bg-white tw-px-3 tw-py-1.5 tw-text-sm tw-font-medium tw-text-blue-600 hover:tw-bg-blue-50">
        <i class="bi bi-search"></i> CEIDG
      </button>
    </div>
    <div id="ceidgResult" class="tw-mt-1"></div>
  </div>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div class="sm:tw-col-span-2">
      <label for="zl_imie_nazwisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Imię i nazwisko</label>
      <input id="zl_imie_nazwisko" name="imie_nazwisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['imie_nazwisko']) ?>">
      <?= byli_check_field('imie_nazwisko') ?>
    </div>
    <div>
      <label for="zl_pesel" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">PESEL</label>
      <input id="zl_pesel" name="pesel" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" maxlength="11" value="<?= h($row['pesel']) ?>">
    </div>
    <div>
      <label for="zl_seria" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Seria/nr dowodu</label>
      <input id="zl_seria" name="seria_nr_dowodu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['seria_nr_dowodu']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1"><i class="bi bi-house me-1 tw-text-slate-400"></i>Adres zamieszkania / siedziby</label>
      <?= address_widget($row, ['copy_button' => true, 'autocomplete' => true, 'widget_id' => 'zlecenieAddrWidget']) ?>
    </div>
    <div class="sm:tw-col-span-2">
      <label for="zl_email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres e-mail kontrahenta</label>
      <input type="email" id="zl_email" name="email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email'])?>">
    </div>
    <div>
      <label for="zl_urzad" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Urząd skarbowy</label>
      <input id="zl_urzad" name="urzad_skarbowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['urzad_skarbowy']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="zl_rachunek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy (nr konta)</label>
      <input id="zl_rachunek" name="rachunek_bankowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['rachunek_bankowy']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="m365_login" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Email do logowania w panelu</label>
      <div class="tw-relative">
        <i class="bi bi-envelope tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400"></i>
        <input id="m365_login" name="m365_login" type="email"
               class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-pl-9 tw-pr-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['m365_login'] ?? '') ?>" placeholder="imie.nazwisko@feer.org.pl  lub  prywatny@email.com">
      </div>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Adres Microsoft 365 (<code>@feer.org.pl</code>) lub prywatny e-mail — umożliwia dostęp do panelu umów.</p>
    </div>
  </div>

  </div></section>
  <section class="esec" data-cc-collapsed>
  <div class="esec-head"><h3 class="esec-title">Numery referencyjne <span class="esec-sub">opcjonalne</span></h3></div>
  <div class="esec-body">
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div>
      <label for="nr_roboczy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr roboczy umowy</label>
      <input id="nr_roboczy" name="nr_roboczy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="np. PR-2026-001">
    </div>
    <div>
      <label for="nr_system" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr ogólny <span class="tw-text-slate-400 tw-font-normal">(webNGO)</span></label>
      <input id="nr_system" name="nr_system" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['nr_system']??'') ?>">
    </div>
    <div>
      <label for="nr_rejestru" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr rejestru <span class="tw-text-slate-400 tw-font-normal">RU/{nr}/{rok}/{inicjały}</span></label>
      <input id="nr_rejestru" name="nr_rejestru" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nr_rejestru']??'') ?>" placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
    </div>
  </div>
  </div></section>
</div>

<!-- TAB 2: Podpisanie -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Podpisanie</h3></div>
  <div class="esec-body">
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_zawarcia']) ?>">
    </div>
    <div>
      <label for="podpisujacy_fundacja" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Podpisujący ze strony Fundacji</label>
      <input id="podpisujacy_fundacja" name="podpisujacy_fundacja" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['podpisujacy_fundacja'] ?? '') ?>" placeholder="Imię i nazwisko">
    </div>
    <div>
      <label for="podpisujacy_stanowisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Stanowisko / funkcja</label>
      <input id="podpisujacy_stanowisko" name="podpisujacy_stanowisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['podpisujacy_stanowisko'] ?? '') ?>" placeholder="np. Prezes Zarządu">
    </div>
  </div>
  <div class="tw-mb-4 sm:tw-w-1/3">
    <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
    <select id="forma_podpisania" name="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
      <option value="">—</option>
      <option value="papierowa" <?= $row['forma_podpisania']==='papierowa'?'selected':'' ?>>Papierowa</option>
      <option value="elektroniczna" <?= $row['forma_podpisania']==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
    </select>
  </div>

  <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= $row['forma_podpisania']==='elektroniczna'?'':'none' ?>">
    <div>
      <label for="platforma_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Platforma</label>
      <input id="platforma_el" name="platforma_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['platforma_el']) ?>">
    </div>
    <div>
      <label for="id_dokumentu_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">ID dokumentu</label>
      <input id="id_dokumentu_el" name="id_dokumentu_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['id_dokumentu_el']) ?>">
    </div>
    <div>
      <label for="plik_potwierdzenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nowy plik potwierdzenia</label>
      <input id="plik_potwierdzenia" name="plik_potwierdzenia" type="file" accept=".pdf"
             class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
      <?php if ($row['plik_potwierdzenia']): ?>
      <div class="tw-mt-1"><?= upload_link($row['plik_potwierdzenia']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div id="epodpis_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:none">
    <div>
      <label for="epodpis_dostawca" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Dostawca podpisu (TSP)</label>
      <input id="epodpis_dostawca" name="epodpis_dostawca" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca'] ?? '') ?>">
    </div>
    <div>
      <label for="epodpis_nr_certyfikatu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer seryjny certyfikatu</label>
      <input id="epodpis_nr_certyfikatu" name="epodpis_nr_certyfikatu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>">
    </div>
    <div>
      <label for="epodpis_data_waznosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Ważność certyfikatu</label>
      <input id="epodpis_data_waznosci" name="epodpis_data_waznosci" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>">
    </div>
  </div>

  </div></section>
  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Plik umowy</h3></div>
  <div class="esec-body">
  <div>
    <?php if ($row['plik_umowy']): ?>
    <div class="tw-mb-2 tw-text-sm"><?= upload_link($row['plik_umowy']) ?></div>
    <label for="plik_umowy" class="tw-block tw-text-xs tw-text-slate-500 tw-mb-1">Zastąp nowym plikiem:</label>
    <?php else: ?>
    <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF/DOCX)</label>
    <?php endif; ?>
    <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
           class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
  </div>
  </div></section>
</div>

<!-- TAB 3: Wykonanie -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Wykonanie / realizacja</h3></div>
  <div class="esec-body">
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-6">
    <div>
      <label for="data_rozpoczecia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rozpoczęcia</label>
      <input id="data_rozpoczecia" name="data_rozpoczecia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_rozpoczecia']) ?>">
    </div>
    <div>
      <label for="data_zakonczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zakończenia</label>
      <input id="data_zakonczenia" name="data_zakonczenia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_zakonczenia']) ?>">
    </div>
    <div>
      <label for="liczba_godzin_planowana" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Liczba godzin (plan)</label>
      <input id="liczba_godzin_planowana" name="liczba_godzin_planowana" type="number" step="0.5" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['liczba_godzin_planowana']) ?>">
    </div>
    <div>
      <label for="typ_stawki" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Typ stawki</label>
      <select id="typ_stawki" name="typ_stawki" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="">—</option>
        <option value="godzinowo" <?= $row['typ_stawki']==='godzinowo'?'selected':'' ?>>Godzinowa</option>
        <option value="ryczalt" <?= $row['typ_stawki']==='ryczalt'?'selected':'' ?>>Ryczałt</option>
      </select>
    </div>
    <div>
      <label for="stawka_kwota" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Kwota stawki (PLN)</label>
      <input id="stawka_kwota" name="stawka_kwota" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['stawka_kwota']) ?>">
    </div>
    <div>
      <label for="sposob_rozliczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Sposób rozliczenia</label>
      <input id="sposob_rozliczenia" name="sposob_rozliczenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="np. miesięcznie, po wykonaniu" value="<?= h($row['sposob_rozliczenia'] ?? '') ?>">
    </div>
  </div>

  </div></section>
  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">ZUS / Ubezpieczenie</h3></div>
  <div class="esec-body">
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div class="tw-flex tw-flex-col tw-gap-2 tw-pt-1">
      <label for="zus_skladki" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="zus_skladki" id="zus_skladki" value="1" <?= $row['zus_skladki']?'checked':'' ?>>
        Składki ZUS
      </label>
      <label for="zwolnienie_wiek" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="zwolnienie_wiek" id="zwolnienie_wiek" value="1" <?= $row['zwolnienie_wiek']?'checked':'' ?>>
        Zwolnienie &lt;26 lat
      </label>
    </div>
    <div>
      <label for="tytul_ubezpieczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Tytuł ubezpieczenia</label>
      <input id="tytul_ubezpieczenia" name="tytul_ubezpieczenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['tytul_ubezpieczenia']) ?>">
    </div>
    <div class="tw-space-y-3">
      <div>
        <label for="zus_data_rejestracji" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zgłoszenia do ZUS (ZUA/ZZA)</label>
        <input id="zus_data_rejestracji" name="zus_data_rejestracji" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['zus_data_rejestracji'] ?? '') ?>">
        <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Termin: 7 dni od rozpoczęcia. Wypełnienie wycisza przypomnienia.</p>
      </div>
      <div>
        <label for="zus_data_wyrejestrowania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data wyrejestrowania z ZUS (ZWUA)</label>
        <input id="zus_data_wyrejestrowania" name="zus_data_wyrejestrowania" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['zus_data_wyrejestrowania'] ?? '') ?>">
        <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Termin: 7 dni od zakończenia. Wypełnienie wycisza przypomnienia.</p>
      </div>
    </div>
  </div>
  </div></section>
</div>

<!-- TAB 4: Rozliczenie -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">

  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Rozliczenie i rachunek</h3></div>
  <div class="esec-body">
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div class="tw-pt-1">
      <label for="wymagany_rachunek" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="wymagany_rachunek" id="wymagany_rachunek" value="1" <?= $row['wymagany_rachunek']?'checked':'' ?>>
        Wymagany rachunek
      </label>
    </div>
    <div>
      <label for="termin_platnosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin płatności</label>
      <input id="termin_platnosci" name="termin_platnosci" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['termin_platnosci']) ?>">
    </div>
    <div>
      <label for="kup" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">KUP</label>
      <select id="kup" name="kup" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="brak" <?= ($row['kup']??'brak')==='brak'?'selected':'' ?>>Brak</option>
        <option value="20" <?= $row['kup']==='20'?'selected':'' ?>>20%</option>
        <option value="50" <?= $row['kup']==='50'?'selected':'' ?>>50%</option>
      </select>
    </div>
    <div>
      <label for="data_zl_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data złożenia rachunku</label>
      <input id="data_zl_rachunku" name="data_zl_rachunku" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_zl_rachunku']) ?>">
    </div>
    <div>
      <label for="data_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rachunku</label>
      <input id="data_rachunku" name="data_rachunku" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_rachunku'] ?? '') ?>">
    </div>
    <div>
      <label for="okres_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Za jaki okres jest rachunek</label>
      <input id="okres_rachunku" name="okres_rachunku" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="np. czerwiec 2026" value="<?= h($row['okres_rachunku'] ?? '') ?>">
    </div>
    <div>
      <label for="zaliczka_podatek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zaliczka podatek (PLN)</label>
      <input id="zaliczka_podatek" name="zaliczka_podatek" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['zaliczka_podatek']) ?>">
    </div>
  </div>
  <p class="tw-text-xs tw-text-slate-500 tw-mb-6">Pełny proces rozliczeń (rachunki, wysyłka do księgowego) prowadzisz z widoku umowy.</p>

  </div></section>
  <section class="esec">
  <div class="esec-head"><h3 class="esec-title">Uwagi</h3></div>
  <div class="esec-body">
  <div class="tw-mb-6">
    <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['uwagi']) ?></textarea>
  </div>

  </div></section>
  <section class="esec" data-cc-collapsed>
  <div class="esec-head"><h3 class="esec-title">Microsoft 365</h3></div>
  <div class="esec-body">
  <label for="m365_konto" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700 tw-mb-2">
    <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="m365_konto" id="m365_konto" value="1" <?= !empty($row['m365_konto'])?'checked':'' ?>>
    Konto M365 zostało utworzone
  </label>
  <div id="m365_manual_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mb-6" style="display:<?= !empty($row['m365_konto'])?'':'none' ?>">
    <div>
      <label for="m365_user_id" class="tw-block tw-text-xs tw-font-medium tw-text-slate-700 tw-mb-1">User ID (Azure AD)</label>
      <input id="m365_user_id" name="m365_user_id" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['m365_user_id']??'') ?>">
    </div>
  </div>
  <script>document.getElementById('m365_konto').addEventListener('change',function(){document.getElementById('m365_manual_fields').style.display=this.checked?'':'none'});</script>

  </div></section>
  <section class="esec" data-cc-collapsed>
  <div class="esec-head"><h3 class="esec-title">Dostęp</h3></div>
  <div class="esec-body">
  <div>
    <?= contract_access_field_html(contract_access_user_ids($TYPE, $id)) ?>
  </div>
  </div></section>
</div>

<!-- Pasek akcji — zawsze widoczny, niezależnie od aktywnej zakładki -->
<div class="tw-flex tw-flex-wrap tw-items-center tw-justify-end tw-gap-2 tw-border-t tw-border-slate-200 tw-bg-slate-50 tw-px-6 tw-py-4">
  <a href="view.php?id=<?= $id ?>" class="tw-inline-flex tw-items-center tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50">Anuluj</a>
  <button type="submit" :disabled="submitting"
          class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-bg-blue-600 tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-white hover:tw-bg-blue-700 disabled:tw-opacity-60">
    <span x-show="submitting" x-cloak class="tw-inline-block tw-h-3.5 tw-w-3.5 tw-animate-spin tw-rounded-full tw-border-2 tw-border-white/40 tw-border-t-white" aria-hidden="true"></span>
    <i x-show="!submitting" x-cloak class="bi bi-check-lg"></i>
    <span x-text="submitting ? 'Zapisywanie…' : 'Zapisz zmiany'"></span>
  </button>
</div>

</form>

</div><!-- /tw-max-w-6xl -->

<script>
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
