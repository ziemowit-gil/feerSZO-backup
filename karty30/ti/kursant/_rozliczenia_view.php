<?php
/**
 * Partial: widok rozliczeń (+ opcjonalnie frekwencji) kursanta.
 * Wymaga zdefiniowanych zmiennych:
 *   $rv_client_id   (int)  — klient (kursant), którego dane pokazujemy,
 *   $rv_show_lessons(bool) — czy dołączyć sekcję frekwencji (panel rodzica = true).
 * Używa h() oraz helperów k30_ti_client_billing / k30_ti_client_lessons.
 */
$rv_billing = k30_ti_client_billing((int)$rv_client_id);
$rv_months  = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
               7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$rv_st = [
    'draft'  => ['Robocze',    '#94a3b8'],
    'issued' => ['Wystawione', '#3b82f6'],
    'paid'   => ['Opłacone',   '#22c55e'],
];
$rv_total = 0.0; $rv_paid = 0.0;
foreach ($rv_billing as $b) {
    $t = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
    $rv_total += $t;
    if ($b['status']==='paid') $rv_paid += $t;
}
?>
<div class="vlab-section-title"><i class="bi bi-receipt"></i> Rozliczenia</div>

<div class="stats-row">
  <div class="stat-card">
    <div class="stat-val"><?= number_format($rv_total, 2, ',', ' ') ?> zł</div>
    <div class="stat-lbl">Suma rozliczeń</div>
  </div>
  <div class="stat-card">
    <div class="stat-val att-yes"><?= number_format($rv_paid, 2, ',', ' ') ?> zł</div>
    <div class="stat-lbl">Opłacone</div>
  </div>
  <div class="stat-card">
    <div class="stat-val"><?= number_format(max(0, $rv_total - $rv_paid), 2, ',', ' ') ?> zł</div>
    <div class="stat-lbl">Do zapłaty</div>
  </div>
</div>

<div class="lesson-table">
  <table>
    <thead>
      <tr><th>Okres</th><th>Godziny</th><th>Korekta</th><th>Do zapłaty</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php if (!$rv_billing): ?>
      <tr><td colspan="5" style="color:var(--muted);text-align:center;padding:2rem">Brak rozliczeń.</td></tr>
      <?php endif; ?>
      <?php foreach ($rv_billing as $b):
        [$lbl, $col] = $rv_st[$b['status']] ?? [$b['status'], '#94a3b8'];
        $adj = (float)($b['adjustment'] ?? 0);
        $tot = (float)$b['amount'] + $adj;
      ?>
      <tr>
        <td><?= h($rv_months[(int)$b['month']] ?? $b['month']) ?> <?= (int)$b['year'] ?></td>
        <td><?= number_format((float)$b['hours_billed'], 2, ',', ' ') ?> h</td>
        <td>
          <?php if ($adj != 0): ?>
            <span style="color:<?= $adj > 0 ? '#f87171' : '#4ade80' ?>"><?= ($adj>0?'+':'−').number_format(abs($adj),2,',',' ') ?> zł</span>
            <?php if (!empty($b['adjustment_note'])): ?><div style="color:var(--muted);font-size:.72rem"><?= h($b['adjustment_note']) ?></div><?php endif; ?>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td class="fw-bold"><?= number_format($tot, 2, ',', ' ') ?> zł</td>
        <td><span class="vlab-st" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44"><?= h($lbl) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($rv_show_lessons)):
  $rv_lessons = k30_ti_client_lessons((int)$rv_client_id, 40);
  $rv_mon = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
?>
<div class="vlab-section-title" style="margin-top:1.75rem"><i class="bi bi-calendar-check"></i> Frekwencja</div>
<div class="lesson-table">
  <table>
    <thead><tr><th>Data</th><th>Kurs</th><th>Temat</th><th class="text-center">Obecność</th></tr></thead>
    <tbody>
      <?php if (!$rv_lessons): ?>
      <tr><td colspan="4" style="color:var(--muted);text-align:center;padding:2rem">Brak lekcji.</td></tr>
      <?php endif; ?>
      <?php foreach ($rv_lessons as $l):
        $d = new DateTime($l['lesson_date']);
      ?>
      <tr>
        <td class="text-nowrap" style="color:var(--muted);font-size:.82rem"><?= $d->format('d') ?> <?= $rv_mon[(int)$d->format('n')] ?> <?= $d->format('Y') ?></td>
        <td style="color:var(--muted);font-size:.82rem"><?= h($l['course_name']) ?></td>
        <td style="font-size:.85rem"><?= $l['topic'] ? h($l['topic']) : '<span style="color:var(--muted)">—</span>' ?></td>
        <td class="text-center">
          <?php if ($l['status'] !== 'held'): ?>
          <span class="att-unk" style="font-size:.8rem"><?= $l['status']==='planned'?'planowana':h($l['status']) ?></span>
          <?php elseif ($l['attended']): ?>
          <span class="att-yes"><i class="bi bi-check-circle-fill"></i></span>
          <?php else: ?>
          <span class="att-no"><i class="bi bi-x-circle-fill"></i></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
