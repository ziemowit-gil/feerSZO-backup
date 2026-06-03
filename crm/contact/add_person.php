<?php
/**
 * crm/contact/add_person.php — Formularz dla osoby fizycznej.
 *
 * Pola specyficzne dla człowieka: imię, nazwisko (oddzielnie),
 * PESEL, data urodzenia, rola społeczna, dane kontaktowe.
 * Bez menu systemowego — używa header_crm.php.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do dodawania kontaktów.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();

$edit_id  = (int)($_GET['id'] ?? 0);
$is_edit  = $edit_id > 0;
$row      = [];
$errors   = [];

if ($is_edit) {
    $row = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1 AND type='osoba'", [$edit_id]);
    if (!$row) { flash_set('danger', 'Nie znaleziono kontaktu.'); header('Location: ' . APP_URL . '/crm/index.php'); exit; }
}

$PAGE_TITLE = $is_edit ? 'Edytuj osobę: ' . $row['imie_nazwisko'] : 'Nowa osoba fizyczna';

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $imie    = trim($_POST['imie']    ?? '');
    $nazwisko= trim($_POST['nazwisko'] ?? '');
    $full    = trim($imie . ' ' . $nazwisko);

    $data = [
        'type'           => 'osoba',
        'imie'           => $imie ?: null,
        'nazwisko'       => $nazwisko ?: null,
        'imie_nazwisko'  => $full ?: trim($_POST['imie_nazwisko'] ?? ''),
        'status'         => array_key_exists($_POST['status'] ?? '', crm_statuses()) ? $_POST['status'] : 'prospect',
        'email'          => trim($_POST['email'] ?? '')          ?: null,
        'telefon'        => trim($_POST['telefon'] ?? '')         ?: null,
        'adres'          => trim($_POST['adres'] ?? '')           ?: null,
        'pesel'          => preg_replace('/\D/', '', $_POST['pesel'] ?? '') ?: null,
        'data_urodzenia' => trim($_POST['data_urodzenia'] ?? '')  ?: null,
        'stanowisko'     => trim($_POST['stanowisko'] ?? '')      ?: null,
        'organizacja'    => trim($_POST['organizacja'] ?? '')     ?: null,
        'notatka'        => trim($_POST['notatka'] ?? '')         ?: null,
        'wojewodztwo'    => trim($_POST['wojewodztwo'] ?? '')    ?: null,
        'powiat'         => trim($_POST['powiat'] ?? '')         ?: null,
        'gmina'          => trim($_POST['gmina'] ?? '')          ?: null,
    ];

    // Walidacja
    if (!$data['imie_nazwisko']) $errors[] = 'Imię lub nazwisko jest wymagane.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Nieprawidłowy adres e-mail.';
    if ($data['pesel'] && strlen($data['pesel']) !== 11)
        $errors[] = 'PESEL musi mieć dokładnie 11 cyfr.';

    if (!$errors) {
        $user_id = (int)(current_user()['id'] ?? 0);
        if ($is_edit) {
            CrmManager::updateContact($edit_id, $data);
            flash_set('success', 'Dane kontaktu zaktualizowane.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $edit_id);
        } else {
            $data['created_by'] = $user_id;
            $new_id = CrmManager::createContact($data);
            flash_set('success', 'Kontakt osoby fizycznej dodany.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $new_id);
        }
        exit;
    }
    $row = array_merge($row, $_POST);
}

include __DIR__ . '/../includes/header_crm.php';
?>

<style>
/* Formularz osoby — akcent niebieski (wyróżnienie od firmowego) */
.person-accent { --form-accent: #2563eb; --form-accent-bg: #EEF4FF; --form-accent-border: #93C5FD; }
.person-header-bar {
  background: linear-gradient(90deg, #1D4ED8, #2563eb);
  border-radius: 10px; padding: 1.25rem 1.5rem; color: #fff; margin-bottom: 1.5rem;
  display: flex; align-items: center; gap: 1rem;
}
.person-header-icon {
  width: 48px; height: 48px; border-radius: 50%;
  background: rgba(255,255,255,.2); border: 2px solid rgba(255,255,255,.4);
  display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;
}
.step-label {
  font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
  color: #64748b; margin-bottom: .75rem; margin-top: .25rem;
  display: flex; align-items: center; gap: .4rem;
}
.step-label::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
.field-hint { font-size: .76rem; color: #94a3b8; margin-top: .2rem; }
.form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.btn-person-submit {
  background: #2563eb; color: #fff; border: none; border-radius: .5rem;
  padding: .75rem 1.5rem; font-size: .95rem; font-weight: 600; width: 100%;
  transition: background .15s; cursor: pointer;
}
.btn-person-submit:hover { background: #1D4ED8; }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">Kontakty</a></li>
    <li class="breadcrumb-item active" aria-current="page">
      <?= $is_edit ? 'Edytuj osobę' : 'Nowa osoba fizyczna' ?>
    </li>
  </ol>
</nav>

<!-- Header formularza -->
<div class="person-header-bar" role="banner">
  <div class="person-header-icon" aria-hidden="true"><i class="bi bi-person-fill"></i></div>
  <div>
    <div style="font-size:1.15rem;font-weight:700"><?= $is_edit ? 'Edytuj osobę fizyczną' : 'Nowy kontakt — Osoba fizyczna' ?></div>
    <div style="font-size:.82rem;opacity:.8">Wolontariusz, pracownik, darczyńca, osoba prywatna</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= APP_URL ?>/crm/contact/add_org.php" class="btn btn-sm btn-light opacity-75"
       aria-label="Przełącz na formularz firmy/organizacji">
      <i class="bi bi-building me-1"></i>To firma? Użyj formularza firmy
    </a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert" aria-live="assertive">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <ul class="mb-0 ps-2">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" novalidate aria-label="Formularz osoby fizycznej CRM">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-3">

<!-- ── Lewa — dane osobowe ──────────────────────────────────── -->
<div class="col-lg-8">

  <!-- 1. Tożsamość -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label"><i class="bi bi-person-vcard text-primary" aria-hidden="true"></i>Dane osobowe</div>

      <div class="row g-3">
        <div class="col-sm-5">
          <label class="form-label fw-semibold" for="imie">
            Imię <span class="text-danger" aria-hidden="true">*</span>
          </label>
          <input type="text" name="imie" id="imie"
                 class="form-control"
                 value="<?= h($row['imie'] ?? '') ?>"
                 placeholder="np. Jan"
                 autocomplete="given-name"
                 required
                 aria-required="true"
                 oninput="updateFullName()">
        </div>
        <div class="col-sm-7">
          <label class="form-label fw-semibold" for="nazwisko">
            Nazwisko <span class="text-danger" aria-hidden="true">*</span>
          </label>
          <input type="text" name="nazwisko" id="nazwisko"
                 class="form-control"
                 value="<?= h($row['nazwisko'] ?? '') ?>"
                 placeholder="np. Kowalski"
                 autocomplete="family-name"
                 required
                 aria-required="true"
                 oninput="updateFullName()">
        </div>
      </div>

      <!-- Podgląd pełnego imienia (hidden — synchronizowany JS) -->
      <input type="hidden" name="imie_nazwisko" id="imie_nazwisko_hidden"
             value="<?= h($row['imie_nazwisko'] ?? '') ?>">

      <div class="row g-3 mt-0">
        <div class="col-sm-6">
          <label class="form-label" for="pesel">PESEL</label>
          <input type="text" name="pesel" id="pesel"
                 class="form-control font-monospace"
                 value="<?= h($row['pesel'] ?? '') ?>"
                 placeholder="00000000000"
                 maxlength="11"
                 pattern="\d{11}"
                 inputmode="numeric"
                 autocomplete="off"
                 aria-describedby="peselHelp"
                 oninput="this.value=this.value.replace(/\D/g,'').slice(0,11); extractBirthdate(this.value)">
          <div id="peselHelp" class="field-hint">11 cyfr · data urodzenia zostanie uzupełniona automatycznie</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="data_urodzenia">Data urodzenia</label>
          <input type="date" name="data_urodzenia" id="data_urodzenia"
                 class="form-control"
                 value="<?= h($row['data_urodzenia'] ?? '') ?>"
                 max="<?= date('Y-m-d') ?>"
                 aria-label="Data urodzenia">
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Kontakt -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label"><i class="bi bi-telephone text-primary" aria-hidden="true"></i>Dane kontaktowe</div>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label" for="email">Adres e-mail</label>
          <input type="email" name="email" id="email"
                 class="form-control"
                 value="<?= h($row['email'] ?? '') ?>"
                 placeholder="jan.kowalski@email.pl"
                 autocomplete="email">
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="telefon">Numer telefonu</label>
          <input type="tel" name="telefon" id="telefon"
                 class="form-control phone-48"
                 value="<?= h($row['telefon'] ?? '') ?>"
                 placeholder="123 456 789"
                 autocomplete="tel">
        </div>
      </div>
      <div class="mt-3">
        <label class="form-label" for="adres">Adres zamieszkania / korespondencji</label>
        <input type="text" name="adres" id="adres"
               class="form-control"
               value="<?= h($row['adres'] ?? '') ?>"
               placeholder="ul. Przykładowa 1, 00-001 Warszawa"
               autocomplete="street-address">
      </div>
    </div>
  </div>

  <!-- 3. Rola / powiązanie z organizacją -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label"><i class="bi bi-briefcase text-primary" aria-hidden="true"></i>Rola i powiązania</div>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label" for="stanowisko">Rola / Stanowisko</label>
          <input type="text" name="stanowisko" id="stanowisko"
                 class="form-control"
                 value="<?= h($row['stanowisko'] ?? '') ?>"
                 list="stanowisko_suggestions"
                 placeholder="np. Wolontariusz, Darczyńca…">
          <datalist id="stanowisko_suggestions">
            <option value="Wolontariusz">
            <option value="Koordynator wolontariuszy">
            <option value="Pracownik">
            <option value="Darczyńca indywidualny">
            <option value="Beneficjent">
            <option value="Rodzic / Opiekun">
            <option value="Kontakt zewnętrzny">
          </datalist>
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="organizacja">Powiązana firma / organizacja</label>
          <input type="text" name="organizacja" id="organizacja"
                 class="form-control"
                 value="<?= h($row['organizacja'] ?? '') ?>"
                 placeholder="Nazwa firmy lub org. (opcjonalnie)">
        </div>
      </div>
    </div>
  </div>

  <!-- 3b. Terytorium -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label"><i class="bi bi-map" style="color:#059669" aria-hidden="true"></i>Terytorium</div>
      <?php
      $woj_list_p = ['dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie','małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie','pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie'];
      ?>
      <div class="row g-2">
        <div class="col-md-5">
          <label class="form-label small mb-1">Województwo</label>
          <select name="wojewodztwo" class="form-select form-select-sm">
            <option value="">— wybierz —</option>
            <?php foreach ($woj_list_p as $w): ?>
            <option value="<?= h($w) ?>" <?= ($row['wojewodztwo']??'') === $w ? 'selected' : '' ?>><?= h(ucfirst($w)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1">Powiat</label>
          <input type="text" name="powiat" class="form-control form-control-sm"
                 value="<?= h($row['powiat'] ?? '') ?>" placeholder="np. warszawa">
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1">Gmina</label>
          <input type="text" name="gmina" class="form-control form-control-sm"
                 value="<?= h($row['gmina'] ?? '') ?>" placeholder="gmina">
        </div>
      </div>
    </div>
  </div>

  <!-- 4. Notatka -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="step-label"><i class="bi bi-sticky text-primary" aria-hidden="true"></i>Notatka wstępna</div>
      <label class="visually-hidden" for="notatka">Notatka o kontakcie</label>
      <textarea name="notatka" id="notatka" class="form-control" rows="4"
                placeholder="Opcjonalna notatka, okoliczności poznania, uwagi…"
                aria-label="Notatka o kontakcie"><?= h($row['notatka'] ?? '') ?></textarea>
    </div>
  </div>

</div><!-- /lewa -->

<!-- ── Prawa — status + podgląd ──────────────────────────────── -->
<div class="col-lg-4">

  <!-- Status -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label">Status w CRM</div>
      <div class="d-flex flex-column gap-2" role="radiogroup" aria-label="Wybierz status kontaktu">
        <?php foreach (crm_statuses() as $sk => $sv): ?>
        <label class="d-flex align-items-center gap-2 p-2 border rounded"
               style="cursor:pointer;font-size:.84rem;border-radius:.4rem!important;
                      <?= ($row['status'] ?? 'prospect') === $sk ? 'background:var(--crm-primary-bg);border-color:var(--crm-primary)!important;font-weight:600' : '' ?>">
          <input type="radio" name="status" value="<?= h($sk) ?>"
                 <?= ($row['status'] ?? 'prospect') === $sk ? 'checked' : '' ?>
                 class="form-check-input mt-0" style="flex-shrink:0">
          <span class="crm-badge crm-badge-<?= h($sk) ?>" style="pointer-events:none">
            <?= h($sv['label']) ?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Podgląd awatara -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body text-center">
      <div class="step-label" style="justify-content:center">Podgląd awatara</div>
      <div id="avatarPreview"
           style="width:72px;height:72px;border-radius:50%;background:#2563eb;color:#fff;
                  display:flex;align-items:center;justify-content:center;font-size:1.6rem;
                  font-weight:700;margin:0 auto .75rem;font-family:monospace;letter-spacing:1px"
           aria-label="Podgląd inicjałów awatara">
        <?= h(CrmManager::makeInitials($row['imie_nazwisko'] ?? '')) ?: '?' ?>
      </div>
      <div id="avatarName" style="font-size:.88rem;font-weight:600;color:#1e293b">
        <?= h($row['imie_nazwisko'] ?? 'Jan Kowalski') ?>
      </div>
      <div class="text-muted mt-1" style="font-size:.75rem">Osoba fizyczna</div>
    </div>
  </div>

  <!-- Przycisk wyślij -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <button type="submit" class="btn-person-submit" aria-label="<?= $is_edit ? 'Zapisz zmiany' : 'Utwórz kontakt osoby fizycznej' ?>">
        <i class="bi bi-person-check-fill me-2" aria-hidden="true"></i>
        <?= $is_edit ? 'Zapisz zmiany' : 'Utwórz kontakt' ?>
      </button>
      <a href="<?= $is_edit ? APP_URL . '/crm/contact/view.php?id=' . $edit_id : APP_URL . '/crm/index.php' ?>"
         class="btn btn-outline-secondary w-100 mt-2" style="font-size:.88rem">
        Anuluj
      </a>
      <?php if (!$is_edit): ?>
      <a href="<?= APP_URL ?>/crm/contact/add_org.php"
         class="btn btn-outline-secondary w-100 mt-2" style="font-size:.82rem;opacity:.7">
        <i class="bi bi-building me-1"></i>To firma? Zmień formularz
      </a>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /prawa -->
</div><!-- /row -->
</form>

<script>
// Łączy imię + nazwisko w pole hidden
function updateFullName() {
  var i = (document.getElementById('imie')?.value || '').trim();
  var n = (document.getElementById('nazwisko')?.value || '').trim();
  var full = [i, n].filter(Boolean).join(' ');
  var h = document.getElementById('imie_nazwisko_hidden');
  if (h) h.value = full;
  // Awatar preview
  var initials = '';
  if (i) initials += i.charAt(0).toUpperCase();
  if (n) initials += n.charAt(0).toUpperCase();
  var av = document.getElementById('avatarPreview');
  if (av) av.textContent = initials || '?';
  var an = document.getElementById('avatarName');
  if (an) an.textContent = full || 'Imię Nazwisko';
}

// Wyciąga datę urodzenia z PESEL
function extractBirthdate(pesel) {
  if (pesel.length !== 11) return;
  var y = parseInt(pesel.slice(0,2), 10);
  var m = parseInt(pesel.slice(2,4), 10);
  var d = parseInt(pesel.slice(4,6), 10);
  if (m >= 81)       { y += 1800; m -= 80; }
  else if (m >= 61)  { y += 2200; m -= 60; }
  else if (m >= 41)  { y += 2100; m -= 40; }
  else if (m >= 21)  { y += 2000; m -= 20; }
  else               { y += 1900; }
  if (m < 1 || m > 12 || d < 1 || d > 31) return;
  var dateStr = y.toString().padStart(4,'0') + '-'
              + m.toString().padStart(2,'0') + '-'
              + d.toString().padStart(2,'0');
  var dateInput = document.getElementById('data_urodzenia');
  if (dateInput) dateInput.value = dateStr;
}

// Podświetl wybrany status radio
document.querySelectorAll('[name="status"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.querySelectorAll('[name="status"]').forEach(function(x) {
      x.closest('label').style.background = '';
      x.closest('label').style.borderColor = '';
      x.closest('label').style.fontWeight  = '';
    });
    this.closest('label').style.background   = 'var(--crm-primary-bg)';
    this.closest('label').style.borderColor  = 'var(--crm-primary)';
    this.closest('label').style.fontWeight   = '600';
  });
});
</script>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
