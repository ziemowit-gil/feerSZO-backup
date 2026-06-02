<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
require_module_enabled('reports_enabled', 'Moduł zestawień');

$PAGE_TITLE = 'Statystyki wolontariatu';

// ── Parametry filtrów ─────────────────────────────────────────────────────────
$current_year = (int)date('Y');
$sel_year     = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;
$sel_status   = trim($_GET['status'] ?? '');

// ── Zapis stawki godzinowej (admin) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_rate']) && is_admin()) {
    csrf_check();
    $new_rate = (float)str_replace(',', '.', $_POST['volunteer_hourly_rate'] ?? '30');
    if ($new_rate <= 0) $new_rate = 30;
    $existing = db_one("SELECT id FROM settings WHERE key_='volunteer_hourly_rate'");
    if ($existing) {
        db()->prepare("UPDATE settings SET value=? WHERE key_='volunteer_hourly_rate'")
              ->execute([(string)$new_rate]);
    } else {
        db_insert('settings', ['key_' => 'volunteer_hourly_rate', 'value' => (string)$new_rate]);
    }
    flash_set('success', 'Stawka godzinowa zaktualizowana: ' . number_format($new_rate, 2, ',', ' ') . ' PLN/h');
    header('Location: volunteer_stats.php?year=' . $sel_year . '&status=' . urlencode($sel_status));
    exit;
}

// ── XLS Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    // SpreadsheetML — działa bez zewnętrznych bibliotek
    $hourly_rate = (float)(org_setting('volunteer_hourly_rate') ?: '30');

    $where_parts = ["strftime('%Y', data_rozpoczecia) = ?"];
    $params      = [(string)$sel_year];
    if ($sel_status !== '') {
        $where_parts[] = "status = ?";
        $params[]      = $sel_status;
    }
    $where = implode(' AND ', $where_parts);

    $all_rows = db_all("SELECT * FROM umowy_wolontariat WHERE {$where} ORDER BY data_rozpoczecia", $params);

    $total_hours = array_sum(array_column($all_rows, 'godzin_przepracowanych'));
    $total_value = $total_hours * $hourly_rate;

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wolontariusze_' . $sel_year . '.xls"');
    header('Pragma: no-cache');

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    echo "<Worksheet ss:Name=\"Wolontariusze " . htmlspecialchars((string)$sel_year) . "\">\n";
    echo "<Table>\n";

    // Nagłówki
    $headers = ['Nr umowy','Imię i nazwisko','E-mail','Status','Data rozpoczęcia','Data zakończenia',
                'Miejsce','Projekt/program','Godziny','Wartość (PLN)'];
    echo "<Row>\n";
    foreach ($headers as $h) {
        echo "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($h) . "</Data></Cell>\n";
    }
    echo "</Row>\n";

    foreach ($all_rows as $r) {
        $hours = (float)($r['godzin_przepracowanych'] ?? 0);
        $value = $hours * $hourly_rate;
        echo "<Row>\n";
        $cells = [
            $r['numer_umowy']        ?? '',
            $r['imie_nazwisko']      ?? '',
            $r['email']              ?? '',
            $r['status']             ?? '',
            $r['data_rozpoczecia']   ?? '',
            $r['data_zakonczenia']   ?? '',
            $r['miejsce_wolontariatu'] ?? '',
            $r['projekt_program']    ?? '',
            $hours,
            round($value, 2),
        ];
        foreach ($cells as $i => $cell) {
            $type = ($i >= 8) ? 'Number' : 'String';
            echo "<Cell><Data ss:Type=\"{$type}\">" . htmlspecialchars((string)$cell) . "</Data></Cell>\n";
        }
        echo "</Row>\n";
    }

    // Podsumowanie
    echo "<Row></Row>\n";
    echo "<Row><Cell><Data ss:Type=\"String\">ŁĄCZNIE godzin:</Data></Cell>";
    echo "<Cell><Data ss:Type=\"Number\">" . round($total_hours, 2) . "</Data></Cell></Row>\n";
    echo "<Row><Cell><Data ss:Type=\"String\">Wartość (PLN):</Data></Cell>";
    echo "<Cell><Data ss:Type=\"Number\">" . round($total_value, 2) . "</Data></Cell></Row>\n";

    echo "</Table>\n</Worksheet>\n</Workbook>\n";
    exit;
}

// ── Stawka godzinowa ──────────────────────────────────────────────────────────
$hourly_rate = (float)(org_setting('volunteer_hourly_rate') ?: '30');
if ($hourly_rate <= 0) $hourly_rate = 30.0;

// ── Pomocnicze WHERE ──────────────────────────────────────────────────────────
$where_year_parts = ["strftime('%Y', data_rozpoczecia) = ?"];
$params_year      = [(string)$sel_year];
if ($sel_status !== '') {
    $where_year_parts[] = "status = ?";
    $params_year[]      = $sel_status;
}
$where_year   = implode(' AND ', $where_year_parts);
$prev_year    = $sel_year - 1;
$where_prev   = str_replace((string)$sel_year, (string)$prev_year, $where_year);
$params_prev  = $params_year;
$params_prev[0] = (string)$prev_year;

// ── Statystyki główne ────────────────────────────────────────────────────────
$total_this_year = (int)(db_one(
    "SELECT COUNT(*) AS c FROM umowy_wolontariat WHERE {$where_year}", $params_year
)['c'] ?? 0);

$total_last_year = (int)(db_one(
    "SELECT COUNT(*) AS c FROM umowy_wolontariat WHERE {$where_prev}", $params_prev
)['c'] ?? 0);

// Aktywne umowy (status w realizacji lub podpisana, niezależnie od roku)
$active_contracts = (int)(db_one(
    "SELECT COUNT(*) AS c FROM umowy_wolontariat WHERE status IN ('podpisana','w realizacji','obowiązująca')"
)['c'] ?? 0);

// Suma godzin (wybrany rok + filtr statusu)
$total_hours_row = db_one(
    "SELECT SUM(godzin_przepracowanych) AS s FROM umowy_wolontariat WHERE {$where_year}", $params_year
);
$total_hours = (float)($total_hours_row['s'] ?? 0);
$total_value = $total_hours * $hourly_rate;

// ── Breakdown miesięczny (ostatnie 12 miesięcy) ───────────────────────────────
$monthly = [];
for ($m = 1; $m <= 12; $m++) {
    $month_str = sprintf('%04d-%02d', $sel_year, $m);
    $cnt = db_one(
        "SELECT COUNT(*) AS c FROM umowy_wolontariat
         WHERE strftime('%Y-%m', data_rozpoczecia) = ?
         " . ($sel_status !== '' ? "AND status = ?" : ""),
        $sel_status !== '' ? [$month_str, $sel_status] : [$month_str]
    );
    $monthly[$m] = (int)($cnt['c'] ?? 0);
}
$max_monthly = max(1, max($monthly));

// Nazwy miesięcy po polsku
$months_pl = ['','Sty','Lut','Mar','Kwi','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'];

// ── Breakdown wg projektu/programu (top 10) ───────────────────────────────────
$by_project = db_all(
    "SELECT COALESCE(NULLIF(TRIM(projekt_program),''), '(brak)') AS grp,
            COUNT(*) AS cnt,
            SUM(godzin_przepracowanych) AS hours
     FROM umowy_wolontariat
     WHERE {$where_year}
     GROUP BY grp ORDER BY cnt DESC LIMIT 10",
    $params_year
);

// ── Breakdown wg miejsca wolontariatu (top 10) ────────────────────────────────
$by_location = db_all(
    "SELECT COALESCE(NULLIF(TRIM(miejsce_wolontariatu),''), '(brak)') AS grp,
            COUNT(*) AS cnt
     FROM umowy_wolontariat
     WHERE {$where_year}
     GROUP BY grp ORDER BY cnt DESC LIMIT 10",
    $params_year
);

// ── Rozkład wiekowy ───────────────────────────────────────────────────────────
$all_dob = db_all(
    "SELECT data_urodzenia, pesel FROM umowy_wolontariat WHERE {$where_year}", $params_year
);
$age_buckets = ['<18' => 0, '18-25' => 0, '26-35' => 0, '36-50' => 0, '51-65' => 0, '65+' => 0];
foreach ($all_dob as $r) {
    $dob = $r['data_urodzenia'] ?? null;
    // Jeśli brak data_urodzenia, spróbuj z PESEL
    if (!$dob && !empty($r['pesel'])) {
        $dob = pesel_to_birthdate($r['pesel']);
    }
    if (!$dob) continue;
    $age = (int)date_diff(date_create($dob), date_create())->y;
    if ($age < 18)       $age_buckets['<18']++;
    elseif ($age <= 25)  $age_buckets['18-25']++;
    elseif ($age <= 35)  $age_buckets['26-35']++;
    elseif ($age <= 50)  $age_buckets['36-50']++;
    elseif ($age <= 65)  $age_buckets['51-65']++;
    else                 $age_buckets['65+']++;
}

// ── Lata do selektora ────────────────────────────────────────────────────────
$year_range_row = db_one("SELECT MIN(strftime('%Y',data_rozpoczecia)) AS mn,
                                  MAX(strftime('%Y',data_rozpoczecia)) AS mx
                           FROM umowy_wolontariat WHERE data_rozpoczecia IS NOT NULL");
$year_min = (int)($year_range_row['mn'] ?? $current_year);
$year_max = (int)($year_range_row['mx'] ?? $current_year);
$year_max = max($year_max, $current_year);

// ── Statystyki statusów w wybranym roku ───────────────────────────────────────
$status_breakdown = db_all(
    "SELECT status, COUNT(*) AS cnt FROM umowy_wolontariat
     WHERE strftime('%Y', data_rozpoczecia) = ?
     GROUP BY status ORDER BY cnt DESC",
    [(string)$sel_year]
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-people-fill text-primary"></i> Statystyki wolontariatu</h4>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <!-- Eksport -->
    <a href="?year=<?= $sel_year ?>&status=<?= urlencode($sel_status) ?>&export=xls"
       class="btn btn-sm btn-outline-success">
      <i class="bi bi-file-earmark-spreadsheet"></i> Eksport XLS
    </a>
  </div>
</div>

<!-- ── Filtry ─────────────────────────────────────────────────────────────── -->
<form method="get" class="card shadow-sm mb-4 p-3">
  <div class="row g-2 align-items-end">
    <div class="col-sm-auto">
      <label class="form-label form-label-sm mb-1">Rok</label>
      <select name="year" class="form-select form-select-sm" style="width:auto">
        <?php for ($y = $year_max; $y >= $year_min; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $sel_year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-sm-auto">
      <label class="form-label form-label-sm mb-1">Status umowy</label>
      <select name="status" class="form-select form-select-sm" style="width:auto">
        <option value="">— wszystkie —</option>
        <?php foreach (STATUS_LABELS as $sv => $sm): ?>
        <option value="<?= h($sv) ?>" <?= $sel_status === $sv ? 'selected' : '' ?>>
          <?= h($sm['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-auto">
      <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtruj</button>
    </div>
  </div>
</form>

<!-- ── Kafelki KPI ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #3b82f6 !important">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-person-plus text-primary me-1"></i>Nowi wolontariusze <?= $sel_year ?></div>
        <div class="fs-2 fw-bold text-primary"><?= $total_this_year ?></div>
        <div class="small text-muted mt-1">
          Rok <?= $prev_year ?>: <strong><?= $total_last_year ?></strong>
          <?php
          if ($total_last_year > 0) {
              $diff_pct = round(($total_this_year - $total_last_year) / $total_last_year * 100, 1);
              $cls = $diff_pct >= 0 ? 'text-success' : 'text-danger';
              $ico = $diff_pct >= 0 ? 'bi-arrow-up' : 'bi-arrow-down';
              echo " <span class=\"{$cls}\"><i class=\"bi {$ico}\"></i> " . abs($diff_pct) . "%</span>";
          }
          ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #22c55e !important">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-check2-circle text-success me-1"></i>Aktywne umowy</div>
        <div class="fs-2 fw-bold text-success"><?= $active_contracts ?></div>
        <div class="small text-muted mt-1">podpisana / w realizacji</div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #f59e0b !important">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-clock text-warning me-1"></i>Godziny wolontariackie</div>
        <div class="fs-2 fw-bold text-warning"><?= number_format($total_hours, 0, ',', ' ') ?></div>
        <div class="small text-muted mt-1">h w roku <?= $sel_year ?></div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #8b5cf6 !important">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-currency-exchange" style="color:#8b5cf6"></i> Wartość wkładu</div>
        <div class="fs-2 fw-bold" style="color:#8b5cf6"><?= number_format($total_value, 0, ',', ' ') ?> PLN</div>
        <div class="small text-muted mt-1">stawka: <?= number_format($hourly_rate, 2, ',', ' ') ?> PLN/h</div>
      </div>
    </div>
  </div>

</div>

<!-- ── Admin: edycja stawki ───────────────────────────────────────────────── -->
<?php if (is_admin()): ?>
<div class="card shadow-sm mb-4 border-warning">
  <div class="card-header fw-semibold py-2 d-flex align-items-center gap-2">
    <i class="bi bi-gear text-warning"></i> Ustawienie stawki godzinowej
  </div>
  <div class="card-body py-2">
    <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_save_rate" value="1">
      <label class="form-label mb-0 me-1 small">Stawka godzinowa wolontariatu (PLN):</label>
      <input type="number" name="volunteer_hourly_rate" step="0.01" min="0.01"
             value="<?= h(number_format($hourly_rate, 2, '.', '')) ?>"
             class="form-control form-control-sm" style="width:110px">
      <button class="btn btn-sm btn-warning"><i class="bi bi-save"></i> Zapisz</button>
      <span class="text-muted small">Używana do obliczenia wartości wkładu w raportach dla donatorów.</span>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- ── Wykres miesięczny ─────────────────────────────────────────────────── -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-bar-chart-line text-primary me-1"></i>
        Nowe umowy wg miesiąca — <?= $sel_year ?>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-end gap-1" style="height:160px;overflow-x:auto">
          <?php foreach ($monthly as $m => $cnt): ?>
          <?php $bar_pct = $max_monthly > 0 ? round($cnt / $max_monthly * 100) : 0; ?>
          <div class="d-flex flex-column align-items-center flex-shrink-0" style="width:calc((100% - 11 * 6px)/12);min-width:32px">
            <div class="small text-muted mb-1" style="font-size:.7rem"><?= $cnt > 0 ? $cnt : '' ?></div>
            <div style="height:120px;width:100%;display:flex;align-items:flex-end">
              <div style="width:100%;height:<?= max(2, $bar_pct) ?>%;
                          background:<?= $m === (int)date('n') && $sel_year === $current_year ? '#3b82f6' : '#93c5fd' ?>;
                          border-radius:4px 4px 0 0;transition:height .3s"
                   title="<?= $months_pl[$m] ?> <?= $sel_year ?>: <?= $cnt ?> umów">
              </div>
            </div>
            <div class="text-muted mt-1" style="font-size:.7rem"><?= $months_pl[$m] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Wg projektu/programu ──────────────────────────────────────────────── -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold">
        <i class="bi bi-diagram-3 text-primary me-1"></i>
        Wg projektu / programu <span class="badge bg-secondary ms-1"><?= count($by_project) ?></span>
      </div>
      <?php if ($by_project): ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr><th>Projekt / program</th><th class="text-end">Wolontariusze</th><th class="text-end">Godziny</th></tr>
          </thead>
          <tbody>
          <?php foreach ($by_project as $pg): ?>
          <tr>
            <td><?= h($pg['grp']) ?></td>
            <td class="text-end"><?= (int)$pg['cnt'] ?></td>
            <td class="text-end"><?= number_format((float)$pg['hours'], 1, ',', ' ') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="card-body text-muted small">Brak danych.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Wg miejsca wolontariatu ────────────────────────────────────────────── -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold">
        <i class="bi bi-geo-alt text-primary me-1"></i>
        Wg miejsca wolontariatu <span class="badge bg-secondary ms-1"><?= count($by_location) ?></span>
      </div>
      <?php if ($by_location): ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr><th>Miejsce</th><th class="text-end">Umowy</th><th></th></tr>
          </thead>
          <tbody>
          <?php
          $max_loc = max(1, (int)($by_location[0]['cnt'] ?? 1));
          foreach ($by_location as $loc):
              $bar_w = round((int)$loc['cnt'] / $max_loc * 100);
          ?>
          <tr>
            <td><?= h($loc['grp']) ?></td>
            <td class="text-end"><?= (int)$loc['cnt'] ?></td>
            <td style="width:100px">
              <div style="background:#e0e7ff;border-radius:3px;height:8px;width:100%">
                <div style="background:#6366f1;border-radius:3px;height:8px;width:<?= $bar_w ?>%"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="card-body text-muted small">Brak danych.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Rozkład wiekowy ────────────────────────────────────────────────────── -->
  <div class="col-md-6">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-person-bounding-box text-primary me-1"></i>
        Rozkład wiekowy wolontariuszy
      </div>
      <div class="card-body">
        <?php
        $max_age = max(1, max($age_buckets));
        foreach ($age_buckets as $bucket => $cnt):
            $bar_w = round($cnt / $max_age * 100);
        ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:55px;text-align:right;font-size:.85rem;color:#64748b"><?= h($bucket) ?></div>
          <div style="flex:1;background:#f1f5f9;border-radius:4px;height:18px">
            <div style="background:#0ea5e9;border-radius:4px;height:18px;width:<?= $bar_w ?>%;
                        display:flex;align-items:center;padding-left:6px;font-size:.75rem;color:#fff;font-weight:600;white-space:nowrap">
              <?= $cnt > 0 ? $cnt : '' ?>
            </div>
          </div>
          <div style="width:30px;font-size:.82rem;color:#64748b"><?= $cnt ?></div>
        </div>
        <?php endforeach; ?>
        <div class="text-muted small mt-2">
          <i class="bi bi-info-circle"></i>
          Wiek obliczany na podstawie pola <em>data_urodzenia</em> lub numeru PESEL.
          Wolontariusze bez danych nie są uwzględnieni.
        </div>
      </div>
    </div>
  </div>

  <!-- ── Wg statusu ─────────────────────────────────────────────────────────── -->
  <div class="col-md-6">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-tags text-primary me-1"></i>
        Wg statusu — <?= $sel_year ?>
      </div>
      <?php if ($status_breakdown): ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr><th>Status</th><th class="text-end">Liczba</th><th class="text-end">%</th></tr>
          </thead>
          <tbody>
          <?php
          $total_for_pct = max(1, array_sum(array_column($status_breakdown, 'cnt')));
          foreach ($status_breakdown as $sb):
              $pct = round((int)$sb['cnt'] / $total_for_pct * 100, 1);
          ?>
          <tr>
            <td><?= status_badge($sb['status']) ?></td>
            <td class="text-end"><?= (int)$sb['cnt'] ?></td>
            <td class="text-end text-muted"><?= $pct ?>%</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="card-body text-muted small">Brak danych dla roku <?= $sel_year ?>.</div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /row -->

<div class="mt-3 text-end">
  <a href="?year=<?= $sel_year ?>&status=<?= urlencode($sel_status) ?>&export=xls"
     class="btn btn-outline-success">
    <i class="bi bi-file-earmark-spreadsheet"></i> Eksportuj dane do XLS
  </a>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
