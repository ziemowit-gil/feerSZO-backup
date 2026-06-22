<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

if (!can_write('karty30') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do zapisu.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Nowy beneficjent — Karty 30';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $dob      = trim($_POST['date_of_birth'] ?? '');
    $gender   = $_POST['gender'] ?? '';
    $status   = $_POST['status'] ?? 'enrolled';
    $problem  = trim($_POST['problem'] ?? '');
    $equipment= trim($_POST['equipment'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');
    $consent  = isset($_POST['consent']) ? 1 : 0;
    $avail_h  = (float)($_POST['available_hours'] ?? 0);
    $pcm      = $_POST['preferred_contact_method'] ?? 'email';

    if ($name === '') $errors[] = 'Imię i nazwisko jest wymagane.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres email.';
    if (!array_key_exists($status, K30_CLIENT_STATUSES)) $status = 'enrolled';

    if (!$errors) {
        $id = db_insert('k30_clients', [
            'name'                    => $name,
            'email'                   => $email ?: null,
            'phone'                   => $phone ?: null,
            'date_of_birth'           => $dob ?: null,
            'gender'                  => $gender ?: null,
            'status'                  => $status,
            'problem'                 => $problem ?: null,
            'equipment'               => $equipment ?: null,
            'address'                 => $address ?: null,
            'notes'                   => $notes ?: null,
            'consent'                 => $consent,
            'available_hours'         => $avail_h,
            'preferred_contact_method'=> $pcm,
            'created_by'              => current_user()['id'] ?? null,
        ]);
        // Sync do CRM — kontakt + grupa "Beneficjenci — Konsultacje Tyflo"
        $uid = (int)(current_user()['id'] ?? 0);
        k30_sync_to_crm([
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'address' => $address,
        ], $uid);

        flash_set('success', 'Beneficjent został dodany i zsynchronizowany z CRM.');
        header('Location: view.php?id=' . $id);
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka</a></li>
    <li class="breadcrumb-item"><a href="index.php">Beneficjenci</a></li>
    <li class="breadcrumb-item active" aria-current="page">Nowy beneficjent</li>
  </ol>
</nav>

<div class="k30-page-header">
  <div>
    <h1 class="k30-page-title">Nowy beneficjent</h1>
    <p class="k30-page-subtitle">Wypełnij dane osobowe beneficjenta. Pola oznaczone gwiazdką są wymagane.</p>
  </div>
</div>

<?php if ($errors): ?>
<div class="k30-alert k30-alert-danger" role="alert" aria-label="Błędy formularza">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0"></i>
  <div>
    <strong>Popraw następujące błędy:</strong>
    <ul class="mb-0 mt-1">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<form method="post" novalidate aria-label="Formularz nowego beneficjenta" style="max-width:720px">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <!-- Dane identyfikacyjne -->
  <fieldset class="mb-4">
    <legend class="fw-bold mb-3" style="font-size:1rem;border-bottom:2px solid #E5E7EB;padding-bottom:.5rem">
      Dane osobowe
    </legend>

    <div class="mb-3">
      <label class="form-label" for="f_name">
        Imię i nazwisko <span class="req" aria-hidden="true">*</span>
      </label>
      <input type="text" name="name" id="f_name"
             class="form-control"
             value="<?= h($_POST['name'] ?? '') ?>"
             required
             aria-required="true"
             <?= in_array('Imię i nazwisko jest wymagane.', $errors) ? 'aria-invalid="true" aria-describedby="err_name"' : '' ?>
             autocomplete="name"
             autofocus>
      <?php if (in_array('Imię i nazwisko jest wymagane.', $errors)): ?>
      <div id="err_name" class="form-error" role="alert">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>Imię i nazwisko jest wymagane.
      </div>
      <?php endif; ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="f_email">Adres e-mail</label>
        <input type="email" name="email" id="f_email"
               class="form-control"
               value="<?= h($_POST['email'] ?? '') ?>"
               autocomplete="email"
               aria-describedby="hint_email">
        <div id="hint_email" class="form-hint">Używany do kontaktu i logowania do portalu.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="f_phone">Numer telefonu</label>
        <input type="tel" name="phone" id="f_phone"
               class="form-control"
               value="<?= h($_POST['phone'] ?? '') ?>"
               autocomplete="tel"
               aria-describedby="hint_phone">
        <div id="hint_phone" class="form-hint">Format: +48 123 456 789</div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="f_dob">Data urodzenia</label>
        <input type="date" name="date_of_birth" id="f_dob"
               class="form-control"
               value="<?= h($_POST['date_of_birth'] ?? '') ?>"
               autocomplete="bday">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="f_gender">Płeć</label>
        <select name="gender" id="f_gender" class="form-select">
          <option value="">— Nie podano —</option>
          <option value="male"   <?= (($_POST['gender'] ?? '') === 'male')   ? 'selected' : '' ?>>Mężczyzna</option>
          <option value="female" <?= (($_POST['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Kobieta</option>
          <option value="other"  <?= (($_POST['gender'] ?? '') === 'other')  ? 'selected' : '' ?>>Inne / nie podano</option>
        </select>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="f_address">Adres zamieszkania</label>
      <input type="text" name="address" id="f_address"
             class="form-control"
             value="<?= h($_POST['address'] ?? '') ?>"
             autocomplete="street-address">
    </div>
  </fieldset>

  <!-- Szczegóły programu -->
  <fieldset class="mb-4">
    <legend class="fw-bold mb-3" style="font-size:1rem;border-bottom:2px solid #E5E7EB;padding-bottom:.5rem">
      Szczegóły uczestnictwa
    </legend>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="f_status">Status</label>
        <select name="status" id="f_status" class="form-select">
          <?php foreach (K30_CLIENT_STATUSES as $sk => $sv): ?>
          <option value="<?= h($sk) ?>" <?= (($_POST['status'] ?? 'enrolled') === $sk) ? 'selected' : '' ?>>
            <?= h($sv['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="f_hours">Dostępne godziny</label>
        <input type="number" name="available_hours" id="f_hours"
               class="form-control"
               value="<?= h($_POST['available_hours'] ?? '0') ?>"
               step="0.5" min="0"
               aria-describedby="hint_hours">
        <div id="hint_hours" class="form-hint">Łączna liczba godzin konsultacji przyznanych beneficjentowi.</div>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="f_pcm">Preferowany sposób kontaktu</label>
      <select name="preferred_contact_method" id="f_pcm" class="form-select">
        <option value="email" <?= (($_POST['preferred_contact_method'] ?? 'email') === 'email') ? 'selected' : '' ?>>E-mail</option>
        <option value="phone" <?= (($_POST['preferred_contact_method'] ?? '') === 'phone') ? 'selected' : '' ?>>Telefon głosowy</option>
        <option value="sms"   <?= (($_POST['preferred_contact_method'] ?? '') === 'sms')   ? 'selected' : '' ?>>SMS</option>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label" for="f_problem">Opis potrzeby / problem</label>
      <textarea name="problem" id="f_problem"
                class="form-control" rows="3"
                aria-describedby="hint_problem"
                placeholder="Opisz główny obszar wsparcia potrzebny beneficjentowi…"><?= h($_POST['problem'] ?? '') ?></textarea>
      <div id="hint_problem" class="form-hint">Informacja widoczna tylko dla pracowników. Nie jest udostępniana beneficjentowi.</div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="f_equipment">Sprzęt / wyposażenie</label>
      <input type="text" name="equipment" id="f_equipment"
             class="form-control"
             value="<?= h($_POST['equipment'] ?? '') ?>"
             placeholder="np. czytnik ekranu NVDA, lupa, brajlowska…"
             aria-describedby="hint_equipment">
      <div id="hint_equipment" class="form-hint">Sprzęt pomocniczy z którego korzysta beneficjent.</div>
    </div>

    <div class="mb-4">
      <label class="form-label" for="f_notes">Notatki wewnętrzne</label>
      <textarea name="notes" id="f_notes"
                class="form-control" rows="2"
                placeholder="Dodatkowe informacje dla konsultantów…"><?= h($_POST['notes'] ?? '') ?></textarea>
    </div>
  </fieldset>

  <!-- Zgoda RODO -->
  <fieldset class="mb-4">
    <legend class="fw-bold mb-2" style="font-size:1rem">Zgoda na przetwarzanie danych</legend>
    <div class="form-check">
      <input class="form-check-input" type="checkbox"
             name="consent" id="f_consent" value="1"
             <?= !empty($_POST['consent']) ? 'checked' : '' ?>
             aria-describedby="hint_consent">
      <label class="form-check-label" for="f_consent">
        Beneficjent wyraził zgodę na przetwarzanie danych osobowych zgodnie z RODO
      </label>
      <div id="hint_consent" class="form-hint">Zaznacz jeśli beneficjent podpisał formularz zgody.</div>
    </div>
  </fieldset>

  <!-- Przyciski -->
  <div class="d-flex gap-3 flex-wrap">
    <button type="submit" class="btn btn-k30">
      <i class="bi bi-check-lg me-2" aria-hidden="true"></i>Zapisz beneficjenta
    </button>
    <a href="index.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Anuluj i wróć do listy
    </a>
  </div>
</form>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
