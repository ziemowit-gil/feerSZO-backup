<?php /* ═══════════════════════ TAB: NIEOBECNOŚCI ═══════════════════════ */ ?>
<?php
  $absences = k30_ti_course_tracks_attendance((int)$cur_course) ? db_all(
    "SELECT a.session_id, a.client_id, a.cancelled, a.cancel_reason, a.cancelled_by, a.cancelled_at,
            a.no_show, a.no_show_billing, a.no_show_reason,
            s.lesson_date, s.time_from, s.topic, cl.name AS client_name
     FROM k30_ti_attendance a
     JOIN k30_ti_sessions s ON s.id=a.session_id
     JOIN k30_clients cl ON cl.id=a.client_id
     WHERE s.course_id=? AND s.status IN ('held','individual_change')
       AND COALESCE(a.attended,0)=0 AND COALESCE(a.cancel_pending,0)=0
     ORDER BY s.lesson_date DESC, s.time_from DESC, cl.name COLLATE NOCASE",
    [$cur_course]) : [];
  $abs_unexcused = array_filter($absences, fn($r) => (int)($r['cancelled'] ?? 0) === 0 && (int)($r['no_show'] ?? 0) === 0);
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-person-x me-2"></i>Nieobecności</span>
    <span class="badge bg-secondary"><?= count($abs_unexcused) ?> nieusprawiedliwionych</span>
    <span class="text-body-secondary small ms-auto">Usprawiedliwiona nieobecność nie jest liczona do ceny.</span>
  </div>
  <?php if (!$absences): ?>
  <div class="card-body text-body-secondary py-3"><i class="bi bi-check-circle me-1 text-success"></i>Brak nieobecności na odbytych lekcjach.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <caption class="visually-hidden">Lista nieobecności kursantów</caption>
      <thead class="table-light">
        <tr><th>Data</th><th>Kursant</th><th>Temat</th><th>Status</th><th>Powód / kto</th><th class="text-end">Akcja</th></tr>
      </thead>
      <tbody>
        <?php foreach ($absences as $ab):
          $excused  = (int)($ab['cancelled'] ?? 0) === 1;
          $no_show    = !$excused && (int)($ab['no_show'] ?? 0) === 1;
          $ns_bill    = ($ab['no_show_billing'] ?? 'full') === '1h' ? '1 godz.' : 'cała lekcja';
          $ns_reason  = $ab['no_show_reason'] ?? '';
          $lbl = h($ab['client_name']) . ' — ' . date('d.m.Y', strtotime($ab['lesson_date'])); ?>
        <tr>
          <td class="text-nowrap small">
            <?= h(date('d.m.Y', strtotime($ab['lesson_date']))) ?>
            <?php if ($ab['time_from']): ?><span class="text-body-secondary"><?= h(substr((string)$ab['time_from'],0,5)) ?></span><?php endif; ?>
          </td>
          <td class="fw-semibold"><?= h($ab['client_name']) ?></td>
          <td class="small text-body-secondary" style="max-width:220px"><?= $ab['topic'] ? h($ab['topic']) : '—' ?></td>
          <td>
            <?php if ($no_show): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-dash-circle me-1"></i>nie pojawił się</span>
            <?php elseif ($excused): ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check2-circle me-1"></i>usprawiedliwiona</span>
            <?php else: ?>
            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-x-circle me-1"></i>nieusprawiedliwiona</span>
            <?php endif; ?>
          </td>
          <td class="small text-body-secondary" style="max-width:220px">
            <?php if ($no_show): ?>
            Rozliczenie: <?= h($ns_bill) ?>
            <?php if ($ns_reason): ?><div><?= h($ns_reason) ?></div><?php endif; ?>
            <?php if ($ab['cancelled_by']): ?><div class="text-body-tertiary"><?= h($ab['cancelled_by']) ?></div><?php endif; ?>
            <?php elseif ($excused): ?>
            <?= $ab['cancel_reason'] ? h($ab['cancel_reason']) : '—' ?>
            <?php if ($ab['cancelled_by']): ?><div class="text-body-tertiary"><?= h($ab['cancelled_by']) ?></div><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($no_show): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć oznaczenie? Udział uczestnika zostanie przywrócony.')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="restore_attendee">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="session_id" value="<?= (int)$ab['session_id'] ?>">
              <input type="hidden" name="client_id" value="<?= (int)$ab['client_id'] ?>">
              <button class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-arrow-counterclockwise me-1"></i>Cofnij</button>
            </form>
            <?php elseif ($excused): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć usprawiedliwienie? Nieobecność znów będzie nieusprawiedliwiona.')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="unexcuse_absence">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="session_id" value="<?= (int)$ab['session_id'] ?>">
              <input type="hidden" name="client_id" value="<?= (int)$ab['client_id'] ?>">
              <button class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-arrow-counterclockwise me-1"></i>Cofnij</button>
            </form>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                    onclick="dydOpenExcuse(<?= (int)$ab['session_id'] ?>, <?= (int)$ab['client_id'] ?>, <?= htmlspecialchars(json_encode($lbl), ENT_QUOTES) ?>)">
              <i class="bi bi-check2-circle me-1"></i>Usprawiedliw
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: usprawiedliwienie nieobecności -->
<div class="modal fade" id="excuseAbsenceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><form method="post" class="modal-content">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="excuse_absence">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" id="ex_sid" value="">
    <input type="hidden" name="client_id"  id="ex_cid" value="">
    <div class="modal-header">
      <h5 class="modal-title"><i class="bi bi-check2-circle text-success me-2"></i>Usprawiedliw nieobecność</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="mb-2">Nieobecność: <strong id="ex_label"></strong></p>
      <p class="text-body-secondary small mb-2">Usprawiedliwiona nieobecność nie zostanie policzona do ceny. Powód jest opcjonalny.</p>
      <label class="form-label fw-semibold" for="ex_reason">Powód <span class="text-body-secondary fw-normal">(opc.)</span></label>
      <textarea class="form-control" id="ex_reason" name="reason" rows="2" placeholder="np. choroba (zwolnienie lekarskie)…"></textarea>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Usprawiedliw</button>
    </div>
  </form></div>
</div>
