<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';

auth_start();

if (empty($_SESSION['webauthn_pending_uid'])) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$raw_redirect = $_GET['redirect'] ?? '';
$redirect     = ($raw_redirect && str_starts_with($raw_redirect, APP_URL . '/'))
    ? $raw_redirect
    : APP_URL . '/portal.php';

$PAGE_TITLE = 'Weryfikacja klucza sprzętowego';
define('SKIP_CONSENT_CHECK', true);
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container py-5" style="max-width:480px">
  <div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">

      <div class="mb-3">
        <i class="bi bi-shield-lock-fill text-primary" style="font-size:3rem"></i>
      </div>
      <h4 class="fw-bold mb-1">Dotknij klucz sprzętowy</h4>
      <p class="text-muted small mb-4">
        Przyłóż YubiKey lub inny klucz FIDO2 do czytnika i naciśnij przycisk.
      </p>

      <div id="spinner" class="d-none mb-3">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Oczekiwanie na klucz…</span>
        </div>
        <div class="small text-muted mt-2">Oczekiwanie na klucz sprzętowy…</div>
      </div>

      <div id="errorDiv" class="alert alert-danger d-none" role="alert"></div>

      <button id="btnAuth" class="btn btn-primary w-100 mb-3" onclick="doAuth()">
        <i class="bi bi-usb-symbol me-2"></i>Weryfikuj klucz
      </button>

      <a href="<?= APP_URL ?>/auth/login.php" class="d-block text-muted small">
        <i class="bi bi-arrow-left me-1"></i>Wróć do logowania
      </a>

    </div>
  </div>
</div>

<input type="hidden" id="csrf_token"   value="<?= csrf_token() ?>">
<input type="hidden" id="redirect_url" value="<?= h($redirect) ?>">

<script>
function b64u_to_ab(str) {
    var s = str.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
}

function ab_to_b64u(buf) {
    var bytes = new Uint8Array(buf);
    var bin   = '';
    for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function doAuth() {
    var btn     = document.getElementById('btnAuth');
    var spinner = document.getElementById('spinner');
    var errDiv  = document.getElementById('errorDiv');
    var csrf    = document.getElementById('csrf_token').value;
    var redir   = document.getElementById('redirect_url').value;

    btn.disabled  = true;
    spinner.classList.remove('d-none');
    errDiv.classList.add('d-none');
    errDiv.textContent = '';

    try {
        // 1. Begin auth
        var beginResp = await fetch('<?= APP_URL ?>/admin/api/webauthn_begin_auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: csrf})
        });
        var beginData = await beginResp.json();
        if (!beginData.ok) throw new Error(beginData.message || 'Błąd inicjalizacji');

        var opts = beginData.options;

        // 2. Convert challenge
        opts.challenge = b64u_to_ab(opts.challenge);
        if (opts.allowCredentials) {
            opts.allowCredentials = opts.allowCredentials.map(function(c) {
                return Object.assign({}, c, {id: b64u_to_ab(c.id)});
            });
        }

        // 3. Get credential
        var credential = await navigator.credentials.get({publicKey: opts});

        // 4. Complete auth
        var credData = {
            id:                 credential.id,
            rawId:              ab_to_b64u(credential.rawId),
            clientDataJSON:     ab_to_b64u(credential.response.clientDataJSON),
            authenticatorData:  ab_to_b64u(credential.response.authenticatorData),
            signature:          ab_to_b64u(credential.response.signature),
            userHandle:         credential.response.userHandle ? ab_to_b64u(credential.response.userHandle) : null,
        };

        var complResp = await fetch('<?= APP_URL ?>/admin/api/webauthn_complete_auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: csrf, response: credData})
        });
        var complData = await complResp.json();
        if (!complData.ok) throw new Error(complData.message || 'Błąd weryfikacji');

        // 5. Redirect
        window.location.href = complData.redirect || redir;

    } catch (e) {
        spinner.classList.add('d-none');
        btn.disabled = false;
        errDiv.classList.remove('d-none');
        errDiv.textContent = e.message || 'Nieoczekiwany błąd. Spróbuj ponownie.';
    }
}

document.addEventListener('DOMContentLoaded', doAuth);
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
