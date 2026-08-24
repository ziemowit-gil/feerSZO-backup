<?php
/**
 * crm/contact/add_person.php — Formularz osoby fizycznej.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do dodawania kontaktów.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();
crm_require('contacts', 'write');

$edit_id = (int)($_GET['id'] ?? 0);
$is_edit = $edit_id > 0;
$row     = [];
$errors  = [];

if ($is_edit) {
    $row = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1 AND type='osoba'", [$edit_id]);
    if (!$row) {
        flash_set('danger', 'Nie znaleziono kontaktu.');
        header('Location: ' . APP_URL . '/crm/index.php');
        exit;
    }
}

$PAGE_TITLE = $is_edit ? 'Edytuj osobę: ' . ($row['imie_nazwisko'] ?? '') : 'Nowa osoba';

// ── Opcje źródła kontaktu ─────────────────────────────────────────────────────
$source_options = [
    ''             => '— nieznane —',
    'polecenie'    => 'Polecenie',
    'wydarzenie'   => 'Wydarzenie / spotkanie',
    'formularz_www'=> 'Formularz na stronie',
    'social_media' => 'Media społecznościowe',
    'email'        => 'E-mail',
    'telefon'      => 'Telefon',
    'wolontariat'  => 'Umowa wolontariacka',
    'inne'         => 'Inne',
];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $imie     = trim($_POST['imie']     ?? '');
    $nazwisko = trim($_POST['nazwisko'] ?? '');
    $full     = trim($imie . ' ' . $nazwisko);

    $data = [
        'type'           => 'osoba',
        'imie'           => $imie     ?: null,
        'nazwisko'       => $nazwisko ?: null,
        'imie_nazwisko'  => $full     ?: trim($_POST['imie_nazwisko'] ?? ''),
        'status'         => array_key_exists($_POST['status'] ?? '', crm_statuses()) ? $_POST['status'] : 'prospect',
        'email'          => trim($_POST['email']      ?? '') ?: null,
        'telefon'        => trim($_POST['telefon']    ?? '') ?: null,
        'addr_street'    => trim($_POST['addr_street']  ?? ''),
        'addr_house'     => trim($_POST['addr_house']   ?? ''),
        'addr_flat'      => trim($_POST['addr_flat']    ?? ''),
        'addr_postal'    => trim($_POST['addr_postal']  ?? ''),
        'addr_city'      => trim($_POST['addr_city']    ?? ''),
        'addr_country'   => trim($_POST['addr_country'] ?? '') ?: 'PL',
        'adres'          => address_format($_POST) ?: (trim($_POST['adres'] ?? '') ?: null),
        'stanowisko'     => trim($_POST['stanowisko'] ?? '') ?: null,
        'organizacja'    => trim($_POST['organizacja']?? '') ?: null,
        'branza'         => trim($_POST['branza']     ?? '') ?: null,
        'strona_www'     => trim($_POST['strona_www'] ?? '') ?: null,
        'source'         => trim($_POST['source']     ?? '') ?: 'manual',   // kolumna NOT NULL DEFAULT 'manual'
        'notatka'        => trim($_POST['notatka']    ?? '') ?: null,
        // Dane formalne (ukryte — potrzebne do umów)
        'pesel'          => preg_replace('/\D/', '', $_POST['pesel'] ?? '') ?: null,
        'data_urodzenia' => trim($_POST['data_urodzenia'] ?? '') ?: null,
        // Lokalizacja
        'wojewodztwo'    => trim($_POST['wojewodztwo'] ?? '') ?: null,
        'powiat'         => trim($_POST['powiat']      ?? '') ?: null,
        'gmina'          => trim($_POST['gmina']       ?? '') ?: null,
    ];

    // Walidacja
    if (!$data['imie_nazwisko'])
        $errors[] = 'Imię lub nazwisko jest wymagane.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Nieprawidłowy adres e-mail.';
    if ($data['pesel'] && strlen($data['pesel']) !== 11)
        $errors[] = 'PESEL musi mieć dokładnie 11 cyfr.';
    if ($data['strona_www'] && !preg_match('/^https?:\/\/.+/i', $data['strona_www']))
        $data['strona_www'] = 'https://' . ltrim($data['strona_www'], '/');

    if (!$errors) {
        $user_id = (int)(current_user()['id'] ?? 0);
        if ($is_edit) {
            CrmManager::updateContact($edit_id, $data);
            flash_set('success', 'Dane kontaktu zaktualizowane.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $edit_id);
        } else {
            $data['created_by'] = $user_id;
            $new_id = CrmManager::createContact($data);
            flash_set('success', 'Kontakt osoby dodany.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $new_id);
        }
        exit;
    }
    $row = array_merge($row, $_POST);
}

// Czy mamy wypełnione dane formalne (do stanu collapsible)
$has_formal = !empty($row['pesel']) || !empty($row['data_urodzenia']);

include __DIR__ . '/../includes/header_crm.php';
?>

<style>
.pf-accent        { --acc: #2563eb; --acc-bg: #EEF4FF; --acc-ring: rgba(37,99,235,.12); }
.pf-header        { background: linear-gradient(90deg,#1D4ED8,#2563eb); border-radius:10px; padding:1.25rem 1.5rem; color:#fff; margin-bottom:1.5rem; display:flex; align-items:center; gap:1rem; }
.pf-header-icon   { width:48px; height:48px; border-radius:50%; background:rgba(255,255,255,.2); border:2px solid rgba(255,255,255,.4); display:flex; align-items:center; justify-content:center; font-size:1.5rem; flex-shrink:0; }
.sec-label        { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#64748b; margin-bottom:.8rem; display:flex; align-items:center; gap:.4rem; }
.sec-label::after { content:''; flex:1; height:1px; background:#e2e8f0; }
.field-hint       { font-size:.75rem; color:#94a3b8; margin-top:.2rem; }
.form-control:focus,
.form-select:focus { border-color:#2563eb; box-shadow:0 0 0 3px var(--acc-ring,rgba(37,99,235,.12)); }
.btn-main-submit  { background:#2563eb; color:#fff; border:none; border-radius:.5rem; padding:.75rem 1.5rem; font-size:.95rem; font-weight:600; width:100%; transition:background .15s; cursor:pointer; }
.btn-main-submit:hover { background:#1D4ED8; }
/* Dane formalne — toggle */
.formal-toggle    { font-size:.8rem; color:#64748b; cursor:pointer; background:none; border:none; padding:.3rem 0; display:flex; align-items:center; gap:.35rem; }
.formal-toggle:hover { color:#2563eb; }
/* Źródło — radio pills */
.source-grid      { display:grid; grid-template-columns:1fr 1fr; gap:.35rem; }
.source-pill      { display:flex; align-items:center; gap:.45rem; padding:.4rem .65rem; border:1px solid #e2e8f0; border-radius:.4rem; cursor:pointer; font-size:.8rem; transition:all .1s; }
.source-pill:hover { border-color:#93c5fd; background:#f0f7ff; }
.source-pill input { flex-shrink:0; }
.source-pill.checked { border-color:#2563eb; background:#EEF4FF; font-weight:600; }
</style>

<!-- Breadcrumb -->
<nav aria-label="Nawigacja" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">Kontakty</a></li>
    <li class="breadcrumb-item active"><?= $is_edit ? 'Edytuj osobę' : 'Nowa osoba' ?></li>
  </ol>
</nav>

<!-- Header -->
<div class="pf-header pf-accent">
  <div class="pf-header-icon"><i class="bi bi-person-fill"></i></div>
  <div>
    <div style="font-size:1.1rem;font-weight:700"><?= $is_edit ? 'Edytuj kontakt — Osoba' : 'Nowy kontakt — Osoba' ?></div>
    <div style="font-size:.81rem;opacity:.8">Wolontariusz, darczyńca, beneficjent, partner, pracownik…</div>
  </div>
  <?php if (!$is_edit): ?>
  <div class="ms-auto">
    <a href="<?= APP_URL ?>/crm/contact/add_org.php" class="btn btn-sm btn-light opacity-75">
      <i class="bi bi-building me-1"></i>To firma/org.?
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <ul class="mb-0 ps-2">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" novalidate class="pf-accent">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="imie_nazwisko" id="imie_nazwisko_hidden" value="<?= h($row['imie_nazwisko'] ?? '') ?>">

<div class="row g-3">

<!-- ══ LEWA — główne dane ══════════════════════════════════════════════════ -->
<div class="col-lg-8 d-flex flex-column gap-3">

  <!-- 1. Dane osoby -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label"><i class="bi bi-person-vcard text-primary"></i>Dane osoby</div>

      <div class="row g-3 mb-3">
        <div class="col-5">
          <label class="form-label fw-semibold" for="imie">Imię <span class="text-danger">*</span></label>
          <input type="text" name="imie" id="imie" class="form-control"
                 value="<?= h($row['imie'] ?? '') ?>"
                 placeholder="np. Anna" autocomplete="given-name"
                 required oninput="syncName()">
        </div>
        <div class="col-7">
          <label class="form-label fw-semibold" for="nazwisko">Nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="nazwisko" id="nazwisko" class="form-control"
                 value="<?= h($row['nazwisko'] ?? '') ?>"
                 placeholder="np. Kowalska" autocomplete="family-name"
                 required oninput="syncName()">
        </div>
      </div>

      <div class="row g-3">
        <div class="col-sm-5">
          <label class="form-label" for="stanowisko">Rola / stanowisko</label>
          <input type="text" name="stanowisko" id="stanowisko" class="form-control"
                 value="<?= h($row['stanowisko'] ?? '') ?>"
                 list="stanowisko_list"
                 placeholder="np. Wolontariusz, Darczyńca…">
          <datalist id="stanowisko_list">
            <option value="Wolontariusz">
            <option value="Koordynator wolontariuszy">
            <option value="Darczyńca indywidualny">
            <option value="Beneficjent">
            <option value="Pracownik">
            <option value="Rodzic / Opiekun prawny">
            <option value="Kontakt zewnętrzny">
            <option value="Dziennikarz / Media">
            <option value="Przedstawiciel partnera">
          </datalist>
        </div>
        <div class="col-sm-7">
          <label class="form-label" for="organizacja">Firma / organizacja</label>
          <input type="text" name="organizacja" id="organizacja" class="form-control"
                 value="<?= h($row['organizacja'] ?? '') ?>"
                 placeholder="Gdzie pracuje lub skąd pochodzi">
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Dane kontaktowe -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label"><i class="bi bi-telephone text-primary"></i>Dane kontaktowe</div>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label" for="email">E-mail</label>
          <input type="email" name="email" id="email" class="form-control"
                 value="<?= h($row['email'] ?? '') ?>"
                 placeholder="anna.kowalska@email.pl"
                 autocomplete="email">
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="telefon">Telefon</label>
          <input type="tel" name="telefon" id="telefon" class="form-control"
                 value="<?= h($row['telefon'] ?? '') ?>"
                 placeholder="123 456 789"
                 autocomplete="tel">
        </div>
      </div>

      <?php echo address_widget($row, ['label'=>'Adres zamieszkania / korespondencji', 'autocomplete'=>true]); ?>
    </div>
  </div>

  <!-- 3. Profil zawodowy / online -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label"><i class="bi bi-briefcase text-primary"></i>Profil</div>

      <div class="row g-3">
        <div class="col-sm-5">
          <label class="form-label" for="branza">Branża / obszar działalności</label>
          <input type="text" name="branza" id="branza" class="form-control"
                 value="<?= h($row['branza'] ?? '') ?>"
                 list="branza_list"
                 placeholder="np. Edukacja, IT, NGO…">
          <datalist id="branza_list">
            <option value="Edukacja">
            <option value="IT / Technologie">
            <option value="Zdrowie / Opieka">
            <option value="Kultura / Sztuka">
            <option value="Sport / Rekreacja">
            <option value="Ochrona środowiska">
            <option value="Pomoc społeczna">
            <option value="Biznes / Finanse">
            <option value="Prawo">
            <option value="Media / Komunikacja">
            <option value="NGO / Organizacje">
            <option value="Administracja publiczna">
          </datalist>
        </div>
        <div class="col-sm-7">
          <label class="form-label" for="strona_www">LinkedIn / profil online / strona</label>
          <input type="url" name="strona_www" id="strona_www" class="form-control"
                 value="<?= h($row['strona_www'] ?? '') ?>"
                 placeholder="https://linkedin.com/in/…">
          <div class="field-hint">Profil LinkedIn, Twitter/X, osobista strona itp.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 4. Notatka wstępna -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label"><i class="bi bi-sticky text-primary"></i>Notatka wstępna</div>
      <textarea name="notatka" id="notatka" class="form-control" rows="3"
                placeholder="Okoliczności poznania, zainteresowania, co warto zapamiętać…"><?= h($row['notatka'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- 5. Dane formalne — zwinięte domyślnie, potrzebne do umów -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <button type="button" class="formal-toggle" onclick="toggleFormal()"
              id="formalToggleBtn" aria-expanded="<?= $has_formal ? 'true' : 'false' ?>">
        <i class="bi bi-chevron-<?= $has_formal ? 'down' : 'right' ?>" id="formalChevron"></i>
        <span>Dane formalne</span>
        <span class="text-muted" style="font-size:.75rem;font-weight:400">(PESEL, data urodzenia — potrzebne tylko przy umowach)</span>
        <?php if ($has_formal): ?>
        <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">uzupełnione</span>
        <?php endif; ?>
      </button>
      <div id="formalSection" style="<?= $has_formal ? '' : 'display:none' ?>; margin-top:.75rem">
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="pesel">PESEL</label>
            <input type="text" name="pesel" id="pesel"
                   class="form-control font-monospace"
                   value="<?= h($row['pesel'] ?? '') ?>"
                   placeholder="00000000000"
                   maxlength="11" pattern="\d{11}" inputmode="numeric"
                   autocomplete="off"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,11);extractBirthdate(this.value)">
            <div class="field-hint">11 cyfr · data urodzenia uzupełni się automatycznie</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="data_urodzenia">Data urodzenia</label>
            <input type="date" name="data_urodzenia" id="data_urodzenia"
                   class="form-control"
                   value="<?= h($row['data_urodzenia'] ?? '') ?>"
                   max="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-sm-6">
            <label class="form-label small" for="powiat">Powiat</label>
            <input type="text" name="powiat" id="powiat" class="form-control form-control-sm"
                   value="<?= h($row['powiat'] ?? '') ?>" placeholder="np. warszawa">
          </div>
          <div class="col-sm-6">
            <label class="form-label small" for="gmina">Gmina</label>
            <input type="text" name="gmina" id="gmina" class="form-control form-control-sm"
                   value="<?= h($row['gmina'] ?? '') ?>" placeholder="gmina">
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /lewa -->

<!-- ══ PRAWA — status, źródło, lokalizacja, submit ══════════════════════════ -->
<div class="col-lg-4 d-flex flex-column gap-3">

  <!-- Status -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label">Status w CRM</div>
      <div class="d-flex flex-column gap-2" role="radiogroup">
        <?php foreach (crm_statuses() as $sk => $sv): ?>
        <label class="d-flex align-items-center gap-2 p-2 border rounded status-option"
               style="cursor:pointer;font-size:.84rem;<?= ($row['status'] ?? 'prospect') === $sk ? 'background:var(--crm-primary-bg);border-color:var(--crm-primary)!important;font-weight:600' : '' ?>">
          <input type="radio" name="status" value="<?= h($sk) ?>"
                 <?= ($row['status'] ?? 'prospect') === $sk ? 'checked' : '' ?>
                 class="form-check-input mt-0">
          <span class="crm-badge crm-badge-<?= h($sk) ?>"><?= h($sv['label']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Źródło kontaktu -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label"><i class="bi bi-signpost-split text-primary"></i>Skąd trafia?</div>
      <div class="source-grid">
        <?php foreach ($source_options as $sv => $sl):
          $checked = ($row['source'] ?? '') === $sv; ?>
        <label class="source-pill <?= $checked ? 'checked' : '' ?>">
          <input type="radio" name="source" value="<?= h($sv) ?>" class="form-check-input mt-0"
                 <?= $checked ? 'checked' : '' ?>
                 onchange="document.querySelectorAll('.source-pill').forEach(function(el){el.classList.remove('checked')});this.closest('.source-pill').classList.add('checked')">
          <span><?= h($sl) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Lokalizacja (tylko województwo — powiat/gmina w Danych formalnych) -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label"><i class="bi bi-geo-alt" style="color:#059669"></i>Lokalizacja</div>
      <?php $woj_list = ['dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie','małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie','pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie']; ?>
      <select name="wojewodztwo" class="form-select form-select-sm">
        <option value="">— województwo —</option>
        <?php foreach ($woj_list as $w): ?>
        <option value="<?= h($w) ?>" <?= ($row['wojewodztwo'] ?? '') === $w ? 'selected' : '' ?>><?= h(ucfirst($w)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="field-hint mt-1">Dokładniejsza lokalizacja (powiat, gmina) w sekcji Dane formalne powyżej.</div>
    </div>
  </div>

  <!-- Podgląd awatara -->
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-3">
      <div id="avatarPreview"
           style="width:64px;height:64px;border-radius:50%;background:#2563eb;color:#fff;
                  display:flex;align-items:center;justify-content:center;font-size:1.4rem;
                  font-weight:700;margin:0 auto .65rem;letter-spacing:1px">
        <?= h(CrmManager::makeInitials($row['imie_nazwisko'] ?? '')) ?: '?' ?>
      </div>
      <div id="avatarName" style="font-size:.86rem;font-weight:600;color:#1e293b">
        <?= h($row['imie_nazwisko'] ?? 'Imię Nazwisko') ?>
      </div>
      <div class="text-muted" style="font-size:.72rem">Osoba fizyczna</div>
    </div>
  </div>

  <!-- Przyciski -->
  <div class="card border-0 shadow-sm">
    <div class="card-body d-flex flex-column gap-2">
      <button type="submit" class="btn-main-submit">
        <i class="bi bi-person-check-fill me-2"></i><?= $is_edit ? 'Zapisz zmiany' : 'Utwórz kontakt' ?>
      </button>
      <a href="<?= $is_edit ? APP_URL . '/crm/contact/view.php?id=' . $edit_id : APP_URL . '/crm/index.php' ?>"
         class="btn btn-outline-secondary btn-sm w-100">Anuluj</a>
      <?php if (!$is_edit): ?>
      <a href="<?= APP_URL ?>/crm/contact/add_org.php"
         class="btn btn-outline-secondary btn-sm w-100" style="opacity:.7">
        <i class="bi bi-building me-1"></i>Zmień na formularz firmy
      </a>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /prawa -->
</div><!-- /row -->
</form>

<script>
// Synchronizuje imię+nazwisko → pole hidden + podgląd awatara
function syncName() {
  var i = (document.getElementById('imie')?.value    || '').trim();
  var n = (document.getElementById('nazwisko')?.value || '').trim();
  var full = [i, n].filter(Boolean).join(' ');
  var h = document.getElementById('imie_nazwisko_hidden');
  if (h) h.value = full;
  var ini = '';
  if (i) ini += i.charAt(0).toUpperCase();
  if (n) ini += n.charAt(0).toUpperCase();
  var av = document.getElementById('avatarPreview');
  if (av) av.textContent = ini || '?';
  var an = document.getElementById('avatarName');
  if (an) an.textContent = full || 'Imię Nazwisko';
}

// Wyciąga datę urodzenia z PESEL
function extractBirthdate(pesel) {
  if (pesel.length !== 11) return;
  var y = parseInt(pesel.slice(0, 2), 10);
  var m = parseInt(pesel.slice(2, 4), 10);
  var d = parseInt(pesel.slice(4, 6), 10);
  if      (m >= 81) { y += 1800; m -= 80; }
  else if (m >= 61) { y += 2200; m -= 60; }
  else if (m >= 41) { y += 2100; m -= 40; }
  else if (m >= 21) { y += 2000; m -= 20; }
  else              { y += 1900; }
  if (m < 1 || m > 12 || d < 1 || d > 31) return;
  var ds = y.toString().padStart(4,'0') + '-' + m.toString().padStart(2,'0') + '-' + d.toString().padStart(2,'0');
  var inp = document.getElementById('data_urodzenia');
  if (inp) inp.value = ds;
}

// Rozwijanie/zwijanie danych formalnych
function toggleFormal() {
  var sec = document.getElementById('formalSection');
  var btn = document.getElementById('formalToggleBtn');
  var chev = document.getElementById('formalChevron');
  var open = sec.style.display === 'none';
  sec.style.display  = open ? '' : 'none';
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-right';
}

// Podświetlenie wybranego statusu
document.querySelectorAll('[name="status"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.querySelectorAll('.status-option').forEach(function(x) {
      x.style.background = '';
      x.style.borderColor = '';
      x.style.fontWeight = '';
    });
    this.closest('.status-option').style.background   = 'var(--crm-primary-bg)';
    this.closest('.status-option').style.borderColor  = 'var(--crm-primary)';
    this.closest('.status-option').style.fontWeight   = '600';
  });
});
</script>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
