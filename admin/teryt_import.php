<?php
/**
 * admin/teryt_import.php — Import danych TERYT z GUS.
 *
 * Obsługuje pliki:
 *  - TERC_Urzedowy_YYYY-MM-DD.xml  (pobierany z eTERYT GUS)
 *  - TERC_Adresowy_YYYY-MM-DD.xml
 *  - terc.csv  (CSV export z eTERYT: WOJ;POW;GMI;RODZ;NAZWA;NAZWA_DOD;STAN_NA)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Import TERYT';

// Auto-migracja tabeli
db()->exec("CREATE TABLE IF NOT EXISTS teryt_units (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    level       INTEGER NOT NULL,
    kod_woj     TEXT NOT NULL,
    kod_pow     TEXT,
    kod_gmi     TEXT,
    nazwa       TEXT NOT NULL,
    nazwa_typ   TEXT,
    UNIQUE(COALESCE(kod_gmi,''),COALESCE(kod_pow,''),kod_woj,level)
)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_teryt_woj ON teryt_units(kod_woj,level)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_teryt_pow ON teryt_units(kod_pow,level)");

$stats  = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'seed_woj') {
        // Zawsze dostępny seed województw
        $woj = [
            '02'=>'dolnośląskie','04'=>'kujawsko-pomorskie','06'=>'lubelskie','08'=>'lubuskie',
            '10'=>'łódzkie','12'=>'małopolskie','14'=>'mazowieckie','16'=>'opolskie',
            '18'=>'podkarpackie','20'=>'podlaskie','22'=>'pomorskie','24'=>'śląskie',
            '26'=>'świętokrzyskie','28'=>'warmińsko-mazurskie','30'=>'wielkopolskie','32'=>'zachodniopomorskie',
        ];
        $ins = db()->prepare("INSERT OR IGNORE INTO teryt_units (level,kod_woj,nazwa) VALUES (1,?,?)");
        foreach ($woj as $k => $n) $ins->execute([$k, $n]);
        flash_set('success', 'Seeded 16 województw.');
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }

    if ($op === 'clear') {
        db()->exec("DELETE FROM teryt_units");
        flash_set('warning', 'Usunięto wszystkie dane TERYT.');
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }

    if ($op === 'import' && isset($_FILES['teryt_file'])) {
        $file = $_FILES['teryt_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Błąd przesyłania pliku: ' . $file['error'];
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $tmp = $file['tmp_name'];

            // ZIP — wypakuj i znajdź TERC*.xml lub terc.csv
            if ($ext === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $found = null;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (preg_match('/TERC.*\.(xml|csv)$/i', $name)) {
                            $found = $name;
                            $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            break;
                        }
                    }
                    if ($found) {
                        $tmp_extracted = tempnam(sys_get_temp_dir(), 'teryt_') . '.' . $ext;
                        file_put_contents($tmp_extracted, $zip->getFromName($found));
                        $tmp = $tmp_extracted;
                        $zip->close();
                    } else {
                        $errors[] = 'W pliku ZIP nie znaleziono pliku TERC*.xml ani terc.csv.';
                        $zip->close();
                    }
                } else {
                    $errors[] = 'Nie można otworzyć pliku ZIP.';
                }
            }

            if (!$errors) {
                try {
                    $stats = $ext === 'xml'
                        ? import_terc_xml($tmp)
                        : import_terc_csv($tmp);
                    flash_set('success', "Import zakończony: {$stats['woj']} woj., {$stats['pow']} pow., {$stats['gmi']} gmin.");
                } catch (\Throwable $e) {
                    $errors[] = 'Błąd importu: ' . $e->getMessage();
                }
            }
        }
        if ($errors) flash_set('danger', implode(' ', $errors));
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
}

// ── Parsery ───────────────────────────────────────────────────────────────────

function import_terc_xml(string $path): array {
    $xml = simplexml_load_file($path);
    if (!$xml) throw new \RuntimeException('Nieprawidłowy plik XML.');

    $ins = db()->prepare(
        "INSERT OR REPLACE INTO teryt_units (level,kod_woj,kod_pow,kod_gmi,nazwa,nazwa_typ)
         VALUES (?,?,?,?,?,?)"
    );
    db()->beginTransaction();
    $c = ['woj'=>0,'pow'=>0,'gmi'=>0];

    // Format: <row><WOJ>14</WOJ><POW></POW><GMI></GMI><RODZ></RODZ><NAZWA>...</NAZWA><NAZWA_DOD>...</NAZWA_DOD></row>
    foreach ($xml->row ?? $xml->catalog->row ?? [] as $r) {
        $woj  = str_pad(trim((string)($r->WOJ ?? '')), 2, '0', STR_PAD_LEFT);
        $pow  = trim((string)($r->POW ?? ''));
        $gmi  = trim((string)($r->GMI ?? ''));
        $rodz = trim((string)($r->RODZ ?? ''));
        $name = trim((string)($r->NAZWA ?? ''));
        $nadd = trim((string)($r->NAZWA_DOD ?? ''));
        if (!$woj || !$name) continue;

        if (!$pow && !$gmi) {
            // Województwo
            $ins->execute([1, $woj, null, null, $name, null]);
            $c['woj']++;
        } elseif ($pow && !$gmi) {
            // Powiat
            $kod_pow = $woj . str_pad($pow, 2, '0', STR_PAD_LEFT);
            $ins->execute([2, $woj, $kod_pow, null, $name, $nadd ?: null]);
            $c['pow']++;
        } else {
            // Gmina
            $kod_pow = $woj . str_pad($pow, 2, '0', STR_PAD_LEFT);
            $kod_gmi = $kod_pow . str_pad($gmi, 2, '0', STR_PAD_LEFT) . $rodz;
            $ins->execute([3, $woj, $kod_pow, $kod_gmi, $name, $nadd ?: null]);
            $c['gmi']++;
        }
    }
    db()->commit();
    return $c;
}

function import_terc_csv(string $path): array {
    $fp  = fopen($path, 'r');
    if (!$fp) throw new \RuntimeException('Nie można otworzyć pliku CSV.');
    // Wykryj separator i BOM
    $header_raw = fgets($fp);
    $header_raw = preg_replace('/^\xEF\xBB\xBF/', '', $header_raw); // BOM UTF-8
    $sep = strpos($header_raw, ';') !== false ? ';' : ',';
    rewind($fp);

    $ins = db()->prepare(
        "INSERT OR REPLACE INTO teryt_units (level,kod_woj,kod_pow,kod_gmi,nazwa,nazwa_typ)
         VALUES (?,?,?,?,?,?)"
    );
    db()->beginTransaction();
    $c = ['woj'=>0,'pow'=>0,'gmi'=>0];
    $header = null;

    while (($row = fgetcsv($fp, 0, $sep)) !== false) {
        if (!$header) { $header = array_map('trim', $row); continue; }
        $r = array_combine($header, array_pad($row, count($header), ''));
        $woj  = str_pad(trim($r['WOJ'] ?? ''), 2, '0', STR_PAD_LEFT);
        $pow  = trim($r['POW'] ?? '');
        $gmi  = trim($r['GMI'] ?? '');
        $rodz = trim($r['RODZ'] ?? '');
        $name = trim($r['NAZWA'] ?? '');
        $nadd = trim($r['NAZWA_DOD'] ?? '');
        if (!$woj || !$name || $woj === '00') continue;

        if (!$pow && !$gmi) {
            $ins->execute([1, $woj, null, null, $name, null]);
            $c['woj']++;
        } elseif ($pow && !$gmi) {
            $kod_pow = $woj . str_pad($pow, 2, '0', STR_PAD_LEFT);
            $ins->execute([2, $woj, $kod_pow, null, $name, $nadd ?: null]);
            $c['pow']++;
        } else {
            $kod_pow = $woj . str_pad($pow, 2, '0', STR_PAD_LEFT);
            $kod_gmi = $kod_pow . str_pad($gmi, 2, '0', STR_PAD_LEFT) . $rodz;
            $ins->execute([3, $woj, $kod_pow, $kod_gmi, $name, $nadd ?: null]);
            $c['gmi']++;
        }
    }
    fclose($fp);
    db()->commit();
    return $c;
}

// ── Statystyki ────────────────────────────────────────────────────────────────
$teryt_counts = [
    'woj' => (int)(db_one("SELECT COUNT(*) AS c FROM teryt_units WHERE level=1")['c'] ?? 0),
    'pow' => (int)(db_one("SELECT COUNT(*) AS c FROM teryt_units WHERE level=2")['c'] ?? 0),
    'gmi' => (int)(db_one("SELECT COUNT(*) AS c FROM teryt_units WHERE level=3")['c'] ?? 0),
];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4" style="max-width:760px">
  <div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:44px;height:44px;border-radius:10px;background:#e8f5e9;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#16a34a">
      <i class="bi bi-geo-alt-fill"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Import TERYT — jednostki terytorialne</h1>
      <p class="text-muted mb-0 small">Pobierz plik z <a href="https://eteryt.stat.gov.pl/eTeryt/rejestr_teryt/udostepnianie_danych/baza_teryt/uzytkownicy_indywidualni/pobieranie/pliki_pelne.aspx?contrast=default" target="_blank">eTERYT GUS <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i></a> i załaduj poniżej.</p>
    </div>
  </div>

  <?= flash_get() ?>

  <!-- Stan bazy -->
  <div class="row g-3 mb-4">
    <?php foreach ([['Województwa','woj','#0176D3'],['Powiaty','pow','#7F2B8B'],['Gminy','gmi','#2E844A']] as [$lbl,$key,$col]): ?>
    <div class="col-4">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="fs-3 fw-bold" style="color:<?= $col ?>"><?= number_format($teryt_counts[$key]) ?></div>
        <div class="text-muted small"><?= $lbl ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Instrukcja -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 fw-semibold small">Jak pobrać plik TERYT z GUS?</div>
    <div class="card-body small">
      <ol class="mb-0">
        <li>Wejdź na <a href="https://eteryt.stat.gov.pl/eTeryt/rejestr_teryt/udostepnianie_danych/baza_teryt/uzytkownicy_indywidualni/pobieranie/pliki_pelne.aspx?contrast=default" target="_blank">eTERYT — pliki pełne</a></li>
        <li>Pobierz <strong>TERC — urzędowy</strong> (plik ZIP zawierający XML)</li>
        <li>Wgraj poniżej plik ZIP lub wypakowany XML/CSV</li>
      </ol>
      <div class="mt-2 text-muted">Format CSV: kolumny <code>WOJ;POW;GMI;RODZ;NAZWA;NAZWA_DOD;STAN_NA</code> (separator <code>;</code>). Format XML: standardowy export z eTERYT.</div>
    </div>
  </div>

  <!-- Upload -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-2 fw-semibold">Importuj plik</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="_op" value="import">
        <div class="col-md-8">
          <label class="form-label small fw-semibold">Plik TERC (ZIP, XML lub CSV)</label>
          <input type="file" name="teryt_file" class="form-control" accept=".zip,.xml,.csv" required>
        </div>
        <div class="col-md-4">
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-upload me-1"></i>Importuj
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Akcje -->
  <div class="d-flex gap-2">
    <form method="post" class="d-inline">
      <?= csrf_field() ?><input type="hidden" name="_op" value="seed_woj">
      <button class="btn btn-outline-primary btn-sm">
        <i class="bi bi-globe me-1"></i>Seed tylko województwa (16)
      </button>
    </form>
    <?php if ($teryt_counts['gmi'] > 0 || $teryt_counts['pow'] > 0): ?>
    <form method="post" class="d-inline">
      <?= csrf_field() ?><input type="hidden" name="_op" value="clear">
      <button class="btn btn-outline-danger btn-sm"
              data-confirm="Usunąć wszystkie dane TERYT? Województwa zostaną zachowane przez seed.">
        <i class="bi bi-trash3 me-1"></i>Wyczyść bazę TERYT
      </button>
    </form>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-outline-secondary btn-sm ms-auto">
      ← Admin
    </a>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
