<?php /* ═══════════════════════ TAB: DOSTĘPNOŚĆ ═══════════════════════ */ ?>
<?php
  $av_by_day = [];
  foreach ($my_avail as $w) { $av_by_day[(int)$w['day_of_week']][] = $w; }
?>
<?php if (!$my_avail): ?>
<div class="alert alert-warning d-flex align-items-start gap-3 mb-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong>Nie masz jeszcze ustawionej dostępności.</strong><br>
    Dodaj okna czasowe poniżej, aby koordynatorzy wiedzieli, kiedy możesz prowadzić zajęcia.
    Bez ustawionej dostępności system nie może automatycznie weryfikować terminów lekcji.
  </div>
</div>
<?php endif; ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent">
    <span class="fw-semibold"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Moja dostępność w tygodniu</span>
  </div>
  <div class="card-body">
    <p class="text-body-secondary small">Zajęcia można dodać tylko w godzinach Twojej dostępności. Bez zdefiniowanych okien obowiązują dotychczasowe zasady (bez ograniczeń). Możesz dodać kilka okien w jednym dniu.</p>
    <div class="row g-3">
      <?php foreach ([1,2,3,4,5,6,0] as $dw): $wins = $av_by_day[$dw] ?? []; ?>
      <div class="col-md-6 col-lg-4">
        <div class="border rounded p-2 h-100">
          <div class="fw-semibold mb-2"><i class="bi bi-calendar-day me-1 text-primary" aria-hidden="true"></i><?= h(K30_TI_DAYS[$dw]) ?></div>
          <?php if (!$wins): ?><div class="text-body-secondary small mb-2">— niedostępny —</div><?php endif; ?>
          <?php foreach ($wins as $w):
            $av_st  = $w['status'] ?? 'approved';
            $av_cfg = TI_AVAIL_STATUS[$av_st] ?? TI_AVAIL_STATUS['approved'];
            $is_draft = $av_st === 'draft';
          ?>
          <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="badge text-bg-<?= $av_cfg['color'] ?>"><?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?></span>
            <span class="badge rounded-pill text-bg-<?= $av_cfg['color'] ?> text-opacity-75" style="font-size:.62rem">
              <?= h($av_cfg['label']) ?>
            </span>
            <div class="ms-auto d-flex gap-1">
              <form method="post" style="display:inline">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="avail_status">
                <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                <input type="hidden" name="status" value="<?= $is_draft ? 'approved' : 'draft' ?>">
                <button class="btn btn-sm btn-outline-<?= $is_draft ? 'success' : 'secondary' ?> py-0 px-2"
                        title="<?= $is_draft ? 'Zatwierdź' : 'Cofnij do szkicu' ?>">
                  <i class="bi bi-<?= $is_draft ? 'check-circle' : 'arrow-counterclockwise' ?>" aria-hidden="true"></i>
                </button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Usunąć to okno dostępności?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="avail_delete">
                <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń okno"
                        aria-label="Usuń okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?>">
                  <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <hr>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="avail_add">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <div class="col-sm-4">
        <label class="form-label fw-semibold" for="av_dow">Dzień tygodnia</label>
        <select class="form-select" id="av_dow" name="day_of_week" required>
          <?php foreach ([1,2,3,4,5,6,0] as $dw): ?>
          <option value="<?= $dw ?>"><?= h(K30_TI_DAYS[$dw]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_from">Od</label>
        <select class="form-select" id="av_from" name="time_from"><?= ti_time_options('09:00') ?></select>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_to">Do</label>
        <select class="form-select" id="av_to" name="time_to"><?= ti_time_options('13:00') ?></select>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_status">Status</label>
        <select class="form-select" id="av_status" name="status">
          <option value="approved">Zatwierdzona</option>
          <option value="draft">Planowana (szkic)</option>
        </select>
      </div>
      <div class="col-sm-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj</button>
      </div>
    </form>
  </div>
</div>
