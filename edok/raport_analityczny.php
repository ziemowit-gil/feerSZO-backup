<?php
/**
 * edok/raport_analityczny.php — Tabela analityczna przychody/koszty (EODoK).
 * Zestawienie zaakceptowanych dokumentów w wybranym okresie, pogrupowane wg
 * klasyfikacji (rodzaj działalności / projekt), z wynikiem (przychody - wydatki).
 * Widok ekranowy + eksport PDF (mPDF) i XLSX (includes/xlsx.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$data_od = trim($_GET['data_od'] ?? date('Y-m-01'));
$data_do = trim($_GET['data_do'] ?? date('Y-m-d'));

$raport = edok_analityczny_query(['data_od' => $data_od, 'data_do' => $data_do]);
$groups = $raport['groups'];
$totals = $raport['totals'];

$fmt = fn(float $v) => number_format($v, 2, ',', ' ');
$okres_label = date_pl($data_od) . ' – ' . date_pl($data_do);

// ── Eksport PDF ─────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $tmp_dir = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4-L',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10,
        'default_font' => 'dejavusans', 'tempDir' => $tmp_dir,
    ]);
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $mpdf->SetTitle('EODoK — tabela analityczna przychody/koszty ' . $okres_label);

    $html = '<style>
      * { box-sizing: border-box; } body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #000; }
      h1 { font-size: 14px; margin: 0 0 2px; } .sub { font-size: 10px; margin-bottom: 10px; color: #333; }
      table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #999; padding: 4px 6px; }
      th { background: #eee; text-align: left; font-size: 9.5px; text-transform: uppercase; }
      td.num { text-align: right; font-family: monospace; } tr.totals td { font-weight: 700; background: #f5f5f5; }
      td.neg { color: #b00000; }
    </style>';
    $html .= '<h1>' . h($org ?: 'EODoK') . ' — Tabela analityczna: przychody i koszty</h1>';
    $html .= '<div class="sub">Okres: ' . h($okres_label) . ' · dokumenty zaakceptowane · wygenerowano ' . date('d.m.Y H:i') . ' przez ' . h(current_user()['name'] ?? '—') . '</div>';
    $html .= '<table><thead><tr><th>Klasyfikacja</th><th>Przychody netto</th><th>Przychody brutto</th><th>Wydatki netto</th><th>Wydatki brutto</th><th>Wynik (brutto)</th></tr></thead><tbody>';
    foreach ($groups as $g) {
        $html .= '<tr><td>' . h($g['label']) . '</td>'
            . '<td class="num">' . $fmt($g['przychod_netto']) . '</td><td class="num">' . $fmt($g['przychod_brutto']) . '</td>'
            . '<td class="num">' . $fmt($g['wydatek_netto']) . '</td><td class="num">' . $fmt($g['wydatek_brutto']) . '</td>'
            . '<td class="num' . ($g['wynik_brutto'] < 0 ? ' neg' : '') . '">' . $fmt($g['wynik_brutto']) . '</td></tr>';
    }
    $html .= '<tr class="totals"><td>RAZEM</td>'
        . '<td class="num">' . $fmt($totals['przychod_netto']) . '</td><td class="num">' . $fmt($totals['przychod_brutto']) . '</td>'
        . '<td class="num">' . $fmt($totals['wydatek_netto']) . '</td><td class="num">' . $fmt($totals['wydatek_brutto']) . '</td>'
        . '<td class="num' . ($totals['wynik_brutto'] < 0 ? ' neg' : '') . '">' . $fmt($totals['wynik_brutto']) . '</td></tr>';
    $html .= '</tbody></table>';
    $mpdf->WriteHTML($html);
    $mpdf->Output('EODoK_analityczna_' . $data_od . '_' . $data_do . '.pdf', \Mpdf\Output\Destination::INLINE);
    exit;
}

// ── Eksport XLSX ────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/xlsx.php';
    $x = new XlsxWriter();
    $x->addSheet('Analityczna');
    $x->writeRow(['Tabela analityczna przychody/koszty — EODoK', 'Okres: ' . $okres_label]);
    $x->writeRow([]);
    $x->writeRow(['Klasyfikacja', 'Przychody netto', 'Przychody brutto', 'Wydatki netto', 'Wydatki brutto', 'Wynik (brutto)'], ['header']);
    foreach ($groups as $g) {
        $x->writeRow([$g['label'], $g['przychod_netto'], $g['przychod_brutto'], $g['wydatek_netto'], $g['wydatek_brutto'], $g['wynik_brutto']]);
    }
    $x->writeRow(['RAZEM', $totals['przychod_netto'], $totals['przychod_brutto'], $totals['wydatek_netto'], $totals['wydatek_brutto'], $totals['wynik_brutto']], ['header']);
    $x->output('EODoK_analityczna_' . $data_od . '_' . $data_do . '.xlsx');
    exit;
}

$PAGE_TITLE = 'Tabela analityczna przychody/koszty — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-bar-chart-line"></i> Tabela analityczna: przychody i koszty</h4>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/raport_analityczny.php?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-sm btn-outline-danger">
      <i class="bi bi-file-earmark-pdf"></i> PDF
    </a>
    <a href="<?= APP_URL ?>/edok/raport_analityczny.php?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="btn btn-sm btn-outline-success">
      <i class="bi bi-file-earmark-excel"></i> XLS
    </a>
  </div>
</div>
<p class="text-muted small">Zaakceptowane dokumenty EODoK w wybranym okresie (wg daty wpływu / wystawienia), pogrupowane wg klasyfikacji. Wynik = przychody brutto − wydatki brutto.</p>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small mb-1">Od</label>
    <input type="date" name="data_od" class="form-control form-control-sm" value="<?= h($data_od) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Do</label>
    <input type="date" name="data_do" class="form-control form-control-sm" value="<?= h($data_do) ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Pokaż</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Klasyfikacja</th>
        <th class="text-end">Przychody netto</th>
        <th class="text-end">Przychody brutto</th>
        <th class="text-end">Wydatki netto</th>
        <th class="text-end">Wydatki brutto</th>
        <th class="text-end">Wynik (brutto)</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$groups): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">Brak zaakceptowanych dokumentów w tym okresie.</td></tr>
      <?php endif; ?>
      <?php foreach ($groups as $g): ?>
      <tr>
        <td><?= h($g['label']) ?></td>
        <td class="text-end font-monospace"><?= $fmt($g['przychod_netto']) ?></td>
        <td class="text-end font-monospace fw-semibold"><?= $fmt($g['przychod_brutto']) ?></td>
        <td class="text-end font-monospace"><?= $fmt($g['wydatek_netto']) ?></td>
        <td class="text-end font-monospace fw-semibold"><?= $fmt($g['wydatek_brutto']) ?></td>
        <td class="text-end font-monospace fw-bold <?= $g['wynik_brutto'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $fmt($g['wynik_brutto']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($groups): ?>
    <tfoot class="table-light">
      <tr class="fw-bold">
        <td>RAZEM</td>
        <td class="text-end font-monospace"><?= $fmt($totals['przychod_netto']) ?></td>
        <td class="text-end font-monospace"><?= $fmt($totals['przychod_brutto']) ?></td>
        <td class="text-end font-monospace"><?= $fmt($totals['wydatek_netto']) ?></td>
        <td class="text-end font-monospace"><?= $fmt($totals['wydatek_brutto']) ?></td>
        <td class="text-end font-monospace <?= $totals['wynik_brutto'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $fmt($totals['wynik_brutto']) ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
