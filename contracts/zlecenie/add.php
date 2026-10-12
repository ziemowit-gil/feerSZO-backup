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
require_once dirname(dirname(__DIR__)) . '/includes/zlecenie_rachunki.php'; // ZL_EW_ZRODLA (źródło godzin) + kolumny ewidencji
require_once dirname(dirname(__DIR__)) . '/includes/rodo.php';

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

$_task_workspaces = [];
try {
    if (module_enabled('tasks_enabled')) {
        $_task_workspaces = db_all("SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name");
    }
} catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    $is_draft = isset($_POST['zapisz_roboczo']);
    if ($is_draft && empty($row['status'])) $row['status'] = 'projekt';
    if (empty($row['numer_umowy'])) $row['numer_umowy'] = next_contract_number($TYPE);

    if (empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';
    if (!$is_draft && !empty($_task_workspaces) && empty($_POST['task_workspace_id'])) {
        $errors[] = 'Wybierz obszar zadań — pole jest wymagane.';
    }

    if (!$errors) {
        foreach (['zus_skladki','zwolnienie_wiek','wymagany_rachunek','dydaktyka_udostepnij'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        $row['m365_konto'] = 0;
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        if ($plik_umowy) $row['plik_umowy'] = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia'] = $plik_potw;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        foreach (['wynagrodzenie_brutto','stawka_kwota','liczba_godzin_planowana','zaliczka_podatek'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }

        if (isset($row['ti_instructor_user_id']) && $row['ti_instructor_user_id'] === '') $row['ti_instructor_user_id'] = null;
        $allowed = ['numer_umowy','status','imie_nazwisko','pesel','adres','email','seria_nr_dowodu','urzad_skarbowy',
            'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
            'rachunek_bankowy','przedmiot_zlecenia','data_zawarcia','data_rozpoczecia','data_zakonczenia',
            'wynagrodzenie_brutto','stawka_kwota','typ_stawki','liczba_godzin_planowana','sposob_rozliczenia',
            'zrodlo_godzin','ti_instructor_user_id','dydaktyka_udostepnij',
            'termin_platnosci','zus_skladki','tytul_ubezpieczenia','zus_data_rejestracji','zus_data_wyrejestrowania','zwolnienie_wiek','zaliczka_podatek','kup',
            'numer_projektu','opiekun','wymagany_rachunek','data_zl_rachunku','data_rachunku','okres_rachunku','forma_podpisania',
            'platforma_el','id_dokumentu_el','epodpis_dostawca','epodpis_nr_certyfikatu','epodpis_data_waznosci',
            'plik_potwierdzenia','plik_umowy','uwagi','created_by','created_at','updated_at',
            'm365_konto','m365_login','m365_user_id','m365_konto_aktywne','m365_data_utworzenia','m365_licencja_przypisana',
            'nr_roboczy','nr_system','nr_rejestru','person_id','org_unit_id',
            'podpisujacy_fundacja','podpisujacy_stanowisko'];
        $data = array_intersect_key($row, array_flip($allowed));
        foreach (['person_id','org_unit_id','org_position_id'] as $fk) {
            if (isset($data[$fk]) && $data[$fk] === '') $data[$fk] = null;
        }

        assign_nr_rejestru($data);
        $id = db_insert($TABLE, $data);
        contract_access_set($TYPE, $id, $_POST['access_users'] ?? [], (int)current_user()['id']);

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

        $missed = contract_assert_saved($TABLE, $id, $data);
        if ($missed) {
            error_log('[zlecenie/add] zapis nieutrwalony id=' . $id . ' pola=' . implode(',', $missed));
            flash_set('danger', 'Umowa NIE została poprawnie zapisana w bazie (' . count($missed) . ' pól). Zgłoś administratorowi.');
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/add.php"); exit;
        }

        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'create', 'Dodano: ' . ($data['numer_umowy'] ?? ''));

        $task_ws_id = (int)($_POST['task_workspace_id'] ?? 0);
        if ($task_ws_id > 0 && !empty($_task_workspaces)) {
            try {
                $person_email = $data['email'] ?? '';
                if ($person_email) {
                    $zlec_user = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$person_email]);
                    if ($zlec_user) {
                        db()->prepare(
                            "INSERT OR IGNORE INTO task_workspace_members
                             (workspace_id, user_id, role, added_by, added_at)
                             VALUES (?, ?, 'member', ?, datetime('now','localtime'))"
                        )->execute([$task_ws_id, (int)$zlec_user['id'], (int)current_user()['id']]);
                    }
                }
            } catch (\Throwable $e) {}
        }

        if (isset($_POST['nie_mam_drukarki'])) {
            require_once dirname(dirname(__DIR__)) . '/contracts/includes/pdf_queue.php';
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['imie_nazwisko'] ?? '', current_user()['id']);
        }
        if (!$is_draft) {
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
                crm_migrate();
                CrmManager::autoCreateContractCase($TYPE, $id, $data['numer_umowy'] ?? '', $data, (int)(current_user()['id'] ?? 0));
            } catch (\Throwable $e) { error_log('[crm_case_auto] ' . $e->getMessage()); }
        }
        if ($is_draft) {
            flash_set('info', 'Zapisano roboczo — możesz wrócić i dokończyć umowę.');
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}&tab=umowa&edit=1"); exit;
        }

        // M365 auto-create (jeśli zaznaczono checkbox przy zapisie)
        $m365_ok  = null;  // null = nie żądano, true = sukces, string = błąd
        $m365_login_created = '';
        $m365_mail_sent     = false;
        if (!empty($_POST['m365_create_now'])) {
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
                require_once dirname(dirname(__DIR__)) . '/includes/it_helpers.php';
                $graph = new M365Graph();
                if (!$graph->is_configured()) throw new \RuntimeException('Integracja M365 nie jest skonfigurowana.');
                $pname  = $data['imie_nazwisko'] ?? '';
                $pemail = $data['email'] ?? null;
                $login  = $graph->unique_login($pname);
                $pass   = M365Graph::generate_password();
                $sku    = m365_setting('m365_license_sku_id');
                if ($sku) {
                    foreach ($graph->get_subscribed_skus() as $_s) {
                        if ($_s['skuId'] === $sku) {
                            $free = ($_s['prepaidUnits']['enabled'] ?? 0) - ($_s['consumedUnits'] ?? 0);
                            if ($free <= 0) throw new \RuntimeException('Brak wolnych licencji M365 (' . ($_s['skuPartNumber'] ?? '') . ').');
                            break;
                        }
                    }
                }
                $user    = $graph->create_user($login, $pname, $pass, true);
                $user_id = $user['id'];
                $lic_assigned = 0;
                if ($sku) { $graph->assign_license($user_id, $sku); $lic_assigned = 1; }
                $sent_m = false;
                $sender = m365_setting('m365_sender_user_id');
                if ($sender && $pemail) { $graph->send_welcome_email($sender, $pemail, $pname, $login, $pass); $sent_m = true; }
                try {
                    it_migrate();
                    $_svc = db_one("SELECT id FROM it_services WHERE slug='m365'");
                    if ($_svc) {
                        it_log_password(['service_id'=>(int)$_svc['id'],'contract_type'=>$TYPE,'contract_id'=>$id,
                            'login'=>$login,'plain'=>$pass,'sent_to_email'=>$sent_m?$pemail:null,
                            'notes'=>'Wygenerowano automatycznie przy tworzeniu umowy','issued_by'=>current_user()['id']]);
                    }
                } catch (\Throwable $_e) {}
                db_update($TABLE, ['m365_konto'=>1,'m365_login'=>$login,'m365_user_id'=>$user_id,
                    'm365_konto_aktywne'=>1,'m365_data_utworzenia'=>date('Y-m-d H:i:s'),'m365_licencja_przypisana'=>$lic_assigned], $id);
                $lnk = m365_auto_link_or_create_local($pemail ?? '', $pname, $user_id);
                if ($lnk['msg']) log_contract_action($TYPE, $id, (int)current_user()['id'], 'note', $lnk['msg']);
                auth_start();
                $_SESSION['m365_new_pass']  = $pass;
                $_SESSION['m365_new_login'] = $login;
                $_SESSION['m365_sent']      = $sent_m;
                $m365_ok = true;
                $m365_login_created = $login;
                $m365_mail_sent     = $sent_m;
            } catch (\Throwable $_e) {
                $m365_ok = $_e->getMessage();
                error_log('[zlecenie/add m365] ' . $_e->getMessage());
            }
        }

        if ($m365_ok === true) {
            flash_set('success', 'Umowa zlecenie dodana. Konto M365 <strong>' . h($m365_login_created) . '</strong> utworzone.' . ($m365_mail_sent ? ' Mail z danymi wysłany.' : ''));
        } elseif (is_string($m365_ok)) {
            flash_set('success', 'Umowa zlecenie dodana. <span class="text-warning fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>Konto M365 nie zostało utworzone:</span> ' . h($m365_ok));
        } else {
            flash_set('success', 'Umowa zlecenie została dodana.');
        }
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}"); exit;
    }
}

$_units = [];
try { $_units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name"); } catch(\Throwable $e) {}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style type="text/tailwindcss">
  /* Formularz nowej umowy zlecenie — jedna strona z sekcjami (bez zakładek). Tailwind bez preflightu:
     każde tw-border* wymaga tw-border-solid, inaczej ramka pola jest niewidoczna. */
  .zl-wrap   { @apply tw-max-w-6xl tw-mx-auto tw-pb-28; }
  .zl-layout { @apply tw-grid tw-grid-cols-1 lg:tw-grid-cols-[13rem_minmax(0,1fr)] tw-gap-6; }
  .zl-nav    { @apply tw-hidden lg:tw-block; }
  .zl-nav ol { @apply tw-sticky tw-top-24 tw-space-y-1 tw-list-none tw-p-0 tw-m-0; }
  .zl-nav a  { @apply tw-flex tw-items-center tw-gap-2 tw-rounded-lg tw-px-3 tw-py-2 tw-text-sm tw-text-slate-600 tw-no-underline hover:tw-bg-white hover:tw-text-slate-900; }
  .zl-nav a.is-active { @apply tw-bg-white tw-font-semibold tw-text-blue-700 tw-shadow-sm; }
  .zl-nav a .zl-dot { @apply tw-flex tw-h-6 tw-w-6 tw-flex-none tw-items-center tw-justify-center tw-rounded-full tw-bg-slate-200 tw-text-xs tw-font-bold tw-text-slate-600; }
  .zl-nav a.is-active .zl-dot { @apply tw-bg-blue-600 tw-text-white; }
  .zl-card   { @apply tw-scroll-mt-24 tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-5 tw-shadow-sm sm:tw-p-6; }
  .zl-card + .zl-card { @apply tw-mt-5; }
  .zl-head   { @apply tw-mb-5 tw-flex tw-items-start tw-gap-3; }
  .zl-num    { @apply tw-flex tw-h-9 tw-w-9 tw-flex-none tw-items-center tw-justify-center tw-rounded-full tw-bg-blue-50 tw-text-base tw-text-blue-700; }
  .zl-title  { @apply tw-m-0 tw-text-base tw-font-semibold tw-text-slate-900; }
  .zl-sub    { @apply tw-m-0 tw-mt-0.5 tw-text-sm tw-text-slate-500; }
  .zl-grid   { @apply tw-grid tw-grid-cols-1 tw-gap-4 sm:tw-grid-cols-6; }
  .zl-c2 { @apply sm:tw-col-span-2; } .zl-c3 { @apply sm:tw-col-span-3; } .zl-c4 { @apply sm:tw-col-span-4; } .zl-c6 { @apply sm:tw-col-span-6; }
  .zl-label  { @apply tw-mb-1 tw-block tw-text-sm tw-font-medium tw-text-slate-700; }
  .zl-opt    { @apply tw-font-normal tw-text-slate-400; }
  .zl-req    { @apply tw-text-red-500; }
  .zl-input  { @apply tw-w-full tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-text-slate-900 tw-outline-none tw-transition placeholder:tw-text-slate-400 focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100; }
  .zl-file   { @apply tw-w-full tw-rounded-lg tw-border tw-border-dashed tw-border-slate-300 tw-bg-slate-50 tw-p-2 tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-md file:tw-border-0 file:tw-bg-white file:tw-px-3 file:tw-py-1.5 file:tw-text-sm file:tw-font-medium file:tw-text-slate-700 hover:file:tw-bg-slate-100; }
  .zl-help   { @apply tw-mb-0 tw-mt-1 tw-text-xs tw-text-slate-500; }
  .zl-check  { @apply tw-flex tw-items-start tw-gap-2.5 tw-text-sm tw-text-slate-700; }
  .zl-cb     { @apply tw-mt-0.5 tw-h-4 tw-w-4 tw-flex-none tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200; }
  .zl-box    { @apply tw-rounded-lg tw-border tw-border-solid tw-border-slate-200 tw-bg-slate-50 tw-p-4; }
  .zl-box-info { @apply tw-rounded-lg tw-border tw-border-solid tw-border-sky-200 tw-bg-sky-50 tw-p-4; }
  .zl-box-warn { @apply tw-rounded-lg tw-border tw-border-solid tw-border-amber-200 tw-bg-amber-50 tw-p-4; }
  .zl-sep    { @apply tw-my-6 tw-border-0 tw-border-t tw-border-solid tw-border-slate-100; }
  .zl-btn    { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-slate-700 tw-no-underline hover:tw-bg-slate-50; }
  .zl-btn-sec{ @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-solid tw-border-blue-300 tw-bg-white tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-blue-700 hover:tw-bg-blue-50; }
  .zl-btn-pri{ @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-solid tw-border-blue-600 tw-bg-blue-600 tw-px-5 tw-py-2 tw-text-sm tw-font-semibold tw-text-white hover:tw-bg-blue-700 disabled:tw-opacity-60; }
  .zl-bar    { @apply tw-fixed tw-inset-x-0 tw-bottom-0 tw-z-30 tw-border-0 tw-border-t tw-border-solid tw-border-slate-200 tw-bg-white/95 tw-backdrop-blur; }
  .zl-bar-in { @apply tw-mx-auto tw-flex tw-max-w-6xl tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-px-4 tw-py-3; }
  .zl-details > summary { @apply tw-flex tw-cursor-pointer tw-list-none tw-items-center tw-gap-2 tw-text-sm tw-font-medium tw-text-slate-600 hover:tw-text-slate-900; }
  .zl-details > summary::-webkit-details-marker { display: none; }
  .zl-details[open] > summary .bi-chevron-right { transform: rotate(90deg); }
</style>

<div class="zl-wrap">

<div class="tw-mb-5 tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3">
  <div>
    <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500">Umowy zlecenie</p>
    <h1 class="tw-m-0 tw-mt-1 tw-flex tw-items-center tw-gap-2 tw-text-2xl tw-font-semibold tw-text-slate-900">
      <i class="bi bi-person-lines-fill tw-text-blue-600" aria-hidden="true"></i> Nowa umowa zlecenie
    </h1>
    <p class="tw-m-0 tw-mt-1 tw-text-sm tw-text-slate-500">Wypełnij to, co znasz — resztę uzupełnisz później. „Zapisz roboczo” zapisuje bez sprawdzania wymaganych pól.</p>
  </div>
  <a href="list.php" class="zl-btn"><i class="bi bi-arrow-left" aria-hidden="true"></i> Lista umów</a>
</div>

<?php if ($errors): ?>
<div class="tw-mb-5 tw-rounded-lg tw-border tw-border-solid tw-border-red-200 tw-bg-red-50 tw-px-4 tw-py-3 tw-text-sm tw-text-red-800" role="alert">
  <p class="tw-m-0 tw-font-semibold">Popraw, aby zapisać umowę:</p>
  <ul class="tw-mb-0 tw-mt-1 tw-pl-5 tw-list-disc"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<?php
  $_zl_sections = [
    'zl-umowa'     => ['Umowa', 'bi-file-earmark-text'],
    'zl-osoba'     => ['Zleceniobiorca', 'bi-person'],
    'zl-podpis'    => ['Podpisanie', 'bi-pen'],
    'zl-realizacja'=> ['Realizacja i stawka', 'bi-play-circle'],
    'zl-zus'       => ['ZUS', 'bi-shield-check'],
    'zl-rozlicz'   => ['Rozliczenie', 'bi-cash-coin'],
    'zl-dostep'    => ['Dostęp i uwagi', 'bi-shield-lock'],
  ];
?>
<form method="post" enctype="multipart/form-data" id="zlecForm" novalidate x-data="tabbedContractForm(1)" @submit="onSubmit($event)">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="zl-layout">
  <nav class="zl-nav" aria-label="Sekcje formularza">
    <ol>
      <?php $_n = 0; foreach ($_zl_sections as $_sid => [$_sl, $_si]): $_n++; ?>
      <li><a href="#<?= $_sid ?>" data-zl-nav="<?= $_sid ?>"><span class="zl-dot" aria-hidden="true"><?= $_n ?></span><?= h($_sl) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <div class="tw-min-w-0">

  <!-- 1. Umowa -->
  <section id="zl-umowa" class="zl-card" aria-labelledby="zl-umowa-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
      <div><h2 id="zl-umowa-h" class="zl-title">Umowa</h2><p class="zl-sub">Numer, status, przedmiot i wynagrodzenie.</p></div>
    </div>
    <div class="zl-grid">
      <div class="zl-c2">
        <label for="numer_umowy" class="zl-label">Numer umowy <span class="zl-req" aria-hidden="true">*</span></label>
        <input id="numer_umowy" name="numer_umowy" class="zl-input tw-font-mono tw-font-semibold" value="<?= h($row['numer_umowy']??'') ?>" required>
      </div>
      <div class="zl-c2">
        <label for="status" class="zl-label">Status <span class="zl-req" aria-hidden="true">*</span></label>
        <select id="status" name="status" required class="zl-input" aria-describedby="status_help">
          <?php foreach(STATUS_LABELS as $k=>$v): if($k==='aneks') continue;
            $sel = ($row['status']??'projekt')===$k?'selected':''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <p id="status_help" class="zl-help">projekt → podpisana → w realizacji → do rozliczenia → zakończona</p>
      </div>
      <div class="zl-c2">
        <label for="opiekun" class="zl-label">Opiekun umowy</label>
        <input id="opiekun" name="opiekun" class="zl-input" value="<?= h($row['opiekun']??'') ?>">
      </div>
      <div class="zl-c6">
        <label for="przedmiot_zlecenia" class="zl-label">Przedmiot zlecenia</label>
        <textarea id="przedmiot_zlecenia" name="przedmiot_zlecenia" rows="3" class="zl-input" placeholder="Co zleceniobiorca ma wykonać — np. prowadzenie zajęć z obsługi komputera z czytnikiem ekranu"><?= h($row['przedmiot_zlecenia']??'') ?></textarea>
      </div>
      <div class="zl-c2">
        <label for="wynagrodzenie_brutto" class="zl-label">Wynagrodzenie brutto <span class="zl-opt">(PLN)</span></label>
        <input id="wynagrodzenie_brutto" name="wynagrodzenie_brutto" type="number" step="0.01" min="0" class="zl-input tw-font-semibold" value="<?= h($row['wynagrodzenie_brutto']??'') ?>">
      </div>
      <div class="zl-c4">
        <label for="numer_projektu" class="zl-label">Numer projektu / źródło finansowania</label>
        <input id="numer_projektu" name="numer_projektu" class="zl-input" value="<?= h($row['numer_projektu']??'') ?>">
      </div>
    </div>

    <details class="zl-details tw-mt-5" <?= ($row['nr_roboczy'] ?? '') !== '' || ($row['nr_system'] ?? '') !== '' || ($row['nr_rejestru'] ?? '') !== '' ? 'open' : '' ?>>
      <summary><i class="bi bi-chevron-right tw-transition" aria-hidden="true"></i> Numery referencyjne <span class="zl-opt">— opcjonalne</span></summary>
      <div class="zl-grid tw-mt-3">
        <div class="zl-c2">
          <label for="nr_roboczy" class="zl-label">Nr roboczy</label>
          <input id="nr_roboczy" name="nr_roboczy" class="zl-input" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="PR-2026-001">
        </div>
        <div class="zl-c2">
          <label for="nr_system" class="zl-label">Nr ogólny <span class="zl-opt">(webNGO)</span></label>
          <input id="nr_system" name="nr_system" class="zl-input" value="<?= h($row['nr_system']??'') ?>">
        </div>
        <div class="zl-c2">
          <label for="nr_rejestru" class="zl-label">Nr rejestru <span class="zl-opt">RU/{nr}/{rok}/{inicjały}</span></label>
          <input id="nr_rejestru" name="nr_rejestru" class="zl-input tw-font-mono" value="<?= h($row['nr_rejestru']??'') ?>" placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>" aria-describedby="nr_rejestru_help">
          <p id="nr_rejestru_help" class="zl-help">Zostaw puste — nadany automatycznie.</p>
        </div>
      </div>
    </details>
  </section>

  <!-- 2. Zleceniobiorca -->
  <section id="zl-osoba" class="zl-card" aria-labelledby="zl-osoba-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-person"></i></span>
      <div><h2 id="zl-osoba-h" class="zl-title">Zleceniobiorca</h2><p class="zl-sub">Najszybciej: wybierz osobę z rejestru albo wpisz NIP z CEIDG — pola uzupełnią się same.</p></div>
    </div>

    <div class="zl-grid tw-mb-5">
      <div class="zl-c4 zl-box">
        <?= person_picker($row, [
          'id'          => 'zlpp',
          'label'       => 'Wypełnij z rejestru osób',
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
      </div>
      <div class="zl-c2 zl-box">
        <label for="ceidgNipInput" class="zl-label">Z CEIDG <span class="zl-opt">(JDG)</span></label>
        <div class="tw-flex tw-gap-2">
          <input type="text" id="ceidgNipInput" class="zl-input" placeholder="NIP (10 cyfr)" maxlength="13" inputmode="numeric">
          <button type="button" id="ceidgBtn" onclick="ceidgSearch()" class="zl-btn-sec tw-whitespace-nowrap"><i class="bi bi-search" aria-hidden="true"></i> Szukaj</button>
        </div>
        <div id="ceidgResult" class="tw-mt-1" aria-live="polite"></div>
      </div>
    </div>

    <div class="zl-grid">
      <div class="zl-c4">
        <label for="zl_imie_nazwisko" class="zl-label">Imię i nazwisko</label>
        <input id="zl_imie_nazwisko" name="imie_nazwisko" class="zl-input" autocomplete="off" value="<?= h($row['imie_nazwisko']??'') ?>">
        <?= byli_check_field('imie_nazwisko') ?>
      </div>
      <div class="zl-c2">
        <label for="org_unit_id" class="zl-label">Komórka organizacyjna</label>
        <select id="org_unit_id" name="org_unit_id" class="zl-input">
          <option value="">— wybierz —</option>
          <?php foreach ($_units as $u): ?>
          <option value="<?= h($u['id']) ?>" <?= ($row['org_unit_id']??'')==$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="zl-c2">
        <label for="zl_pesel" class="zl-label">PESEL</label>
        <input id="zl_pesel" name="pesel" class="zl-input tw-font-mono" maxlength="11" pattern="\d{11}" inputmode="numeric" value="<?= h($row['pesel']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="zl_seria" class="zl-label">Seria i nr dowodu</label>
        <input id="zl_seria" name="seria_nr_dowodu" class="zl-input tw-font-mono" value="<?= h($row['seria_nr_dowodu']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="zl_urzad" class="zl-label">Urząd skarbowy</label>
        <input id="zl_urzad" name="urzad_skarbowy" class="zl-input" value="<?= h($row['urzad_skarbowy']??'') ?>">
      </div>
      <div class="zl-c6">
        <span class="zl-label"><i class="bi bi-house tw-mr-1 tw-text-slate-400" aria-hidden="true"></i>Adres zamieszkania / siedziby</span>
        <?= address_widget($row, ['copy_button'=>true,'autocomplete'=>true,'widget_id'=>'zlecenieAddrWidget']) ?>
      </div>
      <div class="zl-c3">
        <label for="zl_email" class="zl-label">E-mail kontaktowy</label>
        <input type="email" id="zl_email" name="email" class="zl-input" placeholder="jan.kowalski@email.pl" value="<?= h($row['email']??'') ?>">
      </div>
      <div class="zl-c3">
        <label for="zl_rachunek" class="zl-label">Rachunek bankowy</label>
        <input id="zl_rachunek" name="rachunek_bankowy" class="zl-input tw-font-mono" placeholder="XX XXXX XXXX XXXX XXXX XXXX XXXX" value="<?= h($row['rachunek_bankowy']??'') ?>">
      </div>
      <div class="zl-c6">
        <label for="m365_login" class="zl-label">E-mail do logowania w panelu</label>
        <div class="tw-relative">
          <i class="bi bi-envelope tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400" aria-hidden="true"></i>
          <input id="m365_login" name="m365_login" type="email" class="zl-input tw-pl-9" value="<?= h($row['m365_login']??'') ?>" placeholder="imie.nazwisko@feer.org.pl lub prywatny@email.com" aria-describedby="m365_login_help">
        </div>
        <p id="m365_login_help" class="zl-help">Adres M365 albo prywatny — daje dostęp do panelu umów. Konto M365 możesz założyć w sekcji „Dostęp i uwagi”.</p>
      </div>
    </div>
  </section>

  <!-- 3. Podpisanie -->
  <section id="zl-podpis" class="zl-card" aria-labelledby="zl-podpis-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-pen"></i></span>
      <div><h2 id="zl-podpis-h" class="zl-title">Podpisanie</h2><p class="zl-sub">Kto, kiedy i w jakiej formie podpisuje — plus plik umowy.</p></div>
    </div>
    <div class="zl-grid">
      <div class="zl-c2">
        <label for="data_zawarcia" class="zl-label">Data zawarcia</label>
        <input id="data_zawarcia" name="data_zawarcia" type="date" class="zl-input" value="<?= h($row['data_zawarcia']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="podpisujacy_fundacja" class="zl-label">Podpisujący ze strony Fundacji</label>
        <input id="podpisujacy_fundacja" name="podpisujacy_fundacja" class="zl-input" value="<?= h($row['podpisujacy_fundacja']??'') ?>" placeholder="Jan Prezes">
      </div>
      <div class="zl-c2">
        <label for="podpisujacy_stanowisko" class="zl-label">Stanowisko</label>
        <input id="podpisujacy_stanowisko" name="podpisujacy_stanowisko" class="zl-input" value="<?= h($row['podpisujacy_stanowisko']??'') ?>" placeholder="Prezes Zarządu">
      </div>
      <div class="zl-c2">
        <label for="forma_podpisania" class="zl-label">Forma podpisania</label>
        <select id="forma_podpisania" name="forma_podpisania" class="zl-input">
          <option value="">—</option>
          <option value="papierowa" <?= ($row['forma_podpisania']??'')==='papierowa'?'selected':'' ?>>Papierowa</option>
          <option value="elektroniczna" <?= ($row['forma_podpisania']??'')==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
          <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania']??'')==='epodpis_kwalifikowany'?'selected':'' ?>>ePodpis kwalifikowany</option>
        </select>
      </div>
      <div class="zl-c4">
        <label for="plik_umowy" class="zl-label">Plik umowy <span class="zl-opt">(PDF/DOCX, do 20 MB)</span></label>
        <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx" class="zl-file">
      </div>
    </div>

    <div id="el_fields" class="zl-grid zl-box tw-mt-4" style="display:<?= ($row['forma_podpisania']??'')!=='elektroniczna'?'none':'' ?>">
      <div class="zl-c2">
        <label for="platforma_el" class="zl-label">Platforma</label>
        <input id="platforma_el" name="platforma_el" class="zl-input" placeholder="Autenti / inny" value="<?= h($row['platforma_el']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="id_dokumentu_el" class="zl-label">ID dokumentu w systemie</label>
        <input id="id_dokumentu_el" name="id_dokumentu_el" class="zl-input" value="<?= h($row['id_dokumentu_el']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="plik_potwierdzenia" class="zl-label">Plik potwierdzenia <span class="zl-opt">(PDF)</span></label>
        <input id="plik_potwierdzenia" name="plik_potwierdzenia" type="file" accept=".pdf" class="zl-file">
      </div>
    </div>

    <div id="epodpis_fields" class="zl-grid zl-box tw-mt-4" style="display:<?= ($row['forma_podpisania']??'')!=='epodpis_kwalifikowany'?'none':'' ?>">
      <div class="zl-c2">
        <label for="epodpis_dostawca" class="zl-label">Dostawca podpisu (TSP)</label>
        <input id="epodpis_dostawca" name="epodpis_dostawca" class="zl-input" placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="epodpis_nr_certyfikatu" class="zl-label">Nr seryjny certyfikatu</label>
        <input id="epodpis_nr_certyfikatu" name="epodpis_nr_certyfikatu" class="zl-input tw-font-mono" value="<?= h($row['epodpis_nr_certyfikatu']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="epodpis_data_waznosci" class="zl-label">Ważność certyfikatu</label>
        <input id="epodpis_data_waznosci" name="epodpis_data_waznosci" type="date" class="zl-input" value="<?= h($row['epodpis_data_waznosci']??'') ?>">
      </div>
    </div>
  </section>

  <!-- 4. Realizacja i stawka -->
  <section id="zl-realizacja" class="zl-card" aria-labelledby="zl-realizacja-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-play-circle"></i></span>
      <div><h2 id="zl-realizacja-h" class="zl-title">Realizacja i stawka</h2><p class="zl-sub">Okres, stawka i skąd brać godziny do ewidencji.</p></div>
    </div>
    <div class="zl-grid">
      <div class="zl-c2">
        <label for="data_rozpoczecia" class="zl-label">Data rozpoczęcia</label>
        <input id="data_rozpoczecia" name="data_rozpoczecia" type="date" class="zl-input" value="<?= h($row['data_rozpoczecia']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="data_zakonczenia" class="zl-label">Data zakończenia</label>
        <input id="data_zakonczenia" name="data_zakonczenia" type="date" class="zl-input" value="<?= h($row['data_zakonczenia']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="liczba_godzin_planowana" class="zl-label">Liczba godzin <span class="zl-opt">(planowana)</span></label>
        <input id="liczba_godzin_planowana" name="liczba_godzin_planowana" type="number" step="0.5" class="zl-input" value="<?= h($row['liczba_godzin_planowana']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="typ_stawki" class="zl-label">Typ stawki</label>
        <select id="typ_stawki" name="typ_stawki" class="zl-input">
          <option value="">—</option>
          <option value="godzinowo" <?= ($row['typ_stawki']??'')==='godzinowo'?'selected':'' ?>>Godzinowa</option>
          <option value="ryczalt"   <?= ($row['typ_stawki']??'')==='ryczalt'?'selected':'' ?>>Ryczałt</option>
        </select>
      </div>
      <div class="zl-c2">
        <label for="stawka_kwota" class="zl-label">Kwota stawki <span class="zl-opt">(PLN)</span></label>
        <input id="stawka_kwota" name="stawka_kwota" type="number" step="0.01" min="0" class="zl-input" value="<?= h($row['stawka_kwota']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="sposob_rozliczenia" class="zl-label">Sposób rozliczenia</label>
        <input id="sposob_rozliczenia" name="sposob_rozliczenia" class="zl-input" placeholder="np. miesięcznie, po wykonaniu" value="<?= h($row['sposob_rozliczenia']??'') ?>">
      </div>
    </div>

    <div class="zl-box-info tw-mt-5">
      <p class="tw-m-0 tw-mb-3 tw-text-sm tw-font-semibold tw-text-slate-800"><i class="bi bi-clock-history tw-mr-1 tw-text-sky-700" aria-hidden="true"></i> Ewidencja godzin i dydaktyka</p>
      <div class="zl-grid">
        <div class="zl-c3">
          <label for="zrodlo_godzin" class="zl-label">Źródło godzin</label>
          <select id="zrodlo_godzin" name="zrodlo_godzin" class="zl-input" aria-describedby="zrodlo_godzin_help"
                  onchange="document.getElementById('ti-instr-wrap').style.display = (this.value==='karty30' || document.getElementById('dydaktyka_udostepnij').checked) ? '' : 'none'">
            <?php foreach (ZL_EW_ZRODLA as $_zk => $_zv): ?>
            <option value="<?= h($_zk) ?>" <?= (($row['zrodlo_godzin'] ?? 'reczne') === $_zk) ? 'selected' : '' ?>><?= h($_zv['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <p id="zrodlo_godzin_help" class="zl-help">Ręczne wpisy dzienne/miesięczne albo godziny z e-dziennika TI z podziałem na grupy.</p>
        </div>
        <div class="zl-c3" id="ti-instr-wrap" style="<?= (($row['zrodlo_godzin'] ?? 'reczne') === 'karty30' || !empty($row['dydaktyka_udostepnij'])) ? '' : 'display:none' ?>">
          <label for="ti_instructor_user_id" class="zl-label">Prowadzący w e-dzienniku</label>
          <select id="ti_instructor_user_id" name="ti_instructor_user_id" class="zl-input" aria-describedby="ti_instructor_help">
            <option value="">— wybierz konto prowadzącego —</option>
            <?php foreach (db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name") as $_u): ?>
            <option value="<?= (int)$_u['id'] ?>" <?= (int)($row['ti_instructor_user_id'] ?? 0) === (int)$_u['id'] ? 'selected' : '' ?>><?= h($_u['name']) ?><?= $_u['email'] ? ' (' . h($_u['email']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <p id="ti_instructor_help" class="zl-help">Sesje „odbyta”, „zmiana indywidualna” i „praca własna” liczone per grupa; stawka zawsze z umowy.</p>
        </div>
        <div class="zl-c6">
          <label for="dydaktyka_udostepnij" class="zl-check">
            <input class="zl-cb" type="checkbox" name="dydaktyka_udostepnij" id="dydaktyka_udostepnij" value="1" onchange="document.getElementById('ti-instr-wrap').style.display = (this.checked || document.getElementById('zrodlo_godzin').value==='karty30') ? '' : 'none'" aria-describedby="dydaktyka_udostepnij_opis" <?= !empty($row['dydaktyka_udostepnij'])?'checked':'' ?>>
            <span><strong class="tw-font-semibold">Dydaktyka — udostępnij dane do modułu</strong>
              <span id="dydaktyka_udostepnij_opis" class="tw-block tw-text-xs tw-text-slate-500">Umowa widoczna w panelu dydaktyka TI: e-ewidencja godzin i lista obecności z protokołów, strona „Umowa” prowadzącego.</span></span>
          </label>
        </div>
      </div>
    </div>
  </section>

  <!-- 5. ZUS -->
  <section id="zl-zus" class="zl-card" aria-labelledby="zl-zus-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
      <div><h2 id="zl-zus-h" class="zl-title">ZUS i ubezpieczenie</h2><p class="zl-sub">Zgłoszenie i wyrejestrowanie — po 7 dni od rozpoczęcia i zakończenia.</p></div>
    </div>
    <div class="zl-grid">
      <div class="zl-c2 tw-space-y-3 tw-pt-1">
        <label for="zus_skladki" class="zl-check">
          <input class="zl-cb" type="checkbox" name="zus_skladki" id="zus_skladki" value="1" <?= !empty($row['zus_skladki'])?'checked':'' ?>>
          Podlega składkom ZUS
        </label>
        <label for="zwolnienie_wiek" class="zl-check">
          <input class="zl-cb" type="checkbox" name="zwolnienie_wiek" id="zwolnienie_wiek" value="1" <?= !empty($row['zwolnienie_wiek'])?'checked':'' ?>>
          Zwolnienie — student/uczeń do 26 lat
        </label>
      </div>
      <div class="zl-c4">
        <label for="tytul_ubezpieczenia" class="zl-label">Tytuł ubezpieczenia ZUS</label>
        <input id="tytul_ubezpieczenia" name="tytul_ubezpieczenia" class="zl-input" value="<?= h($row['tytul_ubezpieczenia']??'') ?>">
      </div>
      <div class="zl-c3">
        <label for="zus_data_rejestracji" class="zl-label">Data zgłoszenia do ZUS <span class="zl-opt">(ZUA/ZZA)</span></label>
        <input id="zus_data_rejestracji" name="zus_data_rejestracji" type="date" class="zl-input" value="<?= h($row['zus_data_rejestracji']??'') ?>">
        <p class="zl-help">Termin: 7 dni od rozpoczęcia.</p>
      </div>
      <div class="zl-c3">
        <label for="zus_data_wyrejestrowania" class="zl-label">Data wyrejestrowania <span class="zl-opt">(ZWUA)</span></label>
        <input id="zus_data_wyrejestrowania" name="zus_data_wyrejestrowania" type="date" class="zl-input" value="<?= h($row['zus_data_wyrejestrowania']??'') ?>">
        <p class="zl-help">Termin: 7 dni od zakończenia.</p>
      </div>
    </div>
  </section>

  <!-- 6. Rozliczenie -->
  <section id="zl-rozlicz" class="zl-card" aria-labelledby="zl-rozlicz-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
      <div><h2 id="zl-rozlicz-h" class="zl-title">Rozliczenie i rachunek</h2><p class="zl-sub">Rachunek, koszty uzyskania przychodu i termin płatności.</p></div>
    </div>
    <div class="zl-grid">
      <div class="zl-c6">
        <label for="wymagany_rachunek" class="zl-check">
          <input class="zl-cb" type="checkbox" name="wymagany_rachunek" id="wymagany_rachunek" value="1" <?= !empty($row['wymagany_rachunek'])?'checked':'' ?>>
          Wymagany rachunek do umowy
        </label>
      </div>
      <div class="zl-c2">
        <label for="kup" class="zl-label">Koszty uzyskania przychodu</label>
        <select id="kup" name="kup" class="zl-input">
          <option value="brak">Brak / standardowe</option>
          <option value="20" <?= ($row['kup']??'')==='20'?'selected':'' ?>>20% KUP</option>
          <option value="50" <?= ($row['kup']??'')==='50'?'selected':'' ?>>50% KUP (prawa autorskie)</option>
        </select>
      </div>
      <div class="zl-c2">
        <label for="termin_platnosci" class="zl-label">Termin płatności</label>
        <input id="termin_platnosci" name="termin_platnosci" class="zl-input" placeholder="np. 14 dni od rachunku" value="<?= h($row['termin_platnosci']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="zaliczka_podatek" class="zl-label">Zaliczka na podatek <span class="zl-opt">(PLN)</span></label>
        <input id="zaliczka_podatek" name="zaliczka_podatek" type="number" step="0.01" min="0" class="zl-input" value="<?= h($row['zaliczka_podatek']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="data_zl_rachunku" class="zl-label">Data złożenia rachunku</label>
        <input id="data_zl_rachunku" name="data_zl_rachunku" type="date" class="zl-input" value="<?= h($row['data_zl_rachunku']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="data_rachunku" class="zl-label">Data rachunku</label>
        <input id="data_rachunku" name="data_rachunku" type="date" class="zl-input" value="<?= h($row['data_rachunku']??'') ?>">
      </div>
      <div class="zl-c2">
        <label for="okres_rachunku" class="zl-label">Za jaki okres</label>
        <input id="okres_rachunku" name="okres_rachunku" class="zl-input" placeholder="np. czerwiec 2026" value="<?= h($row['okres_rachunku']??'') ?>">
      </div>
    </div>
  </section>

  <!-- 7. Dostęp i uwagi -->
  <section id="zl-dostep" class="zl-card" aria-labelledby="zl-dostep-h">
    <div class="zl-head">
      <span class="zl-num" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
      <div><h2 id="zl-dostep-h" class="zl-title">Dostęp i uwagi</h2><p class="zl-sub">Konto M365, obszar zadań, kto widzi umowę, uwagi i upoważnienie RODO.</p></div>
    </div>

    <div class="zl-box">
      <label for="m365_create_now" class="zl-check">
        <input class="zl-cb" type="checkbox" name="m365_create_now" id="m365_create_now" value="1" aria-describedby="m365_create_help">
        <span><strong class="tw-font-semibold"><i class="bi bi-microsoft tw-mr-1 tw-text-blue-600" aria-hidden="true"></i>Utwórz konto Microsoft 365 przy zapisie</strong>
          <span id="m365_create_help" class="tw-block tw-text-xs tw-text-slate-500">Wymaga wolnej licencji i aktywnej integracji M365. Dane logowania pójdą na e-mail kontaktowy. Konto można też założyć później w widoku umowy.</span></span>
      </label>
    </div>

    <?php if (!empty($_task_workspaces)): ?>
    <div class="zl-box-warn tw-mt-4">
      <label for="task_workspace_id" class="zl-label"><i class="bi bi-kanban tw-mr-1 tw-text-amber-700" aria-hidden="true"></i>Obszar w module Zadania <span class="zl-req" aria-hidden="true">*</span></label>
      <select id="task_workspace_id" name="task_workspace_id" required class="zl-input sm:tw-w-1/2" aria-describedby="task_ws_help">
        <option value="">— wybierz obszar zadań —</option>
        <?php foreach ($_task_workspaces as $ws): ?>
        <option value="<?= h($ws['id']) ?>" <?= ($row['task_workspace_id']??'')==$ws['id']?'selected':'' ?>><?= h($ws['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p id="task_ws_help" class="zl-help">Zleceniobiorca zostanie dodany jako członek obszaru. Niewymagane przy zapisie roboczym.</p>
    </div>
    <?php endif; ?>

    <hr class="zl-sep">
    <p class="zl-label">Dostęp do umowy</p>
    <?= contract_access_field_html([]) ?>

    <hr class="zl-sep">
    <label for="uwagi" class="zl-label">Uwagi</label>
    <textarea id="uwagi" name="uwagi" rows="3" class="zl-input"><?= h($row['uwagi']??'') ?></textarea>

    <div class="tw-mt-5">
      <?php rodo_grant_form_section(); ?>
    </div>
  </section>

  </div>
</div>

<!-- Pasek akcji — przyklejony do dołu ekranu -->
<div class="zl-bar">
  <div class="zl-bar-in">
    <label for="nie_mam_drukarki" class="zl-check tw-items-center tw-text-slate-600">
      <input class="zl-cb tw-mt-0" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
      <i class="bi bi-printer" aria-hidden="true"></i> Nie mam drukarki — zapisz PDF do późniejszego wydruku
    </label>
    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <a href="list.php" class="zl-btn">Anuluj</a>
      <button type="submit" name="zapisz_roboczo" value="1" formnovalidate id="wizDraft" class="zl-btn-sec">
        <i class="bi bi-save" aria-hidden="true"></i> Zapisz roboczo
      </button>
      <button type="submit" :disabled="submitting" class="zl-btn-pri">
        <span x-show="submitting" x-cloak class="tw-inline-block tw-h-3.5 tw-w-3.5 tw-animate-spin tw-rounded-full tw-border-2 tw-border-solid tw-border-white/40 tw-border-t-white" aria-hidden="true"></span>
        <i x-show="!submitting" class="bi bi-check-lg" aria-hidden="true"></i>
        <span x-text="submitting ? 'Zapisywanie…' : 'Zapisz umowę'">Zapisz umowę</span>
      </button>
    </div>
  </div>
</div>

</form>

</div><!-- /zl-wrap -->

<script>
/* ── Spis sekcji: podświetlenie bieżącej ─────────────────── */
(function () {
  var links = document.querySelectorAll('[data-zl-nav]');
  if (!links.length || !('IntersectionObserver' in window)) return;
  var byId = {}; links.forEach(function (a) { byId[a.dataset.zlNav] = a; });
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      links.forEach(function (a) { a.classList.remove('is-active'); a.removeAttribute('aria-current'); });
      var a = byId[e.target.id]; if (a) { a.classList.add('is-active'); a.setAttribute('aria-current', 'true'); }
    });
  }, { rootMargin: '-20% 0px -70% 0px' });
  Object.keys(byId).forEach(function (id) { var el = document.getElementById(id); if (el) io.observe(el); });
})();

/* ── Forma podpisania ──────────────────────────────────── */
document.getElementById('forma_podpisania').addEventListener('change', function () {
  var fp = this.value;
  document.getElementById('el_fields').style.display = fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = fp === 'epodpis_kwalifikowany' ? '' : 'none';
});

/* ── CEIDG ─────────────────────────────────────────────── */
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

<?php // Wymagalność pól (Admin → Wymagane pola w formularzach): oznaczenia etykiet + ostrzeżenia, bez blokowania zapisu.
require_once dirname(dirname(__DIR__)) . '/modules/form_fields/logic/formFields.php'; formFieldsAttach('zlecenie'); ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
