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
  // Robocze wersje lekcji — lista do zatwierdzenia (popup z podglądem maila i załączników)
  require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_lesson_approval/logic/approval.php';
  $_appr_drafts = $cur_course ? ti_appr_drafts((int)$cur_course) : [];
  $_appr_days = ['Nd','Pn','Wt','Śr','Cz','Pt','So'];
?>
<?php if ($_appr_drafts): ?>
<div class="alert alert-warning d-flex align-items-center flex-wrap gap-2" role="alert" id="apprWarn">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
  <span><strong>Masz <?= count($_appr_drafts) ?> <?= count($_appr_drafts) === 1 ? 'wersję roboczą' : 'wersji roboczych' ?> lekcji.</strong> Kursanci ich nie widzą i nie liczą się do frekwencji ani rozliczeń — podejmij decyzję.</span>
  <button type="button" class="btn btn-sm btn-warning ms-auto" id="apprDecide">Podejmij decyzję…</button>
</div>
<section class="card border-warning mb-3" aria-labelledby="apprH">
  <div class="card-header d-flex align-items-center flex-wrap gap-2 bg-warning-subtle">
    <h2 class="h6 mb-0" id="apprH"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Wersje robocze do zatwierdzenia <span class="badge text-bg-warning"><?= count($_appr_drafts) ?></span></h2>
    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="apprEdit" disabled><i class="bi bi-sliders me-1" aria-hidden="true"></i>Zmień parametry zaznaczonych…</button>
    <button type="button" class="btn btn-sm btn-success" id="apprOpen" disabled><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Zatwierdź zaznaczone…</button>
  </div>
  <ul class="list-group list-group-flush">
    <li class="list-group-item py-1">
      <div class="form-check"><input class="form-check-input" type="checkbox" id="apprAll"><label class="form-check-label small" for="apprAll">Zaznacz wszystkie</label></div>
    </li>
    <?php foreach ($_appr_drafts as $_d): $_ts = (string)$_d['lesson_date'] !== '' ? strtotime((string)$_d['lesson_date']) : 0; $_online = in_array((string)$_d['lesson_method'], ['zdalna_zoom','zdalna_inne'], true); ?>
    <li class="list-group-item py-1 d-flex align-items-center gap-2">
      <div class="form-check">
        <input class="form-check-input appr-chk" type="checkbox" value="<?= (int)$_d['id'] ?>" id="apprC<?= (int)$_d['id'] ?>">
        <label class="form-check-label" for="apprC<?= (int)$_d['id'] ?>">
          <?= $_ts ? h(date('d.m.Y', $_ts) . ' (' . $_appr_days[(int)date('w', $_ts)] . ') ' . substr((string)$_d['time_from'], 0, 5) . '–' . substr((string)$_d['time_to'], 0, 5)) : '<span class="text-body-secondary">bez terminu</span>' ?>
          — <?= h(trim((string)$_d['topic']) !== '' ? $_d['topic'] : 'bez tematu') ?>
          <?php if ($_online): ?><span class="badge text-bg-info"><i class="bi bi-camera-video me-1" aria-hidden="true"></i>online</span><?php endif; ?>
        </label>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto" data-bs-toggle="modal" data-bs-target="#edL<?= (int)$_d['id'] ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj<span class="visually-hidden"> wersję roboczą <?= h(trim((string)$_d['topic']) !== '' ? $_d['topic'] : 'bez tematu') ?></span></button>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<form id="apprDelForm" method="post" action="approve_drafts.php" class="d-none">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="course" value="<?= (int)$cur_course ?>"><input type="hidden" name="op" value="delete">
</form>
<script src="../assets/dydApprovalModal.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/dydApprovalModal.js') ?>"></script>
<script>
(function () {
  var chks = document.querySelectorAll('.appr-chk'), all = document.getElementById('apprAll'), open = document.getElementById('apprOpen');
  var cid = <?= (int)$cur_course ?>, token = <?= json_encode(dyd_token()) ?>, total = chks.length;
  function sel() { return [].filter.call(chks, function (c) { return c.checked; }).map(function (c) { return c.value; }); }
  function sync() { open.disabled = sel().length === 0; document.getElementById('apprEdit').disabled = sel().length === 0; all.checked = sel().length === chks.length; }
  document.getElementById('apprEdit').addEventListener('click', function () {   // przejście do masowej zmiany parametrów z wybranymi szkicami
    var mm = document.getElementById('mmModal'), only = document.getElementById('mmOnlyDrafts');
    if (!mm || !only || typeof bootstrap === 'undefined') return;
    only.checked = true;
    mm.addEventListener('shown.bs.modal', function onShown() {
      mm.removeEventListener('shown.bs.modal', onShown);
      var f = document.getElementById('mmChg_lesson_method'); if (f) f.focus();
    });
    bootstrap.Modal.getOrCreateInstance(mm).show();
  });
  function approve(ids) {
    dydApprovalModal({ title: 'Zatwierdzenie wersji roboczych', previewUrl: 'approve_drafts.php?course=' + cid + '&ids=' + ids.join(','),
      postUrl: 'approve_drafts.php', pairs: [['_token', token], ['course', cid], ['op', 'approve']] });
  }
  all.addEventListener('change', function () { [].forEach.call(chks, function (c) { c.checked = all.checked; }); sync(); });
  [].forEach.call(chks, function (c) { c.addEventListener('change', sync); });
  open.addEventListener('click', function () { var ids = sel(); if (ids.length) approve(ids); });
  sync();

  // Popup decyzyjny — raz na sesję przeglądarki dla danego kursu i liczby roboczych; zawsze dostępny przyciskiem w ostrzeżeniu
  function decide() {
    var old = document.getElementById('apprDecision'); if (old) old.remove();
    var el = document.createElement('div');
    el.id = 'apprDecision'; el.className = 'modal fade'; el.tabIndex = -1; el.setAttribute('aria-labelledby', 'apprDecT'); el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
      + '<div class="modal-header"><h2 class="modal-title h5" id="apprDecT"><i class="bi bi-exclamation-triangle-fill text-warning me-2" aria-hidden="true"></i>Wersje robocze lekcji (' + total + ')</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>'
      + '<div class="modal-body"><p>Te lekcje są jeszcze <strong>niewidoczne dla kursantów</strong> i nie liczą się do frekwencji ani rozliczeń. Co z nimi zrobić?</p>'
      + '<div class="d-grid gap-2">'
      + '<button type="button" class="btn btn-success" data-d="all"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Przejrzyj i zatwierdź wszystkie (podgląd maila)</button>'
      + '<button type="button" class="btn btn-outline-primary" data-d="pick"><i class="bi bi-ui-checks me-1" aria-hidden="true"></i>Wybiorę, które zatwierdzić</button>'
      + '<button type="button" class="btn btn-outline-danger" data-d="del"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń wszystkie wersje robocze</button>'
      + '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zdecyduję później</button>'
      + '</div></div></div></div>';
    document.body.appendChild(el);
    var m = bootstrap.Modal.getOrCreateInstance(el); m.show();
    el.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-d]'); if (!b) return;
      var d = b.getAttribute('data-d'); m.hide();
      if (d === 'all') approve([].map.call(chks, function (c) { return c.value; }));
      else if (d === 'pick') { var s = document.getElementById('apprH'); if (s) { s.scrollIntoView({ block: 'center' }); var f = chks[0]; if (f) f.focus(); } }
      else if (d === 'del' && confirm('Usunąć WSZYSTKIE wersje robocze (' + total + ') tego kursu? Tej operacji nie można cofnąć.')) document.getElementById('apprDelForm').submit();
    });
  }
  document.getElementById('apprDecide').addEventListener('click', decide);
  var key = 'dydApprDecision:' + cid + ':' + total;
  try { if (!sessionStorage.getItem(key)) { sessionStorage.setItem(key, '1'); window.addEventListener('load', function () { setTimeout(decide, 400); }); } } catch (e) { window.addEventListener('load', function () { setTimeout(decide, 400); }); }
})();
</script>
<?php endif; ?>
<?php if ($cur_course): // Masowa zmiana parametrów terminów ?>
<?php $_mm_rooms = pl_rooms_list(['is_active' => 1]); $_mm_staff = dyd_is_staff(); $_mm_instrs = $_mm_staff ? k30_ti_instructors() : []; ?>
<button type="button" class="btn btn-outline-primary btn-sm mb-3" data-bs-toggle="modal" data-bs-target="#mmModal" id="mmOpen"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Masowa zmiana typu i parametrów terminów</button>
<div class="modal fade" id="mmModal" tabindex="-1" aria-labelledby="mmModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="mmModalTitle"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Masowa zmiana typu i parametrów terminów</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij okno masowej zmiany"></button>
      </div>
      <div class="modal-body p-0" id="mmBox">
  <form class="card-body" id="mmForm" onsubmit="return false">
    <fieldset class="mb-3">
      <legend class="h6">1. Których lekcji dotyczy zmiana (tylko nadchodzące, niezakończone)</legend>
      <?php if ($_appr_drafts): ?>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mmOnlyDrafts"><label class="form-check-label" for="mmOnlyDrafts">Tylko zaznaczone wersje robocze (z listy powyżej) — zakres dat i filtry są wtedy pomijane; dotyczy też szkiców bez terminu</label></div>
      <?php endif; ?>
      <div class="row g-2">
        <div class="col-6 col-md-3"><label class="form-label small" for="mmFrom">Od dnia</label><input class="form-control form-control-sm" type="date" id="mmFrom" name="from" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="mmTo">Do dnia</label><input class="form-control form-control-sm" type="date" id="mmTo" name="to" value="<?= date('Y-m-d', strtotime('+90 days')) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="mmOm">Obecna forma</label>
          <select class="form-select form-select-sm" id="mmOm" name="only_method"><option value="any">dowolna</option><option value="none">nie wybrano</option><option value="stacjonarna">stacjonarna</option><option value="zdalna_zoom">zdalna — Zoom</option><option value="zdalna_inne">zdalna — inna</option></select></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="mmOs">Obecny typ terminu</label>
          <select class="form-select form-select-sm" id="mmOs" name="only_status"><option value="any">dowolny</option><option value="draft">wersja robocza</option><option value="reserved">rezerwacja</option><option value="planned">zaplanowana</option></select></div>
      </div>
    </fieldset>
    <fieldset class="mb-3">
      <legend class="h6">2. Co zmienić (zaznacz pola, które mają się zmienić)</legend>
      <?php
      $_mm_row = function (string $f, string $label, string $input) {
          echo '<div class="row g-2 align-items-center mb-2"><div class="col-12 col-md-4"><div class="form-check"><input class="form-check-input mm-chg" type="checkbox" name="chg_' . $f . '" value="1" id="mmChg_' . $f . '" data-f="' . $f . '"><label class="form-check-label" for="mmChg_' . $f . '">' . $label . '</label></div></div><div class="col-12 col-md-8">' . $input . '</div></div>';
      };
      $_mm_opts = fn(array $m) => implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '">' . h($v) . '</option>', array_keys($m), $m));
      $_mm_room_opts = '<option value="0">— brak sali —</option>' . implode('', array_map(fn($r) => '<option value="' . (int)$r['id'] . '">' . h($r['name']) . '</option>', $_mm_rooms));
      $_mm_row('status', 'Typ terminu', '<select class="form-select form-select-sm mm-val" name="val_status" aria-label="Nowy typ terminu">' . $_mm_opts(TI_MM_STATUSES) . '</select>');
      $_mm_row('lesson_method', 'Forma zajęć', '<select class="form-select form-select-sm mm-val" name="val_lesson_method" aria-label="Nowa forma zajęć">' . $_mm_opts(TI_MM_METHODS) . '</select>');
      $_mm_row('meeting_url', 'Link do spotkania', '<input class="form-control form-control-sm mm-val" type="url" name="val_meeting_url" placeholder="https://… (puste = wyczyść)" aria-label="Link do spotkania">');
      $_mm_row('room_id', 'Sala', '<select class="form-select form-select-sm mm-val" name="val_room_id" aria-label="Nowa sala">' . $_mm_room_opts . '</select>');
      $_mm_row('time_from', 'Godzina rozpoczęcia', '<input class="form-control form-control-sm mm-val" type="time" name="val_time_from" aria-label="Nowa godzina rozpoczęcia">');
      $_mm_row('time_to', 'Godzina zakończenia', '<input class="form-control form-control-sm mm-val" type="time" name="val_time_to" aria-label="Nowa godzina zakończenia">');
      $_mm_row('shift_days', 'Przesuń daty o (dni)', '<input class="form-control form-control-sm mm-val" type="number" name="val_shift_days" value="7" min="-365" max="365" aria-label="Przesunięcie dat w dniach (ujemne = wstecz)">');
      $_mm_row('topic', 'Temat', '<input class="form-control form-control-sm mm-val" type="text" name="val_topic" maxlength="300" placeholder="puste = wyczyść" aria-label="Nowy temat">');
      $_mm_row('notes', 'Notatki', '<input class="form-control form-control-sm mm-val" type="text" name="val_notes" maxlength="2000" placeholder="puste = wyczyść" aria-label="Nowe notatki">');
      $_mm_row('has_homework', 'Zadanie domowe', '<select class="form-select form-select-sm mm-val" name="val_has_homework" aria-label="Zadanie domowe"><option value="1">tak</option><option value="0">nie</option></select>');
      $_mm_row('self_prep_remote', 'Praca własna zdalnie', '<select class="form-select form-select-sm mm-val" name="val_self_prep_remote" aria-label="Praca własna zdalnie"><option value="1">tak</option><option value="0">nie</option></select>');
      $_mm_row('date_flag', 'Pewność terminu', '<select class="form-select form-select-sm mm-val" name="val_date_flag" aria-label="Pewność terminu">' . implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '">' . h($v['label']) . '</option>', array_keys(K30_TI_DATE_FLAGS), K30_TI_DATE_FLAGS)) . '</select>');
      if ($_mm_staff) $_mm_row('instructor_id', 'Prowadzący (zastępstwo)', '<select class="form-select form-select-sm mm-val" name="val_instructor_id" aria-label="Prowadzący"><option value="0">— domyślny kursu —</option>' . implode('', array_map(fn($i) => '<option value="' . (int)$i['id'] . '">' . h($i['name']) . '</option>', $_mm_instrs)) . '</select>');
      ?>
    </fieldset>
    <button type="button" class="btn btn-primary" id="mmPreview"><i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd i zatwierdzenie zmiany…</button>
    <span class="small text-body-secondary ms-2">Przed zapisem zobaczysz listę terminów, ostrzeżenia (kolizje, Zoom, brak linku) oraz podgląd maila do kursantów z harmonogramem w załączniku.</span>
  </form>
      </div>
    </div>
  </div>
</div>

<script src="../assets/dydApprovalModal.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/dydApprovalModal.js') ?>"></script>
<script>
(function () {
  var form = document.getElementById('mmForm'); if (!form) return;
  var cid = <?= (int)$cur_course ?>, token = <?= json_encode(dyd_token()) ?>;
  [].forEach.call(form.querySelectorAll('.mm-val'), function (v) {   // zmiana wartości = automatyczne zaznaczenie „zmień to pole"
    v.addEventListener('input', function () { var c = form.querySelector('.mm-chg[data-f="' + v.name.replace('val_', '') + '"]'); if (c) c.checked = true; });
  });
  document.getElementById('mmPreview').addEventListener('click', function () {
    var pairs = [];
    new FormData(form).forEach(function (val, key) {
      var f = key.indexOf('val_') === 0 ? key.slice(4) : null;
      if (f && !form.querySelector('.mm-chg[data-f="' + f + '"]').checked) return;   // wartości niezaznaczonych pól pomijamy
      pairs.push([key, val]);
    });
    var only = document.getElementById('mmOnlyDrafts');
    if (only && only.checked) {
      var picked = [].filter.call(document.querySelectorAll('.appr-chk'), function (c) { return c.checked; }).map(function (c) { return c.value; });
      if (!picked.length) { alert('Zaznacz na liście wersje robocze, których zmiana ma dotyczyć.'); return; }
      pairs.push(['ids', picked.join(',')]);
    }
    var qs = new URLSearchParams(); qs.append('course', cid); pairs.forEach(function (p) { qs.append(p[0], p[1]); });
    dydApprovalModal({ title: 'Masowa zmiana terminów', previewUrl: 'mass_edit.php?' + qs.toString(), postUrl: 'mass_edit.php',
      pairs: [['_token', token], ['course', cid]].concat(pairs), confirmLabel: 'Zmień i wyślij e-mail', confirmNoMailLabel: 'Zmień terminy' });
  });
})();
</script>
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
    <?php $_n_drafts = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND status='draft' AND draft_id IS NULL", [(int)$cur_course])['n'] ?? 0); ?>
    <div class="ms-auto d-flex align-items-center gap-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#dydPrintModal">
        <i class="bi bi-printer me-1" aria-hidden="true"></i>Wydruki<?php if ($_n_drafts > 0): ?> <span class="badge text-bg-secondary" title="Wersje robocze"><?= $_n_drafts ?></span><?php endif; ?>
      </button>
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
          <li><h6 class="dropdown-header">Wydruki i kalendarz</h6></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#dydPrintModal">
            <i class="bi bi-printer me-2"></i>Wydruki… (plan, frekwencja, raporty, robocze)
          </a></li>
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#dydCalSubModal">
            <i class="bi bi-calendar-check me-2"></i>Subskrybuj / pobierz kalendarz
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
      <div class="btn-group btn-group-sm" role="group" aria-label="Widok lekcji">
        <input type="radio" class="btn-check" name="lekWidok" id="lekWidokSek" value="sekcje" autocomplete="off" checked>
        <label class="btn btn-outline-primary" for="lekWidokSek"><i class="bi bi-list-nested me-1" aria-hidden="true"></i>Sekcje</label>
        <input type="radio" class="btn-check" name="lekWidok" id="lekWidokLista" value="lista" autocomplete="off">
        <label class="btn btn-outline-primary" for="lekWidokLista"><i class="bi bi-table me-1" aria-hidden="true"></i>Tabela</label>
        <input type="radio" class="btn-check" name="lekWidok" id="lekWidokKal" value="kalendarz" autocomplete="off">
        <label class="btn btn-outline-primary" for="lekWidokKal"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Kalendarz</label>
        <input type="radio" class="btn-check" name="lekWidok" id="lekWidokOs" value="os" autocomplete="off">
        <label class="btn btn-outline-primary" for="lekWidokOs"><i class="bi bi-card-list me-1" aria-hidden="true"></i>Oś czasu</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addL">
        <i class="bi bi-plus-lg me-1"></i>Dodaj lekcję
      </button>
    </div>
    <?php if ($sessions): ?>
    <div class="w-100 lek-filters" id="dyd-lekcje-filters" style="display:none" role="search" aria-label="Filtry lekcji">
      <div class="lek-f-search"><i class="bi bi-search" aria-hidden="true"></i>
      <input type="search" class="form-control form-control-sm" id="lek_search"
             placeholder="Szukaj: temat, status, prowadzący…" aria-label="Filtruj lekcje po tekście"></div>
      <?php
        $_lek_months = [];
        foreach ($sessions as $_ls) { $_ym = substr((string)$_ls['lesson_date'], 0, 7); if ($_ym !== '') $_lek_months[$_ym] = true; }
        krsort($_lek_months);
        $_months_pl_short = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
      ?>
      <select class="form-select form-select-sm lek-f-month" id="lek_month" aria-label="Filtruj po miesiącu">
        <option value="">Wszystkie miesiące</option>
        <?php foreach (array_keys($_lek_months) as $_ym): [$_yy,$_mm] = explode('-', $_ym); ?>
        <option value="<?= h($_ym) ?>"><?= $_months_pl_short[(int)$_mm] ?> <?= $_yy ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" class="form-control form-control-sm lek-f-date" id="lek_date" aria-label="Filtruj po konkretnej dacie">
      <button type="button" class="btn btn-sm btn-link lek-f-reset" id="lek_filter_reset"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Wyczyść</button>
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
  <details class="lek-legend">
    <summary><i class="bi bi-info-circle" aria-hidden="true"></i>Legenda: statusy i formy zajęć</summary>
    <div class="lek-legend-in">
      <ul class="lek-legend-grid" aria-label="Statusy lekcji">
        <?php foreach ($STATUS as $_sk => $_sv): ?>
        <li><span class="badge" style="background:<?= h($_sv['bg']) ?>;color:<?= h($_sv['color']) ?>;border:1px solid <?= h($_sv['color']) ?>44"><?= h($_sv['label']) ?></span>
          <span><?= h($_sdesc[$_sk] ?? '') ?></span></li>
        <?php endforeach; ?>
      </ul>
      <ul class="lek-legend-forms" aria-label="Formy zajęć">
        <li><i class="bi bi-geo-alt-fill" style="color:#065F46" aria-hidden="true"></i>stacjonarne</li>
        <li><i class="bi bi-camera-video-fill" style="color:#1D4ED8" aria-hidden="true"></i>Zoom — obciąża konto Zoom</li>
        <li><i class="bi bi-display" style="color:#6B21A8" aria-hidden="true"></i>zdalne inaczej (Teams, telefon, link)</li>
      </ul>
    </div>
  </details>

  <?php
    $_today   = date('Y-m-d');
    $_days_pl = ['Nd','Pn','Wt','Śr','Cz','Pt','So'];
    // Mapa id→imię prowadzących — do odznaczenia zastępstwa (lekcja.instructor_id != kurs.instructor_id)
    $_instr_names = [];
    foreach (k30_ti_instructors() as $_ins) $_instr_names[(int)$_ins['id']] = (string)$_ins['name'];
  ?>
  <?php if ($recurring_rules):
    $_rec_pos_lbl = ['1'=>'Pierwszy','2'=>'Drugi','3'=>'Trzeci','4'=>'Czwarty','last'=>'Ostatni'];
    $_rr_fmt = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '…';
    $_rr_rooms = [];
    foreach (db_all("SELECT id, name, short_label FROM k30_pl_rooms") as $_rm) $_rr_rooms[(int)$_rm['id']] = trim((string)$_rm['short_label']) !== '' ? (string)$_rm['short_label'] : (string)$_rm['name'];
  ?>
  <div class="lek-recur">
    <h3 class="lek-recur-h"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Zajęcia stałe <span class="badge rounded-pill"><?= count($recurring_rules) ?></span></h3>
    <?php /* Akcje masowe na zaznaczonych regułach */ ?>
    <form method="post" id="rrBulk" class="lek-recur-bulk"
          onsubmit="var n=document.querySelectorAll('input[name=&quot;rule_ids[]&quot;][form=rrBulk]:checked').length; if(!n){alert('Zaznacz co najmniej jedną regułę.');return false;} var s=this.querySelector('#rrBulkAct'); if(!s.value){alert('Wybierz akcję.');return false;} if(s.value==='future') this.del_future.value='1'; else this.del_future.value=''; return confirm(s.options[s.selectedIndex].text+' — zaznaczonych reguł: '+n+'?\n\n'+s.options[s.selectedIndex].getAttribute('data-desc'));">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="delete_recurring_rule">
      <input type="hidden" name="_tab" value="lekcje">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <input type="hidden" name="del_future" value="">
      <span class="small fw-semibold" id="rrBulkN" role="status" aria-live="polite">Zaznaczone: 0</span>
      <label class="visually-hidden" for="rrBulkAct">Akcja dla zaznaczonych reguł</label>
      <select class="form-select form-select-sm w-auto" id="rrBulkAct" aria-describedby="rrBulkDesc"
              onchange="var o=this.options[this.selectedIndex]; document.getElementById('rrBulkDesc').textContent=o.getAttribute('data-desc')||'Zaznacz reguły w tabeli i wybierz akcję — tu pojawi się jej opis.';">
        <option value="" data-desc="">— akcja dla zaznaczonych —</option>
        <option value="rule" data-desc="Usuwa same reguły; utworzone już lekcje zostają w planie jako zwykłe terminy.">Usuń reguły (lekcje zostają)</option>
        <option value="future" data-desc="Usuwa reguły oraz ich nadchodzące zaplanowane lekcje. Odbyte i odwołane terminy oraz obecności nie są ruszane.">Usuń reguły + nadchodzące lekcje</option>
      </select>
      <button class="btn btn-sm btn-primary"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Wykonaj</button>
      <span class="small text-body-secondary" id="rrBulkDesc">Zaznacz reguły w tabeli i wybierz akcję — tu pojawi się jej opis.</span>
    </form>
    <div class="table-responsive">
      <table class="table table-sm align-middle lek-recur-table mb-2">
        <caption class="visually-hidden">Zajęcia stałe grupy — reguły powtarzania terminów z akcjami</caption>
        <thead><tr>
          <th scope="col" style="width:2rem"><input type="checkbox" class="form-check-input" aria-label="Zaznacz wszystkie reguły"
            onclick="document.querySelectorAll('input[name=&quot;rule_ids[]&quot;][form=rrBulk]').forEach(function(x){x.checked=this.checked}.bind(this)); rrBulkCount()"></th>
          <th scope="col">Zajęcia</th><th scope="col">Powtarzanie</th><th scope="col">Godziny</th><th scope="col">Okres</th>
          <th scope="col">Sala</th><th scope="col" class="text-end">Nadchodzące</th><th scope="col" class="text-end">Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recurring_rules as $rr):
          $rr_monthly = ($rr['recur_mode'] ?? 'weekly') === 'monthly';
          $rr_dow = $rr_monthly
              ? (K30_TI_DAYS[(int)($rr['recur_dow'] ?? 1)] ?? '?')
              : ['Nd','Pn','Wt','Śr','Cz','Pt','So'][(int)date('w', strtotime((string)$rr['date_from']))];
          $rr_pattern = $rr_monthly
              ? ($_rec_pos_lbl[(string)($rr['recur_position'] ?? '1')] ?? '?') . ' ' . mb_strtolower($rr_dow) . ' miesiąca'
              : ((int)$rr['interval_weeks'] > 1 ? 'co ' . (int)$rr['interval_weeks'] . ' tyg. · ' : 'co tydzień · ') . $rr_dow;
          $rr_upcoming = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE series_id=? AND lesson_date >= date('now') AND status='planned'", [(int)$rr['id']])['n'] ?? 0);
          $_rr_title = trim((string)$rr['topic']) !== '' ? (string)$rr['topic'] : 'Zajęcia (' . $rr_pattern . ')';
          $_rr_past = !empty($rr['date_to']) && (string)$rr['date_to'] < $_today;
        ?>
          <tr<?= $_rr_past ? ' class="text-body-secondary"' : '' ?>>
            <td><input type="checkbox" name="rule_ids[]" value="<?= (int)$rr['id'] ?>" form="rrBulk" class="form-check-input" aria-label="Zaznacz regułę: <?= h($_rr_title) ?>"></td>
            <th scope="row" class="fw-semibold"><?= h(mb_strtoupper(mb_substr($_rr_title, 0, 1)) . mb_substr($_rr_title, 1)) ?></th>
            <td class="small"><?= h($rr_pattern) ?></td>
            <td class="small text-nowrap"><?= $rr['time_from'] ? h($rr['time_from'] . '–' . $rr['time_to']) : '—' ?></td>
            <td class="small text-nowrap"><?= h($_rr_fmt($rr['date_from'])) ?> – <?= h($_rr_fmt($rr['date_to'])) ?><?= $_rr_past ? ' <span class="badge text-bg-secondary">zakończona</span>' : '' ?></td>
            <td class="small"><?= !empty($rr['room_id']) && isset($_rr_rooms[(int)$rr['room_id']]) ? h($_rr_rooms[(int)$rr['room_id']]) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-end"><?= $rr_upcoming ?></td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę „<?= h(addslashes($_rr_title)) ?>”? Utworzone lekcje zostaną w planie.')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete_recurring_rule">
                <input type="hidden" name="_tab" value="lekcje"><input type="hidden" name="course_id" value="<?= $cur_course ?>"><input type="hidden" name="rule_id" value="<?= (int)$rr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-2" title="Usuwa regułę; utworzone lekcje zostają"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Usuń regułę</button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę „<?= h(addslashes($_rr_title)) ?>” ORAZ jej nadchodzące zaplanowane lekcje (<?= $rr_upcoming ?>)?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete_recurring_rule">
                <input type="hidden" name="_tab" value="lekcje"><input type="hidden" name="course_id" value="<?= $cur_course ?>"><input type="hidden" name="rule_id" value="<?= (int)$rr['id'] ?>"><input type="hidden" name="del_future" value="1">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuwa regułę i jej nadchodzące zaplanowane lekcje"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń z lekcjami</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
    function rrBulkCount(){ var n=document.querySelectorAll('input[name="rule_ids[]"][form=rrBulk]:checked').length; document.getElementById('rrBulkN').textContent='Zaznaczone: '+n; }
    document.addEventListener('change', function(e){ if (e.target.matches('input[name="rule_ids[]"][form=rrBulk]')) rrBulkCount(); });
    </script>
  </div>
  <?php endif; ?>


  <style>
  /* ── Nagłówek Lekcji: filtry w jednym rzędzie, zwijana legenda, karty zajęć stałych ── */
  .lek-filters { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
  .lek-filters[style*="display: none"], .lek-filters[style*="display:none"] { display: none !important; }
  .lek-f-search { position: relative; flex: 1 1 260px; min-width: 200px; }
  .lek-f-search .bi { position: absolute; left: .6rem; top: 50%; transform: translateY(-50%); color: #6b7685; font-size: .85rem; pointer-events: none; }
  .lek-f-search input { padding-left: 1.9rem; }
  .lek-f-month { flex: 0 0 auto; width: auto; min-width: 11.5rem; }
  .lek-f-date { flex: 0 0 auto; width: 10.5rem; }
  .lek-f-reset { text-decoration: none; padding-left: .25rem; padding-right: .25rem; }
  .lek-legend { border-bottom: 1px solid #e3e8ef; }
  .lek-legend > summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem; padding: .5rem 1rem; font-size: .8rem; font-weight: 600; color: #0b4a8f; }
  .lek-legend > summary::-webkit-details-marker { display: none; }
  .lek-legend > summary::after { content: '▸'; font-size: .7rem; }
  .lek-legend[open] > summary::after { content: '▾'; }
  .lek-legend > summary:focus-visible { outline: 2px solid #0b4a8f; outline-offset: -2px; }
  .lek-legend-in { padding: 0 1rem .75rem; }
  .lek-legend-grid { list-style: none; margin: 0 0 .5rem; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: .35rem 1.25rem; font-size: .78rem; color: #3d4757; }
  .lek-legend-grid li { display: flex; align-items: baseline; gap: .5rem; }
  .lek-legend-grid .badge { flex: 0 0 9.5rem; white-space: normal; text-align: center; }
  .lek-legend-forms { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .3rem 1.25rem; font-size: .78rem; color: #3d4757; }
  .lek-legend-forms .bi { margin-right: .35rem; }
  .lek-recur { padding: .75rem 1rem .25rem; }
  .lek-recur-h { font-size: .82rem; font-weight: 700; color: #10335c; margin: 0 0 .5rem; display: flex; align-items: center; gap: .4rem; }
  .lek-recur-h .badge { background: #10335c; font-size: .66rem; }
  .lek-recur-card { display: flex; align-items: flex-start; gap: .65rem; padding: .6rem .7rem; border: 1px solid #d3dce7; border-left: 3px solid #0b4a8f; border-radius: 4px; background: #fff; height: 100%; }
  .lek-recur-ico { flex: 0 0 auto; width: 2rem; height: 2rem; border-radius: 6px; display: grid; place-items: center; background: #e6f1fc; color: #0b4a8f; }
  .lek-recur-t { font-weight: 700; font-size: .86rem; color: #10335c; }
  .lek-recur-d { font-size: .78rem; color: #4a5566; }
  .lek-recur-n { font-size: .74rem; color: #6b7685; margin-top: .1rem; }
  .lek-recur-bulk { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .4rem .6rem; margin-bottom: .5rem; border: 1px solid #d3dce7; border-radius: 4px; background: #f6f9fc; }
  .lek-recur-table { background: #fff; border: 1px solid #d3dce7; }
  .lek-recur-table thead th { background: #eef3f9; color: #10335c; font-size: .78rem; }
  .lek-recur-table td, .lek-recur-table th { font-size: .82rem; }
  /* ── Widok „Sekcje”: nagłówki sekcji w tabeli ─────────────────────────── */
  #dyd-table-lekcje .dyd-lek-sec > th { background: #f3f6fa; border-top: 2px solid #d3dce7; padding: 0 !important; }
  #dyd-table-lekcje .dyd-lek-sec-btn { all: unset; box-sizing: border-box; width: 100%; cursor: pointer;
      display: flex; align-items: baseline; gap: .5rem; padding: .45rem .6rem; font-weight: 700; color: #10335c; }
  #dyd-table-lekcje .dyd-lek-sec-btn:focus-visible { outline: 2px solid #0b4a8f; outline-offset: -2px; }
  #dyd-table-lekcje .dyd-lek-sec-btn .badge { background: #10335c; color: #fff; font-size: .66rem; border-radius: 999px; }
  #dyd-table-lekcje .dyd-lek-sec-d { font-weight: 400; font-size: .74rem; color: #5b6677; margin-left: auto; }
  #dyd-table-lekcje .dyd-lek-sec-todo > th { background: #fff7e6; border-top-color: #e8c774; }
  #dyd-table-lekcje .dyd-lek-sec-todo .badge { background: #8a5a00; }
  #dyd-table-lekcje .dyd-lek-sec-cancelled .dyd-lek-sec-btn { color: #6c757d; }
  #dyd-table-lekcje .dyd-lek-more td { text-align: center; padding: .4rem !important; }

  /* ── Widok „Oś czasu”: te same wiersze jako karty pod nagłówkami miesięcy ── */
  #dyd-table-lekcje.dyd-lek-os thead { display: none; }
  #dyd-table-lekcje.dyd-lek-os table, #dyd-table-lekcje.dyd-lek-os tbody { display: block; width: 100%; }
  #dyd-table-lekcje.dyd-lek-os .table-responsive { overflow: visible; }
  #dyd-table-lekcje.dyd-lek-os tr.dyd-lek-month { display: block; background: none !important; }
  #dyd-table-lekcje.dyd-lek-os tr.dyd-lek-month th { display: block; border: 0; padding: .9rem .25rem .3rem !important;
      color: #10335c; font-size: .78rem; letter-spacing: .04em; background: none !important; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] {
      display: grid; grid-template-columns: 5.5rem 1fr auto; grid-template-areas: "date topic att" "time status actions";
      column-gap: .75rem; row-gap: .2rem; align-items: center; margin: 0 0 .5rem; padding: .55rem .7rem;
      background: #fff !important; border: 1px solid #d3dce7; border-left-width: 4px; border-radius: 6px;
      box-shadow: 0 1px 2px rgba(16,51,92,.06); position: relative; }
  /* Karta z otwartym menu „Więcej" ma być nad kolejnymi kartami (inaczej menu jest zasłaniane). */
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item]:has(.dropdown-menu.show) { z-index: 1060; }
  #dyd-table-lekcje .dropdown-menu.show { z-index: 1060; background-color: #fff; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item][hidden], #dyd-table-lekcje.dyd-lek-os tr[style*="display: none"] { display: none; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td { display: block; border: 0 !important; padding: 0 !important; background: none !important; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(1) { grid-area: date; font-size: 1rem !important; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(2) { grid-area: time; color: #5b6677; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(3) { grid-area: status; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(4) { grid-area: topic; font-weight: 600; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(5) { grid-area: att; text-align: right !important; }
  #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(6) { grid-area: actions; text-align: right !important; }
  @media (max-width: 575.98px) {
    #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] { grid-template-columns: 1fr auto;
        grid-template-areas: "date att" "topic topic" "time status" "actions actions"; }
    #dyd-table-lekcje.dyd-lek-os tr[data-filter-item] > td:nth-child(6) { text-align: left !important; }
  }
  </style>

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
          if ((string)$s['lesson_date'] === '') continue; // wersja robocza bez terminu — widoczna tylko w panelu „Wersje robocze”
          $st      = $STATUS[$s['status']] ?? $STATUS['planned'];
          $sdate   = strtotime($s['lesson_date']);
          $_mkey   = date('Y-m', $sdate);
          if ($_mkey !== $_last_month):
            $_last_month = $_mkey;
        ?>
        <tr class="table-light dyd-lek-month">
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
        <?php
          // Sekcja w widoku „Sekcje”: odwołane / do uzupełnienia (minione zaplanowane
          // albo odbyte bez kompletnej dokumentacji) / nadchodzące / odbyte.
          $_held = in_array($s['status'], K30_TI_HELD_STATUSES, true);
          $_sec  = $s['status'] === 'cancelled' ? 'cancelled'
                 : (($_held && empty($s['docs_complete'])) || (!$_held && $is_past) ? 'todo'
                 : ($_held ? 'done' : 'upcoming'));
        ?>
        <tr data-filter-item="1" data-date="<?= h($s['lesson_date']) ?>" data-time="<?= h(substr((string)$s['time_from'], 0, 5)) ?>" data-section="<?= $_sec ?>"
            style="border-left:3px solid <?= h($st['color']) ?>;<?= $s['status']==='remote_material' ? 'background:'.$st['bg'] : '' ?>"
            class="<?= $is_past && $s['status']==='planned' ? 'opacity-75' : '' ?>">
          <td style="font-size:.82rem;line-height:1.3">
            <a href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>" data-dyd-lesson="<?= (int)$s['id'] ?>"
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
            <?php
              // Odznaki dodatkowe — jako ikony w jednej linii zamiast osobnych bloków,
              // żeby wiersz nie rozciągał się na kilka linii (pełny opis w title/tooltip).
              $_s_instr = (int)($s['instructor_id'] ?? 0);
              $_flags = [];
              if ($_s_instr && $_s_instr !== (int)($course['instructor_id'] ?? 0)) {
                  $_flags[] = ['icon' => 'person-workspace', 'cls' => 'text-warning-emphasis bg-warning-subtle border-warning-subtle',
                               'title' => 'Zastępstwo: ' . ($_instr_names[$_s_instr] ?? ('#' . $_s_instr))];
              }
              if (in_array($s['status'], K30_TI_HELD_STATUSES, true) && empty($s['docs_complete'])) {
                  $_flags[] = ['icon' => 'exclamation-triangle-fill', 'cls' => 'text-danger-emphasis bg-danger-subtle border-danger-subtle',
                               'title' => 'Dokumentacja niekompletna — wpisz temat i obecność albo zaznacz w menu „Więcej”'];
              }
              if (!empty($s['rescheduled_from_date'])) {
                  $_flags[] = ['icon' => 'calendar2-range', 'cls' => 'text-info-emphasis bg-info-subtle border-info-subtle',
                               'title' => 'Przeniesiono z ' . date('d.m.Y', strtotime((string)$s['rescheduled_from_date']))];
              }
            ?>
            <?php if ($_flags): ?>
            <div class="mt-1">
              <?php foreach ($_flags as $_f): ?>
              <span class="badge <?= $_f['cls'] ?> border me-1" title="<?= h($_f['title']) ?>" data-bs-toggle="tooltip">
                <i class="bi bi-<?= $_f['icon'] ?>" aria-hidden="true"></i><span class="visually-hidden"><?= h($_f['title']) ?></span>
              </span>
              <?php endforeach; ?>
            </div>
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
              <a href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>" data-dyd-lesson="<?= (int)$s['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-2"
                 aria-label="Wejdź do lekcji <?= h(date('d.m.Y', $sdate)) ?>">
                <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Wejdź
              </a>
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
              <?php elseif (in_array($s['status'], K30_TI_HELD_STATUSES, true) && empty($s['docs_complete'])): ?>
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                      onclick="wizOpenExt(<?= (int)$s['id'] ?>)"
                      title="Dokumentacja niekompletna — dopisz temat kreatorem"
                      aria-label="Uzupełnij temat lekcji: <?= h(date('d.m.Y', $sdate)) ?>">
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
                  <?php if ($s['status'] !== 'remote_material'): ?>
                  <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#attL<?= (int)$s['id'] ?>">
                    <i class="bi bi-people me-2"></i>Obecność
                  </a></li>
                  <?php endif; ?>
                  <?php if ($s['status'] === 'remote_material' && $_mat_url): ?>
                  <li><a class="dropdown-item" href="<?= h($_mat_url) ?>" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-arrow-up me-2"></i>Materiał do pracy własnej
                  </a></li>
                  <?php endif; ?>
                  <?php if (dyd_is_staff()): ?>
                  <li><a class="dropdown-item" href="lekcja_pdf.php?id=<?= (int)$s['id'] ?>" target="_blank">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Karta lekcji (PDF)
                  </a></li>
                  <?php endif; ?>
                  <li><hr class="dropdown-divider"></li>
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
                  <?php if (dyd_is_staff() && in_array($s['status'], K30_TI_HELD_STATUSES, true)
                            && (empty($s['docs_complete']) || !empty($s['docs_manual']))): // uzupełniona automatycznie (obecność + temat) ?>
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
                  <li><a class="dropdown-item" href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje&lesson=<?= (int)$s['id'] ?>" data-dyd-lesson="<?= (int)$s['id'] ?>">
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
                  <?php if (dyd_is_staff()): ?>
                  <li><a class="dropdown-item text-danger" href="#"
                         onclick="dydOpenDelSession(<?= (int)$s['id'] ?>,<?= htmlspecialchars(json_encode(date('d.m.Y',$sdate).($s['time_from']?' '.h($s['time_from']):'')),ENT_QUOTES) ?>);return false">
                    <i class="bi bi-trash me-2"></i>Usuń lekcję (kosz, z uzasadnieniem)
                  </a></li>
                  <li>
                    <form method="post" onsubmit="return dydConfirmTwice('Odwołać lekcję z powodu błędu?', 'Na pewno? Lekcja trafi do kosza jako błędny wpis: nie liczy się do obecności ani statystyk i nie wysyła SMS-ów.')">
                      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                      <input type="hidden" name="_op" value="cancel_error">
                      <input type="hidden" name="_tab" value="lekcje">
                      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                      <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                      <input type="hidden" name="reason" value="Błąd wpisu — lekcja wprowadzona omyłkowo">
                      <button type="submit" class="dropdown-item text-danger">
                        <i class="bi bi-x-octagon me-2"></i>Odwołaj z powodu błędu
                      </button>
                    </form>
                  </li>
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
  // Przełącznik widoku: Sekcje (domyślny) / Tabela / Kalendarz / Oś czasu — wybór
  // w localStorage (nowy klucz: każdy raz zaczyna od Sekcji). Widoki listowe to
  // TE SAME wiersze tabeli (z akcjami) — Sekcje je przegrupowują, Oś czasu
  // zamienia w karty (CSS .dyd-lek-os), Tabela przywraca kolejność z miesiącami.
  var calWrap  = document.getElementById('dyd-cal-lekcje');
  var listWrap = document.getElementById('dyd-list-lekcje');
  var tblWrap  = document.getElementById('dyd-table-lekcje');
  var filters  = document.getElementById('dyd-lekcje-filters');
  var tbody    = tblWrap ? tblWrap.querySelector('tbody') : null;
  var original = tbody ? Array.prototype.slice.call(tbody.children) : [];
  var calRendered = false, KEY = 'dydLekcjeWidok2';
  var SEC = [
    ['todo',      'Do uzupełnienia', 'Minione bez obecności albo odbyte bez kompletnej dokumentacji', 'asc',  0],
    ['upcoming',  'Nadchodzące',     'Od dziś, najbliższe na górze',                                   'asc',  0],
    ['done',      'Odbyte',          'Najnowsze na górze',                                             'desc', 10],
    ['cancelled', 'Odwołane',        'Nie liczą się do rozliczenia',                                   'desc', -1]
  ];
  function key(r) { return (r.dataset.date || '') + ' ' + (r.dataset.time || ''); }
  function restore() { if (tbody) original.forEach(function (r) { tbody.appendChild(r); }); tbody && tbody.querySelectorAll('.dyd-lek-sec').forEach(function (h) { h.remove(); }); }
  function buildSections() {
    if (!tbody) return;
    restore();
    var rows = original.filter(function (r) { return r.dataset.section; });
    original.forEach(function (r) { if (!r.dataset.section) r.hidden = true; });   // nagłówki miesięcy
    SEC.forEach(function (sd) {
      var list = rows.filter(function (r) { return r.dataset.section === sd[0]; })
                     .sort(function (a, b) { return sd[3] === 'asc' ? key(a).localeCompare(key(b)) : key(b).localeCompare(key(a)); });
      if (!list.length) return;
      var h = document.createElement('tr'); h.className = 'dyd-lek-sec dyd-lek-sec-' + sd[0];
      var collapsed = sd[4] === -1, limit = sd[4] > 0 ? sd[4] : 0;
      h.innerHTML = '<th colspan="6" scope="colgroup"><button type="button" class="dyd-lek-sec-btn" aria-expanded="' + (!collapsed) + '">'
        + '<i class="bi bi-chevron-' + (collapsed ? 'right' : 'down') + '" aria-hidden="true"></i>'
        + '<span class="dyd-lek-sec-t">' + sd[1] + '</span> <span class="badge">' + list.length + '</span>'
        + '<span class="dyd-lek-sec-d">' + sd[2] + '</span></button></th>';
      tbody.appendChild(h);
      list.forEach(function (r, i) { r.hidden = collapsed || (limit && i >= limit); tbody.appendChild(r); });
      var more = null;
      if (limit && list.length > limit) {
        more = document.createElement('tr'); more.className = 'dyd-lek-sec dyd-lek-more';
        more.innerHTML = '<td colspan="6"><button type="button" class="btn btn-sm btn-outline-secondary">Pokaż wszystkie (' + list.length + ')</button></td>';
        more.querySelector('button').addEventListener('click', function () { list.forEach(function (r) { r.hidden = false; }); more.remove(); });
        tbody.appendChild(more);
      }
      h.querySelector('button').addEventListener('click', function () {
        var open = this.getAttribute('aria-expanded') !== 'true';
        this.setAttribute('aria-expanded', open); this.querySelector('.bi').className = 'bi bi-chevron-' + (open ? 'down' : 'right');
        list.forEach(function (r, i) { r.hidden = !open || (limit && more && more.isConnected && i >= limit); });
        if (more && more.isConnected) more.hidden = !open;
      });
    });
  }
  function show(v) {
    var cal = v === 'kalendarz';
    if (calWrap) calWrap.style.display = cal ? '' : 'none';
    if (listWrap) listWrap.style.display = cal ? 'none' : '';
    if (filters) filters.style.display = cal ? 'none' : '';
    if (tblWrap) tblWrap.classList.toggle('dyd-lek-os', v === 'os');
    if (tblWrap) tblWrap.classList.toggle('dyd-lek-sekcje', v === 'sekcje');
    if (v === 'sekcje') buildSections();
    else { restore(); original.forEach(function (r) { r.hidden = false; }); }
    if (cal && !calRendered && window.dydLekcjeCalendarInit) { window.dydLekcjeCalendarInit(); calRendered = true; }
    try { localStorage.setItem(KEY, v); } catch (e) {}
  }
  document.querySelectorAll('input[name="lekWidok"]').forEach(function (r) {
    r.addEventListener('change', function () { if (r.checked) show(r.value); });
  });
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (!/^(sekcje|lista|kalendarz|os)$/.test(saved || '')) saved = 'sekcje';
  var radio = document.querySelector('input[name="lekWidok"][value="' + saved + '"]');
  if (radio) radio.checked = true;
  show(saved);
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
// Dane do kreatora (wizOpenExt) — zaplanowane lekcje z dziś lub przeszłości,
// oraz odbyte lekcje z niekompletną dokumentacją (dopisanie tematu przyciskiem
// "Uzupełnij" w wierszu, patrz niżej).
$_ext_for_wiz = array_filter($sessions, fn($s) =>
    ($s['status'] === 'planned' && $s['lesson_date'] <= $_today)
    || (in_array($s['status'], K30_TI_HELD_STATUSES, true) && empty($s['docs_complete']))
);
$_wiz_ext_data = [];
foreach ($_ext_for_wiz as $_we) {
    $_watt = k30_ti_session_attendance((int)$_we['id']);
    $_wiz_ext_data[(int)$_we['id']] = [
        'label'     => ($_we['time_from'] ? substr($_we['time_from'],0,5).'–'.substr($_we['time_to']??'',0,5).' · ' : '') . ($_we['course_name'] ?? ''),
        'topic'     => (string)($_we['topic'] ?? ''),
        'status'    => (string)($_we['status'] ?? 'planned'),
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
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $lessonFormHtml(null, 'addL'); ?></div></div>
</div>
<?php foreach ($sessions as $s): ?>
<div class="modal fade" id="edL<?= (int)$s['id'] ?>" tabindex="-1" aria-labelledby="edL<?= (int)$s['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $lessonFormHtml($s, 'edL'.(int)$s['id']); ?></div></div>
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
        <div class="mb-2 p-2 rounded" style="background:#EFF6FF">
          <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="series_reservation" name="is_reservation" value="1"
                 onchange="document.getElementById('series_pesel_wrap').style.display=this.checked?'':'none'; if(this.checked){var d=document.getElementById('series_draft'); if(d) d.checked=false;}">
          <label class="form-check-label" for="series_reservation">
            <i class="bi bi-bookmark-star me-1" aria-hidden="true"></i>Cała seria to rezerwacja terminu (nie ostateczne lekcje)
          </label>
          </div>
          <div class="mt-2" id="series_pesel_wrap" style="display:none">
            <label class="form-label small mb-1" for="series_pesel">PESEL beneficjenta PFRON (opcjonalnie)</label>
            <input type="text" class="form-control form-control-sm" id="series_pesel" name="pfron_pesel" maxlength="11" inputmode="numeric" placeholder="11 cyfr">
            <div class="form-text mb-0">Temat każdej lekcji serii ustawi się jako „PFRON-XXX", pełny PESEL trafi do notatek.</div>
          </div>
        </div>
        <div class="mb-2 p-2 rounded" style="background:#F9FAFB">
          <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="series_draft" name="is_draft" value="1"
                 onchange="if(this.checked){var rv=document.getElementById('series_reservation'); if(rv && rv.checked){rv.checked=false; document.getElementById('series_pesel_wrap').style.display='none';}}">
          <label class="form-check-label" for="series_draft">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Cała seria jako wersja robocza (szkic)
          </label>
          </div>
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
        <div class="mb-2 p-2 rounded" style="background:#F9FAFB">
          <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="rec_draft" name="is_draft" value="1">
          <label class="form-check-label" for="rec_draft">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Cały cykl jako wersja robocza (szkic)
          </label>
          </div>
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
<?php include __DIR__ . '/_wydruki_modal.php'; ?>
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

<!-- Okno lekcji: karta lekcji ładowana w tle (?frag=1), fokus i Esc obsługuje Bootstrap -->
<div class="modal fade" id="dydLessonModal" tabindex="-1" aria-labelledby="dydLessonModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="dydLessonModalTitle">Lekcja</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij okno lekcji"></button>
      </div>
      <div class="modal-body" id="dydLessonBody" aria-live="polite"></div>
    </div>
  </div>
</div>
<script>
(function () {
  var modalEl = document.getElementById('dydLessonModal');
  var body    = document.getElementById('dydLessonBody');
  if (!modalEl || !body || typeof bootstrap === 'undefined') return;
  var trigger = null;

  // Wspólna funkcja zatrzymana przez kartę (obecność „Wszyscy/Wyczyść") — skrypt karty nie wykona się przy innerHTML.
  window.dydAttSetAll = window.dydAttSetAll || function (formSel, val) {
    document.querySelectorAll(formSel + ' .dyd-att-cb').forEach(function (cb) { cb.checked = val; });
  };

  function openLesson(id, from) {
    trigger = from || null;
    var url = new URL(window.location.href);
    url.searchParams.set('tab', 'lekcje');
    url.searchParams.set('lesson', id);
    url.searchParams.set('frag', '1');
    body.innerHTML = '<p class="text-body-secondary mb-0">Ładowanie lekcji…</p>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    fetch(url.toString(), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        body.innerHTML = html;
        // „Edytuj lekcję" otwiera modal z listy, którego w oknie nie ma — otwieramy edycję na pełnej stronie.
        body.querySelectorAll('[data-bs-target^="#edL"]').forEach(function (btn) {
          var sid = btn.getAttribute('data-bs-target').replace('#edL', '');
          btn.removeAttribute('data-bs-toggle');
          btn.removeAttribute('data-bs-target');
          btn.addEventListener('click', function () {
            var u = new URL(window.location.href);
            u.searchParams.set('tab', 'lekcje');
            u.searchParams.set('lesson', sid);
            u.searchParams.set('edit', sid);
            window.location.href = u.toString();
          });
        });
      })
      .catch(function () {
        body.innerHTML = '<div class="alert alert-danger mb-0" role="alert">Nie udało się załadować lekcji. Spróbuj ponownie.</div>';
      });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-dyd-lesson]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || (e.button && e.button !== 0)) return;
    e.preventDefault();
    openLesson(a.getAttribute('data-dyd-lesson'), a);
  });

  // Po zamknięciu wracamy fokusem do linku, który okno otworzył.
  modalEl.addEventListener('hidden.bs.modal', function () {
    if (trigger && document.contains(trigger)) trigger.focus();
    trigger = null;
  });

  // Powrót z „Edytuj" na pełnej stronie: otwórz modal edycji tej lekcji.
  var editId = new URLSearchParams(window.location.search).get('edit');
  if (editId) {
    var em = document.getElementById('edL' + editId);
    if (em) bootstrap.Modal.getOrCreateInstance(em).show();
  }
})();
</script>
<?php if (dyd_is_staff() && $cur_course): ?>
<!-- Modal: usunięcie lekcji przez kierownika (także z przeszłości) — wymaga uzasadnienia -->
<div class="modal fade" id="dydDelSessionModal" tabindex="-1" aria-labelledby="dydDelSession_t" aria-hidden="true">
  <div class="modal-dialog"><form method="post" class="modal-content">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="delete_lesson">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" id="ds_sid" value="">
    <div class="modal-header">
      <h5 class="modal-title" id="dydDelSession_t"><i class="bi bi-trash text-danger me-2"></i>Usunięcie lekcji</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="mb-2">Lekcja: <strong id="ds_label"></strong></p>
      <p class="text-body-secondary small mb-2">Lekcja trafi do kosza i może zostać przywrócona. Operacja zostanie zapisana w dzienniku grupy wraz z uzasadnieniem.</p>
      <label class="form-label fw-semibold" for="ds_reason">Uzasadnienie (min. 10 znaków)</label>
      <textarea class="form-control" id="ds_reason" name="reason" rows="3" required minlength="10" placeholder="np. lekcja wprowadzona omyłkowo jako duplikat…"></textarea>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Usuń lekcję</button>
    </div>
  </form></div>
</div>

<!-- Kosz lekcji grupy (tylko kierownik): przywracanie z uzasadnieniem -->
<?php
  $_trash_rows = ti_session_trash_list((int)$cur_course);
?>
<button type="button" class="btn btn-outline-secondary btn-sm mt-4" data-bs-toggle="modal" data-bs-target="#dydTrashModal" id="dydTrashOpen"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Kosz lekcji <span class="badge bg-secondary ms-1"><?= count($_trash_rows) ?></span></button>
<div class="modal fade" id="dydTrashModal" tabindex="-1" aria-labelledby="dydTrashModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="dydTrashModalTitle"><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Kosz lekcji</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij kosz lekcji"></button>
      </div>
      <div class="modal-body">
<div class="card" id="dydSessionTrash">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-trash3 me-2" aria-hidden="true"></i>
    <strong>Kosz lekcji</strong>
    <span class="badge bg-secondary ms-2"><?= count($_trash_rows) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (!$_trash_rows): ?>
      <div class="p-3 text-body-secondary small">Brak usuniętych lekcji w tej grupie.</div>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Termin</th><th>Usunięto</th><th>Powód</th><th>Stan</th><th style="min-width:280px">Przywrócenie</th></tr></thead>
        <tbody>
        <?php foreach ($_trash_rows as $_tr):
          $_snap = json_decode((string)$_tr['snapshot'], true) ?: [];
          $_ts   = $_snap['session']['time_from'] ?? '';
        ?>
          <tr>
            <td><?= h(date('d.m.Y', strtotime((string)$_tr['lesson_date']))) ?><?= $_ts ? ' ' . h($_ts) : '' ?></td>
            <td class="small"><?= h((string)$_tr['deleted_at']) ?><br><span class="text-body-secondary"><?= h((string)$_tr['deleted_by_name']) ?></span></td>
            <td class="small"><?= h((string)$_tr['reason']) ?></td>
            <td class="small">
              <?php if ($_tr['restored_at']): ?>
                <span class="badge bg-success">Przywrócona</span><br>
                <span class="text-body-secondary"><?= h((string)$_tr['restored_at']) ?> · <?= h((string)$_tr['restored_by_name']) ?></span><br>
                <span class="text-body-secondary"><?= h((string)$_tr['restored_reason']) ?></span>
              <?php else: ?>
                <span class="badge bg-danger">W koszu</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$_tr['restored_at']): ?>
              <form method="post" class="d-flex gap-2 align-items-start">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="restore_session">
                <input type="hidden" name="_tab" value="lekcje">
                <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                <input type="hidden" name="trash_id" value="<?= (int)$_tr['id'] ?>">
                <input type="text" class="form-control form-control-sm" name="reason" required minlength="10" placeholder="Uzasadnienie (min. 10 znaków)" aria-label="Uzasadnienie przywrócenia">
                <button type="submit" class="btn btn-sm btn-outline-success text-nowrap"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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
  <div class="modal-dialog"><form method="post" class="modal-content" onsubmit="return dydConfirmTwice('Odwołać tę lekcję?', 'Na pewno? Lekcja nie zostanie policzona do ceny, a kursanci dostaną SMS o odwołaniu.')">
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
