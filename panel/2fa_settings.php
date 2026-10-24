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
$_is_volunteer_only = panel_volunteer_shell($user); // powłoka panelu: wolontariusz albo ktokolwiek z umową

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
                $via = sms_send_with_fallback($phone_norm, "Kod weryfikacyjny 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '', 'twofa_code');
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
    require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles(); // style panelu (.tz-*, .pv-*) także pod powłoką SZO
}
?>

<?php
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
require_once dirname(__DIR__) . '/modules/mfa_ui/lib/mfaUi.php';
panelMetroAssets($_vol_color ?? '#0050ef');
mfaUiStyles();
$m_totp  = $current_method === 'totp';
$m_sms   = $current_method === 'sms';
$m_on    = $m_totp || $m_sms;
?>
<div class="pvm mfa">

  <div class="mt-head">
    <div>
      <div class="mt-kicker"><a href="<?= APP_URL ?>/panel/index.php" style="color:inherit">Panel współpracownika</a> · konta i dostępy</div>
      <h1 class="mt-title">Weryfikacja dwuetapowa</h1>
      <p class="mt-lead">Dodatkowy czynnik przy logowaniu hasłem: aplikacja uwierzytelniająca, kod SMS albo klucz sprzętowy.</p>
    </div>
  </div>

  <?= flash_html() ?>

  <?php foreach ($errors as $e): ?>
  <div class="mt-note" role="alert" style="border-left-color:var(--m-red)"><i class="bi bi-x-circle" style="color:var(--m-red)" aria-hidden="true"></i><span><?= h($e) ?></span></div>
  <?php endforeach; ?>

  <!-- Stan -->
  <section class="mt-group" aria-labelledby="mfa-state-h">
    <h2 class="mt-h2" id="mfa-state-h">Stan <b>zabezpieczenia</b></h2>
    <div class="mfa-state" style="--c:<?= $m_on ? 'var(--m-green)' : 'var(--m-grey)' ?>">
      <span class="sq" aria-hidden="true"><i class="bi <?= $m_on ? 'bi-shield-fill-check' : 'bi-shield-x' ?>"></i></span>
      <span>
        <?php if ($m_totp): ?><b>Włączone: aplikacja TOTP</b><small>Logowanie wymaga kodu z Google lub Microsoft Authenticator.</small>
        <?php elseif ($m_sms): ?><b>Włączone: kod SMS</b><small>Logowanie wymaga jednorazowego kodu SMS.</small>
        <?php else: ?><b>2FA wyłączone</b><small>Konto jest chronione tylko hasłem.</small><?php endif; ?>
      </span>
    </div>
    <?php if (in_array($user['role'] ?? '', ['admin', 'editor'], true)): ?>
    <div class="mfa-acts"><a class="mt-btn mt-btn--ghost" href="<?= APP_URL ?>/panel/webauthn.php"><i class="bi bi-fingerprint" aria-hidden="true"></i>Klucze bezpieczeństwa (WebAuthn)</a></div>
    <?php endif; ?>
  </section>

  <?php if ($backup_codes_show !== null): ?>
  <section class="mt-group" style="border-left:6px solid var(--m-orange)" aria-labelledby="mfa-bk-h" role="alert">
    <h2 class="mt-h2" id="mfa-bk-h"><i class="bi bi-key-fill" aria-hidden="true"></i> Kody zapasowe — <b>pokazane tylko raz</b></h2>
    <p class="mfa-lead">Jeśli stracisz dostęp do aplikacji, użyj jednego z tych kodów. Każdy działa jednorazowo. Zapisz je w menedżerze haseł albo wydrukuj.</p>
    <div class="mfa-codes">
      <?php foreach ($backup_codes_show as $bc): ?><code><?= h($bc) ?></code><?php endforeach; ?>
    </div>
    <?php unset($_SESSION['2fa_backup_codes_display']); ?>
  </section>
  <?php endif; ?>

  <div class="mfa-cols">

    <!-- TOTP -->
    <section class="mt-group" aria-labelledby="mfa-totp-h">
      <h2 class="mt-h2" id="mfa-totp-h"><i class="bi bi-phone" aria-hidden="true"></i> Aplikacja <b>uwierzytelniająca</b><?php if ($m_totp): ?><span class="mfa-badge">Aktywne</span><?php endif; ?></h2>

      <?php if ($m_totp): ?>
      <p class="mfa-lead">TOTP jest aktywne — kody generuje Twoja aplikacja (np. Google Authenticator, Microsoft Authenticator).</p>
      <form method="post" class="mfa-acts" onsubmit="return confirm('Wyłączyć TOTP?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="totp_disable">
        <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-shield-minus" aria-hidden="true"></i>Wyłącz TOTP</button>
      </form>

      <?php elseif ($pending_secret): ?>
      <ol class="mfa-steps" aria-label="Postęp konfiguracji">
        <li class="is-done"><span class="n" aria-hidden="true">&#10003;</span>Aplikacja</li>
        <li class="is-cur" aria-current="step"><span class="n" aria-hidden="true">2</span>Skanuj kod QR</li>
        <li><span class="n" aria-hidden="true">3</span>Potwierdź kod</li>
      </ol>
      <p class="mfa-lead">Dodaj nowe konto w aplikacji i zeskanuj kod QR albo wpisz klucz ręcznie.</p>
      <div class="mfa-qr"><canvas id="qrcode-canvas" aria-label="Kod QR do konfiguracji aplikacji TOTP"></canvas></div>
      <label class="lbl" for="totp-secret-display">Klucz ręczny</label>
      <div class="mfa-secret">
        <input type="text" class="mfa-in" readonly value="<?= h($pending_secret) ?>" id="totp-secret-display" aria-label="Klucz TOTP do ręcznego wpisania">
        <button type="button" class="mt-btn mt-btn--ghost" aria-label="Kopiuj klucz TOTP"
                onclick="navigator.clipboard.writeText(document.getElementById('totp-secret-display').value);this.innerHTML='<i class=\'bi bi-check2\' aria-hidden=\'true\'></i>'"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
      </div>
      <form method="post" style="margin-top:1rem">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="totp_confirm">
        <label class="lbl" for="totp-code">Kod z aplikacji (6 cyfr)</label>
        <input type="text" name="code" id="totp-code" class="mfa-in mfa-in--code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               placeholder="______" autocomplete="one-time-code" autofocus required aria-required="true">
        <div class="mfa-acts" style="margin-top:.8rem">
          <button type="submit" class="mt-btn"><i class="bi bi-shield-check" aria-hidden="true"></i>Potwierdź i włącz</button>
          <a href="<?= APP_URL ?>/panel/2fa_settings.php" class="mt-btn mt-btn--ghost">Anuluj</a>
        </div>
      </form>
      <script src="https://cdn.jsdelivr.net/npm/qrcode@1.4.4/build/qrcode.min.js"></script>
      <script>QRCode.toCanvas(document.getElementById('qrcode-canvas'), <?= json_encode($totp_uri) ?>, {width: 200}, function (err) { if (err) console.error(err); });</script>

      <?php else: ?>
      <p class="mfa-lead">Zainstaluj <b style="font-weight:600">Google Authenticator</b> lub <b style="font-weight:600">Microsoft Authenticator</b> na telefonie, potem kliknij poniżej.</p>
      <form method="post" class="mfa-acts">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="totp_start">
        <button type="submit" class="mt-btn"><i class="bi bi-qr-code" aria-hidden="true"></i>Skonfiguruj aplikację</button>
      </form>
      <?php endif; ?>
    </section>

    <!-- SMS -->
    <?php if ($sms_available): ?>
    <section class="mt-group" aria-labelledby="mfa-sms-h">
      <h2 class="mt-h2" id="mfa-sms-h"><i class="bi bi-chat-dots" aria-hidden="true"></i> Kod <b>SMS</b><?php if ($m_sms): ?><span class="mfa-badge">Aktywne</span><?php endif; ?></h2>

      <?php if ($m_sms): ?>
      <p class="mfa-lead">Kod SMS jest aktywny. Numer: <b style="font-weight:600"><?= h($db_user['twofa_phone'] ?? '—') ?></b></p>
      <form method="post" class="mfa-acts" onsubmit="return confirm('Wyłączyć SMS 2FA?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="sms_disable">
        <button type="submit" class="mt-btn mt-btn--ghost"><i class="bi bi-shield-minus" aria-hidden="true"></i>Wyłącz SMS 2FA</button>
      </form>

      <?php elseif ($success === 'sms_sent' || $success === 'sms_fallback_email'): ?>
      <ol class="mfa-steps" aria-label="Postęp konfiguracji">
        <li class="is-done"><span class="n" aria-hidden="true">&#10003;</span>Numer</li>
        <li class="is-cur" aria-current="step"><span class="n" aria-hidden="true">2</span>Potwierdź kod</li>
      </ol>
      <?php if ($success === 'sms_fallback_email'): ?>
      <div class="mt-note" role="alert" style="border-left-color:var(--m-orange)"><i class="bi bi-envelope-exclamation" style="color:var(--m-orange)" aria-hidden="true"></i><span>Wysyłka SMS nie powiodła się — kod poszedł na e-mail konta. Sprawdź skrzynkę.</span></div>
      <?php else: ?>
      <p class="mfa-lead">Kod wysłaliśmy na numer <b style="font-weight:600"><?= h($db_user['twofa_phone'] ?? '') ?></b>. Jest ważny 5 minut.</p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="sms_confirm">
        <label class="lbl" for="sms-code">Kod (6 cyfr)</label>
        <input type="text" name="code" id="sms-code" class="mfa-in mfa-in--code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               placeholder="______" autocomplete="one-time-code" autofocus required aria-required="true">
        <div class="mfa-acts" style="margin-top:.8rem">
          <button type="submit" class="mt-btn"><i class="bi bi-shield-check" aria-hidden="true"></i>Potwierdź i włącz</button>
          <a href="<?= APP_URL ?>/panel/2fa_settings.php" class="mt-btn mt-btn--ghost">Anuluj</a>
        </div>
      </form>

      <?php else: ?>
      <p class="mfa-lead">Po włączeniu, przy każdym logowaniu hasłem wyślemy jednorazowy kod SMS na Twój numer.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="sms_start">
        <label class="lbl" for="twofa-phone">Numer telefonu</label>
        <div class="mfa-phone">
          <span class="pre" aria-hidden="true">+48</span>
          <input type="tel" name="twofa_phone" id="twofa-phone" class="mfa-in phone-48" placeholder="123 456 789" autocomplete="tel-national"
                 value="<?= h($db_user['twofa_phone'] ?? '') ?>" required aria-required="true" aria-describedby="twofa-phone-h">
        </div>
        <p class="mfa-hint" id="twofa-phone-h">Wpisz 9 cyfr — prefiks +48 dodamy automatycznie.</p>
        <div class="mfa-acts" style="margin-top:.8rem"><button type="submit" class="mt-btn"><i class="bi bi-send" aria-hidden="true"></i>Wyślij kod</button></div>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

  </div>

  <?php if ($m_on): ?>
  <section class="mfa-danger" aria-labelledby="mfa-off-h">
    <h3 id="mfa-off-h"><i class="bi bi-shield-x me-1" aria-hidden="true"></i>Wyłącz uwierzytelnianie dwuetapowe</h3>
    <p class="mfa-hint" style="margin:0 0 .6rem">Po wyłączeniu konto będzie chronione wyłącznie hasłem. Upewnij się, że ma silne hasło.</p>
    <form method="post" onsubmit="return confirm('Na pewno wyłączyć 2FA?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="disable_all">
      <button type="submit" class="mt-btn" style="background:var(--m-red);border-color:var(--m-red);color:#fff"><i class="bi bi-shield-x" aria-hidden="true"></i>Wyłącz 2FA</button>
    </form>
  </section>
  <?php endif; ?>

</div><!-- /.pvm -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
