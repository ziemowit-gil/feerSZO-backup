<?php
/**
 * admin/postal_import.php — Import słownika kodów pocztowych.
 *
 * Obsługiwane formaty CSV (separator ; lub ,):
 *   kod;miejscowość
 *   kod;miejscowość;gmina;powiat;województwo
 *   (Poczta Polska: KOD_POCZTOWY;MIEJSCOWOSC;GMINA;POWIAT;WOJEWODZTWO)
 *
 * Źródło danych: https://www.poczta-polska.pl → spis kodów pocztowych
 * lub dowolny CSV z polami: kod, miasto.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/address.php';

require_role('admin');
$PAGE_TITLE = 'Słownik kodów pocztowych';

// ── Auto-migracja tabeli ─────────────────────────────────────────────────────
address_migrate(); // tworzy postal_codes jeśli nie istnieje

$errors  = [];
$success = null;
$stats   = null;

// ── Akcje POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // ── Wyczyść tabelę ───────────────────────────────────────────────────────
    if ($op === 'clear') {
        db()->exec("DELETE FROM postal_codes");
        flash_set('warning', 'Usunięto wszystkie kody pocztowe.');
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    // ── Import CSV ───────────────────────────────────────────────────────────
    if ($op === 'import' && isset($_FILES['csv_file'])) {
        $f = $_FILES['csv_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Błąd uploadu pliku (kod ' . $f['error'] . ').';
        } elseif ($f['size'] > 50 * 1024 * 1024) {
            $errors[] = 'Plik zbyt duży (maks. 50 MB).';
        } else {
            $enc     = $_POST['encoding'] ?? 'utf-8';
            $skip    = (int)($_POST['skip_rows'] ?? 1);
            $replace = !empty($_POST['replace_all']);

            $handle = fopen($f['tmp_name'], 'r');
            if (!$handle) {
                $errors[] = 'Nie można otworzyć pliku.';
            } else {
                if ($replace) db()->exec("DELETE FROM postal_codes");

                // Wykryj separator
                $first_line = fgets($handle);
                rewind($handle);
                $sep = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';

                $ins = db()->prepare(
                    "INSERT OR IGNORE INTO postal_codes (code, city, gmina, powiat, woj)
                     VALUES (?, ?, ?, ?, ?)"
                );

                $imported = 0; $skipped = 0; $line_no = 0;
                while (($row = fgetcsv($handle, 512, $sep)) !== false) {
                    $line_no++;
                    if ($line_no <= $skip) continue; // pomiń nagłówek

                    // Konwersja kodowania
                    if ($enc !== 'utf-8') {
                        $row = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', $enc), $row);
                    }

                    $code  = trim($row[0] ?? '');
                    $city  = trim($row[1] ?? '');
                    $gmina = trim($row[2] ?? '');
                    $pow   = trim($row[3] ?? '');
                    $woj   = trim($row[4] ?? '');

                    // Walidacja kodu: XX-XXX
                    if (!preg_match('/^\d{2}-\d{3}$/', $code) || !$city) {
                        $skipped++; continue;
                    }

                    try {
                        $ins->execute([$code, $city, $gmina, $pow, $woj]);
                        $imported++;
                    } catch (\Throwable $e) {
                        $skipped++;
                    }
                }
                fclose($handle);

                $stats = ['imported' => $imported, 'skipped' => $skipped, 'lines' => $line_no - $skip];
                flash_set('success', "Zaimportowano {$imported} kodów pocztowych (pominięto: {$skipped}).");
                header('Location: ' . $_SERVER['PHP_SELF']); exit;
            }
        }
    }
}

// ── Statystyki ───────────────────────────────────────────────────────────────
$count  = (int)(db_one("SELECT COUNT(*) AS c FROM postal_codes")['c'] ?? 0);
$sample = $count > 0 ? db_all("SELECT code, city, gmina, powiat, woj FROM postal_codes ORDER BY code LIMIT 10") : [];
$woj_count = $count > 0 ? (int)(db_one("SELECT COUNT(DISTINCT woj) AS c FROM postal_codes WHERE woj != ''")['c'] ?? 0) : 0;
$city_count = $count > 0 ? (int)(db_one("SELECT COUNT(DISTINCT city) AS c FROM postal_codes")['c'] ?? 0) : 0;

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Słownik kodów pocztowych</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center justify-content-center flex-shrink-0"
       style="width:48px;height:48px;background:#EFF4FF;border-radius:12px">
    <i class="bi bi-mailbox2 fs-4 text-primary"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Słownik kodów pocztowych</h4>
    <div class="text-muted small">Import CSV z Poczty Polskiej — używany w autouzupełnianiu adresów</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
  <div><?php foreach ($errors as $e) echo '<div>' . h($e) . '</div>'; ?></div>
</div>
<?php endif; ?>

<!-- ── Stan bazy ────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-4">
        <div class="fs-2 fw-bold <?= $count > 0 ? 'text-success' : 'text-muted' ?>">
          <?= number_format($count, 0, '.', ' ') ?>
        </div>
        <div class="small text-muted">rekordów w bazie</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-4">
        <div class="fs-2 fw-bold text-primary"><?= number_format($city_count, 0, '.', ' ') ?></div>
        <div class="small text-muted">unikalnych miejscowości</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-4">
        <div class="fs-2 fw-bold text-info"><?= $woj_count ?></div>
        <div class="small text-muted">województw z danymi</div>
      </div>
    </div>
  </div>
</div>

<?php if ($count === 0): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4">
  <i class="bi bi-exclamation-triangle-fill text-warning flex-shrink-0 mt-1 fs-5"></i>
  <div>
    <div class="fw-semibold mb-1">Brak danych — słownik jest pusty</div>
    <div class="small">
      Autouzupełnianie kodów pocztowych w formularzach adresowych nie będzie działać.<br>
      Zaimportuj plik CSV z kodami pocztowymi poniżej.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Import ────────────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header border-bottom fw-semibold py-3">
    <i class="bi bi-upload me-2 text-primary"></i>Import pliku CSV
  </div>
  <div class="card-body">
    <div class="alert alert-info py-2 px-3 mb-3 small">
      <strong>Obsługiwane formaty:</strong>
      Plik CSV (separator <code>;</code> lub <code>,</code>) z kolumnami:
      <code>KOD;MIASTO</code> lub <code>KOD;MIASTO;GMINA;POWIAT;WOJEWÓDZTWO</code>.<br>
      Pobierz z <a href="https://www.poczta-polska.pl/hermes/uploads/2013/07/spis_kodow_pocztowych.xls"
                   target="_blank" rel="noopener" class="alert-link">poczta-polska.pl</a>
      (XLS → zapisz jako CSV) lub użyj otwartych baz danych takich jak
      <a href="https://kodpocztowy.intami.pl" target="_blank" rel="noopener" class="alert-link">kodpocztowy.intami.pl</a>.
    </div>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op"   value="import">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Plik CSV <span class="text-danger">*</span></label>
          <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Kodowanie</label>
          <select name="encoding" class="form-select">
            <option value="utf-8">UTF-8 (domyślne)</option>
            <option value="windows-1250">Windows-1250 (Poczta PL)</option>
            <option value="iso-8859-2">ISO-8859-2</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Pomiń wierszy nagłówka</label>
          <input type="number" name="skip_rows" class="form-control" value="1" min="0" max="5">
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="replace_all" id="replace_all" value="1">
            <label class="form-check-label" for="replace_all">
              <i class="bi bi-trash3 text-danger me-1"></i>
              Wyczyść istniejące dane przed importem
            </label>
          </div>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-upload me-1"></i>Importuj
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Podgląd danych ─────────────────────────────────────────────────────── -->
<?php if ($sample): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header border-bottom fw-semibold py-3 d-flex justify-content-between align-items-center">
    <span><i class="bi bi-table me-2 text-muted"></i>Przykładowe rekordy (pierwsze 10)</span>
    <form method="post" style="display:inline"
          onsubmit="return confirm('Usunąć wszystkie kody pocztowe?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op"   value="clear">
      <button type="submit" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-trash3 me-1"></i>Wyczyść bazę
      </button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 small">
      <thead class="table-light">
        <tr>
          <th>Kod</th><th>Miejscowość</th><th>Gmina</th><th>Powiat</th><th>Województwo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sample as $r): ?>
        <tr>
          <td class="font-monospace fw-semibold"><?= h($r['code']) ?></td>
          <td><?= h($r['city']) ?></td>
          <td class="text-muted"><?= h($r['gmina']) ?></td>
          <td class="text-muted"><?= h($r['powiat']) ?></td>
          <td class="text-muted"><?= h($r['woj']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Podgląd autocomplete ──────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header border-bottom fw-semibold py-3">
    <i class="bi bi-search me-2 text-muted"></i>Test autouzupełniania
  </div>
  <div class="card-body">
    <div class="row g-3" style="max-width:600px">
      <div class="col-sm-4" style="position:relative">
        <label class="form-label small fw-semibold">Kod pocztowy</label>
        <input id="test_postal" class="form-control font-monospace" placeholder="00-001" maxlength="6">
      </div>
      <div class="col-sm-8" style="position:relative">
        <label class="form-label small fw-semibold">Miasto (TERYT)</label>
        <input id="test_city" class="form-control" placeholder="wpisz nazwę miasta…">
      </div>
      <div class="col-12">
        <div id="test_result" class="small text-muted"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var api = '<?= h(APP_URL) ?>/crm/api/addr.php';

  // Test postal
  var testPostal = document.getElementById('test_postal');
  var testCity   = document.getElementById('test_city');
  var testResult = document.getElementById('test_result');

  function fmtPostal(v) {
    var d = v.replace(/\D/g,'').slice(0,5);
    return d.length>2 ? d.slice(0,2)+'-'+d.slice(2) : d;
  }

  if (testPostal) {
    testPostal.addEventListener('input', function() {
      var fmt = fmtPostal(this.value);
      if (fmt !== this.value) this.value = fmt;
      var q = this.value.trim();
      if (q.length < 2) { testResult.textContent = ''; return; }
      fetch(api + '?action=postal&q=' + encodeURIComponent(q))
        .then(function(r){return r.json();})
        .then(function(rows){
          if (!rows.length) { testResult.textContent = 'Brak wyników (słownik pusty lub brak dopasowania)'; return; }
          testResult.innerHTML = '<strong>Znalezione:</strong> '
            + rows.slice(0,5).map(function(r){ return r.code + ' → ' + r.city; }).join(' | ');
        }).catch(function(e){ testResult.textContent = 'Błąd: ' + e.message; });
    });
  }

  if (testCity) {
    var timer;
    testCity.addEventListener('input', function() {
      clearTimeout(timer);
      var q = this.value.trim();
      if (q.length < 2) { testResult.textContent = ''; return; }
      timer = setTimeout(function() {
        fetch(api + '?action=city&q=' + encodeURIComponent(q))
          .then(function(r){return r.json();})
          .then(function(rows){
            if (!rows.length) { testResult.textContent = 'Brak miast w TERYT (zaimportuj dane TERYT w admin/teryt_import.php)'; return; }
            testResult.innerHTML = '<strong>Miasta z TERYT:</strong> ' + rows.slice(0,8).join(', ');
          }).catch(function(e){ testResult.textContent = 'Błąd: '+e.message; });
      }, 250);
    });
  }
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
