<?php /* ═══════════════════════ TAB: TESTY ═══════════════════════ */ ?>
<?php
  $dyd_tests    = k30_ti_tests_list($cur_course);
  $dyd_students = db_all(
      "SELECT cl.id, cl.name FROM k30_clients cl
       JOIN k30_ti_enrollments e ON e.client_id=cl.id
       WHERE e.course_id=? AND e.status='active' ORDER BY cl.name",
      [$cur_course]);
?>
<?php if (!$dyd_tests): ?>
  <div class="alert alert-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak testów w tym kursie.</div>
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
