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

<style>
/* ── Pasek kroków ────────────────────────────────────────────────── */
.wiz-hsteps {
  display: flex; align-items: center; gap: 0;
  padding: .75rem 1.25rem; overflow-x: auto;
}
.wiz-hstep {
  display: flex; flex-direction: column; align-items: center; gap: .2rem;
  padding: .5rem .9rem; background: transparent; border: none; cursor: pointer;
  min-width: 90px; transition: .15s;
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

/* ── Sekcje formularza ───────────────────────────────────────────── */
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

/* ── Sticky nav ──────────────────────────────────────────────────── */
.wiz-sticky-nav {
  position: sticky; bottom: 0; z-index: 100;
  background: rgba(255,255,255,.97); backdrop-filter: blur(4px);
  border-top: 1px solid #E5E7EB; padding: .8rem 1rem;
  display: flex; align-items: center; gap: .6rem;
  box-shadow: 0 -4px 12px rgba(0,0,0,.07);
}
</style>

<form method="post" enctype="multipart/form-data" id="dzieloForm">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- ── Nagłówek ──────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px">
      <i class="bi bi-palette text-primary" style="font-size:1.1rem"></i>
    </span>
    <div>
      <h4 class="mb-0">Nowa umowa o dzieło</h4>
      <div class="text-muted small"><?= h($row['numer_umowy']) ?></div>
    </div>
  </div>
  <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
<ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<!-- ── Pasek kroków ──────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <nav class="wiz-hsteps" id="wizSteps" aria-label="Kroki formularza">
    <button type="button" class="wiz-hstep active" id="step-btn-1" onclick="goToStep(1)">
      <span class="wiz-hstep-num" id="step-num-1">1</span>
      <span class="wiz-hstep-label">Strony</span>
    </button>
    <div class="wiz-hstep-sep" id="step-sep-1"></div>
    <button type="button" class="wiz-hstep" id="step-btn-2" onclick="goToStep(2)">
      <span class="wiz-hstep-num" id="step-num-2">2</span>
      <span class="wiz-hstep-label">Dzieło</span>
    </button>
    <div class="wiz-hstep-sep" id="step-sep-2"></div>
    <button type="button" class="wiz-hstep" id="step-btn-3" onclick="goToStep(3)">
      <span class="wiz-hstep-num" id="step-num-3">3</span>
      <span class="wiz-hstep-label">Podpisanie</span>
    </button>
    <div class="wiz-hstep-sep" id="step-sep-3"></div>
    <button type="button" class="wiz-hstep" id="step-btn-4" onclick="goToStep(4)">
      <span class="wiz-hstep-num" id="step-num-4">4</span>
      <span class="wiz-hstep-label">Finalizacja</span>
    </button>
  </nav>
</div>

<!-- ═══════════ KROK 1 — STRONY ═══════════ -->
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
          <?php foreach(['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $k=>$v):
            $sel = ($row['status']??'')===$k?'selected':''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">projekt → podpisana → w realizacji → zakończona</div>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Data zawarcia</label>
        <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']??'') ?>">
      </div>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Numer projektu / źródło finansowania</label>
        <input name="numer_projektu" class="form-control" value="<?= h($row['numer_projektu']??'') ?>">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Opiekun umowy</label>
        <input name="opiekun" class="form-control" value="<?= h($row['opiekun']??'') ?>">
      </div>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-person-fill"></i></span>
    Wykonawca
  </div>
  <div class="form-section-body">
    <!-- Person picker -->
    <div class="mb-3">
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
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Imię i nazwisko</label>
        <input name="imie_nazwisko" id="dz_imie" class="form-control" value="<?= h($row['imie_nazwisko']??'') ?>">
        <?= byli_check_field('imie_nazwisko') ?>
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">PESEL</label>
        <input name="pesel" id="dz_pesel" class="form-control" maxlength="11" pattern="\d{11}" value="<?= h($row['pesel']??'') ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Komórka organizacyjna</label>
        <select name="org_unit_id" class="form-select">
          <option value="">— wybierz —</option>
          <?php foreach ($_units as $u): ?>
          <option value="<?= h($u['id']) ?>" <?= ($row['org_unit_id']??'')==$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania / siedziby</label>
      <?= address_widget($row, ['copy_button'=>true,'autocomplete'=>true,'widget_id'=>'dzieloAddrWidget']) ?>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">E-mail kontrahenta</label>
        <input type="email" name="email" id="dz_email" class="form-control" placeholder="jan.kowalski@email.pl" value="<?= h($row['email']??'') ?>">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Urząd skarbowy</label>
        <input name="urzad_skarbowy" id="dz_urzad" class="form-control" value="<?= h($row['urzad_skarbowy']??'') ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">Rachunek bankowy</label>
      <input name="rachunek_bankowy" id="dz_rachunek" class="form-control" placeholder="XX XXXX XXXX..." value="<?= h($row['rachunek_bankowy']??'') ?>">
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

</div><!-- /krok 1 -->

<!-- ═══════════ KROK 2 — DZIEŁO ═══════════ -->
<div class="wiz-step d-none" id="wiz-step-2">

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#FEF3E2;color:#D97706"><i class="bi bi-file-earmark-text-fill"></i></span>
    Opis i wynagrodzenie
  </div>
  <div class="form-section-body">
    <div class="mb-3">
      <label class="form-label">Opis dzieła</label>
      <textarea name="opis_dziela" class="form-control" rows="4" placeholder="Dokładny opis dzieła będącego przedmiotem umowy…"><?= h($row['opis_dziela']??'') ?></textarea>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Termin oddania</label>
        <input name="termin_oddania" type="date" class="form-control" value="<?= h($row['termin_oddania']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Wynagrodzenie brutto (PLN)</label>
        <div class="input-group">
          <input name="wynagrodzenie_brutto" type="number" step="0.01" min="0" class="form-control fw-semibold" value="<?= h($row['wynagrodzenie_brutto']??'') ?>">
          <span class="input-group-text">PLN</span>
        </div>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Zaliczka na podatek (PLN)</label>
        <div class="input-group">
          <input name="zaliczka_podatek" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['zaliczka_podatek']??'') ?>">
          <span class="input-group-text">PLN</span>
        </div>
      </div>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="kup50" id="kup50" value="1" <?= !empty($row['kup50'])?'checked':'' ?>>
      <label class="form-check-label" for="kup50">50% koszty uzyskania przychodu (KUP) — prawa autorskie</label>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F5F3FF;color:#7C3AED"><i class="bi bi-c-circle-fill"></i></span>
    Prawa autorskie
  </div>
  <div class="form-section-body">
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="prawa_autorskie" id="prawa_autorskie" value="1"
        <?= !empty($row['prawa_autorskie'])?'checked':'' ?> onchange="toggleZakres(this)">
      <label class="form-check-label" for="prawa_autorskie">Przeniesienie praw autorskich</label>
    </div>
    <div id="zakres_praw_field" class="<?= !empty($row['prawa_autorskie'])?'':'d-none' ?>">
      <label class="form-label">Zakres praw autorskich</label>
      <textarea name="zakres_praw" class="form-control" rows="3"><?= h($row['zakres_praw']??'') ?></textarea>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#EFF7ED;color:#16A34A"><i class="bi bi-check2-circle"></i></span>
    Odbiór dzieła
  </div>
  <div class="form-section-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Data odbioru</label>
        <input name="data_odbioru" type="date" class="form-control" value="<?= h($row['data_odbioru']??'') ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Data złożenia rachunku</label>
        <input name="data_zl_rachunku" type="date" class="form-control" value="<?= h($row['data_zl_rachunku']??'') ?>">
      </div>
      <div class="col-md-4 mb-3 pt-4 d-flex gap-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="wymagany_protokol" id="wymagany_protokol" value="1" <?= !empty($row['wymagany_protokol'])?'checked':'' ?>>
          <label class="form-check-label" for="wymagany_protokol">Wymagany protokół</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="dzielo_przyjete" id="dzielo_przyjete" value="1" <?= !empty($row['dzielo_przyjete'])?'checked':'' ?>>
          <label class="form-check-label" for="dzielo_przyjete">Dzieło przyjęte</label>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /krok 2 -->

<!-- ═══════════ KROK 3 — PODPISANIE ═══════════ -->
<div class="wiz-step d-none" id="wiz-step-3">

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#FEE2E2;color:#DC2626"><i class="bi bi-pen-fill"></i></span>
    Forma i podpisanie
  </div>
  <div class="form-section-body">
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
      <div class="col-md-4 mb-3">
        <label class="form-label">Podpisujący ze strony Fundacji</label>
        <input name="podpisujacy_fundacja" class="form-control" value="<?= h($row['podpisujacy_fundacja']??'') ?>" placeholder="np. Jan Prezes">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Stanowisko podpisującego</label>
        <input name="podpisujacy_stanowisko" class="form-control" value="<?= h($row['podpisujacy_stanowisko']??'') ?>" placeholder="np. Prezes Zarządu">
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
        <input name="epodpis_dostawca" class="form-control" placeholder="Certum, SimplySign, mSzafir…" value="<?= h($row['epodpis_dostawca']??'') ?>">
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

</div><!-- /krok 3 -->

<!-- ═══════════ KROK 4 — FINALIZACJA ═══════════ -->
<div class="wiz-step d-none" id="wiz-step-4">

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

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#F8FAFC;color:#6B7280"><i class="bi bi-chat-left-text"></i></span>
    Uwagi
  </div>
  <div class="form-section-body">
    <div class="mb-3">
      <textarea name="uwagi" class="form-control" rows="3" placeholder="Dodatkowe informacje, zastrzeżenia, notatki…"><?= h($row['uwagi']??'') ?></textarea>
    </div>
  </div>
</div>

<div class="form-section">
  <div class="form-section-head">
    <span class="form-section-icon" style="background:#FEF9EC;color:#B45309"><i class="bi bi-shield-lock-fill"></i></span>
    Dostęp do umowy
  </div>
  <div class="form-section-body">
    <?= contract_access_field_html([]) ?>
  </div>
</div>

<div class="form-check mb-3">
  <input class="form-check-input" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
  <label class="form-check-label text-muted" for="nie_mam_drukarki">
    <i class="bi bi-printer me-1"></i>Nie mam drukarki — zapisz umowę jako PDF do późniejszego wydruku
  </label>
</div>

</div><!-- /krok 4 -->

<!-- ── Sticky nawigacja ──────────────────────────────────────────── -->
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
/* ── Wizard: nawigacja krokowa ───────────────────────────── */
(function () {
  'use strict';
  var STEPS = 4;
  var cur   = 1;
  var wizBack = document.getElementById('wizBack');
  var wizNext = document.getElementById('wizNext');
  var wizSave = document.getElementById('wizSave');

  function goToStep(n) {
    var prev = cur;
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

/* ── Forma podpisania ────────────────────────────────────── */
document.getElementById('forma_podpisania').addEventListener('change', function () {
  var fp = this.value;
  document.getElementById('el_fields').classList.toggle('d-none',       fp !== 'elektroniczna');
  document.getElementById('epodpis_fields').classList.toggle('d-none',  fp !== 'epodpis_kwalifikowany');
});

/* ── Prawa autorskie ─────────────────────────────────────── */
function toggleZakres(cb) {
  document.getElementById('zakres_praw_field').classList.toggle('d-none', !cb.checked);
}

/* ── M365 ────────────────────────────────────────────────── */
document.getElementById('m365_konto').addEventListener('change', function () {
  document.getElementById('m365_manual_fields').classList.toggle('d-none', !this.checked);
});

/* ── CEIDG ───────────────────────────────────────────────── */
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
