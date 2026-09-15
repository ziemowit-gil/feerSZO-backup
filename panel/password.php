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
        flash_set('success', 'Hasło zostało zmienione. Możesz je teraz używać przy kolejnym logowaniu.');
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
require_once __DIR__ . '/includes/pv_ui.php';
?>

<div class="pv-wrap">

<?php pv_page_header('Zmiana hasła', [
    'icon' => 'bi-key',
    'sub'  => 'Hasło dostępu i numer telefonu konta: ' . $user['email'],
]); ?>

<?= flash_html() ?>

<?php if ($force_change): ?>
<div class="pv-note pv-note-warn" role="alert">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <div><strong style="color:var(--tz-ink)">Wymagana zmiana hasła.</strong> Administrator zresetował Twoje hasło — ustaw nowe przed dalszym korzystaniem z systemu.</div>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="pv-alert pv-alert-err mb-3" role="alert">
  <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
  <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-key" aria-hidden="true"></i>Hasło dostępu</div>
  <div class="tz-card__bd">
    <button type="button" id="pwd-toggle-btn"
            class="tz-btn tz-btn--ghost"
            style="<?= $show_pwd_form ? 'display:none' : '' ?>"
            onclick="document.getElementById('pwd-form-wrap').style.display='block'; this.style.display='none';">
      <i class="bi bi-key" aria-hidden="true"></i> Zmień hasło
    </button>
    <div id="pwd-form-wrap" style="<?= $show_pwd_form ? '' : 'display:none' ?>">
      <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <?php if ($has_local_password && !$force_change): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="current_password">Aktualne hasło</label>
          <input type="password" id="current_password" name="current_password" class="form-control" required autofocus autocomplete="current-password">
        </div>
        <?php else: ?>
        <div class="tz-note mb-3" role="note">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          Twoje konto nie ma jeszcze hasła lokalnego. Ustaw je poniżej.
        </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="new_password">Nowe hasło</label>
          <input type="password" id="new_password" name="new_password" class="form-control"
                 minlength="8" required <?= !$has_local_password ? 'autofocus' : '' ?> autocomplete="new-password">
          <div class="form-text">Minimum 8 znaków, co najmniej jedna wielka litera i cyfra.</div>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold" for="confirm_password">Powtórz nowe hasło</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" required autocomplete="new-password">
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" class="tz-btn"><i class="bi bi-key" aria-hidden="true"></i> Zmień hasło</button>
          <?php if (!$force_change): ?><a href="<?= APP_URL ?>/panel/index.php" class="tz-btn tz-btn--ghost">Anuluj</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-phone" aria-hidden="true"></i>Numer telefonu konta</div>
  <div class="tz-card__bd">
    <?php if ($phone_errors): ?>
    <div class="pv-alert pv-alert-err mb-3" role="alert">
      <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
      <ul class="mb-0 ps-3"><?php foreach ($phone_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <form method="post" novalidate>
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="phone_number">Numer telefonu</label>
        <input type="tel" id="phone_number" name="phone_number" class="form-control"
               value="<?= h($db_user['phone_number'] ?? '') ?>" placeholder="np. 600123456" autocomplete="tel">
        <div class="form-text">Używany do powiadomień SMS i kontaktu w sprawach dotyczących Twojego konta.</div>
      </div>
      <button type="submit" class="tz-btn"><i class="bi bi-check2" aria-hidden="true"></i> Zapisz numer</button>
    </form>
  </div>
</div>

<div class="tz-note">
  <i class="bi bi-shield-check" aria-hidden="true"></i>
  <div>
    <strong style="color:var(--tz-ink)">Wskazówki bezpieczeństwa</strong>
    <ul class="mb-0 ps-3 mt-1" style="font-size:.87rem">
      <li class="mb-1">Hasło powinno mieć minimum 8 znaków, w tym wielką literę i cyfrę.</li>
      <li class="mb-1">Nie używaj tego samego hasła w różnych serwisach.</li>
      <li class="mb-1">Rozważ włączenie <strong>weryfikacji dwuetapowej (2FA)</strong> dla dodatkowej ochrony.</li>
      <li>Nie udostępniaj hasła nikomu, w tym pracownikom organizacji.</li>
    </ul>
  </div>
</div>

</div><!-- /.pv-wrap -->

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
