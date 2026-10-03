<?php
/**
 * Sekcja „Dziennik zajęć” karty kursanta — odczyt lekcji i obecności z istniejących tabel TI (nic nie jest duplikowane).
 * Zmienne: $courses, $timeline, $summary.
 */
$_h = intdiv((int)$summary['present_min'], 60); $_m = (int)$summary['present_min'] % 60;
?>
<section id="dziennik" aria-labelledby="h-dziennik" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-dziennik"><i class="bi bi-journal-text text-primary me-2" aria-hidden="true"></i>Dziennik zajęć</h2></div>
  <div class="card-body">
    <?php if (!$timeline): ?>
      <p class="text-body-secondary mb-0">Brak lekcji kursanta w Twoich grupach.</p>
    <?php else: ?>
    <p class="mb-2" id="dz-sum">
      <span class="visually-hidden">Podsumowanie obecności z odbytych lekcji: </span>
      <?= dk_att_badge('present') ?> <strong><?= (int)$summary['present'] ?></strong>
      <?= dk_att_badge('excused') ?> <strong><?= (int)$summary['excused'] ?></strong>
      <?= dk_att_badge('absent') ?> <strong><?= (int)$summary['absent'] ?></strong>
      <span class="small text-body-secondary ms-2">Godziny obecności: <?= $_h ?> h <?= str_pad((string)$_m, 2, '0', STR_PAD_LEFT) ?> min z <?= (int)$summary['held'] ?> odbytych lekcji</span>
    </p>
    <div class="table-responsive">
      <table class="table table-sm align-middle" style="font-size:.88rem">
        <caption class="visually-hidden">Ostatnie lekcje kursanta: data, grupa, temat, obecność, czas trwania i uwaga prowadzącego do lekcji</caption>
        <thead class="table-light"><tr>
          <th scope="col">Data</th><th scope="col">Grupa</th><th scope="col">Temat</th><th scope="col">Obecność</th><th scope="col">Czas</th><th scope="col">Uwaga do lekcji</th>
        </tr></thead>
        <tbody>
        <?php foreach ($timeline as $t): ?>
          <tr>
            <th scope="row" class="fw-normal text-nowrap"><time datetime="<?= h($t['lesson_date']) ?>"><?= h(dk_date($t['lesson_date'])) ?></time>
              <?= $t['time_from'] !== '' ? '<div class="small text-body-secondary">' . h($t['time_from']) . ($t['time_to'] !== '' ? '–' . h($t['time_to']) : '') . '</div>' : '' ?></th>
            <td><a href="index.php?course=<?= (int)$t['course_id'] ?>&amp;tab=lekcje"><?= h($t['course_name']) ?></a></td>
            <td><?= $t['topic'] !== '' ? h($t['topic']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td><?= dk_att_badge($t['att']) ?><?= !empty($t['no_show']) ? '<div class="small text-body-secondary">bez zgłoszenia</div>' : '' ?></td>
            <td class="text-nowrap"><?= (int)$t['dur'] > 0 ? (int)$t['dur'] . ' min' : '—' ?></td>
            <td><?= trim((string)$t['ind_notes']) !== '' ? h($t['ind_notes']) : '<span class="text-body-secondary">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small text-body-secondary mb-0">Obecność i uwagi do lekcji prowadzi się w zakładce Lekcje grupy — tu są tylko odczytane.</p>
    <?php endif; ?>
  </div>
</section>
