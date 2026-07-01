<?php
/**
 * admin/api_manage.php — Zarządzaj API: Klucze API + Webhooki + Integracje zewnętrzne
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/api_auth.php';
require_once dirname(__DIR__) . '/includes/webhooks.php';

require_role('admin');
api_auth_migrate();
webhooks_migrate();

$PAGE_TITLE = 'Zarządzaj API';

// Aktywna zakładka z URL
$tab = in_array($_GET['tab'] ?? '', ['api', 'webhooks', 'integrations']) ? ($_GET['tab'] ?? 'api') : 'api';

// ── Uprawnienia API ────────────────────────────────────────────────────────────
const AM_API_PERMISSIONS = [
    'volunteers:read'  => 'Wolontariusze — odczyt',
    'contracts:read'   => 'Umowy — odczyt',
    'tasks:read'       => 'Zadania — odczyt',
    'users:read'       => 'Użytkownicy — odczyt',
    'crm:read'         => 'CRM — odczyt kontaktów',
    'crm:write'        => 'CRM — zapis kontaktów (twórz / edytuj / usuń)',
    'karty30:read'     => 'Karty 30 — odczyt (beneficjenci, wizyty, konsultacje, TI)',
    'karty30:write'    => 'Karty 30 — zapis (twórz / edytuj / usuń)',
    'events:read'      => 'Wydarzenia — odczyt (lista, szczegóły, rejestracje)',
    'events:write'     => 'Wydarzenia — zapis (twórz / edytuj / rejestracje)',
];

// ── Zdarzenia Webhook ──────────────────────────────────────────────────────────
const AM_WEBHOOK_EVENTS = [
    'contract.created' => ['primary',   'Umowa dodana'],
    'contract.updated' => ['info',      'Umowa zaktualizowana'],
    'contract.expired' => ['warning',   'Umowa wygasła'],
    'volunteer.added'  => ['success',   'Wolontariusz dodany'],
    'task.created'     => ['secondary', 'Zadanie dodane'],
    'user.registered'  => ['dark',      'Użytkownik zarejestrowany'],
];

$errors    = [];
$new_plain = null;

// ════════════════════════════════════════════════════════════════════════════════
// POST — Klucze API
// ════════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_section'] ?? '') === 'api') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['key_name'] ?? '');
        if ($name === '') $errors[] = 'Podaj nazwę klucza.';

        $perms = [];
        foreach (array_keys(AM_API_PERMISSIONS) as $p) {
            if (!empty($_POST['perm_' . str_replace(':', '_', $p)])) $perms[] = $p;
        }
        if (empty($perms)) $errors[] = 'Wybierz co najmniej jedno uprawnienie.';

        if (!$errors) {
            $plain = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $plain);
            $user  = current_user();
            $rate  = max(0, (int)($_POST['rate_limit'] ?? 0));
            db_insert('api_keys', [
                'key_hash'    => $hash,
                'name'        => $name,
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'rate_limit'  => $rate ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
                'is_active'   => 1,
                'created_by'  => $user['id'] ?? null,
            ]);
            $new_plain = $plain;
            flash_set('success', 'Klucz API "' . $name . '" został utworzony. Skopiuj go teraz!');
        }
        $tab = 'api';
    }

    if ($action === 'revoke') {
        $id = (int)($_POST['key_id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE api_keys SET is_active = 0 WHERE id = ?")->execute([$id]);
            flash_set('success', 'Klucz API unieważniony.');
            header('Location: ' . APP_URL . '/admin/api_manage.php?tab=api'); exit;
        }
    }

    if ($action === 'delete_key') {
        $id = (int)($_POST['key_id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
            flash_set('success', 'Klucz API usunięty.');
            header('Location: ' . APP_URL . '/admin/api_manage.php?tab=api'); exit;
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// POST — Webhooki
// ════════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_section'] ?? '') === 'webhooks') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $name   = trim($_POST['name']   ?? '');
        $url    = trim($_POST['url']    ?? '');
        $secret = trim($_POST['secret'] ?? '');
        $evts   = array_values(array_intersect($_POST['events'] ?? [], array_keys(AM_WEBHOOK_EVENTS)));

        if ($name === '') $errors[] = 'Nazwa jest wymagana.';
        if (!filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'Nieprawidłowy adres URL.';
        if (empty($evts)) $errors[] = 'Wybierz co najmniej jedno zdarzenie.';

        if (!$errors) {
            if ($secret === '') $secret = bin2hex(random_bytes(20));
            db_insert('webhook_endpoints', [
                'name'      => $name,
                'url'       => $url,
                'secret'    => $secret,
                'events'    => json_encode($evts),
                'is_active' => 1,
            ]);
            flash_set('success', 'Endpoint webhook dodany.');
            header('Location: ' . APP_URL . '/admin/api_manage.php?tab=webhooks'); exit;
        }
        $tab = 'webhooks';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM webhook_endpoints WHERE id = ?")->execute([$id]);
            db()->prepare("DELETE FROM webhook_log WHERE endpoint_id = ?")->execute([$id]);
            flash_set('success', 'Endpoint usunięty.');
            header('Location: ' . APP_URL . '/admin/api_manage.php?tab=webhooks'); exit;
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE webhook_endpoints SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
            header('Location: ' . APP_URL . '/admin/api_manage.php?tab=webhooks'); exit;
        }
    }

    if ($action === 'test') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $ep = db_one("SELECT * FROM webhook_endpoints WHERE id=?", [$id]);
            if ($ep) {
                $body    = json_encode(['event' => 'webhook.test', 'data' => ['message' => 'Test z FEER NGO'], 'timestamp' => time()], JSON_UNESCAPED_UNICODE);
                $headers = ['Content-Type: application/json', 'X-Event: webhook.test'];
                if (!empty($ep['secret'])) $headers[] = 'X-Webhook-Secret: ' . $ep['secret'];
                $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 5, 'ignore_errors' => true]]);
                $resp = @file_get_contents($ep['url'], false, $ctx);
                $code = 0;
                foreach ($http_response_header ?? [] as $h) {
                    if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) { $code = (int)$m[1]; break; }
                }
                db_insert('webhook_log', ['endpoint_id' => $id, 'event' => 'webhook.test', 'payload' => $body, 'response_code' => $code, 'response_body' => substr((string)$resp, 0, 500)]);
                db()->prepare("UPDATE webhook_endpoints SET last_triggered_at=datetime('now','localtime'), last_status=? WHERE id=?")->execute([$code, $id]);
                flash_set($code >= 200 && $code < 300 ? 'success' : 'warning', 'Test wysłany. HTTP ' . ($code ?: 'brak'));
            }
            header('Location: ' . APP_URL . '/admin/api_manage.php?tab=webhooks'); exit;
        }
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$api_keys  = db_all("SELECT id, name, permissions, rate_limit, last_used_at, created_at, is_active, created_by FROM api_keys ORDER BY created_at DESC");
$endpoints = db_all("SELECT * FROM webhook_endpoints ORDER BY id DESC");

// ── Integracje — status ────────────────────────────────────────────────────────
$int_m365      = (bool)(org_setting('m365_tenant_id'));
$int_smtp      = (bool)(org_setting('smtp_host') ?: org_setting('m365_send_from_email'));
$int_sp        = (bool)(org_setting('sharepoint_site_url'));
$int_sms       = (bool)(org_setting('sms_api_key') ?: org_setting('sms_api_token'));
$int_whatsapp  = (bool)(org_setting('whatsapp_token'));
$int_moodle    = (bool)(org_setting('moodle_url'));
$int_apaczka   = org_setting('apaczka_enabled') === '1' && (bool)(org_setting('apaczka_api_key'));
$int_furgonetka= org_setting('furgonetka_enabled') === '1';
$int_autenti   = (bool)(org_setting('autenti_client_id'));
$int_docusign  = (bool)(org_setting('docusign_account_id'));
$int_ceidg     = (bool)(org_setting('ceidg_api_key'));
$int_postivo   = (bool)(org_setting('postivo_api_key'));
$int_ai        = (bool)(org_setting('ai_openai_key') ?: org_setting('ai_anthropic_key'));

// ── Render ────────────────────────────────────────────────────────────────────
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:48px;height:48px;border-radius:12px;background:#EEF4FF;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#1E6DFF">
    <i class="bi bi-plugin"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Zarządzaj API</h4>
    <div class="text-muted small">Klucze API, webhooki i integracje zewnętrzne</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
      <?= count(array_filter($api_keys, fn($k) => $k['is_active'])) ?> aktywnych kluczy
    </span>
    <span class="badge bg-success-subtle text-success border border-success-subtle">
      <?= count(array_filter($endpoints, fn($e) => $e['is_active'])) ?> webhooków
    </span>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Tabs ──────────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" id="apiTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link <?= $tab === 'api' ? 'active' : '' ?>" type="button"
            onclick="switchTab('api')" id="tab-api">
      <i class="bi bi-key-fill me-1"></i>Klucze API
      <span class="badge bg-<?= count(array_filter($api_keys, fn($k) => $k['is_active'])) > 0 ? 'primary' : 'secondary' ?> ms-1">
        <?= count(array_filter($api_keys, fn($k) => $k['is_active'])) ?>
      </span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link <?= $tab === 'webhooks' ? 'active' : '' ?>" type="button"
            onclick="switchTab('webhooks')" id="tab-webhooks">
      <i class="bi bi-arrow-left-right me-1"></i>Webhooki
      <span class="badge bg-<?= count($endpoints) > 0 ? 'success' : 'secondary' ?> ms-1">
        <?= count($endpoints) ?>
      </span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link <?= $tab === 'integrations' ? 'active' : '' ?>" type="button"
            onclick="switchTab('integrations')" id="tab-integrations">
      <i class="bi bi-puzzle-fill me-1"></i>Integracje zewnętrzne
    </button>
  </li>
</ul>

<script>
function switchTab(name) {
  ['api','webhooks','integrations'].forEach(function(t){
    document.getElementById('pane-'+t).style.display = (t===name)?'':'none';
    document.getElementById('tab-'+t).classList.toggle('active', t===name);
  });
  history.replaceState(null,'','?tab='+name);
}
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     PANE: KLUCZE API
══════════════════════════════════════════════════════════════════════════ -->
<div id="pane-api" <?= $tab !== 'api' ? 'style="display:none"' : '' ?>>

  <?php if ($errors && $tab === 'api'): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($new_plain): ?>
  <div class="alert alert-warning alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Zapisz klucz — nie zostanie pokazany ponownie!</strong>
    <div class="mt-2">
      <code class="user-select-all fs-6 d-block p-2 bg-white border rounded"><?= h($new_plain) ?></code>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Lista kluczy -->
  <?php if ($api_keys): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-2 fw-semibold" style="font-size:.875rem">
      <i class="bi bi-list-ul me-1 text-primary"></i>Istniejące klucze
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" style="font-size:.875rem">
        <thead class="table-light small">
          <tr><th>Nazwa</th><th>Uprawnienia</th><th>Limit/min</th><th>Ostatnie użycie</th><th>Utworzony</th><th>Status</th><th class="text-end">Akcje</th></tr>
        </thead>
        <tbody>
          <?php foreach ($api_keys as $k):
            $perms_arr = json_decode($k['permissions'] ?? '[]', true) ?: [];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($k['name']) ?></td>
            <td>
              <?php foreach ($perms_arr as $p): ?>
              <span class="badge bg-secondary-subtle text-secondary border me-1" style="font-size:.7rem"><?= h($p) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="small"><?= !empty($k['rate_limit']) ? (int)$k['rate_limit'] : '<span class="text-muted">domyślny</span>' ?></td>
            <td class="text-muted small"><?= $k['last_used_at'] ? h(date_pl($k['last_used_at'])) : '—' ?></td>
            <td class="text-muted small"><?= h(date_pl($k['created_at'])) ?></td>
            <td>
              <span class="badge bg-<?= $k['is_active'] ? 'success' : 'secondary' ?>">
                <?= $k['is_active'] ? 'Aktywny' : 'Unieważniony' ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($k['is_active']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Unieważnić klucz?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_section" value="api">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                <button class="btn btn-sm btn-outline-warning py-0 px-2" title="Unieważnij">
                  <i class="bi bi-slash-circle"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if (!$k['is_active']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć klucz?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_section" value="api">
                <input type="hidden" name="action" value="delete_key">
                <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-info d-flex gap-2 mb-4">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>Brak kluczy API. Utwórz pierwszy poniżej.</div>
  </div>
  <?php endif; ?>

  <!-- Formularz nowego klucza -->
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.875rem">
      <i class="bi bi-plus-circle me-1 text-success"></i>Utwórz nowy klucz API
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_section" value="api">
        <input type="hidden" name="action" value="create">

        <div class="col-md-6">
          <label class="form-label fw-semibold">Nazwa klucza <span class="text-danger">*</span></label>
          <input type="text" name="key_name" class="form-control"
                 placeholder="np. Aplikacja mobilna, Integracja n8n"
                 value="<?= h($_POST['key_name'] ?? '') ?>" required maxlength="200">
        </div>

        <div class="col-md-3">
          <label class="form-label fw-semibold">Limit zapytań / min</label>
          <input type="number" name="rate_limit" class="form-control" min="0" step="1"
                 placeholder="domyślny (120)" value="<?= h($_POST['rate_limit'] ?? '') ?>">
          <div class="form-text">0 / puste = domyślny. Po przekroczeniu API zwraca 429.</div>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Uprawnienia <span class="text-danger">*</span></label>
          <div class="row g-2">
            <?php foreach (AM_API_PERMISSIONS as $perm => $label):
              $field = 'perm_' . str_replace(':', '_', $perm); ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       id="<?= h($field) ?>" name="<?= h($field) ?>" value="1"
                       <?= !empty($_POST[$field]) ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= h($field) ?>">
                  <code class="small"><?= h($perm) ?></code><br>
                  <span class="text-muted small"><?= h($label) ?></span>
                </label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="col-12">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Utwórz klucz
          </button>
          <div class="form-text mt-1">
            <i class="bi bi-info-circle me-1"></i>Klucz zostanie wygenerowany losowo i pokazany <strong>tylko raz</strong>. Zaraz po utworzeniu skopiuj go.
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php $api_base = rtrim(APP_URL, '/') . '/api/v1'; ?>

  <!-- Narzędzia API -->
  <div class="d-flex flex-wrap gap-2 mt-4 mb-3">
    <a href="<?= APP_URL ?>/admin/api_audit.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-journal-text me-1"></i>Audyt API (zapisy RODO)
    </a>
    <a href="<?= h($api_base) ?>/openapi.php" target="_blank" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-filetype-json me-1"></i>Specyfikacja OpenAPI
    </a>
  </div>

  <!-- Dokumentacja endpointów -->
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.875rem">
      <i class="bi bi-book me-1 text-primary"></i>Dokumentacja API
    </div>
    <div class="card-body small">
      <p class="text-muted mb-3">
        Uwierzytelnianie: nagłówek <code>Authorization: Bearer &lt;klucz&gt;</code> (lub <code>?api_key=</code>).
        Body POST/PATCH w <code>application/json</code>. Klienci bez PATCH/DELETE: <code>?_method=</code> lub
        <code>X-HTTP-Method-Override</code>. Limit zapytań per klucz (po przekroczeniu <code>429</code> + <code>Retry-After</code>).
        Operacje zapisu są audytowane (RODO).
      </p>

      <h6 class="fw-semibold"><i class="bi bi-card-checklist text-primary me-1"></i>Karty 30 — <code><?= h($api_base) ?>/karty30.php</code></h6>
      <p class="mb-1">Routing <code>?resource=&lt;R&gt;&amp;id=N</code>; metody GET/POST/PATCH/DELETE. Scope: <code>karty30:read</code> / <code>karty30:write</code>.</p>
      <ul class="mb-2">
        <li><strong>Zasoby:</strong> <code>clients</code>, <code>schedules</code>, <code>consultations</code>, <code>waiting</code>, <code>courses</code>, <code>enrollments</code>, <code>lessons</code>, <code>homework</code>, <code>materials</code>, <code>grades</code>, <code>tests</code></li>
        <li>Filtry per zasób (np. <code>client_id</code>, <code>course_id</code>, <code>status</code>), wyszukiwanie <code>q</code>, paginacja <code>page</code>/<code>per_page</code>.</li>
      </ul>
      <pre class="bg-dark text-light p-2 rounded mb-3" style="white-space:pre-wrap;font-size:.8rem"># Lista beneficjentów
curl -H "Authorization: Bearer $KEY" "<?= h($api_base) ?>/karty30.php?resource=clients&per_page=20"

# Wystawienie oceny (value_num policzy się z value_text)
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"course_id":5,"client_id":12,"category":"sprawdzian","value_text":"4+","weight":2}' \
  "<?= h($api_base) ?>/karty30.php?resource=grades"</pre>

      <h6 class="fw-semibold"><i class="bi bi-diagram-2-fill text-success me-1"></i>CRM — <code><?= h($api_base) ?>/crm.php</code></h6>
      <p class="mb-2">CRUD kontaktów + notatki/tagi. Scope: <code>crm:read</code> / <code>crm:write</code>.
        Filtry: <code>q, status, type, source, branza, tag, wojewodztwo, has_email, has_phone, created_from, created_to</code>.</p>
      <pre class="bg-dark text-light p-2 rounded mb-3" style="white-space:pre-wrap;font-size:.8rem">curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"imie_nazwisko":"Anna Kowalska","email":"anna@example.pl"}' \
  "<?= h($api_base) ?>/crm.php"</pre>

      <h6 class="fw-semibold"><i class="bi bi-calendar-event-fill text-primary me-1" style="color:#7c3aed"></i>Wydarzenia — <code><?= h($api_base) ?>/events.php</code></h6>
      <p class="mb-1">Routing <code>?id=N&amp;resource=registrations&amp;reg_id=M</code>; metody GET/POST/PATCH/DELETE. Scope: <code>events:read</code> / <code>events:write</code>.</p>
      <ul class="mb-2">
        <li>Wydarzenia: <code>GET</code> lista (filtry <code>status, type, is_public, upcoming, q, from, to</code>), <code>GET ?id=N</code>, <code>POST</code> (nowe — status <code>draft</code>), <code>PATCH ?id=N</code>, <code>DELETE ?id=N</code> (archiwizuje).</li>
        <li>Rejestracje: <code>GET/POST ?id=N&amp;resource=registrations</code>, <code>GET/PATCH/DELETE …&amp;reg_id=M</code>. Zapis przez API wywołuje sync CRM i webhook Power Automate identycznie jak formularz publiczny.</li>
      </ul>
      <pre class="bg-dark text-light p-2 rounded mb-0" style="white-space:pre-wrap;font-size:.8rem"># Lista nadchodzących, opublikowanych wydarzeń
curl -H "Authorization: Bearer $KEY" "<?= h($api_base) ?>/events.php?status=published&upcoming=1"

# Zgłoszenie rejestracji na wydarzenie o id=5
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"first_name":"Jan","last_name":"Nowak","email":"jan@example.pl"}' \
  "<?= h($api_base) ?>/events.php?id=5&resource=registrations"</pre>

      <div class="alert alert-light border small mt-3 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Do osadzenia listy wydarzeń na zewnętrznej stronie (bez klucza API, tylko wydarzenia publiczne)
        służy osobny, bez-autoryzacyjny widget — zobacz
        <a href="<?= APP_URL ?>/events/settings/embed.php">Wydarzenia → Osadzanie / API</a>.
      </div>
    </div>
  </div>
</div><!-- /pane-api -->


<!-- ══════════════════════════════════════════════════════════════════════════
     PANE: WEBHOOKI
══════════════════════════════════════════════════════════════════════════ -->
<div id="pane-webhooks" <?= $tab !== 'webhooks' ? 'style="display:none"' : '' ?>>

  <?php if ($errors && $tab === 'webhooks'): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <!-- Lista endpointów -->
  <?php if ($endpoints): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-2 fw-semibold" style="font-size:.875rem">
      <i class="bi bi-list-ul me-1 text-primary"></i>Skonfigurowane endpointy
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" style="font-size:.875rem">
        <thead class="table-light small">
          <tr><th>Nazwa</th><th>URL</th><th>Zdarzenia</th><th>Ostatnie wywołanie</th><th>Status HTTP</th><th>Aktywny</th><th class="text-end">Akcje</th></tr>
        </thead>
        <tbody>
          <?php foreach ($endpoints as $ep):
            $ep_events = json_decode($ep['events'] ?? '[]', true) ?: [];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($ep['name']) ?></td>
            <td>
              <code style="font-size:.75rem;word-break:break-all"><?= h($ep['url']) ?></code>
              <?php if (!empty($ep['secret'])): ?>
              <div class="text-muted" style="font-size:.7rem"><i class="bi bi-key me-1"></i><code><?= h(substr($ep['secret'],0,8)) ?>…</code></div>
              <?php endif; ?>
            </td>
            <td>
              <?php foreach ($ep_events as $ev):
                $ec = AM_WEBHOOK_EVENTS[$ev][0] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $ec ?>-subtle text-<?= $ec ?> border border-<?= $ec ?>-subtle me-1" style="font-size:.68rem"><?= h($ev) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="text-muted small"><?= $ep['last_triggered_at'] ? h($ep['last_triggered_at']) : '—' ?></td>
            <td>
              <?php if ($ep['last_status'] === null): ?>
              <span class="badge bg-secondary-subtle text-secondary border">brak</span>
              <?php elseif ($ep['last_status'] >= 200 && $ep['last_status'] < 300): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i><?= $ep['last_status'] ?></span>
              <?php else: ?>
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-x-circle me-1"></i><?= $ep['last_status'] ?: 'błąd' ?></span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_section" value="webhooks">
                <input type="hidden" name="_action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" role="switch"
                         <?= $ep['is_active'] ? 'checked' : '' ?>
                         onchange="this.closest('form').submit()"
                         style="width:2em;height:1em">
                </div>
              </form>
            </td>
            <td class="text-end">
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_section" value="webhooks">
                <input type="hidden" name="_action" value="test">
                <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                <button class="btn btn-sm btn-outline-primary me-1 py-0 px-2" title="Test"><i class="bi bi-send"></i></button>
              </form>
              <button class="btn btn-sm btn-outline-secondary me-1 py-0 px-2"
                      data-bs-toggle="collapse" data-bs-target="#whlogs-<?= (int)$ep['id'] ?>"
                      title="Logi"><i class="bi bi-journal-text"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć endpoint i logi?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_section" value="webhooks">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <!-- Log row -->
          <tr class="collapse" id="whlogs-<?= (int)$ep['id'] ?>">
            <td colspan="7" class="p-0">
              <?php $logs = db_all("SELECT * FROM webhook_log WHERE endpoint_id=? ORDER BY id DESC LIMIT 15", [(int)$ep['id']]); ?>
              <div class="p-3 bg-light border-top">
                <div class="fw-semibold small mb-2"><i class="bi bi-journal-text me-1"></i>Ostatnie 15 wywołań — <?= h($ep['name']) ?></div>
                <?php if (!$logs): ?>
                <div class="text-muted small">Brak wpisów.</div>
                <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm table-striped small mb-0">
                    <thead><tr><th>Data</th><th>Zdarzenie</th><th>HTTP</th><th>Odpowiedź</th></tr></thead>
                    <tbody>
                      <?php foreach ($logs as $log): ?>
                      <tr>
                        <td class="text-nowrap"><?= h($log['triggered_at']) ?></td>
                        <td><code><?= h($log['event']) ?></code></td>
                        <td>
                          <?php if ($log['response_code'] >= 200 && $log['response_code'] < 300): ?>
                          <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $log['response_code'] ?></span>
                          <?php else: ?>
                          <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $log['response_code'] ?: '0' ?></span>
                          <?php endif; ?>
                        </td>
                        <td><code style="font-size:.72rem;word-break:break-all"><?= h($log['response_body']) ?></code></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-info d-flex gap-2 mb-4">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>Brak skonfigurowanych endpointów webhook. Dodaj pierwszy poniżej.</div>
  </div>
  <?php endif; ?>

  <!-- Formularz nowego endpointu -->
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.875rem">
      <i class="bi bi-plus-circle me-1 text-success"></i>Dodaj nowy endpoint webhook
    </div>
    <div class="card-body">
      <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_section" value="webhooks">
        <input type="hidden" name="_action" value="create">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="np. Zapier, Slack, własny CRM" required
                   value="<?= h($_POST['name'] ?? '') ?>">
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">URL endpointu <span class="text-danger">*</span></label>
            <input type="url" name="url" class="form-control" placeholder="https://hooks.zapier.com/…" required
                   value="<?= h($_POST['url'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Sekret <span class="text-muted fw-normal small">(opcjonalny)</span></label>
            <input type="text" name="secret" class="form-control font-monospace" placeholder="auto-generowany"
                   value="<?= h($_POST['secret'] ?? '') ?>">
            <div class="form-text">Nagłówek <code>X-Webhook-Secret</code></div>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label fw-semibold">Zdarzenia <span class="text-danger">*</span></label>
          <div class="d-flex flex-wrap gap-3">
            <?php foreach (AM_WEBHOOK_EVENTS as $ev => [$color, $label]):
              $checked = in_array($ev, $_POST['events'] ?? [], true) ? 'checked' : '';
            ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="events[]"
                     value="<?= h($ev) ?>" id="wev_<?= str_replace('.','_',$ev) ?>" <?= $checked ?>>
              <label class="form-check-label" for="wev_<?= str_replace('.','_',$ev) ?>">
                <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle"><?= h($ev) ?></span>
                <span class="small ms-1"><?= h($label) ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="mt-4">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-plus-circle me-1"></i>Dodaj endpoint
          </button>
        </div>
      </form>
    </div>
  </div>
</div><!-- /pane-webhooks -->


<!-- ══════════════════════════════════════════════════════════════════════════
     PANE: INTEGRACJE ZEWNĘTRZNE
══════════════════════════════════════════════════════════════════════════ -->
<div id="pane-integrations" <?= $tab !== 'integrations' ? 'style="display:none"' : '' ?>>

<?php
// Helper: karta integracji
function _int_card(string $icon, string $name, string $desc, bool $connected, string $url, string $color = '#2563eb'): void { ?>
<div class="col-sm-6 col-xl-4">
  <div class="card border-0 shadow-sm h-100">
    <div class="card-body d-flex gap-3 align-items-start py-3">
      <div style="width:42px;height:42px;border-radius:10px;background:<?= h($color) ?>18;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:<?= h($color) ?>;flex-shrink:0">
        <i class="bi <?= h($icon) ?>"></i>
      </div>
      <div class="flex-grow-1 min-width-0">
        <div class="fw-semibold" style="font-size:.88rem"><?= h($name) ?></div>
        <div class="text-muted" style="font-size:.76rem"><?= h($desc) ?></div>
        <div class="mt-1">
          <span class="badge bg-<?= $connected ? 'success' : 'secondary' ?>-subtle text-<?= $connected ? 'success' : 'secondary' ?> border border-<?= $connected ? 'success' : 'secondary' ?>-subtle" style="font-size:.7rem">
            <?= $connected ? '<i class="bi bi-check-circle me-1"></i>Połączone' : '<i class="bi bi-circle me-1"></i>Nieskonfigurowane' ?>
          </span>
        </div>
      </div>
      <a href="<?= APP_URL . h($url) ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0" title="Ustawienia">
        <i class="bi bi-gear"></i>
      </a>
    </div>
  </div>
</div>
<?php }
?>

  <div class="row g-3">

    <div class="col-12"><div class="text-muted fw-semibold small mb-1" style="text-transform:uppercase;letter-spacing:.07em"><i class="bi bi-microsoft me-1"></i>Microsoft &amp; Komunikacja</div></div>

    <?php _int_card('bi-microsoft',         'Microsoft 365',      'Synchronizacja kont, M365 Groups, SharePoint',          $int_m365,     '/admin/m365_settings.php',        '#0078d4') ?>
    <?php _int_card('bi-cloud-upload-fill', 'SharePoint',         'Przechowywanie plików i synchronizacja dokumentów',     $int_sp,       '/admin/sharepoint_settings.php',  '#038387') ?>
    <?php _int_card('bi-envelope-fill',     'E-mail (SMTP/M365)', 'Wysyłka e-mail — SMTP lub Microsoft Graph',             $int_smtp,     '/admin/org_settings.php#mail',    '#d97706') ?>
    <?php _int_card('bi-chat-text-fill',    'SMS',                'Powiadomienia SMS — API bramki',                         $int_sms,      '/admin/sms_settings.php',         '#7c3aed') ?>
    <?php _int_card('bi-whatsapp',          'WhatsApp',           'Powiadomienia przez WhatsApp Business API',             $int_whatsapp, '/admin/whatsapp_settings.php',    '#25d366') ?>
    <?php _int_card('bi-envelope-at-fill',  'Postivo',            'Wysyłka przez platformę Postivo',                       $int_postivo,  '/admin/postivo_settings.php',     '#f59e0b') ?>

    <div class="col-12 mt-2"><div class="text-muted fw-semibold small mb-1" style="text-transform:uppercase;letter-spacing:.07em"><i class="bi bi-pen-fill me-1"></i>Podpisy elektroniczne</div></div>

    <?php _int_card('bi-pen-fill',          'Autenti eSign',      'Podpisywanie dokumentów przez Autenti',                 $int_autenti,  '/admin/autenti_settings.php',     '#1e40af') ?>
    <?php _int_card('bi-pen-fill',          'DocuSign',           'Podpisywanie dokumentów przez DocuSign',                $int_docusign, '/admin/docusign_settings.php',    '#ffb900') ?>

    <div class="col-12 mt-2"><div class="text-muted fw-semibold small mb-1" style="text-transform:uppercase;letter-spacing:.07em"><i class="bi bi-box-seam me-1"></i>Logistyka &amp; Zewnętrzne</div></div>

    <?php _int_card('bi-box-seam-fill',     'Apaczka',            'Nadawanie paczek — integracja z Apaczka.pl',            $int_apaczka,  '/admin/apaczka_settings.php',     '#f97316') ?>
    <?php _int_card('bi-truck',             'Furgonetka',         'Nadawanie paczek — integracja z Furgonetka.pl',         $int_furgonetka,'/admin/furgonetka_settings.php', '#0369a1') ?>
    <?php _int_card('bi-building-check',    'CEIDG',              'Weryfikacja firm z rejestru CEIDG',                     $int_ceidg,    '/admin/ceidg_settings.php',        '#16a34a') ?>
    <?php _int_card('bi-mortarboard-fill',  'Moodle',             'Platforma e-learningowa — kursy i zapisy',              $int_moodle,   '/admin/moodle.php',                '#e97626') ?>
    <?php _int_card('bi-stars',             'AI (OpenAI/Anthropic)', 'Asystent AI do analizy i generowania treści',       $int_ai,       '/admin/ai_settings.php',           '#8b5cf6') ?>

  </div>

  <div class="mt-4 p-3 border rounded bg-light" style="font-size:.82rem">
    <i class="bi bi-info-circle me-1 text-primary"></i>
    Kliknij <i class="bi bi-gear"></i> przy dowolnej integracji, aby otworzyć jej ustawienia.
    Status <strong>Połączone</strong> oznacza że klucz/token jest skonfigurowany — nie gwarantuje aktywnego połączenia.
  </div>

</div><!-- /pane-integrations -->

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
