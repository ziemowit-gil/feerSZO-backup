<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

require_login();
$PAGE_TITLE = 'Zmiana hasła';
$user = current_user();
$force_change = isset($_GET['force']) || auth_must_change_password($user);

// Sprawdź czy użytkownik ma hasło lokalne (nie tylko M365)
$db_user = db_one("SELECT password, microsoft_id, phone_number FROM users WHERE id = ?", [$user['id']]);
$has_local_password = !empty($db_user['password']);

$errors = [];
$success = false;
$phone_errors = [];
$phone_success = false;

// ── Numer telefonu konta (osobny formularz na tej samej stronie) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone_number'])) {
    csrf_check();

    $phone_new = preg_replace('/[^\d+]/', '', trim($_POST['phone_number']));
    if ($phone_new !== '' && strlen($phone_new) < 9) {
        $phone_errors[] = 'Numer telefonu jest za krótki (min. 9 cyfr).';
    }

    if (!$phone_errors) {
        db()->prepare("UPDATE users SET phone_number=? WHERE id=?")->execute([$phone_new, $user['id']]);
        $db_user['phone_number'] = $phone_new;
        authlog_write((int)$user['id'], 'phone_changed', $user['email'], 'Zmiana numeru telefonu konta');
        $phone_success = true;
        flash_set('success', 'Numer telefonu został zapisany.');
        header('Location: ' . APP_URL . '/panel/password.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['phone_number'])) {
    csrf_check();

    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Przy wymuszonej zmianie nie wymagamy aktualnego hasła
    if ($has_local_password && !$force_change && !password_verify($current, $db_user['password'])) {
        $errors[] = 'Aktualne hasło jest nieprawidłowe.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'Nowe hasło musi mieć co najmniej 8 znaków.';
    }
    if (!preg_match('/[A-Z]/', $new)) {
        $errors[] = 'Hasło musi zawierać co najmniej jedną wielką literę.';
    }
    if (!preg_match('/[0-9]/', $new)) {
        $errors[] = 'Hasło musi zawierać co najmniej jedną cyfrę.';
    }
    if ($new !== $confirm) {
        $errors[] = 'Hasła nie są identyczne.';
    }

    if (!$errors) {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        db()->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?")->execute([$hash, $user['id']]);
        auth_clear_force_password((int)$user['id']);
        authlog_write((int)$user['id'], 'pwd_changed', $user['email'], 'Zmiana hasła');
        $success = true;
        $has_local_password = true;
        $force_change = false;
        flash_set('success', 'Hasło zostało zmienione.');
        header('Location: ' . APP_URL . '/panel/index.php'); exit;
    }
}

// Formularz zmiany hasła jest domyślnie zwinięty za przyciskiem — rozwinięty
// automatycznie, gdy zmiana jest wymuszona, konto nie ma jeszcze hasła
// lokalnego, albo poprzednia próba zakończyła się błędem (żeby nie chować
// komunikatu o błędzie razem z formularzem).
$show_pwd_form = $force_change || !$has_local_password || !empty($errors);

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-key me-2" aria-hidden="true"></i>Zmiana hasła</h1>
  <p class="pv-page-sub">Aktualizuj swoje hasło dostępu</p>
</div>

<?= flash_html() ?>

<?php if ($force_change): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Wymagana zmiana hasła</strong><br>
    Administrator zresetował Twoje hasło. Musisz ustawić nowe hasło przed dalszym korzystaniem z systemu.
  </div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="vol-detail-card">
  <div class="vol-detail-body">
    <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-check-circle-fill fs-5"></i>
      <div>Hasło zostało zmienione. Możesz je teraz używać przy kolejnym logowaniu.</div>
    </div>
    <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-primary">
      <i class="bi bi-arrow-left"></i> Wróć do panelu
    </a>
  </div>
</div>
<?php else: ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0">
  <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-key me-2" aria-hidden="true"></i>Hasło</div>
  <div class="vol-detail-body">
    <button type="button" id="pwd-toggle-btn"
            class="btn btn-outline-secondary"
            style="<?= $show_pwd_form ? 'display:none' : '' ?>"
            onclick="document.getElementById('pwd-form-wrap').style.display='block'; this.style.display='none';">
      <i class="bi bi-key me-1" aria-hidden="true"></i> Zmień hasło
    </button>
    <div id="pwd-form-wrap" style="<?= $show_pwd_form ? '' : 'display:none' ?>">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <?php if ($has_local_password): ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">Aktualne hasło</label>
        <input type="password" name="current_password" class="form-control" required autofocus>
      </div>
      <?php else: ?>
      <div class="alert alert-info small mb-3">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Twoje konto nie ma jeszcze hasła lokalnego. Ustaw je poniżej.
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label fw-semibold">Nowe hasło</label>
        <input type="password" name="new_password" class="form-control"
               minlength="8" required <?= !$has_local_password ? 'autofocus' : '' ?>>
        <div class="form-text">Minimum 8 znaków, co najmniej jedna wielka litera i cyfra.</div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Powtórz nowe hasło</label>
        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" style="background:var(--vol-color);color:#fff;border:none;border-radius:8px;padding:.55rem 1.25rem;font-weight:600">
          <i class="bi bi-key me-1" aria-hidden="true"></i> Zmień hasło
        </button>
        <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-phone me-2" aria-hidden="true"></i>Numer telefonu</div>
  <div class="vol-detail-body">
    <?php if ($phone_errors): ?>
    <div class="alert alert-danger"><ul class="mb-0">
      <?php foreach ($phone_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="phone_number">Numer telefonu</label>
        <input type="tel" id="phone_number" name="phone_number" class="form-control"
               value="<?= h($db_user['phone_number'] ?? '') ?>" placeholder="np. 600123456">
        <div class="form-text">Używany m.in. do powiadomień SMS i kontaktu w sprawach dotyczących Twojego konta.</div>
      </div>
      <button type="submit" style="background:var(--vol-color);color:#fff;border:none;border-radius:8px;padding:.55rem 1.25rem;font-weight:600">
        <i class="bi bi-check2 me-1" aria-hidden="true"></i> Zapisz numer
      </button>
    </form>
  </div>
</div>

<div class="vol-detail-card">
  <div class="vol-detail-header"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Wskazówki bezpieczeństwa</div>
  <div class="vol-detail-body small text-muted">
    <ul class="mb-0 ps-3">
      <li class="mb-1">Hasło powinno mieć minimum 8 znaków, w tym wielką literę i cyfrę.</li>
      <li class="mb-1">Nie używaj tego samego hasła w różnych serwisach.</li>
      <li class="mb-1">Rozważ włączenie <strong>weryfikacji dwuetapowej (2FA)</strong> dla dodatkowej ochrony.</li>
      <li>Nie udostępniaj hasła nikomu, w tym pracownikom organizacji.</li>
    </ul>
  </div>
</div>

<?php endif; ?>

<?php else: /* !$_is_volunteer_only — admin/editor layout */ ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-key text-warning fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Zmiana hasła</h4>
    <div class="text-muted small">Konto: <?= h($user['email']) ?></div>
  </div>
</div>

<?= flash_html() ?>

<?php if ($force_change): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Wymagana zmiana hasła</strong><br>
    Administrator zresetował Twoje hasło. Musisz ustawić nowe hasło przed dalszym korzystaniem z systemu.
  </div>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-md-6 col-lg-5">

<?php if ($success): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
  <i class="bi bi-check-circle-fill fs-5"></i>
  <div>Hasło zostało zmienione. Możesz je teraz używać przy kolejnym logowaniu.</div>
</div>
<a href="<?= APP_URL ?>/panel/index.php" class="btn btn-primary">
  <i class="bi bi-arrow-left"></i> Wróć do panelu
</a>
<?php else: ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0">
  <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body p-4">
    <button type="button" id="pwd-toggle-btn"
            class="btn btn-outline-warning"
            style="<?= $show_pwd_form ? 'display:none' : '' ?>"
            onclick="document.getElementById('pwd-form-wrap').style.display='block'; this.style.display='none';">
      <i class="bi bi-key me-1" aria-hidden="true"></i> Zmień hasło
    </button>
    <div id="pwd-form-wrap" style="<?= $show_pwd_form ? '' : 'display:none' ?>">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <?php if ($has_local_password): ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">Aktualne hasło</label>
        <input type="password" name="current_password" class="form-control" required autofocus>
      </div>
      <?php else: ?>
      <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        Twoje konto nie ma jeszcze hasła lokalnego. Ustaw je poniżej.
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label fw-semibold">Nowe hasło</label>
        <input type="password" name="new_password" class="form-control"
               minlength="8" required <?= !$has_local_password ? 'autofocus' : '' ?>>
        <div class="form-text">Minimum 8 znaków.</div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Powtórz nowe hasło</label>
        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-warning">
          <i class="bi bi-key"></i> Zmień hasło
        </button>
        <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
    </div>
  </div>
</div>

<?php endif; ?>

<div class="card shadow-sm mt-4">
  <div class="card-body p-4">
    <h6 class="fw-semibold mb-3"><i class="bi bi-phone me-2" aria-hidden="true"></i>Numer telefonu</h6>
    <?php if ($phone_errors): ?>
    <div class="alert alert-danger"><ul class="mb-0">
      <?php foreach ($phone_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="phone_number">Numer telefonu</label>
        <input type="tel" id="phone_number" name="phone_number" class="form-control"
               value="<?= h($db_user['phone_number'] ?? '') ?>" placeholder="np. 600123456">
        <div class="form-text">Używany m.in. do powiadomień SMS i kontaktu w sprawach dotyczących Twojego konta.</div>
      </div>
      <button type="submit" class="btn btn-outline-primary">
        <i class="bi bi-check2 me-1" aria-hidden="true"></i> Zapisz numer
      </button>
    </form>
  </div>
</div>

</div>
</div>

<?php endif; /* $_is_volunteer_only */ ?>

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
