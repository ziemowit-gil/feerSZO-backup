<?php
/**
 * Kreator (wizard) — uproszczone dodawanie porozumienia wolontariackiego.
 * 3 kroki:  1. Wolontariusz  2. Porozumienie  3. Podsumowanie + zapis
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/grants.php';

require_role('admin', 'editor');
require_module_enabled('contract_wolontariat', 'Umowy wolontariackie');

$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';
$errors = [];

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $d = [
        'numer_umowy'          => trim($_POST['numer_umowy']      ?? ''),
        'status'               => 'projekt',
        'imie_nazwisko'        => trim($_POST['imie_nazwisko']     ?? ''),
        'pesel'                => trim($_POST['pesel']             ?? ''),
        'data_urodzenia'       => $_POST['data_urodzenia']         ?? '',
        'email'                => trim($_POST['email']             ?? ''),
        'telefon'              => trim($_POST['telefon']           ?? ''),
        'adres'                => trim($_POST['adres']             ?? ''),
        'niepelnoletni'        => isset($_POST['niepelnoletni'])   ? 1 : 0,
        'data_zawarcia'        => $_POST['data_zawarcia']          ?? '',
        'data_rozpoczecia'     => $_POST['data_rozpoczecia']       ?? '',
        'data_zakonczenia'     => $_POST['data_zakonczenia']       ?? '',
        'bezterminowa'         => isset($_POST['bezterminowa'])    ? 1 : 0,
        'miejsce_wolontariatu' => trim($_POST['miejsce_wolontariatu'] ?? ''),
        'przedmiot_porozumienia'=> trim($_POST['przedmiot_porozumienia'] ?? ''),
        'opiekun'              => trim($_POST['opiekun']           ?? ''),
        'projekt_program'      => trim($_POST['projekt_program']   ?? ''),
        'person_id'            => (int)($_POST['person_id']        ?? 0) ?: null,
        'action_id'            => (int)($_POST['action_id']        ?? 0) ?: null,
        'uwagi'                        => trim($_POST['uwagi']                        ?? ''),
        'm365_security_group_id'       => trim($_POST['m365_security_group_id']       ?? '') ?: null,
        'm365_security_group_name'     => trim($_POST['m365_security_group_name']     ?? '') ?: null,
        'created_by'           => (int)current_user()['id'],
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ];

    // Puste daty → NULL (SQLite nie lubi pustych stringów w kolumnach DATE)
    foreach (['data_urodzenia','data_zawarcia','data_rozpoczecia','data_zakonczenia'] as $df) {
        if (empty($d[$df])) $d[$df] = null;
    }

    if (!$d['numer_umowy'])   $errors[] = 'Numer umowy jest wymagany.';
    if (!$d['imie_nazwisko']) $errors[] = 'Imię i nazwisko wolontariusza jest wymagane.';

    if (!$errors) {
        // Generuj kod odzyskiwania (8 cyfr) tylko dla umów sprzed 01.06.2026
        $recovery_plain = null;
        $zawarcia = $d['data_zawarcia'] ?? '';
        if ($zawarcia !== '' && $zawarcia < '2026-06-01') {
            $recovery_plain = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $d['recovery_code_hash'] = password_hash($recovery_plain, PASSWORD_BCRYPT);
        }

        // Plik przed insertem żeby mieć nazwę
        $plik = handle_upload('plik_umowy', $TYPE);
        if ($plik) $d['plik_umowy'] = $plik;

        $id = db_insert($TABLE, $d);

        // Auto-przekazanie do akceptacji
        submit_for_approval($TYPE, $id, (int)current_user()['id'], $d['numer_umowy']);
        log_system_action((int)current_user()['id'], 'contract_create',
            "Dodano porozumienie {$TYPE} #{$id} przez kreator: " . $d['imie_nazwisko']);

        // Przekaż kod plain jednorazowo przez sesję (tylko gdy wygenerowany)
        if ($recovery_plain !== null) {
            $_SESSION['recovery_code_plain_' . $id] = $recovery_plain;
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}&show_recovery=1");
        } else {
            header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}");
        }
        exit;
    }
}

$numer_default = next_contract_number($TYPE);
$PAGE_TITLE = 'Kreator porozumienia wolontariackiego';

// Dane do autouzupełniania z URL (opcjonalne)
$prefill = [];
auth_start();
if (!empty($_SESSION['ob_prefill'])) {
    $prefill = $_SESSION['ob_prefill'];
    unset($_SESSION['ob_prefill']);
}

// Konfiguracja wymagalności pól
$_wiz_field_keys = ['imie_nazwisko','email','pesel','data_urodzenia','telefon','adres',
                    'numer_umowy','data_zawarcia','data_rozpoczecia','data_zakonczenia',
                    'opiekun','miejsce_wolontariatu','przedmiot_porozumienia','projekt_program',
                    'm365_security_group_id'];
$_wiz_field_cfg = [];
foreach ($_wiz_field_keys as $_fk) {
    $_wiz_field_cfg[$_fk] = org_setting('form_field_wolontariat_' . $_fk) ?: 'recommended';
}

// Lista opiekunów (użytkownicy aktywni)
$opiekunowie = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");

// Granty i działania dla kroku 2
try { $__actions = db_all("SELECT id, nazwa FROM actions WHERE status NOT IN ('anulowane','zakończone') ORDER BY nazwa"); }
catch (\Throwable $e) { $__actions = []; }
try { $__grants = db_all("SELECT id, nazwa, donator FROM grants WHERE status IN ('przyznany','w realizacji','rozliczany') ORDER BY nazwa"); }
catch (\Throwable $e) { $__grants = []; }

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Kroki ─────────────────────────────────────────────────────────────────── */
.wz-step { display:none }
.wz-step.active { display:block }

.wz-stepper { display:flex; gap:0; margin-bottom:2rem; counter-reset:step }
.wz-stepper-item {
  flex:1; text-align:center; position:relative;
  --clr: #dee2e6; --txt: #6c757d;
}
.wz-stepper-item.done  { --clr:#198754; --txt:#198754 }
.wz-stepper-item.active{ --clr:#0d6efd; --txt:#0d6efd }

.wz-stepper-item:not(:last-child)::after {
  content:''; position:absolute; top:19px; left:50%; width:100%;
  height:2px; background:var(--clr); z-index:0;
}
.wz-stepper-item .wz-dot {
  width:38px; height:38px; border-radius:50%;
  background:#fff; border:2px solid var(--clr);
  display:inline-flex; align-items:center; justify-content:center;
  font-weight:700; color:var(--txt); font-size:.9rem;
  position:relative; z-index:1; transition:.2s
}
.wz-stepper-item.done .wz-dot  { background:#198754; color:#fff }
.wz-stepper-item.active .wz-dot{ background:#0d6efd; color:#fff }
.wz-stepper-item .wz-label { font-size:.78rem; margin-top:.35rem; color:var(--txt); font-weight:500 }

/* ── Karta kroku ────────────────────────────────────────────────────────────── */
.wz-card {
  background:#fff; border:1px solid #dee2e6; border-radius:.75rem;
  padding:2rem; box-shadow:0 2px 12px rgba(0,0,0,.06);
  animation: fadeSlideIn .22s ease
}
@keyframes fadeSlideIn { from{ opacity:0; transform:translateY(8px) } to{ opacity:1; transform:none } }

/* ── Podsumowanie ────────────────────────────────────────────────────────────── */
.review-row { display:flex; gap:1rem; padding:.45rem 0; border-bottom:1px solid #f0f0f0 }
.review-row:last-child { border:none }
.review-label { flex:0 0 180px; color:#6c757d; font-size:.82rem }
.review-value { font-size:.88rem; font-weight:500; flex:1 }

/* ── Person picker dropdown ─────────────────────────────────────────────────── */
#wz_person_results {
  position:absolute; z-index:1050; background:#fff;
  border:1px solid #dee2e6; border-radius:.5rem;
  box-shadow:0 4px 20px rgba(0,0,0,.1); min-width:340px; max-width:520px;
  max-height:280px; overflow-y:auto
}
.wz-person-item {
  padding:.6rem 1rem; cursor:pointer; border-bottom:1px solid #f5f5f5;
  transition:background .1s
}
.wz-person-item:hover { background:#f8f9fa }

/* ── Pola autofill highlight ─────────────────────────────────────────────────── */
@keyframes fillPulse { 0%{box-shadow:0 0 0 0 rgba(25,135,84,.4)} 70%{box-shadow:0 0 0 8px rgba(25,135,84,0)} 100%{box-shadow:none} }
.autofilled { animation: fillPulse .7s ease }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="list.php">Wolontariat</a></li>
    <li class="breadcrumb-item"><a href="new.php">Nowe</a></li>
    <li class="breadcrumb-item active">Kreator</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <span class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center flex-shrink-0"
        style="width:48px;height:48px">
    <i class="bi bi-magic text-success" style="font-size:1.4rem"></i>
  </span>
  <div>
    <h4 class="mb-0">Kreator porozumienia wolontariackiego</h4>
    <div class="text-muted small">Wypełnij 3 kroki i gotowe — całość zajmuje ~2 minuty</div>
  </div>
  <a href="add.php" class="btn btn-outline-secondary btn-sm ms-auto">
    <i class="bi bi-card-list me-1"></i>Pełny formularz
  </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <ul class="mb-0 ps-2"><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul>
</div>
<?php endif; ?>

<!-- ── Pasek kroków ────────────────────────────────────────────────────────── -->
<div class="wz-stepper mb-4" id="wz-stepper">
  <div class="wz-stepper-item active" data-step="1">
    <div class="wz-dot"><i class="bi bi-person-fill" style="font-size:.9rem"></i></div>
    <div class="wz-label">Wolontariusz</div>
  </div>
  <div class="wz-stepper-item" data-step="2">
    <div class="wz-dot"><i class="bi bi-calendar3" style="font-size:.9rem"></i></div>
    <div class="wz-label">Porozumienie</div>
  </div>
  <div class="wz-stepper-item" data-step="3">
    <div class="wz-dot"><i class="bi bi-check-lg" style="font-size:.9rem"></i></div>
    <div class="wz-label">Podsumowanie</div>
  </div>
</div>

<form method="post" enctype="multipart/form-data" id="wz-form">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="person_id" id="wz_person_id">

<!-- ════════════════════════════════════════════════════════════════════
     KROK 1 — Wolontariusz
     ════════════════════════════════════════════════════════════════════ -->
<div class="wz-step active" id="step-1">
<div class="wz-card">

  <h5 class="fw-bold mb-1"><i class="bi bi-person-fill text-primary me-2"></i>Dane wolontariusza</h5>
  <p class="text-muted small mb-4">Wyszukaj istniejącą osobę lub wpisz dane ręcznie</p>

  <!-- Person picker -->
  <div class="mb-4 pb-4 border-bottom position-relative">
    <label class="form-label fw-semibold small text-uppercase text-muted" style="letter-spacing:.05em">
      <i class="bi bi-search me-1"></i>Szybkie wyszukiwanie w rejestrze osób
    </label>
    <div class="input-group input-group-lg shadow-sm" style="max-width:560px">
      <span class="input-group-text bg-white border-end-0">
        <i class="bi bi-person-lines-fill text-primary"></i>
      </span>
      <input type="text" id="wz_person_search" class="form-control border-start-0 ps-0"
             placeholder="Wpisz imię, PESEL lub e-mail…" autocomplete="off">
      <button type="button" class="btn btn-outline-secondary" id="wz_person_clear"
              style="display:none" title="Wyczyść"><i class="bi bi-x-lg"></i></button>
      <a href="<?= APP_URL ?>/persons/add.php" class="btn btn-outline-primary" target="_blank">
        <i class="bi bi-person-plus me-1"></i>Nowa osoba
      </a>
    </div>
    <div id="wz_person_results" style="display:none"></div>
    <div id="wz_person_badge" class="mt-2" style="display:none">
      <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-3 py-2">
        <i class="bi bi-check-circle me-1"></i>
        <span id="wz_person_badge_name"></span> — dane uzupełnione automatycznie
      </span>
    </div>
  </div>

  <!-- Pola danych -->
  <div class="row g-3">
    <div class="col-sm-7">
      <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
      <input name="imie_nazwisko" id="wz_imie_nazwisko" class="form-control form-control-lg"
             value="<?= h($prefill['imie_nazwisko'] ?? '') ?>"
             placeholder="Jan Kowalski" required autofocus>
    </div>
    <div class="col-sm-5">
      <label class="form-label fw-semibold">
        E-mail <span class="fw-normal text-muted small">(login do panelu)</span>
      </label>
      <input name="email" id="wz_email" type="email" class="form-control form-control-lg"
             value="<?= h($prefill['email'] ?? '') ?>" placeholder="jan@email.com">
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">Telefon</label>
      <div class="input-group">
        <span class="input-group-text text-muted fw-semibold">+48</span>
        <input name="telefon" id="wz_telefon" class="form-control" type="tel"
               placeholder="123 456 789" value="<?= h($prefill['telefon'] ?? '') ?>">
      </div>
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">
        PESEL
        <span id="wz_gender_badge" class="badge ms-1 bg-secondary" style="display:none;font-size:.65rem"></span>
      </label>
      <input name="pesel" id="wz_pesel" class="form-control font-monospace"
             maxlength="11" pattern="\d{11}"
             value="<?= h($prefill['pesel'] ?? '') ?>" placeholder="00000000000" autocomplete="off">
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">
        Data urodzenia
        <span id="wz_dob_hint" class="text-success fw-normal small ms-1" style="display:none">
          <i class="bi bi-magic"></i> z PESEL
        </span>
      </label>
      <input name="data_urodzenia" id="wz_dob" type="date" class="form-control"
             value="<?= h($prefill['data_urodzenia'] ?? '') ?>">
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">Adres zamieszkania</label>
      <input name="adres" id="wz_adres" class="form-control"
             value="<?= h($prefill['adres'] ?? '') ?>"
             placeholder="ul. Przykładowa 1, 00-001 Warszawa">
    </div>
    <div class="col-12">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="niepelnoletni" id="wz_niepelnoletni"
               value="1" <?= !empty($prefill['niepelnoletni'])?'checked':'' ?>>
        <label class="form-check-label fw-semibold" for="wz_niepelnoletni">
          <i class="bi bi-person-exclamation text-warning me-1"></i>Osoba niepełnoletnia
        </label>
        <span class="text-muted small ms-2">
          — po zapisaniu uzupełnij dane opiekuna prawnego w widoku umowy
        </span>
      </div>
    </div>
  </div>
</div>

<!-- nawigacja -->
<div class="d-flex justify-content-between mt-3">
  <a href="new.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Cofnij
  </a>
  <button type="button" class="btn btn-primary btn-lg px-5" id="btn-next-1">
    Dalej <i class="bi bi-arrow-right ms-1"></i>
  </button>
</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     KROK 2 — Szczegóły porozumienia
     ════════════════════════════════════════════════════════════════════ -->
<div class="wz-step" id="step-2">
<div class="wz-card">

  <h5 class="fw-bold mb-1"><i class="bi bi-calendar3 text-primary me-2"></i>Szczegóły porozumienia</h5>
  <p class="text-muted small mb-4">Daty, zakres działania i projekt</p>

  <div class="row g-3">
    <div class="col-sm-5">
      <label class="form-label fw-semibold">Numer umowy <span class="text-danger">*</span></label>
      <input name="numer_umowy" class="form-control font-monospace fw-bold"
             value="<?= h($numer_default) ?>" required>
    </div>
    <div class="col-sm-7">
      <label class="form-label fw-semibold">Opiekun wolontariusza</label>
      <select name="opiekun" class="form-select">
        <option value="">— brak —</option>
        <?php foreach ($opiekunowie as $op):
          $sel = ($prefill['opiekun']??'')===$op['name']?'selected':''; ?>
        <option value="<?= h($op['name']) ?>" <?= $sel ?>><?= h($op['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">Data zawarcia</label>
      <input name="data_zawarcia" type="date" class="form-control"
             value="<?= date('Y-m-d') ?>">
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">Data rozpoczęcia</label>
      <input name="data_rozpoczecia" type="date" class="form-control"
             value="<?= date('Y-m-d') ?>">
    </div>
    <div class="col-sm-4">
      <label class="form-label fw-semibold">Data zakończenia</label>
      <input name="data_zakonczenia" id="wz_zakon" type="date" class="form-control">
      <div class="form-check mt-1">
        <input class="form-check-input" type="checkbox" name="bezterminowa"
               id="wz_bezterminowa" value="1">
        <label class="form-check-label small" for="wz_bezterminowa">Bezterminowa</label>
      </div>
    </div>
    <div class="col-sm-6">
      <label class="form-label fw-semibold">Działanie</label>
      <select name="action_id" id="wz_action_id" class="form-select">
        <option value="">— brak —</option>
        <?php foreach ($__actions as $a): ?>
        <option value="<?= h($a['id']) ?>" data-nazwa="<?= h($a['nazwa']) ?>"><?= h($a['nazwa']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6">
      <label class="form-label fw-semibold">
        Projekt / program
        <span id="wz_pp_hint" class="text-success fw-normal small ms-1" style="display:none">
          <i class="bi bi-magic"></i> z działania
        </span>
      </label>
      <input name="projekt_program" id="wz_projekt_program" class="form-control"
             placeholder="np. Projekt A 2025" value="<?= h($prefill['projekt_program'] ?? '') ?>">
    </div>
    <div class="col-sm-6">
      <label class="form-label fw-semibold">Miejsce wolontariatu</label>
      <input name="miejsce_wolontariatu" class="form-control"
             placeholder="Siedziba / zdalnie" value="">
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">Przedmiot porozumienia</label>
      <textarea name="przedmiot_porozumienia" class="form-control" rows="4"
                placeholder="Opis zakresu działań wolontariusza…"></textarea>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">Uwagi <span class="fw-normal text-muted small">(wewnętrzne)</span></label>
      <textarea name="uwagi" class="form-control" rows="2"
                placeholder="Opcjonalne notatki…"></textarea>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">
        <i class="bi bi-paperclip me-1 text-muted"></i>Plik porozumienia
        <span class="fw-normal text-muted small">(opcjonalny)</span>
      </label>
      <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
    </div>
  </div>
</div>

<div class="d-flex justify-content-between mt-3">
  <button type="button" class="btn btn-outline-secondary" id="btn-prev-2">
    <i class="bi bi-arrow-left me-1"></i>Wstecz
  </button>
  <button type="button" class="btn btn-primary btn-lg px-5" id="btn-next-2">
    Dalej <i class="bi bi-arrow-right ms-1"></i>
  </button>
</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     KROK 3 — Podsumowanie i zapis
     ════════════════════════════════════════════════════════════════════ -->
<div class="wz-step" id="step-3">
<div class="wz-card">

  <h5 class="fw-bold mb-1"><i class="bi bi-check-circle text-success me-2"></i>Podsumowanie — sprawdź przed zapisem</h5>
  <p class="text-muted small mb-4">Po kliknięciu „Zapisz i przekaż do akceptacji" umowa trafi do kolejki zatwierdzania</p>

  <!-- Sekcja: Wolontariusz -->
  <div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fw-semibold">
        <i class="bi bi-person-fill me-1"></i>Wolontariusz
      </span>
      <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="goStep(1)">
        <i class="bi bi-pencil-square me-1"></i>Edytuj
      </button>
    </div>
    <div id="review-wolontariusz">
      <div class="review-row"><div class="review-label">Imię i nazwisko</div><div class="review-value" id="rv-name">—</div></div>
      <div class="review-row"><div class="review-label">PESEL</div><div class="review-value font-monospace" id="rv-pesel">—</div></div>
      <div class="review-row"><div class="review-label">Data urodzenia</div><div class="review-value" id="rv-dob">—</div></div>
      <div class="review-row"><div class="review-label">E-mail</div><div class="review-value" id="rv-email">—</div></div>
      <div class="review-row"><div class="review-label">Telefon</div><div class="review-value" id="rv-telefon">—</div></div>
      <div class="review-row"><div class="review-label">Adres</div><div class="review-value" id="rv-adres">—</div></div>
    </div>
  </div>

  <!-- Sekcja: Porozumienie -->
  <div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 fw-semibold">
        <i class="bi bi-calendar3 me-1"></i>Porozumienie
      </span>
      <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="goStep(2)">
        <i class="bi bi-pencil-square me-1"></i>Edytuj
      </button>
    </div>
    <div id="review-porozumienie">
      <div class="review-row"><div class="review-label">Numer umowy</div><div class="review-value font-monospace fw-bold" id="rv-numer">—</div></div>
      <div class="review-row"><div class="review-label">Opiekun</div><div class="review-value" id="rv-opiekun">—</div></div>
      <div class="review-row"><div class="review-label">Data zawarcia</div><div class="review-value" id="rv-zawarcia">—</div></div>
      <div class="review-row"><div class="review-label">Okres</div><div class="review-value" id="rv-okres">—</div></div>
      <div class="review-row"><div class="review-label">Projekt / program</div><div class="review-value" id="rv-projekt">—</div></div>
      <div class="review-row"><div class="review-label">Miejsce</div><div class="review-value" id="rv-miejsce">—</div></div>
      <div class="review-row"><div class="review-label">Przedmiot</div><div class="review-value" id="rv-przedmiot" style="white-space:pre-wrap">—</div></div>
    </div>
  </div>

  <!-- Sekcja: M365 Security Group (podgląd) -->
  <div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fw-semibold">
        <i class="bi bi-microsoft me-1"></i>Microsoft 365
      </span>
    </div>
    <div id="review-m365">
      <div class="review-row">
        <div class="review-label">Security Group</div>
        <div class="review-value" id="rv-sg">
          <span class="text-danger fst-italic">Nie wybrano — wymagane</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Sekcja: Security Group M365 -->
  <div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fw-semibold">
        <i class="bi bi-microsoft me-1"></i>Microsoft 365
      </span>
    </div>
    <label class="form-label fw-semibold">
      Security Group <span class="text-danger">*</span>
      <span class="fw-normal text-muted small ms-1">— wolontariusz zostanie dodany do tej grupy</span>
    </label>
    <div class="input-group">
      <span class="input-group-text bg-white"><i class="bi bi-people-fill text-primary"></i></span>
      <select name="m365_security_group_id" id="wz_sg_id" class="form-select" required>
        <option value="">— ładowanie grup… —</option>
      </select>
      <button type="button" class="btn btn-outline-secondary" id="wz_sg_refresh" title="Odśwież listę grup">
        <i class="bi bi-arrow-clockwise"></i>
      </button>
    </div>
    <input type="hidden" name="m365_security_group_name" id="wz_sg_name">
    <div id="wz_sg_status" class="form-text mt-1"></div>
  </div>

  <!-- Info o akceptacji -->
  <div class="alert alert-info d-flex gap-2 mb-0">
    <i class="bi bi-send-check-fill flex-shrink-0 mt-1"></i>
    <div>
      Porozumienie zostanie zapisane ze statusem <strong>Projekt</strong> i automatycznie
      przekazane do akceptacji przez administratora. Wolontariusz otrzyma e-mail z dostępem
      do panelu po zatwierdzeniu.
    </div>
  </div>
</div>

<div class="d-flex justify-content-between mt-3">
  <button type="button" class="btn btn-outline-secondary" id="btn-prev-3">
    <i class="bi bi-arrow-left me-1"></i>Wstecz
  </button>
  <button type="submit" class="btn btn-success btn-lg px-5 shadow-sm" id="btn-submit">
    <i class="bi bi-check-lg me-2"></i>Zapisz i przekaż do akceptacji
  </button>
</div>
</div>

</form>

<script>
// ── State ─────────────────────────────────────────────────────────────────────
var currentStep = <?= $errors ? 1 : 1 ?>;

function goStep(n) {
  // Aktualizuj kroki
  document.querySelectorAll('.wz-step').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('step-' + n).classList.add('active');
  document.querySelectorAll('.wz-stepper-item').forEach(function(el, i) {
    el.classList.remove('active','done');
    if (i + 1 < n) el.classList.add('done');
    if (i + 1 === n) el.classList.add('active');
  });
  currentStep = n;
  window.scrollTo({ top: 0, behavior: 'smooth' });

  if (n === 3) fillReview();
}

// ── Nawigacja ─────────────────────────────────────────────────────────────────
document.getElementById('btn-next-1')?.addEventListener('click', function() {
  var name = document.querySelector('[name="imie_nazwisko"]').value.trim();
  if (!name) {
    document.querySelector('[name="imie_nazwisko"]').focus();
    document.querySelector('[name="imie_nazwisko"]').classList.add('is-invalid');
    return;
  }
  document.querySelector('[name="imie_nazwisko"]').classList.remove('is-invalid');
  goStep(2);
});
document.getElementById('btn-next-2')?.addEventListener('click', function() { goStep(3); });
document.getElementById('btn-prev-2')?.addEventListener('click', function() { goStep(1); });
document.getElementById('btn-prev-3')?.addEventListener('click', function() { goStep(2); });

// ── PESEL helpers ─────────────────────────────────────────────────────────────
function peselToBirthdate(p) {
  if (p.length !== 11) return '';
  var y=parseInt(p.substr(0,2),10), m=parseInt(p.substr(2,2),10), d=parseInt(p.substr(4,2),10);
  if(m>=81){y+=1800;m-=80;} else if(m>=61){y+=2200;m-=60;}
  else if(m>=41){y+=2100;m-=40;} else if(m>=21){y+=2000;m-=20;} else{y+=1900;}
  if(m<1||m>12||d<1||d>31) return '';
  return y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
}
function peselGender(p) {
  if (p.length !== 11) return null;
  return parseInt(p[9],10) % 2 === 0 ? 'K' : 'M';
}

document.getElementById('wz_pesel')?.addEventListener('input', function() {
  var p = this.value.replace(/\D/g,'');
  var dob = document.getElementById('wz_dob');
  var hint = document.getElementById('wz_dob_hint');
  var badge = document.getElementById('wz_gender_badge');
  if (p.length === 11) {
    var bd = peselToBirthdate(p);
    if (bd && !dob.value) { dob.value = bd; if (hint) hint.style.display = ''; }
    var g = peselGender(p);
    if (badge && g) {
      badge.textContent = g === 'K' ? '♀ Kobieta' : '♂ Mężczyzna';
      badge.className = 'badge ms-1 ' + (g==='K'?'bg-pink text-white':'bg-info text-white');
      badge.style.display = '';
    }
  } else {
    if (badge) badge.style.display = 'none';
  }
});

// ── Bezterminowa ──────────────────────────────────────────────────────────────
document.getElementById('wz_bezterminowa')?.addEventListener('change', function() {
  var el = document.getElementById('wz_zakon');
  el.disabled = this.checked;
  if (this.checked) el.value = '';
});

// ── Person picker ─────────────────────────────────────────────────────────────
(function() {
  var search  = document.getElementById('wz_person_search');
  var results = document.getElementById('wz_person_results');
  var hiddenId = document.getElementById('wz_person_id');
  var clearBtn = document.getElementById('wz_person_clear');
  var badge    = document.getElementById('wz_person_badge');
  var badgeName= document.getElementById('wz_person_badge_name');
  if (!search) return;

  function autofill(field, val) {
    var el = document.getElementById(field);
    if (el && val) {
      el.value = val;
      el.classList.add('autofilled');
      setTimeout(function() { el.classList.remove('autofilled'); }, 1000);
    }
  }

  var timer;
  search.addEventListener('input', function() {
    clearTimeout(timer);
    hiddenId.value = '';
    clearBtn.style.display = 'none';
    if (badge) badge.style.display = 'none';
    var q = this.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }
    timer = setTimeout(function() {
      fetch('<?= APP_URL ?>/persons/search.php?q=' + encodeURIComponent(q))
        .then(r => r.json()).then(function(data) {
          results.innerHTML = '';
          if (!data.length) {
            results.innerHTML = '<div class="wz-person-item text-muted small">Nie znaleziono. '
              + '<a href="<?= APP_URL ?>/persons/add.php" target="_blank">Dodaj nową osobę</a>.</div>';
          } else {
            data.forEach(function(p) {
              var item = document.createElement('div');
              item.className = 'wz-person-item';
              item.innerHTML =
                '<div class="fw-semibold">' + (p.imie_nazwisko||'') + '</div>'
                + '<div class="text-muted small d-flex gap-3 flex-wrap">'
                + (p.pesel ? '<span><i class="bi bi-credit-card2-front me-1"></i>'
                    + p.pesel.substring(0,6)+'…</span>' : '')
                + (p.email ? '<span><i class="bi bi-envelope me-1"></i>'+p.email+'</span>' : '')
                + (p.telefon ? '<span><i class="bi bi-phone me-1"></i>'+p.telefon+'</span>' : '')
                + '</div>';
              item.addEventListener('click', function() {
                hiddenId.value  = p.id;
                search.value    = p.imie_nazwisko;
                results.style.display = 'none';
                clearBtn.style.display = '';
                // Badge potwierdzenia
                if (badgeName) badgeName.textContent = p.imie_nazwisko;
                if (badge) badge.style.display = '';
                // Autouzupełnianie
                autofill('wz_imie_nazwisko', p.imie_nazwisko);
                autofill('wz_email',         p.email);
                autofill('wz_telefon',       p.telefon);
                autofill('wz_adres',         p.adres);
                autofill('wz_pesel',         p.pesel);
                if (p.data_urodzenia) autofill('wz_dob', p.data_urodzenia);
                // PESEL → data urodzenia + płeć
                if (p.pesel && p.pesel.length === 11) {
                  var dob = document.getElementById('wz_dob');
                  var dobHint = document.getElementById('wz_dob_hint');
                  var gBadge = document.getElementById('wz_gender_badge');
                  var bd = peselToBirthdate(p.pesel);
                  if (bd && !dob.value) { dob.value = bd; if (dobHint) dobHint.style.display = ''; }
                  var g = peselGender(p.pesel);
                  if (gBadge && g) {
                    gBadge.textContent = g==='K' ? '♀ Kobieta' : '♂ Mężczyzna';
                    gBadge.className = 'badge ms-1 ' + (g==='K'?'bg-pink text-white':'bg-info text-white');
                    gBadge.style.display = '';
                  }
                }
              });
              results.appendChild(item);
            });
          }
          results.style.display = '';
        }).catch(function() {});
    }, 230);
  });

  clearBtn?.addEventListener('click', function() {
    hiddenId.value = ''; search.value = ''; this.style.display = 'none';
    if (badge) badge.style.display = 'none';
    results.style.display = 'none';
  });

  document.addEventListener('click', function(e) {
    if (!results.contains(e.target) && e.target !== search)
      results.style.display = 'none';
  });
})();

// ── Podgląd (krok 3) ─────────────────────────────────────────────────────────
function fv(name) {
  var el = document.querySelector('[name="' + name + '"]');
  if (!el) return '—';
  if (el.tagName === 'SELECT') return el.options[el.selectedIndex]?.text?.trim() || '—';
  return el.value.trim() || '—';
}
function fillReview() {
  document.getElementById('rv-name').textContent    = fv('imie_nazwisko');
  document.getElementById('rv-pesel').textContent   = fv('pesel');
  document.getElementById('rv-dob').textContent     = fv('data_urodzenia');
  document.getElementById('rv-email').textContent   = fv('email');
  document.getElementById('rv-telefon').textContent = fv('telefon') !== '—' ? '+48 ' + fv('telefon') : '—';
  document.getElementById('rv-adres').textContent   = fv('adres');
  document.getElementById('rv-numer').textContent   = fv('numer_umowy');
  document.getElementById('rv-opiekun').textContent = fv('opiekun');
  document.getElementById('rv-zawarcia').textContent= fv('data_zawarcia');

  var bezterm = document.getElementById('wz_bezterminowa')?.checked;
  var od = fv('data_rozpoczecia'), do_ = fv('data_zakonczenia');
  document.getElementById('rv-okres').textContent   = od + ' → ' + (bezterm ? '∞ (bezterminowa)' : do_);

  document.getElementById('rv-projekt').textContent = fv('projekt_program');
  document.getElementById('rv-miejsce').textContent = fv('miejsce_wolontariatu');
  document.getElementById('rv-przedmiot').textContent = fv('przedmiot_porozumienia');

  // Security Group
  var sgName = document.getElementById('wz_sg_name').value.trim();
  var rvSg   = document.getElementById('rv-sg');
  if (sgName) {
    rvSg.innerHTML = '<i class="bi bi-people-fill text-primary me-1"></i><strong>' + sgName + '</strong>';
  } else {
    rvSg.innerHTML = '<span class="text-danger fst-italic">Nie wybrano — wymagane</span>';
  }
}

// ── Działanie → Projekt/program ───────────────────────────────────────────────
document.getElementById('wz_action_id')?.addEventListener('change', function() {
  var pp   = document.getElementById('wz_projekt_program');
  var hint = document.getElementById('wz_pp_hint');
  var opt  = this.options[this.selectedIndex];
  var nazwa = opt ? (opt.dataset.nazwa || opt.textContent.trim()) : '';
  if (!nazwa) return;
  if (!pp.value.trim()) {
    pp.value = nazwa;
    pp.classList.add('autofilled');
    if (hint) hint.style.display = '';
    setTimeout(function() { pp.classList.remove('autofilled'); }, 800);
  }
});

// Czyść hint gdy użytkownik zmieni projekt ręcznie
document.getElementById('wz_projekt_program')?.addEventListener('input', function() {
  var hint = document.getElementById('wz_pp_hint');
  var sel  = document.getElementById('wz_action_id');
  if (hint && sel) {
    var opt = sel.options[sel.selectedIndex];
    var nazwa = opt ? (opt.dataset.nazwa || opt.textContent.trim()) : '';
    if (this.value.trim() !== nazwa) hint.style.display = 'none';
  }
});

// ── Konfiguracja wymagalności pól (z PHP) ────────────────────────────────────
var WZ_FIELD_CFG = <?= json_encode($_wiz_field_cfg) ?>;

// Pola per krok [krok] => [{name, label}]
var WZ_STEP_FIELDS = {
  1: [
    {name:'imie_nazwisko', label:'Imię i nazwisko'},
    {name:'email',         label:'E-mail'},
    {name:'pesel',         label:'PESEL'},
    {name:'data_urodzenia',label:'Data urodzenia'},
    {name:'telefon',       label:'Telefon'},
    {name:'adres',         label:'Adres'},
  ],
  2: [
    {name:'numer_umowy',            label:'Numer umowy'},
    {name:'data_zawarcia',          label:'Data zawarcia'},
    {name:'data_rozpoczecia',       label:'Data rozpoczęcia'},
    {name:'data_zakonczenia',       label:'Data zakończenia'},
    {name:'opiekun',                label:'Opiekun'},
    {name:'miejsce_wolontariatu',   label:'Miejsce wolontariatu'},
    {name:'przedmiot_porozumienia', label:'Przedmiot porozumienia'},
    {name:'projekt_program',        label:'Projekt / program'},
  ],
  3: [
    {name:'m365_security_group_id', label:'Security Group M365', customCheck: function() {
      return !!document.getElementById('wz_sg_id')?.value;
    }},
  ],
};

// ── Toast ostrzeżenia / błędu ─────────────────────────────────────────────────
function wzShowToast(required, recommended) {
  var existing = document.getElementById('wz-warn-toast');
  if (existing) existing.remove();
  if (!required.length && !recommended.length) return;

  var hasRequired = required.length > 0;
  var html = '';
  required.forEach(function(m) {
    html += '<div class="d-flex align-items-start gap-2 py-1">'
      + '<i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0 mt-1" style="font-size:.85rem"></i>'
      + '<span><strong>' + m.label + '</strong> — pole wymagane</span></div>';
  });
  recommended.forEach(function(m) {
    html += '<div class="d-flex align-items-start gap-2 py-1">'
      + '<i class="bi bi-exclamation-triangle-fill text-warning flex-shrink-0 mt-1" style="font-size:.85rem"></i>'
      + '<span>' + m.label + ' — zalecane do uzupełnienia</span></div>';
  });

  var toast = document.createElement('div');
  toast.id = 'wz-warn-toast';
  toast.style.cssText = 'position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);'
    + 'z-index:9999;background:#fff;border:1px solid ' + (hasRequired ? '#fca5a5' : '#fde68a') + ';border-radius:.75rem;'
    + 'box-shadow:0 8px 32px rgba(0,0,0,.18);padding:1rem 1.25rem;min-width:320px;max-width:480px;font-size:.85rem;';

  var footer = hasRequired
    ? '<span class="text-danger fw-semibold" style="font-size:.78rem"><i class="bi bi-lock-fill me-1"></i>Uzupełnij wymagane pola aby przejść dalej</span>'
    : '<span class="text-muted" style="font-size:.78rem">Możesz kontynuować — dane uzupełnij później</span>';

  toast.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-2">'
    + '<strong style="font-size:.88rem"><i class="bi bi-clipboard-check me-1"></i>'
    + (hasRequired ? 'Uzupełnij wymagane pola' : 'Warto uzupełnić') + '</strong>'
    + '<button type="button" onclick="document.getElementById(\'wz-warn-toast\').remove()" class="btn-close btn-close-sm" style="font-size:.65rem"></button>'
    + '</div>' + html
    + '<div class="mt-2 pt-2 border-top">' + footer + '</div>';

  document.body.appendChild(toast);
  if (!hasRequired) setTimeout(function() { if (toast.parentNode) toast.remove(); }, 6000);
}

// ── Sprawdź pola dla kroku — zwraca {required, recommended, blocked} ──────────
function wzCheckStep(step) {
  var fields = WZ_STEP_FIELDS[step] || [];
  var required = [], recommended = [];
  fields.forEach(function(f) {
    var level = WZ_FIELD_CFG[f.name] || 'optional';
    if (level === 'optional') return;
    var empty = f.customCheck ? !f.customCheck() : (function() {
      var el = document.querySelector('[name="' + f.name + '"]');
      if (!el) return false;
      if (el.tagName === 'SELECT') return !el.value;
      return !el.value.trim();
    })();
    if (!empty) return;
    if (level === 'required') required.push(f);
    else recommended.push(f);
  });
  return {required: required, recommended: recommended, blocked: required.length > 0};
}

// ── Security Groups M365 ──────────────────────────────────────────────────────
(function() {
  var sel     = document.getElementById('wz_sg_id');
  var hidden  = document.getElementById('wz_sg_name');
  var status  = document.getElementById('wz_sg_status');
  var refresh = document.getElementById('wz_sg_refresh');
  if (!sel) return;

  function loadGroups() {
    sel.innerHTML = '<option value="">— ładowanie… —</option>';
    sel.disabled = true;
    if (status) { status.textContent = ''; status.className = 'form-text mt-1'; }
    fetch('<?= APP_URL ?>/contracts/wolontariat/api_groups.php')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        sel.disabled = false;
        if (data.error) {
          sel.innerHTML = '<option value="">— błąd ładowania grup —</option>';
          if (status) { status.textContent = '⚠ ' + data.error; status.className = 'form-text text-danger mt-1'; }
          return;
        }
        if (!data.length) {
          sel.innerHTML = '<option value="">— brak grup w M365 —</option>';
          if (status) { status.textContent = 'Nie znaleziono Security Groups w tenecie M365.'; status.className = 'form-text text-warning mt-1'; }
          return;
        }
        sel.innerHTML = '<option value="">— wybierz grupę —</option>';
        data.forEach(function(g) {
          var opt = document.createElement('option');
          opt.value = g.id; opt.textContent = g.displayName; opt.dataset.name = g.displayName;
          sel.appendChild(opt);
        });
        if (status) { status.textContent = 'Załadowano ' + data.length + ' grup.'; status.className = 'form-text text-success mt-1'; }
      })
      .catch(function() {
        sel.disabled = false;
        sel.innerHTML = '<option value="">— błąd połączenia —</option>';
        if (status) { status.textContent = '⚠ Nie można pobrać grup z M365.'; status.className = 'form-text text-danger mt-1'; }
      });
  }

  sel.addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    if (hidden) hidden.value = opt ? (opt.dataset.name || opt.textContent.trim()) : '';
    if (typeof fillReview === 'function') fillReview();
  });

  if (refresh) refresh.addEventListener('click', loadGroups);

  var origGoStep = goStep;
  goStep = function(n) {
    if (n > currentStep) {
      var chk = wzCheckStep(currentStep);
      if (chk.required.length || chk.recommended.length) {
        wzShowToast(chk.required, chk.recommended);
        if (chk.blocked) return; // blokuj przejście gdy są wymagane pola
      }
    }
    origGoStep(n);
    if (n === 3 && sel.options.length <= 1) loadGroups();
  };
})();

// ── Submit — sprawdź wszystkie kroki ─────────────────────────────────────────
document.getElementById('wz-form')?.addEventListener('submit', function(e) {
  if (currentStep !== 3) { e.preventDefault(); return; }
  // Zbierz wymagane z wszystkich kroków
  var allRequired = [];
  [1, 2, 3].forEach(function(s) {
    var chk = wzCheckStep(s);
    allRequired = allRequired.concat(chk.required);
  });
  if (allRequired.length) {
    e.preventDefault();
    wzShowToast(allRequired, []);
  }
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
