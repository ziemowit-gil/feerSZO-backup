<?php
/**
 * admin/system_info.php — Informacje o systemie: wersja, środowisko, certyfikaty.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$PAGE_TITLE  = 'Informacje o systemie';
$certs_dir   = dirname(__DIR__) . '/certs';
$upload_dir  = defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__) . '/uploads/');
$db_path     = defined('DB_PATH')    ? DB_PATH    : (dirname(__DIR__) . '/umowy.db');
$flash        = '';
$flash_type   = 'success';

// ── POST: usuń certyfikat ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'delete_cert') {
        $fn = basename($_POST['filename'] ?? '');
        if ($fn && preg_match('/^[a-zA-Z0-9_.-]+$/', $fn)) {
            $full = $certs_dir . '/' . $fn;
            if (is_file($full) && unlink($full)) {
                $flash = 'Certyfikat "' . h($fn) . '" usunięty.';
            } else {
                $flash = 'Nie można usunąć pliku.'; $flash_type = 'danger';
            }
        }
    }

    if ($act === 'upload_cert') {
        $file = $_FILES['cert_file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $fn = basename($file['name']);
            if (!preg_match('/\.(pem|crt|cer|key|p12|pfx)$/i', $fn)) {
                $flash = 'Niedozwolone rozszerzenie. Dozwolone: .pem .crt .cer .key .p12 .pfx';
                $flash_type = 'danger';
            } else {
                if (!is_dir($certs_dir)) mkdir($certs_dir, 0750, true);
                $dest = $certs_dir . '/' . $fn;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    chmod($dest, 0640);
                    $flash = 'Certyfikat "' . h($fn) . '" wgrany.';
                } else {
                    $flash = 'Błąd zapisu pliku.'; $flash_type = 'danger';
                }
            }
        } else {
            $flash = 'Nie przesłano pliku lub błąd uploadu.'; $flash_type = 'danger';
        }
    }

    header('Location: ' . APP_URL . '/admin/system_info.php?msg=' . urlencode($flash) . '&type=' . $flash_type);
    exit;
}

if (!empty($_GET['msg'])) { $flash = $_GET['msg']; $flash_type = $_GET['type'] ?? 'success'; }

// ── Dane systemu ──────────────────────────────────────────────────────────
$php_ver    = PHP_VERSION;
$db_size    = file_exists($db_path) ? round(filesize($db_path) / 1024) . ' KB' : 'n/d';
$upload_size = is_dir($upload_dir) ? _dir_size($upload_dir) : 'n/d';
$env        = defined('APP_ENV')     ? APP_ENV     : 'unknown';
$version    = defined('APP_VERSION') ? APP_VERSION : '?';

// Ilość rekordów
$record_counts = [];
foreach (['users','umowy_wolontariat','tasks','crm_contacts','ev_events','persons'] as $t) {
    try { $record_counts[$t] = (int)db()->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (\Throwable $e) { $record_counts[$t] = null; }
}

// Certyfikaty
$certs = [];
if (is_dir($certs_dir)) {
    foreach (scandir($certs_dir) as $fn) {
        if ($fn[0] === '.') continue;
        $fp = $certs_dir . '/' . $fn;
        if (!is_file($fp)) continue;
        $info = ['name' => $fn, 'size' => round(filesize($fp) / 1024, 1) . ' KB',
                 'mtime' => date('d.m.Y H:i', filemtime($fp)), 'valid_to' => null];
        if (extension_loaded('openssl') && preg_match('/\.(pem|crt|cer)$/i', $fn)) {
            $pem = file_get_contents($fp);
            $cert = @openssl_x509_parse($pem);
            if ($cert) $info['valid_to'] = date('d.m.Y', $cert['validTo_time_t']);
        }
        $certs[] = $info;
    }
}

function _dir_size(string $dir): string {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) $size += $f->getSize();
    }
    if ($size > 1048576) return round($size / 1048576, 1) . ' MB';
    return round($size / 1024) . ' KB';
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Informacje o systemie</li>
  </ol>
</nav>

<h4 class="mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Informacje o systemie</h4>

<?php if ($flash): ?>
<div class="alert alert-<?= h($flash_type) ?> py-2 small"><?= h($flash) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">

  <!-- Wersja i środowisko -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold py-2"><i class="bi bi-tag me-1"></i>Wersja aplikacji</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th style="width:45%">Wersja</th><td><span class="badge bg-primary fs-6"><?= h($version) ?></span></td></tr>
          <tr><th>Środowisko</th><td>
            <?php
            $env_cls = match($env) {
                'production' => 'danger', 'staging' => 'warning', default => 'secondary'
            };
            echo '<span class="badge bg-' . $env_cls . '">' . h(strtoupper($env)) . '</span>';
            ?>
          </td></tr>
          <tr><th>PHP</th><td><?= h($php_ver) ?></td></tr>
          <tr><th>Baza danych</th><td>SQLite · <?= h($db_size) ?></td></tr>
          <tr><th>Pliki (uploads)</th><td><?= h($upload_size) ?></td></tr>
          <tr><th>APP_URL</th><td><code style="font-size:.78rem"><?= h(APP_URL) ?></code></td></tr>
          <tr><th>ORG_NAME</th><td><?= h(defined('ORG_NAME') ? ORG_NAME : '?') ?></td></tr>
        </table>
      </div>
    </div>
  </div>

  <!-- Rekordy -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold py-2"><i class="bi bi-database me-1"></i>Dane w bazie</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <?php
          $labels = ['users'=>'Użytkownicy','umowy_wolontariat'=>'Umowy wolontariatu',
                     'tasks'=>'Zadania','crm_contacts'=>'Kontakty CRM',
                     'ev_events'=>'Wydarzenia','persons'=>'Osoby'];
          foreach ($record_counts as $t => $cnt): ?>
          <tr>
            <th><?= h($labels[$t] ?? $t) ?></th>
            <td><?= $cnt !== null ? number_format($cnt, 0, ',', ' ') : '<em class="text-muted">brak tabeli</em>' ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Certyfikaty -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between py-2">
    <span class="fw-semibold"><i class="bi bi-shield-lock me-1"></i>Certyfikaty (<code>certs/</code>)</span>
    <button class="btn btn-sm btn-outline-primary" type="button"
            data-bs-toggle="collapse" data-bs-target="#uploadCertCollapse">
      <i class="bi bi-upload me-1"></i>Wgraj certyfikat
    </button>
  </div>

  <div class="collapse" id="uploadCertCollapse">
    <div class="card-body border-bottom bg-light py-3">
      <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="upload_cert">
        <div class="col-sm-8">
          <label class="form-label small fw-semibold">Plik certyfikatu (.pem, .crt, .cer, .key, .p12, .pfx)</label>
          <input type="file" name="cert_file" class="form-control form-control-sm"
                 accept=".pem,.crt,.cer,.key,.p12,.pfx" required>
        </div>
        <div class="col-sm-4">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-upload me-1"></i>Wgraj</button>
        </div>
      </form>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Plik</th><th>Rozmiar</th><th>Ważny do</th><th>Modyfikacja</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$certs): ?>
        <tr><td colspan="5" class="text-muted text-center py-3">Brak certyfikatów w katalogu <code>certs/</code>.</td></tr>
        <?php endif; ?>
        <?php foreach ($certs as $c): ?>
        <?php
        $expired = $c['valid_to'] && strtotime(str_replace('.', '-', $c['valid_to'])) < time();
        $soon    = $c['valid_to'] && !$expired && strtotime(str_replace('.', '-', $c['valid_to'])) < strtotime('+30 days');
        ?>
        <tr class="<?= $expired ? 'table-danger' : ($soon ? 'table-warning' : '') ?>">
          <td><i class="bi bi-file-earmark-lock2 me-1 text-muted"></i><code><?= h($c['name']) ?></code></td>
          <td><?= h($c['size']) ?></td>
          <td>
            <?php if ($c['valid_to']): ?>
            <?= h($c['valid_to']) ?>
            <?= $expired ? '<span class="badge bg-danger ms-1">WYGASŁ</span>' : ($soon ? '<span class="badge bg-warning text-dark ms-1">Wkrótce</span>' : '') ?>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= h($c['mtime']) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć certyfikat <?= h(addslashes($c['name'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_cert">
              <input type="hidden" name="filename" value="<?= h($c['name']) ?>">
              <button class="btn btn-outline-danger btn-sm py-0 px-2">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
