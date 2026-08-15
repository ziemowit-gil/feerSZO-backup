<?php /* ═══════════════════════ TAB: LEKCJE ═══════════════════════ */ ?>
<?php
// SMS plan tygodnia — podgląd
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
$_sms_enabled = function_exists('sms_is_enabled') && sms_is_enabled();
$_sms_preview = '';
$_sms_recip   = 0;
if ($_sms_enabled && $cur_course) {
    $mon = date('Y-m-d', strtotime('monday this week'));
    $sun = date('Y-m-d', strtotime('sunday this week'));
    $_week_s = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, c.name AS course_name
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.course_id=? AND s.lesson_date BETWEEN ? AND ?
         ORDER BY s.lesson_date, s.time_from",
        [$cur_course, $mon, $sun]
    );
    $_sms_preview = ti_build_week_sms($_week_s);
    // Policz odbiorców
    $_sms_rows = db_all(
        "SELECT a.notify_sms_lessons, a.is_minor, a.parent_notify_lessons, a.guardian_phone
         FROM k30_ti_enrollments e
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1
         WHERE e.course_id=? AND e.status='active'",
        [$cur_course]
    );
    foreach ($_sms_rows as $_sr) {
        if (!empty($_sr['notify_sms_lessons'])) $_sms_recip++;
        if (!empty($_sr['is_minor']) && !empty($_sr['parent_notify_lessons']) && !empty($_sr['guardian_phone'])) $_sms_recip++;
    }
}
?>
<?php if ($pending_cancel_total > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
  <i class="bi bi-hourglass-split flex-shrink-0" aria-hidden="true"></i>
  <span><strong><?= $pending_cancel_total ?></strong> <?= $pending_cancel_total === 1 ? 'prośba' : 'prośby' ?> o odwołanie udziału czeka na Twoje potwierdzenie — przy odpowiednich lekcjach poniżej.</span>
</div>
<?php endif; ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-calendar-week me-2"></i>Lekcje</span>
    <?php if ($pending_cancel_total > 0): ?><span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><?= $pending_cancel_total ?></span><?php endif; ?>
    <div class="ms-auto d-flex gap-2">
      <div class="dropdown">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-three-dots me-1"></i>Więcej
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header">Dodaj lekcje</h6></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addSeries">
            <i class="bi bi-calendar-plus me-2"></i>Seria lekcji
          </a></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addRecurring">
            <i class="bi bi-arrow-repeat me-2"></i>Zajęcia stałe (cykliczne)
          </a></li>
          <?php if ($all_sessions): ?>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header">Raporty PDF</h6></li>
          <li><a class="dropdown-item" href="attendance_pdf.php?course_id=<?= $cur_course ?>">
            <i class="bi bi-table me-2"></i>Lista obecności (cały kurs)
          </a></li>
          <?php
            $prev  = date('Y-m', strtotime('-1 month'));
            $prev2 = date('Y-m', strtotime('-2 months'));
          ?>
          <li><a class="dropdown-item" href="attendance_monthly.php?course_id=<?= $cur_course ?>&month=<?= date('Y-m') ?>">
            <i class="bi bi-calendar-month me-2"></i>Raport bieżący (<?= date('Y-m') ?>)
          </a></li>
          <li><a class="dropdown-item" href="attendance_monthly.php?course_id=<?= $cur_course ?>&month=<?= $prev ?>">
            <i class="bi bi-calendar-month me-2"></i>Raport <?= $prev ?>
          </a></li>
          <li><a class="dropdown-item" href="self_work.php?month=<?= date('Y-m') ?>">
            <i class="bi bi-person-workspace me-2"></i>Praca własna (bieżący)
          </a></li>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header">Kalendarz</h6></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#dydCalModal">
            <i class="bi bi-calendar3 me-2"></i>Podgląd kalendarza
          </a></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#dydCalSubModal">
            <i class="bi bi-calendar-check me-2"></i>Subskrybuj / pobierz
          </a></li>
          <li><a class="dropdown-item" href="plan_print.php?instructor_id=<?= $uid ?>" target="_blank">
            <i class="bi bi-printer me-2"></i>Wydruk planu tygodniowego
          </a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li>
            <?php if ($_sms_enabled): ?>
            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#dydSmsPreviewModal">
              <i class="bi bi-chat-left-text me-2"></i>SMS z planem do grupy
            </a>
            <?php else: ?>
            <span class="dropdown-item text-body-secondary" title="SMS nieaktywny">
              <i class="bi bi-chat-left-text me-2"></i>SMS z planem do grupy
            </span>
            <?php endif; ?>
          </li>
        </ul>
      </div>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addL">
        <i class="bi bi-plus-lg me-1"></i>Dodaj lekcję
      </button>
    </div>
    <?php if ($sessions): ?>
    <div class="w-100">
      <input type="search" class="form-control form-control-sm" placeholder="Szukaj lekcji (data, temat, status…)"
             aria-label="Filtruj lekcje" data-dyd-filterbox="dyd-list-lekcje">
    </div>
    <?php endif; ?>
  </div>

  <!-- Legenda statusów lekcji -->
  <?php $_sdesc = [
    'planned'           => 'zaplanowana, jeszcze się nie odbyła',
    'held'              => 'odbyła się z pełną grupą',
    'individual_change' => 'odbyła się, ale ze zmienionym składem uczestników',
    'remote_material'   => 'praca własna prowadzącego — bez listy obecności, liczona do rozliczenia',
    'cancelled'         => 'odwołana — nie jest liczona do rozliczenia',
  ]; ?>
  <div class="px-3 pt-2 pb-1 border-bottom">
    <details>
      <summary class="d-inline-flex align-items-center gap-1 text-body-secondary small py-1" style="cursor:pointer;list-style:none">
        <i class="bi bi-info-circle" aria-hidden="true"></i> Objaśnienia statusów
      </summary>
      <div class="d-flex flex-wrap gap-2 py-2">
        <?php foreach ($STATUS as $_sk => $_sv): if ($_sk === 'draft') continue; ?>
        <span class="d-inline-flex align-items-center gap-1 small"
              title="<?= h($_sdesc[$_sk] ?? '') ?>"
              data-bs-toggle="tooltip">
          <span class="rounded-circle flex-shrink-0" style="width:9px;height:9px;background:<?= h($_sv['color']) ?>;display:inline-block"></span>
          <strong style="color:<?= h($_sv['color']) ?>"><?= h($_sv['label']) ?></strong>
        </span>
        <?php endforeach; ?>
      </div>
    </details>
  </div>

  <?php
    $_today  = date('Y-m-d');
    $_days_pl = ['Nd','Pn','Wt','Śr','Cz','Pt','So'];
    $_mon_pl  = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
    $_grouped = [];
    foreach ($sessions as $_sx) {
        $_mk = date('Y-m', strtotime($_sx['lesson_date']));
        $_grouped[$_mk][] = $_sx;
    }
  ?>
  <?php if ($recurring_rules): ?>
  <div class="mb-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="fw-semibold small"><i class="bi bi-arrow-repeat text-primary me-1"></i>Zajęcia stałe</span>
      <span class="badge bg-primary rounded-pill"><?= count($recurring_rules) ?></span>
    </div>
    <div class="row g-2">
      <?php foreach ($recurring_rules as $rr):
        $rr_dow = ['Nd','Pn','Wt','Śr','Cz','Pt','So'][(int)date('w', strtotime((string)$rr['date_from']))];
        $rr_upcoming = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE series_id=? AND lesson_date >= date('now') AND status='planned'", [(int)$rr['id']])['n'] ?? 0);
      ?>
      <div class="col-md-6">
        <div class="card card-body py-2 px-3 border">
          <div class="d-flex align-items-start gap-2">
            <div class="flex-grow-1">
              <div class="fw-semibold small"><?= h($rr['topic'] ?: '—') ?></div>
              <div class="text-body-secondary small">
                Co <?= (int)$rr['interval_weeks'] ?> tyg. · <?= $rr_dow ?>
                <?= $rr['time_from'] ? ' · ' . h($rr['time_from']) . '–' . h($rr['time_to']) : '' ?>
                · <?= h($rr['date_from']) ?> → <?= h($rr['date_to']) ?>
              </div>
              <div class="text-body-secondary small"><?= $rr_upcoming ?> nadchodzących lekcji</div>
            </div>
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-three-dots-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <form method="post" onsubmit="return confirm('Usunąć tylko regułę (lekcje pozostają)?')">
                    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                    <input type="hidden" name="_op" value="delete_recurring_rule">
                    <input type="hidden" name="_tab" value="lekcje">
                    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                    <input type="hidden" name="rule_id" value="<?= (int)$rr['id'] ?>">
                    <button type="submit" class="dropdown-item">
                      <i class="bi bi-x-circle me-2 text-warning"></i>Usuń regułę (lekcje zostają)
                    </button>
                  </form>
                </li>
                <li>
                  <form method="post" onsubmit="return confirm('Usunąć regułę ORAZ nadchodzące zaplanowane lekcje z tej serii?')">
                    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                    <input type="hidden" name="_op" value="delete_recurring_rule">
                    <input type="hidden" name="_tab" value="lekcje">
                    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                    <input type="hidden" name="rule_id" value="<?= (int)$rr['id'] ?>">
                    <input type="hidden" name="del_future" value="1">
                    <button type="submit" class="dropdown-item text-danger">
                      <i class="bi bi-trash me-2"></i>Usuń regułę + nadchodzące lekcje
                    </button>
                  </form>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div id="dyd-list-lekcje">
    <?php if (!$sessions): ?>
    <div class="text-body-secondary py-4 text-center">
      <i class="bi bi-calendar-x fs-2 d-block mb-2 opacity-50"></i>
      Brak lekcji. Kliknij „Dodaj lekcję", aby utworzyć pierwszą.
    </div>
    <?php endif; ?>
    <div class="dyd-filter-empty text-body-secondary py-3" style="display:none">Brak lekcji pasujących do wyszukiwania.</div>

    <?php foreach ($_grouped as $_mk => $_gsessions): ?>
    <?php $_mdt = \DateTime::createFromFormat('Y-m', $_mk); ?>
    <div class="d-flex align-items-center gap-2 mt-3 mb-2">
      <span class="fw-semibold text-body-secondary small text-uppercase ls-wide" style="letter-spacing:.06em">
        <?php $mnum=(int)$_mdt->format('n'); echo $_mon_pl[$mnum].' '.$_mdt->format('Y'); ?>
      </span>
      <hr class="flex-grow-1 my-0" style="border-color:var(--bs-border-color)">
      <span class="badge bg-secondary-subtle text-secondary-emphasis small"><?= count($_gsessions) ?></span>
    </div>

    <?php foreach ($_gsessions as $s):
      $st      = $STATUS[$s['status']] ?? $STATUS['planned'];
      $sdate   = strtotime($s['lesson_date']);
      $is_past = $s['lesson_date'] < $_today;
      $is_today= $s['lesson_date'] === $_today;
      $dow     = $_days_pl[(int)date('w', $sdate)];
      $pending = db_all("SELECT a.client_id, cl.name, a.cancel_reason FROM k30_ti_attendance a JOIN k30_clients cl ON cl.id=a.client_id WHERE a.session_id=? AND a.cancel_pending=1 ORDER BY cl.name", [(int)$s['id']]);
      $resch   = k30_ti_reschedule_pending_for_session((int)$s['id']);
      $has_alert = $pending || $resch;
      $att_total  = (int)$s['total_count'];
      $att_present= (int)$s['attended_count'];
      $no_students= $att_total === 0 && $s['status'] === 'planned';
    ?>
    <div class="card mb-2 border-0 shadow-sm overflow-hidden dyd-lesson-card <?= $is_past && $s['status']==='planned' ? 'opacity-75' : '' ?>"
         data-filter-item="1"
         style="<?= $is_today ? 'box-shadow:0 0 0 2px #2563eb40!important' : '' ?>">
      <div class="d-flex" style="border-left:4px solid <?= h($st['color']) ?>">

        <!-- Kolumna daty -->
        <div class="d-flex flex-column align-items-center justify-content-start text-center px-3 py-3 flex-shrink-0"
             style="min-width:64px;background:<?= h($st['bg']) ?>">
          <span class="fw-bold lh-1" style="font-size:1.45rem;color:<?= h($st['color']) ?>"><?= date('d', $sdate) ?></span>
          <span class="small text-muted lh-1 mt-1"><?= $dow ?></span>
          <span class="small text-muted lh-1"><?= $_mon_pl[(int)date('n',$sdate)] ?></span>
          <?php if ($is_today): ?>
          <span class="badge mt-2 px-1 py-0" style="font-size:.6rem;background:#2563eb;color:#fff">dziś</span>
          <?php endif; ?>
        </div>

        <!-- Treść karty -->
        <div class="flex-grow-1 px-3 py-3 min-width-0">

          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <?php if ($s['time_from']): ?>
            <span class="fw-semibold" style="font-size:.95rem">
              <i class="bi bi-clock text-primary me-1" style="font-size:.8rem"></i><?= h(substr((string)$s['time_from'],0,5)) ?><?= $s['time_to'] ? '–'.h(substr((string)$s['time_to'],0,5)) : '' ?>
              <?php if ($s['duration_min']): ?><span class="text-body-secondary fw-normal small ms-1"><?= (int)$s['duration_min'] ?> min</span><?php endif; ?>
            </span>
            <?php endif; ?>
            <span class="badge" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>44;font-size:.75rem"><?= h($st['label']) ?></span>
            <?php if ($no_students): ?>
            <span class="badge text-bg-danger" style="font-size:.72rem"><i class="bi bi-exclamation-triangle-fill me-1"></i>Brak kursantów</span>
            <?php endif; ?>
            <div class="ms-auto d-flex align-items-center gap-2 flex-shrink-0">
              <?php if ($has_alert): ?>
              <span class="badge text-bg-warning" style="font-size:.7rem" title="Oczekujące prośby"><i class="bi bi-exclamation-circle"></i> <?= count($pending) + count($resch) ?></span>
              <?php endif; ?>
              <?php if ($s['status'] === 'remote_material' || (int)($course['track_attendance'] ?? 1) === 0): ?>
              <span class="text-body-secondary small" title="<?= $s['status']==='remote_material' ? 'Praca własna prowadzącego — nie liczymy obecności ani nieobecności' : 'Frekwencja wyłączona dla tego kursu' ?>"><i class="bi bi-person-workspace me-1"></i>bez frekwencji</span>
              <?php elseif ($att_total > 0): ?>
              <span class="d-flex align-items-center gap-1 text-body-secondary small">
                <i class="bi bi-people"></i>
                <span class="fw-semibold <?= $att_present===$att_total&&$s['status']!=='planned'?'text-success':'' ?>"><?= $att_present ?></span><span>/<?= $att_total ?></span>
              </span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($s['topic'])): ?>
          <div class="text-truncate mb-1" style="font-size:.9rem"><?= h($s['topic']) ?></div>
          <?php endif; ?>
          <?php if (!empty($s['has_homework']) || !empty($s['self_prep_remote'])): ?>
          <div class="d-flex flex-wrap gap-1 mb-1">
            <?php if (!empty($s['has_homework'])): ?>
            <span class="badge text-bg-warning" style="font-size:.72rem"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Zadanie domowe</span>
            <?php endif; ?>
            <?php if (!empty($s['self_prep_remote']) && $s['status'] !== 'remote_material'): ?>
            <span class="badge" style="background:#CCFBF1;color:#0694A2;border:1px solid #0694A244;font-size:.72rem"><i class="bi bi-laptop me-1" aria-hidden="true"></i>Praca własna prowadzącego</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($pending): ?>
          <div class="rounded border border-warning-subtle bg-warning-subtle px-3 py-2 mb-2 small">
            <div class="fw-semibold mb-1 text-warning-emphasis"><i class="bi bi-hourglass-split me-1"></i>Prośby o odwołanie udziału</div>
            <?php foreach ($pending as $pr): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <span class="text-body-emphasis"><?= h($pr['name']) ?><?php if ($pr['cancel_reason']): ?> <span class="text-body-secondary">— <?= h($pr['cancel_reason']) ?></span><?php endif; ?></span>
              <div class="ms-auto d-flex gap-1">
                <form method="post" class="d-inline" onsubmit="return confirm('Potwierdzić odwołanie udziału tego kursanta?')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="confirm_cancel">
                  <input type="hidden" name="_tab" value="lekcje">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="client_id" value="<?= (int)$pr['client_id'] ?>">
                  <button class="btn btn-sm btn-danger py-0 px-2"><i class="bi bi-check-lg me-1"></i>Potwierdź</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="reject_cancel">
                  <input type="hidden" name="_tab" value="lekcje">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="client_id" value="<?= (int)$pr['client_id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-x-lg me-1"></i>Odrzuć</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($resch): ?>
          <div class="rounded border border-info-subtle bg-info-subtle px-3 py-2 mb-2 small">
            <div class="fw-semibold mb-1 text-info-emphasis"><i class="bi bi-calendar2-range me-1"></i>Propozycje zmiany terminu</div>
            <?php foreach ($resch as $rq):
              $rqNew = date('d.m.Y', strtotime($rq['proposed_date'])) . ($rq['proposed_from'] ? ' '.substr((string)$rq['proposed_from'],0,5).($rq['proposed_to']?'–'.substr((string)$rq['proposed_to'],0,5):'') : ''); ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <span><?= h($rq['client_name'] ?: $rq['requested_by']) ?> → <strong><?= h($rqNew) ?></strong><?php if ($rq['reason']): ?> <span class="text-body-secondary">— <?= h($rq['reason']) ?></span><?php endif; ?></span>
              <div class="ms-auto d-flex gap-1">
                <form method="post" class="d-inline" onsubmit="return confirm('Zaakceptować propozycję? Termin lekcji zostanie zmieniony.')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="reschedule_accept">
                  <input type="hidden" name="_tab" value="lekcje">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="request_id" value="<?= (int)$rq['id'] ?>">
                  <button class="btn btn-sm btn-success py-0 px-2"><i class="bi bi-check-lg me-1"></i>Akceptuj</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="reschedule_reject">
                  <input type="hidden" name="_tab" value="lekcje">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="request_id" value="<?= (int)$rq['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-x-lg me-1"></i>Odrzuć</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="d-flex align-items-center gap-2 pt-2 flex-wrap" style="border-top:1px solid var(--bs-border-color-translucent)">
            <?php if ($s['status'] !== 'remote_material'): ?>
            <button type="button" class="btn btn-sm btn-primary"
                    data-bs-toggle="modal" data-bs-target="#attL<?= (int)$s['id'] ?>">
              <i class="bi bi-people me-1"></i>Obecność
            </button>
            <?php endif; ?>
            <?php $_meet_url = trim((string)($s['meeting_url'] ?: ($s['default_meeting_url'] ?? ''))); ?>
            <?php if (!$is_past && $s['status'] === 'planned' && $_meet_url): ?>
            <a href="<?= h($_meet_url) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener noreferrer">
              <i class="bi bi-camera-video-fill me-1"></i>Dołącz do lekcji
            </a>
            <?php endif; ?>
            <?php if (($is_past || $is_today) && $s['status'] === 'planned'): ?>
            <button type="button" class="btn btn-sm btn-success"
                    onclick="wizOpenExt(<?= (int)$s['id'] ?>)">
              <i class="bi bi-journal-text me-1"></i>Uzupełnij dokumentację
            </button>
            <?php endif; ?>
            <div class="ms-auto d-flex gap-1">
              <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                      data-bs-toggle="modal" data-bs-target="#edL<?= (int)$s['id'] ?>"
                      title="Edytuj lekcję">
                <i class="bi bi-pencil"></i>
              </button>
              <?php if ($s['status'] !== 'cancelled' && !$is_past): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                      title="Przenieś lekcję na inny termin"
                      data-bs-toggle="tooltip" data-bs-placement="top"
                      onclick="dydOpenReschedule(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode(date('d.m.Y',$sdate).($s['time_from']?' '.h($s['time_from']):'')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((string)$s['lesson_date']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((string)($s['time_from']??'')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((string)($s['time_to']??'')), ENT_QUOTES) ?>); return false">
                <i class="bi bi-calendar2-range"></i>
              </button>
              <?php endif; ?>
              <?php if ($s['status'] === 'cancelled'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Przywrócić lekcję?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="uncancel_session">
                <input type="hidden" name="_tab" value="lekcje">
                <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-2"
                        title="Przywróć lekcję (zmień status z odwołana na zaplanowana)"
                        data-bs-toggle="tooltip" data-bs-placement="top">
                  <i class="bi bi-arrow-counterclockwise"></i>
                </button>
              </form>
              <?php elseif (!$is_past): ?>
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                      title="Odwołaj lekcję — lekcja nie zostanie policzona do rozliczenia"
                      data-bs-toggle="tooltip" data-bs-placement="top"
                      onclick="dydOpenCancelSession(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode(date('d.m.Y',$sdate).($s['time_from']?' '.h($s['time_from']):'')), ENT_QUOTES) ?>); return false">
                <i class="bi bi-x-circle"></i>
              </button>
              <?php endif; ?>
              <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/lesson.php?id=<?= (int)$s['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-2"
                 title="Szczegóły lekcji — obecność, oceny, notatki"
                 data-bs-toggle="tooltip" data-bs-placement="top">
                <i class="bi bi-arrow-right-circle"></i>
              </a>
            </div>
          </div>

        </div><!-- /treść -->
      </div><!-- /d-flex accent -->
    </div><!-- /card -->
    <?php endforeach; ?>
    <?php endforeach; // grouped months ?>
  </div><!-- /dyd-list-lekcje -->
</div>

<?php
// Dane do kreatora (wizOpenExt) — zaplanowane lekcje z dziś lub przeszłości
$_ext_for_wiz = array_filter($sessions, fn($s) => $s['status'] === 'planned' && $s['lesson_date'] <= $_today);
$_wiz_ext_data = [];
foreach ($_ext_for_wiz as $_we) {
    $_watt = k30_ti_session_attendance((int)$_we['id']);
    $_wiz_ext_data[(int)$_we['id']] = [
        'label'     => ($_we['time_from'] ? substr($_we['time_from'],0,5).'–'.substr($_we['time_to']??'',0,5).' · ' : '') . ($_we['course_name'] ?? ''),
        'topic'     => (string)($_we['topic'] ?? ''),
        'attendees' => array_values(array_map(fn($a) => [
            'id'        => (int)$a['client_id'],
            'name'      => (string)($a['client_name'] ?? ''),
            'attended'  => (int)($a['attended'] ?? 0),
            'cancelled' => (int)($a['cancelled'] ?? 0),
            'pending'   => (int)($a['cancel_pending'] ?? 0),
            'no_show'   => (int)($a['no_show'] ?? 0),
        ], $_watt)),
    ];
}
?>
<script>window.DYD_EXT_SESSIONS = <?= json_encode($_wiz_ext_data, JSON_UNESCAPED_UNICODE) ?>;</script>

<!-- Modale: dodawanie + edycja lekcji -->
<div class="modal fade" id="addL" tabindex="-1" aria-labelledby="addL_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $lessonFormHtml(null, 'addL'); ?></div></div>
</div>
<?php foreach ($sessions as $s): ?>
<div class="modal fade" id="edL<?= (int)$s['id'] ?>" tabindex="-1" aria-labelledby="edL<?= (int)$s['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $lessonFormHtml($s, 'edL'.(int)$s['id']); ?></div></div>
</div>
<div class="modal fade" id="attL<?= (int)$s['id'] ?>" tabindex="-1" aria-labelledby="attL<?= (int)$s['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $attFormHtml($s, k30_ti_session_attendance((int)$s['id']), 'attL'.(int)$s['id']); ?></div></div>
</div>
<?php endforeach; ?>

<!-- Modal: seria lekcji -->
<div class="modal fade" id="addSeries" tabindex="-1" aria-labelledby="addSeries_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="save_lesson_series">
      <input type="hidden" name="_tab" value="lekcje">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="addSeries_t"><i class="bi bi-calendar-plus me-2"></i>Seria lekcji</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-body-secondary small">Utworzy kilka lekcji powtarzających się co wybraną liczbę tygodni, od daty startowej.</p>
        <div class="mb-2">
          <label class="form-label fw-semibold" for="series_date">Data startowa <span class="text-danger">*</span></label>
          <input type="date" class="form-control" id="series_date" name="lesson_date" required value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="row g-2">
          <div class="col-6 mb-2">
            <label class="form-label" for="series_from">Od</label>
            <select class="form-select" id="series_from" name="time_from"><?= ti_time_options('') ?></select>
          </div>
          <div class="col-6 mb-2">
            <label class="form-label" for="series_to">Do</label>
            <select class="form-select" id="series_to" name="time_to"><?= ti_time_options('') ?></select>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="series_topic">Temat <span class="text-body-secondary small">(opc., wspólny)</span></label>
          <input type="text" class="form-control" id="series_topic" name="topic" placeholder="np. Zajęcia cykliczne">
        </div>
        <div class="row g-2">
          <div class="col-6 mb-2">
            <label class="form-label" for="series_weeks">Co ile tygodni</label>
            <input type="number" class="form-control" id="series_weeks" name="weeks" min="1" max="8" value="1">
          </div>
          <div class="col-6 mb-2">
            <label class="form-label" for="series_count">Liczba lekcji</label>
            <input type="number" class="form-control" id="series_count" name="count" min="1" max="52" value="8">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i>Utwórz serię</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal: zajęcia stałe -->
<div class="modal fade" id="addRecurring" tabindex="-1" aria-labelledby="addRecurring_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="save_recurring_rule">
      <input type="hidden" name="_tab" value="lekcje">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="addRecurring_t"><i class="bi bi-arrow-repeat me-2"></i>Zajęcia stałe</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-body-secondary small">Definiuje cykliczne zajęcia — wzorzec jest zapisany i widoczny w widoku kursu. Lekcje są tworzone automatycznie na wskazany zakres.</p>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label fw-semibold" for="rec_date_from">Od <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="rec_date_from" name="date_from" required value="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold" for="rec_date_to">Do <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="rec_date_to" name="date_to" required value="<?= h(date('Y-m-d', strtotime('+3 months'))) ?>">
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label" for="rec_time_from">Godzina od</label>
            <select class="form-select" id="rec_time_from" name="time_from"><?= ti_time_options('') ?></select>
          </div>
          <div class="col-6">
            <label class="form-label" for="rec_time_to">Godzina do</label>
            <select class="form-select" id="rec_time_to" name="time_to"><?= ti_time_options('') ?></select>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label" for="rec_interval">Co ile tygodni</label>
            <input type="number" class="form-control" id="rec_interval" name="interval_weeks" min="1" max="8" value="1">
          </div>
          <div class="col-6 d-flex align-items-end">
            <span class="text-body-secondary small" id="rec_count_hint"></span>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="rec_topic">Temat <span class="text-body-secondary small">(opc., wspólny)</span></label>
          <input type="text" class="form-control" id="rec_topic" name="topic" placeholder="np. Ćwiczenia praktyczne">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Zapisz i utwórz lekcje</button>
      </div>
    </form>
  </div></div>
</div>
<script>
(function(){
  var df=document.getElementById('rec_date_from'), dt=document.getElementById('rec_date_to'),
      iv=document.getElementById('rec_interval'), hint=document.getElementById('rec_count_hint');
  function upd(){
    var f=df?df.value:'',t=dt?dt.value:'',iw=parseInt(iv?iv.value:1)||1;
    if(f&&t&&t>=f){
      var ms=new Date(t)-new Date(f), d=Math.floor(ms/86400000)+1;
      var n=Math.ceil(d/(iw*7));
      hint.textContent='≈'+n+' lekcji';
    }else hint.textContent='';
  }
  [df,dt,iv].forEach(function(el){if(el)el.addEventListener('change',upd);});
})();
</script>

<!-- Modal: widok kalendarza lekcji -->
<?php if ($all_sessions):
  $cal_by_date = [];
  foreach ($all_sessions as $s) { $cal_by_date[(string)$s['lesson_date']][] = $s; }
  $cal_months = []; foreach (array_keys($cal_by_date) as $ld) { if ($ld !== '') $cal_months[substr($ld,0,7)] = true; }
  $cal_months = array_keys($cal_months); sort($cal_months);
  $months_full = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
  $wd_short = ['Pn','Wt','Śr','Cz','Pt','So','Nd']; $wd_full = ['Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota','Niedziela'];
  $today_ymd = date('Y-m-d');
?>
<div class="modal fade" id="dydCalModal" tabindex="-1" aria-labelledby="dydCalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="dydCalTitle"><i class="bi bi-calendar3 me-2"></i>Kalendarz lekcji — <?= h($course['name'] ?? '') ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <?php foreach ($cal_months as $ym):
        $year = (int)substr($ym,0,4); $mon = (int)substr($ym,5,2);
        $daysIn = (int)date('t', mktime(0,0,0,$mon,1,$year));
        $startDow = (int)date('N', mktime(0,0,0,$mon,1,$year));
      ?>
      <table class="table table-bordered kp-cal mb-4">
        <caption class="fw-semibold text-body mb-1"><?= $months_full[$mon] ?> <?= $year ?></caption>
        <thead><tr><?php foreach ($wd_short as $i=>$w): ?><th scope="col" class="text-center small text-body-secondary" abbr="<?= h($wd_full[$i]) ?>"><?= $w ?></th><?php endforeach; ?></tr></thead>
        <tbody><tr>
          <?php
            for ($i=1;$i<$startDow;$i++) echo '<td class="kp-cal-empty" aria-hidden="true"></td>';
            $col = $startDow - 1;
            for ($day=1;$day<=$daysIn;$day++):
              $ymd = sprintf('%04d-%02d-%02d',$year,$mon,$day);
              $dl = $cal_by_date[$ymd] ?? []; $isToday = $ymd===$today_ymd;
          ?>
          <td class="kp-cal-day<?= $dl?' has-lesson':'' ?><?= $isToday?' is-today':'' ?>"<?= $isToday?' aria-current="date"':'' ?>>
            <div class="kp-cal-num <?= $isToday?'fw-bold':'' ?>"><?= $day ?></div>
            <?php foreach ($dl as $e): ?>
            <div class="kp-cal-ev" title="<?= h(($e['time_from']??'' ? substr($e['time_from'],0,5).' ' : '').($e['topic'] ?: 'Lekcja')) ?>">
              <?php if (!empty($e['time_from'])): ?><span class="fw-semibold"><?= h(substr($e['time_from'],0,5)) ?></span> <?php endif; ?><?= h($e['topic'] ?: 'Lekcja') ?>
            </div>
            <?php endforeach; ?>
          </td>
          <?php
              $col++;
              if ($col % 7 === 0 && $day < $daysIn) echo '</tr><tr>';
            endfor;
            while ($col % 7 !== 0) { echo '<td class="kp-cal-empty" aria-hidden="true"></td>'; $col++; }
          ?>
        </tr></tbody>
      </table>
      <?php endforeach; ?>
      <p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>Miesiące z lekcjami; dzisiejszy dzień jest wyróżniony.</p>
    </div>
  </div></div>
</div>
<?php endif; ?>

<?php
  $cal_tok  = k30_ti_instructor_cal_token($uid);
  $cal_base = rtrim(defined('APP_URL') ? APP_URL : '', '/')
              . '/karty30/ti/dydaktyk/ical.php?uid=' . $uid . '&t=' . $cal_tok;
  $cal_webcal = preg_replace('#^https?://#i', 'webcal://', $cal_base);
?>
<div class="modal fade" id="dydCalSubModal" tabindex="-1" aria-labelledby="dydCalSubTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="dydCalSubTitle"><i class="bi bi-calendar-check me-2"></i>Kalendarz lekcji — subskrypcja i pobranie</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="text-body-secondary small">
        Kalendarz obejmuje wszystkie Twoje lekcje (ze wszystkich prowadzonych kursów).
        Każde zdarzenie ma tytuł w formie <strong>„Lekcja — kursant"</strong>.
      </p>
      <label class="form-label fw-semibold" for="dydCalUrl">Adres kanału (URL do subskrypcji)</label>
      <div class="input-group mb-1">
        <input type="text" class="form-control" id="dydCalUrl" value="<?= h($cal_base) ?>" readonly
               onfocus="this.select()" aria-describedby="dydCalUrlHelp">
        <button type="button" class="btn btn-outline-secondary" id="dydCalCopy"
                data-copy-target="dydCalUrl"><i class="bi bi-clipboard me-1"></i>Kopiuj</button>
      </div>
      <p id="dydCalUrlHelp" class="form-text">
        Wklej ten adres w Kalendarzu Google („Inne kalendarze → Dodaj z adresu URL"),
        Apple Calendar lub Outlook, aby kalendarz aktualizował się automatycznie.
      </p>
      <div class="d-flex flex-wrap gap-2 my-3">
        <a class="btn btn-primary btn-sm" href="<?= h($cal_base) ?>">
          <i class="bi bi-download me-1"></i>Pobierz plik .ics
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h($cal_webcal) ?>">
          <i class="bi bi-calendar-plus me-1"></i>Subskrybuj (webcal)
        </a>
      </div>
      <hr>
      <div class="d-flex align-items-center flex-wrap gap-2">
        <span class="small text-body-secondary"><i class="bi bi-shield-lock me-1"></i>Adres jest prywatny — nie udostępniaj go osobom postronnym.</span>
        <form method="post" class="ms-auto" onsubmit="return confirm('Wygenerować nowy adres? Dotychczasowy link przestanie działać.')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="cal_token_reset">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Wygeneruj nowy adres
          </button>
        </form>
      </div>
    </div>
  </div></div>
</div>

<!-- Popup iCal -->
<div id="dydIcalPopup"
     role="dialog" aria-modal="true" aria-labelledby="dydIcalPopupTitle"
     tabindex="-1"
     style="display:none;position:fixed;z-index:1080;bottom:1.5rem;right:1.5rem;
            width:min(380px,calc(100vw - 2rem));
            background:#1e293b;color:#f1f5f9;
            border:2px solid #2563eb;border-radius:.6rem;
            box-shadow:0 8px 32px rgba(0,0,0,.5);">
  <div style="background:#2563eb;color:#fff;border-radius:.45rem .45rem 0 0;
              padding:.55rem 1rem;display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-calendar-plus" aria-hidden="true"></i>
    <h2 class="mb-0 fw-bold" id="dydIcalPopupTitle" style="font-size:1rem">Dodaj lekcje do kalendarza</h2>
  </div>
  <div style="padding:.9rem 1rem .5rem">
    <p style="font-size:.875rem;margin:0 0 .5rem">
      Subskrybuj kanał iCal, aby terminy Twoich lekcji pojawiały się automatycznie
      w Kalendarzu Google, Apple Calendar lub Outlooku — bez ręcznego przepisywania.
    </p>
  </div>
  <div style="padding:.5rem 1rem .8rem;display:flex;justify-content:flex-end;gap:.5rem">
    <button type="button" id="dydIcalPopupLater"
            style="background:transparent;color:#cbd5e1;border:1px solid #475569;border-radius:.375rem;
                   padding:.3rem .8rem;font-size:.875rem;cursor:pointer">Nie teraz</button>
    <button type="button" id="dydIcalPopupGo" data-bs-toggle="modal" data-bs-target="#dydCalSubModal"
            style="background:#2563eb;color:#fff;border:none;border-radius:.375rem;
                   padding:.3rem .9rem;font-weight:600;cursor:pointer;font-size:.875rem">
      <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Dodaj kalendarz
    </button>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var STORE_KEY = 'ti_ical_popup_seen_dyd';
  var popup = document.getElementById('dydIcalPopup');
  if (!popup) return;
  try { if (localStorage.getItem(STORE_KEY) === '1') return; } catch(e) {}
  var dismiss = function(){
    try { localStorage.setItem(STORE_KEY, '1'); } catch(e) {}
    popup.style.display = 'none';
  };
  setTimeout(function(){ popup.style.display = 'block'; }, 800);
  document.getElementById('dydIcalPopupLater').addEventListener('click', dismiss);
  document.getElementById('dydIcalPopupGo').addEventListener('click', dismiss);
});
</script>

<!-- Ukryty formularz akcji obecności -->
<form method="post" id="dydAttAction" class="d-none">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op"         id="daa_op"  value="">
  <input type="hidden" name="_tab"        value="lekcje">
  <input type="hidden" name="course_id"   value="<?= $cur_course ?>">
  <input type="hidden" name="session_id"  id="daa_sid" value="">
  <input type="hidden" name="client_id"   id="daa_cid" value="">
</form>

<!-- Modal: odwołanie całej lekcji -->
<div class="modal fade" id="cancelSessionModal" tabindex="-1" aria-labelledby="cancelSession_t" aria-hidden="true">
  <div class="modal-dialog"><form method="post" class="modal-content">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="cancel_session">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" id="cs_sid" value="">
    <div class="modal-header">
      <h5 class="modal-title" id="cancelSession_t"><i class="bi bi-x-circle text-danger me-2"></i>Odwołanie lekcji</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="mb-2">Lekcja: <strong id="cs_label"></strong></p>
      <p class="text-body-secondary small mb-2">Odwołana lekcja nie zostanie policzona do ceny. Podaj powód.</p>
      <label class="form-label fw-semibold" for="cs_reason">Powód odwołania</label>
      <textarea class="form-control" id="cs_reason" name="reason" rows="3" required placeholder="np. choroba prowadzącego, awaria sprzętu…"></textarea>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Odwołaj lekcję</button>
    </div>
  </form></div>
</div>

<!-- Modal: nie pojawił się na zajęciach -->
<div class="modal fade" id="dydNoShowModal" tabindex="-1" aria-labelledby="dydNoShow_t" aria-hidden="true">
  <div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op"        value="mark_no_show">
    <input type="hidden" name="_tab"       value="lekcje">
    <input type="hidden" name="course_id"  value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" id="dns_sid" value="">
    <input type="hidden" name="client_id"  id="dns_cid" value="">
    <div class="modal-header">
      <h5 class="modal-title" id="dydNoShow_t"><i class="bi bi-dash-circle text-warning me-2"></i>Nie pojawił się</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="mb-3">Uczestnik: <strong id="dns_name"></strong></p>
      <p class="text-muted small mb-3">Lekcja się odbyła, ale beneficjent nie stawił się. Wybierz sposób rozliczenia:</p>
      <div class="d-grid gap-2">
        <div class="form-check border rounded p-3">
          <input class="form-check-input" type="radio" name="no_show_billing" id="dns_full" value="full" checked>
          <label class="form-check-label w-100" for="dns_full">
            <div class="fw-semibold">Cała lekcja</div>
            <div class="text-muted small">Policz pełny czas trwania zajęć.</div>
          </label>
        </div>
        <div class="form-check border rounded p-3">
          <input class="form-check-input" type="radio" name="no_show_billing" id="dns_1h" value="1h">
          <label class="form-check-label w-100" for="dns_1h">
            <div class="fw-semibold">Tylko 1 godzina (rozpoczęta)</div>
            <div class="text-muted small">Policz 1 godzinę — minimum za stawienie się prowadzącego.</div>
          </label>
        </div>
      </div>
      <div class="mt-3">
        <label class="form-label fw-semibold" for="dns_reason">Opis sytuacji <span class="text-body-secondary fw-normal small">(opcjonalnie)</span></label>
        <textarea class="form-control" id="dns_reason" name="no_show_reason" rows="2"
                  placeholder="np. brak kontaktu, hospitalizacja, awaria dojazdu…"></textarea>
      </div>
      <div class="mt-3">
        <label class="form-label fw-semibold" for="dns_screenshot">Screenshot / dokumentacja <span class="text-body-secondary fw-normal small">(opcjonalnie, PNG/JPG/PDF, max 15 MB)</span></label>
        <input class="form-control" type="file" id="dns_screenshot" name="no_show_screenshot" accept=".png,.jpg,.jpeg,.pdf">
        <div class="form-text">Plik zostanie dołączony do maila wysyłanego do rodzica/kursanta.</div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-warning"><i class="bi bi-dash-circle me-1"></i>Oznacz: nie pojawił się</button>
    </div>
  </form></div>
</div>

<!-- Modal: zmiana terminu lekcji -->
<div class="modal fade" id="reschedSessionModal" tabindex="-1" aria-labelledby="reschedSession_t" aria-hidden="true">
  <div class="modal-dialog"><form method="post" class="modal-content">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="reschedule_session">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" id="rs_sid" value="">
    <div class="modal-header">
      <h5 class="modal-title" id="reschedSession_t"><i class="bi bi-calendar2-range text-primary me-2"></i>Zmień termin lekcji</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="text-body-secondary small mb-3">Obecny termin: <strong id="rs_label"></strong></p>
      <div class="mb-2">
        <label class="form-label fw-semibold" for="rs_date">Nowa data <span class="text-danger">*</span></label>
        <input type="date" class="form-control" id="rs_date" name="lesson_date" required>
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="rs_from">Od</label>
          <select class="form-select" id="rs_from" name="time_from"><?= ti_time_options('') ?></select>
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="rs_to">Do</label>
          <select class="form-select" id="rs_to" name="time_to"><?= ti_time_options('') ?></select>
        </div>
      </div>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="rs_notify" name="notify" value="1" checked>
        <label class="form-check-label" for="rs_notify">Powiadom uczestników e-mailem o nowym terminie</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="rs_notify_sms" name="notify_sms" value="1">
        <label class="form-check-label" for="rs_notify_sms">Dodatkowo wyślij SMS</label>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-calendar2-check me-1"></i>Zapisz nowy termin</button>
    </div>
  </form></div>
</div>

<!-- ── Modal: podgląd SMS z planem tygodnia ─────────────────────────────────── -->
<?php if ($_sms_enabled): ?>
<div class="modal fade" id="dydSmsPreviewModal" tabindex="-1" aria-labelledby="dydSmsPreviewLbl" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dydSmsPreviewLbl"><i class="bi bi-chat-left-text me-2 text-primary"></i>Podgląd SMS z planem tygodnia</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php if (!$_sms_preview): ?>
        <div class="alert alert-info py-2 mb-0"><i class="bi bi-info-circle me-1"></i>Brak zaplanowanych lekcji w bieżącym tygodniu — SMS nie zostanie wysłany.</div>
        <?php else: ?>
        <p class="small text-body-secondary mb-2">Treść wiadomości SMS (<?= mb_strlen($_sms_preview) ?> znaków):</p>
        <pre class="border rounded p-3 bg-body-tertiary" style="font-size:.85rem;white-space:pre-wrap;word-break:break-word"><?= h($_sms_preview) ?></pre>
        <p class="small text-body-secondary mt-2 mb-0">
          <i class="bi bi-people me-1"></i>Odbiorcy: <strong><?= $_sms_recip ?></strong>
          <?= $_sms_recip === 1 ? 'osoba' : ($_sms_recip < 5 ? 'osoby' : 'osób') ?>
          (kursanci + opiekunowie z włączonymi SMS o lekcjach).
        </p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <?php if ($_sms_preview && $_sms_recip > 0): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token"    value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"       value="sms_week_group">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-1"></i>Wyślij SMS (<?= $_sms_recip ?>)
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
