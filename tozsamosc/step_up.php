<?php
/**
 * tozsamosc/step_up.php — Step-up authentication z wyborem metody.
 *
 * Przepływ:
 *   1. Moduł wywołuje tz_require_level(2|3) → redirect tutaj z signed token
 *   2. Użytkownik WYBIERA metodę (picker)
 *   3. Użytkownik wpisuje kod / dotyka klucza
 *   4. Sukces → tz_grant_level() + redirect do return_url
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tz_auth.php';
require_once dirname(__DIR__) . '/includes/totp.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';

auth_start();
if (!current_user()) { header('Location: ' . APP_URL . '/auth/login.php'); exit; }

$sms_available = false;
try { require_once dirname(__DIR__) . '/includes/sms.php'; $sms_available = sms_is_enabled(); } catch (\Throwable $e) {}

$user    = current_user();
$db_user = db_one("SELECT * FROM users WHERE id=?", [(int)$user['id']]);
$errors  = [];

// ── Załaduj / zweryfikuj challenge ───────────────────────────────────────────

$challenge = $_SESSION['tz_pending_challenge'] ?? null;

if (!$challenge && isset($_GET['t'])) {
    try {
        $challenge = tz_verify_challenge($_GET['t']);
        $_SESSION['tz_pending_challenge'] = $challenge;
    } catch (\Throwable $e) {
        $challenge = null;
        $errors[]  = $e->getMessage();
    }
}

if (!$challenge && !$errors) {
    header('Location: ' . APP_URL . '/tozsamosc/index.php');
    exit;
}

$required_level = (int)($challenge['level'] ?? 2);
$return_url     = $challenge ? tz_validate_return_url($challenge['ret'] ?? '') : APP_URL . '/tozsamosc/index.php';
$context_label  = $challenge['ctx'] ?? tz_module_label_from_url($return_url);

// Poziom już spełniony (np. wcześniej zweryfikowany)
if ($challenge && tz_auth_level() >= $required_level) {
    unset($_SESSION['tz_pending_challenge']);
    header('Location: ' . $return_url);
    exit;
}

// ── Dostępne metody ──────────────────────────────────────────────────────────

$current_method = $db_user['twofa_method'] ?? '';
$has_totp   = !empty($db_user['totp_confirmed']) && !empty($db_user['totp_secret']);
$has_sms    = $sms_available && ($current_method === 'sms') && !empty($db_user['twofa_phone']);
$has_backup = !empty($db_user['totp_backup_codes'])
              && count(json_decode($db_user['totp_backup_codes'] ?? '[]', true) ?: []) > 0;
$wk_keys    = webauthn_get_credentials((int)$user['id']);
$has_wk     = !empty($wk_keys);

// ── POST: weryfikacja ────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $challenge) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'totp_verify' && $has_totp) {
        $code = trim($_POST['code'] ?? '');
        if (!TOTP::verify($db_user['totp_secret'], $code)) {
            $errors[] = 'Nieprawidłowy kod TOTP. Sprawdź czas w aplikacji i spróbuj ponownie.';
        } else {
            _step_up_success(TZ_LEVEL_MFA, 'totp', $context_label, $return_url);
        }
    }
    elseif ($action === 'sms_send' && $has_sms) {
        try {
            $otp = sms_generate_otp($db_user['twofa_phone'], (int)$user['id']);
            $via = sms_send_with_fallback($db_user['twofa_phone'],
                "Kod weryfikacyjny: {$otp} (ważny 5 min)", $user['email'] ?? '');
            $_SESSION['tz_stepup_sms_sent'] = true;
            $_SESSION['tz_stepup_active']   = 'sms';
        } catch (\Throwable $e) {
            $errors[] = 'Błąd wysyłki SMS: ' . $e->getMessage();
        }
    }
    elseif ($action === 'sms_verify' && $has_sms) {
        $ok = sms_verify_otp($db_user['twofa_phone'], trim($_POST['code'] ?? ''));
        if ($ok && (int)$ok['id'] === (int)$user['id']) {
            unset($_SESSION['tz_stepup_sms_sent']);
            _step_up_success(TZ_LEVEL_MFA, 'sms', $context_label, $return_url);
        } else {
            $errors[] = 'Nieprawidłowy lub wygasły kod SMS.';
            $_SESSION['tz_stepup_active'] = 'sms';
        }
    }
    elseif ($action === 'backup_verify' && $has_backup) {
        $entered   = strtoupper(trim(str_replace([' ', '-'], '', $_POST['code'] ?? '')));
        $codes     = json_decode($db_user['totp_backup_codes'] ?? '[]', true) ?: [];
        $new_codes = [];
        $found     = false;
        foreach ($codes as $c) {
            if (!$found && hash_equals(strtoupper(str_replace('-', '', $c)), $entered)) {
                $found = true;
            } else {
                $new_codes[] = $c;
            }
        }
        if ($found) {
            db()->prepare("UPDATE users SET totp_backup_codes=? WHERE id=?")
                ->execute([json_encode($new_codes), (int)$user['id']]);
            _step_up_success(TZ_LEVEL_MFA, 'backup_code', $context_label, $return_url);
        } else {
            $errors[] = 'Kod zapasowy jest nieprawidłowy lub już został użyty.';
            $_SESSION['tz_stepup_active'] = 'backup';
        }
    }
}

function _step_up_success(int $level, string $method, string $ctx, string $return_url): never {
    global $user;
    tz_grant_level($level);
    log_user_action((int)$user['id'], (int)$user['id'],
        'tz_step_up_' . $method, "Step-up {$method} (level {$level}) → {$ctx}");
    unset($_SESSION['tz_pending_challenge'], $_SESSION['tz_stepup_sms_sent'], $_SESSION['tz_stepup_active']);
    header('Location: ' . $return_url);
    exit;
}

// Aktywna metoda po SMS send lub błędzie
$active_method = $_SESSION['tz_stepup_active'] ?? (
    $errors && isset($_POST['_action']) ? (str_starts_with($_POST['_action'], 'totp') ? 'totp'
        : (str_starts_with($_POST['_action'], 'sms') ? 'sms'
        : (str_starts_with($_POST['_action'], 'backup') ? 'backup' : ''))) : ''
);

// ── Widok ────────────────────────────────────────────────────────────────────

$PAGE_TITLE = 'Weryfikacja';
$TZ_ACTIVE  = '';
include __DIR__ . '/_head.php';

// Ikonki i opisy metod — WebAuthn spełnia też level 2 (jest silniejszy)
$methods_level2 = [];
if ($has_totp)   $methods_level2[] = ['key'=>'totp',     'icon'=>'bi-phone',       'color'=>'#2563eb', 'label'=>'Aplikacja Authenticator', 'sub'=>'Kod jednorazowy z aplikacji Microsoft / Google Authenticator'];
if ($has_sms)    $methods_level2[] = ['key'=>'sms',      'icon'=>'bi-chat-dots',   'color'=>'#16a34a', 'label'=>'Kod SMS',                  'sub'=>'Wyślemy kod na ' . tz_mask_phone($db_user['twofa_phone'] ?? '')];
if ($has_backup) $methods_level2[] = ['key'=>'backup',   'icon'=>'bi-key',         'color'=>'#92400e', 'label'=>'Kod zapasowy',             'sub'=>'Jednorazowy kod z listy kodów awaryjnych'];
if ($has_wk)     $methods_level2[] = ['key'=>'webauthn', 'icon'=>'bi-fingerprint', 'color'=>'#047857', 'label'=>'Klucz sprzętowy / Passkey','sub'=>'YubiKey, biometria (Face ID, Touch ID, Windows Hello)'];
?>

<style>
.su-ctx{border-left:3px solid var(--tz);padding:.4rem .85rem;margin-bottom:1.1rem;background:var(--tz-50);border-radius:0 8px 8px 0}
.su-ctx small{font-size:.72rem;color:var(--tz-muted);display:block;text-transform:uppercase;letter-spacing:.05em;font-weight:600}
.su-ctx strong{font-size:.95rem;color:#111827;font-weight:700}
.su-picker{display:flex;flex-direction:column;gap:.55rem;margin-bottom:1rem}
.su-method{display:flex;align-items:center;gap:1rem;padding:.9rem 1.1rem;border:1.5px solid var(--tz-line);border-radius:10px;background:#fff;cursor:pointer;text-align:left;transition:border-color .13s,box-shadow .13s;width:100%}
.su-method:hover,.su-method:focus-visible{border-color:var(--tz);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}
.su-method__ico{width:40px;height:40px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff}
.su-method__txt strong{display:block;font-size:.92rem;font-weight:600;color:#111827}
.su-method__txt span{font-size:.78rem;color:var(--tz-muted)}
.su-method__arr{margin-left:auto;color:var(--tz-muted);font-size:.85rem}
.su-form{display:none;animation:tzfade .15s ease}
.su-form.active{display:block}
.su-back{font-size:.83rem;color:var(--tz-muted);text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;margin-bottom:.9rem;cursor:pointer;background:none;border:none;padding:0}
.su-back:hover{color:var(--tz)}
</style>

<div class="tz-h" style="margin-bottom:.75rem">
  <h1>Weryfikacja tożsamości</h1>
</div>

<?php if ($context_label): ?>
<div class="su-ctx" aria-label="Kontekst żądania">
  <small>Żądanie pochodzi z</small>
  <strong><?= h($context_label) ?></strong>
</div>
<?php endif; ?>

<div aria-live="assertive" aria-atomic="true">
<?php if ($errors): ?>
  <div class="alert alert-danger d-flex gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
</div>

<?php if (!$challenge || $errors && !$challenge): ?>
  <a href="<?= APP_URL ?>/tozsamosc/index.php" class="tz-btn tz-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do Tożsamości</a>
<?php elseif ($required_level >= 3): ?>

  <?php /* ════ LEVEL 3 — WebAuthn (bez pickera, tylko jedna metoda) ════ */ ?>
  <?php if (!$has_wk): ?>
    <div class="tz-card">
      <div class="tz-card__hd"><i class="bi bi-shield-exclamation" style="color:#c2410c" aria-hidden="true"></i>Brak kluczy sprzętowych</div>
      <div class="tz-card__bd">
        <p class="mb-3">Dostęp do <strong><?= h($context_label) ?></strong> wymaga klucza sprzętowego lub passkey. Nie masz żadnego zarejestrowanego.</p>
        <a href="<?= APP_URL ?>/tozsamosc/mfa.php#webauthn" class="tz-btn"><i class="bi bi-plus-circle" aria-hidden="true"></i> Dodaj klucz / passkey</a>
        <a href="<?= h($return_url) ?>" class="tz-btn tz-btn--ghost ms-2"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć</a>
      </div>
    </div>
  <?php else: ?>
    <div class="tz-card">
      <div class="tz-card__hd">
        <i class="bi bi-fingerprint" style="color:#047857" aria-hidden="true"></i>
        <span>Klucz sprzętowy / Passkey</span>
        <span class="tz-badge tz-badge--ok ms-auto"><?= count($wk_keys) ?> klucz<?= count($wk_keys) > 1 ? 'e' : '' ?></span>
      </div>
      <div class="tz-card__bd">
        <p class="text-muted small mb-3">Dotknij klucz YubiKey / FIDO2 lub użyj biometryki urządzenia (Face ID, Touch ID, Windows Hello).</p>
        <?php foreach ($wk_keys as $k): ?>
          <div class="d-flex align-items-center gap-2 mb-2 text-muted" style="font-size:.82rem">
            <i class="bi bi-usb-symbol" aria-hidden="true"></i>
            <span><?= h($k['name']) ?></span>
            <span class="text-muted">· użyty <?= h($k['last_used_at'] ? date('d.m.Y', strtotime($k['last_used_at'])) : 'nigdy') ?></span>
          </div>
        <?php endforeach; ?>
        <button id="wk_btn" class="tz-btn mt-2" type="button" onclick="tzStepUpWebAuthn()">
          <i class="bi bi-fingerprint" aria-hidden="true"></i> Potwierdź kluczem sprzętowym
        </button>
        <div id="wk_status" role="status" aria-live="polite" class="mt-3"></div>
      </div>
    </div>
  <?php endif; ?>

<?php else: /* ════ LEVEL 2 — picker metod ════ */ ?>

  <?php if (empty($methods_level2)): ?>
    <div class="tz-card">
      <div class="tz-card__hd"><i class="bi bi-shield-exclamation" style="color:#c2410c" aria-hidden="true"></i>Brak metod MFA</div>
      <div class="tz-card__bd">
        <p class="mb-3">Dostęp do <strong><?= h($context_label) ?></strong> wymaga MFA. Skonfiguruj metodę weryfikacji w Tożsamości.</p>
        <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="tz-btn"><i class="bi bi-shield-plus" aria-hidden="true"></i> Skonfiguruj MFA</a>
        <a href="<?= h($return_url) ?>" class="tz-btn tz-btn--ghost ms-2"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć</a>
      </div>
    </div>
  <?php else: ?>

    <!-- Picker -->
    <div id="su-picker-wrap">
      <p class="text-muted mb-3" style="font-size:.88rem">Wybierz, w jaki sposób chcesz potwierdzić swoją tożsamość:</p>
      <div class="su-picker" role="list">
        <?php foreach ($methods_level2 as $m): ?>
        <button class="su-method" type="button"
                onclick="suShowMethod('<?= $m['key'] ?>')"
                role="listitem"
                aria-label="Użyj metody: <?= h($m['label']) ?>">
          <span class="su-method__ico" style="background:<?= $m['color'] ?>">
            <i class="bi <?= $m['icon'] ?>" aria-hidden="true"></i>
          </span>
          <span class="su-method__txt">
            <strong><?= h($m['label']) ?></strong>
            <span><?= h($m['sub']) ?></span>
          </span>
          <i class="bi bi-chevron-right su-method__arr" aria-hidden="true"></i>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Formularze (ukryte, odsłaniane przez JS) -->

    <?php /* TOTP */ ?>
    <?php if ($has_totp): ?>
    <div class="su-form <?= $active_method === 'totp' ? 'active' : '' ?>" id="su-form-totp">
      <button type="button" class="su-back" onclick="suBack()" aria-label="Powrót do wyboru metody">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Inne metody
      </button>
      <div class="tz-card">
        <div class="tz-card__hd">
          <i class="bi bi-phone" style="color:#2563eb" aria-hidden="true"></i>
          <span>Aplikacja Authenticator</span>
        </div>
        <div class="tz-card__bd">
          <p class="text-muted small mb-3">Otwórz Microsoft Authenticator lub Google Authenticator i wpisz aktualny 6-cyfrowy kod dla <strong><?= h(defined('ORG_NAME') ? ORG_NAME : 'organizacji') ?></strong>.</p>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="totp_verify">
            <div class="mb-3" style="max-width:260px">
              <label for="totp_code" class="form-label fw-semibold">Kod jednorazowy</label>
              <input type="text" id="totp_code" name="code" class="form-control tz-otp"
                     inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                     placeholder="000000" autocomplete="one-time-code"
                     <?= $active_method === 'totp' ? 'autofocus' : '' ?> required>
            </div>
            <button type="submit" class="tz-btn">
              <i class="bi bi-shield-check" aria-hidden="true"></i> Potwierdź
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php /* SMS */ ?>
    <?php if ($has_sms): ?>
    <div class="su-form <?= $active_method === 'sms' ? 'active' : '' ?>" id="su-form-sms">
      <button type="button" class="su-back" onclick="suBack()" aria-label="Powrót do wyboru metody">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Inne metody
      </button>
      <div class="tz-card">
        <div class="tz-card__hd">
          <i class="bi bi-chat-dots" style="color:#16a34a" aria-hidden="true"></i>
          <span>Kod SMS</span>
        </div>
        <div class="tz-card__bd">
          <?php if (!empty($_SESSION['tz_stepup_sms_sent']) || $active_method === 'sms'): ?>
            <p class="text-muted small mb-3">
              Wpisz 6-cyfrowy kod wysłany na
              <strong><?= h(tz_mask_phone($db_user['twofa_phone'])) ?></strong>.
              Ważny 5 minut.
            </p>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action" value="sms_verify">
              <div class="mb-3" style="max-width:260px">
                <label for="sms_code" class="form-label fw-semibold">Kod SMS</label>
                <input type="text" id="sms_code" name="code" class="form-control tz-otp"
                       inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       placeholder="000000" autocomplete="one-time-code"
                       <?= $active_method === 'sms' ? 'autofocus' : '' ?> required>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="tz-btn"><i class="bi bi-shield-check" aria-hidden="true"></i> Potwierdź</button>
                <button type="submit" form="su-sms-resend" class="tz-btn tz-btn--ghost">
                  <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Wyślij ponownie
                </button>
              </div>
            </form>
            <form method="post" id="su-sms-resend">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action" value="sms_send">
            </form>
          <?php else: ?>
            <p class="text-muted small mb-3">
              Wyślemy jednorazowy kod na
              <strong><?= h(tz_mask_phone($db_user['twofa_phone'])) ?></strong>.
            </p>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action" value="sms_send">
              <button type="submit" class="tz-btn">
                <i class="bi bi-send" aria-hidden="true"></i> Wyślij kod SMS
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php /* Kod zapasowy */ ?>
    <?php if ($has_backup): ?>
    <div class="su-form <?= $active_method === 'backup' ? 'active' : '' ?>" id="su-form-backup">
      <button type="button" class="su-back" onclick="suBack()" aria-label="Powrót do wyboru metody">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Inne metody
      </button>
      <div class="tz-card" style="border-color:#d97706">
        <div class="tz-card__hd">
          <i class="bi bi-key" style="color:#92400e" aria-hidden="true"></i>
          <span>Kod zapasowy</span>
          <span class="tz-badge tz-badge--warn ms-auto"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> Jednorazowy</span>
        </div>
        <div class="tz-card__bd">
          <p class="text-muted small mb-3">Każdy kod zapasowy działa tylko raz. Użyj go gdy nie masz dostępu do normalnej metody MFA.</p>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="backup_verify">
            <div class="mb-3" style="max-width:280px">
              <label for="backup_code" class="form-label fw-semibold">Kod zapasowy</label>
              <input type="text" id="backup_code" name="code" class="form-control font-monospace"
                     placeholder="XXXX-XXXX" autocomplete="off"
                     <?= $active_method === 'backup' ? 'autofocus' : '' ?> required>
            </div>
            <button type="submit" class="tz-btn tz-btn--ghost">
              <i class="bi bi-key" aria-hidden="true"></i> Użyj kodu zapasowego
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php /* Klucz sprzętowy (WebAuthn — spełnia też level 2) */ ?>
    <?php if ($has_wk): ?>
    <div class="su-form <?= $active_method === 'webauthn' ? 'active' : '' ?>" id="su-form-webauthn">
      <button type="button" class="su-back" onclick="suBack()" aria-label="Powrót do wyboru metody">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Inne metody
      </button>
      <div class="tz-card">
        <div class="tz-card__hd">
          <i class="bi bi-fingerprint" style="color:#047857" aria-hidden="true"></i>
          <span>Klucz sprzętowy / Passkey</span>
          <span class="tz-badge tz-badge--ok ms-auto"><?= count($wk_keys) ?> klucz<?= count($wk_keys) > 1 ? 'e' : '' ?></span>
        </div>
        <div class="tz-card__bd">
          <p class="text-muted small mb-3">Dotknij klucz YubiKey / FIDO2 lub użyj biometryki urządzenia (Face ID, Touch ID, Windows Hello).</p>
          <button id="wk_btn" class="tz-btn mt-2" type="button" onclick="tzStepUpWebAuthn()">
            <i class="bi bi-fingerprint" aria-hidden="true"></i> Potwierdź kluczem sprzętowym
          </button>
          <div id="wk_status" role="status" aria-live="polite" class="mt-3"></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  <?php endif; // $methods_level2 ?>
<?php endif; // required_level ?>

<!-- Anuluj -->
<?php if ($challenge): ?>
<div class="mt-3 pt-1">
  <a href="<?= h($return_url) ?>" class="text-muted" style="font-size:.82rem;text-decoration:none">
    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anuluj i wróć do <?= h($context_label ?: 'modułu') ?>
  </a>
</div>
<?php endif; ?>

<script>
(function(){
  var picker = document.getElementById('su-picker-wrap');
  var forms  = document.querySelectorAll('.su-form');

  window.suShowMethod = function(key) {
    if (picker) picker.style.display = 'none';
    forms.forEach(function(f){ f.classList.remove('active'); });
    var target = document.getElementById('su-form-' + key);
    if (target) {
      target.classList.add('active');
      // Ustaw focus na pierwszy input
      var inp = target.querySelector('input:not([type=hidden]),button[type=submit]');
      if (inp) setTimeout(function(){ inp.focus(); }, 50);
    }
  };

  window.suBack = function() {
    forms.forEach(function(f){ f.classList.remove('active'); });
    if (picker) {
      picker.style.display = '';
      // Focus na pierwszy przycisk pickera
      var first = picker.querySelector('.su-method');
      if (first) first.focus();
    }
  };

  // Jeśli jest aktywna metoda (po błędzie/POST) — ukryj picker
  var active = document.querySelector('.su-form.active');
  if (active && picker) picker.style.display = 'none';

<?php if ($has_wk): ?>
  function b64u(s){var x=s.replace(/-/g,'+').replace(/_/g,'/');while(x.length%4)x+='=';var b=atob(x),u=new Uint8Array(b.length);for(var i=0;i<b.length;i++)u[i]=b.charCodeAt(i);return u.buffer;}
  function ab64u(buf){var b=new Uint8Array(buf),s='';for(var i=0;i<b.byteLength;i++)s+=String.fromCharCode(b[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
  function wkSt(msg,type){var d=document.getElementById('wk_status');if(d)d.innerHTML='<div class="alert alert-'+type+' py-2 small">'+msg+'</div>';}

  window.tzStepUpWebAuthn = async function(){
    var btn  = document.getElementById('wk_btn');
    var csrf = document.querySelector('[name=_csrf]') ? document.querySelector('[name=_csrf]').value : '';
    if (!csrf) {
      // fallback: pobierz z meta lub form
      var csrfMeta = document.getElementById('tz_csrf');
      if (csrfMeta) csrf = csrfMeta.value;
    }
    if(btn) btn.disabled = true;
    wkSt('<span class="spinner-border spinner-border-sm me-2"></span>Inicjalizacja…','info');
    try {
      var r1=await fetch('<?= APP_URL ?>/tozsamosc/api/webauthn_begin_auth.php',
        {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf})});
      var d1=await r1.json();
      if(!d1.ok) throw new Error(d1.message||'Błąd inicjalizacji');
      var opts=d1.options;
      opts.challenge=b64u(opts.challenge);
      if(opts.allowCredentials) opts.allowCredentials=opts.allowCredentials.map(function(c){return Object.assign({},c,{id:b64u(c.id)});});
      wkSt('<span class="spinner-border spinner-border-sm me-2"></span>Dotknij klucz lub użyj biometryki…','info');
      var cred=await navigator.credentials.get({publicKey:opts});
      wkSt('<span class="spinner-border spinner-border-sm me-2"></span>Weryfikacja…','info');
      var payload={id:cred.id,rawId:ab64u(cred.rawId),
        clientDataJSON:ab64u(cred.response.clientDataJSON),
        authenticatorData:ab64u(cred.response.authenticatorData),
        signature:ab64u(cred.response.signature),
        userHandle:cred.response.userHandle?ab64u(cred.response.userHandle):null};
      var r2=await fetch('<?= APP_URL ?>/tozsamosc/api/webauthn_complete_auth.php',
        {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf,response:payload})});
      var d2=await r2.json();
      if(!d2.ok) throw new Error(d2.message||'Błąd weryfikacji');
      wkSt('<i class="bi bi-check-circle-fill me-1"></i>Zweryfikowano!','success');
      setTimeout(function(){window.location=d2.redirect||'<?= APP_URL ?>/tozsamosc/index.php';},600);
    } catch(e){
      if(btn) btn.disabled=false;
      wkSt('<i class="bi bi-exclamation-triangle-fill me-1"></i>'+(e.message||'Błąd'),'danger');
    }
  };

  // CSRF dla WebAuthn (nie ma formularza)
  var csrfEl = document.createElement('input');
  csrfEl.type='hidden'; csrfEl.id='tz_csrf'; csrfEl.value='<?= h(csrf_token()) ?>';
  document.body.appendChild(csrfEl);
<?php endif; ?>
})();
</script>

<?php include __DIR__ . '/_foot.php'; ?>
