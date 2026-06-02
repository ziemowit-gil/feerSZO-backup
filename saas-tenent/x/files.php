<?php
/**
 * Inspektor plików i system diff/snapshot — Panel SaaS
 * Dostęp: /saas/files.php (chroniony, tylko zalogowany admin SaaS)
 */
require_once __DIR__ . '/master.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION[SAAS_SESSION_KEY])) {
    header('Location: index.php'); exit;
}

// ── Stałe ──────────────────────────────────────────────────────────────────
$APP_ROOT       = dirname(dirname(__DIR__));               // katalog główny aplikacji
$SNAPSHOTS_DIR  = __DIR__ . '/snapshots';

// ── Helper: skanuj pliki aplikacji ─────────────────────────────────────────
/**
 * Zwraca tablicę ['rel/path' => ['md5'=>..., 'size'=>..., 'mtime'=>...], ...]
 * dla wszystkich .php, .css, .js rekurencyjnie z $APP_ROOT,
 * z wyłączeniem: tenants/, saas-tenent/x/snapshots/, vendor/, node_modules/, .git/, *.db
 */
function scan_app_files(string $app_root): array {
    $result  = [];
    $exclude = [
        realpath($app_root . '/tenants'),
        realpath($app_root . '/saas-tenent/x/snapshots'),
        realpath($app_root . '/vendor'),
        realpath($app_root . '/node_modules'),
        realpath($app_root . '/.git'),
    ];
    // Filtruj false (nieistniejące katalogi)
    $exclude = array_filter($exclude);

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($app_root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iter as $file) {
        /** @var SplFileInfo $file */
        $real = $file->getRealPath();
        if ($real === false) continue;

        // Sprawdź wykluczenia katalogowe
        $skip = false;
        foreach ($exclude as $ex) {
            if ($ex !== '' && str_starts_with($real, $ex . DIRECTORY_SEPARATOR)) {
                $skip = true; break;
            }
            if ($real === $ex) { $skip = true; break; }
        }
        if ($skip) continue;

        // Tylko .php, .css, .js
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'css', 'js'], true)) continue;

        $rel = ltrim(str_replace($app_root, '', $real), DIRECTORY_SEPARATOR . '/');
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

        $result[$rel] = [
            'md5'   => md5_file($real),
            'size'  => $file->getSize(),
            'mtime' => $file->getMTime(),
        ];
    }

    ksort($result);
    return $result;
}

// ── Helper: lista snapshotów ────────────────────────────────────────────────
function list_snapshots(string $dir): array {
    $files = glob($dir . '/*.json');
    if (!$files) return [];
    rsort($files); // najnowsze pierwsze
    return $files;
}

// ── Helper: formatuj rozmiar ────────────────────────────────────────────────
function fmt_size(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1)    . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

// ── Helper: sprawdź czy ścieżka jest bezpieczna do usunięcia ────────────────
function is_safe_to_delete(string $rel, string $app_root): bool {
    // Musi być wewnątrz app_root
    $abs = realpath($app_root . '/' . $rel);
    if ($abs === false) return false;
    if (!str_starts_with($abs, $app_root . DIRECTORY_SEPARATOR)) return false;

    // Zakazane ścieżki
    $forbidden = [
        'tenants/',
        'saas/auth.php',
        'config.php',
    ];
    foreach ($forbidden as $f) {
        if (str_starts_with($rel, $f) || $rel === rtrim($f, '/')) return false;
    }
    return true;
}

// ── Obsługa akcji POST ──────────────────────────────────────────────────────
$flash        = null;
$diff_result  = null;
$compare_snap = null;
$action       = $_POST['_action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_csrf_check();

    // ── Utwórz snapshot ────────────────────────────────────────────────────
    if ($action === 'create_snapshot') {
        $files     = scan_app_files($APP_ROOT);
        $timestamp = date('Y-m-d_H-i-s');
        $data      = [
            'created_at' => date('Y-m-d H:i:s'),
            'files'      => $files,
        ];
        $path = $SNAPSHOTS_DIR . '/' . $timestamp . '.json';
        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            $flash = ['ok', 'Snapshot <strong>' . htmlspecialchars($timestamp) . '</strong> zapisany. Pliki: ' . count($files) . '.'];
        } else {
            $flash = ['err', 'Błąd zapisu snapshotu. Sprawdź uprawnienia katalogu saas-tenent/x/snapshots/.'];
        }
    }

    // ── Usuń snapshot ──────────────────────────────────────────────────────
    if ($action === 'delete_snapshot') {
        $snap = basename($_POST['snapshot'] ?? '');
        $path = $SNAPSHOTS_DIR . '/' . $snap;
        if ($snap && is_file($path) && str_ends_with($snap, '.json')) {
            unlink($path);
            $flash = ['ok', 'Snapshot <strong>' . htmlspecialchars($snap) . '</strong> usunięty.'];
        } else {
            $flash = ['err', 'Nie znaleziono snapshotu.'];
        }
    }

    // ── Usuń plik ──────────────────────────────────────────────────────────
    if ($action === 'delete_file') {
        $rel = trim($_POST['file_path'] ?? '');
        if (!$rel) {
            $flash = ['err', 'Nie podano ścieżki pliku.'];
        } elseif (!is_safe_to_delete($rel, $APP_ROOT)) {
            $flash = ['err', 'Usunięcie pliku <strong>' . htmlspecialchars($rel) . '</strong> jest zablokowane (chroniona ścieżka).'];
        } else {
            $abs = $APP_ROOT . '/' . $rel;
            if (is_file($abs)) {
                unlink($abs);
                $flash = ['ok', 'Plik <strong>' . htmlspecialchars($rel) . '</strong> usunięty.'];
            } else {
                $flash = ['err', 'Plik nie istnieje: ' . htmlspecialchars($rel)];
            }
        }
    }
}

// ── Obsługa akcji GET: porównanie snapshotu ─────────────────────────────────
if (isset($_GET['compare'])) {
    $snap      = basename($_GET['compare']);
    $snap_path = $SNAPSHOTS_DIR . '/' . $snap;
    if (is_file($snap_path) && str_ends_with($snap, '.json')) {
        $snap_data    = json_decode(file_get_contents($snap_path), true);
        $compare_snap = $snap;
        $snap_files   = $snap_data['files']   ?? [];
        $snap_created = $snap_data['created_at'] ?? '?';
        $cur_files    = scan_app_files($APP_ROOT);

        $added    = [];
        $changed  = [];
        $removed  = [];
        $same_cnt = 0;

        // Pliki w bieżącym stanie
        foreach ($cur_files as $rel => $info) {
            if (!isset($snap_files[$rel])) {
                $added[$rel] = $info;
            } elseif ($info['md5'] !== $snap_files[$rel]['md5']) {
                $changed[$rel] = [
                    'current'  => $info,
                    'snapshot' => $snap_files[$rel],
                ];
            } else {
                $same_cnt++;
            }
        }

        // Pliki w snapshocie, których nie ma teraz
        foreach ($snap_files as $rel => $info) {
            if (!isset($cur_files[$rel])) {
                $removed[$rel] = $info;
            }
        }

        $diff_result = compact('added', 'changed', 'removed', 'same_cnt', 'snap_created', 'snap');
    } else {
        $flash = ['err', 'Nie znaleziono snapshotu: ' . htmlspecialchars($snap)];
    }
}

// ── Dane do widoku ──────────────────────────────────────────────────────────
$snapshots   = list_snapshots($SNAPSHOTS_DIR);
$show_files  = !$diff_result; // Pokaż listę plików tylko gdy nie ma diff
$cur_files   = ($show_files && !$diff_result) ? scan_app_files($APP_ROOT) : ($diff_result ? null : []);
if ($show_files) {
    $cur_files = scan_app_files($APP_ROOT);
}
$csrf        = saas_csrf();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inspektor plików — SaaS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#f0f4f8; }
.saas-header { background:#1e293b; color:#fff; padding:1rem 1.5rem;
               display:flex; align-items:center; justify-content:space-between; }
.saas-header h1 { font-size:1.1rem; font-weight:700; margin:0; }
.saas-header a  { color:#94a3b8; font-size:.85rem; text-decoration:none; }
.saas-header a:hover { color:#fff; }
.file-row td   { font-size:.8rem; vertical-align:middle; }
.file-path     { font-family:monospace; word-break:break-all; }
.diff-added    { background:#d1fae5; }
.diff-changed  { background:#fef3c7; }
.diff-removed  { background:#fee2e2; }
.diff-badge-add { background:#16a34a; }
.diff-badge-chg { background:#d97706; }
.diff-badge-rem { background:#dc2626; }
.snap-name     { font-family:monospace; font-size:.82rem; }
.section-title { font-size:.95rem; font-weight:700; padding:.5rem 0 .25rem; border-bottom:2px solid; margin-bottom:.5rem; }
</style>
</head>
<body>

<div class="saas-header">
  <h1><i class="bi bi-files text-warning me-2"></i>Inspektor plików</h1>
  <div class="d-flex align-items-center gap-3">
    <a href="index.php"><i class="bi bi-arrow-left me-1"></i>Panel SaaS</a>
    <a href="?_logout=1" onclick="return false" style="display:none"></a>
  </div>
</div>

<div class="container-fluid py-4" style="max-width:1200px">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0]==='ok' ? 'success' : 'danger' ?> d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-<?= $flash[0]==='ok' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
  <div><?= $flash[1] ?></div>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- ── LEWA KOLUMNA: snapshoty ──────────────────────────────────────────── -->
  <div class="col-lg-4 col-xl-3">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold small"><i class="bi bi-camera me-1"></i>Snapshoty</span>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="create_snapshot">
          <button type="submit" class="btn btn-sm btn-primary py-0" style="font-size:.78rem"
                  onclick="return confirm('Zapisać snapshot bieżącego stanu plików?')">
            <i class="bi bi-plus-circle me-1"></i>Utwórz snapshot
          </button>
        </form>
      </div>
      <div class="card-body p-0">
        <?php
        $shown_snaps = array_slice($snapshots, 0, 10);
        if (!$shown_snaps):
        ?>
        <div class="text-center text-muted py-4 small">
          <i class="bi bi-inbox display-6 opacity-25 d-block mb-2"></i>
          Brak snapshotów.<br>Kliknij <strong>Utwórz snapshot</strong>.
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($shown_snaps as $snap_path):
            $snap_name = basename($snap_path);
            $snap_ts   = str_replace(['_', '-'], [' ', '-'], pathinfo($snap_name, PATHINFO_FILENAME));
            // Policz pliki w snapshocie
            $sdata     = json_decode(file_get_contents($snap_path), true);
            $scount    = count($sdata['files'] ?? []);
            $active    = ($compare_snap === $snap_name);
          ?>
          <li class="list-group-item p-2 <?= $active ? 'list-group-item-warning' : '' ?>">
            <div class="snap-name mb-1"><?= htmlspecialchars(pathinfo($snap_name, PATHINFO_FILENAME)) ?></div>
            <div class="d-flex gap-1 flex-wrap">
              <span class="badge bg-secondary"><?= $scount ?> plików</span>
              <a href="?compare=<?= urlencode($snap_name) ?>"
                 class="btn btn-xs btn-outline-<?= $active ? 'dark' : 'primary' ?> py-0 px-1"
                 style="font-size:.72rem">
                <i class="bi bi-arrow-left-right me-1"></i>Porównaj z bieżącym
              </a>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"     value="<?= $csrf ?>">
                <input type="hidden" name="_action"   value="delete_snapshot">
                <input type="hidden" name="snapshot"  value="<?= htmlspecialchars($snap_name) ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1"
                        style="font-size:.72rem"
                        onclick="return confirm('Usunąć snapshot <?= htmlspecialchars($snap_name) ?>?')">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($snapshots) > 10): ?>
        <div class="text-muted small text-center py-2">
          Wyświetlono 10 z <?= count($snapshots) ?> snapshotów. Stare snapshoty usuń ręcznie.
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── PRAWA KOLUMNA: diff lub lista plików ─────────────────────────────── -->
  <div class="col-lg-8 col-xl-9">

  <?php if ($diff_result): ?>
  <!-- ════════════════ WIDOK DIFF ════════════════ -->
  <?php
    $added   = $diff_result['added'];
    $changed = $diff_result['changed'];
    $removed = $diff_result['removed'];
    $same    = $diff_result['same_cnt'];
    $sc      = $diff_result['snap_created'];
    $sn      = $diff_result['snap'];
  ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
      <i class="bi bi-arrow-left-right text-primary"></i>
      <span class="fw-semibold">Porównanie z: <code><?= htmlspecialchars($sn) ?></code></span>
      <span class="text-muted small ms-auto">Snapshot z: <?= htmlspecialchars($sc) ?></span>
      <a href="files.php" class="btn btn-sm btn-outline-secondary ms-2 py-0">
        <i class="bi bi-x-lg"></i> Zamknij
      </a>
    </div>
    <div class="card-body py-2">
      <div class="d-flex gap-3 flex-wrap">
        <span class="badge diff-badge-add fs-6"><?= count($added) ?> DODANE</span>
        <span class="badge diff-badge-chg fs-6"><?= count($changed) ?> ZMIENIONE</span>
        <span class="badge diff-badge-rem fs-6"><?= count($removed) ?> USUNIĘTE</span>
        <span class="badge bg-secondary fs-6"><?= $same ?> NIEZMIENIONE</span>
      </div>
    </div>
  </div>

  <?php if ($added): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 text-success fw-semibold small">
      <i class="bi bi-plus-circle-fill me-1"></i>DODANE (<?= count($added) ?>)
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr>
        <th>Plik</th><th>Rozmiar</th><th>MD5</th><th>Akcje</th>
      </tr></thead>
      <tbody>
      <?php foreach ($added as $rel => $info): ?>
      <tr class="diff-added">
        <td class="file-path"><?= htmlspecialchars($rel) ?></td>
        <td><?= fmt_size($info['size']) ?></td>
        <td class="font-monospace" style="font-size:.72rem"><?= htmlspecialchars($info['md5']) ?></td>
        <td>
          <?php if (is_safe_to_delete($rel, $APP_ROOT)): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf"      value="<?= $csrf ?>">
            <input type="hidden" name="_action"    value="delete_file">
            <input type="hidden" name="file_path"  value="<?= htmlspecialchars($rel) ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.72rem"
                    onclick="return confirm('Usunąć plik <?= htmlspecialchars($rel) ?>?')">
              <i class="bi bi-trash3"></i> Usuń
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
  <?php endif; ?>

  <?php if ($changed): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 text-warning-emphasis fw-semibold small">
      <i class="bi bi-pencil-square me-1"></i>ZMIENIONE (<?= count($changed) ?>)
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr>
        <th>Plik</th><th>Rozmiar (snap → teraz)</th><th>Mtime (snap → teraz)</th><th>Akcje</th>
      </tr></thead>
      <tbody>
      <?php foreach ($changed as $rel => $info):
        $old = $info['snapshot'];
        $new = $info['current'];
      ?>
      <tr class="diff-changed">
        <td class="file-path"><?= htmlspecialchars($rel) ?></td>
        <td>
          <?= fmt_size($old['size']) ?> &rarr; <?= fmt_size($new['size']) ?>
          <?php if ($new['size'] > $old['size']): ?>
            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle ms-1">+<?= fmt_size($new['size'] - $old['size']) ?></span>
          <?php elseif ($new['size'] < $old['size']): ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-1">-<?= fmt_size($old['size'] - $new['size']) ?></span>
          <?php endif; ?>
        </td>
        <td class="small text-muted">
          <?= date('d.m.Y H:i:s', $old['mtime']) ?><br>
          &darr; <?= date('d.m.Y H:i:s', $new['mtime']) ?>
        </td>
        <td>
          <?php if (is_safe_to_delete($rel, $APP_ROOT)): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf"      value="<?= $csrf ?>">
            <input type="hidden" name="_action"    value="delete_file">
            <input type="hidden" name="file_path"  value="<?= htmlspecialchars($rel) ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.72rem"
                    onclick="return confirm('Usunąć plik <?= htmlspecialchars($rel) ?>?')">
              <i class="bi bi-trash3"></i> Usuń
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
  <?php endif; ?>

  <?php if ($removed): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 text-danger fw-semibold small">
      <i class="bi bi-dash-circle-fill me-1"></i>USUNIĘTE od snapshotu (<?= count($removed) ?>)
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr>
        <th>Plik</th><th>Rozmiar (w snapshocie)</th><th>MD5 (w snapshocie)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($removed as $rel => $info): ?>
      <tr class="diff-removed">
        <td class="file-path"><?= htmlspecialchars($rel) ?></td>
        <td><?= fmt_size($info['size']) ?></td>
        <td class="font-monospace" style="font-size:.72rem"><?= htmlspecialchars($info['md5']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$added && !$changed && !$removed): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i>
    Brak zmian — bieżący stan aplikacji jest identyczny ze snapshotem.
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- ════════════════ LISTA PLIKÓW ════════════════ -->
  <div class="card shadow-sm">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
      <span class="fw-semibold small">
        <i class="bi bi-folder2-open me-1"></i>
        Pliki aplikacji
        <span class="badge bg-secondary ms-1"><?= count($cur_files) ?></span>
      </span>
      <span class="text-muted" style="font-size:.78rem">
        <code><?= htmlspecialchars($APP_ROOT) ?></code>
      </span>
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-dark">
        <tr class="file-row">
          <th>Ścieżka</th>
          <th>Rozmiar</th>
          <th>MD5</th>
          <th>Modyfikacja</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cur_files as $rel => $info): ?>
      <tr class="file-row">
        <td class="file-path"><?= htmlspecialchars($rel) ?></td>
        <td><?= fmt_size($info['size']) ?></td>
        <td class="font-monospace" style="font-size:.7rem; color:#6b7280"><?= htmlspecialchars($info['md5']) ?></td>
        <td class="text-muted"><?= date('d.m.Y H:i:s', $info['mtime']) ?></td>
        <td class="text-end">
          <?php if (is_safe_to_delete($rel, $APP_ROOT)): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf"      value="<?= $csrf ?>">
            <input type="hidden" name="_action"    value="delete_file">
            <input type="hidden" name="file_path"  value="<?= htmlspecialchars($rel) ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.72rem"
                    onclick="return confirm('Na pewno usunąć plik:\n<?= addslashes($rel) ?>?')">
              <i class="bi bi-trash3"></i>
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
  <?php endif; // diff_result ?>

  </div><!-- /col-right -->
</div><!-- /row -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
