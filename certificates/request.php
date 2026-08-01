<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();

$type = $_GET['type'] ?? $_POST['type'] ?? '';
$id   = intval($_GET['id'] ?? $_POST['id'] ?? 0);

if (!array_key_exists($type, CONTRACT_TYPES) || !$id) {
    http_response_code(400); die('Nieprawidłowe parametry.');
}

$TABLE = table_for_type($type);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }

$user     = current_user();
$has_pending = !empty(array_filter(
    get_certificate_requests($type, $id),
    fn($r) => $r['status'] === 'oczekuje'
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($has_pending) {
        flash_set('warning', 'Istnieje już oczekujący wniosek o zaświadczenie dla tej umowy.');
        header('Location: ' . contract_url($type, $id));
        exit;
    }

    $name      = trim($_POST['requester_name'] ?? '');
    $email     = trim($_POST['requester_email'] ?? '');
    $cel       = trim($_POST['cel'] ?? '');
    $cert_type = $_POST['certificate_type'] ?? '';
    if (!array_key_exists($cert_type, CERTIFICATE_TYPES)) $cert_type = $default_cert_type;

    $errors = [];
    if (!$name)  $errors[] = 'Podaj imię i nazwisko wnioskodawcy.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj poprawny adres e-mail.';
    if (!$cel)   $errors[] = 'Podaj cel wydania zaświadczenia.';

    if (!$errors) {
        $req_id = create_certificate_request($type, $id, $user['id'], $name, $email, $cel, $cert_type);

        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $id, $user['id'], 'certificate_request',
            'Złożono wniosek o zaświadczenie dla: ' . $name);

        $new_req = ['requester_name' => $name, 'requester_email' => $email, 'cel' => $cel];
        _certificate_notify_admins($type, $row, $new_req);

        flash_set('success', 'Wniosek o zaświadczenie został złożony. Administrator otrzymał powiadomienie.');
        header('Location: ' . contract_url($type, $id));
        exit;
    }
}

$person_name = get_contract_person_name($type, $row);

// Domyślny typ zaświadczenia pasujący do tej umowy
$default_cert_type = cert_default_type_for_contract($type);
$sel_cert_type     = $_POST['certificate_type'] ?? $default_cert_type;
if (!array_key_exists($sel_cert_type, CERTIFICATE_TYPES)) $sel_cert_type = $default_cert_type;

$PAGE_TITLE = 'Wniosek o zaświadczenie';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-award text-primary"></i> Wniosek o zaświadczenie</h4>
  <a href="<?= h(contract_url($type, $id)) ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Wróć do umowy
  </a>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<?php if ($has_pending): ?>
<div class="alert alert-warning">
  <i class="bi bi-clock"></i> Istnieje już oczekujący wniosek o zaświadczenie dla tej umowy. Poczekaj na decyzję administratora.
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Umowa</div>
  <div class="card-body text-muted small">
    <?= h(CONTRACT_TYPES[$type]) ?> · <strong><?= h($row['numer_umowy']) ?></strong>
    <?php if ($person_name): ?> · <?= h($person_name) ?><?php endif; ?>
    · <?= date_pl($row['data_zawarcia']) ?>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header fw-semibold">Dane wniosku</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <input type="hidden" name="id"   value="<?= $id ?>">

      <!-- Rodzaj zaświadczenia -->
      <div class="mb-4">
        <label class="form-label fw-semibold">Rodzaj zaświadczenia <span class="text-danger">*</span></label>
        <div class="d-flex gap-2 flex-wrap" id="cert-type-cards">
          <?php foreach (CERTIFICATE_TYPES as $ct_key => $ct): ?>
          <?php $is_native = in_array($type, $ct['for_types'], true); ?>
          <div class="cert-type-card <?= $sel_cert_type === $ct_key ? 'selected' : '' ?>"
               style="border:2px solid <?= $sel_cert_type === $ct_key ? '#0d6efd' : '#dee2e6' ?>;
                      border-radius:.5rem;padding:.65rem .9rem;cursor:pointer;
                      min-width:170px;flex:1;transition:border-color .15s"
               onclick="selectCertType('<?= $ct_key ?>')">
            <input class="form-check-input visually-hidden" type="radio" name="certificate_type"
                   id="ct_<?= $ct_key ?>" value="<?= $ct_key ?>"
                   <?= $sel_cert_type === $ct_key ? 'checked' : '' ?>
                   <?= $has_pending ? 'disabled' : '' ?>>
            <label class="form-check-label w-100" for="ct_<?= $ct_key ?>" style="cursor:pointer">
              <div class="fw-semibold small"><?= h($ct['label']) ?></div>
              <div class="text-muted" style="font-size:.75rem;margin-top:.2rem"><?= h($ct['desc']) ?></div>
              <?php if (!$is_native): ?>
              <span class="badge bg-warning text-dark mt-1" style="font-size:.65rem">
                <i class="bi bi-info-circle"></i> Rzadko stosowane przy tym typie umowy
              </span>
              <?php endif; ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Imię i nazwisko wnioskodawcy <span class="text-danger">*</span></label>
        <input type="text" name="requester_name" class="form-control"
               value="<?= h($_POST['requester_name'] ?? $person_name) ?>" required
               <?= $has_pending ? 'disabled' : '' ?>>
      </div>

      <div class="mb-3">
        <label class="form-label">Adres e-mail do wysyłki zaświadczenia <span class="text-danger">*</span></label>
        <input type="email" name="requester_email" class="form-control"
               value="<?= h($_POST['requester_email'] ?? '') ?>" required
               <?= $has_pending ? 'disabled' : '' ?>>
        <div class="form-text">Na ten adres zostanie wysłane gotowe zaświadczenie.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Cel wydania zaświadczenia <span class="text-danger">*</span></label>
        <textarea name="cel" class="form-control" rows="3" required
                  placeholder="np. do urzędu skarbowego, na potrzeby banku, do ZUS..."
                  <?= $has_pending ? 'disabled' : '' ?>><?= h($_POST['cel'] ?? '') ?></textarea>
      </div>

      <?php if (!$has_pending): ?>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-send"></i> Złóż wniosek o zaświadczenie
        </button>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

</div>
</div>

<script>
function selectCertType(key) {
    document.querySelectorAll('.cert-type-card').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        const active = radio.value === key;
        radio.checked = active;
        card.style.borderColor = active ? '#0d6efd' : '#dee2e6';
        card.classList.toggle('selected', active);
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
