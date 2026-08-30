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
require_module_enabled('contract_dzielo', 'Ten typ umowy');

require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('dzielo') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('warning', 'Moduł dzielo jest w przygotowaniu. Dodawanie i edycja są tymczasowo wyłączone.');
    header('Location: list.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/dzielo/add.php');

$PAGE_TITLE = 'Nowa umowa o dzieło';
$TYPE  = 'dzielo';
$TABLE = 'umowy_dzielo';
$errors = [];
$row = ['numer_umowy' => next_contract_number($TYPE), 'status' => 'projekt'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    $is_draft = isset($_POST['zapisz_roboczo']);
    if ($is_draft && empty($row['status'])) $row['status'] = 'projekt';
    if (empty($row['numer_umowy'])) $row['numer_umowy'] = next_contract_number($TYPE);

    if (empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        foreach (['kup50','prawa_autorskie','wymagany_protokol','dzielo_przyjete','m365_konto'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        if ($plik_umowy) $row['plik_umowy'] = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia'] = $plik_potw;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        foreach (['wynagrodzenie_brutto','zaliczka_podatek'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }

        $allowed = ['numer_umowy','status','imie_nazwisko','pesel','adres','email','urzad_skarbowy',
            'rachunek_bankowy','opis_dziela','termin_oddania','data_zawarcia',
            'wynagrodzenie_brutto','kup50','zaliczka_podatek','prawa_autorskie','zakres_praw',
            'wymagany_protokol','data_odbioru','dzielo_przyjete','data_zl_rachunku',
            'numer_projektu','opiekun','forma_podpisania','platforma_el','id_dokumentu_el',
            'epodpis_dostawca','epodpis_nr_certyfikatu','epodpis_data_waznosci',
            'podpisujacy_fundacja','podpisujacy_stanowisko',
            'plik_potwierdzenia','plik_umowy','uwagi','created_by','created_at','updated_at',
            'm365_konto','m365_login','m365_user_id','m365_konto_aktywne','m365_data_utworzenia','m365_licencja_przypisana',
            'nr_roboczy','nr_system','nr_rejestru','person_id','org_unit_id'];
        $data = array_intersect_key($row, array_flip($allowed));
        foreach (['person_id','org_unit_id'] as $fk) {
            if (isset($data[$fk]) && $data[$fk] === '') $data[$fk] = null;
        }

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
        } catch (\Throwable $e) { error_log('[crm_case_auto] ' . $e->getMessage()); }

        if ($is_draft) {
            flash_set('info', 'Zapisano roboczo — możesz wrócić i dokończyć umowę.');
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/edit.php?id={$id}");
            exit;
        }
        flash_set('success', 'Umowa o dzieło została dodana.');
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}");
        exit;
    }
}

$_units = [];
try { $_units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name"); } catch(\Throwable $e) {}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="tw-max-w-6xl tw-mx-auto">

<div class="tw-flex tw-items-center tw-justify-between tw-mb-4 tw-flex-wrap tw-gap-2">
  <h1 class="tw-text-xl tw-font-semibold tw-text-slate-800 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-palette tw-text-blue-600"></i> Nowa umowa o dzieło
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
      <input id="numer_umowy" name="numer_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_umowy']??'') ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach(['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $k=>$v):
          $sel = ($row['status']??'')===$k?'selected':''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">projekt → podpisana → w realizacji → zakończona</p>
    </div>
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zawarcia']??'') ?>">
    </div>
  </div>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
    <div>
      <label for="numer_projektu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer projektu / źródło finansowania</label>
      <input id="numer_projektu" name="numer_projektu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['numer_projektu']??'') ?>">
    </div>
    <div>
      <label for="opiekun" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun umowy</label>
      <input id="opiekun" name="opiekun" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['opiekun']??'') ?>">
    </div>
  </div>
</div>

<!-- TAB 2: Wykonawca -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-person"></i> Wykonawca</h2>

  <!-- Person picker -->
  <div class="tw-mb-4">
    <?= person_picker($row, [
      'id'          => 'dzpp',
      'label'       => 'Wypełnij z kartoteki osób',
      'fill'        => [
        'imie_nazwisko' => 'dz_imie',
        'pesel'         => 'dz_pesel',
        'email'         => 'dz_email',
        'urzad_skarbowy'=> 'dz_urzad',
        'rachunek_bankowy' => 'dz_rachunek',
      ],
      'addr_widget' => 'dzieloAddrWidget',
    ]) ?>
    <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Wybierz osobę z rejestru — dane uzupełnią się automatycznie. Opcjonalne.</p>
  </div>

  <!-- CEIDG -->
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
      <label for="dz_imie" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Imię i nazwisko</label>
      <input name="imie_nazwisko" id="dz_imie" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['imie_nazwisko']??'') ?>">
      <?= byli_check_field('imie_nazwisko') ?>
    </div>
    <div>
      <label for="dz_pesel" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">PESEL</label>
      <input name="pesel" id="dz_pesel" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             maxlength="11" pattern="\d{11}" value="<?= h($row['pesel']??'') ?>">
    </div>
    <div>
      <label for="org_unit_id" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Komórka organizacyjna</label>
      <select id="org_unit_id" name="org_unit_id" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="">— wybierz —</option>
        <?php foreach ($_units as $u): ?>
        <option value="<?= h($u['id']) ?>" <?= ($row['org_unit_id']??'')==$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:tw-col-span-3">
      <label class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1"><i class="bi bi-house me-1 tw-text-slate-400"></i>Adres zamieszkania / siedziby</label>
      <?= address_widget($row, ['copy_button'=>true,'autocomplete'=>true,'widget_id'=>'dzieloAddrWidget']) ?>
    </div>
    <div class="sm:tw-col-span-2">
      <label for="dz_email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">E-mail kontrahenta</label>
      <input type="email" name="email" id="dz_email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="jan.kowalski@email.pl" value="<?= h($row['email']??'') ?>">
    </div>
    <div>
      <label for="dz_urzad" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Urząd skarbowy</label>
      <input name="urzad_skarbowy" id="dz_urzad" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['urzad_skarbowy']??'') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="dz_rachunek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy</label>
      <input name="rachunek_bankowy" id="dz_rachunek" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy']??'') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="m365_login" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">E-mail do logowania w panelu</label>
      <div class="tw-relative">
        <i class="bi bi-envelope tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400"></i>
        <input id="m365_login" name="m365_login" type="email"
               class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-pl-9 tw-pr-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['m365_login']??'') ?>"
               placeholder="imie.nazwisko@feer.org.pl lub prywatny@email.com">
      </div>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Adres M365 lub prywatny e-mail — umożliwia dostęp do panelu umów.</p>
    </div>
  </div>
</div>

<!-- TAB 3: Dzieło i wynagrodzenie -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-file-earmark-text"></i> Opis i wynagrodzenie</h2>
  <div class="tw-mb-4">
    <label for="opis_dziela" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opis dzieła</label>
    <textarea id="opis_dziela" name="opis_dziela" rows="4" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
              placeholder="Dokładny opis dzieła będącego przedmiotem umowy…"><?= h($row['opis_dziela']??'') ?></textarea>
  </div>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="termin_oddania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin oddania</label>
      <input id="termin_oddania" name="termin_oddania" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['termin_oddania']??'') ?>">
    </div>
    <div>
      <label for="wynagrodzenie_brutto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wynagrodzenie brutto (PLN)</label>
      <input id="wynagrodzenie_brutto" name="wynagrodzenie_brutto" type="number" step="0.01" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['wynagrodzenie_brutto']??'') ?>">
    </div>
    <div>
      <label for="zaliczka_podatek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zaliczka na podatek (PLN)</label>
      <input id="zaliczka_podatek" name="zaliczka_podatek" type="number" step="0.01" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['zaliczka_podatek']??'') ?>">
    </div>
  </div>
  <div class="tw-mb-6">
    <label for="kup50" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="kup50" id="kup50" value="1" <?= !empty($row['kup50'])?'checked':'' ?>>
      50% koszty uzyskania przychodu (KUP) — prawa autorskie
    </label>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-c-circle"></i> Prawa autorskie</h2>
  <div class="tw-mb-6">
    <label for="prawa_autorskie" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700 tw-mb-3">
      <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="prawa_autorskie" id="prawa_autorskie" value="1"
        <?= !empty($row['prawa_autorskie'])?'checked':'' ?> onchange="toggleZakres(this)">
      Przeniesienie praw autorskich
    </label>
    <div id="zakres_praw_field" class="<?= !empty($row['prawa_autorskie'])?'':'tw-hidden' ?>">
      <label for="zakres_praw" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zakres praw autorskich</label>
      <textarea id="zakres_praw" name="zakres_praw" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['zakres_praw']??'') ?></textarea>
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-check2-circle"></i> Odbiór dzieła</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-4 tw-gap-4 tw-items-start">
    <div>
      <label for="data_odbioru" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data odbioru</label>
      <input id="data_odbioru" name="data_odbioru" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_odbioru']??'') ?>">
    </div>
    <div>
      <label for="data_zl_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data złożenia rachunku</label>
      <input id="data_zl_rachunku" name="data_zl_rachunku" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['data_zl_rachunku']??'') ?>">
    </div>
    <div class="sm:tw-col-span-2 tw-flex tw-flex-wrap tw-gap-6 tw-pt-6">
      <label for="wymagany_protokol" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="wymagany_protokol" id="wymagany_protokol" value="1" <?= !empty($row['wymagany_protokol'])?'checked':'' ?>>
        Wymagany protokół
      </label>
      <label for="dzielo_przyjete" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="dzielo_przyjete" id="dzielo_przyjete" value="1" <?= !empty($row['dzielo_przyjete'])?'checked':'' ?>>
        Dzieło przyjęte
      </label>
    </div>
  </div>
</div>

<!-- TAB 4: Podpisanie i pliki -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">
  <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-6">
    <div class="lg:tw-col-span-2">

      <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-pen"></i> Forma i podpisanie</h2>
      <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
        <div>
          <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
          <select name="forma_podpisania" id="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
            <option value="">—</option>
            <option value="papierowa" <?= ($row['forma_podpisania']??'')==='papierowa'?'selected':'' ?>>Papierowa</option>
            <option value="elektroniczna" <?= ($row['forma_podpisania']??'')==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
            <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania']??'')==='epodpis_kwalifikowany'?'selected':'' ?>>ePodpis kwalifikowany</option>
          </select>
        </div>
        <div>
          <label for="podpisujacy_fundacja" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Podpisujący ze strony Fundacji</label>
          <input id="podpisujacy_fundacja" name="podpisujacy_fundacja" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['podpisujacy_fundacja']??'') ?>" placeholder="np. Jan Prezes">
        </div>
        <div>
          <label for="podpisujacy_stanowisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Stanowisko podpisującego</label>
          <input id="podpisujacy_stanowisko" name="podpisujacy_stanowisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['podpisujacy_stanowisko']??'') ?>" placeholder="np. Prezes Zarządu">
        </div>
      </div>

      <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4 <?= ($row['forma_podpisania']??'')!=='elektroniczna'?'tw-hidden':'' ?>">
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

      <div id="epodpis_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4 tw-hidden">
        <div>
          <label for="epodpis_dostawca" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Dostawca podpisu (TSP)</label>
          <input id="epodpis_dostawca" name="epodpis_dostawca" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 placeholder="Certum, SimplySign, mSzafir…" value="<?= h($row['epodpis_dostawca']??'') ?>">
        </div>
        <div>
          <label for="epodpis_nr_certyfikatu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr seryjny certyfikatu</label>
          <input id="epodpis_nr_certyfikatu" name="epodpis_nr_certyfikatu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['epodpis_nr_certyfikatu']??'') ?>">
        </div>
        <div>
          <label for="epodpis_data_waznosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Ważność certyfikatu</label>
          <input id="epodpis_data_waznosci" name="epodpis_data_waznosci" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
                 value="<?= h($row['epodpis_data_waznosci']??'') ?>">
        </div>
      </div>
    </div>

    <div class="lg:tw-col-span-1 tw-space-y-4">
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-paperclip"></i> Plik umowy</h2>
        <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF/DOCX, max 20 MB)</label>
        <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
               class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
      </div>
      <div class="tw-rounded-xl tw-border tw-border-slate-200 tw-p-4">
        <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-shield-lock"></i> Dostęp do umowy</h2>
        <?= contract_access_field_html([]) ?>
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
    <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
              placeholder="Dodatkowe informacje, zastrzeżenia, notatki…"><?= h($row['uwagi']??'') ?></textarea>
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
    <button type="submit" name="zapisz_roboczo" value="1" formnovalidate :disabled="submitting"
            class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-blue-300 tw-bg-white tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-blue-700 hover:tw-bg-blue-50 disabled:tw-opacity-60">
      <i class="bi bi-save"></i> Zapisz roboczo
    </button>
    <button type="submit" :disabled="submitting" class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-bg-blue-600 tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-white hover:tw-bg-blue-700 disabled:tw-opacity-60">
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
  var fp = this.value;
  document.getElementById('el_fields').classList.toggle('tw-hidden',       fp !== 'elektroniczna');
  document.getElementById('epodpis_fields').classList.toggle('tw-hidden',  fp !== 'epodpis_kwalifikowany');
});

function toggleZakres(cb) {
  document.getElementById('zakres_praw_field').classList.toggle('tw-hidden', !cb.checked);
}

document.getElementById('m365_konto').addEventListener('change', function () {
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
        '<strong>' + d.nazwa + '</strong><br>NIP: ' + d.nip + '<br>' + (d.adres ? d.adres + '<br>' : '') + badge +
        '</div><button type="button" class="btn btn-sm btn-success flex-shrink-0" onclick=\'ceidgFill(' + JSON.stringify(d) + ')\'>' +
        '<i class="bi bi-arrow-down-circle"></i> Uzupełnij</button></div></div>';
    }
  } catch (e) { res.innerHTML = '<div class="alert alert-danger py-2 mt-1 mb-0 small">Błąd komunikacji z serwerem.</div>'; }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-search"></i> CEIDG';
}
function ceidgFill(d) {
  var name = (d.imie && d.nazwisko) ? d.imie + ' ' + d.nazwisko : d.nazwa || '';
  var setVal = function (sel, val) { var el = document.querySelector(sel); if (el) el.value = val; };
  setVal('[name=imie_nazwisko]', name);
  setVal('[name=addr_street]', d.adres || '');
  document.getElementById('ceidgResult').innerHTML = '<small class="text-success mt-1 d-block"><i class="bi bi-check-circle"></i> Pola uzupełnione — sprawdź rozbicie adresu.</small>';
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
