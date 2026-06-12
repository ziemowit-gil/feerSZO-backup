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
            CrmManager::saveFieldValues($edit_id, (array)($_POST['custom_fields'] ?? []));
            flash_set('success', 'Kontakt zaktualizowany pomyślnie.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $edit_id);
        } else {
            $data['created_by'] = $user_id;
            $new_id = CrmManager::createContact($data);
            CrmManager::saveFieldValues($new_id, (array)($_POST['custom_fields'] ?? []));
            flash_set('success', 'Kontakt dodany pomyślnie.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $new_id);
        }
        exit;
    }

    // Zachowaj wartości po błędzie
    $row = array_merge($row, $_POST);
}

// Definicje pól dodatkowych (wczytane tu, bo potrzebne też przy render)
$_field_defs   = CrmManager::getFieldDefs();
$_field_values = $is_edit ? CrmManager::getFieldValues($edit_id) : [];

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
              Imię i nazwisko / Nazwa firmy <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <input type="text" name="imie_nazwisko" id="imie_nazwisko"
                   class="form-control"
                   value="<?= h($row['imie_nazwisko'] ?? '') ?>"
                   required
                   aria-required="true"
                   placeholder="np. Jan Kowalski lub Fundacja XYZ">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" for="type">Typ kontaktu</label>
            <select name="type" id="type" class="form-select" aria-label="Typ kontaktu">
              <option value="osoba"       <?= ($row['type'] ?? 'osoba') === 'osoba'       ? 'selected' : '' ?>>Osoba</option>
              <option value="organizacja" <?= ($row['type'] ?? '') === 'organizacja' ? 'selected' : '' ?>>Organizacja</option>
            </select>
          </div>
        </div>

        <div class="row g-3 mt-0">
          <div class="col-sm-6">
            <label class="form-label" for="email">Adres e-mail</label>
            <input type="email" name="email" id="email"
                   class="form-control"
                   value="<?= h($row['email'] ?? '') ?>"
                   placeholder="email@domena.pl"
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

        <div class="row g-3 mt-0">
          <div class="col-sm-6">
            <label class="form-label" for="stanowisko">Stanowisko / Rola</label>
            <input type="text" name="stanowisko" id="stanowisko"
                   class="form-control"
                   value="<?= h($row['stanowisko'] ?? '') ?>"
                   placeholder="np. Prezes, Wolontariusz">
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="organizacja">Firma / Organizacja</label>
            <input type="text" name="organizacja" id="organizacja"
                   class="form-control"
                   value="<?= h($row['organizacja'] ?? '') ?>"
                   placeholder="Nazwa firmy lub org.">
          </div>
        </div>

        <div class="mt-3">
          <?php echo address_widget($row, ['label'=>'Adres korespondencyjny', 'autocomplete'=>true]); ?>
        </div>
      </div>
    </div>

    <!-- Dane firmowe (NIP/KRS) -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title">Dane rejestrowe (opcjonalnie)</div>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="nip">NIP</label>
            <input type="text" name="nip" id="nip"
                   class="form-control font-monospace"
                   value="<?= h($row['nip'] ?? '') ?>"
                   placeholder="0000000000"
                   maxlength="13"
                   pattern="[\d\-]{9,13}"
                   inputmode="numeric">
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="krs">KRS</label>
            <input type="text" name="krs" id="krs"
                   class="form-control font-monospace"
                   value="<?= h($row['krs'] ?? '') ?>"
                   placeholder="0000000000"
                   maxlength="10"
                   pattern="\d{10}"
                   inputmode="numeric">
          </div>
        </div>
      </div>
    </div>

    <!-- Notatka wstępna -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title">Notatka</div>
        <label class="form-label visually-hidden" for="notatka">Notatka</label>
        <textarea name="notatka" id="notatka"
                  class="form-control"
                  rows="4"
                  placeholder="Opcjonalna notatka o kontakcie…"
                  aria-label="Notatka o kontakcie"><?= h($row['notatka'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <?php
  // Filtruj pola wg typu kontaktu (używamy wartości z formularza jeśli błąd, inaczej z bazy)
  $cur_type = $row['type'] ?? 'osoba';
  $visible_defs = array_filter($_field_defs, fn($d) =>
      $d['applies_to'] === 'both' || $d['applies_to'] === $cur_type
  );
  if ($visible_defs):
  ?>
  <!-- Dodatkowe pola -->
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="crm-section-title">Dodatkowe informacje</div>
        <div class="row g-3">
          <?php foreach ($visible_defs as $fd):
            $fid   = (int)$fd['id'];
            $fname = "custom_fields[$fid]";
            // Wartość: z POST przy błędzie, z bazy w edycji, pusta dla nowych
            $fval  = $_POST['custom_fields'][$fid]
                     ?? $_field_values[$fid]
                     ?? '';
            $col   = in_array($fd['field_type'], ['textarea']) ? 'col-12' : 'col-sm-6';
          ?>
          <div class="<?= $col ?>" data-applies="<?= h($fd['applies_to']) ?>">
            <label class="form-label" for="cf_<?= $fid ?>"><?= h($fd['label']) ?></label>
            <?php if ($fd['field_type'] === 'textarea'): ?>
              <textarea class="form-control" id="cf_<?= $fid ?>" name="<?= $fname ?>" rows="3"><?= h($fval) ?></textarea>
            <?php elseif ($fd['field_type'] === 'select'):
              $opts = json_decode($fd['options'], true) ?: [];
            ?>
              <select class="form-select" id="cf_<?= $fid ?>" name="<?= $fname ?>">
                <option value="">— wybierz —</option>
                <?php foreach ($opts as $opt): ?>
                <option value="<?= h($opt) ?>" <?= $fval === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($fd['field_type'] === 'checkbox'): ?>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="1" <?= $fval ? 'checked' : '' ?>>
                <label class="form-check-label" for="cf_<?= $fid ?>">Tak</label>
              </div>
            <?php elseif ($fd['field_type'] === 'date'): ?>
              <input type="date" class="form-control" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
            <?php elseif ($fd['field_type'] === 'number'): ?>
              <input type="number" class="form-control" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
            <?php elseif ($fd['field_type'] === 'url'): ?>
              <input type="url" class="form-control" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>" placeholder="https://">
            <?php elseif ($fd['field_type'] === 'email'): ?>
              <input type="email" class="form-control" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
            <?php else: ?>
              <input type="text" class="form-control" id="cf_<?= $fid ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
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

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
