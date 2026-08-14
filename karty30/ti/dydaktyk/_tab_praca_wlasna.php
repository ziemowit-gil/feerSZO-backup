<?php
/**
 * _tab_praca_wlasna.php — Praca własna prowadzących (Kierownik).
 * Rewrite self_work.php w nowym UI panelu dydaktyka. Tylko dla dyd_is_staff().
 */
$sw_ym = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$sw_prev = date('Y-m', strtotime($sw_ym . '-01 -1 month'));
$sw_next = date('Y-m', strtotime($sw_ym . '-01 +1 month'));
$_sw_msc  = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
             7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$sw_label = ($_sw_msc[(int)substr($sw_ym,5,2)] ?? '') . ' ' . substr($sw_ym,0,4);
$_sw_mon  = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];

$sw_instr_f = (int)($_GET['instructor_id'] ?? 0);

$sw_where  = "s.status='remote_material' AND strftime('%Y-%m', s.lesson_date)=?";
$sw_params = [$sw_ym];
if ($sw_instr_f) { $sw_where .= " AND c.instructor_id=?"; $sw_params[] = $sw_instr_f; }

$sw_rows = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.duration_min, s.topic,
            c.id AS course_id, c.name AS course_name, c.lesson_payout_bb,
            COALESCE(u.ti_is_student, 0) AS is_student,
            COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'')||' '||COALESCE(u.last_name,'')),''), u.name, '—') AS instructor_name
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN users u ON u.id=c.instructor_id
     WHERE $sw_where
     ORDER BY instructor_name COLLATE NOCASE, s.lesson_date, s.time_from",
    $sw_params
);

$sw_instructors = k30_ti_instructors();

// Grupowanie + sumy
$sw_groups = [];
$sw_tot_count = 0; $sw_tot_min = 0; $sw_tot_net = 0.0;
foreach ($sw_rows as $r) {
    $key = $r['instructor_name'];
    $net = ((float)$r['lesson_payout_bb'] > 0)
        ? (float)k30_ti_payout_breakdown((float)$r['lesson_payout_bb'], (bool)$r['is_student'])['netto'] : 0.0;
    $r['_net'] = $net;
    $sw_groups[$key]['rows'][]  = $r;
    $sw_groups[$key]['count']   = ($sw_groups[$key]['count']  ?? 0) + 1;
    $sw_groups[$key]['min']     = ($sw_groups[$key]['min']    ?? 0) + (int)$r['duration_min'];
    $sw_groups[$key]['net']     = ($sw_groups[$key]['net']    ?? 0.0) + $net;
    $sw_tot_count++; $sw_tot_min += (int)$r['duration_min']; $sw_tot_net += $net;
}

$sw_limit = (int)(db_one("SELECT value FROM settings WHERE key_='ti_self_work_month_limit'")['value'] ?? 0);
if ($sw_limit <= 0) $sw_limit = 12;
$sw_over = 0;
foreach ($sw_groups as &$g) { $g['over'] = ((int)$g['count'] > $sw_limit); if ($g['over']) $sw_over++; }
unset($g);

$sw_f  = fn($x) => number_format((float)$x, 2, ',', ' ');
$sw_hh = fn($m) => number_format($m / 60, 2, ',', ' ');

// Budujemy URL parametry dla eksportu
$sw_export_base = array_filter(['m' => $sw_ym, 'instructor_id' => $sw_instr_f ?: null]);
$sw_export_qs   = http_build_query($sw_export_base);
?>

<section aria-label="Praca własna prowadzących" class="dyd-sw-wrap">
<style>
.dyd-sw-wrap { padding: 1.1rem 0 2.5rem; max-width: 900px; }

.dyd-p-banner {
  display: flex; align-items: flex-start; gap: .75rem;
  border-radius: 12px; padding: .75rem 1rem;
  margin-bottom: 1rem; font-size: .875rem; line-height: 1.45;
}
.dyd-p-banner.warn { background: #fff7ed; color: #7c2d12; border: 1px solid #fed7aa; }
.dyd-p-banner.ok   { background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }
[data-bs-theme="dark"] .dyd-p-banner.warn { background: #3c1a00; color: #fdba74; border-color: #92400e; }
[data-bs-theme="dark"] .dyd-p-banner.ok   { background: #052e16; color: #86efac; border-color: #166534; }

/* Nawigacja */
.dyd-sw-nav {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  margin-bottom: .75rem;
}
.dyd-sw-nav-title { font-size: 1rem; font-weight: 700; flex: 1; }
.dyd-sw-nav-sub {
  font-size: .75rem; color: var(--bs-secondary-color);
  text-transform: uppercase; letter-spacing: .05em; margin-top: .1rem;
}

/* Filtr prowadzącego */
.dyd-sw-filters {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  margin-bottom: 1.1rem;
}

/* Kafelki */
.dyd-sw-stats { display: grid; grid-template-columns: repeat(2,1fr); gap: .65rem; margin-bottom: 1.25rem; }
@media (min-width: 640px) { .dyd-sw-stats { grid-template-columns: repeat(4,1fr); } }
.dyd-sw-stat {
  background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
  border-radius: 12px; padding: .9rem 1rem;
}
.dyd-sw-stat-icon { font-size: 1.3rem; margin-bottom: .4rem; opacity: .7; }
.dyd-sw-stat-value { font-size: 1.2rem; font-weight: 700; line-height: 1.2; margin-bottom: .15rem; }
.dyd-sw-stat-label {
  font-size: .72rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; color: var(--bs-secondary-color);
}

/* Karta prowadzącego */
.dyd-sw-card { border: 1px solid var(--bs-border-color); border-radius: 12px; margin-bottom: .75rem; overflow: hidden; }
.dyd-sw-card-head {
  display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
  padding: .75rem 1rem; background: var(--bs-tertiary-bg);
}
.dyd-sw-card-name { font-weight: 700; font-size: .95rem; flex: 1; }
.dyd-sw-card-meta { font-size: .78rem; color: var(--bs-secondary-color); }
.dyd-sw-card-net  { font-weight: 700; font-size: .9rem; color: #16a34a; }
[data-bs-theme="dark"] .dyd-sw-card-net { color: #4ade80; }

/* Wiersze lekcji */
.dyd-sw-lesson {
  display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
  padding: .5rem 1rem; font-size: .83rem;
  border-top: 1px solid var(--bs-border-color);
}
.dyd-sw-lesson:hover { background: rgba(0,0,0,.02); }
[data-bs-theme="dark"] .dyd-sw-lesson:hover { background: rgba(255,255,255,.04); }
.dyd-sw-date { min-width: 44px; font-weight: 600; color: var(--bs-body-color); }
.dyd-sw-time { color: var(--bs-secondary-color); min-width: 36px; }
.dyd-sw-dur  { color: var(--bs-secondary-color); min-width: 50px; font-size: .75rem; }
.dyd-sw-course { flex: 1; color: var(--bs-secondary-color); text-decoration: none; }
.dyd-sw-course:hover { color: #2563eb; }
.dyd-sw-topic { color: var(--bs-secondary-color); font-size: .78rem; flex: 2; }
.dyd-sw-net { font-variant-numeric: tabular-nums; font-weight: 600; color: #16a34a; white-space: nowrap; }
[data-bs-theme="dark"] .dyd-sw-net { color: #4ade80; }

.dyd-sw-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--bs-secondary-color); font-size: .875rem;
}
</style>

<!-- Nawigacja miesiąca -->
<div class="dyd-sw-nav">
  <div>
    <div class="dyd-sw-nav-title">
      <i class="bi bi-person-workspace text-primary me-2" aria-hidden="true"></i><?= h(ucfirst($sw_label)) ?>
    </div>
    <div class="dyd-sw-nav-sub">Praca własna · status „materiał zdalny" · nie liczy się do frekwencji</div>
  </div>
  <div class="d-flex align-items-center gap-1 ms-auto">
    <a href="index.php?tab=praca_wlasna&m=<?= h($sw_prev) ?><?= $sw_instr_f ? '&instructor_id='.$sw_instr_f : '' ?>"
       class="btn btn-outline-secondary btn-sm" aria-label="Poprzedni miesiąc">
      <i class="bi bi-chevron-left" aria-hidden="true"></i>
    </a>
    <form method="get" class="d-flex">
      <input type="hidden" name="tab" value="praca_wlasna">
      <?php if ($sw_instr_f): ?><input type="hidden" name="instructor_id" value="<?= $sw_instr_f ?>"><?php endif; ?>
      <input type="month" name="m" value="<?= h($sw_ym) ?>"
             class="form-control form-control-sm" style="width:auto"
             aria-label="Wybierz miesiąc" onchange="this.form.submit()">
    </form>
    <a href="index.php?tab=praca_wlasna&m=<?= h($sw_next) ?><?= $sw_instr_f ? '&instructor_id='.$sw_instr_f : '' ?>"
       class="btn btn-outline-secondary btn-sm" aria-label="Następny miesiąc">
      <i class="bi bi-chevron-right" aria-hidden="true"></i>
    </a>
  </div>
</div>

<!-- Filtr + eksport -->
<div class="dyd-sw-filters">
  <form method="get" class="d-flex align-items-center gap-2">
    <input type="hidden" name="tab" value="praca_wlasna">
    <input type="hidden" name="m" value="<?= h($sw_ym) ?>">
    <select name="instructor_id" class="form-select form-select-sm" style="min-width:200px"
            aria-label="Filtr prowadzącego" onchange="this.form.submit()">
      <option value="0">Wszyscy prowadzący</option>
      <?php foreach ($sw_instructors as $it): ?>
      <option value="<?= (int)$it['id'] ?>" <?= $sw_instr_f===(int)$it['id']?'selected':'' ?>>
        <?= h($it['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>
  <div class="ms-auto d-flex gap-1">
    <a href="../self_work.php?<?= h($sw_export_qs) ?>&export=csv"
       class="btn btn-outline-secondary btn-sm" title="Pobierz CSV">
      <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>CSV
    </a>
    <a href="../self_work.php?<?= h($sw_export_qs) ?>&export=pdf"
       class="btn btn-outline-danger btn-sm" title="Pobierz PDF">
      <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
    </a>
  </div>
</div>

<?php /* ── Banner ostrzeżenia ── */ ?>
<?php if ($sw_over > 0): ?>
<div class="dyd-p-banner warn" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong><?= $sw_over === 1 ? '1 prowadzący przekracza' : "$sw_over prowadzących przekracza" ?> próg</strong>
    <?= $sw_limit ?> lekcji pracy własnej w miesiącu.
  </div>
</div>
<?php endif; ?>

<!-- Kafelki statystyk -->
<div class="dyd-sw-stats" role="list">
  <div class="dyd-sw-stat" role="listitem">
    <div class="dyd-sw-stat-icon text-secondary"><i class="bi bi-journals" aria-hidden="true"></i></div>
    <div class="dyd-sw-stat-value"><?= $sw_tot_count ?></div>
    <div class="dyd-sw-stat-label">Lekcji</div>
  </div>
  <div class="dyd-sw-stat" role="listitem">
    <div class="dyd-sw-stat-icon text-primary"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
    <div class="dyd-sw-stat-value"><?= $sw_hh($sw_tot_min) ?> h</div>
    <div class="dyd-sw-stat-label">Łączny czas</div>
  </div>
  <div class="dyd-sw-stat" role="listitem">
    <div class="dyd-sw-stat-icon" style="color:#8b5cf6"><i class="bi bi-people" aria-hidden="true"></i></div>
    <div class="dyd-sw-stat-value"><?= count($sw_groups) ?></div>
    <div class="dyd-sw-stat-label">Prowadzących</div>
  </div>
  <div class="dyd-sw-stat" role="listitem">
    <div class="dyd-sw-stat-icon text-success"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
    <div class="dyd-sw-stat-value" style="color:#16a34a"><?= $sw_f($sw_tot_net) ?> zł</div>
    <div class="dyd-sw-stat-label">Netto łącznie</div>
  </div>
</div>

<?php if (!$sw_rows): ?>
<div class="card border-0 shadow-sm">
  <div class="dyd-sw-empty">
    <i class="bi bi-person-workspace d-block mb-2 fs-3" style="opacity:.3" aria-hidden="true"></i>
    Brak lekcji „praca własna" w <?= h($sw_label) ?>.
  </div>
</div>

<?php else: ?>
<?php foreach ($sw_groups as $iname => $g): ?>
<div class="dyd-sw-card">
  <div class="dyd-sw-card-head">
    <i class="bi bi-person-badge text-primary flex-shrink-0" aria-hidden="true"></i>
    <span class="dyd-sw-card-name">
      <?= h($iname) ?>
      <?php if (!empty($g['over'])): ?>
      <span class="badge text-bg-warning ms-1" style="font-size:.68rem"
            title="Powyżej progu <?= $sw_limit ?> lekcji/mies.">
        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>powyżej progu
      </span>
      <?php endif; ?>
    </span>
    <span class="dyd-sw-card-meta">
      <?= (int)$g['count'] ?> lekcji · <?= $sw_hh($g['min']) ?> h
    </span>
    <?php if ($g['net'] > 0): ?>
    <span class="dyd-sw-card-net"><?= $sw_f($g['net']) ?> zł</span>
    <?php endif; ?>
  </div>

  <?php foreach ($g['rows'] as $r):
    $d = new DateTime($r['lesson_date']); ?>
  <div class="dyd-sw-lesson">
    <span class="dyd-sw-date"><?= $d->format('d') ?> <?= $_sw_mon[(int)$d->format('n')] ?></span>
    <span class="dyd-sw-time"><?= h(substr((string)$r['time_from'],0,5)) ?></span>
    <span class="dyd-sw-dur"><?= (int)$r['duration_min'] ?> min</span>
    <a class="dyd-sw-course text-decoration-none" href="../course.php?id=<?= (int)$r['course_id'] ?>">
      <?= h($r['course_name']) ?>
    </a>
    <span class="dyd-sw-topic">
      <?= $r['topic'] ? h($r['topic']) : '<span class="opacity-50">—</span>' ?>
    </span>
    <?php if ($r['_net'] > 0): ?>
    <span class="dyd-sw-net"><?= $sw_f($r['_net']) ?> zł</span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

</section>
