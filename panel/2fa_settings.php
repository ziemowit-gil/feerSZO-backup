<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/totp.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
$PAGE_TITLE = 'Uwierzytelnianie 2FA';
$user = current_user();
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

$sms_available = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled();
} catch (\Throwable $e) {}

// Reload full user record
$db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);

$errors  = [];
$success = '';

// ── POST handling ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // ── TOTP: start setup ─────────────────────────────────────────────────
    if ($action === 'totp_start') {
        $secret = TOTP::generate_secret();
        $_SESSION['2fa_pending_secret'] = $secret;
        // Reload to show QR
    }

    // ── TOTP: confirm code ────────────────────────────────────────────────
    elseif ($action === 'totp_confirm') {
        $secret = $_SESSION['2fa_pending_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');

        if (!$secret) {
            $errors[] = 'Brak sekretu TOTP w sesji. Zacznij od nowa.';
        } elseif (!TOTP::verify($secret, $code)) {
            $errors[] = 'Kod nieprawidłowy. Sprawdź czas systemowy i spróbuj ponownie.';
        } else {
            // Generate 8 backup codes (XXXX-XXXX hex)
            $backup = [];
            for ($i = 0; $i < 8; $i++) {
                $backup[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            }

            db()->prepare(
                "UPDATE users SET totp_secret=?, totp_confirmed=1, twofa_method='totp', totp_backup_codes=? WHERE id=?"
            )->execute([$secret, json_encode($backup), $user['id']]);

            unset($_SESSION['2fa_pending_secret']);
            $_SESSION['2fa_backup_codes_display'] = $backup;

            log_user_action((int)$user['id'], (int)$user['id'], '2fa_enabled', 'Włączono 2FA TOTP');
            $success = 'totp_enabled';
            $db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);
        }
    }

    // ── TOTP: disable ─────────────────────────────────────────────────────
    elseif ($action === 'totp_disable') {
        db()->prepare(
            "UPDATE users SET totp_secret=NULL, totp_confirmed=0, twofa_method='', totp_backup_codes=NULL WHERE id=?"
        )->execute([$user['id']]);
        unset($_SESSION['2fa_pending_secret'], $_SESSION['2fa_backup_codes_display']);
        log_user_action((int)$user['id'], (int)$user['id'], '2fa_disabled', 'Wyłączono 2FA TOTP');
        flash_set('success', '2FA TOTP zostało wyłączone.');
        header('Location: ' . APP_URL . '/panel/2fa_settings.php');
        exit;
    }

    // ── SMS: start (save phone, send test OTP) ────────────────────────────
    elseif ($action === 'sms_start' && $sms_available) {
        $phone = trim($_POST['twofa_phone'] ?? '');
        if (!$phone) {
            $errors[] = 'Podaj numer telefonu.';
        } else {
            $phone_norm = sms_normalize_phone($phone);
            db()->prepare("UPDATE users SET twofa_phone=? WHERE id=?")
                ->execute([$phone_norm, $user['id']]);
            $db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);

            try {
                $otp = sms_generate_otp($phone_norm, $user['id']);
                $via = sms_send_with_fallback($phone_norm, "Kod weryfikacyjny 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '');
                $_SESSION['2fa_sms_setup_sent'] = true;
                $success = $via === 'email' ? 'sms_fallback_email' : 'sms_sent';
            } catch (\Throwable $e) {
                $errors[] = 'Błąd wysyłki kodu: ' . $e->getMessage();
            }
        }
    }

    // ── SMS: confirm OTP ──────────────────────────────────────────────────
    elseif ($action === 'sms_confirm' && $sms_available) {
        $phone_norm = $db_user['twofa_phone'] ?? '';
        $code       = trim($_POST['code'] ?? '');

        if (!$phone_norm) {
            $errors[] = 'Brak numeru telefonu. Wróć do kroku 1.';
        } else {
            $verified = sms_verify_otp($phone_norm, $code);
            if ($verified && (int)$verified['id'] === (int)$user['id']) {
                db()->prepare("UPDATE users SET twofa_method='sms' WHERE id=?")
                    ->execute([$user['id']]);
                unset($_SESSION['2fa_sms_setup_sent']);
                log_user_action((int)$user['id'], (int)$user['id'], '2fa_enabled',
                    'Włączono 2FA SMS: ' . $phone_norm);
                flash_set('success', '2FA SMS zostało włączone.');
                header('Location: ' . APP_URL . '/panel/2fa_settings.php');
                exit;
            } else {
                $errors[] = 'Nieprawidłowy lub wygasły kod. Spróbuj ponownie.';
                $success  = 'sms_sent';  // keep the verify form visible
            }
        }
    }

    // ── SMS: disable ──────────────────────────────────────────────────────
    elseif ($action === 'sms_disable' && $sms_available) {
        db()->prepare("UPDATE users SET twofa_method='' WHERE id=?")
            ->execute([$user['id']]);
        log_user_action((int)$user['id'], (int)$user['id'], '2fa_disabled', 'Wyłączono 2FA SMS');
        flash_set('success', '2FA SMS zostało wyłączone.');
        header('Location: ' . APP_URL . '/panel/2fa_settings.php');
        exit;
    }

    // ── Disable 2FA entirely ──────────────────────────────────────────────
    elseif ($action === 'disable_all') {
        db()->prepare(
            "UPDATE users SET twofa_method='', totp_secret=NULL, totp_confirmed=0, totp_backup_codes=NULL WHERE id=?"
        )->execute([$user['id']]);
        unset($_SESSION['2fa_pending_secret'], $_SESSION['2fa_backup_codes_display'],
              $_SESSION['2fa_sms_setup_sent']);
        log_user_action((int)$user['id'], (int)$user['id'], '2fa_disabled', 'Wyłączono całe 2FA');
        flash_set('success', 'Dwuetapowe uwierzytelnianie zostało wyłączone.');
        header('Location: ' . APP_URL . '/panel/2fa_settings.php');
        exit;
    }
}

// ── View state ────────────────────────────────────────────────────────────
$current_method    = $db_user['twofa_method'] ?? '';
$totp_confirmed    = !empty($db_user['totp_confirmed']);
$pending_secret    = $_SESSION['2fa_pending_secret'] ?? '';
$backup_codes_show = $_SESSION['2fa_backup_codes_display'] ?? null;

if ($success === 'totp_enabled') {
    // Keep showing them in the same request
} elseif ($backup_codes_show !== null && $success !== 'totp_enabled') {
    // already shown once — keep until user navigates away
}

$totp_uri = '';
if ($pending_secret) {
    $label    = $user['email'];
    $issuer   = defined('ORG_NAME') ? ORG_NAME : 'Rejestr Umów';
    $totp_uri = TOTP::get_qr_uri($pending_secret, $label, $issuer);
}

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Weryfikacja dwuetapowa</h1>
  <p class="pv-page-sub">Dodatkowe zabezpieczenie konta</p>
</div>
<?php echo flash_html(); ?>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-shield-lock text-primary fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Uwierzytelnianie dwuetapowe (2FA)</h4>
    <div class="text-muted small">Konto: <?= h($user['email']) ?></div>
  </div>
</div>

<?php if (!$_is_volunteer_only): ?>
<?= flash_html() ?>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0">
  <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<!-- ── Current status ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-body d-flex align-items-center gap-3">
    <?php if ($current_method === 'totp'): ?>
      <i class="bi bi-shield-fill-check text-success fs-2"></i>
      <div>
        <div class="fw-semibold">Aktywna metoda: <span class="text-success">Aplikacja TOTP</span></div>
        <div class="text-muted small">Logowanie wymaga kodu z aplikacji Google/Microsoft Authenticator.</div>
      </div>
    <?php elseif ($current_method === 'sms'): ?>
      <i class="bi bi-shield-fill-check text-success fs-2"></i>
      <div>
        <div class="fw-semibold">Aktywna metoda: <span class="text-success">Kod SMS</span></div>
        <div class="text-muted small">Logowanie wymaga jednorazowego kodu SMS.</div>
      </div>
    <?php else: ?>
      <i class="bi bi-shield-x text-secondary fs-2"></i>
      <div>
        <div class="fw-semibold text-muted">2FA wyłączone</div>
        <div class="text-muted small">Twoje konto nie jest chronione dodatkowym czynnikiem.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Disable all 2FA ─────────────────────────────────────────────────── -->
<?php if ($current_method): ?>
<div class="card shadow-sm mb-4 border-danger border-opacity-25">
  <div class="card-header bg-danger bg-opacity-10 text-danger fw-semibold">
    <i class="bi bi-shield-x"></i> Wyłącz uwierzytelnianie dwuetapowe
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Po wyłączeniu 2FA konto będzie chronione wyłącznie hasłem. Upewnij się, że masz silne hasło.
    </p>
    <form method="post" onsubmit="return confirm('Na pewno wyłączyć 2FA?')">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="disable_all">
      <button type="submit" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-shield-x"></i> Wyłącz 2FA
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Backup codes display (once after enable) ───────────────────────── -->
<?php if ($backup_codes_show !== null): ?>
<div class="alert alert-warning border-warning">
  <div class="fw-semibold mb-2"><i class="bi bi-key-fill"></i> Zapisz kody zapasowe — wyświetlone tylko raz!</div>
  <p class="small mb-2">Jeśli zgubisz dostęp do aplikacji, możesz użyć jednego z tych kodów. Każdy działa tylko raz.</p>
  <div class="row row-cols-2 row-cols-md-4 g-2 mb-2">
    <?php foreach ($backup_codes_show as $bc): ?>
    <div class="col"><code class="d-block text-center p-2 bg-white border rounded fw-bold"><?= h($bc) ?></code></div>
    <?php endforeach; ?>
  </div>
  <small class="text-muted">Przechowuj je w bezpiecznym miejscu (menedżer haseł, wydruk).</small>
  <?php unset($_SESSION['2fa_backup_codes_display']); ?>
</div>
<?php endif; ?>

<!-- ── TOTP Section ────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">
    <i class="bi bi-phone"></i> Aplikacja uwierzytelniająca (TOTP)
    <?php if ($current_method === 'totp'): ?>
      <span class="badge bg-success ms-2">Aktywne</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

    <?php if ($current_method === 'totp'): ?>
    <!-- Already enabled — show disable option -->
    <p class="text-muted small mb-3">
      TOTP jest aktywne. Używasz aplikacji np. Google Authenticator lub Microsoft Authenticator.
    </p>
    <form method="post" onsubmit="return confirm('Wyłączyć TOTP?')">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="totp_disable">
      <button type="submit" class="btn btn-outline-warning btn-sm">
        <i class="bi bi-shield-minus"></i> Wyłącz TOTP
      </button>
    </form>

    <?php elseif ($pending_secret): ?>
    <!-- Step 2: scan QR and confirm code -->
    <p class="fw-semibold mb-1">Krok 2 — Skanuj kod QR lub wpisz klucz ręcznie</p>
    <p class="text-muted small mb-3">
      Otwórz aplikację Google Authenticator lub Microsoft Authenticator i dodaj nowe konto.
    </p>

    <div class="text-center mb-3">
      <canvas id="qrcode-canvas" class="border rounded p-2"></canvas>
    </div>

    <div class="mb-3">
      <label class="form-label small fw-semibold">Klucz ręczny (jeśli nie możesz skanować QR):</label>
      <div class="input-group input-group-sm">
        <input type="text" class="form-control font-monospace" readonly
               value="<?= h($pending_secret) ?>" id="totp-secret-display">
        <button type="button" class="btn btn-outline-secondary"
                onclick="navigator.clipboard.writeText(document.getElementById('totp-secret-display').value)">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
    </div>

    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="totp_confirm">
      <div class="mb-3">
        <label class="form-label fw-semibold">Krok 3 — Wpisz 6-cyfrowy kod z aplikacji</label>
        <input type="text" name="code" class="form-control"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               placeholder="______" autofocus required
               style="font-size:1.5rem;letter-spacing:.4rem;text-align:center;font-weight:700">
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-shield-check"></i> Potwierdź i włącz 2FA
        </button>
        <a href="<?= APP_URL ?>/panel/2fa_settings.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script>
    QRCode.toCanvas(
        document.getElementById('qrcode-canvas'),
        <?= json_encode($totp_uri) ?>,
        {width: 200},
        function(err) { if (err) console.error(err); }
    );
    </script>

    <?php else: ?>
    <!-- Step 1: start TOTP setup -->
    <p class="text-muted small mb-3">
      Zainstaluj aplikację <strong>Google Authenticator</strong> lub <strong>Microsoft Authenticator</strong>
      na swoim telefonie, a następnie kliknij poniższy przycisk.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="totp_start">
      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-qr-code"></i> Skonfiguruj aplikację TOTP
      </button>
    </form>
    <?php endif; ?>

  </div>
</div>

<!-- ── SMS Section ────────────────────────────────────────────────────── -->
<?php if ($sms_available): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">
    <i class="bi bi-chat-dots"></i> Kod SMS
    <?php if ($current_method === 'sms'): ?>
      <span class="badge bg-success ms-2">Aktywne</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

    <?php if ($current_method === 'sms'): ?>
    <p class="text-muted small mb-3">
      SMS 2FA jest aktywne. Numer telefonu: <strong><?= h($db_user['twofa_phone'] ?? '—') ?></strong>
    </p>
    <form method="post" onsubmit="return confirm('Wyłączyć SMS 2FA?')">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sms_disable">
      <button type="submit" class="btn btn-outline-warning btn-sm">
        <i class="bi bi-shield-minus"></i> Wyłącz SMS 2FA
      </button>
    </form>

    <?php elseif ($success === 'sms_sent' || $success === 'sms_fallback_email'): ?>
    <!-- Step 2: verify SMS OTP (or email fallback) -->
    <?php if ($success === 'sms_fallback_email'): ?>
    <div class="alert alert-warning py-2 small mb-3" role="alert">
      <i class="bi bi-envelope-exclamation me-1" aria-hidden="true"></i>
      Wysyłka SMS nie powiodła się — kod wysłany na adres e-mail Twojego konta. Sprawdź skrzynkę.
    </div>
    <?php else: ?>
    <p class="small text-muted mb-3">
      Kod SMS wysłany na numer <strong><?= h($db_user['twofa_phone'] ?? '') ?></strong>.
      Ważny przez 5 minut.
    </p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sms_confirm">
      <div class="mb-3">
        <label class="form-label fw-semibold">6-cyfrowy kod</label>
        <input type="text" name="code" class="form-control"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               placeholder="______" autofocus required
               style="font-size:1.5rem;letter-spacing:.4rem;text-align:center;font-weight:700">
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-shield-check"></i> Potwierdź i włącz SMS 2FA
        </button>
        <a href="<?= APP_URL ?>/panel/2fa_settings.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>

    <?php else: ?>
    <!-- Step 1: enter phone -->
    <p class="text-muted small mb-3">
      Po włączeniu, przy każdym logowaniu hasłem zostanie wysłany jednorazowy kod SMS na Twój numer.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sms_start">
      <div class="mb-3">
        <label class="form-label fw-semibold">Numer telefonu</label>
        <div class="input-group">
          <span class="input-group-text fw-semibold text-muted">+48</span>
          <input type="tel" name="twofa_phone" class="form-control phone-48"
                 placeholder="123 456 789"
                 value="<?= h($db_user['twofa_phone'] ?? '') ?>" required>
        </div>
        <div class="form-text">Wpisz 9 cyfr — prefiks +48 zostanie dodany automatycznie.</div>
      </div>
      <button type="submit" class="btn btn-success btn-sm">
        <i class="bi bi-send"></i> Wyślij kod weryfikacyjny
      </button>
    </form>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>


<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
