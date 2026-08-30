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
        foreach (['zus_skladki','zwolnienie_wiek','wymagany_rachunek'] as $f) {
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
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/edit.php?id={$id}"); exit;
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

<div class="tw-max-w-6xl tw-mx-auto">

<div class="tw-flex tw-items-center tw-justify-between tw-mb-4 tw-flex-wrap tw-gap-2">
  <h1 class="tw-text-xl tw-font-semibold tw-text-slate-800 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-person-lines-fill tw-text-blue-600"></i> Nowa umowa zlecenie
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

<form method="post" enctype="multipart/form-data" id="zlecForm" novalidate x-data="tabbedContractForm(4)" @submit="onSubmit($event)"
      class="tw-bg-white tw-rounded-2xl tw-border tw-border-slate-200 tw-shadow-sm tw-overflow-hidden">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Zakładki -->
<div class="tw-flex tw-gap-1 tw-overflow-x-auto tw-border-b tw-border-slate-200 tw-bg-slate-50 tw-px-3 tw-pt-2" role="tablist" aria-label="Sekcje formularza umowy">
  <?php $_tabs = ['Strony i podstawy', 'Podpisanie', 'Wykonanie', 'Rozliczenie']; ?>
  <?php foreach ($_tabs as $_ti => $_tlabel): $_tn = $_ti + 1; ?>
  <button type="button" role="tab" :aria-selected="(tab===<?= $_tn ?>).toString()" @click="goTab(<?= $_tn ?>)"
          class="tw-inline-flex tw-items-center tw-whitespace-nowrap tw-rounded-t-lg tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-transition"
          :class="tab===<?= $_tn ?> ? 'tw-bg-white tw-text-blue-600 tw-border tw-border-b-0 tw-border-slate-200' : 'tw-text-slate-500 hover:tw-text-slate-700'">
    <?= h($_tlabel) ?>
  </button>
  <?php endforeach; ?>
</div>

<!-- TAB 1: Strony i podstawy -->
<div data-tab-pane="1" x-show="tab===1" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-info-circle-fill"></i> Dane podstawowe</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="numer_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer umowy <span class="tw-text-red-500">*</span></label>
      <input id="numer_umowy" name="numer_umowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['numer_umowy']??'') ?>" required>
    </div>
    <div>
      <label for="status" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Status <span class="tw-text-red-500">*</span></label>
      <select id="status" name="status" required class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <?php foreach(STATUS_LABELS as $k=>$v): if($k==='aneks') continue;
          $sel = ($row['status']??'projekt')===$k?'selected':''; ?>
        <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">projekt → podpisana → w realizacji → do rozliczenia → zakończona</p>
    </div>
    <div>
      <label for="opiekun" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Opiekun umowy</label>
      <input id="opiekun" name="opiekun" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['opiekun']??'') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="przedmiot_zlecenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Przedmiot zlecenia</label>
      <textarea id="przedmiot_zlecenia" name="przedmiot_zlecenia" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['przedmiot_zlecenia']??'') ?></textarea>
    </div>
    <div>
      <label for="wynagrodzenie_brutto" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Wynagrodzenie brutto (PLN)</label>
      <input id="wynagrodzenie_brutto" name="wynagrodzenie_brutto" type="number" step="0.01" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-semibold focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['wynagrodzenie_brutto']??'') ?>">
    </div>
    <div class="sm:tw-col-span-2">
      <label for="numer_projektu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Numer projektu / źródło finansowania</label>
      <input id="numer_projektu" name="numer_projektu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['numer_projektu']??'') ?>">
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3 tw-mt-6"><i class="bi bi-person-fill"></i> Zleceniobiorca</h2>

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
    <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Wybierz osobę z rejestru — dane uzupełnią się automatycznie. Opcjonalne.</p>
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

  <div class="tw-mb-4">
    <label for="org_unit_id" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Komórka organizacyjna</label>
    <select id="org_unit_id" name="org_unit_id" class="tw-w-full sm:tw-w-1/2 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
      <option value="">— wybierz —</option>
      <?php foreach ($_units as $u): ?>
      <option value="<?= h($u['id']) ?>" <?= ($row['org_unit_id']??'')==$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div class="sm:tw-col-span-2">
      <label for="zl_imie_nazwisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Imię i nazwisko</label>
      <input id="zl_imie_nazwisko" name="imie_nazwisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['imie_nazwisko']??'') ?>">
      <?= byli_check_field('imie_nazwisko') ?>
    </div>
    <div>
      <label for="zl_pesel" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">PESEL</label>
      <input id="zl_pesel" name="pesel" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" maxlength="11" pattern="\d{11}" value="<?= h($row['pesel']??'') ?>">
    </div>
    <div>
      <label for="zl_seria" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Seria i nr dowodu</label>
      <input id="zl_seria" name="seria_nr_dowodu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['seria_nr_dowodu']??'') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1"><i class="bi bi-house me-1 tw-text-slate-400"></i>Adres zamieszkania / siedziby</label>
      <?= address_widget($row, ['copy_button'=>true,'autocomplete'=>true,'widget_id'=>'zlecenieAddrWidget']) ?>
    </div>
    <div class="sm:tw-col-span-2">
      <label for="zl_email" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">E-mail kontrahenta</label>
      <input type="email" id="zl_email" name="email" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="jan.kowalski@email.pl" value="<?= h($row['email']??'') ?>">
    </div>
    <div>
      <label for="zl_urzad" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Urząd skarbowy</label>
      <input id="zl_urzad" name="urzad_skarbowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['urzad_skarbowy']??'') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="zl_rachunek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy</label>
      <input id="zl_rachunek" name="rachunek_bankowy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy']??'') ?>">
    </div>
    <div class="sm:tw-col-span-3">
      <label for="m365_login" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">E-mail do logowania w panelu</label>
      <div class="tw-relative">
        <i class="bi bi-envelope tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-slate-400"></i>
        <input id="m365_login" name="m365_login" type="email"
               class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-pl-9 tw-pr-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
               value="<?= h($row['m365_login']??'') ?>" placeholder="imie.nazwisko@feer.org.pl lub prywatny@email.com">
      </div>
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Adres M365 lub prywatny e-mail — umożliwia dostęp do panelu umów.</p>
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3 tw-mt-6"><i class="bi bi-hash"></i> Numery referencyjne <span class="tw-text-slate-400 tw-font-normal tw-normal-case">— opcjonalne</span></h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div>
      <label for="nr_roboczy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr roboczy</label>
      <input id="nr_roboczy" name="nr_roboczy" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="PR-2026-001">
    </div>
    <div>
      <label for="nr_system" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr ogólny <span class="tw-text-slate-400 tw-font-normal">(webNGO)</span></label>
      <input id="nr_system" name="nr_system" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['nr_system']??'') ?>">
    </div>
    <div>
      <label for="nr_rejestru" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr rejestru <span class="tw-text-slate-400 tw-font-normal">RU/{nr}/{rok}/{inicjały}</span></label>
      <input id="nr_rejestru" name="nr_rejestru" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"
             value="<?= h($row['nr_rejestru']??'') ?>" placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
      <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Zostaw puste — nadany automatycznie.</p>
    </div>
  </div>
</div>

<!-- TAB 2: Podpisanie -->
<div data-tab-pane="2" x-show="tab===2" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-pen-fill"></i> Podpisanie i forma</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div>
      <label for="data_zawarcia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zawarcia</label>
      <input id="data_zawarcia" name="data_zawarcia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_zawarcia']??'') ?>">
    </div>
    <div>
      <label for="podpisujacy_fundacja" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Podpisujący ze strony Fundacji</label>
      <input id="podpisujacy_fundacja" name="podpisujacy_fundacja" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['podpisujacy_fundacja']??'') ?>" placeholder="Jan Prezes">
    </div>
    <div>
      <label for="podpisujacy_stanowisko" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Stanowisko</label>
      <input id="podpisujacy_stanowisko" name="podpisujacy_stanowisko" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['podpisujacy_stanowisko']??'') ?>" placeholder="Prezes Zarządu">
    </div>
  </div>
  <div class="tw-mb-4 sm:tw-w-1/3">
    <label for="forma_podpisania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Forma podpisania</label>
    <select id="forma_podpisania" name="forma_podpisania" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
      <option value="">—</option>
      <option value="papierowa" <?= ($row['forma_podpisania']??'')==='papierowa'?'selected':'' ?>>Papierowa</option>
      <option value="elektroniczna" <?= ($row['forma_podpisania']??'')==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania']??'')==='epodpis_kwalifikowany'?'selected':'' ?>>ePodpis kwalifikowany</option>
    </select>
  </div>

  <div id="el_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= ($row['forma_podpisania']??'')!=='elektroniczna'?'none':'' ?>">
    <div>
      <label for="platforma_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Platforma</label>
      <input id="platforma_el" name="platforma_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="Autenti / inny" value="<?= h($row['platforma_el']??'') ?>">
    </div>
    <div>
      <label for="id_dokumentu_el" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">ID dokumentu w systemie</label>
      <input id="id_dokumentu_el" name="id_dokumentu_el" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['id_dokumentu_el']??'') ?>">
    </div>
    <div>
      <label for="plik_potwierdzenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik potwierdzenia (PDF)</label>
      <input id="plik_potwierdzenia" name="plik_potwierdzenia" type="file" accept=".pdf"
             class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
    </div>
  </div>

  <div id="epodpis_fields" class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4" style="display:<?= ($row['forma_podpisania']??'')!=='epodpis_kwalifikowany'?'none':'' ?>">
    <div>
      <label for="epodpis_dostawca" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Dostawca podpisu (TSP)</label>
      <input id="epodpis_dostawca" name="epodpis_dostawca" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca']??'') ?>">
    </div>
    <div>
      <label for="epodpis_nr_certyfikatu" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Nr seryjny certyfikatu</label>
      <input id="epodpis_nr_certyfikatu" name="epodpis_nr_certyfikatu" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm tw-font-mono focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['epodpis_nr_certyfikatu']??'') ?>">
    </div>
    <div>
      <label for="epodpis_data_waznosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Ważność certyfikatu</label>
      <input id="epodpis_data_waznosci" name="epodpis_data_waznosci" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['epodpis_data_waznosci']??'') ?>">
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3 tw-mt-6"><i class="bi bi-paperclip"></i> Plik umowy</h2>
  <div>
    <label for="plik_umowy" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Plik umowy (PDF/DOCX, max 20 MB)</label>
    <input id="plik_umowy" name="plik_umowy" type="file" accept=".pdf,.docx"
           class="tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200">
  </div>
</div>

<!-- TAB 3: Wykonanie -->
<div data-tab-pane="3" x-show="tab===3" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-play-circle-fill"></i> Realizacja i stawka</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-6">
    <div>
      <label for="data_rozpoczecia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rozpoczęcia</label>
      <input id="data_rozpoczecia" name="data_rozpoczecia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_rozpoczecia']??'') ?>">
    </div>
    <div>
      <label for="data_zakonczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zakończenia</label>
      <input id="data_zakonczenia" name="data_zakonczenia" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_zakonczenia']??'') ?>">
    </div>
    <div>
      <label for="liczba_godzin_planowana" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Liczba godzin (planowana)</label>
      <input id="liczba_godzin_planowana" name="liczba_godzin_planowana" type="number" step="0.5" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['liczba_godzin_planowana']??'') ?>">
    </div>
    <div>
      <label for="typ_stawki" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Typ stawki</label>
      <select id="typ_stawki" name="typ_stawki" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="">—</option>
        <option value="godzinowo" <?= ($row['typ_stawki']??'')==='godzinowo'?'selected':'' ?>>Godzinowa</option>
        <option value="ryczalt"   <?= ($row['typ_stawki']??'')==='ryczalt'?'selected':'' ?>>Ryczałt</option>
      </select>
    </div>
    <div>
      <label for="stawka_kwota" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Kwota stawki (PLN)</label>
      <input id="stawka_kwota" name="stawka_kwota" type="number" step="0.01" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['stawka_kwota']??'') ?>">
    </div>
    <div>
      <label for="sposob_rozliczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Sposób rozliczenia</label>
      <input id="sposob_rozliczenia" name="sposob_rozliczenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="np. miesięcznie, po wykonaniu" value="<?= h($row['sposob_rozliczenia']??'') ?>">
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-shield-check"></i> ZUS / ubezpieczenie</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
    <div class="tw-flex tw-flex-col tw-gap-2 tw-pt-1">
      <label for="zus_skladki" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="zus_skladki" id="zus_skladki" value="1" <?= !empty($row['zus_skladki'])?'checked':'' ?>>
        Podlega składkom ZUS
      </label>
      <label for="zwolnienie_wiek" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="zwolnienie_wiek" id="zwolnienie_wiek" value="1" <?= !empty($row['zwolnienie_wiek'])?'checked':'' ?>>
        Zwolnienie — student/uczeń do 26 lat
      </label>
    </div>
    <div>
      <label for="tytul_ubezpieczenia" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Tytuł ubezpieczenia ZUS</label>
      <input id="tytul_ubezpieczenia" name="tytul_ubezpieczenia" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['tytul_ubezpieczenia']??'') ?>">
    </div>
    <div class="tw-space-y-3">
      <div>
        <label for="zus_data_rejestracji" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data zgłoszenia do ZUS (ZUA/ZZA)</label>
        <input id="zus_data_rejestracji" name="zus_data_rejestracji" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['zus_data_rejestracji']??'') ?>">
        <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Termin: 7 dni od rozpoczęcia.</p>
      </div>
      <div>
        <label for="zus_data_wyrejestrowania" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data wyrejestrowania (ZWUA)</label>
        <input id="zus_data_wyrejestrowania" name="zus_data_wyrejestrowania" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['zus_data_wyrejestrowania']??'') ?>">
        <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Termin: 7 dni od zakończenia.</p>
      </div>
    </div>
  </div>
</div>

<!-- TAB 4: Rozliczenie -->
<div data-tab-pane="4" x-show="tab===4" x-cloak class="tw-p-6">

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-cash-coin"></i> Rozliczenie i rachunek</h2>
  <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 tw-mb-4">
    <div class="tw-pt-1">
      <label for="wymagany_rachunek" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-text-slate-700">
        <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="wymagany_rachunek" id="wymagany_rachunek" value="1" <?= !empty($row['wymagany_rachunek'])?'checked':'' ?>>
        Wymagany rachunek do umowy
      </label>
    </div>
    <div>
      <label for="termin_platnosci" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Termin płatności</label>
      <input id="termin_platnosci" name="termin_platnosci" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="np. 14 dni od dostarczenia rachunku" value="<?= h($row['termin_platnosci']??'') ?>">
    </div>
    <div>
      <label for="kup" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Koszty uzyskania przychodu</label>
      <select id="kup" name="kup" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
        <option value="brak">Brak / standardowe</option>
        <option value="20" <?= ($row['kup']??'')==='20'?'selected':'' ?>>20% KUP</option>
        <option value="50" <?= ($row['kup']??'')==='50'?'selected':'' ?>>50% KUP (prawa autorskie)</option>
      </select>
    </div>
    <div>
      <label for="data_zl_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data złożenia rachunku</label>
      <input id="data_zl_rachunku" name="data_zl_rachunku" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_zl_rachunku']??'') ?>">
    </div>
    <div>
      <label for="data_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Data rachunku</label>
      <input id="data_rachunku" name="data_rachunku" type="date" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['data_rachunku']??'') ?>">
    </div>
    <div>
      <label for="okres_rachunku" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Za jaki okres</label>
      <input id="okres_rachunku" name="okres_rachunku" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" placeholder="np. czerwiec 2026" value="<?= h($row['okres_rachunku']??'') ?>">
    </div>
    <div>
      <label for="zaliczka_podatek" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Zaliczka na podatek (PLN)</label>
      <input id="zaliczka_podatek" name="zaliczka_podatek" type="number" step="0.01" min="0" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none" value="<?= h($row['zaliczka_podatek']??'') ?>">
    </div>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3 tw-mt-6"><i class="bi bi-microsoft"></i> Microsoft 365</h2>
  <div class="tw-rounded-lg tw-border tw-border-blue-200 tw-bg-blue-50 tw-text-blue-800 tw-px-3 tw-py-2 tw-mb-3 tw-text-sm">
    <i class="bi bi-info-circle tw-mr-1"></i>
    Adres e-mail do logowania wpisz w zakładce „Strony i podstawy" w polu „E-mail do logowania w panelu".
    Konto zostanie założone w Microsoft 365 — wymagana dostępna licencja i aktywna integracja M365.
  </div>
  <label for="m365_create_now" class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-font-semibold tw-text-slate-700">
    <input class="tw-h-4 tw-w-4 tw-rounded tw-border-slate-300 tw-text-blue-600 focus:tw-ring-blue-200" type="checkbox" name="m365_create_now" id="m365_create_now" value="1">
    <i class="bi bi-microsoft tw-text-blue-600"></i> Utwórz konto M365 automatycznie przy zapisie
  </label>
  <p class="tw-mt-1 tw-text-xs tw-text-slate-500">Konto można też utworzyć ręcznie po zapisaniu — w widoku szczegółów umowy.</p>

  <?php if (!empty($_task_workspaces)): ?>
  <div class="tw-rounded-xl tw-border tw-border-amber-200 tw-bg-amber-50 tw-p-4 tw-mt-6">
    <h2 class="tw-text-xs tw-font-semibold tw-text-amber-700 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-kanban-fill"></i> Obszar zadań <span class="tw-text-red-500">*</span></h2>
    <label for="task_workspace_id" class="tw-block tw-text-sm tw-font-semibold tw-text-slate-700 tw-mb-1">Przypisz zleceniobiorcę do obszaru w module Zadania</label>
    <select id="task_workspace_id" name="task_workspace_id" required class="tw-w-full sm:tw-w-1/2 tw-rounded-lg tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none">
      <option value="">— wybierz obszar zadań —</option>
      <?php foreach ($_task_workspaces as $ws): ?>
      <option value="<?= h($ws['id']) ?>" <?= ($row['task_workspace_id']??'')==$ws['id']?'selected':'' ?>><?= h($ws['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="tw-mt-1 tw-text-xs tw-text-slate-600">Zleceniobiorca zostanie dodany jako <strong>member</strong> wybranego obszaru. Pole niewymagane przy zapisie roboczym.</p>
  </div>
  <?php endif; ?>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3 tw-mt-6"><i class="bi bi-shield-lock-fill"></i> Dostęp do umowy</h2>
  <div class="tw-mb-6">
    <?= contract_access_field_html([]) ?>
  </div>

  <h2 class="tw-text-xs tw-font-semibold tw-text-slate-500 tw-uppercase tw-tracking-wide tw-mb-3"><i class="bi bi-chat-left-text"></i> Uwagi</h2>
  <div class="tw-mb-2">
    <textarea id="uwagi" name="uwagi" rows="3" class="tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none"><?= h($row['uwagi']??'') ?></textarea>
  </div>

  <div class="tw-mt-4">
    <?php rodo_grant_form_section(); ?>
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
    <button type="submit" name="zapisz_roboczo" value="1" formnovalidate id="wizDraft"
            class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-border tw-border-blue-300 tw-bg-white tw-px-4 tw-py-2 tw-text-sm tw-font-medium tw-text-blue-600 hover:tw-bg-blue-50">
      <i class="bi bi-save"></i> Zapisz roboczo
    </button>
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

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
