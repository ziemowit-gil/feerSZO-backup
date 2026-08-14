<?php
/**
 * _tab_wypłaty.php — Miesięczne wypłaty prowadzących (Kierownik).
 * Rewrite payouts.php w nowym UI panelu dydaktyka. Tylko dla dyd_is_staff().
 */
$wy_ym = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$wy_prev  = date('Y-m', strtotime($wy_ym . '-01 -1 month'));
$wy_next  = date('Y-m', strtotime($wy_ym . '-01 +1 month'));
$_wy_msc  = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
             7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$wy_label = ($_wy_msc[(int)substr($wy_ym,5,2)] ?? '') . ' ' . substr($wy_ym,0,4);

$wy_rows = k30_ti_payouts_by_instructor($wy_ym);
$wy_tot  = _k30_ti_payout_zero();
foreach ($wy_rows as $r) {
    foreach (['lessons','brutto_brutto','zus_employer','brutto','skladki','pit','netto'] as $k) {
        $wy_tot[$k] += $r[$k];
    }
}
$wy_f = fn($x) => number_format((float)$x, 2, ',', ' ');
?>

<section aria-label="Wypłaty prowadzących" class="dyd-wy-wrap">
<style>
.dyd-wy-wrap { padding: 1.1rem 0 2.5rem; max-width: 900px; }

.dyd-p-banner {
  display: flex; align-items: flex-start; gap: .75rem;
  border-radius: 12px; padding: .75rem 1rem;
  margin-bottom: 1rem; font-size: .875rem; line-height: 1.45;
}
.dyd-p-banner.info { background: #eff6ff; color: #1e3a5f; border: 1px solid #bfdbfe; }
[data-bs-theme="dark"] .dyd-p-banner.info { background: #0c1f3a; color: #93c5fd; border-color: #1d4ed8; }

/* Nawigacja miesiąca */
.dyd-wy-nav {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  margin-bottom: 1.1rem;
}
.dyd-wy-nav-title {
  font-size: 1rem; font-weight: 700; flex: 1;
}
.dyd-wy-nav-sub {
  font-size: .75rem; color: var(--bs-secondary-color);
  text-transform: uppercase; letter-spacing: .05em;
  margin-top: .1rem;
}

/* Kafelki */
.dyd-wy-stats { display: grid; grid-template-columns: repeat(2,1fr); gap: .65rem; margin-bottom: 1.25rem; }
@media (min-width: 640px) { .dyd-wy-stats { grid-template-columns: repeat(4,1fr); } }
.dyd-wy-stat {
  background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
  border-radius: 12px; padding: .9rem 1rem;
}
.dyd-wy-stat-icon { font-size: 1.3rem; margin-bottom: .4rem; opacity: .7; }
.dyd-wy-stat-value { font-size: 1.2rem; font-weight: 700; line-height: 1.2; margin-bottom: .15rem; }
.dyd-wy-stat-label {
  font-size: .72rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; color: var(--bs-secondary-color);
}

/* Karta prowadzącego */
.dyd-wy-instr { border: 1px solid var(--bs-border-color); border-radius: 12px; margin-bottom: .75rem; overflow: hidden; }
.dyd-wy-instr-head {
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
  padding: .8rem 1rem; background: var(--bs-tertiary-bg);
  cursor: pointer; user-select: none;
}
.dyd-wy-instr-name { font-weight: 700; font-size: .95rem; flex: 1; }
.dyd-wy-instr-meta { font-size: .78rem; color: var(--bs-secondary-color); font-variant-numeric: tabular-nums; }
.dyd-wy-instr-netto { font-weight: 700; font-size: .95rem; color: #16a34a; }
[data-bs-theme="dark"] .dyd-wy-instr-netto { color: #4ade80; }
.dyd-wy-instr-chevron { transition: transform .18s; color: var(--bs-secondary-color); }
.dyd-wy-instr.open .dyd-wy-instr-chevron { transform: rotate(180deg); }

/* Wiersze per kurs */
.dyd-wy-course-row {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  padding: .55rem 1rem .55rem 2.2rem; font-size: .84rem;
  border-top: 1px solid var(--bs-border-color);
}
.dyd-wy-course-name { flex: 1; color: var(--bs-secondary-color); }
.dyd-wy-amounts {
  display: flex; gap: .85rem; font-variant-numeric: tabular-nums; font-size: .8rem;
}
.dyd-wy-amounts .lbl { font-size: .65rem; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); }
.dyd-wy-amounts .val { font-weight: 600; color: var(--bs-body-color); }
.dyd-wy-amounts .val.netto { color: #16a34a; }
[data-bs-theme="dark"] .dyd-wy-amounts .val.netto { color: #4ade80; }

/* Wiersz sumaryczny */
.dyd-wy-total {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  padding: .7rem 1rem; border-top: 2px solid var(--bs-border-color);
  background: var(--bs-tertiary-bg); font-weight: 700; font-size: .9rem;
  border-radius: 0 0 12px 12px; margin-top: .5rem;
}

.dyd-wy-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--bs-secondary-color); font-size: .875rem;
}
</style>

<!-- Nawigacja miesiąca -->
<div class="dyd-wy-nav">
  <div>
    <div class="dyd-wy-nav-title">
      <i class="bi bi-wallet2 text-primary me-2" aria-hidden="true"></i><?= h(ucfirst($wy_label)) ?>
    </div>
    <div class="dyd-wy-nav-sub">Wypłaty prowadzących · lekcje odbyte z ustaloną stawką BB</div>
  </div>
  <div class="d-flex align-items-center gap-1 ms-auto">
    <a href="index.php?tab=wypłaty&m=<?= h($wy_prev) ?>"
       class="btn btn-outline-secondary btn-sm" aria-label="Poprzedni miesiąc">
      <i class="bi bi-chevron-left" aria-hidden="true"></i>
    </a>
    <form method="get" class="d-flex">
      <input type="hidden" name="tab" value="wypłaty">
      <input type="month" name="m" value="<?= h($wy_ym) ?>"
             class="form-control form-control-sm" style="width:auto"
             aria-label="Wybierz miesiąc" onchange="this.form.submit()">
    </form>
    <a href="index.php?tab=wypłaty&m=<?= h($wy_next) ?>"
       class="btn btn-outline-secondary btn-sm" aria-label="Następny miesiąc">
      <i class="bi bi-chevron-right" aria-hidden="true"></i>
    </a>
    <a href="../payouts.php?m=<?= h($wy_ym) ?>"
       class="btn btn-outline-secondary btn-sm ms-1" target="_blank" rel="noopener"
       title="Otwórz w pełnym panelu TI">
      <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
    </a>
  </div>
</div>

<!-- Kafelki statystyk -->
<div class="dyd-wy-stats" role="list">
  <div class="dyd-wy-stat" role="listitem">
    <div class="dyd-wy-stat-icon text-primary"><i class="bi bi-person-badge" aria-hidden="true"></i></div>
    <div class="dyd-wy-stat-value"><?= count($wy_rows) ?></div>
    <div class="dyd-wy-stat-label">Prowadzących</div>
  </div>
  <div class="dyd-wy-stat" role="listitem">
    <div class="dyd-wy-stat-icon text-secondary"><i class="bi bi-calendar-check" aria-hidden="true"></i></div>
    <div class="dyd-wy-stat-value"><?= (int)$wy_tot['lessons'] ?></div>
    <div class="dyd-wy-stat-label">Lekcji</div>
  </div>
  <div class="dyd-wy-stat" role="listitem">
    <div class="dyd-wy-stat-icon text-warning"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
    <div class="dyd-wy-stat-value"><?= $wy_f($wy_tot['brutto_brutto']) ?> zł</div>
    <div class="dyd-wy-stat-label">Koszt BB</div>
  </div>
  <div class="dyd-wy-stat" role="listitem">
    <div class="dyd-wy-stat-icon text-success"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
    <div class="dyd-wy-stat-value" style="color:#16a34a"><?= $wy_f($wy_tot['netto']) ?> zł</div>
    <div class="dyd-wy-stat-label">Na rękę</div>
  </div>
</div>

<?php if (!$wy_rows): ?>
<div class="card border-0 shadow-sm">
  <div class="dyd-wy-empty">
    <i class="bi bi-wallet-fill d-block mb-2 fs-3" style="opacity:.3" aria-hidden="true"></i>
    Brak odbytych lekcji z ustaloną stawką wynagrodzenia w <?= h($wy_label) ?>.
  </div>
</div>

<?php else: ?>
<!-- Lista prowadzących -->
<?php foreach ($wy_rows as $idx => $r): ?>
<div class="dyd-wy-instr" id="wy-instr-<?= $idx ?>">
  <div class="dyd-wy-instr-head" onclick="wyToggle(<?= $idx ?>)" role="button"
       aria-expanded="false" aria-controls="wy-courses-<?= $idx ?>">
    <i class="bi bi-person-badge text-primary flex-shrink-0" aria-hidden="true"></i>
    <span class="dyd-wy-instr-name"><?= h($r['name']) ?></span>
    <span class="dyd-wy-instr-meta">
      <?= (int)$r['lessons'] ?> lekcji
      <?php if (count($r['courses']) > 1): ?>
      · <?= count($r['courses']) ?> gr.
      <?php endif; ?>
      · BB <?= $wy_f($r['brutto_brutto']) ?> zł
    </span>
    <span class="dyd-wy-instr-netto"><?= $wy_f($r['netto']) ?> zł</span>
    <i class="bi bi-chevron-down dyd-wy-instr-chevron" aria-hidden="true"></i>
  </div>

  <div id="wy-courses-<?= $idx ?>" style="display:none">
    <?php foreach ($r['courses'] as $c): ?>
    <div class="dyd-wy-course-row">
      <span class="dyd-wy-course-name">
        <i class="bi bi-arrow-return-right me-1 opacity-40" aria-hidden="true"></i><?= h($c['name']) ?>
      </span>
      <div class="dyd-wy-amounts">
        <div><div class="lbl">Lekcje</div><div class="val"><?= (int)$c['lessons'] ?></div></div>
        <div><div class="lbl">BB</div><div class="val"><?= $wy_f($c['brutto_brutto']) ?></div></div>
        <div><div class="lbl">Brutto</div><div class="val"><?= $wy_f($c['brutto']) ?></div></div>
        <div><div class="lbl">Składki</div><div class="val"><?= $wy_f($c['skladki']) ?></div></div>
        <div><div class="lbl">PIT</div><div class="val"><?= $wy_f($c['pit']) ?></div></div>
        <div><div class="lbl">Na rękę</div><div class="val netto"><?= $wy_f($c['netto']) ?></div></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<!-- Wiersz sumaryczny -->
<div class="dyd-wy-total">
  <span class="flex-grow-1">Razem (<?= count($wy_rows) ?> prowadzących · <?= (int)$wy_tot['lessons'] ?> lekcji)</span>
  <div class="dyd-wy-amounts">
    <div><div class="lbl">Koszt BB</div><div class="val"><?= $wy_f($wy_tot['brutto_brutto']) ?></div></div>
    <div><div class="lbl">Koszt płatnika</div><div class="val"><?= $wy_f($wy_tot['zus_employer']) ?></div></div>
    <div><div class="lbl">Brutto</div><div class="val"><?= $wy_f($wy_tot['brutto']) ?></div></div>
    <div><div class="lbl">Składki</div><div class="val"><?= $wy_f($wy_tot['skladki']) ?></div></div>
    <div><div class="lbl">PIT</div><div class="val"><?= $wy_f($wy_tot['pit']) ?></div></div>
    <div><div class="lbl">Na rękę</div><div class="val netto"><?= $wy_f($wy_tot['netto']) ?></div></div>
  </div>
</div>

<p class="text-body-secondary small mt-2 mb-0">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Wszystkie kwoty w zł. Kliknij prowadzącego, aby zobaczyć rozbicie per kurs.
  Stawki potrąceń — <a href="../index.php">ustawienia kursów</a>.
</p>
<?php endif; ?>

</section>

<script>
function wyToggle(idx) {
  var el = document.getElementById('wy-instr-' + idx);
  var box = document.getElementById('wy-courses-' + idx);
  if (!el || !box) return;
  var open = el.classList.toggle('open');
  box.style.display = open ? '' : 'none';
  el.querySelector('.dyd-wy-instr-head').setAttribute('aria-expanded', open ? 'true' : 'false');
}
</script>
