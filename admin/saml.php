<?php
/**
 * admin/saml.php — zarządzanie SAML 2.0 Identity Provider.
 *
 * Status IdP + certyfikat + metadata, rejestr Service Providerów (dodaj/edytuj/
 * usuń/aktywacja), import metadata SP, presety (Moodle/Nextcloud/Grafana),
 * polityka dostępu (role), log zdarzeń SSO.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/saml_idp.php';
require_once dirname(__DIR__) . '/includes/owncloud.php';

require_role('admin');
ika_require(APP_URL . '/admin/saml.php', 3600);
$PAGE_TITLE = 'SAML Identity Provider';

function _saml_setting_set(string $key, string $val): void {
    db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
        ->execute([$key, $val]);
}

$NAMEID_FORMATS = ['emailAddress' => 'E-mail (emailAddress)', 'persistent' => 'Trwały (persistent)',
                   'transient' => 'Ulotny (transient)', 'unspecified' => 'Nieokreślony (unspecified)'];
$NAMEID_ATTRS   = ['email' => 'E-mail', 'username' => 'Login (część przed @)', 'id' => 'ID użytkownika',
                   'name' => 'Imię i nazwisko'];
$PRESETS = ['generic' => 'Generyczny (OID)', 'moodle' => 'Moodle', 'nextcloud' => 'Nextcloud',
            'owncloud' => 'ownCloud', 'grafana' => 'Grafana'];
$PRESET_ENDPOINTS = saml_preset_endpoints();
$ROLES_AVAILABLE = [];
try { foreach (db_all("SELECT name FROM roles ORDER BY name") as $r) $ROLES_AVAILABLE[] = $r['name']; } catch (\Throwable $e) {}
if (!$ROLES_AVAILABLE) $ROLES_AVAILABLE = ['admin', 'editor', 'viewer', 'crm_user'];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'toggle_idp') {
        _saml_setting_set('saml_idp_enabled', isset($_POST['enabled']) ? '1' : '0');
        flash_set('success', 'Zapisano stan SAML IdP.');
        header('Location: ' . APP_URL . '/admin/saml.php'); exit;
    }

    if ($action === 'gen_cert') {
        $res = saml_idp_generate_cert(isset($_POST['force']));
        flash_set($res['ok'] ? 'success' : 'danger', $res['msg']);
        header('Location: ' . APP_URL . '/admin/saml.php'); exit;
    }

    if ($action === 'import_metadata') {
        $xml = trim($_POST['metadata_xml'] ?? '');
        $parsed = $xml !== '' ? saml_parse_sp_metadata($xml) : null;
        if (!$parsed) {
            flash_set('danger', 'Nie udało się sparsować metadata SP (brak entityID lub ACS).');
            header('Location: ' . APP_URL . '/admin/saml.php'); exit;
        }
        // Przekaż sparsowane wartości do formularza dodawania (sesja).
        $_SESSION['saml_prefill'] = $parsed;
        flash_set('info', 'Wczytano metadata SP — sprawdź dane i zapisz.');
        header('Location: ' . APP_URL . '/admin/saml.php#form'); exit;
    }

    if ($action === 'save_sp') {
        $id = (int)($_POST['id'] ?? 0);
        $attrMap = trim($_POST['attr_map'] ?? '');
        if ($attrMap !== '' && json_decode($attrMap) === null) {
            flash_set('danger', 'Mapa atrybutów nie jest poprawnym JSON-em.');
            header('Location: ' . APP_URL . '/admin/saml.php#form'); exit;
        }
        $roles = $_POST['allowed_roles'] ?? [];
        $data = [
            'name'            => trim($_POST['name'] ?? ''),
            'entity_id'       => trim($_POST['entity_id'] ?? ''),
            'acs_url'         => trim($_POST['acs_url'] ?? ''),
            'acs_binding'     => 'HTTP-POST',
            'slo_url'         => trim($_POST['slo_url'] ?? ''),
            'nameid_format'   => $_POST['nameid_format'] ?? 'emailAddress',
            'nameid_attr'     => $_POST['nameid_attr'] ?? 'email',
            'attr_map'        => $attrMap,
            'sp_cert'         => trim($_POST['sp_cert'] ?? ''),
            'want_signed_req' => isset($_POST['want_signed_req']) ? 1 : 0,
            'sign_assertion'  => isset($_POST['sign_assertion']) ? 1 : 0,
            'sign_response'   => isset($_POST['sign_response']) ? 1 : 0,
            'allowed_roles'   => implode(',', array_filter(array_map('trim', (array)$roles))),
            'relay_default'   => trim($_POST['relay_default'] ?? ''),
            'preset'          => $_POST['preset'] ?? 'generic',
            'is_active'       => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($data['name'] === '' || $data['entity_id'] === '' || $data['acs_url'] === '') {
            flash_set('danger', 'Nazwa, Entity ID i ACS URL są wymagane.');
            header('Location: ' . APP_URL . '/admin/saml.php#form'); exit;
        }
        try {
            if ($id > 0) {
                db_update('saml_sp', $data, $id);
                flash_set('success', 'Zapisano Service Providera.');
            } else {
                $data['created_by'] = (int)current_user()['id'];
                db_insert('saml_sp', $data);
                flash_set('success', 'Dodano Service Providera.');
            }
            unset($_SESSION['saml_prefill']);
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd zapisu: ' . $e->getMessage() . ' (Entity ID musi być unikalne).');
            header('Location: ' . APP_URL . '/admin/saml.php#form'); exit;
        }
        header('Location: ' . APP_URL . '/admin/saml.php'); exit;
    }

    if ($action === 'delete_sp') {
        db()->prepare("DELETE FROM saml_sp WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash_set('success', 'Usunięto Service Providera.');
        header('Location: ' . APP_URL . '/admin/saml.php'); exit;
    }

    if ($action === 'toggle_sp') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE saml_sp SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        header('Location: ' . APP_URL . '/admin/saml.php'); exit;
    }
}

// ── Dane do widoku ──────────────────────────────────────────────────────────────
$enabled  = saml_idp_enabled();
$hasCert  = saml_idp_has_cert();
$certInfo = saml_idp_cert_info();
$sps      = saml_sp_all();
$log      = db_all("SELECT * FROM saml_sso_log ORDER BY id DESC LIMIT 40");

$editId = (int)($_GET['edit'] ?? 0);
$edit   = $editId > 0 ? saml_sp_by_id($editId) : null;
$prefill = $_SESSION['saml_prefill'] ?? null;
// Wartości formularza (edycja > prefill z importu > puste).
$f = function (string $k, $def = '') use ($edit, $prefill) {
    if ($edit && array_key_exists($k, $edit)) return $edit[$k];
    if ($prefill && array_key_exists($k, $prefill)) return $prefill[$k];
    return $def;
};
$editRoles = $edit ? array_filter(array_map('trim', explode(',', (string)$edit['allowed_roles']))) : [];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:1100px">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-shield-lock me-2"></i>SAML Identity Provider</h1>
    <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Panel admina</a>
  </div>

  <!-- ── Status IdP ──────────────────────────────────────────────────────── -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="bi bi-broadcast me-1"></i>Status IdP</span>
      <span class="badge bg-<?= $enabled ? 'success' : 'secondary' ?>"><?= $enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY' ?></span>
    </div>
    <div class="card-body">
      <form method="post" class="d-flex align-items-center gap-2 mb-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="toggle_idp">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="idpEnabled" name="enabled" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="idpEnabled">Udostępniaj logowanie SSO dla zewnętrznych aplikacji</label>
        </div>
        <button class="btn btn-sm btn-primary">Zapisz</button>
      </form>

      <div class="row g-3 small">
        <div class="col-md-7">
          <table class="table table-sm mb-0">
            <tr><th style="width:130px">Entity ID</th><td><code><?= h(saml_idp_entity_id()) ?></code></td></tr>
            <tr><th>SSO URL</th><td><code><?= h(saml_idp_sso_url()) ?></code></td></tr>
            <tr><th>SLO URL</th><td><code><?= h(saml_idp_slo_url()) ?></code></td></tr>
            <tr><th>Metadata</th><td><a href="<?= h(saml_idp_metadata_url()) ?>" target="_blank"><?= h(saml_idp_metadata_url()) ?> <i class="bi bi-box-arrow-up-right"></i></a></td></tr>
          </table>
        </div>
        <div class="col-md-5">
          <?php if ($certInfo): ?>
            <div class="border rounded p-2 bg-light">
              <div class="fw-semibold mb-1"><i class="bi bi-patch-check me-1"></i>Certyfikat podpisujący
                <span class="badge bg-<?= $certInfo['dedicated'] ? 'success' : 'warning' ?> ms-1">
                  <?= $certInfo['dedicated'] ? 'dedykowany' : 'współdzielony (app)' ?></span>
              </div>
              <div>Podmiot: <?= h($certInfo['subject']) ?></div>
              <div>Ważny do: <?= h($certInfo['valid_to']) ?>
                <span class="text-<?= $certInfo['days_left'] < 30 ? 'danger' : 'muted' ?>">(<?= (int)$certInfo['days_left'] ?> dni)</span></div>
              <div class="text-truncate" title="<?= h($certInfo['fingerprint']) ?>">SHA-256: <code style="font-size:.7rem"><?= h($certInfo['fingerprint']) ?></code></div>
            </div>
          <?php else: ?>
            <div class="alert alert-warning py-2 mb-2 small">Brak certyfikatu podpisującego — wygeneruj poniżej.</div>
          <?php endif; ?>
          <form method="post" class="mt-2" onsubmit="return <?= $certInfo ? "confirm('Nadpisać certyfikat? Wszystkie SP będą musiały zaktualizować metadata IdP.')" : 'true' ?>">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="gen_cert">
            <?php if ($certInfo && $certInfo['dedicated']): ?><input type="hidden" name="force" value="1"><?php endif; ?>
            <button class="btn btn-sm btn-outline-primary">
              <i class="bi bi-key me-1"></i><?= $certInfo && $certInfo['dedicated'] ? 'Wygeneruj ponownie (rotacja)' : 'Wygeneruj dedykowany certyfikat' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Lista SP ────────────────────────────────────────────────────────── -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <span><i class="bi bi-grid-3x3-gap me-1"></i>Zarejestrowane aplikacje (Service Providers) — <?= count($sps) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Nazwa</th><th>Entity ID</th><th>Preset</th><th>NameID</th><th>Role</th><th class="text-center">Status</th><th class="text-end">Akcje</th></tr>
        </thead>
        <tbody>
        <?php if (!$sps): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Brak zarejestrowanych aplikacji. Dodaj pierwszą poniżej.</td></tr>
        <?php endif; ?>
        <?php foreach ($sps as $s): ?>
          <tr>
            <td class="fw-semibold"><?= h($s['name']) ?></td>
            <td><code class="small"><?= h($s['entity_id']) ?></code></td>
            <td><span class="badge bg-light text-dark border"><?= h($PRESETS[$s['preset']] ?? $s['preset']) ?></span></td>
            <td class="small"><?= h($s['nameid_format']) ?></td>
            <td class="small"><?= $s['allowed_roles'] !== '' ? h($s['allowed_roles']) : '<span class="text-muted">wszystkie</span>' ?></td>
            <td class="text-center">
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="toggle_sp"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-sm btn-<?= $s['is_active'] ? 'success' : 'outline-secondary' ?> py-0 px-2" title="Kliknij, aby przełączyć">
                  <?= $s['is_active'] ? 'aktywny' : 'wyłączony' ?></button>
              </form>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary py-0 px-1" href="<?= APP_URL ?>/saml/sso.php?sp=<?= (int)$s['id'] ?>" target="_blank" title="Test logowania (IdP-initiated)"><i class="bi bi-box-arrow-up-right"></i></a>
              <a class="btn btn-sm btn-outline-secondary py-0 px-1" href="<?= APP_URL ?>/admin/saml.php?edit=<?= (int)$s['id'] ?>#form" title="Edytuj"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tego Service Providera?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="delete_sp"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Usuń"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Import metadata SP ──────────────────────────────────────────────── -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header"><i class="bi bi-filetype-xml me-1"></i>Import metadata Service Providera (XML)</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="import_metadata">
        <textarea name="metadata_xml" class="form-control form-control-sm font-monospace" rows="4" placeholder="Wklej zawartość metadata SP (EntityDescriptor)…"></textarea>
        <button class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-download me-1"></i>Wczytaj do formularza</button>
        <span class="form-text ms-2">Wyciągnie Entity ID, ACS, SLO i certyfikat SP — uzupełnij resztę i zapisz.</span>
      </form>
    </div>
  </div>

  <!-- ── Formularz dodaj/edytuj SP ───────────────────────────────────────── -->
  <div class="card mb-4 shadow-sm" id="form">
    <div class="card-header"><i class="bi bi-<?= $edit ? 'pencil-square' : 'plus-circle' ?> me-1"></i><?= $edit ? 'Edytuj' : 'Dodaj' ?> Service Providera</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_sp">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

        <div class="col-md-4">
          <label class="form-label small fw-semibold">Nazwa aplikacji *</label>
          <input type="text" name="name" class="form-control form-control-sm" value="<?= h($f('name')) ?>" required>
        </div>
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Entity ID (SP) *</label>
          <input type="text" name="entity_id" class="form-control form-control-sm" value="<?= h($f('entity_id')) ?>" placeholder="https://app.example.org/saml/metadata" required>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Preset</label>
          <select name="preset" class="form-select form-select-sm">
            <?php foreach ($PRESETS as $k => $v): ?>
              <option value="<?= $k ?>" <?= $f('preset', 'generic') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12" id="oc-help" style="display:none">
          <div class="alert alert-info small mb-0">
            <i class="bi bi-info-circle me-1"></i>Po stronie ownCloud: włącz aplikację
            <strong>„user_saml"</strong> (Ustawienia → Administracja → uwierzytelnianie SAML)
            i wklej tam adres metadata IdP: <code><?= h(saml_idp_metadata_url()) ?></code>.
            Entity ID/ACS/SLO poniżej uzupełniły się automatycznie na podstawie adresu
            skonfigurowanego w Admin → Integracje → Magazyn plików / ownCloud.
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label small fw-semibold">ACS URL (AssertionConsumerService) *</label>
          <input type="url" name="acs_url" class="form-control form-control-sm" value="<?= h($f('acs_url')) ?>" placeholder="https://app.example.org/saml/acs" required>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">SLO URL (SingleLogoutService)</label>
          <input type="url" name="slo_url" class="form-control form-control-sm" value="<?= h($f('slo_url')) ?>" placeholder="opcjonalnie">
        </div>

        <div class="col-md-4">
          <label class="form-label small fw-semibold">Format NameID</label>
          <select name="nameid_format" class="form-select form-select-sm">
            <?php foreach ($NAMEID_FORMATS as $k => $v): ?>
              <option value="<?= $k ?>" <?= $f('nameid_format', 'emailAddress') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Pole NameID</label>
          <select name="nameid_attr" class="form-select form-select-sm">
            <?php foreach ($NAMEID_ATTRS as $k => $v): ?>
              <option value="<?= $k ?>" <?= $f('nameid_attr', 'email') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Ignorowane dla formatu persistent/transient.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Domyślny RelayState</label>
          <input type="text" name="relay_default" class="form-control form-control-sm" value="<?= h($f('relay_default')) ?>" placeholder="np. URL startowy">
        </div>

        <div class="col-12">
          <label class="form-label small fw-semibold">Dozwolone role (puste = wszystkie)</label>
          <div class="d-flex flex-wrap gap-3">
            <?php foreach ($ROLES_AVAILABLE as $r): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="allowed_roles[]" value="<?= h($r) ?>" id="role_<?= h($r) ?>" <?= in_array($r, $editRoles, true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="role_<?= h($r) ?>"><?= h($r) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="col-12">
          <div class="d-flex flex-wrap gap-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="sign_assertion" id="sa" <?= (int)$f('sign_assertion', 1) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="sa">Podpisuj asercję</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="sign_response" id="sr" <?= (int)$f('sign_response', 0) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="sr">Podpisuj całą odpowiedź</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="want_signed_req" id="wsr" <?= (int)$f('want_signed_req', 0) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="wsr">Wymagaj podpisanego żądania (potrzebny certyfikat SP)</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_active" id="ia" <?= (int)$f('is_active', 1) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="ia">Aktywny</label>
            </div>
          </div>
        </div>

        <div class="col-12">
          <label class="form-label small fw-semibold">Certyfikat SP (PEM) — do weryfikacji podpisu żądań</label>
          <textarea name="sp_cert" class="form-control form-control-sm font-monospace" rows="3" placeholder="-----BEGIN CERTIFICATE-----…"><?= h($f('sp_cert')) ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label small fw-semibold">Mapa atrybutów (JSON, opcjonalnie — puste = z presetu)</label>
          <textarea name="attr_map" class="form-control form-control-sm font-monospace" rows="3" placeholder='[{"name":"email","friendly":"email","nameformat":"urn:oasis:names:tc:SAML:2.0:attrname-format:basic","source":"email"}]'><?= h($f('attr_map')) ?></textarea>
          <div class="form-text">Źródła: email, username, name, first_name, last_name, role, id, phone.</div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i><?= $edit ? 'Zapisz zmiany' : 'Dodaj SP' ?></button>
          <?php if ($edit): ?><a href="<?= APP_URL ?>/admin/saml.php" class="btn btn-sm btn-outline-secondary">Anuluj</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Log SSO ─────────────────────────────────────────────────────────── -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header"><i class="bi bi-journal-text me-1"></i>Ostatnie zdarzenia SSO/SLO</div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 small">
        <thead class="table-light"><tr><th>Czas</th><th>Zdarz.</th><th>Aplikacja</th><th>Użytkownik</th><th>Wynik</th><th>Szczegóły</th></tr></thead>
        <tbody>
        <?php if (!$log): ?><tr><td colspan="6" class="text-center text-muted py-2">Brak zdarzeń.</td></tr><?php endif; ?>
        <?php foreach ($log as $l): ?>
          <tr>
            <td class="text-nowrap"><?= h($l['created_at']) ?></td>
            <td><?= h($l['event']) ?></td>
            <td><code class="small"><?= h($l['sp_entity']) ?></code></td>
            <td><?= h($l['user_email']) ?></td>
            <td><span class="badge bg-<?= $l['result'] === 'ok' ? 'success' : ($l['result'] === 'denied' ? 'warning' : 'danger') ?>"><?= h($l['result']) ?></span></td>
            <td><?= h($l['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function samlCopy(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  el.select(); el.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(el.value).then(function () {
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check2"></i>';
    btn.classList.add('btn-success'); btn.classList.remove('btn-outline-secondary');
    setTimeout(function () { btn.innerHTML = old; btn.classList.remove('btn-success'); btn.classList.add('btn-outline-secondary'); }, 1200);
  });
}
</script>
<script>
(function () {
  var ENDPOINTS = <?= json_encode($PRESET_ENDPOINTS, JSON_UNESCAPED_SLASHES) ?>;
  var form = document.getElementById('form');
  if (!form) return;
  var preset = form.querySelector('[name="preset"]');
  var entity = form.querySelector('[name="entity_id"]');
  var acs    = form.querySelector('[name="acs_url"]');
  var slo    = form.querySelector('[name="slo_url"]');
  var ocHelp = document.getElementById('oc-help');
  if (!preset) return;
  function syncPreset() {
    if (ocHelp) ocHelp.style.display = preset.value === 'owncloud' ? '' : 'none';
    var e = ENDPOINTS[preset.value];
    if (!e) return;
    // Uzupełnij tylko puste pola, by nie nadpisać ręcznych zmian.
    if (entity && entity.value.trim() === '') entity.value = e.entity_id;
    if (acs && acs.value.trim() === '')       acs.value = e.acs_url;
    if (slo && e.slo_url && slo.value.trim() === '') slo.value = e.slo_url;
  }
  preset.addEventListener('change', syncPreset);
  syncPreset();
})();
</script>
<?php
unset($_SESSION['saml_prefill']);
include dirname(__DIR__) . '/includes/footer.php';
