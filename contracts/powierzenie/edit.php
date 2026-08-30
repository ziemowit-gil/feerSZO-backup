<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/powierzenie_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin', 'editor');
$TYPE  = 'powierzenie';
$TABLE = 'umowy_powierzenie';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row)) {
    flash_set('error', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . '/contracts/' . $TYPE . '/list.php'); exit;
}
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/powierzenie/edit.php?id=' . $id);

$formy_zlecenia = ['powierzenie' => 'Powierzenie (100% dotacji)', 'wsparcie' => 'Wsparcie (z wkładem własnym)'];
$tryby = ['konkurs' => 'Otwarty konkurs ofert', 'art19a' => 'Tryb pozakonkursowy (art. 19a — mały grant)', 'inny' => 'Inny tryb'];
$statuses = ['projekt' => 'Projekt', 'podpisana' => 'Podpisana', 'w realizacji' => 'W realizacji',
             'do rozliczenia' => 'Do rozliczenia', 'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty($data['numer_umowy']))   $errors[] = 'Numer umowy jest wymagany.';
    if (empty($data['status']))        $errors[] = 'Status jest wymagany.';
    if (empty($data['nazwa_zadania'])) $errors[] = 'Nazwa zadania publicznego jest wymagana.';

    if (!$errors) {
        foreach (['kwota_dotacji', 'wklad_wlasny', 'wklad_osobowy', 'calkowity_koszt'] as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }

        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zalaczniki = handle_upload('zalaczniki', $TYPE);

        $data['plik_umowy']         = $plik_umowy ?: $row['plik_umowy'];
        $data['plik_potwierdzenia'] = $plik_potw  ?: $row['plik_potwierdzenia'];
        $data['zalaczniki']         = $zalaczniki  ?: $row['zalaczniki'];

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

<form method="post" enctype="multipart/form-data" novalidate x-data="tabbedContractForm(4)" @submit="onSubmit($event)"
      class="tw-bg-white tw-rounded-2xl tw-border tw-border-slate-200 tw-shadow-sm tw-overflow-hidden">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Zakładki -->
<div class="tw-flex tw-gap-1 tw-overflow-x-auto tw-border-b tw-border-slate-200 tw-bg-slate-50 tw-px-3 tw-pt-2" role="tablist" aria-label="Sekcje formularza umowy">
  <?php $_tabs = ['Umowa', 'Zadanie i zleceniodawca', 'Finansowanie i terminy', 'Podpisanie i pliki']; ?>
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
             placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Zostaw puste — zostanie nadany automatycznie.</p>
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-info-circle"></i> Dane podstawowe</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div>
      <label for="numer_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer umowy <span class="tw-text-red-500">*</span></label>
      <input id="numer_umowy" name="numer_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_umowy']) ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach ($statuses as $k => $v):
          $sel = ($row['status'] ?? '') === $k ? 'selected' : ''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="forma_zlecenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma zlecenia</label>
      <select id="forma_zlecenia" name="forma_zlecenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach ($formy_zlecenia as $k => $v):
          $sel = ($row['forma_zlecenia'] ?? 'powierzenie') === $k ? 'selected' : ''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="opiekun" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun umowy</label>
      <input id="opiekun" name="opiekun" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['opiekun']) ?>">
    </div>
    <div class="sm:tw-col-span-2">
      <label for="numer_projektu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer projektu / źródło finansowania</label>
      <input id="numer_projektu" name="numer_projektu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_projektu']) ?>">
    </div>
  </div>
</div>

<!-- TAB 2: Zadanie i zleceniodawca -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-clipboard-check"></i> Zadanie publiczne</h2>
  <div class="tw-mb-4">
    <label for="nazwa_zadania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nazwa zadania publicznego <span class="tw-text-red-500">*</span></label>
    <input id="nazwa_zadania" name="nazwa_zadania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
           value="<?= h($row['nazwa_zadania'] ?? '') ?>" required>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mb-4">
    <div>
      <label for="sfera_zadania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Sfera pożytku publicznego</label>
      <input id="sfera_zadania" name="sfera_zadania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             list="sfery_list" value="<?= h($row['sfera_zadania'] ?? '') ?>" placeholder="np. ochrona i promocja zdrowia">
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
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
      <div>
        <label for="tryb_zlecenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Tryb zlecenia</label>
        <select id="tryb_zlecenia" name="tryb_zlecenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
          <option value="">— wybierz —</option>
          <?php foreach ($tryby as $k => $v):
            $sel = ($row['tryb_zlecenia'] ?? '') === $k ? 'selected' : ''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="nazwa_konkursu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr / nazwa konkursu</label>
        <input id="nazwa_konkursu" name="nazwa_konkursu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['nazwa_konkursu'] ?? '') ?>">
      </div>
    </div>
  </div>
  <div class="tw-mb-4">
    <label for="zakres_rzeczowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zakres rzeczowy zadania</label>
    <textarea id="zakres_rzeczowy" name="zakres_rzeczowy" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['zakres_rzeczowy'] ?? '') ?></textarea>
  </div>
  <div class="tw-mb-6">
    <label for="rezultaty" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zakładane rezultaty</label>
    <textarea id="rezultaty" name="rezultaty" rows="2" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['rezultaty'] ?? '') ?></textarea>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-building"></i> Zleceniodawca (organ administracji)</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mb-4">
    <div>
      <label for="organ_zlecajacy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Organ zlecający</label>
      <input id="organ_zlecajacy" name="organ_zlecajacy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['organ_zlecajacy'] ?? '') ?>" placeholder="np. Gmina Miasta X / Urząd Marszałkowski…">
    </div>
    <div>
      <label for="organ_reprezentacja" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Reprezentowany przez</label>
      <input id="organ_reprezentacja" name="organ_reprezentacja" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['organ_reprezentacja'] ?? '') ?>" placeholder="imię, nazwisko, stanowisko">
    </div>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
    <div>
      <label for="organ_adres" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres organu</label>
      <input id="organ_adres" name="organ_adres" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['organ_adres'] ?? '') ?>">
    </div>
    <div>
      <label for="email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres e-mail kontaktowy</label>
      <input id="email" type="email" name="email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. kontakt@urzad.gov.pl" value="<?= h($row['email']??'')?>">
    </div>
  </div>
</div>

<!-- TAB 3: Finansowanie i terminy -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-cash-coin"></i> Finansowanie / dotacja</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="kwota_dotacji" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Kwota dotacji</label>
      <input id="kwota_dotacji" name="kwota_dotacji" type="number" step="0.01"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['kwota_dotacji'] ?? '') ?>">
    </div>
    <div>
      <label for="waluta" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Waluta</label>
      <select id="waluta" name="waluta" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach (['PLN', 'EUR', 'USD'] as $cur):
          $sel = ($row['waluta'] ?: 'PLN') === $cur ? 'selected' : ''; ?>
        <option value="<?= $cur ?>" <?= $sel ?>><?= $cur ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="rachunek_dotacji" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wyodrębniony rachunek bankowy dotacji</label>
      <input id="rachunek_dotacji" name="rachunek_dotacji" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['rachunek_dotacji'] ?? '') ?>">
    </div>
  </div>
  <div id="wsparcie_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= ($row['forma_zlecenia'] ?? '') === 'wsparcie' ? '' : 'none' ?>">
    <div>
      <label for="wklad_wlasny" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wkład własny finansowy</label>
      <input id="wklad_wlasny" name="wklad_wlasny" type="number" step="0.01"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wklad_wlasny'] ?? '') ?>">
    </div>
    <div>
      <label for="wklad_osobowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wkład osobowy / rzeczowy</label>
      <input id="wklad_osobowy" name="wklad_osobowy" type="number" step="0.01"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wklad_osobowy'] ?? '') ?>">
    </div>
    <div>
      <label for="calkowity_koszt" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Całkowity koszt zadania</label>
      <input id="calkowity_koszt" name="calkowity_koszt" type="number" step="0.01"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['calkowity_koszt'] ?? '') ?>">
    </div>
  </div>
  <div class="tw-mb-4">
    <label for="transze" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Harmonogram / transze płatności</label>
    <textarea id="transze" name="transze" rows="2" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
              placeholder="np. I transza 60% po podpisaniu, II transza 40% po rozliczeniu"><?= h($row['transze'] ?? '') ?></textarea>
  </div>
  <div class="tw-mb-6">
    <label for="koszty_kwalifikowane" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Koszty kwalifikowane / kosztorys</label>
    <textarea id="koszty_kwalifikowane" name="koszty_kwalifikowane" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['koszty_kwalifikowane'] ?? '') ?></textarea>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-calendar3"></i> Terminy</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zawarcia']) ?>">
    </div>
    <div>
      <label for="data_rozpoczecia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Realizacja od</label>
      <input id="data_rozpoczecia" name="data_rozpoczecia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_rozpoczecia']) ?>">
    </div>
    <div>
      <label for="data_zakonczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Realizacja do</label>
      <input id="data_zakonczenia" name="data_zakonczenia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zakonczenia']) ?>">
    </div>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
    <div>
      <label for="termin_wykorzystania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin wykorzystania dotacji</label>
      <input id="termin_wykorzystania" name="termin_wykorzystania" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['termin_wykorzystania'] ?? '') ?>">
    </div>
    <div>
      <label for="termin_sprawozdania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin złożenia sprawozdania końcowego</label>
      <input id="termin_sprawozdania" name="termin_sprawozdania" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['termin_sprawozdania'] ?? '') ?>">
    </div>
  </div>
</div>

<!-- TAB 4: Podpisanie i pliki -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-6">
    <div class="lg:tw-col-span-2">

      <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-pen"></i> Forma podpisania</h2>
      <div class="tw-mb-4 sm:tw-w-1/2">
        <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
        <select id="forma_podpisania" name="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
          <option value="">—</option>
          <option value="papierowa" <?= $row['forma_podpisania'] === 'papierowa' ? 'selected' : '' ?>>Papierowa</option>
          <option value="elektroniczna" <?= $row['forma_podpisania'] === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna</option>
          <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
        </select>
      </div>

      <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= $row['forma_podpisania'] === 'elektroniczna' ? '' : 'none' ?>">
        <div>
          <label for="platforma_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Platforma</label>
          <input id="platforma_el" name="platforma_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['platforma_el']) ?>">
        </div>
        <div>
          <label for="id_dokumentu_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">ID dokumentu</label>
          <input id="id_dokumentu_el" name="id_dokumentu_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['id_dokumentu_el']) ?>">
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

      <div id="epodpis_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= $row['forma_podpisania'] === 'epodpis_kwalifikowany' ? '' : 'none' ?>">
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

      <div>
        <label for="uwagi" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Uwagi</label>
        <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['uwagi']) ?></textarea>
      </div>
    </div>

    <div class="lg:tw-col-span-1 tw-space-y-4">
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-paperclip"></i> Pliki</h2>
        <?php if ($row['plik_umowy']): ?>
        <div class="tw-mb-2 tw-text-sm"><?= upload_link($row['plik_umowy']) ?></div>
        <label for="plik_umowy" class="tw-block tw-text-xs tw-text-slate-500 tw-mb-1">Zastąp nowym plikiem:</label>
        <?php else: ?>
        <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF/DOCX)</label>
        <?php endif; ?>
        <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
               class="tw-w-full tw-text-sm tw-text-slate-600 tw-mb-3 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">

        <label for="zalaczniki" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Załączniki</label>
        <?php if ($row['zalaczniki']): ?>
        <div class="tw-mb-1 tw-text-sm"><?= upload_link($row['zalaczniki']) ?></div>
        <?php endif; ?>
        <input id="zalaczniki" name="zalaczniki" type="file" accept=".pdf,.docx,.jpg,.png"
               class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
      </div>
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-shield-lock"></i> Dostęp</h2>
        <?= contract_access_field_html(contract_access_user_ids($TYPE, $id)) ?>
      </div>
    </div>
  </div>
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
document.getElementById('forma_zlecenia').addEventListener('change', function () {
    document.getElementById('wsparcie_fields').style.display = this.value === 'wsparcie' ? '' : 'none';
});
document.getElementById('forma_podpisania').addEventListener('change', function () {
    var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
