<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';

require_login();
cpc_migrate();
kdok_migrate();

$user    = current_user();
$uid     = (int)$user['id'];
$errors  = [];
$success = false;

// Sprawdź czy użytkownik ma już oba kody — pokazuj stosowny komunikat
$fresh = db_one("SELECT cpc_code, kdok_ikaks_hash, ika_setup_token, ika_setup_token_expires FROM users WHERE id=?", [$uid]);
$has_cpc   = !empty($fresh['cpc_code']);
$has_ikaks = !empty($fresh['kdok_ikaks_hash']);
$has_token = !empty($fresh['ika_setup_token'])
    && !empty($fresh['ika_setup_token_expires'])
    && $fresh['ika_setup_token_expires'] > date('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $admin_code = strtoupper(trim($_POST['admin_code'] ?? ''));
    $cpc1       = trim($_POST['cpc_code'] ?? '');
    $ikaks1     = $_POST['ikaks1'] ?? '';
    $ikaks2     = $_POST['ikaks2'] ?? '';

    // Walidacja AdminCode
    if ($admin_code === '') {
        $errors[] = 'Podaj AdminCode otrzymany od administratora.';
    } elseif (!preg_match('/^[0-9A-F]{10}$/', $admin_code)) {
        $errors[] = 'Nieprawidłowy format AdminCode (10 znaków, cyfry i litery A–F).';
    }

    // Walidacja CPC
    if (!preg_match('/^\d{6}$/', $cpc1)) {
        $errors[] = 'Kod IKA musi składać się dokładnie z 6 cyfr.';
    }

    // Walidacja IKAKS
    if (strlen($ikaks1) < 6) {
        $errors[] = 'IKAKS musi mieć minimum 6 znaków.';
    } elseif ($ikaks1 !== $ikaks2) {
        $errors[] = 'Oba pola IKAKS muszą być identyczne.';
    }

    if (!$errors) {
        // Weryfikacja tokenu — jednorazowa, konsumuje token
        if (!cpc_setup_token_verify($uid, $admin_code)) {
            $errors[] = 'AdminCode jest nieprawidłowy lub wygasł. Poproś administratora o wygenerowanie nowego.';
        } else {
            // Ustaw CPC
            db()->prepare("UPDATE users SET cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL WHERE id=?")
                ->execute([$cpc1, $uid]);

            // Ustaw IKAKS
            kdok_ikaks_set($uid, $ikaks1);

            authlog_write($uid, 'self_set_ika_ikaks', $user['email'] ?? '',
                'Użytkownik samodzielnie ustawił kod IKA i IKAKS przy użyciu tokenu konfiguracyjnego.');

            $success = true;
            $has_cpc   = true;
            $has_ikaks = true;
            $has_token = false;
            $fresh = db_one("SELECT cpc_code, kdok_ikaks_hash, ika_setup_token, ika_setup_token_expires FROM users WHERE id=?", [$uid]);
        }
    }
}

$PAGE_TITLE = 'Ustaw kody autoryzacyjne (IKA i IKAKS)';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="mb-0">
    <i class="bi bi-shield-lock text-warning"></i>
    Ustaw kody autoryzacyjne
  </h4>
</div>

<?= flash_html() ?>

<!-- Status kodów -->
<div class="row g-3 mb-4" style="max-width:600px">
  <div class="col-sm-6">
    <div class="card p-3 text-center border-<?= $has_cpc ? 'success' : 'danger' ?>">
      <i class="bi bi-key fs-2 text-<?= $has_cpc ? 'success' : 'danger' ?>"></i>
      <div class="fw-semibold mt-1">Kod IKA</div>
      <div class="small text-muted"><?= $has_cpc ? 'Ustawiony' : 'Brak' ?></div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="card p-3 text-center border-<?= $has_ikaks ? 'success' : 'danger' ?>">
      <i class="bi bi-key-fill fs-2 text-<?= $has_ikaks ? 'success' : 'danger' ?>"></i>
      <div class="fw-semibold mt-1">IKAKS</div>
      <div class="small text-muted"><?= $has_ikaks ? 'Ustawiony' : 'Brak' ?></div>
    </div>
  </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success d-flex gap-2 align-items-start" style="max-width:560px">
  <i class="bi bi-check-circle-fill fs-4 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Kody autoryzacyjne zostały ustawione.</strong><br>
    Kod IKA (6 cyfr) i IKAKS są aktywne. Zapamiętaj je — nie będą pokazywane ponownie.
  </div>
</div>

<?php elseif ($has_cpc && $has_ikaks): ?>
<div class="alert alert-info d-flex gap-2 align-items-start" style="max-width:560px">
  <i class="bi bi-info-circle-fill fs-4 flex-shrink-0 mt-1"></i>
  <div>
    Masz już ustawione oba kody autoryzacyjne.<br>
    <strong>Kod IKA</strong> możesz zmienić w panelu administratora.<br>
    <strong>IKAKS</strong> możesz zmienić w <a href="<?= APP_URL ?>/user/kdok_ikaks.php">panelu IKAKS</a>.
  </div>
</div>

<?php else: ?>

<?php if (!$has_token): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start" style="max-width:560px">
  <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0 mt-1"></i>
  <div>
    Aby ustawić kody, potrzebujesz <strong>jednorazowego AdminCode</strong> od administratora.<br>
    Poproś administratora o wygenerowanie tokenu konfiguracyjnego dla Twojego konta.
  </div>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger" style="max-width:560px" role="alert">
  <ul class="mb-0">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:560px">
  <div class="card-header fw-semibold py-2">
    <i class="bi bi-shield-plus me-1 text-warning"></i>
    Wprowadź kody
  </div>
  <div class="card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-4 pb-3 border-bottom">
        <label class="form-label fw-semibold" for="admin_code">
          AdminCode <span class="text-danger">*</span>
          <span class="text-muted fw-normal small ms-1">(jednorazowy, od administratora)</span>
        </label>
        <input type="text"
               id="admin_code"
               name="admin_code"
               class="form-control font-monospace<?= in_array('AdminCode', array_map(fn($e)=>substr($e,0,9), $errors)) ? ' is-invalid' : '' ?>"
               maxlength="10"
               placeholder="np. A3B7C2D8E1"
               autocomplete="off"
               spellcheck="false"
               style="letter-spacing:.2em;font-size:1.15rem;max-width:240px"
               oninput="this.value=this.value.toUpperCase().replace(/[^0-9A-F]/g,'')">
        <div class="form-text">10-znakowy kod otrzymany od administratora (cyfry i litery A–F).</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="cpc_code">
          Nowy kod IKA <span class="text-danger">*</span>
          <span class="text-muted fw-normal small ms-1">(dokładnie 6 cyfr)</span>
        </label>
        <input type="text"
               id="cpc_code"
               name="cpc_code"
               class="form-control font-monospace"
               maxlength="6"
               pattern="\d{6}"
               placeholder="000000"
               inputmode="numeric"
               autocomplete="new-password"
               style="max-width:160px;letter-spacing:.35em;font-size:1.4rem">
        <div class="form-text">
          Wybierz 6-cyfrowy kod, który będziesz używał do autoryzacji umów i operacji krytycznych.
          Zapamiętaj go.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="ikaks1">
          Nowy IKAKS <span class="text-danger">*</span>
          <span class="text-muted fw-normal small ms-1">(min. 6 znaków)</span>
        </label>
        <input type="password"
               id="ikaks1"
               name="ikaks1"
               class="form-control"
               minlength="6"
               autocomplete="new-password"
               style="max-width:320px"
               placeholder="min. 6 znaków">
        <div class="form-text">IKAKS służy do autoryzacji dokumentów księgowych (EOD).</div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold" for="ikaks2">
          Powtórz IKAKS <span class="text-danger">*</span>
        </label>
        <input type="password"
               id="ikaks2"
               name="ikaks2"
               class="form-control"
               minlength="6"
               autocomplete="new-password"
               style="max-width:320px"
               placeholder="powtórz IKAKS">
      </div>

      <div class="alert alert-light border small mb-3" style="max-width:480px">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Bezpieczeństwo:</strong> AdminCode jest jednorazowy i wygaśnie po użyciu.
        Kody IKA i IKAKS są Twoją osobistą autoryzacją — nie udostępniaj ich nikomu.
        IKAKS przechowywany jest w formie zahashowanej; administrator nie może go odczytać.
      </div>

      <button type="submit" class="btn btn-warning fw-semibold px-4">
        <i class="bi bi-shield-check me-1"></i>
        Ustaw kody autoryzacyjne
      </button>
    </form>
  </div>
</div>

<?php endif; ?>

<script>
// Autouzupełnianie wielkich liter dla AdminCode
(function () {
  var inp = document.getElementById('admin_code');
  if (!inp) return;
  inp.addEventListener('paste', function(e) {
    e.preventDefault();
    var pasted = (e.clipboardData || window.clipboardData).getData('text');
    inp.value = pasted.toUpperCase().replace(/[^0-9A-F]/g, '').slice(0, 10);
  });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
