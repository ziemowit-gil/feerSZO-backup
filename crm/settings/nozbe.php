<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/nozbe.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!is_admin()) { flash_set('danger','Tylko administrator.'); header('Location: '.APP_URL.'/crm/dashboard.php'); exit; }
crm_migrate();

$PAGE_TITLE = 'CRM — Integracja Nozbe';

// ── AJAX: pobierz projekty po podaniu tokenu ──────────────────────────────────
if (isset($_GET['_ajax']) && $_GET['_ajax'] === 'projects') {
    header('Content-Type: application/json');
    $token = trim($_GET['token'] ?? '');
    if (!$token) { echo json_encode(['ok'=>false,'error'=>'Brak tokenu']); exit; }
    try {
        $n = new NozbeAPI($token);
        $projects = $n->get_projects();
        $list = array_map(fn($p) => ['id'=>$p['id'],'name'=>$p['name']??$p['id']], $projects);
        echo json_encode(['ok'=>true,'projects'=>$list]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $token   = trim($_POST['nozbe_api_token'] ?? '');
    $project = trim($_POST['nozbe_default_project_id'] ?? '');
    $enabled = isset($_POST['nozbe_enabled']) ? '1' : '0';

    nozbe_save_setting('nozbe_api_token', $token);
    nozbe_save_setting('nozbe_default_project_id', $project);
    nozbe_save_setting('nozbe_enabled', $enabled);

    flash_set('success','Ustawienia Nozbe zapisane.');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$cur_token   = nozbe_setting('nozbe_api_token');
$cur_project = nozbe_setting('nozbe_default_project_id');
$cur_enabled = nozbe_setting('nozbe_enabled') === '1';

// Status połączenia
$conn_status = null;
$projects    = [];
if ($cur_token) {
    try {
        $nozbe     = new NozbeAPI($cur_token);
        $projects  = $nozbe->get_projects();
        $conn_status = ['ok'=>true,'count'=>count($projects)];
    } catch (\Throwable $e) {
        $conn_status = ['ok'=>false,'error'=>$e->getMessage()];
    }
}

include __DIR__ . '/../includes/header_crm.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Nozbe</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon" style="background:#e8f5e9">
    <img src="https://nozbe.com/favicon.ico" style="width:22px;height:22px;object-fit:contain" alt="Nozbe" onerror="this.replaceWith(document.createTextNode('N'))">
  </div>
  <div>
    <h1 class="crm-object-title">Integracja Nozbe</h1>
    <div class="crm-object-count">Twórz zadania Nozbe bezpośrednio z CRM — komunikacje, follow-upy, kontakty</div>
  </div>
</div>

<?= flash_get() ?>

<?php if ($conn_status): ?>
<div class="alert alert-<?= $conn_status['ok'] ? 'success' : 'danger' ?> py-2 px-3 mb-3 d-flex align-items-center gap-2 small">
  <i class="bi bi-<?= $conn_status['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
  <?= $conn_status['ok']
    ? "Połączono z Nozbe — znaleziono <strong>{$conn_status['count']}</strong> projektów."
    : "Błąd połączenia: " . h($conn_status['error']) ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4" style="max-width:640px">
  <div class="card-header bg-white py-2 fw-semibold">Konfiguracja</div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>

      <!-- Włącz/wyłącz -->
      <div class="form-check form-switch mb-3">
        <input type="checkbox" class="form-check-input" id="nozbe_enabled" name="nozbe_enabled"
               <?= $cur_enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="nozbe_enabled">
          Integracja aktywna
        </label>
        <div class="form-text">Gdy wyłączona, opcje Nozbe nie pojawiają się w CRM.</div>
      </div>

      <!-- Token -->
      <div class="mb-3">
        <label class="form-label fw-semibold small">Token API Nozbe <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="password" name="nozbe_api_token" id="nozbeToken"
                 class="form-control font-monospace"
                 value="<?= h($cur_token) ?>"
                 placeholder="apikey zaczyna się od nozbe_...">
          <button type="button" class="btn btn-outline-secondary"
                  onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password'">
            <i class="bi bi-eye"></i>
          </button>
          <button type="button" class="btn btn-outline-primary" onclick="loadProjects()">
            <i class="bi bi-plug me-1"></i>Testuj
          </button>
        </div>
        <div class="form-text">
          Nozbe → ⚙️ Ustawienia → Tokeny API → „Dodaj nowy klucz dostępu".
          Nagłówek auth: <code>apikey &lt;token&gt;</code>
        </div>
        <div id="token-status" class="mt-1 small"></div>
      </div>

      <!-- Domyślny projekt -->
      <div class="mb-3">
        <label class="form-label fw-semibold small">Domyślny projekt</label>
        <select name="nozbe_default_project_id" id="projectSelect" class="form-select">
          <option value="">— wybierz projekt —</option>
          <?php foreach ($projects as $p): ?>
          <option value="<?= h($p['id']) ?>" <?= $cur_project === $p['id'] ? 'selected' : '' ?>>
            <?= h($p['name'] ?? $p['id']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Projekt w którym domyślnie będą tworzone zadania. Można zmieniać przy każdym zadaniu.</div>
      </div>

      <button type="submit" class="btn btn-crm-primary btn-sm">
        <i class="bi bi-save me-1"></i>Zapisz
      </button>
    </form>
  </div>
</div>

<!-- Co umożliwia integracja -->
<div class="card border-0 shadow-sm" style="max-width:640px">
  <div class="card-header bg-white py-2 fw-semibold small text-muted">Co umożliwia integracja</div>
  <ul class="list-group list-group-flush small">
    <li class="list-group-item py-2">
      <i class="bi bi-chat-dots me-2 text-primary"></i>
      <strong>Przy wysyłaniu wiadomości</strong> — checkbox „Utwórz zadanie follow-up w Nozbe" z polem terminu
    </li>
    <li class="list-group-item py-2">
      <i class="bi bi-person-fill me-2 text-success"></i>
      <strong>Na karcie kontaktu</strong> — przycisk „Dodaj zadanie w Nozbe" z nazwą kontaktu i notatką
    </li>
    <li class="list-group-item py-2">
      <i class="bi bi-megaphone me-2 text-warning"></i>
      <strong>Przy masowej wysyłce</strong> — opcjonalne zadanie podsumowujące po zakończeniu wysyłki
    </li>
  </ul>
</div>

<script>
async function loadProjects() {
  const token = document.getElementById('nozbeToken').value.trim();
  const status = document.getElementById('token-status');
  const sel    = document.getElementById('projectSelect');
  if (!token) { status.innerHTML = '<span class="text-warning">Wpisz token.</span>'; return; }

  status.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Łączenie z Nozbe…</span>';

  const res = await fetch(`?_ajax=projects&token=${encodeURIComponent(token)}`).then(r=>r.json());
  if (res.ok) {
    status.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>Połączono — ${res.projects.length} projektów</span>`;
    sel.innerHTML = '<option value="">— wybierz projekt —</option>'
      + res.projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
  } else {
    status.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${res.error}</span>`;
  }
}
</script>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
