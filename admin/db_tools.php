<?php
/**
 * admin/db_tools.php — Narzędzia bazy danych.
 *
 * Funkcje:
 *  • Eksport SQL (dump pełny lub wybranych tabel)
 *  • Import SQL (wgraj plik .sql lub .sql.gz)
 *  • Czyszczenie danych (deleguje do db_clean.php)
 *  • Podgląd struktury bazy (tabele + liczba wierszy)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db_clean.php';

require_role('admin');
$PAGE_TITLE = 'Narzędzia bazy danych';

$pdo     = db();
$db_type = DB_TYPE;
$error   = '';
$success = '';

// ── Pomocnicze: lista tabel ───────────────────────────────────────────────────
function db_tables(): array {
    $pdo = db();
    if (DB_TYPE === 'sqlite') {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                    ->fetchAll();
        return array_column($rows, 'name');
    } else {
        $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
        return array_map(fn($r) => $r[0], $rows);
    }
}

// ── Pomocnicze: liczba wierszy tabeli ─────────────────────────────────────────
function db_table_count(string $table): int {
    try {
        $r = db()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        return (int)$r;
    } catch (\Throwable $e) { return -1; }
}

// ── Pomocnicze: rozmiar pliku DB (SQLite) ─────────────────────────────────────
function db_file_size(): string {
    if (DB_TYPE !== 'sqlite' || !defined('DB_PATH')) return '—';
    $bytes = @filesize(DB_PATH);
    if ($bytes === false) return '?';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

// ── Eksport SQL ───────────────────────────────────────────────────────────────
function dump_sqlite(array $tables, bool $with_data = true): string {
    $pdo = db();
    $sql = "-- Eksport bazy SQLite\n";
    $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Wersja SQLite: " . $pdo->query("SELECT sqlite_version()")->fetchColumn() . "\n\n";
    $sql .= "PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n";

    foreach ($tables as $t) {
        // Schemat
        $create = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))
                      ->fetchColumn();
        if ($create) {
            $sql .= "DROP TABLE IF EXISTS `{$t}`;\n";
            $sql .= $create . ";\n\n";
        }
        // Dane
        if ($with_data) {
            $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $sql .= "INSERT INTO `{$t}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
            }
            if ($rows) $sql .= "\n";
        }
    }
    $sql .= "COMMIT;\nPRAGMA foreign_keys = ON;\n";
    return $sql;
}

function dump_mysql(array $tables, bool $with_data = true): string {
    $pdo = db();
    $sql = "-- Eksport bazy MySQL\n";
    $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
        if ($create) {
            $sql .= "DROP TABLE IF EXISTS `{$t}`;\n";
            $sql .= $create[1] . ";\n\n";
        }
        if ($with_data) {
            $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $sql .= "INSERT INTO `{$t}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
            }
            if ($rows) $sql .= "\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

// ── Import SQL ────────────────────────────────────────────────────────────────
function import_sql(string $sql_content): array {
    $pdo = db();
    $pdo->exec("PRAGMA foreign_keys = OFF");
    $executed = 0;
    $errors   = [];

    // Podziel po średnikach (proste — nie obsługuje procedur składowanych)
    $statements = preg_split('/;\s*\n/m', $sql_content);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!$stmt || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) continue;
        // Pomiń META komentarze i PRAGMA
        if (preg_match('/^(PRAGMA foreign_keys|BEGIN TRANSACTION|COMMIT|SET FOREIGN_KEY)/i', $stmt)) continue;
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (\Throwable $e) {
            $errors[] = mb_substr($e->getMessage(), 0, 120) . ' — SQL: ' . mb_substr($stmt, 0, 80);
        }
    }
    if (DB_TYPE === 'sqlite') $pdo->exec("PRAGMA foreign_keys = ON");
    return ['executed' => $executed, 'errors' => $errors];
}

// ── POST: Eksport ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'export') {
    csrf_check();
    $sel_tables  = $_POST['tables'] ?? [];
    $with_data   = !empty($_POST['with_data']);
    $compress    = !empty($_POST['compress']);
    $all_tables  = db_tables();
    $export_tabs = $sel_tables ? array_intersect($sel_tables, $all_tables) : $all_tables;

    if (!$export_tabs) {
        $error = 'Wybierz co najmniej jedną tabelę do eksportu.';
    } else {
        try {
            $sql  = ($db_type === 'sqlite') ? dump_sqlite($export_tabs, $with_data) : dump_mysql($export_tabs, $with_data);
            $fname = 'backup_' . preg_replace('/[^a-z0-9_]/i','_',ORG_NAME) . '_' . date('Ymd_His') . '.sql';

            if ($compress && function_exists('gzencode')) {
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="' . $fname . '.gz"');
                header('Cache-Control: no-cache');
                echo gzencode($sql, 6);
            } else {
                header('Content-Type: text/sql; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $fname . '"');
                header('Cache-Control: no-cache');
                echo $sql;
            }
            exit;
        } catch (\Throwable $e) {
            $error = 'Błąd eksportu: ' . $e->getMessage();
        }
    }
}

// ── POST: Import ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'import') {
    csrf_check();
    $confirm = trim($_POST['confirm_import'] ?? '');
    if ($confirm !== 'IMPORTUJĘ') {
        $error = 'Nieprawidłowe potwierdzenie. Wpisz dokładnie: IMPORTUJĘ';
    } elseif (empty($_FILES['sql_file']['tmp_name']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Nie wgrałeś pliku SQL.';
    } else {
        $file     = $_FILES['sql_file'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $max_size = 50 * 1024 * 1024; // 50 MB
        if ($file['size'] > $max_size) {
            $error = 'Plik jest za duży (max 50 MB).';
        } elseif (!in_array($ext, ['sql', 'gz'])) {
            $error = 'Dozwolone formaty: .sql, .gz';
        } else {
            try {
                $content = file_get_contents($file['tmp_name']);
                if ($ext === 'gz') {
                    $content = gzdecode($content);
                    if ($content === false) throw new \RuntimeException('Błąd dekompresji pliku .gz');
                }
                $result  = import_sql($content);
                $ok      = $result['executed'];
                $errs    = $result['errors'];
                if ($errs) {
                    $success = "Import częściowy: wykonano {$ok} instrukcji, " . count($errs) . " błędów.";
                    $error   = implode('<br>', array_map('h', array_slice($errs, 0, 10)));
                } else {
                    flash_set('success', "Import zakończony — wykonano {$ok} instrukcji SQL.");
                    header('Location: db_tools.php'); exit;
                }
            } catch (\Throwable $e) {
                $error = 'Błąd importu: ' . $e->getMessage();
            }
        }
    }
}

// ── Dane do widoku ────────────────────────────────────────────────────────────
$all_tables  = db_tables();
$table_stats = [];
foreach ($all_tables as $t) {
    $table_stats[$t] = db_table_count($t);
}
arsort($table_stats); // sortuj malejąco po liczbie wierszy
$total_rows = array_sum(array_filter($table_stats, fn($v) => $v >= 0));
$db_size    = db_file_size();

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.dbt-card { background:#fff;border:1px solid #E5E7EB;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:1.25rem }
.dbt-card-header { display:flex;align-items:center;gap:.5rem;padding:.8rem 1.1rem;border-bottom:1px solid #F3F4F6;font-weight:700;font-size:.9rem }
.dbt-card-body { padding:1.1rem }
.dbt-section-icon { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0 }
.table-pill { display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:5px;font-size:.75rem;font-weight:500;cursor:pointer;border:1.5px solid #E5E7EB;background:#fff;transition:all .12s;margin:.15rem }
.table-pill:hover { border-color:#6B7280 }
.table-pill input { position:absolute;opacity:0;width:0;height:0 }
.table-pill.checked { border-color:var(--c,#2563EB);background:#EFF6FF;color:#1D4ED8 }
.row-count { font-size:.7rem;color:#9CA3AF;margin-left:.25rem }
.dbt-stat { background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:.65rem 1rem;text-align:center }
.dbt-stat-val { font-size:1.4rem;font-weight:800;color:#111827;line-height:1 }
.dbt-stat-lbl { font-size:.72rem;color:#9CA3AF;margin-top:.15rem }
.import-drop { border:2.5px dashed #D1D5DB;border-radius:10px;padding:2rem;text-align:center;transition:border-color .15s,background .15s;cursor:pointer }
.import-drop:hover,.import-drop.dragover { border-color:var(--c,#2563EB);background:#F0F7FF }
.import-drop input[type=file] { display:none }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,#1E3A5F,#2563EB);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="bi bi-database-fill text-white" style="font-size:1.2rem"></i>
  </div>
  <div>
    <h1 style="font-size:1.3rem;font-weight:800;margin:0 0 .1rem;color:#111827">Narzędzia bazy danych</h1>
    <div style="font-size:.82rem;color:#6B7280">
      Silnik: <strong><?= strtoupper(DB_TYPE) ?></strong>
      <?php if (DB_TYPE === 'sqlite'): ?>
      · Plik: <code><?= h(basename(DB_PATH)) ?></code>
      · Rozmiar: <strong><?= $db_size ?></strong>
      <?php else: ?>
      · Baza: <strong><?= defined('DB_NAME') ? h(DB_NAME) : '—' ?></strong>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger d-flex gap-2 mb-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <div><?= $error ?></div>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success d-flex gap-2 mb-3" role="alert">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i>
  <span><?= h($success) ?></span>
</div>
<?php endif; ?>
<?= flash_html() ?>

<!-- ══ STATYSTYKI ════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="dbt-stat">
      <div class="dbt-stat-val"><?= count($all_tables) ?></div>
      <div class="dbt-stat-lbl">Tabel</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="dbt-stat">
      <div class="dbt-stat-val"><?= number_format($total_rows) ?></div>
      <div class="dbt-stat-lbl">Wierszy łącznie</div>
    </div>
  </div>
  <?php if (DB_TYPE === 'sqlite'): ?>
  <div class="col-6 col-md-3">
    <div class="dbt-stat">
      <div class="dbt-stat-val"><?= $db_size ?></div>
      <div class="dbt-stat-lbl">Rozmiar pliku</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-6 col-md-3">
    <div class="dbt-stat">
      <div class="dbt-stat-val" style="font-size:1rem"><?= date('d.m.Y H:i') ?></div>
      <div class="dbt-stat-lbl">Teraz</div>
    </div>
  </div>
</div>

<div class="row g-3">
<div class="col-lg-7">

<!-- ══ EKSPORT SQL ══════════════════════════════════════════════════════════ -->
<div class="dbt-card">
  <div class="dbt-card-header">
    <div class="dbt-section-icon" style="background:#EFF6FF;color:#2563EB">
      <i class="bi bi-cloud-download-fill"></i>
    </div>
    Eksport SQL
  </div>
  <div class="dbt-card-body">
    <form method="post" id="exportForm">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="export">

      <!-- Wybór tabel -->
      <div class="mb-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <label class="fw-semibold small">Tabele do eksportu</label>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.72rem"
                    onclick="toggleAllTables(true)">Wszystkie</button>
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.72rem"
                    onclick="toggleAllTables(false)">Żadna</button>
          </div>
        </div>
        <div id="tablePills" style="max-height:260px;overflow-y:auto;padding:.25rem">
          <?php foreach ($table_stats as $t => $cnt): ?>
          <label class="table-pill" id="pill_<?= h($t) ?>">
            <input type="checkbox" name="tables[]" value="<?= h($t) ?>" checked
                   onchange="this.closest('.table-pill').classList.toggle('checked', this.checked)">
            <i class="bi bi-table" style="font-size:.75rem;color:#6B7280"></i>
            <?= h($t) ?>
            <span class="row-count"><?= $cnt >= 0 ? number_format($cnt) : '?' ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <script>
        document.querySelectorAll('.table-pill').forEach(p => p.classList.add('checked'));
        function toggleAllTables(v) {
          document.querySelectorAll('#tablePills input[type=checkbox]').forEach(cb => {
            cb.checked = v;
            cb.closest('.table-pill').classList.toggle('checked', v);
          });
        }
        </script>
      </div>

      <!-- Opcje -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="with_data" id="with_data" value="1" checked>
            <label class="form-check-label fw-semibold small" for="with_data">
              Eksportuj dane (INSERT)
            </label>
          </div>
          <div class="form-text">Odznacz aby eksportować tylko schemat (CREATE TABLE)</div>
        </div>
        <?php if (function_exists('gzencode')): ?>
        <div class="col-sm-6">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="compress" id="compress" value="1">
            <label class="form-check-label fw-semibold small" for="compress">
              Kompresja GZIP (.sql.gz)
            </label>
          </div>
          <div class="form-text">Zalecane dla dużych baz</div>
        </div>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="bi bi-download me-1"></i>Pobierz plik SQL
      </button>
    </form>
  </div>
</div>

<!-- ══ IMPORT SQL ════════════════════════════════════════════════════════════ -->
<div class="dbt-card">
  <div class="dbt-card-header">
    <div class="dbt-section-icon" style="background:#FEF3E2;color:#D97706">
      <i class="bi bi-cloud-upload-fill"></i>
    </div>
    Import SQL
    <span class="badge bg-warning text-dark ms-auto" style="font-size:.7rem">Niebezpieczne</span>
  </div>
  <div class="dbt-card-body">

    <div class="alert alert-warning d-flex gap-2 py-2 mb-3" style="font-size:.82rem">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <div>
        Import <strong>nadpisuje dane</strong> — instrukcje DROP TABLE i INSERT zostaną wykonane.
        Przed importem wykonaj <strong>eksport jako kopię zapasową</strong>.
        Dozwolone formaty: <code>.sql</code>, <code>.sql.gz</code> (max 50 MB).
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" id="importForm">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="import">

      <!-- Drop zone -->
      <div class="import-drop mb-3" id="importDrop"
           onclick="document.getElementById('sql_file').click()"
           ondragover="event.preventDefault();this.classList.add('dragover')"
           ondragleave="this.classList.remove('dragover')"
           ondrop="handleDrop(event)">
        <input type="file" name="sql_file" id="sql_file" accept=".sql,.gz"
               onchange="showFileName(this)">
        <i class="bi bi-file-earmark-code" style="font-size:2rem;color:#9CA3AF;display:block;margin-bottom:.5rem"></i>
        <div id="dropLabel" class="fw-semibold" style="color:#374151">Przeciągnij plik .sql lub kliknij by wybrać</div>
        <div class="text-muted small mt-1">Obsługiwane: .sql, .sql.gz (max 50 MB)</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold small">
          Potwierdzenie <span class="text-danger">*</span>
        </label>
        <input type="text" name="confirm_import" class="form-control form-control-sm font-monospace"
               placeholder="Wpisz: IMPORTUJĘ" autocomplete="off" required>
        <div class="form-text">Wpisz dokładnie <code>IMPORTUJĘ</code> aby potwierdzić operację.</div>
      </div>

      <button type="submit" class="btn btn-warning"
              onclick="return confirm('Czy na pewno chcesz importować SQL? Operacja może nadpisać dane!')">
        <i class="bi bi-upload me-1"></i>Importuj SQL
      </button>
    </form>
  </div>
</div>

</div><!-- /col-7 -->

<!-- ══ PRAWA: Przegląd tabel + Czyszczenie ══════════════════════════════════ -->
<div class="col-lg-5">

<!-- Przegląd tabel -->
<div class="dbt-card">
  <div class="dbt-card-header">
    <div class="dbt-section-icon" style="background:#F0FDF4;color:#16A34A">
      <i class="bi bi-table"></i>
    </div>
    Struktura bazy
    <span class="badge bg-secondary ms-auto"><?= count($all_tables) ?> tabel</span>
  </div>
  <div class="dbt-card-body p-0">
    <div style="max-height:340px;overflow-y:auto">
      <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
        <thead class="table-light sticky-top">
          <tr>
            <th class="ps-3">Tabela</th>
            <th class="text-end pe-3">Wiersze</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($table_stats as $t => $cnt): ?>
          <tr>
            <td class="ps-3 font-monospace" style="font-size:.78rem"><?= h($t) ?></td>
            <td class="text-end pe-3 text-muted"><?= $cnt >= 0 ? number_format($cnt) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="ps-3 fw-bold">Łącznie</td>
            <td class="text-end pe-3 fw-bold"><?= number_format($total_rows) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- Czyszczenie bazy -->
<div class="dbt-card">
  <div class="dbt-card-header">
    <div class="dbt-section-icon" style="background:#FEF2F2;color:#DC2626">
      <i class="bi bi-trash3-fill"></i>
    </div>
    Czyszczenie danych
    <span class="badge bg-danger ms-auto" style="font-size:.7rem">Destrukcyjne</span>
  </div>
  <div class="dbt-card-body">
    <p class="text-muted small mb-3">
      Usuwa umowy, dokumenty i dane osobowe z bazy.
      Przed czyszczeniem wykonaj <strong>eksport SQL</strong>.
    </p>
    <?php
    $counts = db_clean_counts(db());
    $data_total = 0;
    foreach ($counts as $t => $n) {
      if ($t !== 'users' && $n !== null) $data_total += $n;
    }
    ?>
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <?php foreach ($counts as $label => $count): if ($count === null) continue; ?>
      <div class="dbt-stat" style="flex:1;min-width:80px;padding:.5rem .75rem">
        <div class="dbt-stat-val" style="font-size:1.1rem"><?= number_format($count) ?></div>
        <div class="dbt-stat-lbl" style="font-size:.65rem"><?= h($label) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="d-grid gap-2">
      <a href="<?= APP_URL ?>/admin/clean_db.php"
         class="btn btn-outline-danger btn-sm">
        <i class="bi bi-trash3 me-1"></i>Przejdź do czyszczenia bazy
      </a>
    </div>
  </div>
</div>

<!-- SQLite: VACUUM -->
<?php if (DB_TYPE === 'sqlite'): ?>
<div class="dbt-card">
  <div class="dbt-card-header">
    <div class="dbt-section-icon" style="background:#F5F3FF;color:#7C3AED">
      <i class="bi bi-lightning-charge-fill"></i>
    </div>
    Optymalizacja SQLite
  </div>
  <div class="dbt-card-body">
    <p class="text-muted small mb-3">
      <code>VACUUM</code> defragmentuje plik bazy i odzyskuje miejsce po usuniętych rekordach.
      Operacja jest bezpieczna, ale może chwilę potrwać na dużych bazach.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="vacuum">
      <button type="submit" class="btn btn-outline-secondary btn-sm"
              onclick="return confirm('Wykonać VACUUM na bazie SQLite?')">
        <i class="bi bi-magic me-1"></i>Wykonaj VACUUM
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

</div><!-- /col-5 -->
</div><!-- /row -->

<script>
// Drag & drop dla importu
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('importDrop').classList.remove('dragover');
  var f = e.dataTransfer.files[0];
  if (!f) return;
  var inp = document.getElementById('sql_file');
  // Przypisz plik przez DataTransfer
  var dt = new DataTransfer();
  dt.items.add(f);
  inp.files = dt.files;
  showFileName(inp);
}

function showFileName(inp) {
  var lbl = document.getElementById('dropLabel');
  if (inp.files.length && lbl) {
    var f = inp.files[0];
    var kb = (f.size / 1024).toFixed(1);
    lbl.textContent = '✓ ' + f.name + ' (' + kb + ' KB)';
    lbl.style.color = '#16A34A';
  }
}

// Szybki eksport SQLite native (tylko link do pobrania pliku .db)
<?php if (DB_TYPE === 'sqlite'): ?>
// Przycisk "Pobierz plik .db" — opcjonalnie można dodać do UI
<?php endif; ?>
</script>

<?php
// ── POST: VACUUM ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'vacuum' && DB_TYPE === 'sqlite') {
    csrf_check();
    try {
        $pdo->exec("VACUUM");
        flash_set('success', 'VACUUM wykonany. Baza zoptymalizowana. Nowy rozmiar: ' . db_file_size());
    } catch (\Throwable $e) {
        flash_set('danger', 'Błąd VACUUM: ' . $e->getMessage());
    }
    header('Location: db_tools.php'); exit;
}
?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
