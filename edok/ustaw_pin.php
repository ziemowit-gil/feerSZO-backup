<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$user = current_user();
$uid  = (int)$user['id'];
$has_pin = edok_pin_is_set($uid);

$db_user = db_one("SELECT password, microsoft_id FROM users WHERE id = ?", [$uid]);
// Konta logujące się przez Microsoft 365 (microsoft_id ustawiony) nie muszą
// potwierdzać hasła lokalnego, nawet jeśli takie hasło technicznie istnieje
// w bazie — użytkownik loguje się przez Office/M365, więc realnie go nie zna/
// nie używa. Bez microsoft_id — jak w panel/password.php: hasło wymagane
// tylko wtedy, gdy konto w ogóle je ma.
$skip_password_check = !empty($db_user['microsoft_id']) || empty($db_user['password']);

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'pin_session_setting') {
        if (!is_admin()) { flash_set('danger', 'Brak uprawnień.'); }
        else {
            $m = max(0, min(30, (int)($_POST['pin_session_min'] ?? 0)));
            org_setting_set('edok_pin_session_min', (string)$m);
            audit_log('edok.pin_session_setting', ['minutes' => $m, 'by' => $user['name'] ?? ''], $uid);
            flash_set('success', $m > 0 ? "Sesja PIN: {$m} min." : 'Sesja PIN wyłączona — PIN przy każdej akceptacji.');
        }
        header('Location: ' . APP_URL . '/edok/ustaw_pin.php'); exit;
    }
    $current_password = $_POST['current_password'] ?? '';
    $new_pin          = trim($_POST['new_pin'] ?? '');
    $confirm_pin      = trim($_POST['confirm_pin'] ?? '');

    if (!$skip_password_check && !password_verify($current_password, $db_user['password'])) {
        $errors[] = 'Aktualne hasło logowania jest nieprawidłowe.';
    }
    if (!preg_match('/^\d{6}$/', $new_pin)) {
        $errors[] = 'PIN musi składać się z dokładnie 6 cyfr.';
    }
    if ($new_pin !== $confirm_pin) {
        $errors[] = 'PIN-y nie są identyczne.';
    }

    if (!$errors) {
        edok_pin_set($uid, $new_pin);
        edok_log(0, 'pin_set', '', '', '', ($has_pin ? 'Zmieniono' : 'Ustawiono') . ' PIN EODoK dla użytkownika ' . ($user['name'] ?? ('uid:' . $uid)) . '.');
        flash_set('success', 'PIN EODoK został ' . ($has_pin ? 'zmieniony' : 'ustawiony') . '.');
        header('Location: ' . APP_URL . '/edok/ustaw_pin.php');
        exit;
    }
}

$PAGE_TITLE = 'Twój PIN EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Twój PIN EODoK</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong><?= $has_pin ? 'Zmiana PIN-u' : 'Ustawienie PIN-u' ?></strong></div>
      <div class="card-body">
        <p class="small text-muted">
          6-cyfrowy PIN EODoK to osobny sekret od hasła logowania. Jest wymagany przy każdej
          akceptacji ("Tak/OK") etapu obiegu dokumentu — zgodnie z Uchwałą Zarządu nr 5/2026
          w sprawie elektronicznej akceptacji dokumentów. Nie udostępniaj go nikomu.
        </p>
        <form method="post" novalidate>
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <?php if (!$skip_password_check): ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="current_password">Aktualne hasło logowania</label>
            <input type="password" id="current_password" name="current_password" class="form-control form-control-sm" required autocomplete="current-password">
          </div>
          <?php else: ?>
          <div class="alert alert-light border small py-2 mb-3">
            <i class="bi <?= !empty($db_user['microsoft_id']) ? 'bi-microsoft' : 'bi-info-circle' ?>"></i>
            <?= !empty($db_user['microsoft_id']) ? 'Logujesz się przez Microsoft 365 — nie musisz potwierdzać hasła.' : 'Twoje konto nie ma hasła lokalnego — nie musisz go potwierdzać.' ?>
          </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="new_pin"><?= $has_pin ? 'Nowy PIN' : 'PIN' ?> (6 cyfr)</label>
            <input type="password" id="new_pin" name="new_pin" class="form-control form-control-sm" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="off">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="confirm_pin">Powtórz PIN</label>
            <input type="password" id="confirm_pin" name="confirm_pin" class="form-control form-control-sm" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="off">
          </div>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> <?= $has_pin ? 'Zmień PIN' : 'Ustaw PIN' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if (is_admin()): ?>
<div class="row"><div class="col-lg-6">
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Sesja PIN (ustawienie organizacji)</strong></div>
    <div class="card-body">
      <p class="small text-muted">Po poprawnym wpisaniu PIN-u kolejne akceptacje („Tak/OK") tego użytkownika w tej samej sesji przeglądarki nie wymagają PIN-u przez podany czas (liczony od ostatniego wpisania PIN-u, nie przesuwa się). Każda takie decyzja jest w audycie i na karcie akceptacji oznaczona jako „sesja PIN". Wylogowanie lub przycisk „Zakończ sesję" kończy ją od razu. 0 = PIN przy każdej akceptacji.</p>
      <form method="post" class="d-flex gap-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="pin_session_setting">
        <div><label class="form-label small fw-semibold" for="pin_session_min">Czas sesji (minuty, 0–30)</label>
          <input type="number" id="pin_session_min" name="pin_session_min" min="0" max="30" class="form-control form-control-sm" style="width:110px" value="<?= (int)edok_pin_session_minutes() ?>"></div>
        <button class="btn btn-sm btn-primary">Zapisz</button>
      </form>
    </div>
  </div>
</div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
