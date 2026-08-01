<?php
/**
 * tozsamosc/mfa.php — Konfiguracja MFA w podsystemie Tożsamość.
 *
 * Przeniesione i przestylowane z panel/2fa_settings.php — ta sama, sprawdzona
 * logika (TOTP + SMS + kody zapasowe), ale w chrome podsystemu Tożsamość
 * (_head/_foot) i palecie #1E6DFF. Redirecty kierują na /tozsamosc/mfa.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/totp.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';
require_once dirname(__DIR__) . '/includes/tz_auth.php';
require_once dirname(__DIR__) . '/includes/approval.php';

// Osobne logowanie podsystemu.
auth_start();
if (!current_user()) { header('Location: ' . APP_URL . '/tozsamosc/login.php'); exit; }
require_login();

$PAGE_TITLE = 'Metody weryfikacji';
$SELF = APP_URL . '/tozsamosc/mfa.php';
$user = current_user();

$sms_available = false;
try { require_once dirname(__DIR__) . '/includes/sms.php'; $sms_available = sms_is_enabled(); } catch (\Throwable $e) {}

webauthn_migrate();
$db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);
$wk_keys = webauthn_get_credentials((int)$user['id']);
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'totp_start') {
        $_SESSION['2fa_pending_secret'] = TOTP::generate_secret();
    }
    elseif ($action === 'totp_confirm') {
        $secret = $_SESSION['2fa_pending_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if (!$secret) {
            $errors[] = 'Brak sekretu TOTP w sesji. Zacznij od nowa.';
        } elseif (!TOTP::verify($secret, $code)) {
            $errors[] = 'Kod nieprawidłowy. Sprawdź czas systemowy i spróbuj ponownie.';
        } else {
            $backup = [];
            for ($i = 0; $i < 8; $i++) {
                $backup[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            }
            db()->prepare("UPDATE users SET totp_secret=?, totp_confirmed=1, twofa_method='totp', totp_backup_codes=? WHERE id=?")
                ->execute([$secret, json_encode($backup), $user['id']]);
            unset($_SESSION['2fa_pending_secret']);
            $_SESSION['2fa_backup_codes_display'] = $backup;
            log_user_action((int)$user['id'], (int)$user['id'], '2fa_enabled', 'Włączono 2FA TOTP (Tożsamość)');
            tz_grant_level(TZ_LEVEL_MFA); // Konfiguracja TOTP = udana weryfikacja
            $success = 'totp_enabled';
            $db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);
        }
    }
    elseif ($action === 'totp_disable') {
        db()->prepare("UPDATE users SET totp_secret=NULL, totp_confirmed=0, twofa_method='', totp_backup_codes=NULL WHERE id=?")
            ->execute([$user['id']]);
        unset($_SESSION['2fa_pending_secret'], $_SESSION['2fa_backup_codes_display']);
        log_user_action((int)$user['id'], (int)$user['id'], '2fa_disabled', 'Wyłączono 2FA TOTP (Tożsamość)');
        flash_set('success', '2FA TOTP zostało wyłączone.');
        header('Location: ' . $SELF); exit;
    }
    elseif ($action === 'sms_start' && $sms_available) {
        $phone = trim($_POST['twofa_phone'] ?? '');
        if (!$phone) { $errors[] = 'Podaj numer telefonu.'; }
        else {
            $phone_norm = sms_normalize_phone($phone);
            db()->prepare("UPDATE users SET twofa_phone=? WHERE id=?")->execute([$phone_norm, $user['id']]);
            $db_user = db_one("SELECT * FROM users WHERE id=?", [$user['id']]);
            try {
                $otp = sms_generate_otp($phone_norm, $user['id']);
                $via = sms_send_with_fallback($phone_norm, "Kod weryfikacyjny 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '');
                $_SESSION['2fa_sms_setup_sent'] = true;
                $success = $via === 'email' ? 'sms_fallback_email' : 'sms_sent';
            } catch (\Throwable $e) { $errors[] = 'Błąd wysyłki kodu: ' . $e->getMessage(); }
        }
    }
    elseif ($action === 'sms_confirm' && $sms_available) {
        $phone_norm = $db_user['twofa_phone'] ?? '';
        $code       = trim($_POST['code'] ?? '');
        if (!$phone_norm) { $errors[] = 'Brak numeru telefonu. Wróć do kroku 1.'; }
        else {
            $verified = sms_verify_otp($phone_norm, $code);
            if ($verified && (int)$verified['id'] === (int)$user['id']) {
                db()->prepare("UPDATE users SET twofa_method='sms' WHERE id=?")->execute([$user['id']]);
                unset($_SESSION['2fa_sms_setup_sent']);
                log_user_action((int)$user['id'], (int)$user['id'], '2fa_enabled', 'Włączono 2FA SMS (Tożsamość): ' . $phone_norm);
                tz_grant_level(TZ_LEVEL_MFA); // Weryfikacja SMS = udany MFA
                flash_set('success', '2FA SMS zostało włączone.');
                header('Location: ' . $SELF); exit;
            } else { $errors[] = 'Nieprawidłowy lub wygasły kod. Spróbuj ponownie.'; $success = 'sms_sent'; }
        }
    }
    elseif ($action === 'sms_disable' && $sms_available) {
        db()->prepare("UPDATE users SET twofa_method='' WHERE id=?")->execute([$user['id']]);
        log_user_action((int)$user['id'], (int)$user['id'], '2fa_disabled', 'Wyłączono 2FA SMS (Tożsamość)');
        flash_set('success', '2FA SMS zostało wyłączone.');
        header('Location: ' . $SELF); exit;
    }
    elseif ($action === 'webauthn_delete') {
        $cred_id = (int)($_POST['cred_id'] ?? 0);
        if ($cred_id && webauthn_delete_credential($cred_id, (int)$user['id'])) {
            log_user_action((int)$user['id'], (int)$user['id'], 'webauthn_deleted', 'Usunięto klucz WebAuthn ID=' . $cred_id . ' (Tożsamość)');
            flash_set('success', 'Klucz bezpieczeństwa został usunięty.');
        } else {
            flash_set('danger', 'Nie można usunąć klucza.');
        }
        header('Location: ' . $SELF . '#webauthn'); exit;
    }
    elseif ($action === 'disable_all') {
        db()->prepare("UPDATE users SET twofa_method='', totp_secret=NULL, totp_confirmed=0, totp_backup_codes=NULL WHERE id=?")
            ->execute([$user['id']]);
        unset($_SESSION['2fa_pending_secret'], $_SESSION['2fa_backup_codes_display'], $_SESSION['2fa_sms_setup_sent']);
        log_user_action((int)$user['id'], (int)$user['id'], '2fa_disabled', 'Wyłączono całe 2FA (Tożsamość)');
        flash_set('success', 'Dwuetapowe uwierzytelnianie zostało wyłączone.');
        header('Location: ' . $SELF); exit;
    }
}

$current_method    = $db_user['twofa_method'] ?? '';
$pending_secret    = $_SESSION['2fa_pending_secret'] ?? '';
$backup_codes_show = $_SESSION['2fa_backup_codes_display'] ?? null;
$wk_keys           = webauthn_get_credentials((int)$user['id']); // świeże po POST
$totp_uri = '';
if ($pending_secret) {
    $issuer   = defined('ORG_NAME') ? ORG_NAME : 'Tożsamość';
    $totp_uri = TOTP::get_qr_uri($pending_secret, $user['email'], $issuer);
}

$TZ_ACTIVE = 'mfa';
include __DIR__ . '/_head.php';
?>

<div class="tz-h">
  <h1>Metody weryfikacji</h1>
  <p>Wszystkie sposoby potwierdzania tożsamości · konto: <?= h($user['email']) ?></p>
</div>

<p class="mb-3"><a href="<?= APP_URL ?>/tozsamosc/index.php#bezpieczenstwo" class="tz-btn tz-btn--ghost btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć</a></p>

<?= flash_html() ?>

<div aria-live="assertive">
<?php if ($errors): ?>
<div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
</div>

<!-- Status -->
<section class="tz-card">
  <div class="tz-card__bd d-flex align-items-center gap-3">
    <?php if ($current_method === 'totp' || $current_method === 'sms'): ?>
      <i class="bi bi-shield-fill-check fs-2 text-success"></i>
      <div>
        <div class="fw-semibold">Aktywna metoda:
          <span class="text-success"><?= $current_method === 'totp' ? 'Aplikacja Authenticator (TOTP)' : 'Kod SMS' ?></span>
        </div>
        <div class="text-muted small"><?= $current_method === 'totp'
          ? 'Logowanie wymaga kodu z aplikacji Microsoft/Google Authenticator.'
          : 'Logowanie wymaga jednorazowego kodu SMS.' ?></div>
      </div>
    <?php else: ?>
      <i class="bi bi-shield-x fs-2 text-secondary"></i>
      <div>
        <div class="fw-semibold text-muted">MFA wyłączone</div>
        <div class="text-muted small">Twoje konto nie jest chronione dodatkowym czynnikiem.</div>
      </div>
    <?php endif; ?>
    <?php if ($current_method): ?>
    <form method="post" class="ms-auto" onsubmit="return confirm('Na pewno wyłączyć całe MFA?')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="disable_all">
      <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-shield-x me-1" aria-hidden="true"></i>Wyłącz MFA</button>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($backup_codes_show !== null): ?>
<section class="tz-card" style="border-color:#fed7aa">
  <div class="tz-card__bd">
    <div class="fw-semibold mb-2 text-warning-emphasis"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Zapisz kody zapasowe — wyświetlone tylko raz!</div>
    <p class="small text-muted mb-2">Jeśli stracisz dostęp do aplikacji, użyj jednego z tych kodów. Każdy działa jednorazowo.</p>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-2">
      <?php foreach ($backup_codes_show as $bc): ?>
      <div class="col"><code class="d-block text-center p-2 bg-light border rounded fw-bold"><?= h($bc) ?></code></div>
      <?php endforeach; ?>
    </div>
    <small class="text-muted">Przechowuj je w bezpiecznym miejscu (menedżer haseł, wydruk).</small>
    <?php unset($_SESSION['2fa_backup_codes_display']); ?>
  </div>
</section>
<?php endif; ?>

<!-- TOTP -->
<section class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-phone" aria-hidden="true"></i>
    <span>Aplikacja Authenticator (TOTP)</span>
    <?php if ($current_method === 'totp'): ?><span class="tz-badge tz-badge--ok ms-auto"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aktywne</span><?php endif; ?>
  </div>
  <div class="tz-card__bd">
    <?php if ($current_method === 'totp'): ?>
      <p class="text-muted small mb-3">TOTP jest aktywne — używasz Microsoft/Google Authenticator.</p>
      <form method="post" onsubmit="return confirm('Wyłączyć TOTP?')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="totp_disable">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-minus me-1" aria-hidden="true"></i>Wyłącz TOTP</button>
      </form>
    <?php elseif ($pending_secret): ?>
      <p class="fw-semibold mb-1">Krok 2 — zeskanuj kod QR lub wpisz klucz ręcznie</p>
      <p class="text-muted small mb-3">Otwórz aplikację Authenticator i dodaj nowe konto.</p>
      <div class="text-center mb-3"><canvas id="qrcode-canvas" class="border rounded p-2"></canvas></div>
      <div class="mb-3">
        <label for="totp-secret-display" class="form-label small fw-semibold">Klucz ręczny:</label>
        <div class="input-group input-group-sm" style="max-width:360px">
          <input type="text" class="form-control font-monospace" readonly value="<?= h($pending_secret) ?>" id="totp-secret-display">
          <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('totp-secret-display').value)"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
        </div>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="totp_confirm">
        <div class="mb-3" style="max-width:320px">
          <label for="totp_code" class="form-label fw-semibold">Krok 3 — wpisz 6-cyfrowy kod z aplikacji</label>
          <input type="text" id="totp_code" name="code" class="form-control tz-otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autofocus required>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="tz-btn"><i class="bi bi-shield-check" aria-hidden="true"></i> Potwierdź i włącz</button>
          <a href="<?= $SELF ?>" class="btn btn-outline-secondary">Anuluj</a>
        </div>
      </form>
      <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
      <script>QRCode.toCanvas(document.getElementById('qrcode-canvas'), <?= json_encode($totp_uri) ?>, {width:200}, function(e){if(e)console.error(e);});</script>
    <?php else: ?>
      <p class="text-muted small mb-3">Zainstaluj <strong>Microsoft Authenticator</strong> lub <strong>Google Authenticator</strong>, a następnie kliknij poniżej.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="totp_start">
        <button type="submit" class="tz-btn"><i class="bi bi-qr-code" aria-hidden="true"></i> Skonfiguruj aplikację TOTP</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<!-- SMS -->
<?php if ($sms_available): ?>
<section class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-chat-dots" aria-hidden="true"></i>
    <span>Kod SMS</span>
    <?php if ($current_method === 'sms'): ?><span class="tz-badge tz-badge--ok ms-auto"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aktywne</span><?php endif; ?>
  </div>
  <div class="tz-card__bd">
    <?php if ($current_method === 'sms'): ?>
      <p class="text-muted small mb-3">SMS 2FA jest aktywne. Numer: <strong><?= h($db_user['twofa_phone'] ?? '—') ?></strong></p>
      <form method="post" onsubmit="return confirm('Wyłączyć SMS 2FA?')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="sms_disable">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-minus me-1" aria-hidden="true"></i>Wyłącz SMS 2FA</button>
      </form>
    <?php elseif ($success === 'sms_sent' || $success === 'sms_fallback_email'): ?>
      <?php if ($success === 'sms_fallback_email'): ?>
      <div class="alert alert-warning py-2 small mb-3" role="alert"><i class="bi bi-envelope-exclamation me-1" aria-hidden="true"></i>Wysyłka SMS nie powiodła się — kod wysłano na e-mail konta.</div>
      <?php else: ?>
      <p class="small text-muted mb-3">Kod SMS wysłany na numer <strong><?= h($db_user['twofa_phone'] ?? '') ?></strong>. Ważny 5 minut.</p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="sms_confirm">
        <div class="mb-3" style="max-width:320px">
          <label for="sms_code" class="form-label fw-semibold">6-cyfrowy kod</label>
          <input type="text" id="sms_code" name="code" class="form-control tz-otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autofocus required>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="tz-btn"><i class="bi bi-shield-check" aria-hidden="true"></i> Potwierdź i włącz SMS 2FA</button>
          <a href="<?= $SELF ?>" class="btn btn-outline-secondary">Anuluj</a>
        </div>
      </form>
    <?php else: ?>
      <p class="text-muted small mb-3">Po włączeniu, przy każdym logowaniu hasłem wyślemy jednorazowy kod SMS na Twój numer.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_action" value="sms_start">
        <div class="mb-3" style="max-width:320px">
          <label for="twofa_phone" class="form-label fw-semibold">Numer telefonu</label>
          <div class="input-group">
            <span class="input-group-text">+48</span>
            <input type="tel" id="twofa_phone" name="twofa_phone" class="form-control" placeholder="600 100 200" value="<?= h($db_user['twofa_phone'] ?? '') ?>" required>
          </div>
        </div>
        <button type="submit" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i> Wyślij kod weryfikacyjny</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- ═══════════ WEBAUTHN / PASSKEYS ═══════════ -->
<section class="tz-card" id="webauthn">
  <div class="tz-card__hd">
    <i class="bi bi-fingerprint" aria-hidden="true"></i>
    <span>Klucze bezpieczeństwa / Passkeys <span style="font-size:.72rem;color:var(--tz-muted);font-weight:500">(WebAuthn / FIDO2)</span></span>
    <?php if ($wk_keys): ?>
    <span class="tz-badge tz-badge--ok ms-auto"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= count($wk_keys) ?> klucz<?= count($wk_keys) > 1 ? 'e' : '' ?></span>
    <?php endif; ?>
  </div>
  <div class="tz-card__bd">
    <p class="text-muted small mb-3">
      Klucze sprzętowe (YubiKey, FIDO2) lub wbudowane w urządzenie (Face ID, Touch ID, Windows Hello)
      są najsilniejszą metodą uwierzytelniania — odporną na phishing.
    </p>

    <?php if ($wk_keys): ?>
    <div class="mb-3">
      <?php foreach ($wk_keys as $k): ?>
      <div class="d-flex align-items-center gap-2 py-2 border-bottom">
        <i class="bi bi-usb-symbol text-muted" aria-hidden="true"></i>
        <div style="flex:1;min-width:0">
          <div class="fw-semibold" style="font-size:.9rem"><?= h($k['name']) ?></div>
          <div class="text-muted" style="font-size:.75rem">
            <?= $k['alg'] == -257 ? 'RSA-SHA256' : 'EC P-256' ?> ·
            dodano <?= h($k['created_at'] ? date('d.m.Y', strtotime($k['created_at'])) : '—') ?> ·
            użyty <?= h($k['last_used_at'] ? date('d.m.Y H:i', strtotime($k['last_used_at'])) : 'nigdy') ?>
          </div>
        </div>
        <form method="post" class="m-0" onsubmit="return confirm('Usunąć klucz «<?= h(addslashes($k['name'])) ?>»?')">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="webauthn_delete">
          <input type="hidden" name="cred_id" value="<?= (int)$k['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Dodaj nowy klucz -->
    <div class="d-flex gap-2 flex-wrap align-items-end mb-3">
      <div>
        <label for="wk_name" class="form-label fw-semibold" style="font-size:.85rem">Nazwa klucza</label>
        <input id="wk_name" type="text" class="form-control form-control-sm" placeholder="np. YubiKey 5, iPhone" maxlength="80" style="width:220px">
      </div>
      <button id="wk_btn" class="tz-btn" type="button" onclick="tzRegisterKey()">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Dodaj klucz / passkey
      </button>
    </div>
    <div id="wk_status" role="status" aria-live="polite"></div>
    <p class="text-muted" style="font-size:.78rem">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
      Jeśli Twoje urządzenie obsługuje Face ID / Touch ID / Windows Hello, zostanie ono zaproponowane automatycznie.
    </p>
  </div>
</section>

<input type="hidden" id="tz_csrf" value="<?= h(csrf_token()) ?>">

<script>
(function(){
function b64u(str){var s=str.replace(/-/g,'+').replace(/_/g,'/');while(s.length%4)s+='=';var b=atob(s),u=new Uint8Array(b.length);for(var i=0;i<b.length;i++)u[i]=b.charCodeAt(i);return u.buffer;}
function ab64u(buf){var b=new Uint8Array(buf),s='';for(var i=0;i<b.byteLength;i++)s+=String.fromCharCode(b[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
function wkStatus(msg,type){document.getElementById('wk_status').innerHTML='<div class="alert alert-'+type+' py-2 small mt-2">'+msg+'</div>';}

window.tzRegisterKey = async function(){
    var btn  = document.getElementById('wk_btn');
    var name = (document.getElementById('wk_name').value.trim()) || 'Klucz bezpieczeństwa';
    var csrf = document.getElementById('tz_csrf').value;
    btn.disabled = true;
    wkStatus('<span class="spinner-border spinner-border-sm me-2"></span>Inicjalizacja…', 'info');
    try {
        var r1 = await fetch('<?= APP_URL ?>/tozsamosc/api/webauthn_begin_register.php',
            {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf})});
        var d1 = await r1.json();
        if (!d1.ok) throw new Error(d1.message || 'Błąd inicjalizacji');
        var opts = d1.options;
        opts.challenge  = b64u(opts.challenge);
        opts.user.id    = b64u(opts.user.id);
        if (opts.excludeCredentials) opts.excludeCredentials = opts.excludeCredentials.map(function(c){return Object.assign({},c,{id:b64u(c.id)});});
        wkStatus('<span class="spinner-border spinner-border-sm me-2"></span>Dotknij klucz lub użyj biometryki…', 'info');
        var cred = await navigator.credentials.create({publicKey: opts});
        wkStatus('<span class="spinner-border spinner-border-sm me-2"></span>Zapisywanie…', 'info');
        var payload = {id:cred.id, rawId:ab64u(cred.rawId),
            clientDataJSON:ab64u(cred.response.clientDataJSON),
            attestationObject:ab64u(cred.response.attestationObject)};
        var r2 = await fetch('<?= APP_URL ?>/tozsamosc/api/webauthn_complete_register.php',
            {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf,response:payload,key_name:name})});
        var d2 = await r2.json();
        if (!d2.ok) throw new Error(d2.message || 'Błąd rejestracji');
        wkStatus('<i class="bi bi-check-circle-fill me-1"></i>Klucz zarejestrowany!', 'success');
        setTimeout(function(){location.reload();}, 1000);
    } catch(e) {
        btn.disabled = false;
        wkStatus('<i class="bi bi-exclamation-triangle-fill me-1"></i>' + (e.message || 'Błąd'), 'danger');
    }
};
})();
</script>

<?php include __DIR__ . '/_foot.php';
