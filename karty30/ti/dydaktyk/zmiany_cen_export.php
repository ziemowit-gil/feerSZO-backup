<?php
/**
 * karty30/ti/dydaktyk/zmiany_cen_export.php — wyciąg zmian cen zajęć TI
 * (includes/ti_price_changes.php) do PDF albo XLSX. Wywoływany z Wydruków
 * (sekcja Raporty). Tylko kierownik / pracownik D3.
 *
 * GET: format=pdf|xlsx, status=all|active, course_id, from, to (RRRR-MM-DD —
 * zmiany, których zakres dat nachodzi na okres).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_price_changes.php';

karty30_migrate();
$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak uprawnień.'); }

$date_ok = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
$F = [
    'status'    => ($_GET['status'] ?? 'all') === 'active' ? 'active' : 'all',
    'course_id' => (int)($_GET['course_id'] ?? 0),
    'from'      => $date_ok($_GET['from'] ?? ''),
    'to'        => $date_ok($_GET['to'] ?? ''),
];
$format = ($_GET['format'] ?? 'pdf') === 'xlsx' ? 'xlsx' : 'pdf';
$rows   = ti_price_changes_report($F);

$course_name = $F['course_id'] ? (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$F['course_id']])['name'] ?? '#' . $F['course_id']) : 'wszystkie grupy';
$d = fn($v) => $v ? date('d.m.Y', strtotime((string)$v)) : '';
$scope_txt = 'Grupy: ' . $course_name
    . ' · ' . ($F['status'] === 'active' ? 'tylko aktywne' : 'aktywne i anulowane')
    . ($F['from'] || $F['to'] ? ' · okres: ' . ($d($F['from']) ?: '…') . ' – ' . ($d($F['to']) ?: '…') : '');
$gen_txt = 'Stan na: ' . date('d.m.Y H:i') . ' · wygenerował(a): ' . ($me['name'] ?? '');
$n_act   = count(array_filter($rows, fn($r) => $r['status'] === 'active'));
$sum_txt = 'Razem: ' . count($rows) . ' (aktywne ' . $n_act . ', anulowane ' . (count($rows) - $n_act) . ')';

$cols = ['ID', 'Status', 'Stan dziś', 'Zakres', 'Grupa', 'Kursant', 'Zmiana', 'Od', 'Do', 'Powiadomiono', 'Utworzono', 'Autor', 'Uzasadnienie'];
$cells = function (array $r) use ($d): array {
    return [
        '#' . $r['id'], $r['status_label'], $r['state'], $r['scope_label'],
        (string)$r['course_name'], (string)($r['client_name'] ?: '—'), $r['value_label'],
        $d($r['date_from']), $r['date_to'] ? $d($r['date_to']) : 'bezterminowo',
        $r['notified_at'] ? (int)$r['notified_count'] . ' os., ' . $d($r['notified_at']) : 'nie',
        $r['created_at'] ? date('d.m.Y H:i', strtotime((string)$r['created_at'])) : '',
        $r['author'] ?: '—', trim(preg_replace('/\s+/', ' ', (string)$r['reason'])),
    ];
};
$fname = 'zmiany_cen_ti_' . date('Y-m-d');
ti_print_log_add('price_changes_' . $format, 'Wyciąg zmian cen (' . strtoupper($format) . ') — ' . $scope_txt,
                 $F['course_id'], 0, $F, $me);

if ($format === 'xlsx') {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $x = new XlsxWriter();
    $x->addSheet('Zmiany cen');
    $x->writeRow(['Wyciąg zmian cen zajęć TI'], ['header']);
    $x->writeRow([$scope_txt]);
    $x->writeRow([$gen_txt]);
    $x->writeRow([]);
    $x->writeRow($cols, ['header']);
    foreach ($rows as $r) $x->writeRow($cells($r));
    if (!$rows) $x->writeRow(['Brak zmian cen dla wybranych filtrów.']);
    $x->writeRow([]);
    $x->writeRow([$sum_txt]);
    $x->output($fname . '.xlsx');
    exit;
}

// ── PDF ──────────────────────────────────────────────────────────────────────
ob_start(); ?>
<h1>Wyciąg zmian cen zajęć TI</h1>
<p class="meta"><?= h($scope_txt) ?><br><?= h($gen_txt) ?></p>
<?php if (!$rows): ?>
<p>Brak zmian cen dla wybranych filtrów.</p>
<?php else: ?>
<table>
  <thead><tr><?php foreach ($cols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] !== 'active' ? 'off' : '' ?>"><?php foreach ($cells($r) as $v): ?><td><?= h((string)$v) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<p class="sum"><?= h($sum_txt) ?></p>
<?php
$html = ob_get_clean();
try {
    require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';
    $tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $tmp,
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 12, 'margin_bottom' => 12,
        'default_font' => 'dejavusans',
    ]);
    $mpdf->SetTitle('Wyciąg zmian cen zajęć TI');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML(
        'body { font-size:8pt; } h1 { font-size:13pt; margin:0 0 2mm; }
         p.meta { color:#555; margin:0 0 4mm; } p.sum { margin-top:3mm; font-weight:bold; }
         table { border-collapse:collapse; width:100%; }
         th { background:#e9eef5; text-align:left; }
         th, td { border:.2mm solid #b8c2cc; padding:1.2mm 1.5mm; vertical-align:top; }
         tr.off td { color:#888; }',
        \Mpdf\HTMLParserMode::HEADER_CSS
    );
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
    $mpdf->Output($fname . '.pdf', \Mpdf\Output\Destination::INLINE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'Nie udało się wygenerować PDF: ' . h($e->getMessage());
}
