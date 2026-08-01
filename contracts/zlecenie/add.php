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
        foreach (['zus_skladki','zwolnienie_wiek','wymagany_rachunek','m365_konto'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
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
        flash_set('success', 'Umowa zlecenie została dodana.');
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}"); exit;
    }
}

$_units = [];
try { $_units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name"); } catch(\Throwable $e) {}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Pasek kroków ─────────────────────────────────────────────── */
.wiz-hsteps {
  display: flex; align-items: center; gap: 0;
  padding: .75rem 1.25rem; overflow-x: auto;
}
.wiz-hstep {
  display: flex; flex-direction: column; align-items: center; gap: .2rem;
  padding: .5rem .9rem; background: transparent; border: none; cursor: pointer;
  min-width: 100px; transition: .15s;
}
.wiz-hstep:hover:not(.active) { background: #F5F8FF; border-radius: 8px; }
.wiz-hstep-num {
  width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: #E2E8F0; color: #64748B; font-weight: 700; font-size: .85rem;
  transition: .15s;
}
.wiz-hstep.active .wiz-hstep-num { background: #2563EB; color: #fff; }
.wiz-hstep.done   .wiz-hstep-num { background: #16A34A; color: #fff; }
.wiz-hstep-label  { font-size: .75rem; font-weight: 600; color: #94A3B8; white-space: nowrap; }
.wiz-hstep.active .wiz-hstep-label { color: #2563EB; font-weight: 700; }
.wiz-hstep.done   .wiz-hstep-label { color: #16A34A; }
.wiz-hstep-sep {
  flex: 1; height: 2px; background: #E2E8F0; min-width: 20px; margin-bottom: 18px;
  transition: background .3s;
}
.wiz-hstep-sep.done { background: #16A34A; }

/* ── Sekcje formularza ─────────────────────────────────────────── */
.form-section {
  border-radius: 12px; border: 1px solid #E5E7EB;
  background: #fff; margin-bottom: 1rem; overflow: hidden;
}
.form-section-head {
  display: flex; align-items: center; gap: .6rem;
  padding: .8rem 1.1rem; background: #F9FAFB;
  border-bottom: 1px solid #E5E7EB; font-weight: 700; font-size: .88rem; color: #374151;
}
.form-section-icon {
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center; font-size: .95rem;
  flex-shrink: 0;
}
.form-section-body { padding: 1.1rem 1.1rem .3rem; }

/* ── Sticky nav ────────────────────────────────────────────────── */
.wiz-sticky-nav {
  position: sticky; bottom: 0; z-index: 100;
  background: rgba(255,255,255,.97); backdrop-filter: blur(4px);
  border-top: 1px solid #E5E7EB; padding: .8rem 1rem;
  display: flex; align-items: center; gap: .6rem;
  box-shadow: 0 -4px 12px rgba(0,0,0,.07);
}
</style>

<form method="post" enctype="multipart/form-data" id="zlecForm">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- ── Nagłówek ─────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px">
      <i class="bi bi-person-lines-fill text-primary" style="font-size:1.1rem"></i>
    </span>
    <div>
      <h4 class="mb-0">Nowa umowa zlecenie</h4>
      <div class="text-muted small"><?= h($row['numer_umowy']) ?></div>
    </div>
  </div>
  <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
<ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<!-- ── Pasek kroków ─────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <nav class="wiz-hsteps" id="wizSteps" aria-label="Kroki formularza">
    <button type="button" class="wiz-hstep active" id="step-btn-1" onclick="goToStep(1)">
      <span class="wiz-hstep-num" id="step-num-1">1</span>
      <span class="wiz-hstep-label">Strony</span>
    </button>
    <div class="wiz-hstep-sep" id="step-sep-1"></div>
    <button type="button" class="wiz-hstep" id="step-btn-2" onclick="goToStep(2)">
      <span class="wiz-hstep-num" id="step-num-2">2</span>
      <span class="wiz-hstep-label">Podpisanie</span>
    </button>
    <div class="wiz-hstep-sep" id="step-sep-2"></div>
    <button type="button" class="wiz-hstep" id="step-btn-3" onclick="goToStep(3)">
      <span class="wiz-hstep-num" id="step-num-3">3</span>
      <span class="wiz-hstep-label">Wykonanie</span>
    </button>
    <div class="wiz-hstep-sep" id="step-sep-3"></div>
    <button type="button" class="wiz-hstep" id="step-btn-4" onclick="goToStep(4)">
      <span class="wiz-hstep-num" id="step-num-4">4</span>
      <span class="wiz-hstep-label">Rozliczenie</span>
    </button>
  </nav>
</div>

<!-- ═══════════ KROK 1 — STRONY I PODSTAWY ═══════════ -->
<div class="wiz-step" id="wiz-step-1">

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-info-circle-fill"></i></span>
    Dane podstawowe
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Numer umowy <span class="text-danger">*</span></label>
        <input name="numer_umowy" class="form-control fw-bold font-monospace" value="<?= h($row['numer_umowy']??'') ?>" required>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Status <span class="text-danger">*</span></label>
        <select name="status" class="form-select" required>
          <?php foreach(STATUS_LABELS as $k=>$v): if($k==='aneks') continue;
            $sel = ($row['status']??'projekt')===$k?'selected':''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">projekt → podpisana → w realizacji → do rozliczenia → zakończona</div>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Opiekun umowy</label>
        <input name="opiekun" class="form-control" value="<?= h($row['opiekun']??'') ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">Przedmiot zlecenia</label>
      <textarea name="przedmiot_zlecenia" class="form-control" rows="3"><?= h($row['przedmiot_zlecenia']??'') ?></textarea>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Wynagrodzenie brutto (PLN)</label>
        <div class="input-group">
          <input name="wynagrodzenie_brutto" type="number" step="0.01" min="0" class="form-control fw-semibold" value="<?= h($row['wynagrodzenie_brutto']??'') ?>">
          <span class="input-group-text">PLN</span>
        </div>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Numer projektu / źródło finansowania</label>
        <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']??'') ?>">
      </div>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-person-fill"></i></span>
    Zleceniobiorca
  </div>
  <div class="form-section-body">
    <!-- Person picker -->
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
      <div class="form-text">Wybierz osobę z rejestru — dane uzupełnią się automatycznie. Opcjonalne.</div>
    </div>
    <!-- CEIDG -->
    <div class="mb-3">
      <label class="form-label small text-muted">Szybkie uzupełnienie z CEIDG (dla JDG)</label>
      <div class="input-group input-group-sm" style="max-width:440px">
        <span class="input-group-text"><i class="bi bi-building-check"></i></span>
        <input type="text" id="ceidgNipInput" class="form-control" placeholder="NIP działalności (10 cyfr)" maxlength="13">
        <button type="button" class="btn btn-outline-primary" id="ceidgBtn" onclick="ceidgSearch()">
          <i class="bi bi-search"></i> CEIDG
        </button>
      </div>
      <div id="ceidgResult"></div>
    </div>
    <div class="mb-3">
      <label class="form-label">Komórka organizacyjna</label>
      <select name="org_unit_id" class="form-select">
        <option value="">— wybierz —</option>
        <?php foreach ($_units as $u): ?>
        <option value="<?= h($u['id']) ?>" <?= ($row['org_unit_id']??'')==$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Imię i nazwisko</label>
        <input name="imie_nazwisko" id="zl_imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko']??'') ?>">
        <?= byli_check_field('imie_nazwisko') ?>
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">PESEL</label>
        <input name="pesel" id="zl_pesel" class="form-control" maxlength="11" pattern="\d{11}" value="<?= h($row['pesel']??'') ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Seria i nr dowodu</label>
        <input name="seria_nr_dowodu" id="zl_seria" class="form-control" value="<?= h($row['seria_nr_dowodu']??'') ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania / siedziby</label>
      <?= address_widget($row, ['copy_button'=>true,'autocomplete'=>true,'widget_id'=>'zlecenieAddrWidget']) ?>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">E-mail kontrahenta</label>
        <input type="email" name="email" id="zl_email" class="form-control" placeholder="jan.kowalski@email.pl" value="<?= h($row['email']??'') ?>">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Urząd skarbowy</label>
        <input name="urzad_skarbowy" id="zl_urzad" class="form-control" value="<?= h($row['urzad_skarbowy']??'') ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">Rachunek bankowy</label>
      <input name="rachunek_bankowy" id="zl_rachunek" class="form-control" placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy']??'') ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">E-mail do logowania w panelu</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
        <input name="m365_login" type="email" class="form-control"
          value="<?= h($row['m365_login']??'') ?>"
          placeholder="imie.nazwisko@feer.org.pl lub prywatny@email.com">
      </div>
      <div class="form-text">Adres M365 lub prywatny e-mail — umożliwia dostęp do panelu umów.</div>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F8FAFC;color:#94A3B8"><i class="bi bi-hash"></i></span>
    Numery referencyjne <span class="fw-normal text-muted small">&nbsp;— opcjonalne</span>
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Nr roboczy</label>
        <input name="nr_roboczy" class="form-control" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="PR-2026-001">
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
        <div class="form-text">Zostaw puste — nadany automatycznie.</div>
      </div>
    </div>
  </div>
</div>

</div><!-- /krok 1 -->

<!-- ═══════════ KROK 2 — PODPISANIE ═══════════ -->
<div class="wiz-step d-none" id="wiz-step-2">

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#FEE2E2;color:#DC2626"><i class="bi bi-pen-fill"></i></span>
    Podpisanie i forma
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Data zawarcia</label>
        <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Podpisujący ze strony Fundacji</label>
        <input name="podpisujacy_fundacja" class="form-control" value="<?= h($row['podpisujacy_fundacja']??'') ?>" placeholder="Jan Prezes">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Stanowisko</label>
        <input name="podpisujacy_stanowisko" class="form-control" value="<?= h($row['podpisujacy_stanowisko']??'') ?>" placeholder="Prezes Zarządu">
      </div>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Forma podpisania</label>
        <select name="forma_podpisania" class="form-select" id="forma_podpisania">
          <option value="">—</option>
          <option value="papierowa" <?= ($row['forma_podpisania']??'')==='papierowa'?'selected':'' ?>>Papierowa</option>
          <option value="elektroniczna" <?= ($row['forma_podpisania']??'')==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
          <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania']??'')==='epodpis_kwalifikowany'?'selected':'' ?>>ePodpis kwalifikowany</option>
        </select>
      </div>
    </div>
    <div id="el_fields" class="row <?= ($row['forma_podpisania']??'')!=='elektroniczna'?'d-none':'' ?>">
      <div class="col-md-4 mb-3">
        <label class="form-label">Platforma</label>
        <input name="platforma_el" class="form-control" placeholder="Autenti / inny" value="<?= h($row['platforma_el']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">ID dokumentu w systemie</label>
        <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Plik potwierdzenia (PDF)</label>
        <input name="plik_potwierdzenia" type="file" class="form-control" accept=".pdf">
      </div>
    </div>
    <div id="epodpis_fields" class="row <?= ($row['forma_podpisania']??'')!=='epodpis_kwalifikowany'?'d-none':'' ?>">
      <div class="col-md-4 mb-3">
        <label class="form-label">Dostawca podpisu (TSP)</label>
        <input name="epodpis_dostawca" class="form-control" placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Nr seryjny certyfikatu</label>
        <input name="epodpis_nr_certyfikatu" class="form-control font-monospace" value="<?= h($row['epodpis_nr_certyfikatu']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Ważność certyfikatu</label>
        <input name="epodpis_data_waznosci" type="date" class="form-control" value="<?= h($row['epodpis_data_waznosci']??'') ?>">
      </div>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F3F4F6;color:#6B7280"><i class="bi bi-paperclip"></i></span>
    Plik umowy
  </div>
  <div class="form-section-body">
    <div class="mb-3">
      <label class="form-label">Plik umowy (PDF/DOCX, max 20 MB)</label>
      <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
    </div>
  </div>
</div>

</div><!-- /krok 2 -->

<!-- ═══════════ KROK 3 — WYKONANIE ═══════════ -->
<div class="wiz-step d-none" id="wiz-step-3">

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#EEF4FF;color:#0176D3"><i class="bi bi-play-circle-fill"></i></span>
    Realizacja i stawka
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Data rozpoczęcia</label>
        <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Data zakończenia</label>
        <input name="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Liczba godzin (planowana)</label>
        <input name="liczba_godzin_planowana" type="number" step="0.5" class="form-control" value="<?= h($row['liczba_godzin_planowana']??'') ?>">
      </div>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Typ stawki</label>
        <select name="typ_stawki" class="form-select">
          <option value="">—</option>
          <option value="godzinowo" <?= ($row['typ_stawki']??'')==='godzinowo'?'selected':'' ?>>Godzinowa</option>
          <option value="ryczalt"   <?= ($row['typ_stawki']??'')==='ryczalt'?'selected':'' ?>>Ryczałt</option>
        </select>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Kwota stawki (PLN)</label>
        <div class="input-group">
          <input name="stawka_kwota" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['stawka_kwota']??'') ?>">
          <span class="input-group-text">PLN</span>
        </div>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Sposób rozliczenia</label>
        <input name="sposob_rozliczenia" class="form-control" placeholder="np. miesięcznie, po wykonaniu" value="<?= h($row['sposob_rozliczenia']??'') ?>">
      </div>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#FFF7ED;color:#EA580C"><i class="bi bi-shield-check"></i></span>
    ZUS / ubezpieczenie
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3 pt-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="zus_skladki" id="zus_skladki" value="1" <?= !empty($row['zus_skladki'])?'checked':'' ?>>
          <label class="form-check-label" for="zus_skladki">Podlega składkom ZUS</label>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="zwolnienie_wiek" id="zwolnienie_wiek" value="1" <?= !empty($row['zwolnienie_wiek'])?'checked':'' ?>>
          <label class="form-check-label" for="zwolnienie_wiek">Zwolnienie — student/uczeń do 26 lat</label>
        </div>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Tytuł ubezpieczenia ZUS</label>
        <input name="tytul_ubezpieczenia" class="form-control" value="<?= h($row['tytul_ubezpieczenia']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <div class="mb-2">
          <label class="form-label">Data zgłoszenia do ZUS (ZUA/ZZA)</label>
          <input type="date" name="zus_data_rejestracji" class="form-control" value="<?= h($row['zus_data_rejestracji']??'') ?>">
          <div class="form-text">Termin: 7 dni od rozpoczęcia.</div>
        </div>
        <div>
          <label class="form-label">Data wyrejestrowania (ZWUA)</label>
          <input type="date" name="zus_data_wyrejestrowania" class="form-control" value="<?= h($row['zus_data_wyrejestrowania']??'') ?>">
          <div class="form-text">Termin: 7 dni od zakończenia.</div>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /krok 3 -->

<!-- ═══════════ KROK 4 — ROZLICZENIE I FINALIZACJA ═══════════ -->
<div class="wiz-step d-none" id="wiz-step-4">

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-cash-coin"></i></span>
    Rozliczenie i rachunek
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3 pt-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="wymagany_rachunek" id="wymagany_rachunek" value="1" <?= !empty($row['wymagany_rachunek'])?'checked':'' ?>>
          <label class="form-check-label" for="wymagany_rachunek">Wymagany rachunek do umowy</label>
        </div>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Termin płatności</label>
        <input name="termin_platnosci" class="form-control" placeholder="np. 14 dni od dostarczenia rachunku" value="<?= h($row['termin_platnosci']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Koszty uzyskania przychodu</label>
        <select name="kup" class="form-select">
          <option value="brak">Brak / standardowe</option>
          <option value="20" <?= ($row['kup']??'')==='20'?'selected':'' ?>>20% KUP</option>
          <option value="50" <?= ($row['kup']??'')==='50'?'selected':'' ?>>50% KUP (prawa autorskie)</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="col-md-3 mb-3">
        <label class="form-label">Data złożenia rachunku</label>
        <input name="data_zl_rachunku" type="date" class="form-control" value="<?= h($row['data_zl_rachunku']??'') ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Data rachunku</label>
        <input name="data_rachunku" type="date" class="form-control" value="<?= h($row['data_rachunku']??'') ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Za jaki okres</label>
        <input name="okres_rachunku" class="form-control" placeholder="np. czerwiec 2026" value="<?= h($row['okres_rachunku']??'') ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Zaliczka na podatek (PLN)</label>
        <div class="input-group">
          <input name="zaliczka_podatek" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['zaliczka_podatek']??'') ?>">
          <span class="input-group-text">PLN</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F0F7FF;color:#0176D3"><i class="bi bi-microsoft"></i></span>
    Microsoft 365
  </div>
  <div class="form-section-body">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="m365_konto" id="m365_konto" value="1" <?= !empty($row['m365_konto'])?'checked':'' ?>>
      <label class="form-check-label" for="m365_konto">Konto M365 zostało utworzone</label>
    </div>
    <div id="m365_manual_fields" class="<?= !empty($row['m365_konto'])?'':'d-none' ?>">
      <div class="row">
        <div class="col-md-6 mb-2">
          <label class="form-label small">User ID (Azure AD)</label>
          <input name="m365_user_id" class="form-control form-control-sm font-monospace" value="<?= h($row['m365_user_id']??'') ?>">
        </div>
      </div>
    </div>
    <small class="text-muted">Konto można też <a href="#">utworzyć automatycznie</a> po zapisaniu umowy z widoku szczegółów.</small>
  </div>
</div>

<?php if (!empty($_task_workspaces)): ?>
<div class="form-section" style="border-color:#FDE68A">
  <div class="form-section-head" style="background:#FFFBEB">
    <span class="form-section-icon" style="background:#FEF3C7;color:#D97706"><i class="bi bi-kanban-fill"></i></span>
    Obszar zadań <span class="text-danger ms-1">*</span>
  </div>
  <div class="form-section-body">
    <label class="form-label fw-semibold">Przypisz zleceniobiorcę do obszaru w module Zadania</label>
    <select name="task_workspace_id" id="task_workspace_id" class="form-select" required>
      <option value="">— wybierz obszar zadań —</option>
      <?php foreach ($_task_workspaces as $ws): ?>
      <option value="<?= h($ws['id']) ?>" <?= ($row['task_workspace_id']??'')==$ws['id']?'selected':'' ?>><?= h($ws['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Zleceniobiorca zostanie dodany jako <strong>member</strong> wybranego obszaru.</div>
  </div>
</div>
<?php endif; ?>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#FEF9EC;color:#B45309"><i class="bi bi-shield-lock-fill"></i></span>
    Dostęp do umowy
  </div>
  <div class="form-section-body">
    <?= contract_access_field_html([]) ?>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F8FAFC;color:#6B7280"><i class="bi bi-chat-left-text"></i></span>
    Uwagi
  </div>
  <div class="form-section-body">
    <div class="mb-3">
      <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi']??'') ?></textarea>
    </div>
  </div>
</div>

<div class="form-check mb-3">
  <input class="form-check-input" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
  <label class="form-check-label text-muted" for="nie_mam_drukarki">
    <i class="bi bi-printer me-1"></i>Nie mam drukarki — zapisz umowę jako PDF do późniejszego wydruku
  </label>
</div>

<?php rodo_grant_form_section(); ?>

</div><!-- /krok 4 -->

<!-- ── Sticky nawigacja ─────────────────────────────────────────── -->
<div class="wiz-sticky-nav">
  <button type="button" class="btn btn-outline-secondary" id="wizBack" style="display:none"><i class="bi bi-arrow-left me-1"></i>Wstecz</button>
  <a href="list.php" class="btn btn-link text-muted">Anuluj</a>
  <div class="ms-auto d-flex gap-2">
    <button type="submit" name="zapisz_roboczo" value="1" formnovalidate class="btn btn-outline-primary" id="wizDraft">
      <i class="bi bi-save me-1"></i>Zapisz roboczo
    </button>
    <button type="button" class="btn btn-primary" id="wizNext">Dalej <i class="bi bi-arrow-right ms-1"></i></button>
    <button type="submit" class="btn btn-success" id="wizSave" style="display:none"><i class="bi bi-check-lg me-1"></i>Zapisz umowę</button>
  </div>
</div>

</form>

<script>
/* ── Wizard: nawigacja krokowa ──────────────────────────── */
(function () {
  'use strict';
  var STEPS = 4;
  var cur   = 1;
  var wizBack = document.getElementById('wizBack');
  var wizNext = document.getElementById('wizNext');
  var wizSave = document.getElementById('wizSave');

  function goToStep(n) {
    cur = Math.max(1, Math.min(STEPS, n));
    for (var i = 1; i <= STEPS; i++) {
      var stepEl = document.getElementById('wiz-step-' + i);
      if (stepEl) stepEl.classList.toggle('d-none', i !== cur);
      var btn = document.getElementById('step-btn-' + i);
      if (btn) {
        btn.classList.toggle('active', i === cur);
        btn.classList.toggle('done',   i <  cur);
      }
      var sep = document.getElementById('step-sep-' + i);
      if (sep) sep.classList.toggle('done', i < cur);
    }
    wizBack.style.display = cur > 1 ? '' : 'none';
    wizNext.style.display = cur < STEPS ? '' : 'none';
    wizSave.style.display = cur === STEPS ? '' : 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function validStep() {
    var fields = document.getElementById('wiz-step-' + cur).querySelectorAll('input,select,textarea');
    for (var i = 0; i < fields.length; i++) {
      if (!fields[i].checkValidity()) { fields[i].reportValidity(); return false; }
    }
    return true;
  }

  wizNext.addEventListener('click', function () { if (validStep()) goToStep(cur + 1); });
  wizBack.addEventListener('click', function () { goToStep(cur - 1); });
  for (var s = 1; s <= STEPS; s++) {
    (function (step) {
      var btn = document.getElementById('step-btn-' + step);
      if (btn) btn.addEventListener('click', function () {
        if (step > cur && !validStep()) return;
        goToStep(step);
      });
    })(s);
  }
  goToStep(1);
  window.goToStep = goToStep;
})();

/* ── Forma podpisania ──────────────────────────────────── */
document.getElementById('forma_podpisania').addEventListener('change', function () {
  var fp = this.value;
  document.getElementById('el_fields').classList.toggle('d-none',      fp !== 'elektroniczna');
  document.getElementById('epodpis_fields').classList.toggle('d-none', fp !== 'epodpis_kwalifikowany');
});

/* ── M365 ──────────────────────────────────────────────── */
document.getElementById('m365_konto').addEventListener('change', function () {
  document.getElementById('m365_manual_fields').classList.toggle('d-none', !this.checked);
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
