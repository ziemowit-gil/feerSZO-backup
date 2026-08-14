<?php /* ═══════════════════════ TAB: OCENY (e-dziennik) ═══════════════════════ */ ?>
<?php
  $course_row = k30_ti_course_get($cur_course);
  $grades_on  = !empty($course_row['grades_enabled']);
  $CATS = k30_ti_grade_categories();
  $g_enrollees = db_all("SELECT cl.id, cl.name FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id WHERE e.course_id=? AND e.status='active' ORDER BY cl.name COLLATE NOCASE", [$cur_course]);
  $g_rows = db_all("SELECT g.*, cl.name AS client_name FROM k30_ti_grades g JOIN k30_clients cl ON cl.id=g.client_id WHERE g.course_id=? ORDER BY cl.name COLLATE NOCASE, g.graded_at DESC", [$cur_course]);
  $g_by_client = []; foreach ($g_rows as $g) $g_by_client[(int)$g['client_id']][] = $g;
  $g_edit_id = (int)($_GET['edit'] ?? 0);
  $g_edit = $g_edit_id ? k30_ti_grade_get($g_edit_id) : null;
  if ($g_edit && (int)$g_edit['course_id'] !== $cur_course) $g_edit = null;
?>
<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-journal-bookmark me-2 text-primary"></i><?= $g_edit ? 'Edytuj ocenę' : 'Wystaw ocenę' ?></div>
      <div class="card-body">
        <?php if (!$grades_on): ?>
        <div class="alert alert-warning py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle"></i>
          <span>Oceny w tym kursie są wyłączone.</span>
          <form method="post" class="ms-auto">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="grade_toggle_course">
            <input type="hidden" name="course_id" value="<?= $cur_course ?>">
            <button class="btn btn-sm btn-warning py-0 px-2">Włącz oceny</button>
          </form>
        </div>
        <?php endif; ?>
        <?php if (!$g_enrollees): ?>
        <p class="text-body-secondary small mb-0">Brak aktywnych kursantów w tym kursie.</p>
        <?php else: ?>
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="grade_save">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <?php if ($g_edit): ?><input type="hidden" name="grade_id" value="<?= (int)$g_edit['id'] ?>"><?php endif; ?>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="g_client">Kursant <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" id="g_client" name="client_id" required <?= $grades_on?'':'disabled' ?>>
              <option value="">— wybierz —</option>
              <?php foreach ($g_enrollees as $en): ?>
              <option value="<?= (int)$en['id'] ?>" <?= ($g_edit && (int)$g_edit['client_id']===(int)$en['id'])?'selected':'' ?>><?= h($en['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label small fw-semibold" for="g_value">Ocena <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="g_value" name="value_text" required value="<?= h($g_edit['value_text'] ?? '') ?>" placeholder="np. 5, 4+, 85%" <?= $grades_on?'':'disabled' ?>>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label small fw-semibold" for="g_weight">Waga</label>
              <input type="number" class="form-control form-control-sm" id="g_weight" name="weight" min="0.5" step="0.5" value="<?= h(rtrim(rtrim(number_format((float)($g_edit['weight'] ?? 1),2,'.',''),'0'),'.') ?: '1') ?>" <?= $grades_on?'':'disabled' ?>>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label small fw-semibold" for="g_cat">Kategoria</label>
              <select class="form-select form-select-sm" id="g_cat" name="category" <?= $grades_on?'':'disabled' ?>>
                <?php foreach ($CATS as $ck=>$cv): ?>
                <option value="<?= h($ck) ?>" <?= (($g_edit['category'] ?? 'inne')===$ck)?'selected':'' ?>><?= h($cv['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label small fw-semibold" for="g_sess">Lekcja <span class="text-body-secondary">(opc.)</span></label>
              <select class="form-select form-select-sm" id="g_sess" name="session_id" <?= $grades_on?'':'disabled' ?>>
                <option value="">—</option>
                <?php foreach ($all_sessions as $ss): ?>
                <option value="<?= (int)$ss['id'] ?>" <?= ($g_edit && (int)($g_edit['session_id']??0)===(int)$ss['id'])?'selected':'' ?>><?= h(date('d.m.Y', strtotime($ss['lesson_date']))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="g_desc">Za co / opis <span class="text-body-secondary">(opc.)</span></label>
            <input type="text" class="form-control form-control-sm" id="g_desc" name="description" maxlength="300" value="<?= h($g_edit['description'] ?? '') ?>" <?= $grades_on?'':'disabled' ?>>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="g_notify" name="notify" value="1" <?= $grades_on?'':'disabled' ?>>
            <label class="form-check-label small" for="g_notify">Powiadom kursanta (i opiekuna) e-mailem</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary" <?= $grades_on?'':'disabled' ?>><i class="bi bi-save me-1"></i><?= $g_edit ? 'Zapisz zmiany' : 'Wystaw ocenę' ?></button>
            <?php if ($g_edit): ?><a href="index.php?course=<?= $cur_course ?>&tab=oceny" class="btn btn-sm btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center"><i class="bi bi-table me-2 text-primary"></i>Dziennik ocen
        <span class="badge bg-secondary ms-2"><?= count($g_rows) ?></span></div>
      <div class="card-body">
        <?php if (!$g_by_client): ?>
        <p class="text-body-secondary small mb-0">Brak wystawionych ocen.</p>
        <?php else: foreach ($g_by_client as $cid => $cgr):
          $avg = k30_ti_grades_average($cgr); [$abg,$afg] = k30_ti_grade_color($avg); ?>
        <div class="mb-3">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="fw-semibold"><?= h($cgr[0]['client_name']) ?></span>
            <?php if ($avg !== null): ?><span class="badge ms-auto" style="background:<?= $abg ?>;color:<?= $afg ?>">śr. <?= number_format($avg,2,',','') ?></span><?php endif; ?>
          </div>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($cgr as $g): ?>
            <span class="d-inline-flex align-items-center gap-1 border rounded px-1" title="<?= h(k30_ti_grade_category_label($g['category'])) . ($g['description'] ? ' — ' . h($g['description']) : '') ?>">
              <a href="index.php?course=<?= $cur_course ?>&tab=oceny&edit=<?= (int)$g['id'] ?>" class="text-decoration-none"><?= k30_ti_grade_badge($g) ?></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę ocenę?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="grade_delete">
                <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
                <button class="btn btn-sm btn-link text-danger p-0" style="line-height:1" title="Usuń ocenę"><i class="bi bi-x"></i></button>
              </form>
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
