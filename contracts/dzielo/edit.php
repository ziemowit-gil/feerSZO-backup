<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin','editor');

// Moduł w przygotowaniu — blokuj dodawanie/edycję
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('dzielo') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('warning', 'Moduł dzielo jest w przygotowaniu. Dodawanie i edycja są tymczasowo wyłączone.');
    header('Location: list.php'); exit;
}
$TYPE  = 'dzielo';
$TABLE = 'umowy_dzielo';
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
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/dzielo/edit.php?id=' . $id);

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
        foreach (['kup50','prawa_autorskie','wymagany_protokol','dzielo_przyjete','m365_konto'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        foreach (['wynagrodzenie_brutto','zaliczka_podatek'] as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $data['plik_umowy']          = $plik_umowy  ?: $row['plik_umowy'];
        $data['plik_potwierdzenia']  = $plik_potw   ?: $row['plik_potwierdzenia'];

        $data['updated_at'] = date('Y-m-d H:i:s');

        $allowed = ['numer_umowy','status','imie_nazwisko','pesel','adres','email','urzad_skarbowy',
            'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
            'rachunek_bankowy','opis_dziela','termin_oddania','data_zawarcia',
            'wynagrodzenie_brutto','kup50','zaliczka_podatek','prawa_autorskie','zakres_praw',
            'wymagany_protokol','data_odbioru','dzielo_przyjete','data_zl_rachunku',
            'numer_projektu','opiekun','forma_podpisania','platforma_el','id_dokumentu_el',
            'plik_potwierdzenia','plik_umowy','uwagi','updated_at',
            'm365_konto','m365_login','m365_user_id','m365_konto_aktywne','m365_data_utworzenia','m365_licencja_przypisana',
            'nr_roboczy','nr_system','nr_rejestru','person_id','org_unit_id',
            'podpisujacy_fundacja','podpisujacy_stanowisko'];
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

<form method="post" enctype="multipart/form-data" novalidate x-data="tabbedContractForm(5)" @submit="onSubmit($event)"
      class="tw-bg-white tw-rounded-2xl tw-border tw-border-slate-200 tw-shadow-sm tw-overflow-hidden">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Zakładki -->
<div class="tw-flex tw-gap-1 tw-overflow-x-auto tw-border-b tw-border-slate-200 tw-bg-slate-50 tw-px-3 tw-pt-2" role="tablist" aria-label="Sekcje formularza umowy">
  <?php $_tabs = ['Umowa', 'Wykonawca', 'Dzieło i wynagrodzenie', 'Podpisanie i pliki', 'M365 i uwagi']; ?>
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
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-6">
    <div>
      <label for="numer_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer umowy <span class="tw-text-red-500">*</span></label>
      <input id="numer_umowy" name="numer_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_umowy']) ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach(['projekt','podpisana','w realizacji','zakończona','rozwiązana','anulowana'] as $s):
          $sel = $row['status']===$s?'selected':''; ?>
        <option value="<?= h($s) ?>" <?= $sel ?>><?= h(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zawarcia']) ?>">
    </div>
  </div>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
    <div>
      <label for="numer_projektu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer projektu</label>
      <input id="numer_projektu" name="numer_projektu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_projektu']) ?>">
    </div>
    <div>
      <label for="opiekun" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun umowy</label>
      <input id="opiekun" name="opiekun" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['opiekun']) ?>">
    </div>
  </div>
</div>

<!-- TAB 2: Wykonawca -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-person"></i> Wykonawca</h2>

  <div class="tw-mb-4">
    <label for="person_search" class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1">Osoba powiązana w rejestrze</label>
    <div class="tw-relative tw-flex tw-gap-2">
      <input type="text" id="person_search"
             class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="Szukaj po imieniu, PESEL lub email…"
             value="<?= h($row['_person_name'] ?? '') ?>"
             autocomplete="off">
      <a href="<?= APP_URL ?>/persons/add.php" class="tw-inline-flex tw-items-center tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-text-slate-600 hover:tw-bg-slate-50 tw-flex-shrink-0" target="_blank" title="Dodaj nową osobę">
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

  <div class="tw-mb-4">
    <label for="ceidgNipInput" class="tw-block tw-text-sm tw-font-medium tw-text-slate-500 tw-mb-1">Szybkie uzupełnienie z CEIDG (dla JDG)</label>
    <div class="tw-flex tw-gap-2 tw-max-w-md">
      <div class="tw-relative tw-flex-1">
        <i class="bi bi-building-check tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400"></i>
        <input type="text" id="ceidgNipInput" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-pl-9 tw-pr-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="NIP działalności (10 cyfr)" maxlength="13">
      </div>
      <button type="button" id="ceidgBtn" onclick="ceidgSearch()"
              class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50 tw-flex-shrink-0">
        <i class="bi bi-search"></i> CEIDG
      </button>
    </div>
    <div id="ceidgResult"></div>
  </div>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div class="sm:tw-col-span-2">
      <label for="imie_nazwisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Imię i nazwisko</label>
      <input id="imie_nazwisko" name="imie_nazwisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['imie_nazwisko']) ?>">
      <?= byli_check_field('imie_nazwisko') ?>
    </div>
    <div>
      <label for="pesel" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">PESEL</label>
      <input id="pesel" name="pesel" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             maxlength="11" value="<?= h($row['pesel']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1"><i class="bi bi-house me-1 tw-text-slate-400"></i>Adres zamieszkania / siedziby</label>
      <?= address_widget($row) ?>
    </div>
    <div>
      <label for="email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres e-mail kontrahenta</label>
      <input type="email" name="email" id="email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email'])?>">
    </div>
    <div class="sm:tw-col-span-2">
      <label for="urzad_skarbowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Urząd skarbowy</label>
      <input id="urzad_skarbowy" name="urzad_skarbowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['urzad_skarbowy']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="rachunek_bankowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy</label>
      <input id="rachunek_bankowy" name="rachunek_bankowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['rachunek_bankowy']) ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="m365_login" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Email do logowania w panelu</label>
      <div class="tw-relative">
        <i class="bi bi-envelope tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400"></i>
        <input id="m365_login" name="m365_login" type="email"
               class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-pl-9 tw-pr-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['m365_login'] ?? '') ?>"
               placeholder="imie.nazwisko@feer.org.pl  lub  prywatny@email.com">
      </div>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Adres Microsoft 365 (<code>@feer.org.pl</code>) lub prywatny e-mail — umożliwia dostęp do panelu umów.</p>
    </div>
  </div>
</div>

<!-- TAB 3: Dzieło i wynagrodzenie -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-file-earmark-text"></i> Opis i wynagrodzenie</h2>
  <div class="tw-mb-4">
    <label for="opis_dziela" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opis dzieła</label>
    <textarea id="opis_dziela" name="opis_dziela" rows="4" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['opis_dziela']) ?></textarea>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="termin_oddania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin oddania</label>
      <input id="termin_oddania" name="termin_oddania" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['termin_oddania']) ?>">
    </div>
    <div>
      <label for="wynagrodzenie_brutto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wynagrodzenie brutto (PLN)</label>
      <input id="wynagrodzenie_brutto" name="wynagrodzenie_brutto" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wynagrodzenie_brutto']) ?>">
    </div>
    <div>
      <label for="zaliczka_podatek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zaliczka na podatek (PLN)</label>
      <input id="zaliczka_podatek" name="zaliczka_podatek" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['zaliczka_podatek']) ?>">
    </div>
  </div>
  <div class="tw-mb-6">
    <label for="kup50" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="kup50" id="kup50" value="1" <?= $row['kup50']?'checked':'' ?>>
      50% koszty uzyskania przychodu (KUP)
    </label>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-c-circle"></i> Prawa autorskie</h2>
  <div class="tw-mb-6">
    <label for="prawa_autorskie" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700 tw-mb-3">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="prawa_autorskie" id="prawa_autorskie" value="1"
        <?= $row['prawa_autorskie']?'checked':'' ?> onchange="toggleZakres(this)">
      Przeniesienie praw autorskich
    </label>
    <div id="zakres_praw_field" class="<?= $row['prawa_autorskie']?'':'tw-hidden' ?>">
      <label for="zakres_praw" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zakres praw autorskich</label>
      <textarea id="zakres_praw" name="zakres_praw" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['zakres_praw']) ?></textarea>
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-check2-circle"></i> Odbiór dzieła</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-4 tw-gap-4 tw-items-start">
    <div class="tw-pt-6">
      <label for="wymagany_protokol" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="wymagany_protokol" id="wymagany_protokol" value="1" <?= $row['wymagany_protokol']?'checked':'' ?>>
        Wymagany protokół odbioru
      </label>
    </div>
    <div>
      <label for="data_odbioru" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data odbioru</label>
      <input id="data_odbioru" name="data_odbioru" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_odbioru']) ?>">
    </div>
    <div class="tw-pt-6">
      <label for="dzielo_przyjete" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="dzielo_przyjete" id="dzielo_przyjete" value="1" <?= $row['dzielo_przyjete']?'checked':'' ?>>
        Dzieło przyjęte
      </label>
    </div>
    <div>
      <label for="data_zl_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data złożenia rachunku</label>
      <input id="data_zl_rachunku" name="data_zl_rachunku" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zl_rachunku']) ?>">
    </div>
  </div>
</div>

<!-- TAB 4: Podpisanie i pliki -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-6">
    <div class="lg:tw-col-span-2">

      <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-pen"></i> Forma podpisania</h2>
      <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
        <div>
          <label for="podpisujacy_fundacja" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Podpisuje ze strony fundacji</label>
          <input id="podpisujacy_fundacja" name="podpisujacy_fundacja" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['podpisujacy_fundacja'] ?? '') ?>" placeholder="Imię i nazwisko">
        </div>
        <div>
          <label for="podpisujacy_stanowisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Stanowisko / funkcja</label>
          <input id="podpisujacy_stanowisko" name="podpisujacy_stanowisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['podpisujacy_stanowisko'] ?? '') ?>" placeholder="np. Prezes Zarządu">
        </div>
        <div>
          <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
          <select name="forma_podpisania" id="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
            <option value="">—</option>
            <option value="papierowa" <?= $row['forma_podpisania']==='papierowa'?'selected':'' ?>>Papierowa</option>
            <option value="elektroniczna" <?= $row['forma_podpisania']==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
            <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
          </select>
        </div>
      </div>

      <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4 <?= $row['forma_podpisania']==='elektroniczna'?'':'tw-hidden' ?>">
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

      <div id="epodpis_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4 tw-hidden">
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
    </div>

    <div class="lg:tw-col-span-1 tw-space-y-4">
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-paperclip"></i> Plik umowy</h2>
        <?php if ($row['plik_umowy']): ?>
        <div class="tw-mb-2 tw-text-sm"><?= upload_link($row['plik_umowy']) ?></div>
        <label for="plik_umowy" class="tw-block tw-text-xs tw-text-slate-500 tw-mb-1">Zastąp nowym plikiem:</label>
        <?php else: ?>
        <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF)</label>
        <?php endif; ?>
        <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
               class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
      </div>
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-shield-lock"></i> Dostęp</h2>
        <?= contract_access_field_html(contract_access_user_ids($TYPE, $id)) ?>
      </div>
    </div>
  </div>
</div>

<!-- TAB 5: M365 i uwagi -->
<div data-tab-pane="5" x-show="tab===5" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-microsoft"></i> Microsoft 365</h2>
  <div class="tw-mb-6">
    <label for="m365_konto" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700 tw-mb-3">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="m365_konto" id="m365_konto" value="1" <?= !empty($row['m365_konto'])?'checked':'' ?>>
      Konto M365 zostało utworzone
    </label>
    <div id="m365_manual_fields" class="<?= !empty($row['m365_konto'])?'':'tw-hidden' ?>">
      <div class="sm:tw-w-1/2">
        <label for="m365_user_id" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">User ID (Azure AD)</label>
        <input id="m365_user_id" name="m365_user_id" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['m365_user_id']??'') ?>">
      </div>
    </div>
    <p class="tw-mt-2 tw-text-xs tw-text-slate-500">Konto można też <a href="#" class="tw-text-blue-600 hover:tw-underline">utworzyć automatycznie</a> po zapisaniu umowy z widoku szczegółów.</p>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-chat-left-text"></i> Uwagi</h2>
  <div>
    <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['uwagi']) ?></textarea>
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
document.getElementById('forma_podpisania').addEventListener('change', function() {
  var _fp = this.value;
  document.getElementById('el_fields').classList.toggle('tw-hidden', _fp !== 'elektroniczna');
  document.getElementById('epodpis_fields').classList.toggle('tw-hidden', _fp !== 'epodpis_kwalifikowany');
});
function toggleZakres(cb) {
  document.getElementById('zakres_praw_field').classList.toggle('tw-hidden', !cb.checked);
}
document.getElementById('m365_konto').addEventListener('change', function() {
  document.getElementById('m365_manual_fields').classList.toggle('tw-hidden', !this.checked);
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
  var adres = d.adres || '';
  var streetFld = document.querySelector('[name=addr_street]');
  var houseFld  = document.querySelector('[name=addr_house]');
  var flatFld   = document.querySelector('[name=addr_flat]');
  var postalFld = document.querySelector('[name=addr_postal]');
  var cityFld   = document.querySelector('[name=addr_city]');
  if (streetFld && adres) {
    var mPostal = adres.match(/(\d{2}-\d{3})\s+(.+)$/);
    if (mPostal) { postalFld.value = mPostal[1]; cityFld.value = mPostal[2].trim(); adres = adres.replace(/,?\s*\d{2}-\d{3}\s+.+$/, '').trim(); }
    var mHouse = adres.match(/^(.*?)\s+([\d][\w\/\-]*)$/);
    if (mHouse) { var mFlat = mHouse[2].match(/^(\d+)\/(\d+)$/); if(mFlat){houseFld.value=mFlat[1];if(flatFld)flatFld.value=mFlat[2];}else houseFld.value=mHouse[2]; streetFld.value=mHouse[1]; }
    else streetFld.value = adres;
  }
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
