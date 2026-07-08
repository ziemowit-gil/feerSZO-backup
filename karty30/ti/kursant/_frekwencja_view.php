<?php
/**
 * Partial: tabela frekwencji kursanta. Wymaga: $rv_client_id (int).
 * Używany przez _rozliczenia_view.php oraz panel rodzica (zakładka Frekwencja).
 */
$rv_lessons = k30_ti_client_lessons((int)$rv_client_id, 40);
$rv_mon = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
?>
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
