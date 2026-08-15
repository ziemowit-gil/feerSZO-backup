<?php /* ═══════════════════════ TAB: FREKWENCJA GRUP ═══════════════════════
 * Zestawienia, wykresy i eksporty frekwencji per kurs prowadzącego.
 * Wymaga: $course_ids[], $uid (z index.php).
 */ ?>
<?php
$fg_month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $fg_month)) $fg_month = date('Y-m');
$fg_year  = (int)substr($fg_month, 0, 4);
$fg_mnum  = (int)substr($fg_month, 5, 2);

$fg_mon_pl = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
              7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
$fg_mon_s  = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
$fg_dow_s  = ['Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'Sb','Sun'=>'Nd'];

// Miesiące do wyboru (bieżący + 5 wstecz)
$fg_month_opts = [];
for ($mi = 0; $mi < 6; $mi++) {
    $ym = date('Y-m', strtotime("first day of -$mi months"));
    $n  = (int)substr($ym, 5, 2);
    $fg_month_opts[] = ['ym' => $ym, 'label' => ($fg_mon_pl[$n] ?? '') . ' ' . substr($ym, 0, 4)];
}

if (!$course_ids) {
    echo '<div class="alert alert-info small px-3 py-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak przypisanych grup.</div>';
    return;
}
$fg_ph = implode(',', array_fill(0, count($course_ids), '?'));

// ── Zestawienie per kurs — wybrany miesiąc ───────────────────────────────
$fg_summary = db_all(
    "SELECT c.id AS course_id, c.name AS course_name,
            COUNT(DISTINCT s.id) AS lessons,
            SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=1 THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=0 THEN 1 ELSE 0 END) AS absent,
            COUNT(DISTINCT e.client_id) AS enrolled
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN k30_ti_attendance a ON a.session_id=s.id
     LEFT JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.status='active'
     WHERE s.course_id IN ($fg_ph)
       AND s.status IN ('held','individual_change')
       AND COALESCE(c.track_attendance,1)=1
       AND strftime('%Y-%m', s.lesson_date)=?
     GROUP BY c.id ORDER BY c.name COLLATE NOCASE",
    array_merge($course_ids, [$fg_month])
);

// ── Trend 6 miesięcy — agregat ──────────────────────────────────────────
$fg_trend = db_all(
    "SELECT strftime('%Y-%m', s.lesson_date) AS ym,
            SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=1 THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=0 THEN 1 ELSE 0 END) AS absent
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN k30_ti_attendance a ON a.session_id=s.id
     WHERE s.course_id IN ($fg_ph)
       AND s.status IN ('held','individual_change')
       AND COALESCE(c.track_attendance,1)=1
       AND s.lesson_date >= date('now','-6 months','localtime')
     GROUP BY ym ORDER BY ym",
    $course_ids
);

// ── Szczegóły per kurs: sesje + frekwencja (tylko bieżący miesiąc) ───────
$fg_details = [];
foreach ($course_ids as $cid) {
    $sessions_c = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.topic
         FROM k30_ti_sessions s
         WHERE s.course_id=? AND s.status IN ('held','individual_change')
           AND strftime('%Y-%m', s.lesson_date)=?
         ORDER BY s.lesson_date, s.time_from",
        [(int)$cid, $fg_month]
    );
    if (!$sessions_c) continue;
    $students_c = db_all(
        "SELECT cl.id, cl.name FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active'
         ORDER BY cl.name COLLATE NOCASE",
        [(int)$cid]
    );
    $att_map = [];
    $att_rows = db_all(
        "SELECT a.client_id, a.session_id,
                COALESCE(a.attended,0) AS attended,
                COALESCE(a.cancelled,0) AS cancelled,
                COALESCE(a.no_show,0) AS no_show
         FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id
         WHERE s.course_id=? AND s.status IN ('held','individual_change')
           AND strftime('%Y-%m', s.lesson_date)=?",
        [(int)$cid, $fg_month]
    );
    foreach ($att_rows as $ar) $att_map[$ar['session_id']][$ar['client_id']] = $ar;
    $fg_details[$cid] = ['sessions' => $sessions_c, 'students' => $students_c, 'att' => $att_map];
}
?>

<style>
.fg-card   { background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:10px; margin-bottom:.75rem; overflow:hidden; }
.fg-card-hdr { padding:.6rem 1rem; background:var(--bs-tertiary-bg); display:flex; align-items:center; gap:.5rem; font-weight:600; font-size:.88rem; cursor:pointer; }
.fg-card-hdr:hover { background:var(--bs-secondary-bg); }
.fg-card-body { padding:.75rem 1rem; }

.fg-matrix { border-collapse:collapse; font-size:.78rem; }
.fg-matrix th, .fg-matrix td { padding:3px 6px; border:1px solid var(--bs-border-color); text-align:center; white-space:nowrap; }
.fg-matrix .fg-m-name { text-align:left; font-weight:600; white-space:nowrap; min-width:110px; position:sticky; left:0; background:var(--bs-body-bg); z-index:1; border-right:2px solid var(--bs-border-color); }
.fg-matrix thead th { background:var(--bs-tertiary-bg); font-weight:600; font-size:.72rem; }
.fg-matrix .fg-ok   { background:#d1fae5; color:#065f46; }
.fg-matrix .fg-bad  { background:#fee2e2; color:#991b1b; }
.fg-matrix .fg-canc { background:var(--bs-secondary-bg); color:var(--bs-secondary-color); }
.fg-matrix .fg-pct  { font-weight:700; }

.fg-pct-bar { display:inline-block; width:44px; height:5px; border-radius:3px; background:rgba(100,116,139,.18); vertical-align:middle; margin-right:4px; }
.fg-pct-fill { display:block; height:5px; border-radius:3px; }
</style>

<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <h2 class="mb-0 fw-bold" style="font-size:1.05rem"><i class="bi bi-bar-chart-steps me-2 text-primary" aria-hidden="true"></i>Frekwencja grup</h2>

  <!-- Selektor miesiąca -->
  <div class="dropdown ms-auto">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?= h(($fg_mon_pl[$fg_mnum] ?? '') . ' ' . $fg_year) ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <?php foreach ($fg_month_opts as $mo): ?>
      <li><a class="dropdown-item <?= $mo['ym'] === $fg_month ? 'active' : '' ?>"
             href="index.php?tab=frekwencja_grup&month=<?= h($mo['ym']) ?>"><?= h($mo['label']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Eksport CSV -->
  <a href="attendance_csv.php?month=<?= h($fg_month) ?>" class="btn btn-outline-success btn-sm"
     title="Pobierz CSV z frekwencją wszystkich grup za wybrany miesiąc">
    <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Eksport CSV
  </a>
</div>

<?php if (!$fg_summary): ?>
<div class="text-center py-5 text-body-secondary">
  <i class="bi bi-calendar-x d-block mb-2 fs-2" aria-hidden="true"></i>
  Brak lekcji (ze śledzeniem frekwencji) w <?= h(strtolower($fg_mon_pl[$fg_mnum] ?? $fg_month)) ?> <?= $fg_year ?>.
</div>
<?php else: ?>

<!-- ── Zestawienie zbiorcze ────────────────────────────────────────── -->
<section class="mb-4" aria-label="Zestawienie frekwencji za <?= h($fg_mon_pl[$fg_mnum] ?? '') ?>">
  <div class="fw-semibold small text-body-secondary text-uppercase mb-2" style="letter-spacing:.06em">
    Zestawienie — <?= h(strtolower($fg_mon_pl[$fg_mnum] ?? $fg_month)) ?> <?= $fg_year ?>
  </div>
  <div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
    <div class="table-responsive">
      <table class="table align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light">
          <tr>
            <th>Kurs / grupa</th>
            <th class="text-end">Lekcji</th>
            <th class="text-end">Kursantów</th>
            <th class="text-end text-success">Obecnych</th>
            <th class="text-end text-danger">Nieobecnych</th>
            <th class="text-end">Frekwencja</th>
            <th>PDF</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fg_summary as $fg_r):
            $fg_tot = (int)$fg_r['present'] + (int)$fg_r['absent'];
            $fg_pct = $fg_tot > 0 ? round((int)$fg_r['present'] / $fg_tot * 100) : null;
            $fg_pc  = $fg_pct === null ? '' : ($fg_pct >= 80 ? 'text-success' : ($fg_pct >= 60 ? 'text-warning' : 'text-danger'));
            $fg_fillc = $fg_pct === null ? '#94a3b8' : ($fg_pct >= 80 ? '#22c55e' : ($fg_pct >= 60 ? '#f59e0b' : '#ef4444'));
          ?>
          <tr>
            <td class="fw-semibold"><?= h($fg_r['course_name']) ?></td>
            <td class="text-end text-body-secondary"><?= (int)$fg_r['lessons'] ?></td>
            <td class="text-end text-body-secondary"><?= (int)$fg_r['enrolled'] ?></td>
            <td class="text-end fw-semibold text-success"><?= (int)$fg_r['present'] ?></td>
            <td class="text-end <?= (int)$fg_r['absent'] > 0 ? 'fw-semibold text-danger' : 'text-body-secondary' ?>"><?= (int)$fg_r['absent'] ?></td>
            <td class="text-end">
              <?php if ($fg_pct !== null): ?>
              <span class="<?= $fg_pc ?>">
                <span class="fg-pct-bar" aria-hidden="true"><span class="fg-pct-fill" style="width:<?= $fg_pct ?>%;background:<?= $fg_fillc ?>"></span></span>
                <?= $fg_pct ?>%
              </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <a href="attendance_monthly.php?course_id=<?= (int)$fg_r['course_id'] ?>&month=<?= h($fg_month) ?>"
                 class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size:.72rem" target="_blank" rel="noopener"
                 title="Raport PDF frekwencji za <?= h($fg_month) ?>">
                <i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>PDF
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php endif; /* $fg_summary */ ?>

<!-- ── Wykres trendu 6 miesięcy ────────────────────────────────────── -->
<?php if (count($fg_trend) > 1):
    $ftn = count($fg_trend);
    $ftW = 340; $ftH = 140;
    $ftpL = 30; $ftpR = 8; $ftpT = 14; $ftpB = 24;
    $ftaW = $ftW - $ftpL - $ftpR;
    $ftaH = $ftH - $ftpT - $ftpB;
    $ftBGap = max(3, ($ftaW / $ftn) * 0.15);
    $ftBW   = max(8, ($ftaW - $ftBGap * ($ftn + 1)) / $ftn);
?>
<section class="mb-4" aria-label="Trend frekwencji ostatnie 6 miesięcy">
  <div class="fw-semibold small text-body-secondary text-uppercase mb-2" style="letter-spacing:.06em">
    Trend — ostatnie 6 miesięcy (wszystkie grupy)
  </div>
  <div class="card border-0 shadow-sm p-3" style="border-radius:10px">
    <svg viewBox="0 0 <?= $ftW ?> <?= $ftH ?>" aria-hidden="true"
         class="w-100 d-block" style="max-height:140px">
      <?php foreach ([0, 50, 100] as $ftg): ?>
      <?php $ftgY = $ftpT + $ftaH - ($ftg * $ftaH / 100); ?>
      <line x1="<?= $ftpL ?>" y1="<?= number_format($ftgY,1) ?>" x2="<?= $ftW - $ftpR ?>" y2="<?= number_format($ftgY,1) ?>"
            stroke="currentColor" stroke-opacity="<?= $ftg === 50 ? '.1' : '.2' ?>" stroke-dasharray="<?= $ftg === 50 ? '3,3' : '0' ?>"/>
      <text x="<?= $ftpL - 3 ?>" y="<?= number_format($ftgY + 3.5, 1) ?>"
            text-anchor="end" font-size="8.5" fill="currentColor" opacity=".5"><?= $ftg ?>%</text>
      <?php endforeach; ?>
      <?php $fti = 0; foreach ($fg_trend as $ftr): ?>
      <?php
          $fttot = (int)$ftr['present'] + (int)$ftr['absent'];
          $ftpct = $fttot > 0 ? round((int)$ftr['present'] / $fttot * 100) : null;
          $ftbh  = ($ftpct !== null) ? max($ftpct * $ftaH / 100, $ftpct > 0 ? 2 : 0) : 0;
          $ftbx  = $ftpL + $ftBGap + $fti * ($ftBW + $ftBGap);
          $ftby  = $ftpT + $ftaH - $ftbh;
          $ftbc  = $ftpct === null ? '#94a3b8' : ($ftpct >= 80 ? '#22c55e' : ($ftpct >= 60 ? '#f59e0b' : '#ef4444'));
          $ftlx  = $ftbx + $ftBW / 2;
          $ftyp  = explode('-', $ftr['ym']);
          $ftml  = ($fg_mon_s[(int)$ftyp[1]] ?? '') . ' \'' . substr($ftyp[0], 2);
          $ftcur = $ftr['ym'] === $fg_month;
      ?>
      <?php if ($ftbh > 0): ?>
      <rect x="<?= number_format($ftbx,1) ?>" y="<?= number_format($ftby,1) ?>"
            width="<?= number_format($ftBW,1) ?>" height="<?= number_format($ftbh,1) ?>"
            fill="<?= $ftbc ?>" opacity="<?= $ftcur ? '1' : '.75' ?>" rx="2"/>
      <?php endif; ?>
      <?php if ($ftcur): ?><rect x="<?= number_format($ftbx - 1, 1) ?>" y="<?= $ftpT + $ftaH + 1 ?>" width="<?= number_format($ftBW + 2, 1) ?>" height="2" fill="<?= $ftbc ?>" rx="1"/><?php endif; ?>
      <text x="<?= number_format($ftlx,1) ?>" y="<?= number_format($ftby - 3, 1) ?>"
            text-anchor="middle" font-size="9" font-weight="600"
            fill="<?= $ftbc ?>"><?= $ftpct !== null ? $ftpct . '%' : '—' ?></text>
      <text x="<?= number_format($ftlx,1) ?>" y="<?= $ftH - $ftpB + 13 ?>"
            text-anchor="middle" font-size="8" fill="currentColor" opacity="<?= $ftcur ? '.9' : '.6' ?>"
            font-weight="<?= $ftcur ? '700' : '400' ?>"><?= h($ftml) ?></text>
      <?php $fti++; endforeach; ?>
    </svg>
    <!-- Screen reader table -->
    <table class="visually-hidden">
      <caption>Trend frekwencji per miesiąc</caption>
      <thead><tr><th scope="col">Miesiąc</th><th scope="col">Obecności</th><th scope="col">Nieobecności</th><th scope="col">Frekwencja</th></tr></thead>
      <tbody>
        <?php foreach ($fg_trend as $ftr):
          $fttot2 = (int)$ftr['present'] + (int)$ftr['absent'];
          $ftpct2 = $fttot2 > 0 ? round((int)$ftr['present'] / $fttot2 * 100) : null;
          $ftyp2  = explode('-', $ftr['ym']);
          $ftml2  = ($fg_mon_s[(int)$ftyp2[1]] ?? '') . ' ' . $ftyp2[0]; ?>
        <tr><th scope="row"><?= h($ftml2) ?></th><td><?= (int)$ftr['present'] ?></td><td><?= (int)$ftr['absent'] ?></td><td><?= $ftpct2 !== null ? $ftpct2 . '%' : '—' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- ── Szczegóły per kurs — macierz obecności ──────────────────────── -->
<?php if ($fg_details): ?>
<section aria-label="Szczegółowe listy obecności per kurs">
  <div class="fw-semibold small text-body-secondary text-uppercase mb-2" style="letter-spacing:.06em">
    Listy obecności — <?= h(strtolower($fg_mon_pl[$fg_mnum] ?? $fg_month)) ?> <?= $fg_year ?>
  </div>
  <?php foreach ($fg_details as $cid => $fd):
    $cname = '';
    foreach ($fg_summary as $fgs) { if ((int)$fgs['course_id'] === (int)$cid) { $cname = $fgs['course_name']; break; } }
    if (!$cname) foreach ($courses as $cobj) { if ((int)$cobj['id'] === (int)$cid) { $cname = $cobj['name']; break; } }
    $fg_did = 'fg-det-' . (int)$cid;
    $fg_sessions = $fd['sessions'];
    $fg_students = $fd['students'];
    $fg_att      = $fd['att'];
  ?>
  <div class="fg-card">
    <div class="fg-card-hdr" data-bs-toggle="collapse" data-bs-target="#<?= $fg_did ?>"
         aria-expanded="false" aria-controls="<?= $fg_did ?>">
      <i class="bi bi-chevron-right text-body-secondary" style="transition:transform .2s;font-size:.8rem" aria-hidden="true"></i>
      <i class="bi bi-pc-display text-primary" aria-hidden="true"></i>
      <span class="flex-grow-1"><?= h($cname) ?></span>
      <span class="badge bg-secondary-subtle text-secondary fw-normal" style="font-size:.72rem"><?= count($fg_sessions) ?> lekcji · <?= count($fg_students) ?> kursantów</span>
      <a href="attendance_monthly.php?course_id=<?= (int)$cid ?>&month=<?= h($fg_month) ?>"
         class="btn btn-xs btn-outline-secondary py-0 px-2 ms-2" style="font-size:.72rem"
         target="_blank" rel="noopener" onclick="event.stopPropagation()"
         title="Raport PDF">
        <i class="bi bi-filetype-pdf" aria-hidden="true"></i>
      </a>
      <a href="attendance_csv.php?month=<?= h($fg_month) ?>&course_id=<?= (int)$cid ?>"
         class="btn btn-xs btn-outline-success py-0 px-2" style="font-size:.72rem"
         onclick="event.stopPropagation()" title="Eksport CSV tej grupy">
        <i class="bi bi-filetype-csv" aria-hidden="true"></i>
      </a>
    </div>
    <div class="collapse" id="<?= $fg_did ?>">
      <div class="fg-card-body">
        <?php if (!$fg_students): ?>
        <p class="text-body-secondary small mb-0">Brak aktywnych kursantów.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="fg-matrix" aria-label="Macierz obecności — <?= h($cname) ?>">
            <thead>
              <tr>
                <th class="fg-m-name text-start">Kursant</th>
                <?php foreach ($fg_sessions as $fgs): ?>
                <?php
                  $fsd  = new DateTime($fgs['lesson_date']);
                  $dow  = $fg_dow_s[$fsd->format('D')] ?? '';
                  $ddmm = $fsd->format('d.m');
                ?>
                <th title="<?= h($fgs['topic'] ?: $ddmm) ?>">
                  <div style="font-size:.7rem"><?= $dow ?></div>
                  <div><?= $ddmm ?></div>
                </th>
                <?php endforeach; ?>
                <th title="Obecności / lekcji">Razem</th>
                <th title="Frekwencja">%</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fg_students as $fgst):
                $spresent = 0; $stotal = 0;
              ?>
              <tr>
                <td class="fg-m-name"><?= h($fgst['name']) ?></td>
                <?php foreach ($fg_sessions as $fgs):
                  $a = $fg_att[$fgs['id']][$fgst['id']] ?? null;
                  $stotal++;
                  if ($a === null):
                ?>
                <td class="fg-canc" title="Brak wpisu">·</td>
                <?php elseif ((int)$a['cancelled']): ?>
                <td class="fg-canc" title="Odwołano udział">odo</td>
                <?php elseif ((int)$a['attended']):
                  $spresent++;
                ?>
                <td class="fg-ok" title="Obecny/a"><i class="bi bi-check-lg" aria-hidden="true"></i></td>
                <?php else: ?>
                <td class="fg-bad" title="Nieobecny/a"><i class="bi bi-x-lg" aria-hidden="true"></i></td>
                <?php endif; ?>
                <?php endforeach; ?>
                <td class="fg-pct"><?= $spresent ?>/<?= $stotal ?></td>
                <?php $spct = $stotal > 0 ? round($spresent / $stotal * 100) : 0; ?>
                <td class="fg-pct <?= $spct >= 80 ? 'text-success' : ($spct >= 60 ? 'text-warning' : 'text-danger') ?>"><?= $spct ?>%</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- ── Eksporty PDF ─────────────────────────────────────────────────── -->
<section class="mt-4" aria-label="Eksporty i raporty">
  <div class="fw-semibold small text-body-secondary text-uppercase mb-2" style="letter-spacing:.06em">
    Raporty PDF
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($fg_summary as $fg_r): ?>
    <div class="d-flex align-items-center gap-1 border rounded px-3 py-2" style="font-size:.83rem">
      <i class="bi bi-pc-display text-primary me-1" aria-hidden="true"></i>
      <span class="fw-semibold"><?= h($fg_r['course_name']) ?></span>
      <a href="attendance_monthly.php?course_id=<?= (int)$fg_r['course_id'] ?>&month=<?= h($fg_month) ?>"
         class="btn btn-xs btn-outline-secondary py-0 px-2 ms-1" target="_blank" rel="noopener"
         style="font-size:.72rem">
        <i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>Miesięczny
      </a>
      <a href="attendance_pdf.php?course_id=<?= (int)$fg_r['course_id'] ?>"
         class="btn btn-xs btn-outline-secondary py-0 px-2" target="_blank" rel="noopener"
         style="font-size:.72rem">
        <i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>Cały kurs
      </a>
    </div>
    <?php endforeach; ?>
    <?php if (!$fg_summary): ?>
    <span class="text-body-secondary small">Brak danych dla tego miesiąca.</span>
    <?php endif; ?>
  </div>
</section>

<script>
// Obracaj ikonkę chevron przy expand/collapse
document.querySelectorAll('.fg-card-hdr').forEach(function(hdr) {
  var ic = hdr.querySelector('.bi-chevron-right');
  var target = document.querySelector(hdr.getAttribute('data-bs-target'));
  if (!target || !ic) return;
  target.addEventListener('show.bs.collapse', function() { ic.style.transform = 'rotate(90deg)'; });
  target.addEventListener('hide.bs.collapse', function() { ic.style.transform = ''; });
});
</script>
