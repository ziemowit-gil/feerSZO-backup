<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin', 'editor');
require_module_enabled('contract_praca', 'Umowy o pracę');

// Moduł w przygotowaniu — blokuj dodawanie/edycję
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('praca') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('warning', 'Moduł praca jest w przygotowaniu. Dodawanie i edycja są tymczasowo wyłączone.');
    header('Location: list.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/praca/add.php');
$PAGE_TITLE = 'Nowa umowa o pracę';
$TYPE  = 'praca';
$TABLE = 'umowy_praca';
$errors = [];
$row = [
    'numer_umowy' => next_contract_number($TYPE),
    'status'      => 'obowiązująca',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    if (empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        // Pola checkboxowe
        foreach (['ppk', 'pit2', 'klauzula_rodo'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Upload plików
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        if ($plik_umowy) $row['plik_umowy']           = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia']   = $plik_potw;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        // Puste pola liczbowe → null
        foreach (['wynagrodzenie_brutto', 'urlop_wymiar', 'urlop_zalegly'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }

        // Tylko kolumny z tabeli
        $allowed = [
            'numer_umowy', 'status', 'imie_nazwisko', 'pesel', 'adres','email', 'seria_nr_dowodu',
            'urzad_skarbowy', 'rachunek_bankowy', 'email_login', 'stanowisko', 'dzial_projekt', 'wymiar_etatu',
            'rodzaj_umowy', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'wynagrodzenie_brutto', 'skladniki_wynagrodzenia', 'urlop_wymiar', 'urlop_zalegly',
            'okres_wypowiedzenia', 'ppk', 'pit2', 'badania_data_waznosci', 'bhp_data_waznosci',
            'klauzula_rodo', 'opiekun_przelozony', 'forma_podpisania', 'platforma_el',
            'id_dokumentu_el', 'plik_potwierdzenia', 'plik_umowy', 'aneksy', 'uwagi',
            'nr_roboczy', 'nr_system', 'nr_rejestru',
            'created_by', 'created_at', 'updated_at',
            'person_id', 'org_unit_id',
        ];
        $data = array_intersect_key($row, array_flip($allowed));

        assign_nr_rejestru($data);
        $id = db_insert($TABLE, $data);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);
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
        flash_set('success', 'Umowa o pracę została dodana.');
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}");
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="tw-max-w-6xl tw-mx-auto">

<div class="tw-flex tw-items-center tw-justify-between tw-mb-4 tw-flex-wrap tw-gap-2">
  <h1 class="tw-text-xl tw-font-semibold tw-text-slate-800 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-plus-circle tw-text-blue-600"></i> Nowa umowa o pracę
  </h1>
  <a href="list.php" class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-1.5 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50">
    <i class="bi bi-arrow-left"></i> Lista
  </a>
</div>

<?php if ($errors): ?>
<div class="tw-rounded-lg tw-border tw-border-red-200 tw-bg-red-50 tw-text-red-800 tw-px-4 tw-py-3 tw-mb-4 tw-text-sm">
  <ul class="tw-mb-0 tw-pl-4 tw-list-disc"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate x-data="tabbedContractForm(4)" @submit="onSubmit($event)"
      class="tw-bg-white tw-rounded-2xl tw-border tw-border-slate-200 tw-shadow-sm tw-overflow-hidden">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Zakładki -->
<div class="tw-flex tw-gap-1 tw-overflow-x-auto tw-border-b tw-border-slate-200 tw-bg-slate-50 tw-px-3 tw-pt-2" role="tablist" aria-label="Sekcje formularza umowy">
  <?php $_tabs = ['Umowa', 'Pracownik', 'Wynagrodzenie i formalności', 'Podpisanie i pliki']; ?>
  <?php foreach ($_tabs as $_ti => $_tlabel): $_tn = $_ti + 1; ?>
  <button type="button" role="tab" :aria-selected="(tab===<?= $_tn ?>).toString()" @click="goTab(<?= $_tn ?>)"
          class="tw-inline-flex tw-items-center tw-whitespace-nowrap tw-rounded-t-lg tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-transition"
          :class="tab===<?= $_tn ?> ? 'tw-bg-white tw-text-blue-600 tw-border tw-border-b-0 tw-border-slate-200' : 'tw-text-slate-500 hover:tw-text-slate-700'">
    <?= h($_tlabel) ?>
  </button>
  <?php endforeach; ?>
</div>

<!-- TAB 1: Umowa -->
<div data-tab-pane="1" x-show="tab===1" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-hash"></i> Numery referencyjne</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-6">
    <div>
      <label for="nr_roboczy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr roboczy umowy</label>
      <input id="nr_roboczy" name="nr_roboczy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nr_roboczy']??'') ?>" placeholder="np. PR-2026-001">
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Numer roboczy w projekcie.</p>
    </div>
    <div>
      <label for="nr_system" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr ogólny <span class="tw-text-slate-400 tw-font-normal">(webNGO, opcjonalne)</span></label>
      <input id="nr_system" name="nr_system" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nr_system']??'') ?>">
    </div>
    <div>
      <label for="nr_rejestru" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr rejestru <span class="tw-text-slate-400 tw-font-normal">RU/{nr}/{rok}/{inicjały}</span></label>
      <input id="nr_rejestru" name="nr_rejestru" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nr_rejestru']??'') ?>"
             placeholder="<?= h(suggest_nr_rejestru($row['opiekun_przelozony']??'')) ?>">
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Zostaw puste — zostanie nadany automatycznie.</p>
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-info-circle"></i> Dane podstawowe</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div>
      <label for="numer_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer umowy <span class="tw-text-red-500">*</span></label>
      <input id="numer_umowy" name="numer_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_umowy'] ?? '') ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach (['obowiązująca' => 'Obowiązująca', 'rozwiązana' => 'Rozwiązana', 'wygasła' => 'Wygasła'] as $k => $v):
          $sel = ($row['status'] ?? '') === $k ? 'selected' : ''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="rodzaj_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rodzaj umowy</label>
      <select id="rodzaj_umowy" name="rodzaj_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="">—</option>
        <?php foreach (['okres próbny' => 'Okres próbny', 'czas określony' => 'Czas określony', 'czas nieokreślony' => 'Czas nieokreślony'] as $k => $v):
          $sel = ($row['rodzaj_umowy'] ?? '') === $k ? 'selected' : ''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zawarcia'] ?? '') ?>">
    </div>
    <div>
      <label for="data_rozpoczecia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rozpoczęcia</label>
      <input id="data_rozpoczecia" name="data_rozpoczecia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_rozpoczecia'] ?? '') ?>">
    </div>
    <div>
      <label for="data_zakonczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zakończenia</label>
      <input id="data_zakonczenia" name="data_zakonczenia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zakonczenia'] ?? '') ?>">
    </div>
  </div>
</div>

<!-- TAB 2: Pracownik -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-person"></i> Pracownik</h2>

  <div class="tw-mb-4">
    <label for="person_search" class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1">Osoba powiązana w rejestrze</label>
    <div class="tw-relative tw-flex tw-gap-2">
      <input type="text" id="person_search"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="Szukaj po imieniu, PESEL lub email…"
             value="<?= h($row['_person_name'] ?? '') ?>"
             autocomplete="off">
      <a href="<?= APP_URL ?>/persons/add.php" target="_blank" title="Dodaj nową osobę"
         class="tw-inline-flex tw-items-center tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-text-slate-600 hover:tw-bg-slate-50 tw-flex-shrink-0">
        <i class="bi bi-person-plus"></i>
      </a>
      <div id="person_results" class="tw-hidden tw-absolute tw-top-full tw-left-0 tw-mt-1 tw-z-50 tw-w-full tw-max-w-[500px] tw-bg-white tw-border tw-border-slate-200 tw-rounded-lg tw-shadow-lg tw-overflow-hidden" style="display:none;position:absolute;z-index:1000;max-width:500px"></div>
    </div>
    <input type="hidden" name="person_id" id="person_id" value="<?= h($row['person_id'] ?? '') ?>">
    <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Opcjonalnie: wybierz istniejącą osobę lub <a href="<?= APP_URL ?>/persons/add.php" target="_blank" class="tw-text-blue-600 hover:tw-underline">dodaj nową</a>.</p>
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

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div class="sm:tw-col-span-2">
      <label for="imie_nazwisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Imię i nazwisko</label>
      <input id="imie_nazwisko" name="imie_nazwisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['imie_nazwisko'] ?? '') ?>">
      <?= byli_check_field('imie_nazwisko') ?>
    </div>
    <div>
      <label for="pesel" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">PESEL</label>
      <input id="pesel" name="pesel" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             maxlength="11" pattern="\d{11}" value="<?= h($row['pesel'] ?? '') ?>">
    </div>
    <div>
      <label for="seria_nr_dowodu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Seria i nr dowodu</label>
      <input id="seria_nr_dowodu" name="seria_nr_dowodu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['seria_nr_dowodu'] ?? '') ?>">
    </div>
    <div class="sm:tw-col-span-2">
      <label for="adres" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres zamieszkania</label>
      <input id="adres" name="adres" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['adres'] ?? '') ?>">
    </div>
    <div>
      <label for="email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres e-mail kontrahenta</label>
      <input id="email" type="email" name="email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email']??'') ?>">
    </div>
    <div class="sm:tw-col-span-2">
      <label for="urzad_skarbowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Urząd skarbowy</label>
      <input id="urzad_skarbowy" name="urzad_skarbowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['urzad_skarbowy'] ?? '') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="rachunek_bankowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy</label>
      <input id="rachunek_bankowy" name="rachunek_bankowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy'] ?? '') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="email_login" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Email do logowania w panelu</label>
      <div class="tw-relative">
        <i class="bi bi-envelope tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400"></i>
        <input id="email_login" name="email_login" type="email"
               class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-pl-9 tw-pr-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['email_login'] ?? '') ?>"
               placeholder="imie.nazwisko@feer.org.pl  lub  prywatny@email.com">
      </div>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Adres Microsoft 365 (<code>@feer.org.pl</code>) lub prywatny e-mail — umożliwia dostęp do panelu umów.</p>
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3 tw-mt-6"><i class="bi bi-building"></i> Stanowisko</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div>
      <label for="stanowisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Stanowisko</label>
      <input id="stanowisko" name="stanowisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['stanowisko'] ?? '') ?>">
    </div>
    <div>
      <label for="dzial_projekt" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Dział / projekt</label>
      <input id="dzial_projekt" name="dzial_projekt" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['dzial_projekt'] ?? '') ?>">
    </div>
    <div>
      <label for="wymiar_etatu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wymiar etatu</label>
      <select id="wymiar_etatu" name="wymiar_etatu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="">—</option>
        <?php foreach (['pełny' => 'Pełny etat', '1/2' => '1/2 etatu', '3/4' => '3/4 etatu', '1/4' => '1/4 etatu', 'inny' => 'Inny'] as $k => $v):
          $sel = ($row['wymiar_etatu'] ?? '') === $k ? 'selected' : ''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:tw-col-span-2">
      <label for="opiekun_przelozony" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun / przełożony</label>
      <input id="opiekun_przelozony" name="opiekun_przelozony" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['opiekun_przelozony'] ?? '') ?>">
    </div>
  </div>
</div>

<!-- TAB 3: Wynagrodzenie i formalności -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-currency-exchange"></i> Wynagrodzenie</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="wynagrodzenie_brutto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wynagrodzenie brutto (PLN)</label>
      <input id="wynagrodzenie_brutto" name="wynagrodzenie_brutto" type="number" step="0.01" min="0"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wynagrodzenie_brutto'] ?? '') ?>">
    </div>
    <div>
      <label for="urlop_wymiar" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wymiar urlopu (dni/rok)</label>
      <input id="urlop_wymiar" name="urlop_wymiar" type="number" min="0"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['urlop_wymiar'] ?? '') ?>">
    </div>
    <div>
      <label for="urlop_zalegly" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Urlop zaległy (dni)</label>
      <input id="urlop_zalegly" name="urlop_zalegly" type="number" min="0"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['urlop_zalegly'] ?? '') ?>">
    </div>
  </div>
  <div class="tw-mb-4">
    <label for="skladniki_wynagrodzenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Składniki wynagrodzenia</label>
    <textarea id="skladniki_wynagrodzenia" name="skladniki_wynagrodzenia" rows="3"
              class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
              placeholder="np. premia regulaminowa, dodatek funkcyjny..."><?= h($row['skladniki_wynagrodzenia'] ?? '') ?></textarea>
  </div>
  <div class="tw-mb-6 sm:tw-w-1/3">
    <label for="okres_wypowiedzenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Okres wypowiedzenia</label>
    <input id="okres_wypowiedzenia" name="okres_wypowiedzenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
           placeholder="np. 1 miesiąc" value="<?= h($row['okres_wypowiedzenia'] ?? '') ?>">
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-shield-check"></i> Formalności</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mb-4">
    <div>
      <label for="badania_data_waznosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Badania lekarskie — ważne do</label>
      <input id="badania_data_waznosci" name="badania_data_waznosci" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['badania_data_waznosci'] ?? '') ?>">
    </div>
    <div>
      <label for="bhp_data_waznosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">BHP — szkolenie ważne do</label>
      <input id="bhp_data_waznosci" name="bhp_data_waznosci" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['bhp_data_waznosci'] ?? '') ?>">
    </div>
  </div>
  <div class="tw-flex tw-flex-wrap tw-gap-6">
    <label for="ppk" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="ppk" id="ppk" value="1" <?= !empty($row['ppk']) ? 'checked' : '' ?>>
      PPK (Pracownicze Plany Kapitałowe)
    </label>
    <label for="pit2" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="pit2" id="pit2" value="1" <?= !empty($row['pit2']) ? 'checked' : '' ?>>
      PIT-2 złożony
    </label>
    <label for="klauzula_rodo" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="klauzula_rodo" id="klauzula_rodo" value="1" <?= !empty($row['klauzula_rodo']) ? 'checked' : '' ?>>
      Klauzula RODO podpisana
    </label>
  </div>
</div>

<!-- TAB 4: Podpisanie i pliki -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-6">
    <div class="lg:tw-col-span-2">

      <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-pen"></i> Podpisanie i aneksy</h2>
      <div class="tw-mb-4 sm:tw-w-1/2">
        <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
        <select name="forma_podpisania" id="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
          <option value="">—</option>
          <option value="papierowa" <?= ($row['forma_podpisania'] ?? '') === 'papierowa' ? 'selected' : '' ?>>Papierowa</option>
          <option value="elektroniczna" <?= ($row['forma_podpisania'] ?? '') === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna</option>
          <option value="epodpis_kwalifikowany" <?= (($row['forma_podpisania'] ?? '') === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
        </select>
      </div>

      <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= ($row['forma_podpisania'] ?? '') === 'elektroniczna' ? '' : 'none' ?>">
        <div>
          <label for="platforma_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Platforma</label>
          <input id="platforma_el" name="platforma_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 placeholder="Autenti / inny" value="<?= h($row['platforma_el'] ?? '') ?>">
        </div>
        <div>
          <label for="id_dokumentu_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">ID dokumentu w systemie</label>
          <input id="id_dokumentu_el" name="id_dokumentu_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['id_dokumentu_el'] ?? '') ?>">
        </div>
        <div>
          <label for="plik_potwierdzenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik potwierdzenia (PDF)</label>
          <input id="plik_potwierdzenia" name="plik_potwierdzenia" type="file" accept=".pdf"
                 class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
        </div>
      </div>

      <div id="epodpis_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:none">
        <div>
          <label for="epodpis_dostawca" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Dostawca podpisu (TSP)</label>
          <input id="epodpis_dostawca" name="epodpis_dostawca" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca'] ?? '') ?>">
        </div>
        <div>
          <label for="epodpis_nr_certyfikatu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer seryjny certyfikatu</label>
          <input id="epodpis_nr_certyfikatu" name="epodpis_nr_certyfikatu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>">
        </div>
        <div>
          <label for="epodpis_data_waznosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Ważność certyfikatu</label>
          <input id="epodpis_data_waznosci" name="epodpis_data_waznosci" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>">
        </div>
      </div>

      <div class="tw-mb-4">
        <label for="aneksy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Aneksy</label>
        <textarea id="aneksy" name="aneksy" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                  placeholder="Wykaz aneksów / zmian do umowy..."><?= h($row['aneksy'] ?? '') ?></textarea>
      </div>

      <div>
        <label for="uwagi" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Uwagi</label>
        <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['uwagi'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="lg:tw-col-span-1 tw-space-y-4">
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-paperclip"></i> Plik umowy</h2>
        <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF / DOCX, max 20 MB)</label>
        <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
               class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
      </div>
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-shield-lock"></i> Dostęp</h2>
        <?= contract_access_field_html([]) ?>
      </div>
    </div>
  </div>
</div>

<!-- Pasek akcji — zawsze widoczny, niezależnie od aktywnej zakładki -->
<div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-border-t tw-border-slate-200 tw-bg-slate-50 tw-px-6 tw-py-4">
  <label for="nie_mam_drukarki" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-600">
    <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
    <i class="bi bi-printer"></i> Nie mam drukarki — zapisz umowę jako PDF do późniejszego wydruku
  </label>
  <div class="tw-flex tw-gap-2">
    <a href="list.php" class="tw-inline-flex tw-items-center tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50">Anuluj</a>
    <button type="submit" :disabled="submitting"
            class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-bg-blue-600 tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-white hover:tw-bg-blue-700 disabled:tw-opacity-60">
      <span x-show="submitting" x-cloak class="tw-inline-block tw-h-3.5 tw-w-3.5 tw-animate-spin tw-rounded-full tw-border-2 tw-border-white/40 tw-border-t-white" aria-hidden="true"></span>
      <i x-show="!submitting" x-cloak class="bi bi-check-lg"></i>
      <span x-text="submitting ? 'Zapisywanie…' : 'Zapisz umowę'"></span>
    </button>
  </div>
</div>

</form>

</div><!-- /tw-max-w-6xl -->

<script>
document.getElementById('forma_podpisania').addEventListener('change', function () {
  var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
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
            results.innerHTML = '<div class="tw-p-2 tw-text-sm tw-text-slate-500">Nie znaleziono. <a href="<?= APP_URL ?>/persons/add.php" target="_blank" class="tw-text-blue-600 hover:tw-underline">Dodaj nową osobę</a>.</div>';
          } else {
            data.forEach(function(p) {
              var btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'tw-block tw-w-full tw-text-left tw-px-3 tw-py-2 tw-text-sm hover:tw-bg-slate-50 tw-border-b tw-border-slate-100 last:tw-border-b-0';
              btn.innerHTML = '<strong>' + p.imie_nazwisko + '</strong>'
                + (p.pesel ? ' <span class="tw-text-slate-400">' + p.pesel.substring(0,6) + '…</span>' : '')
                + (p.email ? ' <span class="tw-text-slate-400">' + p.email + '</span>' : '');
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
