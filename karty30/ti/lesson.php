<?php
/**
 * karty30/ti/lesson.php — Lekcja TI: obecność, temat, uwagi prowadzącego, zadanie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$session_id = (int)($_GET['id'] ?? 0);
$session    = $session_id ? k30_ti_session_get($session_id) : null;

// Rola i podpis osoby odwołującej (po stronie kadry: Doradca lub administrator)
$cu           = current_user();
$cancel_role  = is_admin() ? 'admin' : 'doradca';
$cancel_label = $cu['name'] ?? ($cu['login'] ?? '');

if (!$session) {
    flash_set('danger', 'Lekcja nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Lekcja: ' . $session['course_name'];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Zapis obecności + metadanych lekcji (jeden formularz)
    if ($op === 'save_lesson') {
        $attended = array_map('intval', (array)($_POST['attended'] ?? []));

        // Kurs jednosobowy + jedyna osoba nieobecna → decyzja o rozliczeniu
        $solo_action = trim($_POST['_solo_absent_action'] ?? '');
        if ($solo_action === 'cancel') {
            // Admin wybrał „nie licz" — anuluj lekcję
            db()->prepare(
                "UPDATE k30_ti_sessions SET status='cancelled', cancel_reason='Nieobecność kursanta — lekcja niezaliczona',
                 updated_at=datetime('now') WHERE id=?"
            )->execute([$session_id]);
            flash_set('success', 'Lekcja odwołana — nie jest liczona do rozliczenia.');
            header('Location: lesson.php?id=' . $session_id); exit;
        }
        if (in_array($solo_action, ['no_show_full', 'no_show_1h'], true)) {
            // Zapisz obecność (nieobecny), metadane i oznacz jako no-show
            k30_ti_save_attendance($session_id, $attended);
            $billing = $solo_action === 'no_show_1h' ? '1h' : 'full';
            $reason  = trim($_POST['solo_absent_reason'] ?? '');
            // Oznacz każdego nieobecnego jako no-show
            $att_rows = db_all("SELECT client_id FROM k30_ti_attendance WHERE session_id=? AND COALESCE(cancelled,0)=0", [$session_id]);
            foreach ($att_rows as $ar) {
                if (!in_array((int)$ar['client_id'], $attended)) {
                    k30_ti_mark_no_show($session_id, (int)$ar['client_id'], $billing, 'admin', 'Administrator', $reason, null);
                }
            }
            $topic            = trim($_POST['topic'] ?? '');
            $instructor_notes = trim($_POST['instructor_notes'] ?? '');
            $has_homework     = !empty($_POST['has_homework']) ? 1 : 0;
            $self_prep_remote = !empty($_POST['self_prep_remote']) ? 1 : 0;
            $duration_min     = max(1, (int)($_POST['duration_min'] ?? $session['duration_min']));
            $time_from        = trim($_POST['time_from'] ?? $session['time_from']);
            $time_to          = trim($_POST['time_to']   ?? $session['time_to']);
            if ($time_from && $time_to) {
                $m = (strtotime('1970-01-01 '.$time_to) - strtotime('1970-01-01 '.$time_from)) / 60;
                if ($m > 0) $duration_min = (int)$m;
            }
            db()->prepare(
                "UPDATE k30_ti_sessions SET status='individual_change', topic=?, instructor_notes=?,
                 has_homework=?, self_prep_remote=?, duration_min=?, time_from=?, time_to=?,
                 updated_at=datetime('now') WHERE id=?"
            )->execute([$topic, $instructor_notes, $has_homework, $self_prep_remote, $duration_min, $time_from, $time_to, $session_id]);
            flash_set('success', 'Lekcja zapisana jako zajęcia indywidualne — brak kursanta (' . ($billing === '1h' ? '1 godzina' : 'cała lekcja') . ').');
            header('Location: lesson.php?id=' . $session_id); exit;
        }

        // Zapisz obecność (ogólna)
        k30_ti_save_attendance($session_id, $attended);

        // Zapisz indywidualne uwagi per uczestnik
        $ind_notes = (array)($_POST['ind_notes'] ?? []);
        foreach ($ind_notes as $cid => $note) {
            $cid = (int)$cid;
            $note = trim($note);
            try {
                db()->prepare(
                    "UPDATE k30_ti_attendance SET ind_notes=? WHERE session_id=? AND client_id=?"
                )->execute([$note, $session_id, $cid]);
            } catch (\Throwable $e) {}
        }

        // Metadane lekcji
        $topic            = trim($_POST['topic']            ?? '');
        $instructor_notes = trim($_POST['instructor_notes'] ?? '');
        $has_homework     = !empty($_POST['has_homework']) ? 1 : 0;
        $self_prep_remote = !empty($_POST['self_prep_remote']) ? 1 : 0;
        $duration_min     = max(1, (int)($_POST['duration_min'] ?? $session['duration_min']));
        $time_from        = trim($_POST['time_from'] ?? $session['time_from']);
        $time_to          = trim($_POST['time_to']   ?? $session['time_to']);
        // Przelicz czas trwania z od-do jeśli zmieniono godziny
        if ($time_from && $time_to) {
            $m = (strtotime('1970-01-01 '.$time_to) - strtotime('1970-01-01 '.$time_from)) / 60;
            if ($m > 0) $duration_min = (int)$m;
        }

        // Status po zapisie obecności
        $any_absent = !empty(array_filter(
            db_all("SELECT attended FROM k30_ti_attendance WHERE session_id=? AND COALESCE(cancelled,0)=0", [$session_id]),
            fn($r) => !$r['attended']
        ));
        $course_row     = db_one("SELECT is_subgroup FROM k30_ti_courses WHERE id=?", [$session['course_id']]);
        $is_subgroup    = !empty($course_row['is_subgroup']);
        $enrolled_count = (int)(db_one(
            "SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'",
            [$session['course_id']]
        )['n'] ?? 0);
        if ($enrolled_count === 0) {
            $new_status = 'cancelled';
        } elseif ($is_subgroup || $any_absent || $enrolled_count === 1) {
            $new_status = 'individual_change';
        } else {
            $new_status = 'held';
        }
        if ($session['lesson_date'] > date('Y-m-d') && in_array($new_status, ['held','individual_change'], true)) {
            flash_set('danger', 'Nie można oznaczyć lekcji z przyszłości jako odbytej.');
            header('Location: lesson.php?id=' . $session_id); exit;
        }
        db()->prepare(
            "UPDATE k30_ti_sessions
             SET status=?, topic=?, instructor_notes=?, has_homework=?, self_prep_remote=?,
                 duration_min=?, time_from=?, time_to=?, updated_at=datetime('now')
             WHERE id=?"
        )->execute([$new_status, $topic, $instructor_notes, $has_homework, $self_prep_remote, $duration_min, $time_from, $time_to, $session_id]);

        // Alert niskiej frekwencji — sprawdź aktywnych kursantów kursu
        foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [(int)$session['course_id']]) as $er) {
            try { k30_ti_check_low_attendance((int)$session['course_id'], (int)$er['client_id']); } catch (\Throwable $ex) {}
        }

        flash_set('success', 'Lekcja zapisana.');
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Zmiana statusu bez zapisu obecności
    if ($op === 'set_status') {
        // Lekcje z przeszłości (przed dzisiaj) — nie można odwoływać ani zmieniać terminu
        if ($session['lesson_date'] < date('Y-m-d') && ($_POST['status'] ?? '') === 'cancelled') {
            flash_set('danger', 'Nie można odwołać lekcji z przeszłości.');
            header('Location: lesson.php?id=' . $session_id); exit;
        }
        $st = array_key_exists($_POST['status'] ?? '', K30_TI_SESSION_STATUSES)
              ? $_POST['status'] : 'planned';
        if ($session['lesson_date'] > date('Y-m-d') && in_array($st, ['held','individual_change','remote_material'], true)) {
            flash_set('danger', 'Nie można oznaczyć lekcji z przyszłości jako odbytej.');
            header('Location: lesson.php?id=' . $session_id); exit;
        }
        if ($st === 'cancelled') {
            // Odwołanie całej lekcji — wymaga powodu
            $reason = trim($_POST['cancel_reason'] ?? '');
            if ($reason === '') {
                flash_set('danger', 'Podaj powód odwołania lekcji.');
                header('Location: lesson.php?id=' . $session_id); exit;
            }
            $sms_sent = k30_ti_cancel_session($session_id, $reason, $cancel_role, $cancel_label);
            flash_set('success', 'Lekcja odwołana — nie zostanie policzona do ceny.'
                . ($sms_sent ? " Wysłano SMS: {$sms_sent}." : ''));
        } else {
            // Powrót do planowanej / odbytej — czyścimy dane odwołania
            db()->prepare(
                "UPDATE k30_ti_sessions
                 SET status=?, cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL,
                     updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$st, $session_id]);
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Odwołanie udziału pojedynczego uczestnika (Doradca/admin) — nie liczone do ceny
    if ($op === 'cancel_attendee') {
        $cid    = (int)($_POST['client_id'] ?? 0);
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($cid && $reason !== '') {
            k30_ti_cancel_attendance($session_id, $cid, $reason, $cancel_role, $cancel_label);
            flash_set('success', 'Udział uczestnika odwołany — nie zostanie policzony do ceny.');
        } else {
            flash_set('danger', 'Podaj powód odwołania udziału.');
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Przywrócenie udziału uczestnika (cofnięcie odwołania)
    if ($op === 'restore_attendee') {
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($cid) {
            k30_ti_uncancel_attendance($session_id, $cid);
            flash_set('success', 'Udział uczestnika przywrócony.');
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Oznaczenie uczestnika jako „nie pojawił się" (no_show)
    if ($op === 'mark_no_show') {
        $cid     = (int)($_POST['client_id'] ?? 0);
        $billing = trim($_POST['no_show_billing'] ?? 'full');
        $reason  = trim($_POST['no_show_reason'] ?? '');
        $attachment = [];
        if (!empty($_FILES['no_show_screenshot']['name'])) {
            if (!function_exists('mail_queue_save_attachment')) @require_once dirname(__DIR__, 2) . '/includes/mail_queue.php';
            if (function_exists('mail_queue_save_attachment')) {
                $att = mail_queue_save_attachment($_FILES['no_show_screenshot']);
                if ($att) $attachment = $att;
            }
        }
        if ($cid) {
            k30_ti_mark_no_show($session_id, $cid, $billing, $cancel_role, $cancel_label, $reason, $attachment);
            flash_set('success', 'Oznaczono jako „nie pojawił się" — rozliczenie: ' . ($billing === '1h' ? '1 godzina' : 'cała lekcja') . '.');
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Potwierdzenie / odrzucenie prośby kursanta o odwołanie udziału (czeka na potwierdzenie)
    if ($op === 'confirm_cancel_req' || $op === 'reject_cancel_req') {
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($cid) {
            if ($op === 'confirm_cancel_req') {
                k30_ti_confirm_cancel_attendance($session_id, $cid);
                k30_ti_notify_student_cancel_decision($session_id, $cid, true);
                flash_set('success', 'Odwołanie potwierdzone — kursant został powiadomiony.');
            } else {
                k30_ti_uncancel_attendance($session_id, $cid);
                k30_ti_notify_student_cancel_decision($session_id, $cid, false);
                flash_set('success', 'Prośba o odwołanie odrzucona — kursant został powiadomiony.');
            }
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Zapis linku do lekcji online — bez zmiany statusu lekcji
    if ($op === 'save_link') {
        $url = trim($_POST['meeting_url'] ?? '');
        db()->prepare("UPDATE k30_ti_sessions SET meeting_url=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$url, $session_id]);
        flash_set('success', $url !== '' ? 'Link do lekcji zapisany.' : 'Link do lekcji usunięty.');
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }
}

// Przeładuj
$session    = k30_ti_session_get($session_id);
$attendance = k30_ti_session_attendance($session_id);
$st_info    = K30_TI_SESSION_STATUSES[$session['status']] ?? ['label' => $session['status'], 'color' => '#666', 'bg' => '#eee'];
$is_held    = in_array($session['status'], ['held', 'individual_change', 'remote_material']);
$is_past    = $session['lesson_date'] < date('Y-m-d'); // lekcja z dnia wcześniejszego niż dziś

// Indywidualne uwagi (pobierz z bazy)
$ind_notes_map = [];
try {
    $rows = db_all("SELECT client_id, ind_notes FROM k30_ti_attendance WHERE session_id=?", [$session_id]);
    foreach ($rows as $r) $ind_notes_map[(int)$r['client_id']] = $r['ind_notes'];
} catch (\Throwable $e) {}

// Liczba aktywnych zapisów i flaga podgrupy (obsługa kursu jednosobowego / podgrupy)
$_course_flags  = db_one("SELECT is_subgroup FROM k30_ti_courses WHERE id=?", [$session['course_id']]);
$is_subgroup_view = !empty($_course_flags['is_subgroup']);
$solo_enrolled  = (int)(db_one(
    "SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'",
    [$session['course_id']]
)['n'] ?? 0);

// Oceny lekcji od kursantów (1–5)
$ratings     = k30_ti_session_ratings($session_id);
$rating_avg  = $ratings ? round(array_sum(array_column($ratings, 'rating')) / count($ratings), 2) : 0;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<style>
.att-row.present  { background: #f0fdf4; }
.att-row.absent   { background: #fafafa; }
.att-row.cancelled{ background: #fef2f2; }
.att-row.no-show  { background: #fefce8; }
.att-row.pending  { background: #fffbeb; }
.att-cb           { width: 1.4em; height: 1.4em; flex-shrink: 0; cursor: pointer; }
.section-head     { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 12px; }
/* Karta główna — obecność na pierwszym planie */
.lesson-primary   { border: 2px solid #c2410c !important; }
.lesson-primary > .card-header { background: #fff7ed; }
/* Pasek statystyk podsumowania */
.lesson-stats     { display:flex; flex-wrap:wrap; gap:.5rem; }
.lesson-stat      { flex:1 1 8rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.6rem .9rem; }
.lesson-stat .lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
.lesson-stat .val { font-size:1.4rem; font-weight:800; line-height:1.1; }
/* Zwijana sekcja „Informacje o lekcji" */
details.lesson-card > summary { cursor:pointer; list-style:none; }
details.lesson-card > summary::-webkit-details-marker { display:none; }
details.lesson-card > summary .chev { transition: transform .15s ease; }
details.lesson-card[open] > summary .chev { transform: rotate(180deg); }
@media (prefers-reduced-motion: reduce){ details.lesson-card > summary .chev { transition:none; } }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="course.php?id=<?= (int)$session['course_id'] ?>"><?= h($session['course_name']) ?></a></li>
  <li class="breadcrumb-item active">Lekcja <?= date('d.m.Y', strtotime($session['lesson_date'])) ?></li>
</ol></nav>

<!-- Nagłówek -->
<div class="d-flex align-items-start mb-3 gap-2 flex-wrap">
  <div class="flex-grow-1">
    <h4 class="mb-0 fw-bold">
      <i class="bi bi-clipboard-check text-primary me-2"></i>
      <?= h($session['course_name']) ?>
      <span class="text-muted fw-normal fs-5">— <?= date('d.m.Y', strtotime($session['lesson_date'])) ?></span>
    </h4>
    <div class="text-muted small mt-1 d-flex align-items-center gap-2 flex-wrap">
      <?php if ($session['time_from']): ?>
      <span><i class="bi bi-clock me-1"></i><?= h($session['time_from']) ?>–<?= h($session['time_to']) ?> (<?= (int)$session['duration_min'] ?> min)</span>
      <?php else: ?>
      <span><?= (int)$session['duration_min'] ?> min</span>
      <?php endif; ?>
      <?php if ($session['instructor_name']): ?><span>·</span><span><?= h($session['instructor_name']) ?></span><?php endif; ?>
      <span class="badge" style="background:<?= h($st_info['bg']) ?>;color:<?= h($st_info['color']) ?>;border:1px solid <?= h($st_info['color']) ?>44">
        <?= h($st_info['label']) ?>
      </span>
      <?php if ($session['has_homework'] ?? 0): ?>
      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
        <i class="bi bi-pencil-square me-1"></i>Zadanie domowe
      </span>
      <?php endif; ?>
      <?php if ($session['self_prep_remote'] ?? 0): ?>
      <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
        <i class="bi bi-laptop me-1"></i>Praca własna — materiał zdalny
      </span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($can_write): ?>
  <div class="flex-shrink-0 d-flex align-items-center gap-2">
    <?php if ($session['status'] !== 'cancelled'): ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"     value="set_status">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (K30_TI_SESSION_STATUSES as $sk => $sv): if ($sk === 'cancelled') continue; ?>
        <option value="<?= h($sk) ?>" <?= $session['status']===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($is_past): ?>
    <span class="text-body-secondary small" title="Lekcji z przeszłości nie można odwołać">
      <i class="bi bi-lock me-1"></i>Odwołanie niedostępne
    </span>
    <?php else: ?>
    <button type="button" class="btn btn-sm btn-outline-danger"
            data-bs-toggle="modal" data-bs-target="#cancelLessonModal">
      <i class="bi bi-x-circle me-1"></i>Odwołaj lekcję
    </button>
    <?php endif; ?>
    <?php else: ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Przywrócić lekcję (status: zaplanowana)?')">
      <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"    value="set_status">
      <input type="hidden" name="status" value="planned">
      <button type="submit" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć lekcję
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Legenda statusów -->
<?php $_lesson_sdesc = [
  'draft'             => 'wstępny szkic z SZOPlanner, niezatwierdzony',
  'planned'           => 'zaplanowana, jeszcze się nie odbyła',
  'held'              => 'odbyła się normalnie z całą grupą',
  'individual_change' => 'odbyła się ze zmienionym składem uczestników',
  'remote_material'   => 'praca własna prowadzącego — bez listy obecności, liczona do rozliczenia',
  'cancelled'         => 'odwołana — nie jest liczona do rozliczenia',
]; ?>
<div class="mb-3">
  <details>
    <summary class="d-inline-flex align-items-center gap-1 text-body-secondary small" style="cursor:pointer;list-style:none">
      <i class="bi bi-info-circle" aria-hidden="true"></i> Objaśnienia statusów
    </summary>
    <div class="d-flex flex-wrap gap-2 mt-2">
      <?php foreach (K30_TI_SESSION_STATUSES as $_sk => $_sv): ?>
      <span class="d-inline-flex align-items-center gap-1 small"
            title="<?= h($_lesson_sdesc[$_sk] ?? '') ?>"
            data-bs-toggle="tooltip" data-bs-placement="top">
        <span class="rounded-circle flex-shrink-0" style="width:9px;height:9px;background:<?= h($_sv['color']) ?>;display:inline-block"></span>
        <strong style="color:<?= h($_sv['color']) ?>"><?= h($_sv['label']) ?></strong>
      </span>
      <?php endforeach; ?>
    </div>
  </details>
</div>

<?php
$_no_students = empty($attendance) && $session['status'] === 'planned';
if ($_no_students): ?>
<div class="alert alert-danger d-flex align-items-center gap-2">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
  <div>
    <strong>Brak zapisanych kursantów.</strong> Lekcja nie może się odbyć.
    Zapisz lekcję, aby automatycznie ustawić status na <em>Odwołana</em>,
    lub odwołaj ją ręcznie.
  </div>
</div>
<?php endif; ?>

<?php if ($session['status'] === 'cancelled'): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
  <i class="bi bi-x-octagon-fill mt-1"></i>
  <div>
    <div class="fw-semibold">Lekcja odwołana — nie liczona do ceny.</div>
    <?php if (!empty($session['cancel_reason'])): ?>
    <div class="mt-1"><span class="text-muted">Powód:</span> <?= h($session['cancel_reason']) ?></div>
    <?php endif; ?>
    <?php if (!empty($session['cancelled_by_role']) || !empty($session['cancelled_by'])):
      $role_lbl = K30_TI_CANCEL_ROLES[$session['cancelled_by_role']] ?? $session['cancelled_by_role']; ?>
    <div class="small text-muted mt-1">
      Odwołał(a): <?= h(trim(($role_lbl ? $role_lbl : '') . ($session['cancelled_by'] ? ' — '.$session['cancelled_by'] : ''))) ?>
      <?php if (!empty($session['cancelled_at'])): ?> · <?= h(date('d.m.Y H:i', strtotime($session['cancelled_at']))) ?><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<form method="post" id="lesson_form">
<input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="_op"   value="save_lesson">
<input type="hidden" name="_solo_absent_action" id="solo_absent_action" value="">
<input type="hidden" name="solo_absent_reason"  id="solo_absent_reason" value="">

<!-- ══ Podsumowanie (gdy odbyta) — pasek statystyk u góry ══ -->
<?php if ($is_held && $attendance):
  $present   = array_filter($attendance, fn($a) => $a['attended']);
  $total_h   = (float)$session['duration_min'] / 60;
  $total_pln = array_sum(array_map(fn($a) => $total_h * (float)$a['hourly_rate'], $present));
?>
<div class="lesson-stats mb-3">
  <div class="lesson-stat"><div class="lbl">Obecni</div><div class="val text-success"><?= count($present) ?><span class="text-muted fs-6">/<?= count($attendance) ?></span></div></div>
  <div class="lesson-stat"><div class="lbl">Czas lekcji</div><div class="val"><?= number_format($total_h,2,',','') ?> h</div></div>
  <div class="lesson-stat"><div class="lbl">Kwota</div><div class="val text-primary"><?= number_format($total_pln,2,',','') ?> zł</div></div>
</div>
<?php endif; ?>

<!-- ══ GŁÓWNE: Lista obecności (na pierwszym planie) ══ -->
<div class="card border-0 shadow-sm lesson-primary mb-3">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-person-check me-2 text-primary"></i>Lista obecności
    <span class="badge bg-secondary ms-2"><?= count($attendance) ?></span>
    <?php if ($can_write && $attendance): ?>
    <div class="ms-auto d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="toggleAll(true)">Wszyscy ✓</button>
      <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="toggleAll(false)">Brak ✗</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($session['status'] === 'remote_material'): ?>
  <div class="card-body">
    <div class="alert alert-info d-flex align-items-center gap-2 mb-0 py-2" style="font-size:.88rem">
      <i class="bi bi-person-workspace fs-5"></i>
      <div><strong>Praca własna prowadzącego (materiał zdalny)</strong> — dla tej lekcji <strong>nie liczymy obecności ani nieobecności</strong>. Lista poniżej ma charakter wyłącznie informacyjny i nie wchodzi do frekwencji.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$attendance): ?>
  <div class="card-body text-muted">
    Brak uczestników kursu.
    <a href="course.php?id=<?= (int)$session['course_id'] ?>#uczestnicy">Dodaj uczestników</a>.
  </div>
  <?php else: ?>
  <div class="list-group list-group-flush" id="att_list">
    <?php foreach ($attendance as $a):
      $cid       = (int)$a['client_id'];
      $cancelled = (int)($a['cancelled'] ?? 0) === 1;
      $pending   = (int)($a['cancel_pending'] ?? 0) === 1;
      $no_show   = !$cancelled && !$pending && (int)($a['no_show'] ?? 0) === 1;
      $present   = !$cancelled && !$no_show && (bool)$a['attended'];
      $note      = $ind_notes_map[$cid] ?? '';
      $role_lbl  = K30_TI_CANCEL_ROLES[$a['cancelled_by_role'] ?? ''] ?? ($a['cancelled_by_role'] ?? '');
      $ns_bill   = ($a['no_show_billing'] ?? 'full') === '1h' ? '1 godzina' : 'cała lekcja';
    ?>
    <div class="list-group-item att-row <?= $pending ? 'pending' : ($cancelled ? 'cancelled' : ($no_show ? 'no-show' : ($present ? 'present' : 'absent'))) ?> py-2 px-3"
         id="row_<?= $cid ?>">
      <div class="d-flex align-items-center gap-3">
        <input class="att-cb form-check-input" type="checkbox"
               name="attended[]" value="<?= $cid ?>"
               <?= $present ? 'checked' : '' ?>
               onchange="rowToggle(this)"
               aria-label="Obecny: <?= h($a['client_name']) ?>"
               <?= (!$can_write || $cancelled || $no_show) ? 'disabled' : '' ?>>
        <div class="flex-grow-1 min-width-0">
          <div class="fw-semibold text-truncate <?= ($cancelled || $no_show) ? 'text-muted' : '' ?>"><?= h($a['client_name']) ?></div>
          <?php if ($a['client_email']): ?>
          <div class="text-muted" style="font-size:.75rem"><?= h($a['client_email']) ?></div>
          <?php endif; ?>
        </div>
        <div class="text-muted text-end flex-shrink-0" style="font-size:.78rem">
          <?= number_format((float)$a['hourly_rate'], 2, ',', '') ?> zł/h
        </div>
        <?php if ($can_write): ?>
          <?php if ($pending): ?>
          <button type="button" class="btn btn-sm btn-success py-0 px-2 flex-shrink-0"
                  onclick="confirmCancelReq(<?= $cid ?>)" title="Potwierdź odwołanie udziału">
            <i class="bi bi-check-lg me-1"></i>Potwierdź
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
                  onclick="rejectCancelReq(<?= $cid ?>)" title="Odrzuć prośbę (przywróć udział)" aria-label="Odrzuć prośbę o odwołanie">
            <i class="bi bi-x-lg"></i>
          </button>
          <?php elseif ($cancelled || $no_show): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
                  onclick="restoreAtt(<?= $cid ?>)" title="Przywróć udział" aria-label="Przywróć udział: <?= h($a['client_name']) ?>">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
          <?php else: ?>
          <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2 flex-shrink-0"
                  onclick="openNoShow(<?= $cid ?>, <?= htmlspecialchars(json_encode($a['client_name']), ENT_QUOTES) ?>)"
                  title="Nie pojawił się na zajęciach" aria-label="Nie pojawił się: <?= h($a['client_name']) ?>">
            <i class="bi bi-dash-circle"></i>
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 flex-shrink-0"
                  onclick="openCancelAtt(<?= $cid ?>, <?= htmlspecialchars(json_encode($a['client_name']), ENT_QUOTES) ?>)"
                  title="Odwołaj udział (nie liczone do ceny)" aria-label="Odwołaj udział: <?= h($a['client_name']) ?>">
            <i class="bi bi-x-circle"></i>
          </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if ($pending): ?>
      <div class="mt-1 ms-5 small text-warning-emphasis">
        <i class="bi bi-hourglass-split me-1"></i>Prośba o odwołanie udziału — czeka na potwierdzenie.
        <?php if (!empty($a['cancel_reason'])): ?><span class="text-muted">Powód:</span> <?= h($a['cancel_reason']) ?><?php endif; ?>
        <?php if ($role_lbl || !empty($a['cancelled_by'])): ?>
        <span class="text-muted d-block">Zgłosił(a): <?= h(trim(($role_lbl ?: '') . (!empty($a['cancelled_by']) ? ' — '.$a['cancelled_by'] : ''))) ?></span>
        <?php endif; ?>
      </div>
      <?php elseif ($cancelled): ?>
      <div class="mt-1 ms-5 small text-danger">
        <i class="bi bi-x-octagon me-1"></i>Udział odwołany — nie liczony do ceny.
        <?php if (!empty($a['cancel_reason'])): ?><span class="text-muted">Powód:</span> <?= h($a['cancel_reason']) ?><?php endif; ?>
        <?php if ($role_lbl || !empty($a['cancelled_by'])): ?>
        <span class="text-muted d-block">Odwołał(a): <?= h(trim(($role_lbl ?: '') . (!empty($a['cancelled_by']) ? ' — '.$a['cancelled_by'] : ''))) ?></span>
        <?php endif; ?>
      </div>
      <?php elseif ($no_show): ?>
      <div class="mt-1 ms-5 small text-warning-emphasis">
        <i class="bi bi-dash-circle me-1"></i>Nie pojawił się — rozliczono: <strong><?= $ns_bill ?></strong>.
        <?php if (!empty($a['no_show_reason'])): ?>
        <span class="text-muted d-block"><?= h($a['no_show_reason']) ?></span>
        <?php endif; ?>
        <?php if ($role_lbl || !empty($a['cancelled_by'])): ?>
        <span class="text-muted d-block">Oznaczył(a): <?= h(trim(($role_lbl ?: '') . (!empty($a['cancelled_by']) ? ' — '.$a['cancelled_by'] : ''))) ?></span>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <!-- Uwagi indywidualne -->
      <div class="mt-1 ms-5">
        <input type="text"
               class="form-control form-control-sm border-0 bg-transparent px-0"
               name="ind_notes[<?= $cid ?>]"
               value="<?= h($note) ?>"
               placeholder="Uwaga do uczestnika…"
               aria-label="Uwaga indywidualna: <?= h($a['client_name']) ?>"
               <?= !$can_write ? 'readonly' : '' ?>
               style="font-size:.78rem;color:#64748b">
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($can_write): ?>
  <div class="card-footer d-flex align-items-center justify-content-between gap-2">
    <span class="text-muted small" id="att_count">
      Zaznaczono: <strong id="att_num"><?= count(array_filter($attendance,fn($a)=>$a['attended'])) ?></strong>/<?= count($attendance) ?>
    </span>
    <button type="submit" class="btn btn-success btn-lg">
      <i class="bi bi-check2-all me-1"></i>Zapisz lekcję
    </button>
  </div>
  <?php endif; ?>
  <?php endif; // !$attendance ?>
</div>

<!-- ══ Zwijane: informacje o lekcji (temat, godziny, zadanie, uwagi) ══ -->
<details class="card border-0 shadow-sm lesson-card mb-3"<?= (trim((string)($session['topic'] ?? ''))==='' && trim((string)($session['instructor_notes'] ?? ''))==='') ? ' open' : '' ?>>
  <summary class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-journal-text me-2 text-primary"></i>Informacje o lekcji
    <span class="text-muted fw-normal small ms-2">temat · godziny · zadanie · uwagi</span>
    <i class="bi bi-chevron-down chev ms-auto" aria-hidden="true"></i>
  </summary>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-lg-6">
        <label class="form-label fw-semibold" for="topic"><i class="bi bi-journal-text me-1 text-primary"></i>Temat lekcji</label>
        <input type="text" class="form-control" id="topic" name="topic"
               value="<?= h($session['topic'] ?? '') ?>"
               placeholder="np. Obsługa poczty e-mail, Tworzenie dokumentów w Word…"
               <?= !$can_write ? 'readonly' : '' ?>>
      </div>
      <div class="col-lg-6">
        <label class="form-label fw-semibold d-block">Czas zajęć</label>
        <div class="row g-2">
          <div class="col-5">
            <select class="form-select" id="ltime_from" name="time_from" aria-label="Początek" onchange="recalcDur()" <?= (!$can_write || $is_past) ? 'disabled' : '' ?>><?= ti_time_options($session['time_from'] ?? '') ?></select>
          </div>
          <div class="col-2 text-center pt-2 text-muted">–</div>
          <div class="col-5">
            <select class="form-select" id="ltime_to" name="time_to" aria-label="Koniec" onchange="recalcDur()" <?= (!$can_write || $is_past) ? 'disabled' : '' ?>><?= ti_time_options($session['time_to'] ?? '') ?></select>
          </div>
        </div>
        <div class="form-text">Czas trwania: <span class="fw-semibold" id="dur_display"><?php $dm=(int)$session['duration_min']; echo $dm>=60 ? floor($dm/60).'h'.($dm%60?' '.($dm%60).'m':'') : $dm.'m'; ?></span></div>
        <input type="hidden" id="ldur" name="duration_min" value="<?= (int)$session['duration_min'] ?>">
      </div>
    </div>

    <div class="row g-2 mt-1">
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="has_homework" name="has_homework" value="1"
                 <?= ($session['has_homework'] ?? 0) ? 'checked' : '' ?> <?= !$can_write ? 'disabled' : '' ?>>
          <label class="form-check-label fw-semibold" for="has_homework"><i class="bi bi-pencil-square me-1 text-warning"></i>Zadano zadanie domowe</label>
        </div>
      </div>
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="self_prep_remote" name="self_prep_remote" value="1"
                 <?= ($session['self_prep_remote'] ?? 0) ? 'checked' : '' ?> <?= !$can_write ? 'disabled' : '' ?>>
          <label class="form-check-label fw-semibold" for="self_prep_remote"><i class="bi bi-laptop me-1 text-info"></i>Praca własna prowadzącego (materiał zdalny)</label>
        </div>
      </div>
    </div>

    <div class="mt-3">
      <label class="form-label fw-semibold" for="inst_notes"><i class="bi bi-chat-square-text me-1 text-secondary"></i>Uwagi prowadzącego</label>
      <textarea class="form-control" id="inst_notes" name="instructor_notes" rows="3"
                placeholder="Postępy grupy, trudności, tematy do powtórzenia…"
                <?= !$can_write ? 'readonly' : '' ?>><?= h($session['instructor_notes'] ?? '') ?></textarea>
    </div>
  </div>
</details>
</form>

<!-- ══ Dół: link do lekcji online + oceny kursantów ══ -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="section-head"><i class="bi bi-camera-video me-1 text-primary"></i>Link do lekcji online</div>
        <?php if ($can_write): ?>
        <form method="post" class="input-group input-group-sm">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="save_link">
          <input type="url" class="form-control font-monospace" name="meeting_url"
                 value="<?= h($session['meeting_url'] ?? '') ?>" aria-label="Link do lekcji online"
                 placeholder="<?= !empty($session['course_meeting_url']) ? 'puste = stały link grupy' : 'https://… (Teams/Zoom/Meet)' ?>">
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
        </form>
        <?php endif; ?>
        <div class="small text-muted mt-2">
          <?php if (!empty($session['meeting_url'])): ?>
            Link tej lekcji: <a href="<?= h($session['meeting_url']) ?>" target="_blank" rel="noopener"><?= h($session['meeting_url']) ?></a>
          <?php elseif (!empty($session['course_meeting_url'])): ?>
            <i class="bi bi-link-45deg me-1"></i>Używany jest stały link grupy:
            <a href="<?= h($session['course_meeting_url']) ?>" target="_blank" rel="noopener"><?= h($session['course_meeting_url']) ?></a>
          <?php else: ?>
            Brak linku — ustaw powyżej lub stały link w ustawieniach kursu.
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="section-head"><i class="bi bi-star me-1 text-warning"></i>Oceny kursantów</div>
        <?php if ($ratings): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="fs-4 fw-bold text-warning"><?= number_format($rating_avg, 2, ',', '') ?></span>
          <span class="text-warning">
            <?php for ($i=1;$i<=5;$i++): ?><i class="bi bi-star<?= $i <= round($rating_avg) ? '-fill' : '' ?>"></i><?php endfor; ?>
          </span>
          <span class="text-muted small">(<?= count($ratings) ?>)</span>
        </div>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($ratings as $rr): ?>
          <li class="border-top pt-1 mt-1">
            <span class="text-warning"><?php for ($i=1;$i<=5;$i++): ?><i class="bi bi-star<?= $i <= (int)$rr['rating'] ? '-fill' : '' ?>"></i><?php endfor; ?></span>
            <span class="text-muted ms-1"><?= h($rr['client_name']) ?></span>
            <?php if (!empty($rr['comment'])): ?><div class="text-body-secondary fst-italic">„<?= h($rr['comment']) ?>"</div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="text-muted small">Brak ocen. Kursanci mogą ocenić odbytą lekcję w swoim panelu.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; // cancelled ?>

<?php if ($can_write): ?>
<!-- Modal: odwołanie całej lekcji (Doradca / admin) -->
<div class="modal fade" id="cancelLessonModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"    value="set_status">
      <input type="hidden" name="status" value="cancelled">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Odwołanie lekcji</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Odwołana lekcja nie zostanie policzona do ceny. Podaj powód odwołania.</p>
        <label class="form-label fw-semibold" for="cl_reason">Powód odwołania</label>
        <textarea class="form-control" id="cl_reason" name="cancel_reason" rows="3" required
                  placeholder="np. choroba prowadzącego, brak frekwencji, awaria sprzętu…"></textarea>
        <div class="form-text">Odwołujący: <?= h(K30_TI_CANCEL_ROLES[$cancel_role]) ?><?= $cancel_label ? ' — '.h($cancel_label) : '' ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Odwołaj lekcję</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: odwołanie udziału uczestnika -->
<div class="modal fade" id="cancelAttModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"       value="cancel_attendee">
      <input type="hidden" name="client_id" id="ca_cid" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Odwołanie udziału</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Uczestnik: <strong id="ca_name"></strong></p>
        <p class="text-muted small mb-2">Odwołany udział nie zostanie policzony do ceny. Podaj powód.</p>
        <label class="form-label fw-semibold" for="ca_reason">Powód odwołania</label>
        <textarea class="form-control" id="ca_reason" name="cancel_reason" rows="3" required
                  placeholder="np. nieobecność zgłoszona przez beneficjenta, choroba…"></textarea>
        <div class="form-text">Odwołujący: <?= h(K30_TI_CANCEL_ROLES[$cancel_role]) ?><?= $cancel_label ? ' — '.h($cancel_label) : '' ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Odwołaj udział</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: nie pojawił się na zajęciach -->
<div class="modal fade" id="noShowModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"       value="mark_no_show">
      <input type="hidden" name="client_id" id="ns_cid" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-dash-circle text-warning me-2"></i>Nie pojawił się</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">Uczestnik: <strong id="ns_name"></strong></p>
        <p class="text-muted small mb-3">Lekcja się odbyła, ale beneficjent nie stawił się. Wybierz sposób rozliczenia:</p>
        <div class="d-grid gap-2">
          <div class="form-check border rounded p-3">
            <input class="form-check-input" type="radio" name="no_show_billing" id="ns_full" value="full" checked>
            <label class="form-check-label w-100" for="ns_full">
              <div class="fw-semibold">Cała lekcja</div>
              <div class="text-muted small">Policz pełny czas trwania zajęć (<?= h(number_format((float)$session['duration_min']/60, 2, ',', '')) ?>&nbsp;h).</div>
            </label>
          </div>
          <div class="form-check border rounded p-3">
            <input class="form-check-input" type="radio" name="no_show_billing" id="ns_1h" value="1h">
            <label class="form-check-label w-100" for="ns_1h">
              <div class="fw-semibold">Tylko 1 godzina (rozpoczęta)</div>
              <div class="text-muted small">Policz 1 godzinę — minimalną jednostkę za stawienie się prowadzącego.</div>
            </label>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label fw-semibold" for="ns_reason">Opis sytuacji <span class="text-body-secondary fw-normal small">(opcjonalnie)</span></label>
          <textarea class="form-control" id="ns_reason" name="no_show_reason" rows="2"
                    placeholder="np. brak kontaktu, hospitalizacja, awaria dojazdu…"></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label fw-semibold" for="ns_screenshot">Screenshot / dokumentacja <span class="text-body-secondary fw-normal small">(opcjonalnie, PNG/JPG/PDF, max 15 MB)</span></label>
          <input class="form-control" type="file" id="ns_screenshot" name="no_show_screenshot" accept=".png,.jpg,.jpeg,.pdf">
          <div class="form-text">Plik zostanie dołączony do maila wysyłanego do rodzica/kursanta.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-warning"><i class="bi bi-dash-circle me-1"></i>Oznacz: nie pojawił się</button>
      </div>
    </form>
  </div>
</div>

<!-- Ukryty formularz akcji uczestnika (przywracanie) -->
<form method="post" id="attActionForm" class="d-none">
  <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_op"       id="aa_op"  value="">
  <input type="hidden" name="client_id" id="aa_cid" value="">
</form>
<?php endif; ?>

<script>
function openNoShow(cid, name) {
  document.getElementById('ns_cid').value = cid;
  document.getElementById('ns_name').textContent = name;
  document.getElementById('ns_full').checked = true;
  var r = document.getElementById('ns_reason'); if (r) r.value = '';
  var f = document.getElementById('ns_screenshot'); if (f) f.value = '';
  new bootstrap.Modal(document.getElementById('noShowModal')).show();
}
function openCancelAtt(cid, name) {
  document.getElementById('ca_cid').value = cid;
  document.getElementById('ca_name').textContent = name;
  var t = document.getElementById('ca_reason'); if (t) t.value = '';
  new bootstrap.Modal(document.getElementById('cancelAttModal')).show();
}
function restoreAtt(cid) {
  if (!confirm('Przywrócić udział uczestnika?')) return;
  document.getElementById('aa_op').value  = 'restore_attendee';
  document.getElementById('aa_cid').value = cid;
  document.getElementById('attActionForm').submit();
}
function confirmCancelReq(cid) {
  if (!confirm('Potwierdzić odwołanie udziału? Kursant zostanie powiadomiony.')) return;
  document.getElementById('aa_op').value  = 'confirm_cancel_req';
  document.getElementById('aa_cid').value = cid;
  document.getElementById('attActionForm').submit();
}
function rejectCancelReq(cid) {
  if (!confirm('Odrzucić prośbę i przywrócić udział? Kursant zostanie powiadomiony.')) return;
  document.getElementById('aa_op').value  = 'reject_cancel_req';
  document.getElementById('aa_cid').value = cid;
  document.getElementById('attActionForm').submit();
}

function recalcDur() {
  var tf = document.getElementById('ltime_from').value;
  var tt = document.getElementById('ltime_to').value;
  var disp = document.getElementById('dur_display');
  if (!tf || !tt) return;
  var m = Math.round((new Date('1970-01-01T'+tt) - new Date('1970-01-01T'+tf)) / 60000);
  if (m <= 0) { if (disp) disp.textContent = '?'; return; }
  document.getElementById('ldur').value = m;
  if (disp) {
    var h = Math.floor(m/60), min = m%60;
    disp.textContent = h > 0 ? h+'h'+(min?' '+min+'m':'') : min+'m';
  }
}

function rowToggle(cb) {
  var row = document.getElementById('row_' + cb.value);
  if (row) row.className = row.className.replace(/\b(present|absent)\b/, cb.checked ? 'present' : 'absent');
  updateCount();
}

function toggleAll(val) {
  document.querySelectorAll('.att-cb').forEach(function(cb) {
    cb.checked = val;
    rowToggle(cb);
  });
}

function updateCount() {
  var total   = document.querySelectorAll('.att-cb').length;
  var checked = document.querySelectorAll('.att-cb:checked').length;
  var el = document.getElementById('att_num');
  if (el) el.textContent = checked;
}

updateCount();
</script>

<?php if ($can_write && ($solo_enrolled === 1 || $is_subgroup_view) && !$is_held && $session['status'] !== 'cancelled'): ?>
<!-- Modal: kurs jednosobowy — nieobecność jedynego kursanta -->
<div class="modal fade" id="soloAbsentModal" tabindex="-1" aria-labelledby="soloAbsentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-warning" style="background:#fffbeb">
        <h5 class="modal-title" id="soloAbsentModalLabel">
          <i class="bi bi-question-circle text-warning me-2"></i>Jedyny kursant nieobecny — jak liczyć?
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-body-secondary small mb-3">
          Kurs jest jednosobowy, a kursant nie był obecny na zajęciach.
          Wybierz, jak potraktować tę lekcję w rozliczeniu.
        </p>
        <div class="d-grid gap-2">
          <label class="border rounded p-3 d-flex gap-3 align-items-start" style="cursor:pointer">
            <input type="radio" name="_solo_choice" value="no_show_full" class="form-check-input mt-1 flex-shrink-0" checked>
            <div>
              <div class="fw-semibold">Licz — cała lekcja</div>
              <div class="text-muted small">Prowadzący stawił się, kursant nie — nalicz pełny czas (<?= h(number_format((float)$session['duration_min']/60, 2, ',', '')) ?>&nbsp;h).</div>
            </div>
          </label>
          <label class="border rounded p-3 d-flex gap-3 align-items-start" style="cursor:pointer">
            <input type="radio" name="_solo_choice" value="no_show_1h" class="form-check-input mt-1 flex-shrink-0">
            <div>
              <div class="fw-semibold">Licz — tylko 1 godzina</div>
              <div class="text-muted small">Nalicz 1 godzinę za stawiennictwo prowadzącego.</div>
            </div>
          </label>
          <label class="border rounded p-3 d-flex gap-3 align-items-start" style="cursor:pointer">
            <input type="radio" name="_solo_choice" value="cancel" class="form-check-input mt-1 flex-shrink-0">
            <div>
              <div class="fw-semibold">Nie licz — odwołaj lekcję</div>
              <div class="text-muted small">Lekcja nie wejdzie do rozliczenia (status: <em>Odwołana</em>).</div>
            </div>
          </label>
        </div>
        <div class="mt-3">
          <label class="form-label fw-semibold small" for="solo_reason_inp">Opis / powód <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
          <input type="text" class="form-control form-control-sm" id="solo_reason_inp"
                 placeholder="np. hospitalizacja, brak kontaktu…">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-warning" id="soloAbsentConfirm">
          <i class="bi bi-check-lg me-1"></i>Zapisz z wybraną opcją
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var form     = document.getElementById('lesson_form');
  var actionIn = document.getElementById('solo_absent_action');
  var reasonIn = document.getElementById('solo_absent_reason');
  if (!form || !actionIn) return;

  form.addEventListener('submit', function(e){
    // Sprawdź czy jedyna osoba jest nieobecna (żaden checkbox nie zaznaczony)
    var cbs = form.querySelectorAll('.att-cb');
    if (cbs.length === 0) return; // brak kursantów — normalny submit
    var anyChecked = Array.from(cbs).some(function(cb){ return cb.checked; });
    if (!anyChecked && actionIn.value === '') {
      // Jedyny kursant nieobecny i jeszcze nie wybrano opcji → pokaż modal
      e.preventDefault();
      var modal = new bootstrap.Modal(document.getElementById('soloAbsentModal'));
      modal.show();
    }
  });

  document.getElementById('soloAbsentConfirm').addEventListener('click', function(){
    var choice = document.querySelector('input[name="_solo_choice"]:checked');
    if (!choice) return;
    actionIn.value = choice.value;
    reasonIn.value = (document.getElementById('solo_reason_inp').value || '').trim();
    bootstrap.Modal.getInstance(document.getElementById('soloAbsentModal')).hide();
    form.submit();
  });
})();
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
