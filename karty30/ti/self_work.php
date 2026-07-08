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
if ($instr_f) { $where .= " AND c.instructor_id=?"; $params[] = $instr_f; }

$rows = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.topic,
            c.id AS course_id, c.name AS course_name, c.lesson_payout_bb,
            c.instructor_id,
            COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'')||' '||COALESCE(u.last_name,'')),''), u.name, '—') AS instructor_name
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN users u ON u.id=c.instructor_id
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
        $net = ((float)$r['lesson_payout_bb'] > 0) ? k30_ti_payout_breakdown((float)$r['lesson_payout_bb'])['netto'] : 0;
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
    $net = ((float)$r['lesson_payout_bb'] > 0) ? (float)k30_ti_payout_breakdown((float)$r['lesson_payout_bb'])['netto'] : 0.0;
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

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
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
  <div class="col-auto">
    <a href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Eksport CSV</a>
  </div>
</form>

<div class="text-body-secondary mb-3" style="font-size:.88rem">
  <i class="bi bi-info-circle me-1"></i><?= h(ucfirst($ym_label)) ?> · lekcje „praca własna prowadzącego (materiał zdalny)". Ten status <strong>nie liczy się do frekwencji</strong>, ale jest lekcją odbytą do wypłaty.
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
<div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>Brak lekcji „praca własna prowadzącego" w tym miesiącu.</div>
<?php else: ?>
<?php foreach ($groups as $iname => $g): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-person-badge me-2 text-primary"></i><?= h($iname) ?></span>
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
