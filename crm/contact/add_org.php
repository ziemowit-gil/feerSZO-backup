<?php
/**
 * crm/contact/add_org.php — Formularz dla firmy / organizacji.
 *
 * Pola specyficzne dla osoby prawnej: NIP, KRS, REGON, forma prawna,
 * branża, strona www, osoba kontaktowa.
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
    $row = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1 AND type='organizacja'", [$edit_id]);
    if (!$row) {
        flash_set('danger', 'Nie znaleziono kontaktu.');
        header('Location: ' . APP_URL . '/crm/index.php');
        exit;
    }
}

$PAGE_TITLE = $is_edit ? 'Edytuj firmę: ' . $row['imie_nazwisko'] : 'Nowa firma / organizacja';

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $nazwa = trim($_POST['nazwa'] ?? '');

    $data = [
        'type'             => 'organizacja',
        'imie_nazwisko'    => $nazwa,                                            // "imię_nazwisko" przechowuje nazwę firmy
        'status'           => array_key_exists($_POST['status'] ?? '', CRM_STATUSES) ? $_POST['status'] : 'prospect',
        'email'            => trim($_POST['email']             ?? '') ?: null,
        'telefon'          => trim($_POST['telefon']           ?? '') ?: null,
        'adres'            => trim($_POST['adres']             ?? '') ?: null,
        'nip'              => preg_replace('/[\s\-]/', '', $_POST['nip'] ?? '') ?: null,
        'krs'              => preg_replace('/[\s\-]/', '', $_POST['krs'] ?? '') ?: null,
        'regon'            => preg_replace('/[\s\-]/', '', $_POST['regon'] ?? '') ?: null,
        'branza'           => trim($_POST['branza']            ?? '') ?: null,
        'strona_www'       => trim($_POST['strona_www']        ?? '') ?: null,
        'forma_prawna'     => trim($_POST['forma_prawna']      ?? '') ?: null,
        'osoba_kontaktowa' => trim($_POST['osoba_kontaktowa']  ?? '') ?: null,
        'stanowisko'       => trim($_POST['stanowisko']        ?? '') ?: null,
        'notatka'          => trim($_POST['notatka']           ?? '') ?: null,
    ];

    // Walidacja
    if (!$data['imie_nazwisko']) $errors[] = 'Nazwa firmy / organizacji jest wymagana.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Nieprawidłowy adres e-mail.';
    if ($data['nip'] && !preg_match('/^\d{10}$/', $data['nip']))
        $errors[] = 'NIP musi składać się z 10 cyfr.';
    if ($data['krs'] && !preg_match('/^\d{10}$/', $data['krs']))
        $errors[] = 'KRS musi składać się z 10 cyfr.';
    if ($data['regon'] && !preg_match('/^\d{9}(\d{5})?$/', $data['regon']))
        $errors[] = 'REGON musi mieć 9 lub 14 cyfr.';
    if ($data['strona_www'] && !filter_var($data['strona_www'], FILTER_VALIDATE_URL))
        $errors[] = 'Nieprawidłowy adres strony www (wymagany format: https://…).';

    if (!$errors) {
        $user_id = (int)(current_user()['id'] ?? 0);
        if ($is_edit) {
            CrmManager::updateContact($edit_id, $data);
            flash_set('success', 'Dane firmy zaktualizowane.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $edit_id);
        } else {
            $data['created_by'] = $user_id;
            $new_id = CrmManager::createContact($data);
            flash_set('success', 'Kontakt firmy / organizacji dodany.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $new_id);
        }
        exit;
    }
    $row = array_merge($row, $_POST);
}

include __DIR__ . '/../includes/header_crm.php';
?>

<style>
/* Formularz firmy — akcent fioletowy (wyróżnienie od osobowego niebieskiego) */
.org-accent { --form-accent: #7F2B8B; --form-accent-bg: #F5F0FF; --form-accent-border: #C4B5FD; }
.org-header-bar {
  background: linear-gradient(90deg, #581C87, #7F2B8B);
  border-radius: 10px; padding: 1.25rem 1.5rem; color: #fff; margin-bottom: 1.5rem;
  display: flex; align-items: center; gap: 1rem;
}
.org-header-icon {
  width: 48px; height: 48px; border-radius: 12px;
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
.form-control:focus { border-color: #7F2B8B; box-shadow: 0 0 0 3px rgba(127,43,139,.1); }
.btn-org-submit {
  background: #7F2B8B; color: #fff; border: none; border-radius: .5rem;
  padding: .75rem 1.5rem; font-size: .95rem; font-weight: 600; width: 100%;
  transition: background .15s; cursor: pointer;
}
.btn-org-submit:hover { background: #581C87; }
/* ID-field monospace */
.field-id { font-family: ui-monospace, SFMono-Regular, monospace; letter-spacing: .05em; }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">Kontakty</a></li>
    <li class="breadcrumb-item active" aria-current="page">
      <?= $is_edit ? 'Edytuj firmę' : 'Nowa firma / organizacja' ?>
    </li>
  </ol>
</nav>

<!-- Header formularza -->
<div class="org-header-bar" role="banner">
  <div class="org-header-icon" aria-hidden="true"><i class="bi bi-building-fill"></i></div>
  <div>
    <div style="font-size:1.15rem;font-weight:700">
      <?= $is_edit ? 'Edytuj firmę / organizację' : 'Nowy kontakt — Firma / Organizacja' ?>
    </div>
    <div style="font-size:.82rem;opacity:.8">Partner, darczyńca instytucjonalny, kontrahent, stowarzyszenie</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-sm btn-light opacity-75"
       aria-label="Przełącz na formularz osoby fizycznej">
      <i class="bi bi-person me-1"></i>To osoba? Użyj formularza osoby
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

<form method="post" novalidate aria-label="Formularz firmy / organizacji CRM">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-3">

<!-- ── Lewa — dane firmy ─────────────────────────────────────── -->
<div class="col-lg-8">

  <!-- 1. Dane rejestrowe -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label">
        <i class="bi bi-building text-purple" style="color:#7F2B8B" aria-hidden="true"></i>
        Dane rejestrowe
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="nazwa">
          Nazwa firmy / organizacji <span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input type="text" name="nazwa" id="nazwa"
               class="form-control"
               value="<?= h($row['imie_nazwisko'] ?? $row['nazwa'] ?? '') ?>"
               placeholder="np. Fundacja Pomagamy Razem"
               autocomplete="organization"
               required
               aria-required="true"
               oninput="updateOrgPreview(this.value)">
      </div>

      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label" for="forma_prawna">Forma prawna</label>
          <select name="forma_prawna" id="forma_prawna" class="form-select"
                  aria-label="Forma prawna organizacji">
            <option value="">— wybierz —</option>
            <?php
            $formy = [
              'fundacja'         => 'Fundacja',
              'stowarzyszenie'   => 'Stowarzyszenie',
              'sp_z_oo'          => 'Sp. z o.o.',
              'sa'               => 'S.A.',
              'jednoosobowa'     => 'Jednoosobowa działalność gospodarcza',
              'spoldzielnia'     => 'Spółdzielnia',
              'ngo_inne'         => 'Inna organizacja pozarządowa',
              'instytucja'       => 'Instytucja publiczna',
              'samorzad'         => 'Jednostka samorządu terytorialnego',
              'koscielna'        => 'Organizacja kościelna',
              'inne'             => 'Inne',
            ];
            $sel_fp = $row['forma_prawna'] ?? '';
            foreach ($formy as $fk => $fv):
            ?>
            <option value="<?= h($fk) ?>" <?= $sel_fp === $fk ? 'selected' : '' ?>>
              <?= h($fv) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="branza">Branża / Obszar działalności</label>
          <input type="text" name="branza" id="branza"
                 class="form-control"
                 value="<?= h($row['branza'] ?? '') ?>"
                 list="branza_suggestions"
                 placeholder="np. Pomoc społeczna, Edukacja…">
          <datalist id="branza_suggestions">
            <option value="Pomoc społeczna">
            <option value="Edukacja i szkolenia">
            <option value="Ochrona środowiska">
            <option value="Kultura i sztuka">
            <option value="Ochrona zdrowia">
            <option value="Sport i rekreacja">
            <option value="Badania i nauka">
            <option value="IT i technologie">
            <option value="Budownictwo">
            <option value="Finanse i ubezpieczenia">
            <option value="Usługi dla NGO">
          </datalist>
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Numery identyfikacyjne -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label">
        <i class="bi bi-fingerprint" style="color:#7F2B8B" aria-hidden="true"></i>
        Numery identyfikacyjne
      </div>
      <div class="row g-3">
        <div class="col-sm-4">
          <label class="form-label" for="nip">NIP</label>
          <input type="text" name="nip" id="nip"
                 class="form-control field-id"
                 value="<?= h($row['nip'] ?? '') ?>"
                 placeholder="0000000000"
                 maxlength="13"
                 inputmode="numeric"
                 aria-describedby="nipHelp"
                 oninput="this.value=this.value.replace(/[^\d\-\s]/g,'').slice(0,13)">
          <div id="nipHelp" class="field-hint">10 cyfr (bez myślników)</div>
        </div>
        <div class="col-sm-4">
          <label class="form-label" for="krs">KRS</label>
          <input type="text" name="krs" id="krs"
                 class="form-control field-id"
                 value="<?= h($row['krs'] ?? '') ?>"
                 placeholder="0000000000"
                 maxlength="13"
                 inputmode="numeric"
                 aria-describedby="krsHelp"
                 oninput="this.value=this.value.replace(/[^\d\-\s]/g,'').slice(0,13)">
          <div id="krsHelp" class="field-hint">10 cyfr (bez myślników)</div>
        </div>
        <div class="col-sm-4">
          <label class="form-label" for="regon">REGON</label>
          <input type="text" name="regon" id="regon"
                 class="form-control field-id"
                 value="<?= h($row['regon'] ?? '') ?>"
                 placeholder="000000000"
                 maxlength="14"
                 inputmode="numeric"
                 aria-describedby="regonHelp"
                 oninput="this.value=this.value.replace(/[^\d]/g,'').slice(0,14)">
          <div id="regonHelp" class="field-hint">9 lub 14 cyfr</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Dane kontaktowe -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label">
        <i class="bi bi-telephone" style="color:#7F2B8B" aria-hidden="true"></i>
        Dane kontaktowe
      </div>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label" for="email">Adres e-mail</label>
          <input type="email" name="email" id="email"
                 class="form-control"
                 value="<?= h($row['email'] ?? '') ?>"
                 placeholder="biuro@fundacja.pl"
                 autocomplete="email">
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="telefon">Numer telefonu</label>
          <input type="tel" name="telefon" id="telefon"
                 class="form-control"
                 value="<?= h($row['telefon'] ?? '') ?>"
                 placeholder="+48 123 456 789"
                 autocomplete="tel">
        </div>
      </div>
      <div class="row g-3 mt-0">
        <div class="col-sm-8">
          <label class="form-label" for="adres">Adres siedziby</label>
          <input type="text" name="adres" id="adres"
                 class="form-control"
                 value="<?= h($row['adres'] ?? '') ?>"
                 placeholder="ul. Główna 1, 00-001 Warszawa"
                 autocomplete="street-address">
        </div>
        <div class="col-sm-4">
          <label class="form-label" for="strona_www">Strona www</label>
          <input type="url" name="strona_www" id="strona_www"
                 class="form-control"
                 value="<?= h($row['strona_www'] ?? '') ?>"
                 placeholder="https://…"
                 autocomplete="url"
                 aria-describedby="wwwHelp">
          <div id="wwwHelp" class="field-hint">Z http:// lub https://</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 4. Osoba kontaktowa -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label">
        <i class="bi bi-person-badge" style="color:#7F2B8B" aria-hidden="true"></i>
        Osoba kontaktowa
      </div>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label" for="osoba_kontaktowa">Imię i nazwisko</label>
          <input type="text" name="osoba_kontaktowa" id="osoba_kontaktowa"
                 class="form-control"
                 value="<?= h($row['osoba_kontaktowa'] ?? '') ?>"
                 placeholder="np. Anna Nowak"
                 autocomplete="name">
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="stanowisko">Stanowisko / Rola</label>
          <input type="text" name="stanowisko" id="stanowisko"
                 class="form-control"
                 value="<?= h($row['stanowisko'] ?? '') ?>"
                 list="stanowisko_org_suggestions"
                 placeholder="np. Prezes zarządu">
          <datalist id="stanowisko_org_suggestions">
            <option value="Prezes zarządu">
            <option value="Dyrektor generalny">
            <option value="Dyrektor finansowy">
            <option value="Dyrektor ds. HR">
            <option value="Koordynator projektów">
            <option value="Pełnomocnik zarządu">
            <option value="Dyrektor ds. współpracy">
            <option value="Specjalista ds. fundraisingu">
            <option value="Asystent zarządu">
          </datalist>
        </div>
      </div>
    </div>
  </div>

  <!-- 5. Notatka -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="step-label">
        <i class="bi bi-sticky" style="color:#7F2B8B" aria-hidden="true"></i>
        Notatka wstępna
      </div>
      <label class="visually-hidden" for="notatka">Notatka o firmie / organizacji</label>
      <textarea name="notatka" id="notatka" class="form-control" rows="4"
                placeholder="Opcjonalna notatka, historia współpracy, uwagi o organizacji…"
                aria-label="Notatka o firmie lub organizacji"><?= h($row['notatka'] ?? '') ?></textarea>
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
        <?php foreach (CRM_STATUSES as $sk => $sv): ?>
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
           style="width:72px;height:72px;border-radius:14px;background:var(--crm-navy,#032D60);color:#fff;
                  display:flex;align-items:center;justify-content:center;font-size:1.6rem;
                  font-weight:700;margin:0 auto .75rem;font-family:monospace;letter-spacing:1px"
           aria-label="Podgląd inicjałów awatara">
        <?php
          $init_val = $row['imie_nazwisko'] ?? $row['nazwa'] ?? '';
          echo $init_val ? h(CrmManager::makeInitials($init_val)) : '<i class="bi bi-building-fill" style="font-size:1.4rem"></i>';
        ?>
      </div>
      <div id="avatarName" style="font-size:.88rem;font-weight:600;color:#1e293b">
        <?= h($row['imie_nazwisko'] ?? $row['nazwa'] ?? 'Nazwa organizacji') ?>
      </div>
      <div class="text-muted mt-1" style="font-size:.75rem">Firma / Organizacja</div>
    </div>
  </div>

  <!-- Import z KRS -->
  <div class="card border-0 shadow-sm mb-3" id="krs-import-card">
    <div class="card-body">
      <div class="step-label" style="color:#7F2B8B">
        <i class="bi bi-database-fill-down" style="color:#7F2B8B"></i>
        Import z KRS
      </div>
      <p class="text-muted mb-2" style="font-size:.78rem">
        Wpisz numer KRS, by automatycznie uzupełnić nazwę, NIP, REGON, adres i formę prawną z Krajowego Rejestru Sądowego.
      </p>
      <div class="input-group input-group-sm mb-2">
        <input type="text" id="krsInput" class="form-control font-monospace"
               placeholder="0000000000" maxlength="10" inputmode="numeric"
               oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
               aria-label="Numer KRS do wyszukania">
        <button type="button" class="btn btn-outline-secondary" id="krsLookupBtn"
                onclick="krsLookup()" aria-label="Pobierz dane z KRS">
          <i class="bi bi-search"></i> Szukaj
        </button>
      </div>
      <div id="krsStatus" style="font-size:.78rem;min-height:1.2rem"></div>

      <!-- Podgląd wyników KRS -->
      <div id="krsResult" class="mt-2" style="display:none">
        <div class="alert alert-success py-2 mb-2 d-flex align-items-start gap-2" style="font-size:.8rem">
          <i class="bi bi-check-circle-fill flex-shrink-0 mt-1 text-success"></i>
          <div id="krsResultBody"></div>
        </div>
        <button type="button" class="btn btn-sm w-100"
                style="background:#7F2B8B;color:#fff;border:none"
                onclick="krsApply()">
          <i class="bi bi-arrow-down-circle me-1"></i>Zastosuj dane do formularza
        </button>
      </div>
    </div>
  </div>

  <!-- NIP szybki lookup GUS -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="step-label">Weryfikacja GUS</div>
      <p class="text-muted mb-2" style="font-size:.78rem">
        Po wpisaniu NIP można zweryfikować dane w rejestrze GUS (REGON Online).
      </p>
      <a id="gusLink" href="#" target="_blank" rel="noopener"
         class="btn btn-outline-secondary w-100 btn-sm" style="font-size:.8rem"
         onclick="return openGUS()" aria-label="Sprawdź NIP w rejestrze GUS">
        <i class="bi bi-search me-1"></i>Sprawdź w GUS
      </a>
    </div>
  </div>

  <!-- Przycisk wyślij -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <button type="submit" class="btn-org-submit"
              aria-label="<?= $is_edit ? 'Zapisz zmiany w firmie' : 'Utwórz kontakt firmy lub organizacji' ?>">
        <i class="bi bi-building-check me-2" aria-hidden="true"></i>
        <?= $is_edit ? 'Zapisz zmiany' : 'Utwórz kontakt' ?>
      </button>
      <a href="<?= $is_edit ? APP_URL . '/crm/contact/view.php?id=' . $edit_id : APP_URL . '/crm/index.php' ?>"
         class="btn btn-outline-secondary w-100 mt-2" style="font-size:.88rem">
        Anuluj
      </a>
      <?php if (!$is_edit): ?>
      <a href="<?= APP_URL ?>/crm/contact/add_person.php"
         class="btn btn-outline-secondary w-100 mt-2" style="font-size:.82rem;opacity:.7">
        <i class="bi bi-person me-1"></i>To osoba fizyczna? Zmień formularz
      </a>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /prawa -->
</div><!-- /row -->
</form>

<script>
// Aktualizuje podgląd awatara po wpisaniu nazwy
function updateOrgPreview(val) {
  val = (val || '').trim();
  // Inicjały: pierwsze litery kolejnych słów (max 2)
  var words = val.split(/\s+/).filter(Boolean);
  var initials = '';
  for (var i = 0; i < Math.min(2, words.length); i++) {
    initials += words[i].charAt(0).toUpperCase();
  }
  var av = document.getElementById('avatarPreview');
  if (av) {
    if (initials) {
      av.textContent = initials;
    } else {
      av.innerHTML = '<i class="bi bi-building-fill" style="font-size:1.4rem"></i>';
    }
  }
  var an = document.getElementById('avatarName');
  if (an) an.textContent = val || 'Nazwa organizacji';
}

// Otwiera rejestr GUS z aktualnym NIP
function openGUS() {
  var nip = (document.getElementById('nip')?.value || '').replace(/[\s\-]/g, '');
  if (!nip) { alert('Wpisz najpierw numer NIP.'); return false; }
  var url = 'https://wyszukiwarkaregon.stat.gov.pl/appBIR/index.aspx';
  window.open(url, '_blank', 'noopener,noreferrer');
  return false;
}

// Podświetl wybrany status radio
document.querySelectorAll('[name="status"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.querySelectorAll('[name="status"]').forEach(function(x) {
      x.closest('label').style.background   = '';
      x.closest('label').style.borderColor  = '';
      x.closest('label').style.fontWeight   = '';
    });
    this.closest('label').style.background  = 'var(--crm-primary-bg)';
    this.closest('label').style.borderColor = 'var(--crm-primary)';
    this.closest('label').style.fontWeight  = '600';
  });
});

// Inicjuj podgląd przy załadowaniu (tryb edycji)
(function() {
  var n = document.getElementById('nazwa');
  if (n && n.value) updateOrgPreview(n.value);
})();

// ── KRS Lookup ────────────────────────────────────────────────────────────────
var _krsData = null;

function krsLookup() {
  var krs = (document.getElementById('krsInput')?.value || '').replace(/\D/g,'');
  if (!krs || krs.length < 6) {
    krsSetStatus('warning', 'Wpisz numer KRS (min. 6 cyfr).');
    return;
  }

  var btn = document.getElementById('krsLookupBtn');
  var res = document.getElementById('krsResult');
  if (btn) btn.disabled = true;
  if (res) res.style.display = 'none';
  krsSetStatus('info', '<span class="spinner-border spinner-border-sm me-1"></span>Pobieranie danych z KRS…');

  fetch('<?= APP_URL ?>/api/krs.php?krs=' + encodeURIComponent(krs), {
    headers: { 'Accept': 'application/json' }
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (btn) btn.disabled = false;
    if (data.error) {
      krsSetStatus('danger', '<i class="bi bi-x-circle me-1"></i>' + data.error);
      return;
    }
    _krsData = data;
    krsSetStatus('success', '<i class="bi bi-check-circle me-1"></i>Znaleziono: <strong>' + escHtml(data.nazwa) + '</strong> (' + escHtml(data.rejestr_label || data.rejestr) + ')');

    // Wypełnij podgląd
    var body = document.getElementById('krsResultBody');
    if (body) {
      body.innerHTML = [
        '<strong>' + escHtml(data.nazwa) + '</strong>',
        data.forma_prawna ? '<br><span class="text-muted">Forma: </span>' + escHtml(data.forma_prawna) : '',
        data.nip   ? '<br><span class="text-muted">NIP: </span><code>' + escHtml(data.nip) + '</code>' : '',
        data.regon ? '<br><span class="text-muted">REGON: </span><code>' + escHtml(data.regon) + '</code>' : '',
        data.adres ? '<br><span class="text-muted">Adres: </span>' + escHtml(data.adres) : '',
      ].join('');
    }
    if (res) res.style.display = '';
  })
  .catch(function(err) {
    if (btn) btn.disabled = false;
    krsSetStatus('danger', '<i class="bi bi-x-circle me-1"></i>Błąd połączenia: ' + err.message);
  });
}

function krsApply() {
  if (!_krsData) return;
  var d = _krsData;

  function setField(id, val) {
    var el = document.getElementById(id);
    if (el && val) { el.value = val; el.classList.add('autofilled-krs'); setTimeout(function(){ el.classList.remove('autofilled-krs'); }, 1200); }
  }
  function setSelect(id, val) {
    var el = document.getElementById(id);
    if (!el || !val) return;
    // Spróbuj dopasować wartość (case-insensitive)
    for (var i = 0; i < el.options.length; i++) {
      if (el.options[i].value.toLowerCase() === val.toLowerCase() ||
          el.options[i].text.toLowerCase().includes(val.toLowerCase().slice(0, 8))) {
        el.selectedIndex = i; break;
      }
    }
  }

  setField('nazwa',  d.nazwa);
  setField('nip',    d.nip);
  setField('krs',    d.krs);
  setField('regon',  d.regon);
  setField('adres',  d.adres);

  // Forma prawna — mapowanie
  var formaMap = {
    'fundacja': 'fundacja',
    'stowarzyszenie': 'stowarzyszenie',
    'spółka z ograniczoną odpowiedzialnością': 'sp_z_oo',
    'spółka akcyjna': 'sa',
    'spółdzielnia': 'spoldzielnia',
  };
  var fp = (d.forma_prawna || '').toLowerCase();
  var mapped = null;
  for (var key in formaMap) {
    if (fp.includes(key)) { mapped = formaMap[key]; break; }
  }
  if (mapped) setSelect('forma_prawna', mapped);

  if (d.nazwa) updateOrgPreview(d.nazwa);

  krsSetStatus('success', '<i class="bi bi-check-circle me-1"></i>Dane zastosowane! Sprawdź i uzupełnij brakujące pola.');
  document.getElementById('krsResult').style.display = 'none';

  // Przewiń do pola Nazwa
  document.getElementById('nazwa')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function krsSetStatus(type, html) {
  var el = document.getElementById('krsStatus');
  if (!el) return;
  var colors = { info:'#0176D3', success:'#2E844A', warning:'#D97706', danger:'#DC2626' };
  el.innerHTML = '<span style="color:' + (colors[type]||'#374151') + '">' + html + '</span>';
}

function escHtml(str) {
  return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Szukaj po Enter w polu KRS
document.getElementById('krsInput')?.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); krsLookup(); }
});
</script>

<style>
@keyframes krsHighlight { 0%{background:#fffbe6} 100%{background:transparent} }
.autofilled-krs { animation: krsHighlight 1.2s ease }
</style>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
