<?php
/**
 * tozsamosc/step_up.php — Centralny punkt step-up authentication.
 *
 * Obsługuje wszystkie żądania podwyższenia poziomu zaufania sesji:
 *   Level 2 — TOTP aplikacja / kod SMS / kod zapasowy
 *   Level 3 — WebAuthn (klucz sprzętowy / passkey / biometria)
 *
 * Wywołanie: ?t=SIGNED_CHALLENGE_TOKEN
 * Powrót: redirect → challenge['ret'] po pomyślnej weryfikacji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tz_auth.php';
require_once dirname(__DIR__) . '/includes/totp.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';

auth_start();
if (!current_user()) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$sms_available = false;
try { require_once dirname(__DIR__) . '/includes/sms.php'; $sms_available = sms_is_enabled(); } catch (\Throwable $e) {}

$user    = current_user();
$db_user = db_one("SELECT * FROM users WHERE id=?", [(int)$user['id']]);
$errors  = [];
$success = '';
$info    = '';

// ── 1. Załaduj/zweryfikuj challenge ─────────────────────────────────────────

$challenge = $_SESSION['tz_pending_challenge'] ?? null;

if (!$challenge && isset($_GET['t'])) {
    try {
        // require_login(), żeby sesja była — challenge sprawdza uid
        $challenge = tz_verify_challenge($_GET['t']);
        $_SESSION['tz_pending_challenge'] = $challenge;
    } catch (\Throwable $e) {
        $challenge = null;
        $errors[] = 'Link weryfikacyjny jest nieprawidłowy lub wygasł: ' . $e->getMessage();
    }
}

if (!$challenge && !$errors) {
    // Brak tokenu — wróć do tozsamosc
    header('Location: ' . APP_URL . '/tozsamosc/index.php');
    exit;
}

$required_level = (int)($challenge['level'] ?? 2);
$return_url     = $challenge ? tz_validate_return_url($challenge['ret'] ?? '') : APP_URL . '/tozsamosc/index.php';
$context_label  = $challenge['ctx'] ?? tz_module_label_from_url($return_url);

// Czy poziom już spełniony? (np. po wcześniejszej weryfikacji w tej sesji)
if (tz_auth_level() >= $required_level) {
    unset($_SESSION['tz_pending_challenge']);
    header('Location: ' . $return_url);
    exit;
}

// ── 2. Dostępne metody dla wymaganego poziomu ────────────────────────────────

$current_method = $db_user['twofa_method'] ?? '';
$has_totp   = ($db_user['totp_confirmed'] ?? 0) && ($current_method === 'totp' || $db_user['totp_secret'] ?? '');
$has_sms    = $sms_available && ($current_method === 'sms') && ($db_user['twofa_phone'] ?? '');
$has_backup = (bool)($db_user['totp_backup_codes'] ?? '');
$wk_keys    = webauthn_get_credentials((int)$user['id']);
$has_wk     = !empty($wk_keys);

// Level 2: TOTP lub SMS lub backup code
// Level 3: WebAuthn (hardware key / passkey)

// ── 3. Obsługa POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $challenge) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // ─ TOTP ─
    if ($action === 'totp_verify' && $required_level <= 2 && $has_totp) {
        $code = trim($_POST['code'] ?? '');
        $secret = $db_user['totp_secret'] ?? '';
        if (!$secret) {
            $errors[] = 'TOTP nie jest skonfigurowane na tym koncie.';
        } elseif (!TOTP::verify($secret, $code)) {
            $errors[] = 'Nieprawidłowy kod TOTP. Sprawdź czas w aplikacji.';
        } else {
            tz_grant_level(2);
            log_user_action((int)$user['id'], (int)$user['id'],
                'tz_step_up_totp', 'Step-up TOTP (level 2) → ' . $context_label);
            unset($_SESSION['tz_pending_challenge']);
            header('Location: ' . $return_url);
            exit;
        }
    }

    // ─ SMS: wyślij ─
    elseif ($action === 'sms_send' && $required_level <= 2 && $has_sms) {
        $phone = $db_user['twofa_phone'];
        try {
            $otp = sms_generate_otp($phone, (int)$user['id']);
            $via = sms_send_with_fallback($phone, "Kod weryfikacyjny: {$otp} (ważny 5 min)", $user['email'] ?? '');
            $_SESSION['tz_stepup_sms_sent'] = true;
            $info = $via === 'email'
                ? 'SMS nie przeszedł — kod wysłano na e-mail konta.'
                : 'Kod SMS wysłany na ' . tz_mask_phone($phone) . '. Ważny 5 min.';
        } catch (\Throwable $e) {
            $errors[] = 'Błąd wysyłki SMS: ' . $e->getMessage();
        }
    }

    // ─ SMS: zweryfikuj ─
    elseif ($action === 'sms_verify' && $required_level <= 2 && $has_sms) {
        $phone = $db_user['twofa_phone'];
        $code  = trim($_POST['code'] ?? '');
        $ok    = sms_verify_otp($phone, $code);
        if ($ok && (int)$ok['id'] === (int)$user['id']) {
            tz_grant_level(2);
            unset($_SESSION['tz_pending_challenge'], $_SESSION['tz_stepup_sms_sent']);
            log_user_action((int)$user['id'], (int)$user['id'],
                'tz_step_up_sms', 'Step-up SMS (level 2) → ' . $context_label);
            header('Location: ' . $return_url);
            exit;
        } else {
            $errors[] = 'Nieprawidłowy lub wygasły kod SMS.';
        }
    }

    // ─ Kod zapasowy ─
    elseif ($action === 'backup_verify' && $required_level <= 2 && $has_backup) {
        $entered = strtoupper(trim(str_replace([' ', '-'], '', $_POST['code'] ?? '')));
        $raw     = $db_user['totp_backup_codes'] ?? '';
        $codes   = json_decode($raw, true) ?: [];
        $found   = false;
        $new_codes = [];
        foreach ($codes as $c) {
            $c_clean = strtoupper(str_replace('-', '', $c));
            if (!$found && hash_equals($c_clean, $entered)) {
                $found = true; // usuń — jednorazowy
            } else {
                $new_codes[] = $c;
            }
        }
        if ($found) {
            db()->prepare("UPDATE users SET totp_backup_codes=? WHERE id=?")
                ->execute([json_encode($new_codes), (int)$user['id']]);
            tz_grant_level(2);
            unset($_SESSION['tz_pending_challenge']);
            log_user_action((int)$user['id'], (int)$user['id'],
                'tz_step_up_backup', 'Step-up kodem zapasowym (level 2) → ' . $context_label);
            header('Location: ' . $return_url);
            exit;
        } else {
            $errors[] = 'Kod zapasowy jest nieprawidłowy lub już został użyty.';
        }
    }
}

// ── 4. Widok ─────────────────────────────────────────────────────────────────

$sms_form_shown = !empty($_SESSION['tz_stepup_sms_sent']) || (isset($info) && $info !== '');

$PAGE_TITLE = 'Weryfikacja tożsamości';
$TZ_ACTIVE  = '';
include __DIR__ . '/_head.php';
?>

<div class="tz-h">
  <h1>Weryfikacja tożsamości</h1>
  <p>
    <?php if ($context_label && $context_label !== 'moduł'): ?>
      <strong><?= h($context_label) ?></strong> wymaga
    <?php else: ?>
      Wymagany
    <?php endif; ?>
    dodatkowego potwierdzenia tożsamości ·
    <span style="color:var(--tz);font-weight:600"><?= h(tz_level_label($required_level)) ?></span>
  </p>
</div>

<div aria-live="assertive" aria-atomic="true">
<?php if ($errors): ?>
  <div class="alert alert-danger d-flex gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($info): ?>
  <div class="alert alert-info d-flex gap-2" role="status">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
    <span><?= h($info) ?></span>
  </div>
<?php endif; ?>
</div>

<?php if ($challenge && !$errors): ?>

<?php /* ════ LEVEL 3: WEBAUTHN ════ */ ?>
<?php if ($required_level >= 3): ?>
  <?php if (!$has_wk): ?>
    <div class="tz-card">
      <div class="tz-card__hd"><i class="bi bi-exclamation-octagon" style="color:#c2410c" aria-hidden="true"></i>Brak kluczy sprzętowych</div>
      <div class="tz-card__bd">
        <p>Dostęp do <strong><?= h($context_label) ?></strong> wymaga klucza sprzętowego / passkey (WebAuthn), ale nie masz żadnego zarejestrowanego.</p>
        <a href="<?= APP_URL ?>/tozsamosc/mfa.php#webauthn" class="tz-btn"><i class="bi bi-plus-circle" aria-hidden="true"></i> Dodaj klucz w Tożsamości</a>
        <a href="<?= h($return_url) ?>" class="tz-btn tz-btn--ghost ms-2"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć</a>
      </div>
    </div>
  <?php else: ?>
    <section class="tz-card">
      <div class="tz-card__hd">
        <i class="bi bi-fingerprint" aria-hidden="true"></i>
        <span>Użyj klucza sprzętowego lub passkey</span>
      </div>
      <div class="tz-card__bd">
        <p class="text-muted small mb-3">
          Dotknij klucz YubiKey lub użyj biometryki urządzenia (Face ID, Touch ID, Windows Hello).
          Masz <?= count($wk_keys) ?> zarejestrowany<?= count($wk_keys) > 1 ? 'ch kluczy' : ' klucz' ?>.
        </p>
        <button id="wk_btn" class="tz-btn" type="button" onclick="tzStepUpWebAuthn()">
          <i class="bi bi-fingerprint" aria-hidden="true"></i> Potwierdź kluczem sprzętowym
        </button>
        <div id="wk_status" role="status" aria-live="polite" class="mt-3"></div>
      </div>
    </section>
  <?php endif; ?>

<?php /* ════ LEVEL 2: MFA ════ */ ?>
<?php else: ?>

  <?php $any_method = $has_totp || $has_sms || $has_backup; ?>
  <?php if (!$any_method): ?>
    <div class="tz-card">
      <div class="tz-card__hd"><i class="bi bi-shield-exclamation" style="color:#c2410c" aria-hidden="true"></i>Brak metod MFA</div>
      <div class="tz-card__bd">
        <p>Dostęp wymaga MFA (poziom 2), ale nie masz skonfigurowanej żadnej metody weryfikacji.</p>
        <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="tz-btn"><i class="bi bi-shield-plus" aria-hidden="true"></i> Skonfiguruj MFA</a>
        <a href="<?= h($return_url) ?>" class="tz-btn tz-btn--ghost ms-2"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć</a>
      </div>
    </div>
  <?php else: ?>

  <?php /* ─ TOTP ─ */ ?>
  <?php if ($has_totp): ?>
  <section class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-phone" aria-hidden="true"></i>
      <span>Aplikacja Authenticator (TOTP)</span>
      <span class="tz-badge tz-badge--ok ms-auto"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Skonfigurowane</span>
    </div>
    <div class="tz-card__bd">
      <p class="text-muted small mb-3">Otwórz Microsoft Authenticator lub Google Authenticator i wpisz aktualny 6-cyfrowy kod.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="totp_verify">
        <div class="mb-3" style="max-width:300px">
          <label for="totp_code" class="form-label fw-semibold">Kod jednorazowy</label>
          <input type="text" id="totp_code" name="code" class="form-control tz-otp"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 placeholder="000000" autocomplete="one-time-code" autofocus required>
        </div>
        <button type="submit" class="tz-btn"><i class="bi bi-shield-check" aria-hidden="true"></i> Potwierdź</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <?php /* ─ SMS ─ */ ?>
  <?php if ($has_sms): ?>
  <section class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-chat-dots" aria-hidden="true"></i>
      <span>Kod SMS</span>
      <span class="tz-badge tz-badge--ok ms-auto"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Skonfigurowane</span>
    </div>
    <div class="tz-card__bd">
      <?php if ($sms_form_shown): ?>
        <p class="text-muted small mb-3">Wpisz 6-cyfrowy kod wysłany na numer
          <strong><?= h(function_exists('tz_mask_phone') ? tz_mask_phone($db_user['twofa_phone'] ?? '') : '***') ?></strong>. Ważny 5 minut.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="sms_verify">
          <div class="mb-3" style="max-width:300px">
            <label for="sms_code" class="form-label fw-semibold">Kod SMS</label>
            <input type="text" id="sms_code" name="code" class="form-control tz-otp"
                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="000000" autocomplete="one-time-code" required>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="tz-btn"><i class="bi bi-shield-check" aria-hidden="true"></i> Potwierdź</button>
            <button type="submit" form="sms_resend_form" class="tz-btn tz-btn--ghost"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Wyślij ponownie</button>
          </div>
        </form>
        <form method="post" id="sms_resend_form">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="sms_send">
        </form>
      <?php else: ?>
        <p class="text-muted small mb-3">Wyślemy jednorazowy kod na numer
          <strong><?= h(function_exists('tz_mask_phone') ? tz_mask_phone($db_user['twofa_phone'] ?? '') : '***') ?></strong>.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="sms_send">
          <button type="submit" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i> Wyślij kod SMS</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php /* ─ Kod zapasowy ─ */ ?>
  <?php if ($has_backup): ?>
  <details class="tz-card" style="cursor:pointer">
    <summary class="tz-card__hd" style="list-style:none;cursor:pointer">
      <i class="bi bi-key" aria-hidden="true"></i>
      <span>Użyj kodu zapasowego</span>
      <i class="bi bi-chevron-down ms-auto" style="font-size:.8rem;color:var(--tz-muted)" aria-hidden="true"></i>
    </summary>
    <div class="tz-card__bd">
      <p class="text-muted small mb-3">Kody zapasowe są jednorazowe. Użyj tylko gdy nie masz dostępu do normalnej metody MFA.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="backup_verify">
        <div class="mb-3" style="max-width:320px">
          <label for="backup_code" class="form-label fw-semibold">Kod zapasowy</label>
          <input type="text" id="backup_code" name="code" class="form-control font-monospace"
                 placeholder="XXXX-XXXX" autocomplete="off" required>
        </div>
        <button type="submit" class="tz-btn tz-btn--ghost"><i class="bi bi-key" aria-hidden="true"></i> Użyj kodu zapasowego</button>
      </form>
    </div>
  </details>
  <?php endif; ?>

  <?php endif; // $any_method ?>
<?php endif; // required_level ?>

<!-- Anuluj -->
<div class="mt-3">
  <a href="<?= h($return_url) ?>" class="btn btn-link text-muted" style="font-size:.85rem">
    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anuluj weryfikację i wróć
  </a>
</div>

<?php endif; // $challenge && !$errors ?>

<?php if ($required_level >= 3 && $has_wk): ?>
<input type="hidden" id="tz_csrf" value="<?= h(csrf_token()) ?>">
<script>
(function(){
function b64u(s){var x=s.replace(/-/g,'+').replace(/_/g,'/');while(x.length%4)x+='=';var b=atob(x),u=new Uint8Array(b.length);for(var i=0;i<b.length;i++)u[i]=b.charCodeAt(i);return u.buffer;}
function ab64u(buf){var b=new Uint8Array(buf),s='';for(var i=0;i<b.byteLength;i++)s+=String.fromCharCode(b[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
function wkStatus(msg,type){
    var d=document.getElementById('wk_status');
    if(d) d.innerHTML='<div class="alert alert-'+type+' py-2 small">'+msg+'</div>';
}
window.tzStepUpWebAuthn = async function(){
    var btn=document.getElementById('wk_btn');
    var csrf=document.getElementById('tz_csrf').value;
    if(btn) btn.disabled=true;
    wkStatus('<span class="spinner-border spinner-border-sm me-2"></span>Inicjalizacja…','info');
    try {
        var r1=await fetch('<?= APP_URL ?>/tozsamosc/api/webauthn_begin_auth.php',
            {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf})});
        var d1=await r1.json();
        if(!d1.ok) throw new Error(d1.message||'Błąd inicjalizacji');
        var opts=d1.options;
        opts.challenge=b64u(opts.challenge);
        if(opts.allowCredentials) opts.allowCredentials=opts.allowCredentials.map(function(c){return Object.assign({},c,{id:b64u(c.id)});});
        wkStatus('<span class="spinner-border spinner-border-sm me-2"></span>Dotknij klucz lub użyj biometryki…','info');
        var cred=await navigator.credentials.get({publicKey:opts});
        wkStatus('<span class="spinner-border spinner-border-sm me-2"></span>Weryfikacja…','info');
        var payload={id:cred.id,rawId:ab64u(cred.rawId),
            clientDataJSON:ab64u(cred.response.clientDataJSON),
            authenticatorData:ab64u(cred.response.authenticatorData),
            signature:ab64u(cred.response.signature),
            userHandle:cred.response.userHandle?ab64u(cred.response.userHandle):null};
        var r2=await fetch('<?= APP_URL ?>/tozsamosc/api/webauthn_complete_auth.php',
            {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf,response:payload})});
        var d2=await r2.json();
        if(!d2.ok) throw new Error(d2.message||'Błąd weryfikacji');
        wkStatus('<i class="bi bi-check-circle-fill me-1"></i>Zweryfikowano! Przenoszę…','success');
        setTimeout(function(){window.location=d2.redirect||'<?= APP_URL ?>/tozsamosc/index.php';},600);
    } catch(e){
        if(btn) btn.disabled=false;
        wkStatus('<i class="bi bi-exclamation-triangle-fill me-1"></i>'+(e.message||'Błąd weryfikacji'),'danger');
    }
};
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/_foot.php'; ?>
