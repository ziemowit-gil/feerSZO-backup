<?php /* ═══════════════════════ TAB: TESTY ═══════════════════════ */ ?>
<?php
  $dyd_tests    = k30_ti_tests_list($cur_course);
  $dyd_students = db_all(
      "SELECT cl.id, cl.name FROM k30_clients cl
       JOIN k30_ti_enrollments e ON e.client_id=cl.id
       WHERE e.course_id=? AND e.status='active' ORDER BY cl.name",
      [$cur_course]);
  $dyd_need_review = (int)db_one(
      "SELECT COUNT(*) FROM k30_ti_test_attempts a
       JOIN k30_ti_tests t ON t.id=a.test_id
       WHERE t.course_id=? AND a.needs_review=1 AND a.status='submitted'",
      [$cur_course]);
?>
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h5 class="mb-0 fw-semibold"><i class="bi bi-card-checklist me-1" aria-hidden="true"></i>Testy</h5>
  <span class="btn btn-sm btn-primary ms-auto disabled" aria-disabled="true"
        title="Kreator testów jest chwilowo niedostępny">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz test
  </span>
</div>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3 small" role="alert">
  <i class="bi bi-tools fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
  <span><strong>Kreator testów chwilowo niedostępny.</strong> Trwają prace serwisowe — możliwość tworzenia i edytowania testów zostanie przywrócona wkrótce. Istniejące testy działają normalnie.</span>
</div>
<?php if ($dyd_need_review > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2 small">
  <i class="bi bi-clipboard-check fs-5 flex-shrink-0" aria-hidden="true"></i>
  <span><?= $dyd_need_review ?> <?= $dyd_need_review === 1 ? 'podejście czeka' : 'podejść czeka' ?> na ocenę pytań otwartych —
    <a href="test_build.php?course_id=<?= $cur_course ?>">przejdź do oceniania</a></span>
</div>
<?php endif; ?>
<?php if (!$dyd_tests): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body d-flex flex-column align-items-center justify-content-center text-center py-5" style="min-height:220px">
      <i class="bi bi-card-checklist mb-3" style="font-size:3rem;opacity:.3" aria-hidden="true"></i>
      <h6 class="fw-semibold mb-1">Brak testów w tym kursie</h6>
      <p class="text-body-secondary small mb-3">Możesz samodzielnie tworzyć testy dla swoich kursantów.</p>
      <span class="btn btn-primary btn-sm disabled" aria-disabled="true"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz pierwszy test</span>
    </div>
  </div>
<?php else: ?>

<?php foreach ($dyd_tests as $dt):
  $dt_id = (int)$dt['id'];
  $dt_attempts = db_all(
      "SELECT a.*, cl.name AS client_name
       FROM k30_ti_test_attempts a
       JOIN k30_clients cl ON cl.id=a.client_id
       WHERE a.test_id=? ORDER BY cl.name, a.id",
      [$dt_id]);
  $dt_att_map = [];
  foreach ($dt_attempts as $da) $dt_att_map[(int)$da['client_id']][] = $da;
  $n_q = (int)$dt['n_questions'];
  $dt_pass = (int)($dt['pass_pct'] ?? 0);
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-card-checklist text-primary me-1" aria-hidden="true"></i><?= h($dt['title']) ?>
    <span class="badge bg-secondary"><?= $n_q ?> pytań</span>
    <?php if ($dt_pass > 0): ?><span class="badge bg-light text-dark border">próg <?= $dt_pass ?>%</span><?php endif; ?>
    <?php if (empty($dt['is_active'])): ?><span class="badge bg-warning text-dark"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>ukryty</span><?php endif; ?>
    <div class="ms-auto d-flex gap-1">
      <a href="test_build.php?test_id=<?= $dt_id ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edytuj pytania"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
      <a href="test_build.php?course_id=<?= $cur_course ?>&amp;test_id=<?= $dt_id ?>&amp;edit_meta=1" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Ustawienia testu"><i class="bi bi-gear" aria-hidden="true"></i></a>
    </div>
  </div>
  <?php if (!$dyd_students): ?>
    <div class="card-body text-muted small">Brak aktywnych kursantów.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr>
        <th scope="col">Kursant</th>
        <th scope="col" class="text-center">Etap</th>
        <th scope="col" class="text-center">Wynik</th>
        <th scope="col" class="text-center">Podejść</th>
        <th scope="col" class="text-center">Poprawa</th>
      </tr></thead>
      <tbody>
      <?php foreach ($dyd_students as $ds):
        $cid      = (int)$ds['id'];
        $att_list = $dt_att_map[$cid] ?? [];
        $last_att = $att_list ? end($att_list) : null;
        $best_pct = null; $has_retake = false;
        foreach ($att_list as $aa) {
            if ($aa['status'] === 'graded' && $aa['max_score'] > 0) {
                $p = round(100 * (float)$aa['score'] / (float)$aa['max_score']);
                if ($best_pct === null || $p > $best_pct) $best_pct = $p;
            }
            if ($aa['attempt_label'] === 'poprawa') $has_retake = true;
        }
        if (!$att_list) {
            $stage = '<span class="text-body-secondary small">Nie rozpoczął</span>';
        } elseif ($last_att['status'] === 'in_progress') {
            $stage = '<span class="badge text-bg-info">W trakcie</span>';
        } elseif ($last_att['status'] === 'submitted' && (int)$last_att['needs_review']) {
            $stage = '<span class="badge text-bg-warning">Oczekuje na ocenę</span>';
        } elseif ($last_att['status'] === 'graded' || $last_att['status'] === 'submitted') {
            $p = $last_att['max_score'] > 0 ? round(100*(float)$last_att['score']/(float)$last_att['max_score']) : 0;
            $pass_thr = ($last_att['attempt_label'] === 'poprawa') ? min(100, $dt_pass + 20) : $dt_pass;
            $ok = $pass_thr === 0 || $p >= $pass_thr;
            $stage = $ok
                ? '<span class="badge text-bg-success">Zaliczono</span>'
                : '<span class="badge text-bg-danger">Nie zaliczono</span>';
        } else {
            $stage = '<span class="text-muted small">—</span>';
        }
      ?>
      <tr>
        <td><?= h($ds['name']) ?></td>
        <td class="text-center"><?= $stage ?></td>
        <td class="text-center">
          <?php if ($best_pct !== null): ?>
            <strong><?= $best_pct ?>%</strong>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="text-center"><?= count($att_list) ?: '<span class="text-muted">0</span>' ?></td>
        <td class="text-center">
          <?php if ($has_retake): ?><i class="bi bi-arrow-repeat text-warning" title="Pisał poprawę" aria-label="Pisał poprawę"></i><?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
