<?php
/**
 * Partial: tabela frekwencji kursanta. Wymaga: $rv_client_id (int).
 * Używany przez _rozliczenia_view.php oraz panel rodzica (zakładka Frekwencja).
 */
$rv_lessons = k30_ti_client_lessons((int)$rv_client_id, 40);
$rv_mon = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
$rv_mon_short = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];

// ── Dane do wykresu miesięcznego ─────────────────────────────────────────
$rv_cdata = [];
foreach ($rv_lessons as $rv_l) {
    if (($rv_l['status'] ?? '') === 'remote_material') continue;
    if ((int)($rv_l['course_track_attendance'] ?? 1) === 0) continue;
    $rv_ym = substr((string)$rv_l['lesson_date'], 0, 7);
    if (!isset($rv_cdata[$rv_ym])) $rv_cdata[$rv_ym] = ['t' => 0, 'p' => 0];
    $rv_cdata[$rv_ym]['t']++;
    if (!empty($rv_l['attended'])) $rv_cdata[$rv_ym]['p']++;
}
ksort($rv_cdata);
if (count($rv_cdata) > 6) $rv_cdata = array_slice($rv_cdata, -6, 6, true);
?>
<?php if ($rv_cdata):
    $rvn  = count($rv_cdata);
    $rvW = 300; $rvH = 150;
    $rvpL = 30; $rvpR = 6; $rvpT = 14; $rvpB = 26;
    $rvaW = $rvW - $rvpL - $rvpR;
    $rvaH = $rvH - $rvpT - $rvpB;
    $rvbW = ($rvaW / $rvn) - 5;
    $rvGap = ($rvaW - $rvbW * $rvn) / ($rvn + 1);
?>
<div class="card mb-3">
  <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
    <i class="bi bi-bar-chart-line text-primary" aria-hidden="true"></i>
    <span class="fw-semibold">Frekwencja miesięczna</span>
  </div>
  <div class="card-body py-3 px-3">
    <svg viewBox="0 0 <?= $rvW ?> <?= $rvH ?>" aria-hidden="true"
         class="w-100 d-block" style="max-height:150px">
      <?php foreach ([0, 50, 100] as $rvg): ?>
      <?php $rvgY = $rvpT + $rvaH - ($rvg * $rvaH / 100); ?>
      <line x1="<?= $rvpL ?>" y1="<?= number_format($rvgY,1) ?>" x2="<?= $rvW - $rvpR ?>" y2="<?= number_format($rvgY,1) ?>"
            stroke="currentColor" stroke-opacity="<?= $rvg === 50 ? '.1' : '.2' ?>" stroke-dasharray="<?= $rvg === 50 ? '3,3' : '0' ?>"/>
      <text x="<?= $rvpL - 3 ?>" y="<?= number_format($rvgY + 3.5, 1) ?>" text-anchor="end"
            font-size="8.5" fill="currentColor" opacity=".55"><?= $rvg ?>%</text>
      <?php endforeach; ?>
      <?php $rvi = 0; foreach ($rv_cdata as $rv_ym => $rv_md): ?>
      <?php
          $rv_pct = $rv_md['t'] > 0 ? round($rv_md['p'] / $rv_md['t'] * 100) : 0;
          $rv_bh  = max($rv_pct * $rvaH / 100, $rv_pct > 0 ? 3 : 0);
          $rv_bx  = $rvpL + $rvGap + $rvi * ($rvbW + $rvGap);
          $rv_by  = $rvpT + $rvaH - $rv_bh;
          $rv_bc  = $rv_pct >= 80 ? '#22c55e' : ($rv_pct >= 60 ? '#f59e0b' : '#ef4444');
          $rv_lx  = $rv_bx + $rvbW / 2;
          $rv_ymp = explode('-', $rv_ym);
          $rv_ml  = ($rv_mon_short[(int)$rv_ymp[1]] ?? '') . ' \'' . substr($rv_ymp[0], 2);
      ?>
      <?php if ($rv_bh > 0): ?>
      <rect x="<?= number_format($rv_bx,1) ?>" y="<?= number_format($rv_by,1) ?>"
            width="<?= number_format($rvbW,1) ?>" height="<?= number_format($rv_bh,1) ?>"
            fill="<?= $rv_bc ?>" rx="3" opacity=".85"/>
      <?php endif; ?>
      <text x="<?= number_format($rv_lx,1) ?>" y="<?= number_format($rv_by - 3,1) ?>"
            text-anchor="middle" font-size="9" font-weight="600"
            fill="<?= $rv_bc ?>"><?= $rv_pct > 0 ? $rv_pct . '%' : '' ?></text>
      <text x="<?= number_format($rv_lx,1) ?>" y="<?= $rvH - $rvpB + 12 ?>"
            text-anchor="middle" font-size="8.5" fill="currentColor" opacity=".65"><?= h($rv_ml) ?></text>
      <?php $rvi++; endforeach; ?>
    </svg>
    <table class="visually-hidden">
      <caption>Frekwencja miesięczna — dane</caption>
      <thead><tr><th scope="col">Miesiąc</th><th scope="col">Lekcji</th><th scope="col">Obecności</th><th scope="col">Frekwencja</th></tr></thead>
      <tbody>
        <?php foreach ($rv_cdata as $rv_ym => $rv_md): ?>
        <?php $rv_pct2 = $rv_md['t'] > 0 ? round($rv_md['p'] / $rv_md['t'] * 100) : 0;
              $rv_ymp2 = explode('-', $rv_ym);
              $rv_full = ($rv_mon_short[(int)$rv_ymp2[1]] ?? '') . ' ' . $rv_ymp2[0]; ?>
        <tr><th scope="row"><?= h($rv_full) ?></th><td><?= (int)$rv_md['t'] ?></td><td><?= (int)$rv_md['p'] ?></td><td><?= $rv_pct2 ?>%</td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <caption class="visually-hidden">Frekwencja na lekcjach</caption>
      <thead>
        <tr><th scope="col">Data</th><th scope="col">Kurs</th><th scope="col">Temat</th><th scope="col" class="text-center">Obecność</th></tr>
      </thead>
      <tbody>
        <?php if (!$rv_lessons): ?>
        <tr><td colspan="4" class="text-center text-body-secondary py-4">Brak lekcji.</td></tr>
        <?php endif; ?>
        <?php foreach ($rv_lessons as $l): $d = new DateTime($l['lesson_date']); ?>
        <tr>
          <td class="text-nowrap text-body-secondary small"><?= $d->format('d') ?> <?= $rv_mon[(int)$d->format('n')] ?> <?= $d->format('Y') ?></td>
          <td class="text-body-secondary small"><?= h($l['course_name']) ?></td>
          <td class="small"><?= $l['topic'] ? h($l['topic']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="text-center">
            <?php if ((int)($l['course_track_attendance'] ?? 1) === 0): ?>
            <span class="badge text-bg-light text-secondary border" title="Kurs bez liczenia frekwencji">bez frekwencji</span>
            <?php elseif (!in_array($l['status'], K30_TI_ATTENDANCE_STATUSES, true)):
              // Statusy poza frekwencją (planowana, praca własna, odwołana) — bez obecności/nieobecności
              $lbl = K30_TI_SESSION_STATUSES[$l['status']]['label'] ?? $l['status'];
              if ($l['status'] === 'planned') $lbl = 'planowana';
              elseif ($l['status'] === 'remote_material') $lbl = 'praca własna';
            ?>
            <span class="badge text-bg-secondary"><?= h($lbl) ?></span>
            <?php elseif ($l['attended']): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>obecny</span>
            <?php elseif ((int)($l['att_no_show'] ?? 0) === 1): ?>
            <span class="badge text-bg-warning"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>nie pojawił się</span>
            <?php elseif ((int)($l['att_cancelled'] ?? 0) === 1): ?>
            <span class="badge text-bg-secondary"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>odwołany</span>
            <?php else: ?>
            <span class="badge text-bg-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>nieobecny</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
