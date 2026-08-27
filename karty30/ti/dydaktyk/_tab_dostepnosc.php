<?php /* ═══════════════════════ TAB: DOSTĘPNOŚĆ ═══════════════════════ */ ?>
<?php
/**
 * Moja dostępność w tygodniu — jedna tabela zamiast siedmiu kart.
 *
 * Kafelki dni rozjeżdżały się przy szerokim menu (dni z kilkoma oknami wychodziły
 * poza ekran, dni puste zajmowały tyle samo miejsca co pełne). Rejestr godzin
 * czyta się w wierszach: dzień → okno → status → akcje, a podsumowanie tygodnia
 * mówi od razu, ile godzin zadeklarowano.
 *
 * Zmienne z index.php: $my_avail, $cur_course.
 */
$av_by_day = [];
foreach ($my_avail as $w) { $av_by_day[(int)$w['day_of_week']][] = $w; }
foreach ($av_by_day as $d => $wins) {
    usort($wins, fn($a, $b) => strcmp((string)$a['time_from'], (string)$b['time_from']));
    $av_by_day[$d] = $wins;
}

$av_order   = [1,2,3,4,5,6,0];
$av_min_all = 0;
$av_windows = 0;
$av_drafts  = 0;
$av_days    = 0;
foreach ($av_order as $dw) {
    $wins = $av_by_day[$dw] ?? [];
    if ($wins) $av_days++;
    foreach ($wins as $w) {
        $av_windows++;
        if (($w['status'] ?? 'approved') === 'draft') $av_drafts++;
        $av_min_all += max(0, ti_hm2min((string)$w['time_to']) - ti_hm2min((string)$w['time_from']));
    }
}
/** Minuty okien danego dnia. */
$av_day_min = function (array $wins): int {
    $m = 0;
    foreach ($wins as $w) $m += max(0, ti_hm2min((string)$w['time_to']) - ti_hm2min((string)$w['time_from']));
    return $m;
};
/** Minuty → „6 h 30 min”. */
$av_hm = function (int $min): string {
    if ($min <= 0) return '—';
    $h = intdiv($min, 60); $m = $min % 60;
    return ($h ? $h . ' h' : '') . ($h && $m ? ' ' : '') . ($m ? $m . ' min' : '');
};
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Moja dostępność w tygodniu</h1>
  <span class="badge bg-secondary"><?= (int)$av_windows ?> <?= $av_windows === 1 ? 'okno' : 'okien' ?></span>
  <?php if ($av_windows): ?>
  <span class="badge text-bg-light border text-dark"><?= h($av_hm($av_min_all)) ?> w <?= (int)$av_days ?> <?= $av_days === 1 ? 'dniu' : 'dniach' ?></span>
  <?php endif; ?>
  <?php if ($av_drafts): ?>
  <span class="badge text-bg-warning"><?= (int)$av_drafts ?> do zatwierdzenia</span>
  <?php endif; ?>
</div>

<?php if (!$my_avail): ?>
<div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong>Nie masz jeszcze ustawionej dostępności.</strong><br>
    Dodaj okna czasowe poniżej, aby koordynatorzy wiedzieli, kiedy możesz prowadzić zajęcia.
    Bez ustawionej dostępności system nie ogranicza terminów lekcji.
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Okna godzinowe</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Okna dostępności w tygodniu: dzień, godziny, status i akcje</caption>
      <thead><tr>
        <th scope="col" style="width:10rem">Dzień</th>
        <th scope="col" style="width:9rem">Godziny</th>
        <th scope="col" style="width:7rem">Czas</th>
        <th scope="col">Status</th>
        <th scope="col" class="text-end" style="width:8rem">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach ($av_order as $dw):
          $wins  = $av_by_day[$dw] ?? [];
          $dmin  = $av_day_min($wins);
          $rows  = max(1, count($wins));
        ?>
          <?php if (!$wins): ?>
          <tr class="text-body-secondary">
            <th scope="row" class="fw-semibold"><?= h(K30_TI_DAYS[$dw]) ?></th>
            <td colspan="4" class="small">— niedostępny —</td>
          </tr>
          <?php else: ?>
            <?php foreach ($wins as $i => $w):
              $av_st    = $w['status'] ?? 'approved';
              $av_cfg   = TI_AVAIL_STATUS[$av_st] ?? TI_AVAIL_STATUS['approved'];
              $is_draft = $av_st === 'draft';
              $hours    = substr((string)$w['time_from'], 0, 5) . '–' . substr((string)$w['time_to'], 0, 5);
              $wmin     = max(0, ti_hm2min((string)$w['time_to']) - ti_hm2min((string)$w['time_from']));
            ?>
            <tr>
              <?php if ($i === 0): ?>
              <th scope="row" rowspan="<?= $rows ?>" class="fw-semibold align-top">
                <?= h(K30_TI_DAYS[$dw]) ?>
                <div class="text-body-secondary fw-normal small"><?= h($av_hm($dmin)) ?></div>
              </th>
              <?php endif; ?>
              <td class="text-nowrap"><strong><?= h($hours) ?></strong></td>
              <td class="text-nowrap small text-body-secondary"><?= h($av_hm($wmin)) ?></td>
              <td><span class="badge text-bg-<?= h($av_cfg['color']) ?>"><?= h($av_cfg['label']) ?></span></td>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="avail_status">
                  <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
                  <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                  <input type="hidden" name="status" value="<?= $is_draft ? 'approved' : 'draft' ?>">
                  <button class="btn btn-sm btn-outline-<?= $is_draft ? 'success' : 'secondary' ?> py-0 px-2"
                          title="<?= $is_draft ? 'Zatwierdź okno' : 'Cofnij do szkicu' ?>"
                          aria-label="<?= $is_draft ? 'Zatwierdź' : 'Cofnij do szkicu' ?> okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h($hours) ?>">
                    <i class="bi bi-<?= $is_draft ? 'check-circle' : 'arrow-counterclockwise' ?>" aria-hidden="true"></i>
                  </button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h($hours) ?>?')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="avail_delete">
                  <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
                  <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń okno"
                          aria-label="Usuń okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h($hours) ?>">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
      <?php if ($av_windows): ?>
      <tfoot><tr>
        <th scope="row" colspan="2" class="text-end">Razem w tygodniu</th>
        <td class="fw-semibold text-nowrap"><?= h($av_hm($av_min_all)) ?></td>
        <td colspan="2" class="small text-body-secondary">
          <?= (int)$av_windows ?> <?= $av_windows === 1 ? 'okno' : 'okien' ?> w <?= (int)$av_days ?> <?= $av_days === 1 ? 'dniu' : 'dniach' ?>
        </td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">Dodaj okno dostępności</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="avail_add">
      <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
      <div class="col-sm-6 col-lg-3">
        <label class="form-label fw-semibold" for="av_dow">Dzień tygodnia</label>
        <select class="form-select" id="av_dow" name="day_of_week" required>
          <?php foreach ($av_order as $dw): ?>
          <option value="<?= $dw ?>"><?= h(K30_TI_DAYS[$dw]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3 col-lg-2">
        <label class="form-label fw-semibold" for="av_from">Od</label>
        <select class="form-select" id="av_from" name="time_from"><?= ti_time_options('09:00') ?></select>
      </div>
      <div class="col-sm-3 col-lg-2">
        <label class="form-label fw-semibold" for="av_to">Do</label>
        <select class="form-select" id="av_to" name="time_to"><?= ti_time_options('13:00') ?></select>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label fw-semibold" for="av_status">Status</label>
        <select class="form-select" id="av_status" name="status">
          <option value="approved">Zatwierdzona</option>
          <option value="draft">Planowana (szkic)</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj</button>
      </div>
    </form>
    <p class="form-text mb-0">
      Zajęcia można ustawiać tylko w godzinach dostępności. Bez zdefiniowanych okien nie ma ograniczeń.
      W jednym dniu może być kilka okien; „Planowana (szkic)” to deklaracja wstępna, która nie zwalnia terminu.
    </p>
  </div>
</div>
