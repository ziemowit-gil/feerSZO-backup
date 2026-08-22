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
require_once dirname(dirname(__DIR__)) . '/includes/address.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do dodawania kontaktów.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();

// Wygeneruj token CSRF możliwie wcześnie — gwarancja, że jest w sesji
// zanim formularz zostanie wyrenderowany (i zanim padnie jakikolwiek redirect).
csrf_token();

$edit_id  = (int)($_GET['id'] ?? 0);
$is_edit  = $edit_id > 0;
$row      = [];
$errors   = [];

// Typ kontaktu: domyślnie 'organizacja', ale można wymusić 'kontrahent' lub 'partner'
$org_like_types = array_keys(array_filter(CRM_CONTACT_TYPES, fn($t) => $t['org_like']));

/**
 * Kolejne osoby kontaktowe z formularza (poza osobą główną).
 * Puste wiersze pomijamy — użytkownik mógł dodać wiersz i go nie wypełnić.
 *
 * @return list<array{imie_nazwisko:string,stanowisko:string,email:string,telefon:string}>
 */
function _org_extra_persons(): array
{
    $out = [];
    foreach ((array)($_POST['extra_persons'] ?? []) as $p) {
        if (!is_array($p)) continue;
        $name = trim((string)($p['imie_nazwisko'] ?? ''));
        if ($name === '') continue;
        $out[] = [
            'imie_nazwisko' => $name,
            'stanowisko'    => trim((string)($p['stanowisko'] ?? '')),
            'email'         => trim((string)($p['email']      ?? '')),
            'telefon'       => trim((string)($p['telefon']    ?? '')),
        ];
    }
    return $out;
}

$_init_type = $_GET['type'] ?? 'organizacja';
$contact_type = in_array($_init_type, $org_like_types, true) ? $_init_type : 'organizacja';
$ct_meta = CRM_CONTACT_TYPES[$contact_type];

if ($is_edit) {
    $placeholders = implode(',', array_fill(0, count($org_like_types), '?'));
    $row = db_one(
        "SELECT * FROM crm_contacts WHERE id=? AND crm_active=1 AND type IN ($placeholders)",
        array_merge([$edit_id], $org_like_types)
    );
    if (!$row) {
        flash_set('danger', 'Nie znaleziono kontaktu.');
        header('Location: ' . APP_URL . '/crm/index.php');
        exit;
    }
    $contact_type = $row['type'];
    $ct_meta = CRM_CONTACT_TYPES[$contact_type] ?? CRM_CONTACT_TYPES['organizacja'];
}

$PAGE_TITLE = $is_edit
    ? 'Edytuj ' . strtolower($ct_meta['label']) . ': ' . $row['imie_nazwisko']
    : 'Nowy/-a ' . $ct_meta['label'];

// ── POST ─────────────────────────────────────────────────────────────────────
// Łagodna weryfikacja CSRF: zamiast twardego die() (który kasuje cały, długi
// formularz firmy — zwłaszcza po imporcie z KRS), przy niezgodnym tokenie
// wracamy do formularza z wpisanymi danymi i świeżym tokenem. Bezpieczeństwo
// zachowane: zapis następuje wyłącznie po poprawnym hash_equals + blokadzie zapisu.
$csrf_failed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        $csrf_failed = true;
        $errors[] = 'Sesja lub formularz wygasły (np. otwarty zbyt długo / w innej karcie). '
                  . 'Twoje dane zostały zachowane — kliknij „Utwórz kontakt” jeszcze raz.';
        $row = array_merge($row, $_POST);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_failed) {
    if (function_exists('system_block_writes')) system_block_writes();

    $nazwa = trim($_POST['nazwa'] ?? '');

    $contact_type = in_array(trim($_POST['contact_type'] ?? ''), $org_like_types, true)
        ? trim($_POST['contact_type']) : 'organizacja';
    // Status ustalamy przed $data — decyduje też o dopuszczalności usług na rzecz
    // organizacji, a liczy się wartość zwalidowana, nie surowa z formularza.
    $status = array_key_exists($_POST['status'] ?? '', crm_statuses()) ? $_POST['status'] : 'prospect';
    $data = [
        'type'             => $contact_type,
        'imie_nazwisko'    => $nazwa,                                            // "imię_nazwisko" przechowuje nazwę firmy
        'status'           => $status,
        'email'            => trim($_POST['email']             ?? '') ?: null,
        'telefon'          => trim($_POST['telefon']           ?? '') ?: null,
        'addr_street'      => trim($_POST['addr_street']         ?? ''),
        'addr_house'       => trim($_POST['addr_house']          ?? ''),
        'addr_flat'        => trim($_POST['addr_flat']           ?? ''),
        'addr_postal'      => trim($_POST['addr_postal']         ?? ''),
        'addr_city'        => trim($_POST['addr_city']           ?? ''),
        'addr_country'     => trim($_POST['addr_country']        ?? '') ?: 'PL',
        'adres'            => address_format($_POST) ?: (trim($_POST['adres'] ?? '') ?: null),
        'nip'              => preg_replace('/[\s\-]/', '', $_POST['nip'] ?? '') ?: null,
        'krs'              => preg_replace('/[\s\-]/', '', $_POST['krs'] ?? '') ?: null,
        'regon'            => preg_replace('/[\s\-]/', '', $_POST['regon'] ?? '') ?: null,
        'branza'           => trim($_POST['branza']            ?? '') ?: null,
        'strona_www'       => trim($_POST['strona_www']        ?? '') ?: null,
        'forma_prawna'     => trim($_POST['forma_prawna']      ?? '') ?: null,
        'osoba_kontaktowa' => trim($_POST['osoba_kontaktowa']  ?? '') ?: null,
        'stanowisko'       => trim($_POST['stanowisko']        ?? '') ?: null,
        'notatka'          => trim($_POST['notatka']           ?? '') ?: null,
        'wojewodztwo'      => trim($_POST['wojewodztwo']       ?? '') ?: null,
        'powiat'           => trim($_POST['powiat']            ?? '') ?: null,
        'gmina'            => trim($_POST['gmina']             ?? '') ?: null,
        // Flagę „świadczy usługi" dopuszczamy tylko przy statusie partnera; przy innym
        // statusie pole w formularzu jest ukryte, więc zignorowanie go jest poprawne.
        'swiadczy_uslugi'  => (!empty($_POST['swiadczy_uslugi']) && crm_services_allowed($status)) ? 1 : 0,
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
            // Pole „osoba kontaktowa" na formularzu edytuje osobę główną w rejestrze osób.
            $_name = trim($_POST['osoba_kontaktowa'] ?? '');
            $_pers = CrmManager::getContactPersons($edit_id);
            if ($_name !== '' && $_pers) {
                CrmManager::updateContactPerson((int)$_pers[0]['id'], [
                    'imie_nazwisko' => $_name,
                    'stanowisko'    => $_POST['stanowisko'] ?? '',
                ]);
            } elseif ($_name !== '') {
                CrmManager::addContactPerson($edit_id, [
                    'imie_nazwisko' => $_name,
                    'stanowisko'    => $_POST['stanowisko'] ?? '',
                    'is_primary'    => true,
                ], $user_id);
            }
            foreach (_org_extra_persons() as $_ep) {
                CrmManager::addContactPerson($edit_id, $_ep, $user_id);
            }
            flash_set('success', 'Dane firmy zaktualizowane.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $edit_id);
        } else {
            $data['created_by'] = $user_id;
            $new_id = CrmManager::createContact($data);
            // Pierwsza osoba kontaktowa trafia do rejestru osób (kolejne dodaje się z karty kontaktu).
            if (trim($_POST['osoba_kontaktowa'] ?? '') !== '') {
                CrmManager::addContactPerson($new_id, [
                    'imie_nazwisko' => $_POST['osoba_kontaktowa'] ?? '',
                    'stanowisko'    => $_POST['stanowisko']       ?? '',
                    'email'         => $_POST['osoba_email']      ?? '',
                    'telefon'       => $_POST['osoba_telefon']    ?? '',
                    'is_primary'    => true,
                ], $user_id);
            }
            // Kolejne osoby kontaktowe — dowolna liczba, wszystkie jako niegłówne.
            foreach (_org_extra_persons() as $_ep) {
                CrmManager::addContactPerson($new_id, $_ep, $user_id);
            }
            // Usługi na rzecz organizacji tylko dla statusu „Partner" — model i tak
            // je odrzuci, ale nie ma po co go o to prosić.
            if (crm_services_allowed($status)) {
                foreach (array_map('intval', (array)($_POST['uslugi'] ?? [])) as $stid) {
                    CrmManager::addContactService($new_id, $stid, null, $user_id);
                }
            }
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
/* Formularz firmy — spójny z formularzem osoby i resztą CRM (akcent CRM-primary). */
.of-accent        { --acc: var(--crm-primary, #0176D3); --acc-ring: rgba(1,118,211,.12); }
.of-header        { background: linear-gradient(90deg, var(--crm-navy, #032D60), var(--crm-primary, #0176D3)); border-radius:10px; padding:1.25rem 1.5rem; color:#fff; margin-bottom:1.5rem; display:flex; align-items:center; gap:1rem; }
.of-header-icon   { width:48px; height:48px; border-radius:12px; background:rgba(255,255,255,.2); border:2px solid rgba(255,255,255,.4); display:flex; align-items:center; justify-content:center; font-size:1.5rem; flex-shrink:0; }
.sec-label        { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#64748b; margin-bottom:.8rem; display:flex; align-items:center; gap:.4rem; }
.sec-label::after { content:''; flex:1; height:1px; background:#e2e8f0; }
.field-hint       { font-size:.75rem; color:#94a3b8; margin-top:.2rem; }
.form-control:focus,
.form-select:focus { border-color: var(--crm-primary, #0176D3); box-shadow:0 0 0 3px var(--acc-ring, rgba(1,118,211,.12)); }
.btn-main-submit  { background: var(--crm-primary, #0176D3); color:#fff; border:none; border-radius:.5rem; padding:.75rem 1.5rem; font-size:.95rem; font-weight:600; width:100%; transition:background .15s; cursor:pointer; }
.btn-main-submit:hover { background: var(--crm-navy, #032D60); }
/* ID-field monospace */
.field-id { font-family: ui-monospace, SFMono-Regular, monospace; letter-spacing: .05em; }
/* Karta importu — wyróżnienie */
.of-importer { background: linear-gradient(180deg, var(--crm-primary-bg, #EEF4FF) 0, #fff 70%); }
/* Kafelki typu podmiotu (CEIDG / KRS) */
.of-type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
@media (max-width: 575px) { .of-type-grid { grid-template-columns: 1fr; } }
.of-type-pill {
  display: flex; align-items: center; gap: .6rem; padding: .65rem .8rem;
  border: 1.5px solid #e2e8f0; border-radius: .55rem; cursor: pointer;
  background: #fff; transition: border-color .12s, background .12s, box-shadow .12s;
}
.of-type-pill:hover { border-color: #93c5fd; }
.of-type-pill--active { border-color: var(--crm-primary, #0176D3); background: var(--crm-primary-bg, #EEF4FF); box-shadow: 0 0 0 3px var(--acc-ring, rgba(1,118,211,.1)); }
.of-type-ico { width: 34px; height: 34px; flex-shrink: 0; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; background: var(--crm-primary-bg, #EEF4FF); color: var(--crm-primary, #0176D3); }
.of-type-txt { display: flex; flex-direction: column; line-height: 1.2; }
.of-type-txt strong { font-size: .9rem; color: #1e293b; }
.of-type-txt small { font-size: .72rem; color: #64748b; }
.of-type-pill input { position: absolute; opacity: 0; pointer-events: none; }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">Kontakty</a></li>
    <li class="breadcrumb-item active" aria-current="page">
      <?= $is_edit ? 'Edytuj ' . h($ct_meta['label']) : 'Nowy/-a ' . h($ct_meta['label']) ?>
    </li>
  </ol>
</nav>

<!-- Header formularza -->
<div class="of-header of-accent" role="banner">
  <div class="of-header-icon" aria-hidden="true"><i class="bi <?= h($ct_meta['icon']) ?>"></i></div>
  <div>
    <div style="font-size:1.15rem;font-weight:700">
      <?= $is_edit ? 'Edytuj ' . h(strtolower($ct_meta['label'])) : 'Nowy kontakt — ' . h($ct_meta['label']) ?>
    </div>
    <div style="font-size:.82rem;opacity:.8">Organizacja, kontrahent, partner — dowolna firma lub instytucja</div>
  </div>
  <?php if (!$is_edit): ?>
  <div class="ms-auto">
    <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-sm btn-light opacity-75"
       aria-label="Przełącz na formularz osoby fizycznej">
      <i class="bi bi-person me-1"></i>To osoba?
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert" aria-live="assertive">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <ul class="mb-0 ps-2">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" novalidate class="of-accent" aria-label="Formularz firmy / organizacji CRM">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="contact_type" value="<?= h($contact_type) ?>">

<!-- ══ SZYBKI IMPORT — przycisk otwierający kreator (CEIDG / KRS) ═════════════ -->
<div class="card border-0 shadow-sm mb-3 of-importer">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="of-type-ico" style="width:42px;height:42px;font-size:1.3rem" aria-hidden="true">
      <i class="bi bi-stars"></i>
    </div>
    <div class="flex-grow-1" style="min-width:200px">
      <div style="font-weight:700;color:#1e293b">Szybkie wypełnienie z rejestru</div>
      <div class="field-hint">Zaciągnij dane firmy z <b>CEIDG</b> (JDG po NIP) lub organizacji z <b>KRS</b> — dane zobaczysz w oknie i potwierdzisz przed importem.</div>
      <div id="importApplied" class="text-success mt-1" style="font-size:.8rem;display:none">
        <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i><span></span>
      </div>
    </div>
    <button type="button" class="btn flex-shrink-0"
            style="background:var(--crm-primary,#0176D3);color:#fff;border:none"
            data-bs-toggle="modal" data-bs-target="#importWizardModal">
      <i class="bi bi-magic me-1" aria-hidden="true"></i>Otwórz kreatora importu
    </button>
  </div>
</div>

<div class="row g-3">

<!-- ── Lewa — dane firmy ─────────────────────────────────────── -->
<div class="col-lg-8">

  <!-- 1. Dane rejestrowe -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="sec-label">
        <i class="bi bi-building" style="color:var(--crm-primary,#0176D3)" aria-hidden="true"></i>
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
      <div class="sec-label">
        <i class="bi bi-fingerprint" style="color:var(--crm-primary,#0176D3)" aria-hidden="true"></i>
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
      <div class="sec-label">
        <i class="bi bi-telephone" style="color:var(--crm-primary,#0176D3)" aria-hidden="true"></i>
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
      <?php echo address_widget($row, ['label'=>'Adres siedziby', 'autocomplete'=>true]); ?>
      <div class="row g-3 mt-2">
        <div class="col-sm-6">
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
      <div class="sec-label">
        <i class="bi bi-person-badge" style="color:var(--crm-primary,#0176D3)" aria-hidden="true"></i>
        Osoba kontaktowa<?= $is_edit ? ' (główna)' : '' ?>
      </div>
      <p class="field-hint mb-3" style="margin-top:-.4rem">
        Podmiot może mieć <strong>wiele osób kontaktowych</strong>. Pole poniżej to osoba
        <strong>główna</strong>; kolejne dodasz przyciskiem na dole tej sekcji — od razu, bez zapisywania.
        <?php if ($is_edit): ?>
        Osoby już zapisane edytujesz i porządkujesz na
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$edit_id ?>">karcie kontaktu</a>,
        w panelu „Osoby kontaktowe”.
        <?php endif; ?>
      </p>
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
        <?php if (!$is_edit): ?>
        <div class="col-sm-6">
          <label class="form-label" for="osoba_email">E-mail osoby kontaktowej</label>
          <input type="email" name="osoba_email" id="osoba_email" class="form-control"
                 value="<?= h($_POST['osoba_email'] ?? '') ?>" placeholder="np. a.nowak@example.com"
                 autocomplete="email">
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="osoba_telefon">Telefon osoby kontaktowej</label>
          <input type="text" name="osoba_telefon" id="osoba_telefon" class="form-control"
                 value="<?= h($_POST['osoba_telefon'] ?? '') ?>" placeholder="np. 600 100 200"
                 autocomplete="tel">
        </div>
        <?php endif; ?>
      </div>

      <!-- Kolejne osoby kontaktowe — podmiot może mieć ich dowolnie wiele.
           Wiersz pierwszy (powyżej) to osoba główna; te dopisują się jako następne. -->
      <div id="extraPersons" class="mt-3"></div>

      <template id="extraPersonTpl">
        <div class="border rounded p-2 mb-2 position-relative" data-extra-person>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="form-label mb-0" style="font-size:.85rem;font-weight:600">
              Osoba kontaktowa <span data-person-no></span>
            </span>
            <button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-person>
              <i class="bi bi-x-lg" aria-hidden="true"></i> Usuń
            </button>
          </div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label">Imię i nazwisko</label>
              <input type="text" name="extra_persons[__I__][imie_nazwisko]" class="form-control" placeholder="np. Jan Kowalski" autocomplete="off">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Stanowisko / Rola</label>
              <input type="text" name="extra_persons[__I__][stanowisko]" class="form-control"
                     list="stanowisko_org_suggestions" placeholder="np. Główny księgowy" autocomplete="off">
            </div>
            <div class="col-sm-6">
              <label class="form-label">E-mail</label>
              <input type="email" name="extra_persons[__I__][email]" class="form-control" placeholder="np. j.kowalski@example.com" autocomplete="off">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Telefon</label>
              <input type="text" name="extra_persons[__I__][telefon]" class="form-control" placeholder="np. 600 100 201" autocomplete="off">
            </div>
          </div>
        </div>
      </template>

      <button type="button" class="btn btn-sm btn-crm-outline" id="addPersonBtn">
        <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Dodaj kolejną osobę kontaktową
      </button>
    </div>
  </div>

  <!-- 4b. Usługi na rzecz organizacji — tylko przy statusie „Partner" -->
  <?php
    $_svc_types  = crm_service_types(true);
    $_org_short  = org_setting('org_short_name') ?: 'FEER';
    // Widoczność zależy od statusu wybranego w prawej kolumnie tego samego
    // formularza, więc poza renderem serwerowym przełącza ją też JS (na końcu pliku).
    $_svc_status = $row['status'] ?? 'prospect';
    $_svc_open   = crm_services_allowed($_svc_status);
  ?>
  <div class="card border-0 shadow-sm mb-3" id="svc_card" data-svc-status="<?= h(CRM_SERVICES_STATUS) ?>">
    <div class="card-body">
      <div class="sec-label">
        <i class="bi bi-tools" style="color:#0F766E" aria-hidden="true"></i>
        Usługi na rzecz <?= h($_org_short) ?>
      </div>

      <p class="field-hint" id="svc_locked" <?= $_svc_open ? 'hidden' : '' ?> style="margin-top:-.4rem">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Dostępne tylko dla kontaktów o statusie <strong><?= h(crm_services_status_label()) ?></strong>.
        Zmień status w kolumnie obok, aby wskazać świadczone usługi.
      </p>

      <div id="svc_fields" <?= $_svc_open ? '' : 'hidden' ?>>
      <div class="form-check mb-2">
        <input type="checkbox" name="swiadczy_uslugi" id="swiadczy_uslugi" value="1" class="form-check-input"
               <?= !empty($row['swiadczy_uslugi']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="swiadczy_uslugi">
          <strong>Świadczy usługi na rzecz <?= h($_org_short) ?></strong>
        </label>
      </div>
      <?php if (!$is_edit && $_svc_types): ?>
      <fieldset>
        <legend class="form-label mb-1" style="font-size:inherit">Rodzaje usług</legend>
        <div class="row g-1">
          <?php foreach ($_svc_types as $t): ?>
          <div class="col-sm-6 col-lg-4">
            <div class="form-check">
              <input type="checkbox" name="uslugi[]" value="<?= (int)$t['id'] ?>"
                     id="svc_<?= (int)$t['id'] ?>" class="form-check-input">
              <label class="form-check-label small" for="svc_<?= (int)$t['id'] ?>"><?= h($t['nazwa']) ?></label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <p class="field-hint">Katalog jest otwarty — brakujący rodzaj dopiszesz na karcie kontaktu
        albo w <a href="<?= APP_URL ?>/crm/settings/services.php">Ustawieniach CRM → Rodzaje usług</a>.</p>
      <?php else: ?>
      <p class="field-hint mb-0">Rodzaje usług przypisujesz na
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$edit_id ?>">karcie kontaktu</a>,
        w panelu „Usługi na rzecz <?= h($_org_short) ?>”.</p>
      <?php endif; ?>
      </div><!-- /#svc_fields -->
    </div>
  </div>

  <!-- 5b. Terytorium -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="sec-label">
        <i class="bi bi-map" style="color:#059669" aria-hidden="true"></i>
        Terytorium
      </div>
      <?php
      $woj_list = ['dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie','małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie','pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie'];
      ?>
      <div class="row g-2">
        <div class="col-md-5">
          <label class="form-label small mb-1">Województwo</label>
          <select name="wojewodztwo" class="form-select form-select-sm">
            <option value="">— wybierz —</option>
            <?php foreach ($woj_list as $w): ?>
            <option value="<?= h($w) ?>" <?= ($row['wojewodztwo']??'') === $w ? 'selected' : '' ?>><?= h(ucfirst($w)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1">Powiat</label>
          <input type="text" name="powiat" class="form-control form-control-sm"
                 value="<?= h($row['powiat'] ?? '') ?>" placeholder="np. m. Kraków">
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1">Gmina</label>
          <input type="text" name="gmina" class="form-control form-control-sm"
                 value="<?= h($row['gmina'] ?? '') ?>" placeholder="gmina">
        </div>
      </div>
    </div>
  </div>

  <!-- 5. Notatka -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="sec-label">
        <i class="bi bi-sticky" style="color:var(--crm-primary,#0176D3)" aria-hidden="true"></i>
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
      <div class="sec-label">Status w CRM</div>
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
      <div class="sec-label" style="justify-content:center">Podgląd awatara</div>
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

  <!-- Przycisk wyślij -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <button type="submit" class="btn-main-submit"
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

<!-- ══ MODAL: KREATOR IMPORTU (CEIDG / KRS) ══════════════════════════════════ -->
<div class="modal fade" id="importWizardModal" tabindex="-1" aria-labelledby="importWizardTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 mb-0" id="importWizardTitle">
          <i class="bi bi-magic me-2" style="color:var(--crm-primary,#0176D3)" aria-hidden="true"></i>
          Kreator importu danych podmiotu
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>

      <div class="modal-body">

        <!-- KROK 1: wybór typu + wyszukiwanie -->
        <div id="iw-step-search">
          <p class="text-muted small mb-2">Wybierz typ podmiotu i podaj numer — dane pobierzemy z właściwego rejestru i pokażemy do zatwierdzenia.</p>

          <div class="of-type-grid mb-3" role="radiogroup" aria-label="Typ podmiotu i źródło importu">
            <label class="of-type-pill" data-mode="ceidg">
              <input type="radio" name="_import_mode" value="ceidg" class="form-check-input mt-0"
                     onchange="setImportMode('ceidg')">
              <span class="of-type-ico" aria-hidden="true"><i class="bi bi-shop"></i></span>
              <span class="of-type-txt">
                <strong>Firma (JDG)</strong>
                <small>Jednoosobowa działalność — import z <b>CEIDG</b> po NIP</small>
              </span>
            </label>
            <label class="of-type-pill of-type-pill--active" data-mode="krs">
              <input type="radio" name="_import_mode" value="krs" class="form-check-input mt-0" checked
                     onchange="setImportMode('krs')">
              <span class="of-type-ico" aria-hidden="true"><i class="bi bi-bank"></i></span>
              <span class="of-type-txt">
                <strong>Organizacja / spółka</strong>
                <small>Fundacja, stowarzyszenie, sp. z o.o. — import z <b>KRS</b></small>
              </span>
            </label>
          </div>

          <label class="form-label small fw-semibold mb-1" id="importInputLabel" for="importInput">Numer KRS</label>
          <div class="input-group input-group-sm mb-2" style="max-width:420px">
            <input type="text" id="importInput" class="form-control font-monospace"
                   placeholder="0000000000" maxlength="10" inputmode="numeric"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                   aria-describedby="importInputLabel">
            <button type="button" class="btn" id="importBtn"
                    style="background:var(--crm-primary,#0176D3);color:#fff;border:none"
                    onclick="entitySearch()">
              <i class="bi bi-search me-1" aria-hidden="true"></i><span id="importBtnLabel">Szukaj w KRS</span>
            </button>
          </div>
          <div id="importStatus" style="font-size:.82rem;min-height:1.2rem" aria-live="polite"></div>

          <p class="field-hint mt-2 mb-0">
            Nie znasz numeru? Możesz
            <a id="gusLink" href="#" target="_blank" rel="noopener" onclick="return openGUS()">sprawdzić podmiot w wyszukiwarce GUS</a>
            i uzupełnić pola ręcznie.
          </p>
        </div>

        <!-- KROK 2: podgląd danych przed importem -->
        <div id="iw-step-review" style="display:none">
          <div class="alert alert-success d-flex align-items-start gap-2 py-2" role="status">
            <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
              <strong>Znaleziono podmiot</strong> w rejestrze <span id="iw-source-label">KRS</span>.
              Sprawdź dane poniżej i potwierdź import do formularza.
            </div>
          </div>
          <dl class="cv-dl mb-0" id="importReviewTable" style="display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem"></dl>
        </div>

      </div>

      <div class="modal-footer">
        <!-- stopka kroku wyszukiwania -->
        <div id="iw-foot-search" class="d-flex gap-2 w-100 justify-content-end">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
        </div>
        <!-- stopka kroku podglądu -->
        <div id="iw-foot-review" class="d-flex gap-2 w-100 justify-content-between" style="display:none!important">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="iwBackToSearch()">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Szukaj ponownie
          </button>
          <button type="button" class="btn btn-sm"
                  style="background:var(--crm-primary,#0176D3);color:#fff;border:none"
                  onclick="entityApply()">
            <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Zaimportuj do formularza
          </button>
        </div>
      </div>

    </div>
  </div>
</div>

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
    syncServicesSection();
  });
});

// Sekcja „Usługi na rzecz FEER" istnieje tylko dla statusu partnera. Status wybiera
// się w tym samym formularzu, więc pola pokazujemy/ukrywamy od razu po zmianie.
// Serwer i tak waliduje status przy zapisie — to jest wyłącznie warstwa UI.
function syncServicesSection() {
  var card = document.getElementById('svc_card');
  if (!card) return;
  var need    = card.dataset.svcStatus;
  var checked = document.querySelector('[name="status"]:checked');
  var open    = !!checked && checked.value === need;

  var fields = document.getElementById('svc_fields');
  var locked = document.getElementById('svc_locked');
  if (fields) fields.hidden = !open;
  if (locked) locked.hidden = open;

  // Ukryte pola nie mogą wysyłać wartości — inaczej zaznaczone rodzaje usług
  // pojechałyby na serwer razem ze statusem, który ich nie dopuszcza.
  if (!open && fields) {
    fields.querySelectorAll('input[type="checkbox"]').forEach(function(c) { c.checked = false; });
  }
}
syncServicesSection();

// ── Kolejne osoby kontaktowe ────────────────────────────────────────────────
// Podmiot może mieć wiele osób kontaktowych. Wiersz główny jest w formularzu na
// stałe, kolejne klonujemy z <template>; __I__ w nazwach pól zastępujemy indeksem,
// bo `extra_persons[][pole]` w PHP rozbiłoby jedną osobę na kilka elementów.
(function () {
    var box = document.getElementById('extraPersons');
    var tpl = document.getElementById('extraPersonTpl');
    var btn = document.getElementById('addPersonBtn');
    if (!box || !tpl || !btn) return;

    var next = 0;   // rośnie zawsze — indeksy nie muszą być ciągłe, serwer je przenumeruje

    function renumberLabels() {
        box.querySelectorAll('[data-person-no]').forEach(function (el, i) {
            el.textContent = '#' + (i + 2);   // #1 to osoba główna w wierszu powyżej
        });
    }

    btn.addEventListener('click', function () {
        var frag = tpl.content.cloneNode(true);
        frag.querySelectorAll('[name]').forEach(function (inp) {
            inp.name = inp.name.replace('__I__', String(next));
        });
        var row = frag.querySelector('[data-extra-person]');
        box.appendChild(frag);
        next++;
        renumberLabels();
        var first = row.querySelector('input');
        if (first) first.focus();
    });

    box.addEventListener('click', function (ev) {
        var rm = ev.target.closest('[data-remove-person]');
        if (!rm) return;
        rm.closest('[data-extra-person]').remove();
        renumberLabels();
        btn.focus();
    });
})();

// Inicjuj podgląd przy załadowaniu (tryb edycji)
(function() {
  var n = document.getElementById('nazwa');
  if (n && n.value) updateOrgPreview(n.value);
})();

// ── Import danych podmiotu: CEIDG (JDG po NIP) lub KRS ─────────────────────────
var importMode = 'krs';      // 'ceidg' | 'krs'
var _importData = null;

// Konfiguracja per-tryb (etykiety, API, walidacja)
var IMPORT_CFG = {
  ceidg: {
    label:   'Numer NIP firmy',
    btn:     'Szukaj w CEIDG',
    ph:      '0000000000',
    api:     '<?= APP_URL ?>/api/ceidg.php?nip=',
    param:   'nip',
    digits:  10,
    source:  'CEIDG',
  },
  krs: {
    label:   'Numer KRS',
    btn:     'Szukaj w KRS',
    ph:      '0000000000',
    api:     '<?= APP_URL ?>/api/krs.php?krs=',
    param:   'krs',
    digits:  10,
    minLen:  6,
    source:  'KRS',
  }
};

function setImportMode(mode) {
  if (!IMPORT_CFG[mode]) return;
  importMode = mode;
  var cfg = IMPORT_CFG[mode];

  // Podświetl wybrany kafelek
  document.querySelectorAll('.of-type-pill').forEach(function(p) {
    p.classList.toggle('of-type-pill--active', p.getAttribute('data-mode') === mode);
  });

  // Zaktualizuj etykiety i pole
  var lbl = document.getElementById('importInputLabel');
  var inp = document.getElementById('importInput');
  var bl  = document.getElementById('importBtnLabel');
  if (lbl) lbl.textContent = cfg.label;
  if (bl)  bl.textContent  = cfg.btn;
  if (inp) { inp.value = ''; inp.placeholder = cfg.ph; inp.setAttribute('maxlength', cfg.digits); }

  // Wyczyść poprzednie wyniki i wróć do kroku wyszukiwania
  _importData = null;
  importSetStatus('', '');
  iwSetStep('search');
}

function entitySearch() {
  var cfg = IMPORT_CFG[importMode];
  var val = (document.getElementById('importInput')?.value || '').replace(/\D/g,'');
  var minLen = cfg.minLen || cfg.digits;
  if (!val || val.length < minLen) {
    importSetStatus('warning', 'Wpisz numer ' + (importMode==='ceidg'?'NIP (10 cyfr)':'KRS (min. ' + minLen + ' cyfr)') + '.');
    return;
  }

  var btn = document.getElementById('importBtn');
  if (btn) btn.disabled = true;
  importSetStatus('info', '<span class="spinner-border spinner-border-sm me-1"></span>Pobieranie danych z ' + cfg.source + '…');

  fetch(cfg.api + encodeURIComponent(val), { headers: { 'Accept': 'application/json' } })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (btn) btn.disabled = false;
    if (data.error) {
      importSetStatus('danger', '<i class="bi bi-x-circle me-1"></i>' + escHtml(data.error));
      return;
    }
    _importData = data;
    importSetStatus('', '');
    iwShowReview(data);
  })
  .catch(function(err) {
    if (btn) btn.disabled = false;
    importSetStatus('danger', '<i class="bi bi-x-circle me-1"></i>Błąd połączenia: ' + escHtml(err.message));
  });
}

// Przełącza widoczny krok kreatora (search ↔ review) wraz ze stopką
function iwSetStep(step) {
  var review = step === 'review';
  var s = document.getElementById('iw-step-search');
  var r = document.getElementById('iw-step-review');
  var fs = document.getElementById('iw-foot-search');
  var fr = document.getElementById('iw-foot-review');
  if (s)  s.style.display  = review ? 'none' : '';
  if (r)  r.style.display  = review ? '' : 'none';
  if (fs) fs.style.setProperty('display', review ? 'none' : 'flex', 'important');
  if (fr) fr.style.setProperty('display', review ? 'flex' : 'none', 'important');
}

function iwBackToSearch() {
  iwSetStep('search');
  importSetStatus('', '');
  document.getElementById('importInput')?.focus();
}

// Renderuje znalezione dane w popupie (krok podglądu) PRZED importem
function iwShowReview(d) {
  var cfg = IMPORT_CFG[importMode];
  var srcLbl = importMode === 'krs' ? (d.rejestr_label || cfg.source) : cfg.source;
  var lbl = document.getElementById('iw-source-label');
  if (lbl) lbl.textContent = srcLbl;

  var rows = [
    ['Nazwa',        d.nazwa,        false],
    ['Forma prawna', d.forma_prawna, false],
    ['KRS',          d.krs,          true],
    ['NIP',          d.nip,          true],
    ['REGON',        d.regon,        true],
    ['Adres',        d.adres,        false],
  ];
  if (importMode === 'ceidg' && d.status) {
    rows.push(['Status', d.status + (d.aktywna ? ' ✓' : ' ⚠️ (nieaktywna)'), false]);
  }

  var html = '';
  rows.forEach(function(r) {
    if (!r[1]) return;
    var val = r[2] ? '<code>' + escHtml(r[1]) + '</code>' : escHtml(r[1]);
    html += '<dt class="text-muted fw-normal">' + escHtml(r[0]) + '</dt>'
          + '<dd class="mb-0 fw-semibold">' + val + '</dd>';
  });
  var table = document.getElementById('importReviewTable');
  if (table) table.innerHTML = html;

  iwSetStep('review');
}

function entityApply() {
  if (!_importData) return;
  var d = _importData;

  setField('nazwa', d.nazwa);
  setField('nip',   d.nip);
  setField('krs',   d.krs);      // brak w CEIDG → pominięte
  setField('regon', d.regon);

  if (d.adres) applyAddress(d.adres);

  // Forma prawna
  if (importMode === 'ceidg') {
    // JDG zawsze → jednoosobowa działalność gospodarcza
    setSelect('forma_prawna', 'jednoosobowa');
  } else {
    var formaMap = {
      'fundacja': 'fundacja',
      'stowarzyszenie': 'stowarzyszenie',
      'spółka z ograniczoną odpowiedzialnością': 'sp_z_oo',
      'spółka akcyjna': 'sa',
      'spółdzielnia': 'spoldzielnia',
    };
    var fp = (d.forma_prawna || '').toLowerCase();
    for (var key in formaMap) {
      if (fp.indexOf(key) !== -1) { setSelect('forma_prawna', formaMap[key]); break; }
    }
  }

  if (d.nazwa) updateOrgPreview(d.nazwa);

  var src = importMode === 'krs' ? (d.rejestr_label || 'KRS') : 'CEIDG';

  // Zamknij modal kreatora
  var modalEl = document.getElementById('importWizardModal');
  if (modalEl && window.bootstrap) {
    var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    inst.hide();
  }

  // Potwierdzenie przy karcie + zresetuj kreatora do kroku wyszukiwania
  var applied = document.getElementById('importApplied');
  if (applied) {
    applied.querySelector('span').textContent = 'Dane zaimportowane z ' + src + '. Sprawdź i uzupełnij brakujące pola.';
    applied.style.display = '';
  }
  iwSetStep('search');
  importSetStatus('', '');

  document.getElementById('nazwa')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Helpery wspólne ────────────────────────────────────────────────────────────
function setField(id, val) {
  var el = document.getElementById(id);
  if (el && val) { el.value = val; el.classList.add('autofilled-krs'); setTimeout(function(){ el.classList.remove('autofilled-krs'); }, 1200); }
}
function setSelect(id, val) {
  var el = document.getElementById(id);
  if (!el || !val) return;
  for (var i = 0; i < el.options.length; i++) {
    if (el.options[i].value.toLowerCase() === val.toLowerCase() ||
        el.options[i].text.toLowerCase().indexOf(val.toLowerCase().slice(0, 8)) !== -1) {
      el.selectedIndex = i; break;
    }
  }
}
// Rozbija jednoliniowy adres ("ul. Ulica 5/12, 00-000 Miasto") na pola widgetu
function applyAddress(adres) {
  adres = (adres || '').trim();
  var postalCity = adres.match(/(\d{2}-\d{3})\s+(.+)$/);
  var code = '', city = '', street = '', house = '', flat = '';
  if (postalCity) {
    code = postalCity[1];
    city = postalCity[2].trim();
    var rem = adres.replace(/,?\s*\d{2}-\d{3}\s+.+$/, '').trim();
    var hm = rem.match(/^(.*?)\s+([\d\w]+(?:\/[\w\d]+)?)$/);
    if (hm && /^\d/.test(hm[2])) {
      street = hm[1].trim();
      var nr = hm[2];
      if (nr.indexOf('/') !== -1) { var p = nr.split('/'); house = p[0]; flat = p[1]; }
      else { house = nr; }
    } else { street = rem; }
  } else { street = adres; }

  var w = document.querySelector('.addr-widget');
  if (!w) return;
  function setAddr(name, val) {
    var el = w.querySelector('[name="' + name + '"]');
    if (el && val) { el.value = val; el.classList.add('autofilled-krs'); setTimeout(function(){ el.classList.remove('autofilled-krs'); }, 1200); }
  }
  setAddr('addr_street', street);
  setAddr('addr_house',  house);
  setAddr('addr_flat',   flat);
  setAddr('addr_postal', code);
  setAddr('addr_city',   city);
}
function importSetStatus(type, html) {
  var el = document.getElementById('importStatus');
  if (!el) return;
  if (!html) { el.innerHTML = ''; return; }
  var colors = { info:'#0176D3', success:'#2E844A', warning:'#D97706', danger:'#DC2626' };
  el.innerHTML = '<span style="color:' + (colors[type]||'#374151') + '">' + html + '</span>';
}
function escHtml(str) {
  return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Szukaj po Enter w polu importu
document.getElementById('importInput')?.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); entitySearch(); }
});

// Ustaw tryb początkowy zgodnie z zaznaczonym radiem (domyślnie KRS)
(function() {
  var checked = document.querySelector('input[name="_import_mode"]:checked');
  setImportMode(checked ? checked.value : 'krs');
})();

// Po otwarciu kreatora: krok wyszukiwania + focus na polu numeru
document.getElementById('importWizardModal')?.addEventListener('shown.bs.modal', function() {
  iwSetStep('search');
  document.getElementById('importInput')?.focus();
});
</script>

<style>
@keyframes krsHighlight { 0%{background:#fffbe6} 100%{background:transparent} }
.autofilled-krs { animation: krsHighlight 1.2s ease }
</style>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
