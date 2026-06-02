<?php
/**
 * admin/migrations.php — Panel zarządzania migracjami
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Zarządzanie migracjami';

// Utwórz tabelę historii migracji
try {
    db()->exec("CREATE TABLE IF NOT EXISTS _migration_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        run_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status TEXT DEFAULT 'ok',
        output TEXT
    )");
} catch (\Throwable $e) {}

$migrations_dir = dirname(__DIR__) . '/cli/migrations';
$archive_dir    = $migrations_dir; // już tam są

$flash = '';
$flash_type = 'success';

// ── Obsługa akcji POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'run') {
        $file = basename($_POST['file'] ?? '');
        if (preg_match('/^migrate_[\w]+\.php$/', $file)) {
            $path = $migrations_dir . '/' . $file;
            if (file_exists($path)) {
                ob_start();
                try {
                    require $path;
                    $out = ob_get_clean();
                    db()->prepare("INSERT INTO _migration_log (filename, status, output) VALUES (?, 'ok', ?)
                        ON CONFLICT(filename) DO UPDATE SET run_at=CURRENT_TIMESTAMP, status='ok', output=excluded.output")
                        ->execute([$file, $out]);
                    $flash = "Migracja <strong>" . h($file) . "</strong> wykonana pomyślnie.";
                } catch (\Throwable $e) {
                    $out = ob_get_clean();
                    $errMsg = $e->getMessage();
                    db()->prepare("INSERT INTO _migration_log (filename, status, output) VALUES (?, 'error', ?)
                        ON CONFLICT(filename) DO UPDATE SET run_at=CURRENT_TIMESTAMP, status='error', output=excluded.output")
                        ->execute([$file, $out . "\nBŁĄD: " . $errMsg]);
                    $flash = "Błąd migracji <strong>" . h($file) . "</strong>: " . h($errMsg);
                    $flash_type = 'danger';
                }
            }
        }
    }

    elseif ($action === 'run_all') {
        $files = glob($migrations_dir . '/migrate_*.php') ?: [];
        $done_stmt = db()->query("SELECT filename FROM _migration_log WHERE status='ok'");
        $done = $done_stmt->fetchAll(\PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($files as $path) {
            $file = basename($path);
            if (in_array($file, $done)) continue;
            ob_start();
            try {
                require $path;
                $out = ob_get_clean();
                db()->prepare("INSERT INTO _migration_log (filename, status, output) VALUES (?, 'ok', ?)
                    ON CONFLICT(filename) DO UPDATE SET run_at=CURRENT_TIMESTAMP, status='ok', output=excluded.output")
                    ->execute([$file, $out]);
                $count++;
            } catch (\Throwable $e) {
                $out = ob_get_clean();
                db()->prepare("INSERT INTO _migration_log (filename, status, output) VALUES (?, 'error', ?)
                    ON CONFLICT(filename) DO UPDATE SET run_at=CURRENT_TIMESTAMP, status='error', output=excluded.output")
                    ->execute([$file, $out . "\nBŁĄD: " . $e->getMessage()]);
            }
        }
        $flash = "Uruchomiono $count niewykonanych migracji.";
    }

    elseif ($action === 'archive') {
        $file = basename($_POST['file'] ?? '');
        if (preg_match('/^migrate_[\w]+\.php$/', $file)) {
            $src = dirname(__DIR__) . '/' . $file;
            $dst = $migrations_dir . '/' . $file;
            if (file_exists($src)) {
                rename($src, $dst);
                $flash = "Plik <strong>" . h($file) . "</strong> przeniesiony do archiwum.";
            } else {
                $flash = "Plik już w archiwum lub nie istnieje.";
                $flash_type = 'warning';
            }
        }
    }

    elseif ($action === 'archive_all') {
        $done_stmt = db()->query("SELECT filename FROM _migration_log WHERE status='ok'");
        $done = $done_stmt->fetchAll(\PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($done as $file) {
            $src = dirname(__DIR__) . '/' . $file;
            $dst = $migrations_dir . '/' . $file;
            if (file_exists($src)) {
                rename($src, $dst);
                $count++;
            }
        }
        $flash = "Zarchiwizowano $count plików migracji.";
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Sprawdź czy obiekt DB istnieje (tabela lub kolumna) ──────────────────────
function _mig_table_exists(string $table): bool {
    try {
        if (DB_TYPE === 'sqlite') {
            $r = db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
            $r->execute([$table]);
            return (int)$r->fetchColumn() > 0;
        } else {
            $r = db()->prepare("SHOW TABLES LIKE ?");
            $r->execute([$table]);
            return $r->rowCount() > 0;
        }
    } catch (\Throwable $e) { return false; }
}

function _mig_column_exists(string $table, string $column): bool {
    try {
        if (!_mig_table_exists($table)) return false;
        if (DB_TYPE === 'sqlite') {
            $r = db()->query("PRAGMA table_info(" . db()->quote($table) . ")");
            foreach ($r->fetchAll() as $col) {
                if (strtolower($col['name']) === strtolower($column)) return true;
            }
            return false;
        } else {
            $r = db()->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $r->execute([$column]);
            return $r->rowCount() > 0;
        }
    } catch (\Throwable $e) { return false; }
}

/**
 * Analizuje treść pliku migracji i sprawdza czy wszystkie tabele/kolumny
 * wymienione w CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD COLUMN
 * już istnieją w bazie. Jeśli tak — migracja jest "zrealizowana" bez logowania.
 *
 * @return array ['applied'=>bool, 'reason'=>string, 'details'=>array]
 */
function _mig_detect_applied(string $filepath): array {
    $content = @file_get_contents($filepath);
    if (!$content) return ['applied' => false, 'reason' => '', 'details' => []];

    $details  = [];
    $all_ok   = true;
    $any_found = false;

    // 1. CREATE TABLE IF NOT EXISTS `table_name` lub table_name
    preg_match_all(
        '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[`"]?(\w+)[`"]?/i',
        $content, $m
    );
    foreach (array_unique($m[1] ?? []) as $table) {
        if (!$table || $table === 'sqlite_sequence') continue;
        $any_found = true;
        $exists = _mig_table_exists($table);
        $details[] = ['type' => 'table', 'name' => $table, 'exists' => $exists];
        if (!$exists) $all_ok = false;
    }

    // 2. ALTER TABLE `table` ADD COLUMN `column`
    preg_match_all(
        '/ALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+ADD\s+COLUMN\s+[`"]?(\w+)[`"]?/i',
        $content, $m2
    );
    for ($i = 0; $i < count($m2[1] ?? []); $i++) {
        $table  = $m2[1][$i] ?? '';
        $column = $m2[2][$i] ?? '';
        if (!$table || !$column) continue;
        $any_found = true;
        $exists = _mig_column_exists($table, $column);
        $details[] = ['type' => 'column', 'name' => "{$table}.{$column}", 'exists' => $exists];
        if (!$exists) $all_ok = false;
    }

    if (!$any_found) {
        return ['applied' => false, 'reason' => 'brak detekcji', 'details' => []];
    }

    $reason = $all_ok
        ? 'Wykryto w bazie: ' . implode(', ', array_column($details, 'name'))
        : 'Brakuje: ' . implode(', ', array_map(
            fn($d) => $d['name'],
            array_filter($details, fn($d) => !$d['exists'])
          ));

    return ['applied' => $all_ok, 'reason' => $reason, 'details' => $details];
}

// ── Pobierz dane ──────────────────────────────────────────────────────────────
$files_in_dir = glob($migrations_dir . '/migrate_*.php') ?: [];
$files_root   = glob(dirname(__DIR__) . '/migrate_*.php') ?: [];
$all_files    = array_unique(array_merge(
    array_map('basename', $files_root),
    array_map('basename', $files_in_dir)
));
sort($all_files);

$logs = [];
try {
    $stmt = db()->query("SELECT * FROM _migration_log ORDER BY filename");
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $logs[$row['filename']] = $row;
    }
} catch (\Throwable $e) {}

// Wykryj status każdej migracji przez analizę bazy
$db_detection = [];
foreach ($all_files as $file) {
    $path = file_exists(dirname(__DIR__) . '/' . $file)
        ? dirname(__DIR__) . '/' . $file
        : $migrations_dir . '/' . $file;
    $db_detection[$file] = _mig_detect_applied($path);
}

// Automatycznie zapisz do logu te wykryte jako zrealizowane (jeśli nie ma wpisu)
foreach ($db_detection as $file => $det) {
    if ($det['applied'] && !isset($logs[$file])) {
        try {
            db()->prepare(
                "INSERT OR IGNORE INTO _migration_log (filename, status, output, run_at)
                 VALUES (?, 'ok', ?, 'wykryto automatycznie')"
            )->execute([$file, 'Wykryto w bazie: ' . $det['reason']]);
            $logs[$file] = ['filename'=>$file,'status'=>'ok','run_at'=>'wykryto','output'=>$det['reason']];
        } catch (\Throwable $e) {}
    }
}

$total   = count($all_files);
$done_ct = count(array_filter($logs, fn($r) => $r['status'] === 'ok'));

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:1100px">

  <div class="d-flex align-items-center gap-3 mb-3">
    <h4 class="mb-0"><i class="bi bi-database-gear me-2"></i>Zarządzanie migracjami</h4>
    <span class="badge bg-success fs-6"><?= $done_ct ?>/<?= $total ?> wykonanych</span>
  </div>

  <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span>Te pliki powinny być uruchamiane <strong>tylko raz</strong>. Ponowne uruchomienie może spowodować błędy lub duplikaty danych.</span>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show">
    <?= $flash ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Akcje globalne -->
  <div class="d-flex gap-2 mb-3">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="run_all">
      <button class="btn btn-primary btn-sm" onclick="return confirm('Uruchomić wszystkie niewykonane migracje?')">
        <i class="bi bi-play-circle me-1"></i>Uruchom wszystkie niewykonane
      </button>
    </form>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="archive_all">
      <button class="btn btn-secondary btn-sm" onclick="return confirm('Zarchiwizować wszystkie wykonane migracje?')">
        <i class="bi bi-archive me-1"></i>Archiwizuj wszystkie wykonane
      </button>
    </form>
    <a href="/cli/migrations/" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-folder me-1"></i>Katalog archiwum
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Plik</th>
            <th>Lokalizacja</th>
            <th>Uruchomiono</th>
            <th>Status</th>
            <th class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($all_files as $file):
            $log     = $logs[$file] ?? null;
            $det     = $db_detection[$file] ?? ['applied'=>false,'reason'=>'','details'=>[]];
            $in_root = file_exists(dirname(__DIR__) . '/' . $file);
            $in_arch = file_exists($migrations_dir . '/' . $file);

            // Ustal status końcowy
            $is_ok        = $log && $log['status'] === 'ok';
            $is_error     = $log && $log['status'] !== 'ok';
            $db_applied   = $det['applied'];
            $has_details  = !empty($det['details']);
        ?>
        <tr class="<?= $is_error ? 'table-danger' : ($is_ok ? '' : ($db_applied ? 'table-success table-opacity-25' : '')) ?>">
          <td><code style="font-size:.8rem"><?= h($file) ?></code></td>
          <td>
            <?php if ($in_root): ?>
              <span class="badge bg-warning text-dark">katalog główny</span>
            <?php elseif ($in_arch): ?>
              <span class="badge bg-light text-secondary border">archiwum</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap text-muted small">
            <?= ($log && $log['run_at'] !== 'wykryto') ? h(substr($log['run_at'],0,16)) : ($db_applied ? '<span class="text-success">wykryto w DB</span>' : '—') ?>
          </td>
          <td>
            <?php if ($is_ok): ?>
              <span class="badge bg-success" title="<?= h($log['output'] ?? '') ?>">
                <i class="bi bi-check-circle me-1"></i>zrealizowana
              </span>
            <?php elseif ($is_error): ?>
              <span class="badge bg-danger">błąd</span>
            <?php elseif ($db_applied): ?>
              <span class="badge bg-success bg-opacity-75" title="<?= h($det['reason']) ?>">
                <i class="bi bi-database-check me-1"></i>w bazie ✓
              </span>
            <?php else: ?>
              <!-- Szczegółowe info z detekcji -->
              <?php if ($has_details): ?>
              <span class="badge bg-secondary" title="<?= h($det['reason']) ?>">
                niewykonana
              </span>
              <?php if (array_filter($det['details'], fn($d) => $d['exists'])): ?>
              <span class="badge bg-warning text-dark ms-1" title="Częściowo: <?= h($det['reason']) ?>">
                częściowo
              </span>
              <?php endif; ?>
              <?php else: ?>
              <span class="badge bg-secondary">niewykonana</span>
              <?php endif; ?>
            <?php endif; ?>

            <!-- Tooltip z detalami detekcji -->
            <?php if ($has_details): ?>
            <button type="button" class="btn btn-link btn-sm p-0 ms-1" style="font-size:.7rem;color:#9CA3AF"
                    data-bs-toggle="tooltip"
                    title="<?= h(implode(' | ', array_map(fn($d) => ($d['exists']?'✓ ':'✗ ').$d['type'].': '.$d['name'], $det['details']))) ?>">
              <i class="bi bi-info-circle"></i>
            </button>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <?php if ($in_root || $in_arch): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="run">
                <input type="hidden" name="file" value="<?= h($file) ?>">
                <button class="btn btn-outline-primary btn-sm py-0"
                        onclick="return confirm('Uruchomić migrację <?= h(addslashes($file)) ?>?')">
                  <i class="bi bi-play"></i> Uruchom
                </button>
              </form>
              <?php endif; ?>
              <?php if ($in_root): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="archive">
                <input type="hidden" name="file" value="<?= h($file) ?>">
                <button class="btn btn-outline-secondary btn-sm py-0"
                        onclick="return confirm('Przenieść do archiwum?')">
                  <i class="bi bi-archive"></i> Archiwizuj
                </button>
              </form>
              <?php endif; ?>
              <?php if ($log && $log['output']): ?>
              <button class="btn btn-outline-info btn-sm py-0"
                      data-bs-toggle="modal" data-bs-target="#out_<?= (int)$log['id'] ?>">
                <i class="bi bi-terminal"></i>
              </button>
              <div class="modal fade" id="out_<?= (int)$log['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h6 class="modal-title">Output: <?= h($file) ?></h6>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <pre class="small"><?= h($log['output']) ?></pre>
                    </div>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$all_files): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Brak plików migracji.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// Inicjuj tooltips Bootstrap
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { html: false });
});
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
