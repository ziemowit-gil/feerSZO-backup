<?php
/**
 * _tab_rozliczenia_godziny.php — „Rozliczenia grupy” dla grupy liczonej TYLKO W GODZINACH (bez kwot).
 * Dołączany z _tab_rozliczenia.php. Wymaga: $cur_course (int), $course (array). Tylko dla dyd_is_staff().
 * Pokazuje sumy godzin rozliczanych per kursant i miesiąc (12 miesięcy) — bez należności, salda i wpłat.
 */
$hg_from = ti_course_hours_only_from((int)$cur_course);
$hg_months_pl = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
$hg_months = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $hg_months[] = ['y' => (int)date('Y', $ts), 'm' => (int)date('n', $ts), 'ym' => date('Y-m', $ts)];
}
$hg_clients = db_all(
    "SELECT cl.id, cl.name FROM k30_clients cl
      WHERE cl.id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active')
         OR cl.id IN (SELECT a.client_id FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id WHERE s.course_id=? AND s.lesson_date>=?)
      ORDER BY cl.name COLLATE NOCASE", [(int)$cur_course, (int)$cur_course, $hg_months[0]['ym'] . '-01']);
$hg = []; $hg_tot_c = []; $hg_tot_m = array_fill_keys(array_column($hg_months, 'ym'), 0.0);
foreach ($hg_clients as $c) {
    $row = 0.0;
    foreach ($hg_months as $mo) {
        $calc = k30_ti_calculate_billing((int)$c['id'], $mo['m'], $mo['y'], (int)$cur_course);
        $h = (float)$calc['hours_billed'];
        $hg[(int)$c['id']][$mo['ym']] = $h; $row += $h; $hg_tot_m[$mo['ym']] += $h;
    }
    $hg_tot_c[(int)$c['id']] = $row;
}
?>
<section aria-label="Rozliczenia grupy — tylko godziny" class="dyd-roz-wrap" style="padding:1.1rem 0 2.5rem">
  <div class="alert alert-warning d-flex align-items-start gap-2" role="status">
    <i class="bi bi-hourglass-split mt-1" aria-hidden="true"></i>
    <div>
      <strong>Grupa liczy tylko godziny — bez kwot.</strong>
      Nie naliczamy stawek, należności, salda, zaległości ani faktur i nie wysyłamy powiadomień o rozliczeniach ani zmianie cen.
      <?php if ($hg_from !== ''): ?>Obowiązuje od miesiąca <strong><?= h($hg_from) ?></strong>; wcześniejsze rozliczenia zostały po staremu.<?php endif; ?>
      Ustawienie zmienisz w <a href="kurs.php?id=<?= (int)$cur_course ?>">Edytuj kurs</a>.
    </div>
  </div>
  <h2 class="h5 fw-bold mb-2"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Godziny rozliczane — ostatnie 12 miesięcy</h2>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle text-center mb-3">
      <caption class="visually-hidden">Godziny rozliczane w podziale na kursantów i miesiące</caption>
      <thead class="table-light"><tr>
        <th scope="col" class="text-start">Kursant</th>
        <?php foreach ($hg_months as $mo): ?><th scope="col" class="small"><?= h($hg_months_pl[$mo['m']] . ' ' . substr((string)$mo['y'], 2)) ?></th><?php endforeach; ?>
        <th scope="col">Razem</th>
      </tr></thead>
      <tbody>
      <?php foreach ($hg_clients as $c): ?>
        <tr><th scope="row" class="text-start fw-semibold"><?= h($c['name']) ?></th>
          <?php foreach ($hg_months as $mo): $h = $hg[(int)$c['id']][$mo['ym']] ?? 0.0; ?>
          <td class="<?= $h > 0 ? '' : 'text-body-secondary' ?>"><?php if ($h > 0): ?><a class="text-decoration-none" target="_blank" rel="noopener"
               href="hours_pdf.php?client_id=<?= (int)$c['id'] ?>&amp;month=<?= $mo['m'] ?>&amp;year=<?= $mo['y'] ?>&amp;course_id=<?= (int)$cur_course ?>"
               title="Rozpiska godzin — <?= h($c['name']) ?>, <?= h($mo['ym']) ?>"><?= h(rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',')) ?></a><?php else: ?>·<?php endif; ?></td>
          <?php endforeach; ?>
          <td class="fw-semibold"><?= h(rtrim(rtrim(number_format($hg_tot_c[(int)$c['id']], 2, ',', ''), '0'), ',')) ?> h</td>
        </tr>
      <?php endforeach; if (!$hg_clients): ?>
        <tr><td colspan="<?= count($hg_months) + 2 ?>" class="text-body-secondary py-3">Brak uczestników ani zajęć w ostatnich 12 miesiącach.</td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($hg_clients): ?>
      <tfoot class="table-light"><tr><th scope="row" class="text-start">Razem w miesiącu</th>
        <?php foreach ($hg_months as $mo): $t = $hg_tot_m[$mo['ym']]; ?><th class="<?= $t > 0 ? '' : 'text-body-secondary' ?>"><?= $t > 0 ? h(rtrim(rtrim(number_format($t, 2, ',', ''), '0'), ',')) : '·' ?></th><?php endforeach; ?>
        <th><?= h(rtrim(rtrim(number_format(array_sum($hg_tot_m), 2, ',', ''), '0'), ',')) ?> h</th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p class="small text-body-secondary mb-0">Godziny liczone jako pełne godziny zegarowe rozpoczęte (czas lekcji zaokrąglany w górę), łącznie z płatną nieobecnością — tak samo jak w pozostałych rozliczeniach.
    Wiersze miesięczne z godzinami powstają przy wystawianiu rozliczeń (<a href="billing.php?course_id=<?= (int)$cur_course ?>">Rozliczenia kursantów</a>) i automatycznie na początku miesiąca.</p>
</section>
