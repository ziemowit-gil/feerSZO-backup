<?php
/**
 * reports/karty30_export.php — Raporty modułu Dydaktyka 3 (Dydaktyka/TI) do druku.
 * Spójne z modelem raportów umów (reports/export.php): format=print|csv.
 *
 * report=courses  — lista kursów TI
 * report=lessons  — lekcje i frekwencja za miesiąc (param m=YYYY-MM)
 * report=payouts  — wypłaty prowadzących za miesiąc (param m=YYYY-MM)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';

require_login();
if (!(can_read('karty30') || is_admin())) { http_response_code(403); die('Brak dostępu do modułu Dydaktyka 3.'); }
karty30_migrate();

$report = $_GET['report'] ?? 'courses';
if (!in_array($report, ['courses','lessons','payouts'], true)) $report = 'courses';
$format = (($_GET['format'] ?? '') === 'csv') ? 'csv' : 'print';

// Raport wypłat zawiera dane wynagrodzeń — tylko kadra/administrator
if ($report === 'payouts' && !(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień do raportu wypłat.'); }

$ym = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$_msc = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$ym_label = ($_msc[(int)substr($ym,5,2)] ?? '') . ' ' . substr($ym,0,4);
$money = fn($x) => number_format((float)$x, 2, ',', ' ');

// ── Budowa zestawu danych: $title, $meta, $columns([label,key,align]), $data, $footer ──
$title = ''; $meta = ''; $columns = []; $data = []; $footer = null;

if ($report === 'courses') {
    $title = 'Kursy TI — Dydaktyka 3';
    $rows = db_all(
        "SELECT c.name, COALESCE(u.name,'—') AS instr, c.location,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS enrolled,
                c.billing_model, c.billing_amount, c.lesson_payout_bb, c.is_active
         FROM k30_ti_courses c LEFT JOIN users u ON u.id=c.instructor_id
         WHERE c.status!='cancelled' ORDER BY c.is_active DESC, c.name"
    );
    $columns = [
        ['label'=>'Kurs','align'=>'left'],
        ['label'=>'Prowadzący','align'=>'left'],
        ['label'=>'Lokalizacja','align'=>'left'],
        ['label'=>'Uczestnicy','align'=>'right'],
        ['label'=>'Model rozliczania','align'=>'left'],
        ['label'=>'Stawka/lekcja (bb)','align'=>'right'],
        ['label'=>'Aktywny','align'=>'left'],
    ];
    foreach ($rows as $r) {
        $data[] = [
            $r['name'],
            $r['instr'],
            $r['location'] ?: '—',
            (string)(int)$r['enrolled'],
            k30_ti_billing_model_label((int)$r['billing_model']),
            ((float)$r['lesson_payout_bb'] > 0) ? ($money($r['lesson_payout_bb']) . ' zł') : '—',
            ((int)$r['is_active']) ? 'tak' : 'nie',
        ];
    }
}

elseif ($report === 'lessons') {
    $title = 'Lekcje i frekwencja — Dydaktyka 3';
    $meta  = 'Miesiąc: ' . ucfirst($ym_label);
    $rows = db_all(
        "SELECT s.lesson_date, c.name AS course, COALESCE(u.name,'—') AS instr,
                s.time_from, s.time_to, s.status, s.topic,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id AND a.attended=1) AS att,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id) AS tot
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE strftime('%Y-%m', s.lesson_date)=?
         ORDER BY s.lesson_date, c.name", [$ym]
    );
    $st_labels = ['planned'=>'planowana','held'=>'odbyła się','cancelled'=>'odwołana'];
    $columns = [
        ['label'=>'Data','align'=>'left'],
        ['label'=>'Kurs','align'=>'left'],
        ['label'=>'Prowadzący','align'=>'left'],
        ['label'=>'Godziny','align'=>'left'],
        ['label'=>'Status','align'=>'left'],
        ['label'=>'Temat','align'=>'left'],
        ['label'=>'Obecność','align'=>'right'],
    ];
    foreach ($rows as $r) {
        $hrs = $r['time_from'] ? ($r['time_from'] . ($r['time_to'] ? '–'.$r['time_to'] : '')) : '—';
        $data[] = [
            date('d.m.Y', strtotime($r['lesson_date'])),
            $r['course'],
            $r['instr'],
            $hrs,
            $st_labels[$r['status']] ?? $r['status'],
            $r['topic'] ?: '—',
            (int)$r['att'] . '/' . (int)$r['tot'],
        ];
    }
}

else { // payouts
    $title = 'Wypłaty prowadzących — Dydaktyka 3';
    $meta  = 'Miesiąc: ' . ucfirst($ym_label) . ' · lekcje odbyte z kursów ze stawką brutto-brutto';
    $by = k30_ti_payouts_by_instructor($ym);
    $columns = [
        ['label'=>'Prowadzący','align'=>'left'],
        ['label'=>'Lekcje','align'=>'right'],
        ['label'=>'Brutto-brutto','align'=>'right'],
        ['label'=>'Koszt płatnika','align'=>'right'],
        ['label'=>'Brutto','align'=>'right'],
        ['label'=>'Składki','align'=>'right'],
        ['label'=>'Podatek','align'=>'right'],
        ['label'=>'Na rękę','align'=>'right'],
    ];
    $tot = _k30_ti_payout_zero();
    foreach ($by as $r) {
        foreach (['lessons','brutto_brutto','zus_employer','brutto','skladki','pit','netto'] as $k) $tot[$k] += $r[$k];
        $data[] = [
            $r['name'], (string)(int)$r['lessons'],
            $money($r['brutto_brutto']), $money($r['zus_employer']), $money($r['brutto']),
            $money($r['skladki']), $money($r['pit']), $money($r['netto']),
        ];
    }
    $footer = [
        'Razem (' . count($by) . ')', (string)(int)$tot['lessons'],
        $money($tot['brutto_brutto']), $money($tot['zus_employer']), $money($tot['brutto']),
        $money($tot['skladki']), $money($tot['pit']), $money($tot['netto']),
    ];
}

$org = defined('ORG_NAME') ? ORG_NAME : '';

// ── CSV ───────────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    $fn = 'karty30_' . $report . ($report !== 'courses' ? '_' . $ym : '') . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($c) => $c['label'], $columns), ';');
    foreach ($data as $row) fputcsv($out, $row, ';');
    if ($footer) fputcsv($out, $footer, ';');
    fclose($out);
    exit;
}

// ── HTML do druku ─────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title><?= h($title) ?> — <?= h($org) ?></title>
<style>
  body{font-family:Arial,sans-serif;font-size:10pt;margin:20px}
  h1{font-size:14pt;margin:0 0 4px}
  .meta{font-size:9pt;color:#555;margin-bottom:16px}
  table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:20px}
  th{background:#7c3aed;color:#fff;padding:4px 6px;text-align:left;white-space:nowrap}
  td{padding:3px 6px;border-bottom:1px solid #e0e0e0;vertical-align:top}
  tr:nth-child(even) td{background:#f7f5ff}
  td.r,th.r{text-align:right}
  tfoot td{font-weight:bold;border-top:2px solid #7c3aed;background:#f3f0ff}
  @media print{ .no-print{display:none} @page{margin:1.5cm;size:A4 landscape} }
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px">
  <button onclick="window.print()" style="padding:4px 18px;background:#7c3aed;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11pt">Drukuj / Zapisz jako PDF</button>
  <a href="index.php#noPrint" style="margin-left:10px;font-size:10pt">← Powrót</a>
</div>

<h1><?= h($org) ?> — <?= h($title) ?></h1>
<div class="meta">Wygenerowano: <?= date('d.m.Y H:i') ?><?= $meta ? ' | ' . h($meta) : '' ?></div>

<table>
  <thead>
    <tr><?php foreach ($columns as $c) echo '<th class="' . ($c['align']==='right'?'r':'') . '">' . h($c['label']) . '</th>'; ?></tr>
  </thead>
  <tbody>
  <?php if (!$data): ?>
    <tr><td colspan="<?= count($columns) ?>" style="text-align:center;color:#888;padding:14px">Brak danych dla wybranych kryteriów.</td></tr>
  <?php else: foreach ($data as $row): ?>
    <tr><?php foreach ($columns as $i => $c) echo '<td class="' . ($c['align']==='right'?'r':'') . '">' . h((string)($row[$i] ?? '')) . '</td>'; ?></tr>
  <?php endforeach; endif; ?>
  </tbody>
  <?php if ($footer): ?>
  <tfoot>
    <tr><?php foreach ($columns as $i => $c) echo '<td class="' . ($c['align']==='right'?'r':'') . '">' . h((string)($footer[$i] ?? '')) . '</td>'; ?></tr>
  </tfoot>
  <?php endif; ?>
</table>

<script>if (location.hash !== '#noPrint') window.onload = () => window.print();</script>
</body>
</html>
<?php exit;
