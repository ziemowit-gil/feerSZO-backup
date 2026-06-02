<?php
/**
 * Panel SaaS — zarządzanie tenantami
 * Dostęp: /saas/index.php  (chroniony hasłem z auth.php)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';

session_start();

// ── Autoryzacja ───────────────────────────────────────────────────────────────
if ($_POST['_login'] ?? false) {
    if ($_POST['password'] === SAAS_PASSWORD) {
        $_SESSION[SAAS_SESSION_KEY] = true;
        header('Location: index.php'); exit;
    }
    $login_error = 'Nieprawidłowe hasło.';
}
if ($_GET['_logout'] ?? false) {
    unset($_SESSION[SAAS_SESSION_KEY]);
    header('Location: index.php'); exit;
}

$authed = !empty($_SESSION[SAAS_SESSION_KEY]);

// ── Akcje POST (tylko zalogowany) ─────────────────────────────────────────────
$flash = '';
$provisioned = null;

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $krs = preg_replace('/\D/', '', trim($_POST['krs'] ?? ''));
        $org = trim($_POST['org_name'] ?? '');
        if (!$krs || !$org) {
            $flash = ['err', 'KRS i nazwa organizacji są wymagane.'];
        } elseif (saas_get_by_krs($krs)) {
            $flash = ['err', "Tenant o KRS $krs już istnieje."];
        } else {
            $slug = saas_make_slug($org);
            $id = saas_create([
                'krs'              => $krs,
                'slug'             => $slug,
                'org_name'         => $org,
                'admin_email'      => trim($_POST['admin_email']      ?? ''),
                'admin_name'       => trim($_POST['admin_name']       ?? 'Administrator'),
                'plan'             => $_POST['plan']                  ?? 'standard',
                'ms_enabled'       => !empty($_POST['ms_enabled'])    ? 1 : 0,
                'ms_tenant_id'     => trim($_POST['ms_tenant_id']     ?? ''),
                'ms_client_id'     => trim($_POST['ms_client_id']     ?? ''),
                'ms_client_secret' => trim($_POST['ms_client_secret'] ?? ''),
                'notes'            => trim($_POST['notes']            ?? ''),
                'crm_enabled'      => !empty($_POST['crm_enabled'])    ? 1 : 0,
                'crm_standalone'   => !empty($_POST['crm_standalone']) ? 1 : 0,
            ]);
            $tenant = saas_get($id);
            try {
                $provisioned = saas_provision($tenant);
                $flash = ['ok', "Tenant <strong>$org</strong> (KRS: $krs) utworzony pomyślnie."];
            } catch (\Throwable $e) {
                $flash = ['err', 'Błąd podczas tworzenia tenanta: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $t  = saas_get($id);
        if ($t) {
            saas_update($id, ['is_active' => $t['is_active'] ? 0 : 1]);
            $flash = ['ok', 'Status zmieniony.'];
        }
        header('Location: index.php'); exit;
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id']     ?? 0);
        $krs = trim($_POST['confirm'] ?? '');
        $t   = saas_get($id);
        if ($t && $t['krs'] === $krs) {
            saas_delete($id);
            $flash = ['ok', "Tenant <strong>{$t['org_name']}</strong> usunięty wraz z danymi."];
        } else {
            $flash = ['err', 'Błędne potwierdzenie KRS. Tenant nie został usunięty.'];
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $crm_was = (int)(saas_get($id)['crm_enabled'] ?? 0);
        saas_update($id, [
            'org_name'         => trim($_POST['org_name']         ?? ''),
            'admin_email'      => trim($_POST['admin_email']      ?? ''),
            'plan'             => $_POST['plan']                  ?? 'standard',
            'ms_enabled'       => !empty($_POST['ms_enabled'])    ? 1 : 0,
            'ms_tenant_id'     => trim($_POST['ms_tenant_id']     ?? ''),
            'ms_client_id'     => trim($_POST['ms_client_id']     ?? ''),
            'ms_client_secret' => trim($_POST['ms_client_secret'] ?? ''),
            'notes'            => trim($_POST['notes']            ?? ''),
            'crm_enabled'      => !empty($_POST['crm_enabled'])    ? 1 : 0,
            'crm_standalone'   => !empty($_POST['crm_standalone']) ? 1 : 0,
        ]);
        // Zsynchronizuj crm_enabled z bazą tenanta jeśli stan się zmienił
        $crm_now = !empty($_POST['crm_enabled']) ? 1 : 0;
        if ($crm_was !== $crm_now) saas_sync_crm($id);
        // Aktualizuj tenant.php jeśli DB istnieje
        $t = saas_get($id);
        if ($t) {
            $cfg_file = TENANTS_DIR . '/' . ($t['slug'] ?: $t['krs']) . '/tenant.php';
            if (is_file($cfg_file)) {
                $old = include $cfg_file;
                $new = "<?php\nreturn [\n"
                     . "    'org_name'        => " . var_export($_POST['org_name'] ?? $t['org_name'], true) . ",\n"
                     . "    'app_key'         => " . var_export($old['app_key'] ?? '', true) . ",\n"
                     . "    'upload_dir'      => " . var_export($old['upload_dir'] ?? '', true) . ",\n"
                     . "    'ms_enabled'      => " . (!empty($_POST['ms_enabled']) ? 'true' : 'false') . ",\n"
                     . "    'ms_tenant_id'    => " . var_export(trim($_POST['ms_tenant_id'] ?? ''), true) . ",\n"
                     . "    'ms_client_id'    => " . var_export(trim($_POST['ms_client_id'] ?? ''), true) . ",\n"
                     . "    'ms_client_secret'=> " . var_export(trim($_POST['ms_client_secret'] ?? ''), true) . ",\n"
                     . "    'crm_standalone'  => " . (!empty($_POST['crm_standalone']) ? 'true' : 'false') . ",\n"
                     . "];\n";
                file_put_contents($cfg_file, $new);
            }
        }
        $flash = ['ok', 'Tenant zaktualizowany.'];
        header('Location: index.php'); exit;
    }

    if ($action === 'sync_pass') {
        $n = saas_sync_master_pass();
        $flash = ['ok', "Hasło SaaS zsynchronizowane w $n tenant(ach)."];
        header('Location: index.php'); exit;
    }

    if ($action === 'toggle_crm') {
        $id = (int)($_POST['id'] ?? 0);
        $t  = saas_get($id);
        if ($t) {
            $new_val = $t['crm_enabled'] ? 0 : 1;
            saas_update($id, ['crm_enabled' => $new_val]);
            saas_sync_crm($id);
            $flash = ['ok', 'Moduł CRM ' . ($new_val ? 'włączony' : 'wyłączony') . '.'];
        }
        header('Location: index.php'); exit;
    }

    if ($action === 'crm_login') {
        $id = (int)($_POST['id'] ?? 0);
        $t  = saas_get($id);
        if ($t && $t['db_ready'] && $t['is_active'] && $t['crm_enabled']) {
            $url = saas_crm_login_url($t);
            if ($url) { header('Location: ' . $url); exit; }
        }
        $flash = ['err', 'Nie można otworzyć CRM tego tenanta.'];
        header('Location: index.php'); exit;
    }
}

$tenants = $authed ? saas_all() : [];
$csrf    = saas_csrf();

// ── Oblicz base URL ───────────────────────────────────────────────────────────
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$plan_labels = ['free' => 'Free', 'standard' => 'Standard', 'pro' => 'Pro'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel SaaS — Rejestr Umów</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#f0f4f8; }
.saas-header { background:#1e293b; color:#fff; padding:1rem 1.5rem;
               display:flex; align-items:center; justify-content:space-between; }
.saas-header h1 { font-size:1.1rem; font-weight:700; margin:0; }
.saas-header a  { color:#94a3b8; font-size:.85rem; text-decoration:none; }
.saas-header a:hover { color:#fff; }
.tenant-url { font-family:monospace; font-size:.8rem; color:#0d6efd; }
</style>
</head>
<body>

<?php if (!$authed): ?>
<!-- ── Logowanie ─────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-center" style="min-height:100vh">
  <div class="card shadow" style="width:340px">
    <div class="card-body p-4">
      <h4 class="mb-1 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Panel SaaS</h4>
      <p class="text-muted small mb-3">Podaj hasło administratora SaaS.</p>
      <?php if (!empty($login_error)): ?>
      <div class="alert alert-danger py-2 small"><?= htmlspecialchars($login_error) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_login" value="1">
        <div class="mb-3">
          <input type="password" name="password" class="form-control" placeholder="Hasło" autofocus>
        </div>
        <button type="submit" class="btn btn-primary w-100">Zaloguj</button>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Panel główny ───────────────────────────────────────────────────────── -->
<div class="saas-header">
  <h1><i class="bi bi-cloud-fill text-warning me-2"></i>SaaS — Rejestr Umów</h1>
  <div class="d-flex align-items-center gap-3">
    <a href="fakturownia.php" class="btn btn-sm btn-outline-light" style="font-size:.8rem"
       title="Fakturowanie tenantów przez fakturownia.pl">
      <i class="bi bi-receipt me-1"></i>Fakturowanie
    </a>
    <a href="migrate_all.php" class="btn btn-sm btn-outline-light" style="font-size:.8rem"
       title="Wdróż migracje schematu na wszystkich tenantach">
      <i class="bi bi-database-gear me-1"></i>Migracje
    </a>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <input type="hidden" name="_action" value="sync_pass">
      <button type="submit" class="btn btn-sm btn-outline-light" style="font-size:.8rem"
              title="Zaktualizuj hasło konta serwis@local we wszystkich tenantach"
              onclick="return confirm('Zaktualizować hasło SaaS we wszystkich tenantach?')">
        <i class="bi bi-arrow-repeat me-1"></i>Sync hasła SaaS
      </button>
    </form>
    <a href="<?= htmlspecialchars(rtrim($base_url . (function() {
        $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $appDir  = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
        return ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
    })(), '/') . '/select_org.php') ?>" target="_blank">
      <i class="bi bi-grid-3x3-gap me-1"></i>Widok publiczny
    </a>
    <a href="?_logout=1"><i class="bi bi-box-arrow-right me-1"></i>Wyloguj</a>
  </div>
</div>

<div class="container-fluid py-4" style="max-width:1200px">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0]==='ok' ? 'success' : 'danger' ?> d-flex align-items-center gap-2">
  <i class="bi bi-<?= $flash[0]==='ok' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
  <div><?= $flash[1] ?></div>
</div>
<?php endif; ?>

<?php if ($provisioned): ?>
<div class="alert alert-warning border-warning">
  <i class="bi bi-key-fill me-2"></i>
  <strong>Dane dostępu do nowego tenanta (zapisz teraz!):</strong><br>
  Login: <code><?= htmlspecialchars($provisioned['admin_email']) ?></code> &nbsp;
  Hasło: <code><?= htmlspecialchars($provisioned['admin_pass']) ?></code>
</div>
<?php endif; ?>

<!-- Pasek akcji -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="mb-0"><i class="bi bi-buildings me-1"></i> Organizacje (<?= count($tenants) ?>)</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreate">
    <i class="bi bi-plus-lg me-1"></i> Nowa organizacja
  </button>
</div>

<!-- ── Tabela tenantów ─────────────────────────────────────────────────────── -->
<?php if (!$tenants): ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-buildings display-4 opacity-25"></i><br>
    Brak organizacji. Kliknij <strong>Nowa organizacja</strong>, aby dodać pierwszą.
  </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark">
    <tr>
      <th>Organizacja</th>
      <th>KRS / URL</th>
      <th>Admin</th>
      <th>Plan</th>
      <th>Status</th>
      <th class="text-center" title="Moduł CRM"><i class="bi bi-diagram-2-fill"></i></th>
      <th>Baza</th>
      <th>Utworzono</th>
      <th class="text-end">Akcje</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($tenants as $t):
    $tenant_url = $base_url . '/org/' . ($t['slug'] ?: $t['krs']) . '/';
    $dir_exists = is_dir(TENANTS_DIR . '/' . ($t['slug'] ?: $t['krs']));
  ?>
  <tr>
    <td>
      <div class="fw-semibold"><?= htmlspecialchars($t['org_name']) ?></div>
      <?php if ($t['notes']): ?>
      <div class="text-muted small"><?= htmlspecialchars(mb_substr($t['notes'], 0, 60)) ?></div>
      <?php endif; ?>
    </td>
    <td>
      <div class="font-monospace small text-muted"><?= htmlspecialchars($t['krs']) ?></div>
      <?php if ($t['is_active'] && $t['db_ready']): ?>
      <a href="<?= htmlspecialchars($tenant_url) ?>" target="_blank"
         class="tenant-url d-block"><?= htmlspecialchars($tenant_url) ?></a>
      <?php endif; ?>
    </td>
    <td class="small"><?= htmlspecialchars($t['admin_email'] ?: '—') ?></td>
    <td>
      <span class="badge bg-<?= $t['plan']==='pro' ? 'warning text-dark' : ($t['plan']==='standard' ? 'primary' : 'secondary') ?>">
        <?= $plan_labels[$t['plan']] ?? $t['plan'] ?>
      </span>
    </td>
    <td>
      <span class="badge <?= $t['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
        <?= $t['is_active'] ? 'Aktywny' : 'Wyłączony' ?>
      </span>
    </td>
    <td class="text-center">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="_action" value="toggle_crm">
        <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
        <button type="submit"
                class="btn btn-xs btn-sm py-0 px-2 <?= $t['crm_enabled'] ? 'btn-primary' : 'btn-outline-secondary' ?>"
                title="<?= $t['crm_enabled'] ? 'CRM włączony — kliknij by wyłączyć' : 'CRM wyłączony — kliknij by włączyć' ?>">
          <i class="bi bi-diagram-2-fill"></i>
        </button>
      </form>
    </td>
    <td>
      <?php if ($t['db_ready'] && $dir_exists): ?>
        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
          <i class="bi bi-database-check"></i> OK
        </span>
      <?php elseif (!$dir_exists): ?>
        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
          <i class="bi bi-exclamation-triangle"></i> Brak plików
        </span>
      <?php else: ?>
        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
          <i class="bi bi-hourglass"></i> Nie zainicjowana
        </span>
      <?php endif; ?>
    </td>
    <td class="small text-muted"><?= date('d.m.Y', strtotime($t['created_at'])) ?></td>
    <td class="text-end">
      <div class="d-flex gap-1 justify-content-end">
        <!-- Edytuj -->
        <button class="btn btn-sm btn-outline-secondary" title="Edytuj"
                onclick="openEdit(<?= htmlspecialchars(json_encode($t)) ?>)">
          <i class="bi bi-pencil"></i>
        </button>
        <!-- Toggle aktywności -->
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-<?= $t['is_active'] ? 'warning' : 'success' ?>"
                  title="<?= $t['is_active'] ? 'Wyłącz' : 'Włącz' ?>">
            <i class="bi bi-<?= $t['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
          </button>
        </form>
        <!-- Zaloguj do panelu (SSO — otwiera nową kartę) -->
        <?php if ($t['is_active'] && $t['db_ready']): ?>
        <form method="get" action="tenant_login.php" target="_blank" class="d-inline">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-primary"
                  title="Zaloguj jako admin tenanta — system główny (SSO, nowa karta)">
            <i class="bi bi-box-arrow-in-right"></i>
          </button>
        </form>
        <!-- Wejdź do CRM tenanta (SSO) -->
        <?php if ($t['crm_enabled']): ?>
        <form method="post" target="_blank" class="d-inline">
          <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="crm_login">
          <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm btn-primary"
                  title="Wejdź do CRM tenanta (SSO, nowa karta)">
            <i class="bi bi-diagram-2-fill"></i>
          </button>
        </form>
        <?php endif; ?>
        <!-- Backup -->
        <a href="backup.php?id=<?= (int)$t['id'] ?>"
           class="btn btn-sm btn-outline-secondary" title="Pobierz backup">
          <i class="bi bi-download"></i>
        </a>
        <?php endif; ?>
        <!-- Usuń -->
        <button class="btn btn-sm btn-outline-danger" title="Usuń"
                onclick="openDelete(<?= (int)$t['id'] ?>, '<?= htmlspecialchars($t['krs']) ?>', '<?= htmlspecialchars($t['org_name']) ?>')">
          <i class="bi bi-trash3"></i>
        </button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL — Nowa organizacja
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalCreate" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
  <form method="post">
  <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
  <input type="hidden" name="_action" value="create">
  <div class="modal-header">
    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nowa organizacja</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold small">KRS <span class="text-danger">*</span></label>
        <div class="input-group input-group-sm">
          <input type="text" name="krs" id="createKrs" class="form-control font-monospace"
                 placeholder="np. 0000123456" maxlength="20" required>
          <button type="button" class="btn btn-outline-secondary" id="btnKrsLookup"
                  title="Pobierz dane z API KRS">
            <i class="bi bi-search" id="krsLookupIcon"></i>
          </button>
        </div>
        <div id="krsLookupMsg" class="form-text"></div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Nazwa organizacji <span class="text-danger">*</span></label>
        <input type="text" name="org_name" id="createOrgName" class="form-control form-control-sm" required
               placeholder="Fundacja Przykładowa">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">E-mail admina</label>
        <input type="email" name="admin_email" class="form-control form-control-sm"
               placeholder="admin@organizacja.pl">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Imię i nazwisko admina</label>
        <input type="text" name="admin_name" class="form-control form-control-sm"
               placeholder="Administrator" value="Administrator">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Plan</label>
        <select name="plan" class="form-select form-select-sm">
          <option value="free">Free</option>
          <option value="standard" selected>Standard</option>
          <option value="pro">Pro</option>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold small">Notatki</label>
        <input type="text" name="notes" class="form-control form-control-sm"
               placeholder="Opcjonalne uwagi...">
      </div>

      <!-- Moduły -->
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="crm_enabled" id="crm_en" value="1"
                 onchange="document.getElementById('crm_standalone_wrap').style.display=this.checked?'':'none'">
          <label class="form-check-label fw-semibold small" for="crm_en">
            <i class="bi bi-diagram-2-fill text-primary me-1"></i>Moduł CRM
          </label>
        </div>
        <div class="text-muted" style="font-size:.75rem;margin-left:2.5rem">
          Kontakty, grupy, komunikacja — dostępne po zalogowaniu do <code>/crm/</code>
        </div>
        <div id="crm_standalone_wrap" class="ms-4 mt-2" style="display:none">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="crm_standalone" id="crm_sa" value="1">
            <label class="form-check-label small" for="crm_sa">
              <strong>CRM Standalone</strong> — tenant działa <em>wyłącznie</em> jako CRM (bez systemu głównego)
            </label>
          </div>
          <div class="text-muted" style="font-size:.72rem;margin-left:2.1rem">
            Logowanie i przekierowania trafiają bezpośrednio do <code>/crm/</code>.
          </div>
        </div>
      </div>

      <!-- Microsoft 365 -->
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="ms_enabled" id="ms_en" value="1"
                 onchange="document.getElementById('ms365Fields').style.display=this.checked?'':'none'">
          <label class="form-check-label fw-semibold small" for="ms_en">Integracja Microsoft 365</label>
        </div>
      </div>
      <div id="ms365Fields" class="col-12 row g-2 ms-1" style="display:none">
        <div class="col-md-4">
          <label class="form-label small">Tenant ID</label>
          <input type="text" name="ms_tenant_id" class="form-control form-control-sm font-monospace" placeholder="xxxxxxxx-xxxx-...">
        </div>
        <div class="col-md-4">
          <label class="form-label small">Client ID</label>
          <input type="text" name="ms_client_id" class="form-control form-control-sm font-monospace">
        </div>
        <div class="col-md-4">
          <label class="form-label small">Client Secret</label>
          <input type="password" name="ms_client_secret" class="form-control form-control-sm">
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-rocket-takeoff me-1"></i> Utwórz i uruchom
    </button>
  </div>
  </form>
</div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL — Edycja tenanta
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEdit" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
  <form method="post">
  <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
  <input type="hidden" name="_action" value="edit">
  <input type="hidden" name="id"      id="editId">
  <div class="modal-header">
    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edytuj organizację</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label fw-semibold small">Nazwa organizacji</label>
        <input type="text" name="org_name" id="editOrgName" class="form-control form-control-sm" required>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Plan</label>
        <select name="plan" id="editPlan" class="form-select form-select-sm">
          <option value="free">Free</option>
          <option value="standard">Standard</option>
          <option value="pro">Pro</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">E-mail admina</label>
        <input type="email" name="admin_email" id="editAdminEmail" class="form-control form-control-sm">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Notatki</label>
        <input type="text" name="notes" id="editNotes" class="form-control form-control-sm">
      </div>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="crm_enabled" id="editCrmEn" value="1"
                 onchange="document.getElementById('editCrmStandaloneWrap').style.display=this.checked?'':'none'">
          <label class="form-check-label fw-semibold small" for="editCrmEn">
            <i class="bi bi-diagram-2-fill text-primary me-1"></i>Moduł CRM
          </label>
        </div>
        <div id="editCrmStandaloneWrap" class="ms-4 mt-2" style="display:none">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="crm_standalone" id="editCrmSa" value="1">
            <label class="form-check-label small" for="editCrmSa">
              <strong>CRM Standalone</strong> — tenant działa wyłącznie jako CRM
            </label>
          </div>
          <div class="text-muted" style="font-size:.72rem;margin-left:2.1rem">
            Logowanie i przekierowania trafiają bezpośrednio do <code>/crm/</code>.
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="ms_enabled" id="editMsEn" value="1"
                 onchange="document.getElementById('editMs365').style.display=this.checked?'':'none'">
          <label class="form-check-label fw-semibold small" for="editMsEn">Integracja M365</label>
        </div>
      </div>
      <div id="editMs365" class="col-12 row g-2 ms-1" style="display:none">
        <div class="col-md-4">
          <label class="form-label small">Tenant ID</label>
          <input type="text" name="ms_tenant_id" id="editMsTenantId" class="form-control form-control-sm font-monospace">
        </div>
        <div class="col-md-4">
          <label class="form-label small">Client ID</label>
          <input type="text" name="ms_client_id" id="editMsClientId" class="form-control form-control-sm font-monospace">
        </div>
        <div class="col-md-4">
          <label class="form-label small">Client Secret</label>
          <input type="password" name="ms_client_secret" id="editMsClientSecret" class="form-control form-control-sm"
                 placeholder="(pozostaw puste by nie zmieniać)">
        </div>
      </div>

      <!-- Lokalizacja plików -->
      <div class="col-12 border-top pt-3 mt-1">
        <label class="form-label fw-semibold small"><i class="bi bi-folder2 me-1"></i>Fizyczna lokalizacja plików (opcjonalne)</label>
        <input type="text" name="upload_dir" id="editUploadDir" class="form-control form-control-sm font-monospace"
               placeholder="np. /var/backups/tenant_uploads/  (puste = domyślna)">
        <div class="form-text">Bezwzględna ścieżka do katalogu uploads. Domyślnie: <code>tenants/{slug}/uploads/</code>.</div>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small"><i class="bi bi-archive me-1"></i>Ścieżka backupów (opcjonalne)</label>
        <input type="text" name="backup_path" id="editBackupPath" class="form-control form-control-sm font-monospace"
               placeholder="np. /var/backups/  (puste = do pobrania przez przeglądarkę)">
        <div class="form-text">Katalog, do którego będą zapisywane automatyczne backupy.</div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
    <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Zapisz</button>
  </div>
  </form>
</div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL — Usuń tenanta
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalDelete" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
  <form method="post">
  <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
  <input type="hidden" name="_action" value="delete">
  <input type="hidden" name="id"      id="deleteId">
  <div class="modal-header bg-danger text-white">
    <h5 class="modal-title"><i class="bi bi-trash3 me-2"></i>Usuń organizację</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <p>Usunięcie tenanta <strong id="deleteOrg"></strong> jest <strong>nieodwracalne</strong>.</p>
    <p class="text-danger small mb-3">Zostaną usunięte: baza danych, wszystkie pliki, konfiguracja.</p>
    <label class="form-label fw-semibold small">Wpisz KRS, aby potwierdzić:</label>
    <input type="text" name="confirm" class="form-control font-monospace" id="deleteConfirm"
           placeholder="np. 0000123456" required>
    <div class="form-text">Oczekiwany KRS: <code id="deleteKrsHint"></code></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
    <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Usuń permanentnie</button>
  </div>
  </form>
</div>
</div>
</div>

<?php endif; // $authed ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEdit(t) {
    document.getElementById('editId').value        = t.id;
    document.getElementById('editOrgName').value   = t.org_name;
    document.getElementById('editPlan').value      = t.plan;
    document.getElementById('editAdminEmail').value= t.admin_email;
    document.getElementById('editNotes').value     = t.notes;
    var crmEn = parseInt(t.crm_enabled) === 1;
    document.getElementById('editCrmEn').checked  = crmEn;
    document.getElementById('editCrmSa').checked  = parseInt(t.crm_standalone) === 1;
    document.getElementById('editCrmStandaloneWrap').style.display = crmEn ? '' : 'none';
    var msEn = parseInt(t.ms_enabled) === 1;
    document.getElementById('editMsEn').checked    = msEn;
    document.getElementById('editMs365').style.display = msEn ? '' : 'none';
    document.getElementById('editMsTenantId').value  = t.ms_tenant_id;
    document.getElementById('editMsClientId').value  = t.ms_client_id;
    document.getElementById('editUploadDir').value   = t.upload_dir  || '';
    document.getElementById('editBackupPath').value  = t.backup_path || '';
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}

function openDelete(id, krs, org) {
    document.getElementById('deleteId').value      = id;
    document.getElementById('deleteOrg').textContent = org;
    document.getElementById('deleteKrsHint').textContent = krs;
    document.getElementById('deleteConfirm').value = '';
    new bootstrap.Modal(document.getElementById('modalDelete')).show();
}

// ── KRS Lookup ────────────────────────────────────────────────────────────────
document.getElementById('btnKrsLookup')?.addEventListener('click', async function() {
    const krsInput = document.getElementById('createKrs');
    const orgInput = document.getElementById('createOrgName');
    const msg      = document.getElementById('krsLookupMsg');
    const icon     = document.getElementById('krsLookupIcon');
    const krs      = krsInput.value.replace(/\D/g, '');

    if (krs.length < 6) {
        msg.innerHTML = '<span class="text-danger">Wpisz numer KRS (min. 6 cyfr).</span>';
        return;
    }

    this.disabled = true;
    icon.className = 'bi bi-hourglass-split';
    msg.innerHTML  = '<span class="text-muted">Pobieranie z KRS API…</span>';

    try {
        const res  = await fetch('krs_lookup.php?krs=' + encodeURIComponent(krs));
        const data = await res.json();
        if (data.ok) {
            orgInput.value = data.org_name;
            krsInput.value = data.krs;
            msg.innerHTML  = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Pobrano dane (rejestr ' + data.rejestr + ').</span>';
            icon.className = 'bi bi-check-lg';
        } else {
            msg.innerHTML  = '<span class="text-danger">' + (data.error || 'Błąd') + '</span>';
            icon.className = 'bi bi-search';
            this.disabled  = false;
        }
    } catch (e) {
        msg.innerHTML  = '<span class="text-danger">Błąd połączenia z API KRS.</span>';
        icon.className = 'bi bi-search';
        this.disabled  = false;
    }
});
</script>
</body>
</html>
