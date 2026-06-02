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
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

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
}
?>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-fingerprint me-2" aria-hidden="true"></i>Klucze sprzętowe</h1>
  <p class="pv-page-sub">Zarządzaj kluczami bezpieczeństwa WebAuthn</p>
</div>
<?php echo flash_html(); ?>
<?php endif; ?>

<div class="container-xl py-4">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item">
        <a href="<?= APP_URL ?>/panel/index.php">Mój panel</a>
      </li>
      <li class="breadcrumb-item active">Klucze sprzętowe</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-usb-symbol fs-3 text-primary"></i>
    <h2 class="h4 mb-0 fw-bold">Klucze sprzętowe (WebAuthn / FIDO2)</h2>
  </div>

  <div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div class="small">
      <strong>WebAuthn / FIDO2</strong> — klucze sprzętowe (np. YubiKey) zapewniają
      silne uwierzytelnianie dwuskładnikowe. Po zarejestrowaniu klucza, każde logowanie
      będzie wymagało jego fizycznego dotknięcia.
    </div>
  </div>

  <?php if (empty($keys)): ?>
  <div class="alert alert-warning d-flex gap-2 align-items-center">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <span>Brak kluczy — logowanie nie wymaga klucza sprzętowego.</span>
  </div>
  <?php else: ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Nazwa</th>
              <th>Algorytm</th>
              <th>Zarejestrowany</th>
              <th>Ostatnie użycie</th>
              <th class="text-end">Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($keys as $key): ?>
            <tr>
              <td>
                <i class="bi bi-usb-symbol me-2 text-muted"></i>
                <?= h($key['name']) ?>
              </td>
              <td>
                <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.73rem">
                  <?= $key['alg'] == -257 ? 'RSA-SHA256' : 'EC P-256 (ECDSA)' ?>
                </span>
              </td>
              <td class="text-muted small">
                <?= h($key['created_at'] ? date('d.m.Y H:i', strtotime($key['created_at'])) : '—') ?>
              </td>
              <td class="text-muted small">
                <?= h($key['last_used_at'] ? date('d.m.Y H:i', strtotime($key['last_used_at'])) : 'Nigdy') ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Usunąć klucz «<?= h(addslashes($key['name'])) ?>»?')">
                  <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                  <input type="hidden" name="action"   value="delete">
                  <input type="hidden" name="cred_id"  value="<?= (int)$key['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Usuń
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Add new key -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <h5 class="card-title fw-semibold mb-3">
        <i class="bi bi-plus-circle me-2"></i>Dodaj nowy klucz
      </h5>
      <div class="row g-2 align-items-end">
        <div class="col-sm-5">
          <label class="form-label small fw-semibold">Nazwa klucza</label>
          <input id="keyName" type="text" class="form-control"
                 placeholder="np. YubiKey 5" maxlength="80">
        </div>
        <div class="col-auto">
          <button id="btnRegister" class="btn btn-primary" onclick="registerKey()">
            <i class="bi bi-usb-symbol me-2"></i>Dodaj nowy klucz
          </button>
        </div>
      </div>
      <div id="regStatus" class="mt-3"></div>
    </div>
  </div>

</div>

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
    d.innerHTML = '<div class="alert alert-' + type + ' py-2 small">' + msg + '</div>';
}

async function registerKey() {
    var btn      = document.getElementById('btnRegister');
    var keyName  = (document.getElementById('keyName').value.trim()) || 'Klucz sprzętowy';
    var csrf     = document.getElementById('csrf_token').value;

    btn.disabled = true;
    setStatus('<span class="spinner-border spinner-border-sm me-2"></span>Inicjalizacja rejestracji…', 'info');

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

        setStatus('<span class="spinner-border spinner-border-sm me-2"></span>Dotknij klucz sprzętowy…', 'info');

        // 3. Create credential
        var credential = await navigator.credentials.create({publicKey: opts});

        setStatus('<span class="spinner-border spinner-border-sm me-2"></span>Zapisywanie klucza…', 'info');

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
