<?php /* ═══════════════════════ TAB: LEKCJE ═══════════════════════ */ ?>
<?php
// SMS plan tygodnia — podgląd
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
$_sms_enabled = function_exists('sms_channel_ready') && sms_channel_ready();
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
<?php
  // Karta otwartej lekcji (?lesson=<id>) — potrzebuje $STATUS, $_days_pl, $uid, $cur_course
  $_days_pl = $_days_pl ?? ['Nd','Pn','Wt','Śr','Cz','Pt','So'];
  include __DIR__ . '/_lekcja_karta.php';
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-calendar-week me-2"></i>Lekcje</span>
    <?php if ($pending_cancel_total > 0): ?><span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><?= $pending_cancel_total ?></span><?php endif; ?>
    <div class="ms-auto d-flex align-items-center gap-2">
      <div class="dropdown">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-three-dots me-1"></i>Więcej
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header">Dodaj lekcje</h6></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addSeries">
            <i class="bi bi-calendar-plus me-2"></i>Seria lekcji
          </a></li>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header">Zarządzanie terminami</h6></li>
          <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#dydClearSessionsModal">
            <i class="bi bi-calendar-x me-2"></i>Wyczyść terminy grupy
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
             aria-label="Filtruj lekcje" data-dyd-filterbox="dyd-table-lekcje">
    </div>
    <?php endif; ?>
  </div>

  <!-- Legenda statusów lekcji -->
  <?php $_sdesc = [
    'planned'           => 'zaplanowana, jeszcze się nie odbyła',
    'held'              => 'odbyła się z pełną grupą',
    'individual_change' => 'odbyła się, ale ze zmienionym składem uczestników',
    'remote_material'   => 'praca prowadzącego — bez listy obecności, liczona do rozliczenia',
    'cancelled'         => 'odwołana — nie jest liczona do rozliczenia',
  ]; ?>
  <div class="px-3 pt-2 pb-2 border-bottom">
    <div class="small fw-semibold mb-1">Co znaczą statusy</div>
    <ul class="list-unstyled small mb-2">
      <?php foreach ($STATUS as $_sk => $_sv): if ($_sk === 'draft') continue; ?>
      <li class="d-flex align-items-start gap-2 py-1">
        <span class="badge flex-shrink-0" style="background:<?= h($_sv['bg']) ?>;color:<?= h($_sv['color']) ?>;border:1px solid <?= h($_sv['color']) ?>44;min-width:7.5rem"><?= h($_sv['label']) ?></span>
        <span class="text-body-secondary"><?= h($_sdesc[$_sk] ?? '') ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <div class="small fw-semibold mb-1">Ikony formy zajęć (obok statusu)</div>
    <ul class="list-unstyled small mb-0">
      <li class="py-1"><i class="bi bi-geo-alt-fill me-2" style="color:#065F46" aria-hidden="true"></i>stacjonarne — na miejscu</li>
      <li class="py-1"><i class="bi bi-camera-video-fill me-2" style="color:#1D4ED8" aria-hidden="true"></i>zdalne przez Zoom — obciąża konto Zoom (patrz „Zajętość Zoom")</li>
      <li class="py-1"><i class="bi bi-display me-2" style="color:#6B21A8" aria-hidden="true"></i>zdalne inaczej — Teams, telefon, własny link</li>
    </ul>
  </div>

  <?php
    $_today   = date('Y-m-d');
    $_days_pl = ['Nd','Pn','Wt','Śr','Cz','Pt','So'];
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


  <!-- ── Widok: tabela kompaktowa ──────────────────────────────── -->
  <div id="dyd-table-lekcje">
    <div class="dyd-filter-empty text-body-secondary py-3 px-3" style="display:none">Brak lekcji pasujących do wyszukiwania.</div>
    <?php if (!$sessions): ?>
    <div class="text-body-secondary py-4 text-center">
      <i class="bi bi-calendar-x fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
      Brak lekcji.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle mb-0" aria-label="Lista lekcji — widok tabelaryczny">
        <thead class="table-light">
          <tr>
            <th scope="col" style="width:92px">Data</th>
            <th scope="col" style="width:96px">Godziny</th>
            <th scope="col" style="width:150px">Status i forma</th>
            <th scope="col">Temat zajęć</th>
            <th scope="col" class="text-center" style="width:96px">Obecność<br><span class="fw-normal text-body-secondary" style="font-size:.66rem">obecni / wszyscy</span></th>
            <th scope="col" class="text-end"    style="width:210px">Akcje</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $_mon_names = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                       7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
        $_last_month = null;
        foreach ($sessions as $s):
          $st      = $STATUS[$s['status']] ?? $STATUS['planned'];
          $sdate   = strtotime($s['lesson_date']);
          $_mkey   = date('Y-m', $sdate);
          if ($_mkey !== $_last_month):
            $_last_month = $_mkey;
        ?>
        <tr class="table-light">
          <th colspan="6" scope="colgroup" class="fw-bold text-uppercase small py-1">
            <?= h(($_mon_names[(int)date('n', $sdate)] ?? '') . ' ' . date('Y', $sdate)) ?>
          </th>
        </tr>
        <?php endif; ?>
        <?php
          $is_past = $s['lesson_date'] < $_today;
          $is_today= $s['lesson_date'] === $_today;
          $dow     = $_days_pl[(int)date('w', $sdate)];
          $att_total  = (int)$s['total_count'];
          $att_present= (int)$s['attended_count'];
          $_mat_url   = trim((string)($s['material_url'] ?? ''));
          $_meet_url  = trim((string)($s['meeting_url'] ?: ($s['default_meeting_url'] ?? '')));
        ?>
        <tr data-filter-item="1"
            style="border-left:3px solid <?= h($st['color']) ?>;<?= $s['status']==='remote_material' ? 'background:'.$st['bg'] : '' ?>"
            class="<?= $is_past && $s['status']==='planned' ? 'opacity-75' : '' ?>">
          <td style="font-size:.82rem;line-height:1.3">
            <a href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>"
               class="text-decoration-none" aria-label="Wejdź do lekcji <?= h(date('d.m.Y', $sdate)) ?>">
              <span class="fw-semibold"><?= date('d.m', $sdate) ?></span><span class="text-body-secondary">.<?= date('y', $sdate) ?></span>
            </a>
            <span class="text-body-secondary d-block" style="font-size:.72rem"><?= $dow ?><?php if ($is_today): ?> <span class="badge px-1 py-0" style="font-size:.52rem;background:#2563eb;color:#fff">dziś</span><?php endif; ?></span>
          </td>
          <td style="font-size:.82rem;white-space:nowrap">
            <?php if ($s['time_from']): ?>
            <?= h(substr((string)$s['time_from'],0,5)) ?><?= $s['time_to'] ? '–'.h(substr((string)$s['time_to'],0,5)) : '' ?>
            <?php else: ?><span class="text-body-tertiary">—</span><?php endif; ?>
          </td>
          <td>
            <span class="badge" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>44;font-size:.68rem"><?= h($st['label']) ?></span>
            <?php $_lm2 = (string)($s['lesson_method'] ?? ''); if ($_lm2 === 'stacjonarna'): ?>
            <i class="bi bi-geo-alt-fill ms-1" title="Stacjonarna" data-bs-toggle="tooltip" style="color:#065F46;font-size:.8rem"></i>
            <?php elseif ($_lm2 === 'zdalna_zoom'): ?>
            <i class="bi bi-camera-video-fill ms-1" title="Zdalna — Zoom" data-bs-toggle="tooltip" style="color:#1D4ED8;font-size:.8rem"></i>
            <?php elseif ($_lm2 === 'zdalna_inne'): ?>
            <i class="bi bi-display ms-1" title="Zdalna — Inne" data-bs-toggle="tooltip" style="color:#6B21A8;font-size:.8rem"></i>
            <?php endif; ?>
          </td>
          <td class="text-truncate" style="max-width:0;font-size:.83rem">
            <?= !empty($s['topic']) ? h($s['topic']) : '<span class="text-body-tertiary">—</span>' ?>
          </td>
          <td class="text-center" style="font-size:.82rem">
            <?php if ($s['status'] === 'remote_material' || (int)($course['track_attendance'] ?? 1) === 0): ?>
            <span class="text-body-tertiary"><i class="bi bi-dash" aria-hidden="true"></i></span>
            <?php elseif ($att_total > 0): ?>
            <span class="<?= $att_present===$att_total&&$s['status']!=='planned'?'text-success fw-semibold':'text-body-secondary' ?>"><?= $att_present ?>/<?= $att_total ?></span>
            <?php else: ?>
            <span class="text-body-tertiary">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex justify-content-end align-items-center flex-wrap gap-1">
              <a href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-2"
                 aria-label="Wejdź do lekcji <?= h(date('d.m.Y', $sdate)) ?>">
                <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Wejdź
              </a>
              <?php if ($s['status'] !== 'remote_material'): ?>
              <button type="button" class="btn btn-sm btn-primary py-0 px-2"
                      data-bs-toggle="modal" data-bs-target="#attL<?= (int)$s['id'] ?>"
                      aria-label="Obecność: <?= h(date('d.m.Y', $sdate)) ?>">
                <i class="bi bi-people me-1" aria-hidden="true"></i>Obecność
              </button>
              <?php endif; ?>
              <?php if ($s['status'] === 'remote_material' && $_mat_url): ?>
              <a href="<?= h($_mat_url) ?>" class="btn btn-sm btn-outline-info py-0 px-2"
                 target="_blank" rel="noopener noreferrer" aria-label="Materiał do pracy własnej (nowa karta)">
                <i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>Materiał
              </a>
              <?php endif; ?>
              <?php if ($_meet_url && $s['status'] !== 'cancelled'): ?>
              <a href="<?= h($_meet_url) ?>" class="btn btn-sm btn-outline-primary py-0 px-2"
                 target="_blank" rel="noopener noreferrer" aria-label="Otwórz spotkanie online (nowa karta)">
                <i class="bi bi-camera-video-fill me-1" aria-hidden="true"></i>Spotkanie
              </a>
              <?php endif; ?>
              <?php if (($is_past || $is_today) && $s['status'] === 'planned'): ?>
              <button type="button" class="btn btn-sm btn-success py-0 px-2"
                      onclick="wizOpenExt(<?= (int)$s['id'] ?>)"
                      aria-label="Uzupełnij obecność i temat: <?= h(date('d.m.Y', $sdate)) ?>">
                <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Uzupełnij
              </button>
              <?php endif; ?>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                        data-bs-toggle="dropdown" aria-expanded="false"
                        aria-label="Więcej akcji dla lekcji <?= h(date('d.m.Y', $sdate)) ?>">
                  Więcej <i class="bi bi-caret-down-fill" style="font-size:.6rem" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#edL<?= (int)$s['id'] ?>">
                    <i class="bi bi-pencil me-2"></i>Edytuj lekcję
                  </a></li>
                  <li><a class="dropdown-item" href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Wejdź do lekcji
                  </a></li>
                  <?php if (dyd_is_staff()): ?>
                  <li><a class="dropdown-item" href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/lesson.php?id=<?= (int)$s['id'] ?>" target="_blank" rel="noopener">
                    <i class="bi bi-arrow-up-right-square me-2"></i>Szczegóły w module TI
                  </a></li>
                  <?php endif; ?>
                  <?php if ($s['status'] !== 'cancelled' && !$is_past): ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="#"
                         onclick="dydOpenReschedule(<?= (int)$s['id'] ?>,<?= htmlspecialchars(json_encode(date('d.m.Y',$sdate).($s['time_from']?' '.h($s['time_from']):'')),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode((string)$s['lesson_date']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode((string)($s['time_from']??'')),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode((string)($s['time_to']??'')),ENT_QUOTES) ?>);return false">
                    <i class="bi bi-calendar2-range me-2"></i>Zmień termin
                  </a></li>
                  <li><a class="dropdown-item text-danger" href="#"
                         onclick="dydOpenCancelSession(<?= (int)$s['id'] ?>,<?= htmlspecialchars(json_encode(date('d.m.Y',$sdate).($s['time_from']?' '.h($s['time_from']):'')),ENT_QUOTES) ?>);return false">
                    <i class="bi bi-x-circle me-2"></i>Odwołaj lekcję
                  </a></li>
                  <?php endif; ?>
                  <?php if ($s['status'] === 'cancelled'): ?>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form method="post" onsubmit="return confirm('Przywrócić lekcję?')">
                      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                      <input type="hidden" name="_op" value="uncancel_session">
                      <input type="hidden" name="_tab" value="lekcje">
                      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                      <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                      <button type="submit" class="dropdown-item text-warning">
                        <i class="bi bi-arrow-counterclockwise me-2"></i>Przywróć lekcję
                      </button>
                    </form>
                  </li>
                  <?php endif; ?>
                </ul>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div><!-- /dyd-table-lekcje -->
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
          <label class="form-label" id="series_dow_lbl">Co tydzień od dnia…</label>
          <div class="btn-group btn-group-sm d-flex flex-wrap" role="group" aria-labelledby="series_dow_lbl">
            <?php foreach (['Pn'=>1,'Wt'=>2,'Śr'=>3,'Cz'=>4,'Pt'=>5,'So'=>6,'Nd'=>0] as $_dl => $_dv): ?>
            <button type="button" class="btn btn-outline-secondary flex-fill series-dow-btn" data-dow="<?= $_dv ?>"><?= $_dl ?></button>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Wybierz dzień tygodnia — pole daty poniżej samo ustawi się na najbliższe takie wystąpienie.</div>
        </div>
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
        <div class="mb-2">
          <label class="form-label" for="series_method">Metoda lekcji</label>
          <select class="form-select" id="series_method" name="lesson_method"
                  onchange="(function(v){var w=document.getElementById('series_meeturl_wrap');w.style.display=(v==='zdalna_zoom'||v==='zdalna_inne')?'':'none';})(this.value)">
            <option value="">— nie wybrano —</option>
            <option value="stacjonarna">Stacjonarna</option>
            <option value="zdalna_zoom">Zdalna — Zoom</option>
            <option value="zdalna_inne">Zdalna — Inne</option>
          </select>
        </div>
        <div class="mb-2" id="series_meeturl_wrap" style="display:none">
          <label class="form-label" for="series_meeturl">
            <i class="bi bi-camera-video me-1 text-primary" aria-hidden="true"></i>Link do spotkania
          </label>
          <input type="url" class="form-control" id="series_meeturl" name="meeting_url" placeholder="https://zoom.us/j/…">
          <div class="form-text">Wspólny link dla wszystkich lekcji w serii — można zmienić per-lekcja po utworzeniu.</div>
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
<script>
(function(){
  var dateEl = document.getElementById('series_date');
  var btns   = document.querySelectorAll('.series-dow-btn');
  if (!dateEl || !btns.length) return;
  function markActive(dow){
    btns.forEach(function(b){ b.classList.toggle('active', parseInt(b.dataset.dow,10) === dow); });
  }
  btns.forEach(function(btn){
    btn.addEventListener('click', function(){
      var wantDow = parseInt(btn.dataset.dow, 10); // 0=Nd..6=So (JS getDay())
      var d = new Date();
      d.setHours(0,0,0,0);
      var diff = (wantDow - d.getDay() + 7) % 7; // najbliższe wystąpienie, dziś liczy się jako pasujące
      d.setDate(d.getDate() + diff);
      var iso = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
      dateEl.value = iso;
      markActive(wantDow);
    });
  });
  // Ręczna zmiana daty — podświetl odpowiadający jej dzień tygodnia (lub zdejmij podświetlenie).
  dateEl.addEventListener('change', function(){
    if (!dateEl.value) { markActive(-1); return; }
    var parts = dateEl.value.split('-').map(Number);
    var picked = new Date(parts[0], parts[1]-1, parts[2]);
    markActive(picked.getDay());
  });
})();
</script>

<!-- Modal: masowe czyszczenie terminów grupy -->
<?php
  $_cs_future  = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND status='planned'   AND lesson_date >= date('now')", [$cur_course])['n'] ?? 0);
  $_cs_past    = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND status='planned'   AND lesson_date <  date('now')", [$cur_course])['n'] ?? 0);
  $_cs_c_future= (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND status='cancelled' AND lesson_date >= date('now')", [$cur_course])['n'] ?? 0);
  $_cs_c_past  = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND status='cancelled' AND lesson_date <  date('now')", [$cur_course])['n'] ?? 0);
  $_cs_total   = $_cs_future + $_cs_past + $_cs_c_future + $_cs_c_past;
?>
<div class="modal fade" id="dydClearSessionsModal" tabindex="-1" aria-labelledby="dydClearSessionsLbl" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post" onsubmit="return confirm('Na pewno usunąć zaznaczone terminy? Tej operacji nie można cofnąć.')">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="clear_group_sessions">
      <input type="hidden" name="_tab" value="lekcje">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="dydClearSessionsLbl"><i class="bi bi-calendar-x me-2 text-danger"></i>Wyczyść terminy grupy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-body-secondary small">
          Masowo usuwa <strong>zaplanowane</strong> (jeszcze nieodbyte) terminy tej grupy — przydatne
          np. przed wygenerowaniem harmonogramu od nowa. Zajęcia już <strong>odbyte</strong> (w tym „praca
          własna” i indywidualne zmiany terminu) oraz ich obecności <strong>nigdy</strong> nie są usuwane.
        </p>
        <div class="form-check mb-2">
          <input class="form-check-input cs-scope" type="radio" name="include_past" id="csFutureOnly" value="0"
                 data-planned="<?= $_cs_future ?>" data-cancelled="<?= $_cs_c_future ?>" checked>
          <label class="form-check-label" for="csFutureOnly">
            Tylko nadchodzące (<?= $_cs_future ?>)
          </label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input cs-scope" type="radio" name="include_past" id="csAll" value="1"
                 data-planned="<?= $_cs_future + $_cs_past ?>" data-cancelled="<?= $_cs_c_future + $_cs_c_past ?>">
          <label class="form-check-label" for="csAll">
            Wszystkie zaplanowane, w tym zaległe (<?= $_cs_future + $_cs_past ?>)
          </label>
        </div>
        <div class="form-check mb-2 border-top pt-2">
          <input class="form-check-input" type="checkbox" name="clear_cancelled" id="csCancelled" value="1">
          <label class="form-check-label" for="csCancelled">
            Czyść też odwołane (<?= $_cs_c_future + $_cs_c_past ?>)
          </label>
        </div>
        <?php if ($_cs_total === 0): ?>
        <div class="alert alert-light border small mb-0">Brak terminów do usunięcia.</div>
        <?php else: ?>
        <div class="alert alert-warning small mb-0">Do usunięcia: <strong id="csTotalCount"><?= $_cs_future ?></strong> terminów.</div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger" id="csSubmitBtn" <?= ($_cs_total === 0) ? 'disabled' : '' ?>>
          <i class="bi bi-trash me-1"></i>Usuń terminy
        </button>
      </div>
    </form>
  </div></div>
</div>
<script>
(function(){
  var scopeInputs = document.querySelectorAll('#dydClearSessionsModal .cs-scope');
  var cancelledCb = document.getElementById('csCancelled');
  var totalEl     = document.getElementById('csTotalCount');
  var submitBtn   = document.getElementById('csSubmitBtn');
  if (!scopeInputs.length || !submitBtn) return;
  function recalc(){
    var scope = document.querySelector('#dydClearSessionsModal .cs-scope:checked');
    if (!scope) return;
    var total = parseInt(scope.dataset.planned, 10) || 0;
    if (cancelledCb && cancelledCb.checked) total += parseInt(scope.dataset.cancelled, 10) || 0;
    if (totalEl) totalEl.textContent = total;
    submitBtn.disabled = (total === 0);
  }
  scopeInputs.forEach(function(el){ el.addEventListener('change', recalc); });
  if (cancelledCb) cancelledCb.addEventListener('change', recalc);
  recalc();
})();
</script>

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
