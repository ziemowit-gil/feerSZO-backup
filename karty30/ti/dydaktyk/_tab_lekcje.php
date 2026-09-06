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
          <li><h6 class="dropdown-header">Plan zajęć (dla ucznia/rodzica)</h6></li>
          <li><a class="dropdown-item" href="harmonogram_pdf.php?course_id=<?= $cur_course ?>" target="_blank">
            <i class="bi bi-file-earmark-pdf me-2"></i>Plan zajęć — PDF
          </a></li>
          <li><a class="dropdown-item" href="harmonogram_xlsx.php?course_id=<?= $cur_course ?>">
            <i class="bi bi-file-earmark-spreadsheet me-2"></i>Plan zajęć — Excel (XLSX)
          </a></li>
          <li><a class="dropdown-item" href="harmonogram_docx.php?course_id=<?= $cur_course ?>">
            <i class="bi bi-file-earmark-word me-2"></i>Plan zajęć — Word (DOCX)
          </a></li>
          <li><a class="dropdown-item" href="plan_librus.php?course_id=<?= $cur_course ?>" target="_blank">
            <i class="bi bi-grid-3x3 me-2"></i>Plan zajęć — siatka
          </a></li>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header">Kalendarz</h6></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#dydCalSubModal">
            <i class="bi bi-calendar-check me-2"></i>Subskrybuj / pobierz
          </a></li>
          <li><a class="dropdown-item" href="plan_print.php?instructor_id=<?= $uid ?>" target="_blank">
            <i class="bi bi-printer me-2"></i>Wydruk mojego planu tygodniowego
          </a></li>
          <?php if (dyd_is_staff() && !empty($course['instructor_id']) && (int)$course['instructor_id'] !== $uid): ?>
          <li><a class="dropdown-item" href="plan_print.php?instructor_id=<?= (int)$course['instructor_id'] ?>" target="_blank">
            <i class="bi bi-printer me-2"></i>Wydruk planu — <?= h($course['instructor_name'] ?? '') ?>
          </a></li>
          <?php endif; ?>
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
      <div class="btn-group btn-group-sm" role="group" aria-label="Widok lekcji">
        <input type="radio" class="btn-check" name="lekWidok" id="lekWidokKal" autocomplete="off" checked>
        <label class="btn btn-outline-primary" for="lekWidokKal"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Kalendarz</label>
        <input type="radio" class="btn-check" name="lekWidok" id="lekWidokLista" autocomplete="off">
        <label class="btn btn-outline-primary" for="lekWidokLista"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addL">
        <i class="bi bi-plus-lg me-1"></i>Dodaj lekcję
      </button>
    </div>
    <?php if ($sessions): ?>
    <div class="w-100 d-flex flex-wrap gap-2" id="dyd-lekcje-filters" style="display:none">
      <input type="search" class="form-control form-control-sm flex-grow-1" id="lek_search" style="min-width:220px"
             placeholder="Szukaj lekcji (temat, status…)" aria-label="Filtruj lekcje po tekście">
      <?php
        $_lek_months = [];
        foreach ($sessions as $_ls) { $_ym = substr((string)$_ls['lesson_date'], 0, 7); if ($_ym !== '') $_lek_months[$_ym] = true; }
        krsort($_lek_months);
        $_months_pl_short = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
      ?>
      <select class="form-select form-select-sm" id="lek_month" style="max-width:160px" aria-label="Filtruj po miesiącu">
        <option value="">— wszystkie miesiące —</option>
        <?php foreach (array_keys($_lek_months) as $_ym): [$_yy,$_mm] = explode('-', $_ym); ?>
        <option value="<?= h($_ym) ?>"><?= $_months_pl_short[(int)$_mm] ?> <?= $_yy ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" class="form-control form-control-sm" id="lek_date" style="max-width:160px" aria-label="Filtruj po konkretnej dacie">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="lek_filter_reset">Wyczyść filtry</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Widok: kalendarz (domyślny) ──────────────────────────────── -->
  <div id="dyd-cal-lekcje" class="p-3">
    <?php if (dyd_is_staff()): ?>
    <div class="text-body-secondary small mb-2"><i class="bi bi-people-fill me-1" aria-hidden="true"></i>Widok kierownika — wszystkie grupy naraz.</div>
    <?php else: ?>
    <div class="text-body-secondary small mb-2"><i class="bi bi-person me-1" aria-hidden="true"></i>Twoje zajęcia — <?= h($course['name'] ?? '') ?>.</div>
    <?php endif; ?>
    <div id="dydLekcjeCalendar" class="ti-term-calendar border rounded"></div>
  </div>
  <script>
  window.dydLekcjeCalendarInit = function () {
    var el = document.getElementById('dydLekcjeCalendar');
    if (!el || el._fcInited) return;
    el._fcInited = true;
    var cal = new FullCalendar.Calendar(el, {
      locale: 'pl',
      initialView: 'dayGridMonth',
      height: 'auto',
      firstDay: 1,
      buttonText: { today: 'Dziś' },
      headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
      events: function (fetchInfo, successCallback, failureCallback) {
        var s = fetchInfo.startStr.slice(0, 10), e = fetchInfo.endStr.slice(0, 10);
        fetch('lekcje_feed.php?course=<?= (int)$cur_course ?>&start=' + s + '&end=' + e)
          .then(function (r) { return r.json(); })
          .then(successCallback)
          .catch(failureCallback);
      },
      eventClick: function (info) {
        info.jsEvent.preventDefault();
        var cid = (info.event.extendedProps && info.event.extendedProps.courseId) || <?= (int)$cur_course ?>;
        window.location.href = 'index.php?course=' + cid + '&tab=lekcje&lesson=' + info.event.id;
      }
    });
    cal.render();
    window._dydLekcjeCal = cal;
  };
  </script>

  <div id="dyd-list-lekcje" style="display:none">

  <!-- Legenda statusów lekcji -->
  <?php $_sdesc = [
    'draft'             => 'wersja robocza — niewidoczna dla kursantów, nie liczy się do frekwencji ani rozliczeń',
    'reserved'          => 'termin zarezerwowany i zablokowany — kursanci jej nie widzą, dopóki nie zostanie potwierdzona',
    'planned'           => 'zaplanowana, jeszcze się nie odbyła',
    'held'              => 'odbyła się z pełną grupą',
    'individual_change' => 'odbyła się, ale ze zmienionym składem uczestników',
    'remote_material'   => 'praca prowadzącego — bez listy obecności, liczona do rozliczenia',
    'cancelled'         => 'odwołana — nie jest liczona do rozliczenia',
  ]; ?>
  <div class="px-3 pt-2 pb-2 border-bottom">
    <div class="small fw-semibold mb-1">Co znaczą statusy</div>
    <ul class="list-unstyled small mb-2">
      <?php foreach ($STATUS as $_sk => $_sv): ?>
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
    // Mapa id→imię prowadzących — do odznaczenia zastępstwa (lekcja.instructor_id != kurs.instructor_id)
    $_instr_names = [];
    foreach (k30_ti_instructors() as $_ins) $_instr_names[(int)$_ins['id']] = (string)$_ins['name'];
  ?>
  <?php if ($recurring_rules): ?>
  <div class="mb-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="fw-semibold small"><i class="bi bi-arrow-repeat text-primary me-1"></i>Zajęcia stałe</span>
      <span class="badge bg-primary rounded-pill"><?= count($recurring_rules) ?></span>
    </div>
    <div class="row g-2">
      <?php
        $_rec_pos_lbl = ['1'=>'Pierwszy','2'=>'Drugi','3'=>'Trzeci','4'=>'Czwarty','last'=>'Ostatni'];
      ?>
      <?php foreach ($recurring_rules as $rr):
        $rr_monthly = ($rr['recur_mode'] ?? 'weekly') === 'monthly';
        $rr_dow = $rr_monthly
            ? (K30_TI_DAYS[(int)($rr['recur_dow'] ?? 1)] ?? '?')
            : ['Nd','Pn','Wt','Śr','Cz','Pt','So'][(int)date('w', strtotime((string)$rr['date_from']))];
        $rr_pattern = $rr_monthly
            ? ($_rec_pos_lbl[(string)($rr['recur_position'] ?? '1')] ?? '?') . ' ' . mb_strtolower($rr_dow) . ' miesiąca'
            : 'Co ' . (int)$rr['interval_weeks'] . ' tyg. · ' . $rr_dow;
        $rr_upcoming = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE series_id=? AND lesson_date >= date('now') AND status='planned'", [(int)$rr['id']])['n'] ?? 0);
      ?>
      <div class="col-md-6">
        <div class="card card-body py-2 px-3 border">
          <div class="d-flex align-items-start gap-2">
            <div class="flex-grow-1">
              <div class="fw-semibold small"><?= h($rr['topic'] ?: '—') ?></div>
              <div class="text-body-secondary small">
                <?= h($rr_pattern) ?>
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
        foreach (array_reverse($sessions) as $s): // domyślnie od najnowszych (najbliższe/ostatnie na górze)
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
        <tr data-filter-item="1" data-date="<?= h($s['lesson_date']) ?>"
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
            <?php $_s_instr = (int)($s['instructor_id'] ?? 0); if ($_s_instr && $_s_instr !== (int)($course['instructor_id'] ?? 0)): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle d-block mt-1" style="font-size:.66rem">
              <i class="bi bi-person-workspace me-1" aria-hidden="true"></i>Zastępstwo: <?= h($_instr_names[$_s_instr] ?? ('#' . $_s_instr)) ?>
            </span>
            <?php endif; ?>
            <?php if (in_array($s['status'], K30_TI_HELD_STATUSES, true) && empty($s['docs_complete'])): ?>
            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle d-block mt-1" style="font-size:.66rem"
                  title="Brak potwierdzenia uzupełnienia dokumentacji — zaznacz w menu „Więcej”">
              <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Dokumentacja niekompletna
            </span>
            <?php endif; ?>
            <?php if (!empty($s['rescheduled_from_date'])): ?>
            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle d-block mt-1" style="font-size:.66rem"
                  title="Normalny termin tej lekcji to <?= h(date('d.m.Y', strtotime((string)$s['rescheduled_from_date']))) ?><?= $s['rescheduled_from_time_from'] ? ', ' . h(substr((string)$s['rescheduled_from_time_from'], 0, 5)) : '' ?> — uwzględniane tak w planie zajęć (siatka).">
              <i class="bi bi-calendar2-range me-1" aria-hidden="true"></i>Przeniesiono z <?= h(date('d.m.Y', strtotime((string)$s['rescheduled_from_date']))) ?>
            </span>
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
                        aria-label="Wydruki dla lekcji <?= h(date('d.m.Y', $sdate)) ?>">
                  <i class="bi bi-printer me-1" aria-hidden="true"></i>Wydruki
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="lekcja_pdf.php?id=<?= (int)$s['id'] ?>" target="_blank">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Karta lekcji (PDF)
                  </a></li>
                </ul>
              </div>
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
                  <?php if ($s['status'] === 'reserved'): ?>
                  <li>
                    <form method="post">
                      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                      <input type="hidden" name="_op" value="confirm_reservation">
                      <input type="hidden" name="_tab" value="lekcje">
                      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                      <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                      <button type="submit" class="dropdown-item text-primary">
                        <i class="bi bi-bookmark-check me-2"></i>Potwierdź rezerwację
                      </button>
                    </form>
                  </li>
                  <?php endif; ?>
                  <?php if (in_array($s['status'], K30_TI_HELD_STATUSES, true)): ?>
                  <li>
                    <form method="post">
                      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                      <input type="hidden" name="_op" value="toggle_docs_complete">
                      <input type="hidden" name="_tab" value="lekcje">
                      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                      <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                      <button type="submit" class="dropdown-item <?= !empty($s['docs_complete']) ? 'text-success' : '' ?>">
                        <i class="bi bi-<?= !empty($s['docs_complete']) ? 'check-square-fill' : 'square' ?> me-2"></i>
                        <?= !empty($s['docs_complete']) ? 'Dokumentacja uzupełniona ✓' : 'Oznacz: dokumentacja uzupełniona' ?>
                      </button>
                    </form>
                  </li>
                  <?php endif; ?>
                  <li><a class="dropdown-item" href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Wejdź do lekcji
                  </a></li>
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
  </div><!-- /dyd-list-lekcje -->
</div>
<script>
(function(){
  // Przełącznik widoku: Kalendarz (domyślny) / Lista — zapamiętany w localStorage.
  var calWrap  = document.getElementById('dyd-cal-lekcje');
  var listWrap = document.getElementById('dyd-list-lekcje');
  var filters  = document.getElementById('dyd-lekcje-filters');
  var rKal     = document.getElementById('lekWidokKal');
  var rLista   = document.getElementById('lekWidokLista');
  var calRendered = false;

  function showCalendar() {
    if (calWrap) calWrap.style.display = '';
    if (listWrap) listWrap.style.display = 'none';
    if (filters) filters.style.display = 'none';
    if (!calRendered && window.dydLekcjeCalendarInit) { window.dydLekcjeCalendarInit(); calRendered = true; }
    try { localStorage.setItem('dydLekcjeWidok', 'kalendarz'); } catch (e) {}
  }
  function showList() {
    if (calWrap) calWrap.style.display = 'none';
    if (listWrap) listWrap.style.display = '';
    if (filters) filters.style.display = '';
    try { localStorage.setItem('dydLekcjeWidok', 'lista'); } catch (e) {}
  }
  if (rKal)   rKal.addEventListener('change', function () { if (rKal.checked) showCalendar(); });
  if (rLista) rLista.addEventListener('change', function () { if (rLista.checked) showList(); });

  var saved = null;
  try { saved = localStorage.getItem('dydLekcjeWidok'); } catch (e) {}
  if (saved === 'lista' && rLista) { rLista.checked = true; showList(); }
  else { showCalendar(); }
})();
</script>
<script>
(function(){
  var wrap  = document.getElementById('dyd-table-lekcje');
  var text  = document.getElementById('lek_search');
  var month = document.getElementById('lek_month');
  var day   = document.getElementById('lek_date');
  var reset = document.getElementById('lek_filter_reset');
  if (!wrap || !text) return;
  function apply(){
    var q  = text.value.toLowerCase().trim();
    var ym = month ? month.value : '';
    var d  = day ? day.value : '';
    var items = wrap.querySelectorAll('[data-filter-item]');
    var shown = 0;
    items.forEach(function(item){
      var okText  = !q  || item.textContent.toLowerCase().indexOf(q) !== -1;
      var okMonth = !ym || (item.dataset.date || '').slice(0,7) === ym;
      var okDay   = !d  || item.dataset.date === d;
      var match = okText && okMonth && okDay;
      item.style.display = match ? '' : 'none';
      if (match) shown++;
    });
    var msg = wrap.querySelector('.dyd-filter-empty');
    if (msg) msg.style.display = ((q || ym || d) && shown === 0) ? '' : 'none';
  }
  [text, month, day].forEach(function(el){ if (el) { el.addEventListener('input', apply); el.addEventListener('change', apply); } });
  if (reset) reset.addEventListener('click', function(){
    text.value = ''; if (month) month.value = ''; if (day) day.value = '';
    apply();
  });
})();
</script>


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

<!-- Podpowiedzi tematu lekcji z planu nauczania kursu — współdzielone przez
     formularz pojedynczej lekcji, serię lekcji i regułę zajęć stałych. -->
<datalist id="ti_topic_options">
  <?php foreach (k30_ti_curriculum_list($cur_course, true) as $_to): ?>
  <option value="<?= h($_to['title']) ?>">
  <?php endforeach; ?>
</datalist>

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
        <p class="text-body-secondary small">Utworzy kilka lekcji powtarzających się wg wzorca, od daty startowej.</p>
        <div class="mb-2">
          <label class="form-label" id="series_mode_lbl">Wzorzec</label>
          <div class="btn-group btn-group-sm d-flex" role="group" aria-labelledby="series_mode_lbl">
            <input type="radio" class="btn-check" name="recur_mode" id="series_mode_weekly" value="weekly" checked
                   onchange="dydSeriesModeToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="series_mode_weekly">Co N tygodni</label>
            <input type="radio" class="btn-check" name="recur_mode" id="series_mode_monthly" value="monthly"
                   onchange="dydSeriesModeToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="series_mode_monthly">Miesięcznie (np. 2. czwartek)</label>
          </div>
        </div>
        <div id="series_weekly_panel">
          <div class="mb-2">
            <label class="form-label" id="series_dow_lbl">Zacznij od dnia…</label>
            <div class="btn-group btn-group-sm d-flex flex-wrap" role="group" aria-labelledby="series_dow_lbl">
              <?php foreach (['Pn'=>1,'Wt'=>2,'Śr'=>3,'Cz'=>4,'Pt'=>5,'So'=>6,'Nd'=>0] as $_dl => $_dv): ?>
              <button type="button" class="btn btn-outline-secondary flex-fill series-dow-btn" data-dow="<?= $_dv ?>"><?= $_dl ?></button>
              <?php endforeach; ?>
            </div>
            <div class="form-text">Wybierz dzień tygodnia — pole daty poniżej samo ustawi się na najbliższe takie wystąpienie.</div>
          </div>
        </div>
        <div id="series_monthly_panel" style="display:none">
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="series_month_dow">Dzień tygodnia</label>
              <select class="form-select" id="series_month_dow" name="recur_dow">
                <?php foreach (K30_TI_DAYS as $_wdv => $_wdl): ?>
                <option value="<?= $_wdv ?>"><?= h($_wdl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="series_month_pos">Który</label>
              <select class="form-select" id="series_month_pos" name="recur_position">
                <option value="1">Pierwszy</option>
                <option value="2">Drugi</option>
                <option value="3">Trzeci</option>
                <option value="4">Czwarty</option>
                <option value="last">Ostatni</option>
              </select>
            </div>
          </div>
          <div class="form-text mb-2">Np. „Drugi" + „Czwartek" = co 2. czwartek każdego miesiąca.</div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" for="series_date">Data startowa <span class="text-danger">*</span></label>
          <input type="date" class="form-control" id="series_date" name="lesson_date" required value="<?= h(date('Y-m-d')) ?>">
          <div class="form-text" id="series_date_hint" style="display:none">Wystąpienia wcześniejsze niż ta data (w tym samym miesiącu) są pomijane.</div>
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
          <label class="form-label" for="series_dflag">Pewność terminu</label>
          <select class="form-select" id="series_dflag" name="date_flag">
            <?php foreach (K30_TI_DATE_FLAGS as $_dfk => $_dfv): ?>
            <option value="<?= h($_dfk) ?>"><?= h($_dfv['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Ta sama adnotacja dla wszystkich lekcji serii — niezależna od statusu.</div>
        </div>
        <div class="form-check form-switch mb-2 p-2 rounded" style="background:#EFF6FF">
          <input class="form-check-input" type="checkbox" role="switch" id="series_reservation" name="is_reservation" value="1"
                 onchange="document.getElementById('series_pesel_wrap').style.display=this.checked?'':'none'; if(this.checked){var d=document.getElementById('series_draft'); if(d) d.checked=false;}">
          <label class="form-check-label" for="series_reservation">
            <i class="bi bi-bookmark-star me-1" aria-hidden="true"></i>Cała seria to rezerwacja terminu (nie ostateczne lekcje)
          </label>
          <div class="mt-2" id="series_pesel_wrap" style="display:none">
            <label class="form-label small mb-1" for="series_pesel">PESEL beneficjenta PFRON (opcjonalnie)</label>
            <input type="text" class="form-control form-control-sm" id="series_pesel" name="pfron_pesel" maxlength="11" inputmode="numeric" placeholder="11 cyfr">
            <div class="form-text mb-0">Temat każdej lekcji serii ustawi się jako „PFRON-XXX", pełny PESEL trafi do notatek.</div>
          </div>
        </div>
        <div class="form-check form-switch mb-2 p-2 rounded" style="background:#F9FAFB">
          <input class="form-check-input" type="checkbox" role="switch" id="series_draft" name="is_draft" value="1"
                 onchange="if(this.checked){var rv=document.getElementById('series_reservation'); if(rv && rv.checked){rv.checked=false; document.getElementById('series_pesel_wrap').style.display='none';}}">
          <label class="form-check-label" for="series_draft">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Cała seria jako wersja robocza (szkic)
          </label>
        </div>
        <div class="mb-2">
          <label class="form-label" for="series_topic">Temat <span class="text-body-secondary small">(opc., wspólny)</span></label>
          <input type="text" class="form-control" id="series_topic" name="topic" list="ti_topic_options" placeholder="np. Zajęcia cykliczne — można wybrać z planu nauczania">
        </div>
        <?php if (dyd_is_staff()): $_ser_instrs = k30_ti_instructors(); ?>
        <div class="mb-2">
          <label class="form-label" for="series_instr">
            Prowadzący <span class="text-body-secondary fw-normal small">(zastępstwo — opcjonalnie, wpływa na wypłatę)</span>
          </label>
          <select class="form-select" id="series_instr" name="instructor_id">
            <option value="0">— domyślny: <?= h($course['instructor_name'] ?? '') ?: 'brak przypisania' ?> —</option>
            <?php foreach ($_ser_instrs as $ins): ?>
            <option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="series_skipavail" name="skip_availability" value="1">
          <label class="form-check-label" for="series_skipavail">Nie sprawdzaj dostępności prowadzącego</label>
          <div class="form-text mb-0">
            Sprawdzana jest dostępność na pierwszym terminie serii — zaznacz, żeby ją pominąć (np. pilne
            zastępstwo poza zwykłymi godzinami). Pozostałe blokady (Zoom, sala, zamknięty okres) działają normalnie.
          </div>
        </div>
        <?php endif; ?>
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
        <div class="mb-2">
          <label class="form-label" for="series_room">Sala / lokalizacja</label>
          <select class="form-select" id="series_room" name="room_id">
            <option value="0">— nie wybrano —</option>
            <?php foreach (pl_rooms_list(['is_active' => 1]) as $_room): ?>
            <option value="<?= (int)$_room['id'] ?>"><?= h($_room['name']) ?><?= trim((string)$_room['location']) !== '' ? ' — ' . h($_room['location']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Wspólna sala dla wszystkich lekcji serii.</div>
        </div>
        <div id="series_weeks_wrap" class="mb-2">
          <label class="form-label" for="series_weeks">Co ile tygodni</label>
          <input type="number" class="form-control" id="series_weeks" name="weeks" min="1" max="8" value="1">
        </div>
        <div class="mb-2">
          <label class="form-label" id="series_end_lbl">Zakończ</label>
          <div class="btn-group btn-group-sm d-flex" role="group" aria-labelledby="series_end_lbl">
            <input type="radio" class="btn-check" name="end_mode" id="series_end_count" value="count" checked
                   onchange="dydSeriesEndToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="series_end_count">Po liczbie lekcji</label>
            <input type="radio" class="btn-check" name="end_mode" id="series_end_until" value="until"
                   onchange="dydSeriesEndToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="series_end_until">Do daty</label>
            <input type="radio" class="btn-check" name="end_mode" id="series_end_hours" value="hours"
                   onchange="dydSeriesEndToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="series_end_hours">Do X godzin</label>
          </div>
        </div>
        <div id="series_count_wrap" class="mb-2">
          <label class="form-label" for="series_count">Liczba lekcji</label>
          <input type="number" class="form-control" id="series_count" name="count" min="1" max="104" value="8">
          <button type="button" class="btn btn-sm btn-outline-secondary mt-1 w-100" id="series_to_eoy_btn">Do końca roku</button>
        </div>
        <div id="series_until_wrap" class="mb-2" style="display:none">
          <label class="form-label" for="series_until">Powtarzaj do daty (włącznie)</label>
          <input type="date" class="form-control" id="series_until" name="until">
        </div>
        <div id="series_hours_wrap" class="mb-2" style="display:none">
          <label class="form-label" for="series_target_hours">Docelowa liczba godzin</label>
          <input type="number" class="form-control" id="series_target_hours" name="target_hours" min="0.5" step="0.5" value="10">
          <div class="form-text">Tyle lekcji, żeby osiągnąć sumę godzin — przy krótszej ostatniej lekcji łączny czas może nieznacznie przekroczyć cel.</div>
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
function dydSeriesModeToggle(){
  var monthly = document.getElementById('series_mode_monthly').checked;
  document.getElementById('series_weekly_panel').style.display  = monthly ? 'none' : '';
  document.getElementById('series_monthly_panel').style.display = monthly ? '' : 'none';
  document.getElementById('series_weeks_wrap').style.display    = monthly ? 'none' : '';
  var hint = document.getElementById('series_date_hint');
  if (hint) hint.style.display = monthly ? '' : 'none';
  var eoyBtn = document.getElementById('series_to_eoy_btn');
  if (eoyBtn) eoyBtn.style.display = monthly ? 'none' : '';
}
function dydSeriesEndToggle(){
  var until = document.getElementById('series_end_until').checked;
  var hours = document.getElementById('series_end_hours').checked;
  document.getElementById('series_count_wrap').style.display = (until || hours) ? 'none' : '';
  document.getElementById('series_until_wrap').style.display = until ? '' : 'none';
  document.getElementById('series_hours_wrap').style.display = hours ? '' : 'none';
}
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

  // „Do końca roku" — dolicza liczbę lekcji tak, by seria (co N tygodni od daty
  // startowej) sięgnęła do 31 grudnia roku daty startowej.
  var eoyBtn = document.getElementById('series_to_eoy_btn');
  var weeksEl = document.getElementById('series_weeks');
  var countEl = document.getElementById('series_count');
  if (eoyBtn && weeksEl && countEl) {
    eoyBtn.addEventListener('click', function(){
      if (!dateEl.value) { dateEl.focus(); return; }
      var parts = dateEl.value.split('-').map(Number);
      var start = new Date(parts[0], parts[1]-1, parts[2]);
      var eoy   = new Date(parts[0], 11, 31);
      var iw    = parseInt(weeksEl.value, 10) || 1;
      if (eoy < start) { countEl.value = 1; return; }
      var days  = Math.floor((eoy - start) / 86400000);
      countEl.value = Math.floor(days / (iw * 7)) + 1;
    });
  }
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
          <input class="form-check-input cs-scope" type="radio" name="scope" id="csFutureOnly" value="future"
                 data-planned="<?= $_cs_future ?>" data-cancelled="<?= $_cs_c_future ?>" checked>
          <label class="form-check-label" for="csFutureOnly">
            Tylko nadchodzące (<?= $_cs_future ?>)
          </label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input cs-scope" type="radio" name="scope" id="csAll" value="all"
                 data-planned="<?= $_cs_future + $_cs_past ?>" data-cancelled="<?= $_cs_c_future + $_cs_c_past ?>">
          <label class="form-check-label" for="csAll">
            Wszystkie zaplanowane, w tym zaległe (<?= $_cs_future + $_cs_past ?>)
          </label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input cs-scope" type="radio" name="scope" id="csDay" value="day"
                 data-planned="0" data-cancelled="0">
          <label class="form-check-label" for="csDay">
            Tylko konkretny dzień
          </label>
          <input type="date" class="form-control form-control-sm mt-1" name="clear_date" id="csDayDate" disabled>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input cs-scope" type="radio" name="scope" id="csWeekday" value="weekday"
                 data-planned="0" data-cancelled="0">
          <label class="form-check-label" for="csWeekday">
            Konkretny dzień tygodnia (tylko nadchodzące)
          </label>
          <select class="form-select form-select-sm mt-1" name="clear_weekday" id="csWeekdaySelect" disabled>
            <?php foreach (K30_TI_DAYS as $_wdv => $_wdl): ?>
            <option value="<?= $_wdv ?>"><?= h($_wdl) ?></option>
            <?php endforeach; ?>
          </select>
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
        <div class="alert alert-warning small mb-0" id="csCountAlert">Do usunięcia: <strong id="csTotalCount"><?= $_cs_future ?></strong> terminów.</div>
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
  var countAlert  = document.getElementById('csCountAlert');
  var submitBtn   = document.getElementById('csSubmitBtn');
  var dayDate     = document.getElementById('csDayDate');
  var weekdaySel  = document.getElementById('csWeekdaySelect');
  if (!scopeInputs.length || !submitBtn) return;
  function recalc(){
    var scope = document.querySelector('#dydClearSessionsModal .cs-scope:checked');
    if (!scope) return;
    var isDay     = scope.value === 'day';
    var isWeekday = scope.value === 'weekday';
    if (dayDate)    dayDate.disabled    = !isDay;
    if (weekdaySel) weekdaySel.disabled = !isWeekday;
    if (isDay) {
      if (countAlert) countAlert.style.display = 'none';
      submitBtn.disabled = !dayDate || !dayDate.value;
      return;
    }
    if (isWeekday) {
      if (countAlert) countAlert.style.display = 'none';
      submitBtn.disabled = false;
      return;
    }
    if (countAlert) countAlert.style.display = '';
    var total = parseInt(scope.dataset.planned, 10) || 0;
    if (cancelledCb && cancelledCb.checked) total += parseInt(scope.dataset.cancelled, 10) || 0;
    if (totalEl) totalEl.textContent = total;
    submitBtn.disabled = (total === 0);
  }
  scopeInputs.forEach(function(el){ el.addEventListener('change', recalc); });
  if (cancelledCb) cancelledCb.addEventListener('change', recalc);
  if (dayDate) dayDate.addEventListener('input', recalc);
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
        <div class="mb-2">
          <label class="form-label" id="rec_mode_lbl">Wzorzec</label>
          <div class="btn-group btn-group-sm d-flex" role="group" aria-labelledby="rec_mode_lbl">
            <input type="radio" class="btn-check" name="recur_mode" id="rec_mode_weekly" value="weekly" checked
                   onchange="dydRecurringModeToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="rec_mode_weekly">Co N tygodni</label>
            <input type="radio" class="btn-check" name="recur_mode" id="rec_mode_monthly" value="monthly"
                   onchange="dydRecurringModeToggle()">
            <label class="btn btn-outline-secondary flex-fill" for="rec_mode_monthly">Miesięcznie (np. 2. czwartek)</label>
          </div>
        </div>
        <div id="rec_weekly_panel" class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label" for="rec_interval">Co ile tygodni</label>
            <input type="number" class="form-control" id="rec_interval" name="interval_weeks" min="1" max="8" value="1">
          </div>
          <div class="col-6 d-flex align-items-end">
            <span class="text-body-secondary small" id="rec_count_hint"></span>
          </div>
        </div>
        <div id="rec_monthly_panel" class="row g-2 mb-2" style="display:none">
          <div class="col-6">
            <label class="form-label" for="rec_month_dow">Dzień tygodnia</label>
            <select class="form-select" id="rec_month_dow" name="recur_dow">
              <?php foreach (K30_TI_DAYS as $_wdv => $_wdl): ?>
              <option value="<?= $_wdv ?>"><?= h($_wdl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" for="rec_month_pos">Który</label>
            <select class="form-select" id="rec_month_pos" name="recur_position">
              <option value="1">Pierwszy</option>
              <option value="2">Drugi</option>
              <option value="3">Trzeci</option>
              <option value="4">Czwarty</option>
              <option value="last">Ostatni</option>
            </select>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="rec_topic">Temat <span class="text-body-secondary small">(opc., wspólny)</span></label>
          <input type="text" class="form-control" id="rec_topic" name="topic" list="ti_topic_options" placeholder="np. Ćwiczenia praktyczne — można wybrać z planu nauczania">
        </div>
        <div class="mb-2">
          <label class="form-label" for="rec_room">Sala / lokalizacja</label>
          <select class="form-select" id="rec_room" name="room_id">
            <option value="0">— nie wybrano —</option>
            <?php foreach (pl_rooms_list(['is_active' => 1]) as $_room): ?>
            <option value="<?= (int)$_room['id'] ?>"><?= h($_room['name']) ?><?= trim((string)$_room['location']) !== '' ? ' — ' . h($_room['location']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Wspólna sala dla całego cyklu — dziedziczona przez wszystkie generowane lekcje.</div>
        </div>
        <div class="form-check form-switch mb-2 p-2 rounded" style="background:#F9FAFB">
          <input class="form-check-input" type="checkbox" role="switch" id="rec_draft" name="is_draft" value="1">
          <label class="form-check-label" for="rec_draft">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Cały cykl jako wersja robocza (szkic)
          </label>
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
function dydRecurringModeToggle(){
  var monthly = document.getElementById('rec_mode_monthly').checked;
  document.getElementById('rec_weekly_panel').style.display  = monthly ? 'none' : '';
  document.getElementById('rec_monthly_panel').style.display = monthly ? '' : 'none';
}
(function(){
  var df=document.getElementById('rec_date_from'), dt=document.getElementById('rec_date_to'),
      iv=document.getElementById('rec_interval'), hint=document.getElementById('rec_count_hint');
  function upd(){
    var monthly = document.getElementById('rec_mode_monthly').checked;
    var f=df?df.value:'',t=dt?dt.value:'',iw=parseInt(iv?iv.value:1)||1;
    if(monthly){ hint.textContent=''; return; }
    if(f&&t&&t>=f){
      var ms=new Date(t)-new Date(f), d=Math.floor(ms/86400000)+1;
      var n=Math.ceil(d/(iw*7));
      hint.textContent='≈'+n+' lekcji';
    }else hint.textContent='';
  }
  [df,dt,iv].forEach(function(el){if(el)el.addEventListener('change',upd);});
})();
</script>

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
  <div class="modal-dialog modal-lg"><form method="post" class="modal-content">
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
      <div class="mb-3">
        <label class="form-label fw-semibold d-block">Nowa data <span class="text-danger">*</span></label>
        <input type="hidden" id="rs_date" name="lesson_date" required>
        <div id="rs_calendar" class="ti-term-calendar border rounded"></div>
        <div class="form-text mt-1" id="rs_date_display">Kliknij dzień w kalendarzu, aby wybrać nowy termin.</div>
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
<script>
(function(){
  var modalEl = document.getElementById('reschedSessionModal');
  if (!modalEl) return;
  var calEl = document.getElementById('rs_calendar');
  var cal = null, pendingDate = '';

  function ensureCalendar() {
    if (cal) return cal;
    cal = new FullCalendar.Calendar(calEl, {
      locale: 'pl', initialView: 'dayGridMonth', height: 'auto', firstDay: 1,
      headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
      buttonText: { today: 'Dziś' },
      dateClick: function (info) { selectDate(info.dateStr); }
    });
    cal.render();
    return cal;
  }

  function selectDate(dateStr) {
    document.getElementById('rs_date').value = dateStr;
    calEl.querySelectorAll('.ti-selected-day').forEach(function (d) { d.classList.remove('ti-selected-day'); });
    var cell = calEl.querySelector('[data-date="' + dateStr + '"]');
    if (cell) cell.classList.add('ti-selected-day');
    var disp = document.getElementById('rs_date_display');
    if (disp) {
      var d = new Date(dateStr + 'T00:00:00');
      disp.textContent = 'Wybrano: ' + d.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
  }

  // Wywoływane z dydOpenReschedule() w index.php przy otwieraniu modala.
  window.tiSetRescheduleDate = function (dateStr) {
    pendingDate = dateStr || '';
    document.getElementById('rs_date').value = pendingDate;
    var disp0 = document.getElementById('rs_date_display');
    if (disp0) disp0.textContent = 'Kliknij dzień w kalendarzu, aby wybrać nowy termin.';
  };

  modalEl.addEventListener('shown.bs.modal', function () {
    var c = ensureCalendar();
    c.updateSize();
    if (pendingDate) { c.gotoDate(pendingDate); selectDate(pendingDate); }
  });

  modalEl.querySelector('form').addEventListener('submit', function (e) {
    if (!document.getElementById('rs_date').value) {
      e.preventDefault();
      alert('Wybierz nową datę w kalendarzu.');
    }
  });
})();
</script>

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
