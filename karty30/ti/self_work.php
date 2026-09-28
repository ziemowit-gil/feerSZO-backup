<?php
/**
 * karty30/ti/self_work.php — Raport „Praca własna prowadzącego" (TI).
 * Zestawienie lekcji o statusie remote_material (materiał zdalny / praca własna)
 * w danym miesiącu, grupowane po prowadzącym. Praca własna NIE liczy się do
 * frekwencji, ale jest lekcją odbytą do wypłaty — dlatego pokazujemy też kwotę.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write = can_write('karty30') || is_admin();
if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }

$PAGE_TITLE = 'Praca własna prowadzących — TI';

$ym = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$prev = date('Y-m', strtotime($ym . '-01 -1 month'));
$next = date('Y-m', strtotime($ym . '-01 +1 month'));
$_msc = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$ym_label = ($_msc[(int)substr($ym,5,2)] ?? '') . ' ' . substr($ym,0,4);

$instr_f = (int)($_GET['instructor_id'] ?? 0);

$where  = "s.status='remote_material' AND strftime('%Y-%m', s.lesson_date)=?";
$params = [$ym];
if ($instr_f) { $where .= " AND COALESCE(s.instructor_id, c.instructor_id)=?"; $params[] = $instr_f; }

$rows = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.topic,
            c.id AS course_id, c.name AS course_name, c.lesson_payout_bb,
            COALESCE(s.instructor_id, c.instructor_id) AS instructor_id, COALESCE(s.self_prep_remote,0) AS self_prep_remote,
            COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS payout_form,
            COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'')||' '||COALESCE(u.last_name,'')),''), u.name, '—') AS instructor_name
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN users u ON u.id=COALESCE(s.instructor_id, c.instructor_id)
     WHERE $where
     ORDER BY instructor_name COLLATE NOCASE, s.lesson_date, s.time_from",
    $params
);

// Lista prowadzących do filtra
$instructors = k30_ti_instructors();

// Eksport CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="praca_wlasna_' . $ym . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Prowadzący','Data','Godzina','Czas (min)','Kurs','Temat','Wypłata netto (zł)'], ';');
    foreach ($rows as $r) {
        $net = ((float)$r['lesson_payout_bb'] > 0) ? k30_ti_payout_breakdown((float)$r['lesson_payout_bb'], in_array($r['payout_form'] ?? 'zlecenie', ['student','b2b'], true) || !empty($r['self_prep_remote']))['netto'] : 0;
        fputcsv($out, [
            $r['instructor_name'], $r['lesson_date'], substr((string)$r['time_from'],0,5),
            (int)$r['duration_min'], $r['course_name'], $r['topic'],
            number_format((float)$net, 2, ',', ''),
        ], ';');
    }
    fclose($out); exit;
}

// Grupowanie po prowadzącym + sumy
$groups = [];
$tot_count = 0; $tot_min = 0; $tot_net = 0.0;
foreach ($rows as $r) {
    $key = $r['instructor_name'];
    $net = ((float)$r['lesson_payout_bb'] > 0) ? (float)k30_ti_payout_breakdown((float)$r['lesson_payout_bb'], in_array($r['payout_form'] ?? 'zlecenie', ['student','b2b'], true) || !empty($r['self_prep_remote']))['netto'] : 0.0;
    $r['_net'] = $net;
    $groups[$key]['rows'][] = $r;
    $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
    $groups[$key]['min']   = ($groups[$key]['min'] ?? 0) + (int)$r['duration_min'];
    $groups[$key]['net']   = ($groups[$key]['net'] ?? 0) + $net;
    $tot_count++; $tot_min += (int)$r['duration_min']; $tot_net += $net;
}
$f  = fn($x) => number_format((float)$x, 2, ',', ' ');
$hh = fn($m) => number_format($m / 60, 2, ',', ' ');
$_mon = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];

// Próg ostrzeżenia (nadużycia pracy własnej) — konfigurowalny; domyślnie 12 lekcji/mies.
$sw_limit = (int)(db_one("SELECT value FROM settings WHERE key_='ti_self_work_month_limit'")['value'] ?? 0);
if ($sw_limit <= 0) $sw_limit = 12;
$over_instructors = 0;
foreach ($groups as &$g) { $g['over'] = ((int)$g['count'] > $sw_limit); if ($g['over']) $over_instructors++; }
unset($g);

// ── Eksport PDF ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {
    require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
    $FD = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
    $pl = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
    try {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetMargins(12, 12, 12);
        $pdf->AddPage();
        $W = $pdf->GetPageWidth() - 24;
        $org = defined('ORG_NAME') ? ORG_NAME : '';

        $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell($W, 9, $pl('Praca własna prowadzących — ' . ucfirst($ym_label)), 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($W, 5, $pl(($org ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i')
            . '   ·   Lekcji: ' . $tot_count . ' · ' . $hh($tot_min) . ' h · netto ' . $f($tot_net) . ' zł'), 0, 1);
        $pdf->Ln(2);

        foreach ($groups as $iname => $g) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
            $pdf->SetFillColor(233, 238, 245); $pdf->SetFont('Helvetica', 'B', 10);
            $head = $iname . '   (' . (int)$g['count'] . ' lekcji · ' . $hh($g['min']) . ' h'
                  . ($g['net'] > 0 ? ' · netto ' . $f($g['net']) . ' zł' : '') . ')'
                  . ($g['over'] ? '   ⚠ powyżej progu ' . $sw_limit : '');
            $pdf->Cell($W, 7, $pl($head), 0, 1, 'L', true);
            $pdf->SetFont('Helvetica', '', 8.5);
            foreach ($g['rows'] as $r) {
                $d = new DateTime($r['lesson_date']);
                $line = $d->format('d.m.Y') . '  ' . substr((string)$r['time_from'], 0, 5)
                      . '  ·  ' . (int)$r['duration_min'] . ' min  ·  ' . $r['course_name']
                      . ($r['topic'] ? '  — ' . $r['topic'] : '')
                      . ($r['_net'] > 0 ? '   (' . $f($r['_net']) . ' zł)' : '');
                $pdf->Cell(4); $pdf->MultiCell($W - 4, 5, $pl($line), 0, 'L');
            }
            $pdf->Ln(2);
        }
        if (!$groups) { $pdf->SetFont('Helvetica', '', 10); $pdf->Cell($W, 8, $pl('Brak lekcji „praca własna" w tym miesiącu.'), 0, 1); }

        while (ob_get_level() > 0) ob_end_clean();
        $pdf->Output('D', 'praca_wlasna_' . $ym . '.pdf');
        exit;
    } catch (\Throwable $e) {
        error_log('[self_work pdf] ' . $ym . ': ' . $e->getMessage());
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
        echo "Nie udało się wygenerować PDF.\nPowód: " . $e->getMessage() . "\n"; exit;
    }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Praca własna prowadzących</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-person-workspace text-primary me-2"></i>Praca własna prowadzących</h4>
  <form method="get" class="ms-auto d-flex align-items-center gap-1 flex-wrap">
    <?php if ($instr_f): ?><input type="hidden" name="instructor_id" value="<?= $instr_f ?>"><?php endif; ?>
    <a href="?m=<?= h($prev) ?><?= $instr_f ? '&instructor_id='.$instr_f : '' ?>" class="btn btn-outline-secondary btn-sm" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left"></i></a>
    <input type="month" name="m" value="<?= h($ym) ?>" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
    <a href="?m=<?= h($next) ?><?= $instr_f ? '&instructor_id='.$instr_f : '' ?>" class="btn btn-outline-secondary btn-sm" aria-label="Następny miesiąc"><i class="bi bi-chevron-right"></i></a>
  </form>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="m" value="<?= h($ym) ?>">
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Prowadzący</label>
    <select name="instructor_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:220px">
      <option value="0">Wszyscy prowadzący</option>
      <?php foreach ($instructors as $it): ?>
      <option value="<?= (int)$it['id'] ?>" <?= $instr_f===(int)$it['id']?'selected':'' ?>><?= h($it['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto d-flex gap-1">
    <a href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
    <a href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'pdf']))) ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
  </div>
</form>

<div class="text-body-secondary mb-3" style="font-size:.88rem">
  <i class="bi bi-info-circle me-1"></i><?= h(ucfirst($ym_label)) ?> · lekcje „praca prowadzącego (materiał zdalny)". Ten status <strong>nie liczy się do frekwencji</strong>, ale jest lekcją odbytą do wypłaty.
  <?php if ($over_instructors): ?>
  <span class="text-warning-emphasis ms-1"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= $over_instructors ?> prowadzących powyżej progu <?= $sw_limit ?> lekcji/mies.</span>
  <?php endif; ?>
</div>

<!-- Karty podsumowania -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
    <div class="text-body-secondary small">Lekcje pracy własnej</div><div class="fs-4 fw-bold"><?= $tot_count ?></div>
  </div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
    <div class="text-body-secondary small">Łączny czas</div><div class="fs-4 fw-bold"><?= $hh($tot_min) ?> h</div>
  </div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
    <div class="text-body-secondary small">Prowadzących</div><div class="fs-4 fw-bold"><?= count($groups) ?></div>
  </div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
    <div class="text-body-secondary small">Wypłata netto (suma)</div><div class="fs-4 fw-bold text-success"><?= $f($tot_net) ?> zł</div>
  </div></div></div>
</div>

<?php if (!$rows): ?>
<div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>Brak lekcji „praca prowadzącego" w tym miesiącu.</div>
<?php else: ?>
<?php foreach ($groups as $iname => $g): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-person-badge me-2 text-primary"></i><?= h($iname) ?>
      <?php if (!empty($g['over'])): ?>
      <span class="badge text-bg-warning ms-1" title="Dużo pracy własnej w tym miesiącu (próg: <?= $sw_limit ?>)"><i class="bi bi-exclamation-triangle me-1"></i>powyżej progu</span>
      <?php endif; ?>
    </span>
    <span class="small text-body-secondary">
      <?= (int)$g['count'] ?> lekcji · <?= $hh($g['min']) ?> h<?= $g['net'] > 0 ? ' · netto ' . $f($g['net']) . ' zł' : '' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Data</th><th>Godz.</th><th class="text-end">Czas</th><th>Kurs</th><th>Temat</th><th class="text-end">Netto</th>
      </tr></thead>
      <tbody>
        <?php foreach ($g['rows'] as $r): $d = new DateTime($r['lesson_date']); ?>
        <tr>
          <td class="text-nowrap small"><?= $d->format('d') ?> <?= $_mon[(int)$d->format('n')] ?></td>
          <td class="small text-body-secondary"><?= h(substr((string)$r['time_from'],0,5)) ?></td>
          <td class="text-end small"><?= (int)$r['duration_min'] ?> min</td>
          <td class="small"><a href="course.php?id=<?= (int)$r['course_id'] ?>" class="text-decoration-none"><?= h($r['course_name']) ?></a></td>
          <td class="small"><?= $r['topic'] ? h($r['topic']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="text-end small"><?= $r['_net'] > 0 ? $f($r['_net']) . ' zł' : '<span class="text-body-secondary">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
