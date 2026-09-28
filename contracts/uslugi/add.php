<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_role('admin','editor');
require_once dirname(dirname(__DIR__)) . '/includes/rodo.php';
require_module_enabled('contract_uslugi', 'Ten typ umowy');
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/uslugi/add.php');
$PAGE_TITLE = 'Nowa umowa o świadczenie usług';
$TYPE  = 'uslugi';
$TABLE = 'umowy_uslugi';
$errors = [];
$row = ['numer_umowy' => next_contract_number($TYPE), 'status' => 'projekt', 'waluta' => 'PLN'];

// ── Wstępne wypełnienie z oferty CRM (konwersja 1-kliknięciem) ───────────────
// crm/offers/action.php buduje adres z parametrami — przenosimy tylko pola,
// które istnieją w formularzu, żeby nie dało się wstrzyknąć obcych kolumn.
$offer_id = (int)($_GET['offer_id'] ?? $_POST['offer_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $offer_id) {
    $prefill_allowed = ['nazwa_wykonawcy','nip_pesel','adres','email','telefon','przedmiot_uslugi',
        'zakres_uslug','wartosc_netto','wartosc_brutto','stawka_vat','waluta','termin_platnosci_dni'];
    foreach ($prefill_allowed as $pf) {
        if (isset($_GET[$pf]) && $_GET[$pf] !== '') $row[$pf] = $_GET[$pf];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    if (empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        // Pola checkboxowe
        foreach (['czas_nieokreslony','wymagana_faktura','wymagany_protokol'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Upload
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        if ($plik_umowy) $row['plik_umowy'] = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia'] = $plik_potw;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        // Usuń puste pola liczbowe
        foreach (['wartosc_netto','wartosc_brutto','stawka_vat','termin_platnosci_dni','kwota_rozliczona'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }

        // Tylko kolumny z tabeli
        $allowed = ['numer_umowy','status','nazwa_wykonawcy','nip_pesel','adres','email','telefon','rachunek_lub_faktura',
            'przedmiot_uslugi','zakres_uslug','data_zawarcia','data_rozpoczecia','data_zakonczenia',
            'czas_nieokreslony','okres_wypowiedzenia','wartosc_netto','wartosc_brutto','stawka_vat',
            'waluta','harmonogram_platnosci','termin_platnosci_dni','numer_projektu','wymagana_faktura',
            'opiekun','wymagany_protokol','data_odbioru','status_rozliczenia','kwota_rozliczona','data_rozliczenia',
            'forma_podpisania','platforma_el',
            'id_dokumentu_el','plik_potwierdzenia','plik_umowy','zalaczniki','uwagi',
            'created_by','created_at','updated_at',
            'nr_roboczy','nr_system','nr_rejestru'];
        $data = array_intersect_key($row, array_flip($allowed));

        assign_nr_rejestru($data);
        $id = db_insert($TABLE, $data);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'create', 'Dodano: ' . ($data['numer_umowy'] ?? ''));
        if (!empty($_POST['rodo_grant'])) {
            try {
                rodo_migrate();
                rodo_quick_create($TYPE, (int)$id, [
                    'numer_umowy'      => $data['numer_umowy']   ?? '',
                    'data_zawarcia'    => $data['data_zawarcia'] ?? '',
                    'imie_nazwisko'    => $data['nazwa_wykonawcy'] ?? '',
                    'pesel'            => (strlen(preg_replace('/\D/', '', $data['nip_pesel'] ?? '')) === 11 ? preg_replace('/\D/', '', $data['nip_pesel']) : ''),
                    'scope_items'      => $_POST['scope_items']  ?? [],
                    'scope_custom'     => $_POST['rodo_scope_custom']     ?? '',
                    'authorized_until' => $_POST['rodo_authorized_until'] ?? '',
                ]);
            } catch (\Throwable $e) { error_log('[uslugi/add rodo] ' . $e->getMessage()); }
        }
        if (isset($_POST['nie_mam_drukarki'])) {
            require_once dirname(dirname(__DIR__)) . '/contracts/includes/pdf_queue.php';
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['nazwa_wykonawcy'] ?? '', current_user()['id']);
        }
        try {
            require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
            crm_migrate();
            CrmManager::autoCreateContractCase($TYPE, $id, $data['numer_umowy'] ?? '', $data, (int)(current_user()['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[crm_case_auto] ' . $e->getMessage());
        }
        if ($offer_id) {
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';
                crm_offer_link_contract($offer_id, $id, $TYPE);
            } catch (\Throwable $e) {
                error_log('[crm_offer_link] ' . $e->getMessage());
            }
        }
        flash_set('success', 'Umowa o świadczenie usług została dodana.');
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}");
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="tw-max-w-6xl tw-mx-auto">

<div class="tw-flex tw-items-center tw-justify-between tw-mb-4 tw-flex-wrap tw-gap-2">
  <h1 class="tw-text-xl tw-font-semibold tw-text-slate-800 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-plus-circle tw-text-blue-600"></i> Nowa umowa o świadczenie usług
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

<?php if ($offer_id): ?>
<div class="tw-rounded-lg tw-border tw-border-blue-200 tw-bg-blue-50 tw-text-blue-800 tw-px-4 tw-py-3 tw-mb-4 tw-text-sm tw-flex tw-items-center tw-gap-2">
  <i class="bi bi-file-earmark-ruled-fill"></i>
  <div class="tw-flex-1">Dane wypełnione z oferty CRM — po zapisaniu umowa zostanie do niej dowiązana.</div>
  <a class="tw-inline-flex tw-items-center tw-rounded-lg tw-border tw-border-blue-300 tw-bg-white tw-px-3 tw-py-1.5 tw-text-sm tw-font-medium tw-text-blue-700 hover:tw-bg-blue-50" href="<?= APP_URL ?>/crm/offers/view.php?id=<?= (int)$offer_id ?>">Otwórz ofertę</a>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate x-data="tabbedContractForm(4)" @submit="onSubmit($event)"
      class="tw-bg-white tw-rounded-2xl tw-border tw-border-slate-200 tw-shadow-sm tw-overflow-hidden">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<?php if ($offer_id): ?><input type="hidden" name="offer_id" value="<?= (int)$offer_id ?>"><?php endif; ?>

<!-- Zakładki -->
<div class="tw-flex tw-gap-1 tw-overflow-x-auto tw-border-b tw-border-slate-200 tw-bg-slate-50 tw-px-3 tw-pt-2" role="tablist" aria-label="Sekcje formularza umowy">
  <?php $_tabs = ['Umowa', 'Wykonawca', 'Finanse', 'Podpisanie i pliki']; ?>
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
             value="<?= h($row['numer_umowy']??'') ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach(['projekt'=>'Projekt','do podpisu'=>'Do podpisu','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zawieszona'=>'Zawieszona','do rozliczenia'=>'Do rozliczenia','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $k=>$v):
          $sel = ($row['status']??'')===$k?'selected':''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="opiekun" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun umowy</label>
      <input id="opiekun" name="opiekun" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['opiekun']??'') ?>">
    </div>
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zawarcia']??'') ?>">
    </div>
    <div>
      <label for="data_rozpoczecia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rozpoczęcia</label>
      <input id="data_rozpoczecia" name="data_rozpoczecia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_rozpoczecia']??'') ?>">
    </div>
    <div>
      <label for="data_zakonczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zakończenia</label>
      <input name="data_zakonczenia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none disabled:tw-bg-slate-100" id="data_zakonczenia" value="<?= h($row['data_zakonczenia']??'') ?>"
        <?= !empty($row['czas_nieokreslony']) ? 'disabled' : '' ?>>
    </div>
    <div class="tw-flex tw-items-end tw-pb-2">
      <label for="czas_nieokreslony" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="czas_nieokreslony" id="czas_nieokreslony" value="1"
          <?= !empty($row['czas_nieokreslony'])?'checked':'' ?>>
        Czas nieokreślony
      </label>
    </div>
    <div>
      <label for="okres_wypowiedzenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Okres wypowiedzenia</label>
      <input id="okres_wypowiedzenia" name="okres_wypowiedzenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. 30 dni" value="<?= h($row['okres_wypowiedzenia']??'') ?>">
    </div>
    <div>
      <label for="numer_projektu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer projektu / źródło finansowania</label>
      <input id="numer_projektu" name="numer_projektu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_projektu']??'') ?>">
    </div>
  </div>
  <div class="tw-mt-4">
    <label for="przedmiot_uslugi" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Przedmiot usługi</label>
    <textarea id="przedmiot_uslugi" name="przedmiot_uslugi" rows="2" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['przedmiot_uslugi']??'') ?></textarea>
  </div>
  <div class="tw-mt-4">
    <label for="zakres_uslug" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zakres usług</label>
    <textarea id="zakres_uslug" name="zakres_uslug" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['zakres_uslug']??'') ?></textarea>
  </div>
</div>

<!-- TAB 2: Wykonawca -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-building"></i> Wykonawca</h2>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mb-4 tw-pb-4 tw-border-b tw-border-slate-200">
    <div>
      <label class="tw-block tw-text-xs tw-font-medium tw-text-slate-500 tw-mb-1">JDG — szukaj po NIP w CEIDG</label>
      <div class="tw-flex tw-gap-2">
        <input type="text" id="ceidgNipInput" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="NIP (10 cyfr)" maxlength="13">
        <button type="button" class="tw-inline-flex tw-items-center tw-gap-1 tw-whitespace-nowrap tw-rounded-lg tw-border tw-border-blue-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-font-medium tw-text-blue-700 hover:tw-bg-blue-50" id="ceidgBtn" onclick="ceidgSearch()">
          <i class="bi bi-search"></i> CEIDG
        </button>
      </div>
      <div id="ceidgResult" class="tw-mt-1"></div>
    </div>
    <div>
      <label class="tw-block tw-text-xs tw-font-medium tw-text-slate-500 tw-mb-1">Spółka / org. — szukaj po nr KRS</label>
      <div class="tw-flex tw-gap-2">
        <input type="text" id="krsInput" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="Nr KRS (10 cyfr)" maxlength="10">
        <button type="button" class="tw-inline-flex tw-items-center tw-gap-1 tw-whitespace-nowrap tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-font-medium tw-text-slate-700 hover:tw-bg-slate-50" id="krsBtn" onclick="krsSearch()">
          <i class="bi bi-search"></i> KRS
        </button>
      </div>
      <div id="krsResult" class="tw-mt-1"></div>
    </div>
  </div>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
    <div>
      <label for="nazwa_wykonawcy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nazwa wykonawcy</label>
      <input id="nazwa_wykonawcy" name="nazwa_wykonawcy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nazwa_wykonawcy']??'') ?>"><?= byli_check_field('nazwa_wykonawcy') ?>
    </div>
    <div>
      <label for="nip_pesel" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">NIP / PESEL</label>
      <input id="nip_pesel" name="nip_pesel" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nip_pesel']??'') ?>">
    </div>
  </div>
  <div class="tw-mt-4">
    <label for="adres" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres</label>
    <input id="adres" name="adres" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
           value="<?= h($row['adres']??'') ?>">
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mt-4">
    <div>
      <label for="email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Adres e-mail kontrahenta</label>
      <input id="email" type="email" name="email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email']??'')?>">
    </div>
    <div>
      <label for="telefon" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Telefon kontaktowy</label>
      <input id="telefon" name="telefon" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. +48 600 100 200" value="<?= h($row['telefon']??'')?>">
    </div>
  </div>
  <div class="tw-mt-4">
    <label for="rachunek_lub_faktura" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy / dane do faktury</label>
    <input id="rachunek_lub_faktura" name="rachunek_lub_faktura" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
           placeholder="Nr rachunku lub dane do faktury" value="<?= h($row['rachunek_lub_faktura']??'') ?>">
  </div>
</div>

<!-- TAB 3: Finanse -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-currency-exchange"></i> Warunki finansowe</h2>
  <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-4 tw-gap-4 tw-mb-4">
    <div>
      <label for="wartosc_netto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wartość netto (PLN)</label>
      <input id="wartosc_netto" name="wartosc_netto" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wartosc_netto']??'') ?>">
    </div>
    <div>
      <label for="stawka_vat" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">VAT (%)</label>
      <input id="stawka_vat" name="stawka_vat" type="number" step="0.01" min="0" max="100" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="23" value="<?= h($row['stawka_vat']??'') ?>">
    </div>
    <div>
      <label for="wartosc_brutto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wartość brutto (PLN)</label>
      <input id="wartosc_brutto" name="wartosc_brutto" type="number" step="0.01" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wartosc_brutto']??'') ?>">
    </div>
    <div>
      <label for="waluta" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Waluta</label>
      <input id="waluta" name="waluta" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             maxlength="3" value="<?= h($row['waluta']??'PLN') ?>">
    </div>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="harmonogram_platnosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Harmonogram płatności</label>
      <select id="harmonogram_platnosci" name="harmonogram_platnosci" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="">—</option>
        <option value="jednorazowo" <?= ($row['harmonogram_platnosci']??'')==='jednorazowo'?'selected':'' ?>>Jednorazowo</option>
        <option value="miesięcznie" <?= ($row['harmonogram_platnosci']??'')==='miesięcznie'?'selected':'' ?>>Miesięcznie</option>
        <option value="transze" <?= ($row['harmonogram_platnosci']??'')==='transze'?'selected':'' ?>>Transze</option>
      </select>
    </div>
    <div>
      <label for="termin_platnosci_dni" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin płatności (dni)</label>
      <input id="termin_platnosci_dni" name="termin_platnosci_dni" type="number" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="np. 14" value="<?= h($row['termin_platnosci_dni']??'') ?>">
    </div>
    <div class="tw-flex tw-items-end tw-pb-2">
      <label for="wymagana_faktura" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="wymagana_faktura" id="wymagana_faktura" value="1" <?= !empty($row['wymagana_faktura'])?'checked':'' ?>>
        Wymagana faktura VAT
      </label>
    </div>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div class="tw-flex tw-items-end tw-pb-2">
      <label for="wymagany_protokol" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="wymagany_protokol" id="wymagany_protokol" value="1" <?= !empty($row['wymagany_protokol'])?'checked':'' ?>>
        Wymagany protokół odbioru
      </label>
    </div>
    <div>
      <label for="data_odbioru" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data odbioru</label>
      <input id="data_odbioru" name="data_odbioru" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_odbioru']??'') ?>">
    </div>
  </div>
  <hr class="tw-my-4 tw-border-slate-200">
  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-check2-square"></i> Rozliczenie</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div>
      <label for="status_rozliczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Stan rozliczenia</label>
      <select id="status_rozliczenia" name="status_rozliczenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach(['nierozliczone'=>'Nierozliczone','częściowo'=>'Częściowo rozliczone','rozliczone'=>'Rozliczone'] as $k=>$v):
          $sel = ($row['status_rozliczenia']??'nierozliczone')===$k?'selected':''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="kwota_rozliczona" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Kwota rozliczona</label>
      <input id="kwota_rozliczona" name="kwota_rozliczona" type="number" step="0.01" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['kwota_rozliczona']??'') ?>">
    </div>
    <div>
      <label for="data_rozliczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rozliczenia</label>
      <input id="data_rozliczenia" name="data_rozliczenia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_rozliczenia']??'') ?>">
    </div>
  </div>
</div>

<!-- TAB 4: Podpisanie i pliki -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-6">
    <div class="lg:tw-col-span-2">

      <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-pen"></i> Podpisanie</h2>
      <div class="tw-mb-4 sm:tw-w-1/2">
        <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
        <select name="forma_podpisania" id="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
          <option value="">—</option>
          <option value="papierowa" <?= ($row['forma_podpisania']??'')==='papierowa'?'selected':'' ?>>Papierowa</option>
          <option value="elektroniczna" <?= ($row['forma_podpisania']??'')==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
          <option value="epodpis_kwalifikowany" <?= (($row['forma_podpisania'] ?? '') === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
        </select>
      </div>

      <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= ($row['forma_podpisania']??'')==='elektroniczna'?'':'none' ?>">
        <div>
          <label for="platforma_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Platforma</label>
          <input id="platforma_el" name="platforma_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 placeholder="Autenti / inny" value="<?= h($row['platforma_el']??'') ?>">
        </div>
        <div>
          <label for="id_dokumentu_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">ID dokumentu w systemie</label>
          <input id="id_dokumentu_el" name="id_dokumentu_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['id_dokumentu_el']??'') ?>">
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

      <div>
        <label for="uwagi" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Uwagi</label>
        <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['uwagi']??'') ?></textarea>
      </div>

      <div>
        <?php rodo_grant_form_section(); ?>
      </div>
    </div>

    <div class="lg:tw-col-span-1 tw-space-y-4">
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-paperclip"></i> Pliki</h2>
        <div class="tw-mb-3">
          <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF/DOCX, max 20MB)</label>
          <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
                 class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
        </div>
        <div>
          <label for="zalaczniki" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Załączniki (opcjonalnie)</label>
          <input id="zalaczniki" name="zalaczniki" type="file" accept=".pdf,.docx,.xlsx,.zip"
                 class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
          <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Dodatkowe dokumenty, aneksy itp.</p>
        </div>
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
