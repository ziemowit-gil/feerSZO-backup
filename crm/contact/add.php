<?php
/**
 * crm/contact/add.php — Dodaj / edytuj kontakt CRM.
 * GET ?id=X → tryb edycji; bez id → nowy kontakt.
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
    flash_set('danger', 'Brak uprawnień do dodawania/edycji kontaktów.');
    header('Location: ' . APP_URL . '/crm/index.php');
    exit;
}
crm_migrate();

$edit_id = (int)($_GET['id'] ?? 0);
$is_edit = $edit_id > 0;

// Załaduj istniejący kontakt lub pusta tablica
$row = [];
if ($is_edit) {
    $row = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [$edit_id]);
    if (!$row) {
        flash_set('danger', 'Kontakt nie istnieje.');
        header('Location: ' . APP_URL . '/crm/index.php');
        exit;
    }
}

$PAGE_TITLE = $is_edit ? 'CRM — Edytuj: ' . $row['imie_nazwisko'] : 'CRM — Nowy kontakt';
$errors = [];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $data = [
        'imie_nazwisko' => trim($_POST['imie_nazwisko'] ?? ''),
        'type'          => in_array($_POST['type'] ?? '', ['osoba','organizacja'], true) ? $_POST['type'] : 'osoba',
        'status'        => array_key_exists($_POST['status'] ?? '', crm_statuses()) ? $_POST['status'] : 'prospect',
        'email'         => trim($_POST['email'] ?? '') ?: null,
        'telefon'       => trim($_POST['telefon'] ?? '') ?: null,
        'addr_street'   => trim($_POST['addr_street']  ?? ''),
        'addr_house'    => trim($_POST['addr_house']   ?? ''),
        'addr_flat'     => trim($_POST['addr_flat']    ?? ''),
        'addr_postal'   => trim($_POST['addr_postal']  ?? ''),
        'addr_city'     => trim($_POST['addr_city']    ?? ''),
        'addr_country'  => trim($_POST['addr_country'] ?? '') ?: 'PL',
        'adres'         => address_format($_POST) ?: (trim($_POST['adres'] ?? '') ?: null),
        'nip'           => trim($_POST['nip'] ?? '') ?: null,
        'krs'           => trim($_POST['krs'] ?? '') ?: null,
        'stanowisko'    => trim($_POST['stanowisko'] ?? '') ?: null,
        'organizacja'   => trim($_POST['organizacja'] ?? '') ?: null,
        'notatka'       => trim($_POST['notatka'] ?? '') ?: null,
    ];

    // Walidacja
    if ($data['imie_nazwisko'] === '') {
        $errors[] = 'Imię i nazwisko / nazwa jest wymagana.';
    }
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Podany adres e-mail jest nieprawidłowy.';
    }

    if (!$errors) {
        $user_id = (int)(current_user()['id'] ?? 0);
        if ($is_edit) {
            CrmManager::updateContact($edit_id, $data);
            CrmManager::saveFieldValues($edit_id, crm_post_custom_fields($data['type']));
            flash_set('success', 'Kontakt zaktualizowany pomyślnie.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $edit_id);
        } else {
            $data['created_by'] = $user_id;
            $new_id = CrmManager::createContact($data);
            CrmManager::saveFieldValues($new_id, crm_post_custom_fields($data['type']));
            flash_set('success', 'Kontakt dodany pomyślnie.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $new_id);
        }
        exit;
    }

    // Zachowaj wartości po błędzie
    $row = array_merge($row, $_POST);
}

// Typ kontaktu, pod który renderujemy formularz: z POST-a po błędzie walidacji,
// z bazy w edycji, „osoba” dla nowego. $cur_key sprowadza cztery typy do dwóch
// zestawów pól (podmioty dzielą pola organizacji).
$cur_type = (string)($row['type'] ?? 'osoba');
if (!isset(CRM_CONTACT_TYPES[$cur_type])) $cur_type = 'osoba';
$cur_key  = crm_field_applies_key($cur_type);

// Mapa typ → zestaw pól, żeby skrypt formularza nie musiał znać CRM_CONTACT_TYPES.
$_type_keys = [];
foreach (CRM_CONTACT_TYPES as $_tk => $_tv) $_type_keys[$_tk] = crm_field_applies_key($_tk);

// Definicje pól dodatkowych — WSZYSTKIE (oba typy), bo formularz przełącza je
// na żywo przy zmianie typu kontaktu. Filtr po typie robi JS + zapis niżej.
$_field_defs_all = CrmManager::getFieldDefs();
$_field_defs     = array_filter($_field_defs_all, 'crm_field_visible');
$_field_values   = $is_edit ? CrmManager::getFieldValues($edit_id) : [];

// Widoczność i edytowalność pól systemowych w formularzu
$_sfe = [];
foreach (['email','telefon','adres','stanowisko','organizacja','nip','krs','notatka'] as $_sfk) {
    $_sfe[$_sfk] = [
        'vis'  => crm_sys_field_visible($_sfk),
        'edit' => crm_sys_field_editable($_sfk),
    ];
}

include __DIR__ . '/../includes/header_crm.php';
?>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <?php if ($is_edit): ?>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $edit_id ?>"><?= h($row['imie_nazwisko']) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">Edytuj</li>
    <?php else: ?>
    <li class="breadcrumb-item active" aria-current="page">Nowy kontakt</li>
    <?php endif; ?>
  </ol>
</nav>

<!-- Object header -->
<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon" aria-hidden="true">
    <i class="bi bi-person-plus-fill"></i>
  </div>
  <div>
    <h1 class="crm-object-title"><?= $is_edit ? 'Edytuj kontakt' : 'Nowy kontakt CRM' ?></h1>
    <div class="crm-object-count"><?= $is_edit ? h($row['imie_nazwisko']) : 'Wypełnij dane poniżej' ?></div>
  </div>
  <div class="crm-object-actions">
    <?php if ($is_edit): ?>
    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $edit_id ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-x-lg me-1"></i>Anuluj
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Lista kontaktów
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <ul class="mb-0 ps-2">
    <?php foreach ($errors as $e): ?>
    <li><?= h($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- ══ FORMULARZ ════════════════════════════════════════════════════════════ -->
<form method="post" novalidate aria-label="Formularz kontaktu CRM">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-3">

  <!-- Lewa: główne dane -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="crm-section-title">Dane podstawowe</div>

        <div class="row g-3">
          <div class="col-sm-8">
            <label class="form-label fw-semibold" for="imie_nazwisko">
              <span data-crm-label="osoba"<?= $cur_key === 'osoba' ? '' : ' hidden' ?>>Imię i nazwisko</span>
              <span data-crm-label="organizacja"<?= $cur_key === 'organizacja' ? '' : ' hidden' ?>>Nazwa podmiotu</span>
              <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <input type="text" name="imie_nazwisko" id="imie_nazwisko"
                   class="form-control"
                   value="<?= h($row['imie_nazwisko'] ?? '') ?>"
                   required
                   aria-required="true"
                   data-ph-osoba="np. Jan Kowalski"
                   data-ph-organizacja="np. Fundacja Edukacji XYZ"
                   placeholder="<?= $cur_key === 'organizacja' ? 'np. Fundacja Edukacji XYZ' : 'np. Jan Kowalski' ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" for="type">Typ kontaktu</label>
            <?php /* Wszystkie typy ze słownika. Wcześniej były tylko dwa, więc edycja
                     kontrahenta albo partnera po cichu przestawiała go na „osobę”. */ ?>
            <select name="type" id="type" class="form-select" aria-label="Typ kontaktu">
              <?php foreach (CRM_CONTACT_TYPES as $tk => $tv): ?>
              <option value="<?= h($tk) ?>" <?= $cur_type === $tk ? 'selected' : '' ?>><?= h($tv['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" style="font-size:.77rem">Przełącza pola widoczne w formularzu.</div>
          </div>
        </div>

        <div class="row g-3 mt-0">
          <?php if ($_sfe['email']['vis']): ?>
          <div class="col-sm-6">
            <label class="form-label" for="email">
              Adres e-mail
              <?php if (!$_sfe['email']['edit']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
            </label>
            <input type="email" name="email" id="email"
                   class="form-control<?= !$_sfe['email']['edit'] ? ' bg-light text-muted' : '' ?>"
                   value="<?= h($row['email'] ?? '') ?>"
                   placeholder="email@domena.pl"
                   autocomplete="email"
                   <?= !$_sfe['email']['edit'] ? 'readonly disabled' : '' ?>>
          </div>
          <?php endif; ?>
          <?php if ($_sfe['telefon']['vis']): ?>
          <div class="col-sm-6">
            <label class="form-label" for="telefon">
              Numer telefonu
              <?php if (!$_sfe['telefon']['edit']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
            </label>
            <input type="tel" name="telefon" id="telefon"
                   class="form-control phone-48<?= !$_sfe['telefon']['edit'] ? ' bg-light text-muted' : '' ?>"
                   value="<?= h($row['telefon'] ?? '') ?>"
                   placeholder="123 456 789"
                   autocomplete="tel"
                   <?= !$_sfe['telefon']['edit'] ? 'readonly disabled' : '' ?>>
          </div>
          <?php endif; ?>
        </div>

        <div class="row g-3 mt-0">
          <?php if ($_sfe['stanowisko']['vis']): ?>
          <div class="col-sm-6">
            <label class="form-label" for="stanowisko">
              Stanowisko / Rola
              <?php if (!$_sfe['stanowisko']['edit']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
            </label>
            <input type="text" name="stanowisko" id="stanowisko"
                   class="form-control<?= !$_sfe['stanowisko']['edit'] ? ' bg-light text-muted' : '' ?>"
                   value="<?= h($row['stanowisko'] ?? '') ?>"
                   placeholder="np. Prezes, Wolontariusz"
                   <?= !$_sfe['stanowisko']['edit'] ? 'readonly disabled' : '' ?>>
          </div>
          <?php endif; ?>
          <?php if ($_sfe['organizacja']['vis']): ?>
          <?php /* Dla podmiotu nazwa organizacji jest w polu głównym — to pole to
                   PRACODAWCA osoby, więc przy typie organizacji nie ma sensu. */ ?>
          <div class="col-sm-6" data-crm-only="osoba"<?= $cur_key === 'osoba' ? '' : ' hidden' ?>>
            <label class="form-label" for="organizacja">
              Firma / Organizacja
              <?php if (!$_sfe['organizacja']['edit']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
            </label>
            <input type="text" name="organizacja" id="organizacja"
                   class="form-control<?= !$_sfe['organizacja']['edit'] ? ' bg-light text-muted' : '' ?>"
                   value="<?= h($row['organizacja'] ?? '') ?>"
                   placeholder="Nazwa firmy lub org."
                   <?= !$_sfe['organizacja']['edit'] ? 'readonly disabled' : '' ?>>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($_sfe['adres']['vis']): ?>
        <div class="mt-3">
          <?php echo address_widget($row, ['label'=>'Adres korespondencyjny', 'autocomplete'=>true, 'readonly' => !$_sfe['adres']['edit']]); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Dane firmowe (NIP/KRS) — tylko podmioty -->
    <?php if ($_sfe['nip']['vis'] || $_sfe['krs']['vis']): ?>
    <div class="card border-0 shadow-sm mt-3" data-crm-only="organizacja"<?= $cur_key === 'organizacja' ? '' : ' hidden' ?>>
      <div class="card-body">
        <div class="crm-section-title">Dane rejestrowe (opcjonalnie)</div>
        <div class="row g-3">
          <?php if ($_sfe['nip']['vis']): ?>
          <div class="col-sm-6">
            <label class="form-label" for="nip">
              NIP
              <?php if (!$_sfe['nip']['edit']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
            </label>
            <input type="text" name="nip" id="nip"
                   class="form-control font-monospace<?= !$_sfe['nip']['edit'] ? ' bg-light text-muted' : '' ?>"
                   value="<?= h($row['nip'] ?? '') ?>"
                   placeholder="0000000000"
                   maxlength="13"
                   pattern="[\d\-]{9,13}"
                   inputmode="numeric"
                   <?= !$_sfe['nip']['edit'] ? 'readonly disabled' : '' ?>>
          </div>
          <?php endif; ?>
          <?php if ($_sfe['krs']['vis']): ?>
          <div class="col-sm-6">
            <label class="form-label" for="krs">
              KRS
              <?php if (!$_sfe['krs']['edit']): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
            </label>
            <input type="text" name="krs" id="krs"
                   class="form-control font-monospace<?= !$_sfe['krs']['edit'] ? ' bg-light text-muted' : '' ?>"
                   value="<?= h($row['krs'] ?? '') ?>"
                   placeholder="0000000000"
                   maxlength="10"
                   pattern="\d{10}"
                   inputmode="numeric"
                   <?= !$_sfe['krs']['edit'] ? 'readonly disabled' : '' ?>>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Notatka wstępna -->
    <?php if ($_sfe['notatka']['vis']): ?>
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title">Notatka</div>
        <label class="form-label visually-hidden" for="notatka">Notatka</label>
        <textarea name="notatka" id="notatka"
                  class="form-control<?= !$_sfe['notatka']['edit'] ? ' bg-light text-muted' : '' ?>"
                  rows="4"
                  placeholder="Opcjonalna notatka o kontakcie…"
                  aria-label="Notatka o kontakcie"
                  <?= !$_sfe['notatka']['edit'] ? 'readonly disabled' : '' ?>><?= h($row['notatka'] ?? '') ?></textarea>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // Renderujemy definicje OBU typów. Te nietrafione w bieżący typ idą z atrybutem
  // hidden i wyłączonymi polami — przełącznik typu odsłania je bez przeładowania,
  // a zapis i tak filtruje po stronie serwera (crm_post_custom_fields).
  $visible_defs = $_field_defs;
  if ($visible_defs):
  ?>
  <!-- Dodatkowe pola -->
  <div class="col-12" id="cf-section">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="crm-section-title">Dodatkowe informacje</div>
        <div class="row g-3">
          <?php foreach ($visible_defs as $fd):
            $fid      = (int)$fd['id'];
            $fname    = "custom_fields[$fid]";
            $can_edit = crm_field_editable($fd);
            // Wartość: z POST przy błędzie, z bazy w edycji, pusta dla nowych
            $fval  = $_POST['custom_fields'][$fid]
                     ?? $_field_values[$fid]
                     ?? '';
            $col   = in_array($fd['field_type'], ['textarea']) ? 'col-12' : 'col-sm-6';
            $ro    = $can_edit ? '' : ' readonly disabled';
            $ro_cls= $can_edit ? '' : ' bg-light text-muted';
          ?>
          <?php
            $f_on  = crm_field_applies_to_type($fd, $cur_type);
            $f_off = $f_on ? '' : ' disabled';   // ukryte pole nie ma trafiać do POST-a
          ?>
          <div class="<?= $col ?>" data-crm-only="<?= h($fd['applies_to']) ?>"<?= $f_on ? '' : ' hidden' ?>>
            <label class="form-label" for="cf_<?= $fid ?>">
              <?= h($fd['label']) ?>
              <?php if (!$can_edit): ?>
              <span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Brak uprawnień do edycji">
                <i class="bi bi-lock-fill"></i>
              </span>
              <?php endif; ?>
            </label>
            <?php if ($fd['field_type'] === 'textarea'): ?>
              <textarea class="form-control<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>" rows="3"<?= $ro ?><?= $f_off ?>><?= h($fval) ?></textarea>
            <?php elseif ($fd['field_type'] === 'select'):
              $opts = json_decode($fd['options'], true) ?: [];
            ?>
              <select class="form-select<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>"<?= $can_edit ? '' : ' disabled' ?><?= $f_off ?>>
                <option value="">— wybierz —</option>
                <?php foreach ($opts as $opt): ?>
                <option value="<?= h($opt) ?>" <?= $fval === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($fd['field_type'] === 'checkbox'): ?>
              <div class="form-check mt-1">
                <?php /* Ukryty bliźniak — bez niego odznaczenie pola nigdy nie
                         docierałoby do zapisu (przeglądarka nie wysyła pustych checkboxów). */ ?>
                <input type="hidden" name="<?= $fname ?>" value=""<?= $can_edit ? '' : ' disabled' ?><?= $f_off ?>>
                <input class="form-check-input" type="checkbox" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="1"
                       <?= $fval ? 'checked' : '' ?><?= $can_edit ? '' : ' disabled' ?><?= $f_off ?>>
                <label class="form-check-label" for="cf_<?= $fid ?>">Tak</label>
              </div>
            <?php elseif ($fd['field_type'] === 'date'): ?>
              <input type="date" class="form-control<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>"<?= $ro ?><?= $f_off ?>>
            <?php elseif ($fd['field_type'] === 'number'): ?>
              <input type="number" class="form-control<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>"<?= $ro ?><?= $f_off ?>>
            <?php elseif ($fd['field_type'] === 'url'): ?>
              <input type="url" class="form-control<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>" placeholder="https://"<?= $ro ?><?= $f_off ?>>
            <?php elseif ($fd['field_type'] === 'email'): ?>
              <input type="email" class="form-control<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>"<?= $ro ?><?= $f_off ?>>
            <?php else: ?>
              <input type="text" class="form-control<?= $ro_cls ?>" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>"<?= $ro ?><?= $f_off ?>>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Prawa: status + akcje -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Status i widoczność</div>
        <label class="form-label" for="status">Status kontaktu</label>
        <select name="status" id="status" class="form-select" aria-label="Status kontaktu">
          <?php foreach (crm_statuses() as $sk => $sv): ?>
          <option value="<?= h($sk) ?>" <?= ($row['status'] ?? 'prospect') === $sk ? 'selected' : '' ?>>
            <?= h($sv['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text mt-1" style="font-size:.77rem">
          Status określa etap cyklu życia kontaktu.
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-crm-primary">
            <i class="bi bi-check-lg me-1"></i>
            <?= $is_edit ? 'Zapisz zmiany' : 'Utwórz kontakt' ?>
          </button>
          <a href="<?= $is_edit ? APP_URL . '/crm/contact/view.php?id=' . $edit_id : APP_URL . '/crm/index.php' ?>"
             class="btn btn-outline-secondary">
            Anuluj
          </a>
        </div>
      </div>
    </div>
  </div>

</div><!-- /row -->
</form>

<style>
/* Bootstrap ustawia .card { display:flex }, co bije domyślne [hidden] przeglądarki.
   Bez tej reguły karta „Dane rejestrowe” nie chowałaby się przy typie „osoba”. */
[data-crm-only][hidden], [data-crm-label][hidden], #cf-section[hidden] { display:none !important }
</style>
<script>
/**
 * Pola zależne od typu kontaktu.
 *
 * Formularz zawiera pola OBU zestawów; przełącznik „Typ kontaktu” odsłania
 * właściwy bez przeładowania strony. Ukryte pola są dodatkowo `disabled`, żeby
 * nie trafiały do POST-a — dzięki temu przestawienie typu nie kasuje wartości
 * drugiego zestawu (serwer ich po prostu nie dostaje i zostawia w bazie).
 */
(function() {
    'use strict';
    var sel = document.getElementById('type');
    if (!sel) return;
    var KEYS = <?= json_encode($_type_keys, JSON_UNESCAPED_UNICODE) ?>;

    function apply() {
        var key = KEYS[sel.value] || 'osoba';

        document.querySelectorAll('[data-crm-only]').forEach(function(el) {
            var on = el.dataset.crmOnly === 'both' || el.dataset.crmOnly === key;
            el.hidden = !on;
            el.querySelectorAll('input, select, textarea').forEach(function(f) {
                // Pole zablokowane uprawnieniami jest już `disabled`, więc nie dostanie
                // znacznika typeOff i nie zostanie odblokowane przy powrocie typu.
                if (!on && !f.disabled) { f.dataset.typeOff = '1'; f.disabled = true; }
                else if (on && f.dataset.typeOff === '1') { delete f.dataset.typeOff; f.disabled = false; }
            });
        });

        document.querySelectorAll('[data-crm-label]').forEach(function(el) {
            el.hidden = el.dataset.crmLabel !== key;
        });

        var name = document.getElementById('imie_nazwisko');
        if (name && name.dataset['ph' + key.charAt(0).toUpperCase() + key.slice(1)]) {
            name.placeholder = name.dataset['ph' + key.charAt(0).toUpperCase() + key.slice(1)];
        }

        // Sekcja pól dodatkowych znika, gdy dla tego typu nie ma czego pokazać.
        var sec = document.getElementById('cf-section');
        if (sec) {
            var any = Array.prototype.some.call(
                sec.querySelectorAll('[data-crm-only]'), function(el) { return !el.hidden; });
            sec.hidden = !any;
        }
    }

    sel.addEventListener('change', apply);
    apply();
})();
</script>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
