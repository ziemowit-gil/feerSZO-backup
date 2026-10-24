<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';

auth_start();
require_role('admin', 'editor');
webauthn_migrate();

$user = current_user();
$_is_volunteer_only = panel_volunteer_shell($user); // powłoka panelu: wolontariusz albo ktokolwiek z umową

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    webauthn_delete_credential((int)($_POST['cred_id'] ?? 0), (int)$user['id']);
    flash_set('success', 'Klucz został usunięty.');
    header('Location: webauthn.php');
    exit;
}

$keys = webauthn_get_credentials((int)$user['id']);

$PAGE_TITLE = 'Klucze sprzętowe (WebAuthn / FIDO2)';
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
?>
<div class="pvm mfa">

  <div class="mt-head">
    <div>
      <div class="mt-kicker"><a href="<?= APP_URL ?>/panel/index.php" style="color:inherit">Panel współpracownika</a> · konta i dostępy</div>
      <h1 class="mt-title">Klucze bezpieczeństwa</h1>
      <p class="mt-lead">Passkeys i klucze sprzętowe (WebAuthn / FIDO2), np. YubiKey. Po zarejestrowaniu klucza każde logowanie wymaga jego dotknięcia.</p>
    </div>
  </div>

  <?= flash_html() ?>

  <section class="mt-group" aria-labelledby="wa-state-h">
    <h2 class="mt-h2" id="wa-state-h">Twoje <b>klucze</b></h2>
    <?php if (empty($keys)): ?>
    <div class="mfa-state" style="--c:var(--m-grey)">
      <span class="sq" aria-hidden="true"><i class="bi bi-fingerprint"></i></span>
      <span><b>Brak kluczy</b><small>Logowanie nie wymaga klucza sprzętowego.</small></span>
    </div>
    <?php else: ?>
    <table class="mfa-tbl">
      <thead><tr><th scope="col">Nazwa</th><th scope="col">Algorytm</th><th scope="col">Zarejestrowany</th><th scope="col">Ostatnie użycie</th><th scope="col" class="end">Akcje</th></tr></thead>
      <tbody>
        <?php foreach ($keys as $key): ?>
        <tr>
          <td><i class="bi bi-usb-symbol me-2" aria-hidden="true"></i><b style="font-weight:600"><?= h($key['name']) ?></b></td>
          <td><?= $key['alg'] == -257 ? 'RSA-SHA256' : 'EC P-256 (ECDSA)' ?></td>
          <td><?= h($key['created_at'] ? date('d.m.Y H:i', strtotime($key['created_at'])) : '—') ?></td>
          <td><?= h($key['last_used_at'] ? date('d.m.Y H:i', strtotime($key['last_used_at'])) : 'Nigdy') ?></td>
          <td class="end">
            <form method="post" style="display:inline" onsubmit="return confirm('Usunąć klucz «<?= h(addslashes($key['name'])) ?>»?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cred_id" value="<?= (int)$key['id'] ?>">
              <button type="submit" class="mt-btn mt-btn--ghost" aria-label="Usuń klucz <?= h($key['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i>Usuń</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="mt-group" aria-labelledby="wa-add-h">
    <h2 class="mt-h2" id="wa-add-h"><i class="bi bi-plus-circle" aria-hidden="true"></i> Dodaj <b>nowy klucz</b></h2>
    <label class="lbl" for="keyName">Nazwa klucza</label>
    <div class="mfa-secret">
      <input id="keyName" type="text" class="mfa-in" placeholder="np. YubiKey 5" maxlength="80" style="font-family:inherit">
      <button id="btnRegister" type="button" class="mt-btn" onclick="registerKey()"><i class="bi bi-usb-symbol" aria-hidden="true"></i>Dodaj klucz</button>
    </div>
    <p class="mfa-hint">Po kliknięciu przeglądarka poprosi o dotknięcie klucza lub użycie odcisku palca.</p>
    <div id="regStatus" class="mfa-status" role="status" aria-live="polite"></div>
  </section>

</div><!-- /.pvm -->

<input type="hidden" id="csrf_token" value="<?= csrf_token() ?>">

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

function setStatus(msg, type) {
    type = type || 'info';
    var d = document.getElementById('regStatus');
    d.innerHTML = '<div class="mfa-msg ' + (type === 'success' ? 'ok' : type === 'danger' ? 'err' : '') + '">' + msg + '</div>';
}

async function registerKey() {
    var btn      = document.getElementById('btnRegister');
    var keyName  = (document.getElementById('keyName').value.trim()) || 'Klucz sprzętowy';
    var csrf     = document.getElementById('csrf_token').value;

    btn.disabled = true;
    setStatus('<i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Inicjalizacja rejestracji…', 'info');

    try {
        // 1. Begin register
        var beginResp = await fetch('<?= APP_URL ?>/admin/api/webauthn_begin_register.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: csrf})
        });
        var beginData = await beginResp.json();
        if (!beginData.ok) throw new Error(beginData.message || 'Błąd inicjalizacji rejestracji');

        var opts = beginData.options;

        // 2. Convert binary fields
        opts.challenge = b64u_to_ab(opts.challenge);
        opts.user.id   = b64u_to_ab(opts.user.id);
        if (opts.excludeCredentials) {
            opts.excludeCredentials = opts.excludeCredentials.map(function(c) {
                return Object.assign({}, c, {id: b64u_to_ab(c.id)});
            });
        }

        setStatus('<i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Dotknij klucz sprzętowy…', 'info');

        // 3. Create credential
        var credential = await navigator.credentials.create({publicKey: opts});

        setStatus('<i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Zapisywanie klucza…', 'info');

        // 4. Complete register
        var credData = {
            id:                 credential.id,
            rawId:              ab_to_b64u(credential.rawId),
            clientDataJSON:     ab_to_b64u(credential.response.clientDataJSON),
            attestationObject:  ab_to_b64u(credential.response.attestationObject),
        };

        var complResp = await fetch('<?= APP_URL ?>/admin/api/webauthn_complete_register.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: csrf, response: credData, key_name: keyName})
        });
        var complData = await complResp.json();
        if (!complData.ok) throw new Error(complData.message || 'Błąd rejestracji klucza');

        setStatus('<i class="bi bi-check-circle-fill me-1"></i>Klucz zarejestrowany pomyślnie!', 'success');
        setTimeout(() => location.reload(), 1200);

    } catch (e) {
        btn.disabled = false;
        setStatus('<i class="bi bi-exclamation-triangle-fill me-1"></i>' + (e.message || 'Nieoczekiwany błąd'), 'danger');
    }
}
</script>


<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
