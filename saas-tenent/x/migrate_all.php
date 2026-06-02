<?php
/**
 * Wdrażanie migracji na wszystkich tenantach.
 * Dostępne tylko dla zalogowanego admina SaaS.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';
require_once dirname(__DIR__) . '/setup/setup_sql.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION[SAAS_SESSION_KEY])) {
    header('Location: index.php'); exit;
}

$run_migration = isset($_POST['run']) && !empty($_POST['run']);
$reports       = [];

if ($run_migration) {
    // Weryfikacja CSRF
    $csrf = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['saas_csrf'] ?? '', $csrf)) {
        http_response_code(403); die('CSRF error');
    }

    $tenants = saas_all();
    foreach ($tenants as $t) {
        $slug    = $t['slug'] ?: $t['krs'];
        $db_path = TENANTS_DIR . '/' . $slug . '/umowy.db';
        $report  = [
            'org'    => $t['org_name'],
            'slug'   => $slug,
            'krs'    => $t['krs'],
            'rows'   => [],
            'error'  => null,
        ];

        if (!$t['db_ready']) {
            $report['error'] = 'Baza nie zainicjowana (db_ready=0)';
            $reports[] = $report; continue;
        }
        if (!is_file($db_path)) {
            $report['error'] = 'Plik bazy nie istnieje: ' . $db_path;
            $reports[] = $report; continue;
        }

        try {
            $pdo = new PDO('sqlite:' . $db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
            $report['rows'] = migrate_tenant_db($pdo);
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }

        $reports[] = $report;
    }
}

$csrf = saas_csrf();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migracje tenantów — SaaS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#f0f4f8; }
.saas-header { background:#1e293b; color:#fff; padding:1rem 1.5rem;
               display:flex; align-items:center; justify-content:space-between; }
.saas-header h1 { font-size:1.1rem; font-weight:700; margin:0; }
.saas-header a  { color:#94a3b8; font-size:.85rem; text-decoration:none; }
.result-ok   { color: #16a34a; }
.result-skip { color: #9ca3af; }
.result-err  { color: #dc2626; font-weight:600; }
.tenant-block { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08);
                margin-bottom:1rem; overflow:hidden; }
.tenant-head  { padding:.75rem 1rem; border-bottom:1px solid #f1f5f9;
                display:flex; align-items:center; gap:.5rem; }
.tenant-body  { padding:.75rem 1rem; font-size:.82rem; font-family:monospace; }
</style>
</head>
<body>

<div class="saas-header">
  <h1><i class="bi bi-database-gear text-warning me-2"></i>Migracje tenantów</h1>
  <a href="index.php"><i class="bi bi-arrow-left me-1"></i>Powrót</a>
</div>

<div class="container py-4" style="max-width:900px">

<?php if (!$run_migration): ?>
<!-- ── Ekran przed uruchomieniem ──────────────────────────────────────────── -->
<div class="card shadow-sm">
  <div class="card-body p-4">
    <h5 class="fw-bold mb-1"><i class="bi bi-rocket-takeoff me-2 text-primary"></i>Wdrożenie migracji</h5>
    <p class="text-muted mb-3">
      Uruchamia <code>migrate_tenant_db()</code> na każdym aktywnym tenancie.<br>
      Operacja jest <strong>bezpieczna i idempotentna</strong> — istniejące kolumny i tabele są pomijane.
    </p>
    <ul class="small text-muted mb-4">
      <li>Tworzy brakujące tabele (<code>CREATE TABLE IF NOT EXISTS</code>)</li>
      <li>Tworzy brakujące indeksy (<code>CREATE INDEX IF NOT EXISTS</code>)</li>
      <li>Dodaje brakujące kolumny (<code>ALTER TABLE ADD COLUMN</code>)</li>
      <li>Uzupełnia domyślne wpisy w <code>settings</code></li>
    </ul>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <input type="hidden" name="run"   value="1">
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('Uruchomić migracje na wszystkich tenantach?')">
        <i class="bi bi-play-circle me-1"></i>Uruchom migracje
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── Raport po uruchomieniu ────────────────────────────────────────────── -->
<?php
$total_ok   = 0; $total_skip = 0; $total_err = 0;
foreach ($reports as $r) {
    if ($r['error']) { $total_err++; continue; }
    foreach ($r['rows'] as [$s]) {
        if ($s === 'ok')   $total_ok++;
        if ($s === 'skip') $total_skip++;
        if ($s === 'err')  $total_err++;
    }
}
?>
<div class="alert alert-<?= $total_err ? 'warning' : 'success' ?> d-flex gap-3 mb-4">
  <div><i class="bi bi-check-circle-fill fs-4"></i></div>
  <div>
    <strong>Migracje zakończone</strong> — <?= count($reports) ?> tenantów<br>
    <span class="result-ok">✓ <?= $total_ok ?> wykonanych</span> &nbsp;
    <span class="result-skip">· <?= $total_skip ?> pominiętych</span> &nbsp;
    <?php if ($total_err): ?>
    <span class="result-err">✗ <?= $total_err ?> błędów</span>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($reports as $r): ?>
<?php
$r_ok   = $r['error'] ? 0 : count(array_filter($r['rows'], fn($x) => $x[0]==='ok'));
$r_skip = $r['error'] ? 0 : count(array_filter($r['rows'], fn($x) => $x[0]==='skip'));
$r_err  = $r['error'] ? 1 : count(array_filter($r['rows'], fn($x) => $x[0]==='err'));
$badge  = $r_err ? 'danger' : ($r_ok ? 'success' : 'secondary');
?>
<div class="tenant-block">
  <div class="tenant-head">
    <span class="badge bg-<?= $badge ?>"><?= $r_err ? 'BŁĄD' : ($r_ok ? 'OK' : 'POMINIĘTO') ?></span>
    <strong><?= htmlspecialchars($r['org']) ?></strong>
    <span class="text-muted small font-monospace">/org/<?= htmlspecialchars($r['slug']) ?>/</span>
    <?php if (!$r_err): ?>
    <span class="ms-auto small text-muted">
      ✓ <?= $r_ok ?> &nbsp; · <?= $r_skip ?>
    </span>
    <?php endif; ?>
  </div>
  <?php if ($r['error']): ?>
  <div class="tenant-body result-err"><?= htmlspecialchars($r['error']) ?></div>
  <?php elseif ($r_ok > 0 || $r_err > 0): ?>
  <div class="tenant-body">
    <?php foreach ($r['rows'] as [$status, $label]): ?>
    <?php if ($status !== 'skip'): ?>
    <div class="result-<?= $status ?>">
      <?= $status === 'ok' ? '✓' : '✗' ?> <?= htmlspecialchars($label) ?>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="mt-3 d-flex gap-2">
  <a href="index.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Powrót do panelu
  </a>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="run"   value="1">
    <button type="submit" class="btn btn-outline-primary">
      <i class="bi bi-arrow-repeat me-1"></i>Uruchom ponownie
    </button>
  </form>
</div>
<?php endif; ?>

</div>
</body>
</html>
