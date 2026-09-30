<?php
/**
 * karty30/ti/course.php — Szczegóły kursu TI: uczestnicy, lekcje, rozliczenia.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_price_changes.php';
require_once dirname(dirname(__DIR__)) . '/includes/zoom.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms_templates.php';

k30_require_access();
karty30_migrate();

/**
 * Synchronizuje alternative_hosts Zoom dla kursu i wszystkich zapisów.
 * Zwraca listę ID spotkań, dla których Zoom zgłosił kod 3001 (usunięte po stronie Zoom).
 */
function _ti_zoom_sync_alt_hosts(int $course_id, array $course): array {
    if (!zoom_enabled()) return [];
    $emails  = k30_ti_course_zoom_alt_hosts($course_id);
    $missing = [];
    try {
        $api = new ZoomAPI();
        if (!empty($course['zoom_meeting_id'])) {
            $ok = $api->update_alternative_hosts((string)$course['zoom_meeting_id'], $emails);
            if (!$ok) $missing[] = 'course';
        }
        $enr = db_all("SELECT zoom_meeting_id FROM k30_ti_enrollments WHERE course_id=? AND zoom_meeting_id!=''", [$course_id]);
        foreach ($enr as $e) {
            $ok = $api->update_alternative_hosts((string)$e['zoom_meeting_id'], $emails);
            if (!$ok) $missing[] = $e['zoom_meeting_id'];
        }
    } catch (\Throwable $e) {}
    return $missing;
}

$id        = (int)($_GET['id'] ?? 0);
$course    = $id ? k30_ti_course_get($id) : null;
if (!$course) { flash_set('danger','Kurs nie istnieje.'); header('Location: index.php'); exit; }

// Feed JSON dla kalendarza lekcji (FullCalendar) — GET, tylko odczyt, bez CSRF.
if (($_GET['_json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $start = trim($_GET['start'] ?? '');
    $end   = trim($_GET['end']   ?? '');
    $rows  = k30_ti_sessions($id, $start, $end);
    $out = [];
    foreach ($rows as $s) {
        $st = K30_TI_SESSION_STATUSES[$s['status']] ?? ['label'=>$s['status'],'color'=>'#666','bg'=>'#eee'];
        $title = ($s['time_from'] ? substr((string)$s['time_from'],0,5).'–'.substr((string)$s['time_to'],0,5).' · ' : '') . $st['label'];
        $out[] = [
            'id'              => (int)$s['id'],
            'title'           => $title,
            'start'           => $s['lesson_date'] . ($s['time_from'] ? 'T'.$s['time_from'] : ''),
            'end'             => $s['time_to'] ? $s['lesson_date'].'T'.$s['time_to'] : null,
            'allDay'          => $s['time_from'] === '' || $s['time_from'] === null,
            'backgroundColor' => $st['bg'],
            'borderColor'     => $st['color'],
            'textColor'       => $st['color'],
        ];
    }
    echo json_encode($out);
    exit;
}

$can_write = can_write('karty30') || is_admin();
$PAGE_TITLE= 'TI: ' . $course['name'];

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    if ($_cc_msg = ti_course_closed_guard($_POST, [(int)$course['id']])) {
        flash_set('danger', $_cc_msg);
        header('Location: course.php?id=' . (int)$course['id']); exit;
    }

    if ($op === 'enroll') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $rate = max(0, (float)str_replace(',','.', $_POST['hourly_rate'] ?? '0'));
        $rate_on = max(0, (float)str_replace(',','.', $_POST['hourly_rate_online'] ?? '0'));   // 0 = jak stacjonarna
        if ($cid) {
            try {
                db()->prepare(
                    "INSERT INTO k30_ti_enrollments (course_id,client_id,hourly_rate,hourly_rate_online,start_date,status)
                     VALUES (?,?,?,?,?,?)
                     ON CONFLICT(course_id,client_id) DO UPDATE SET hourly_rate=excluded.hourly_rate, hourly_rate_online=excluded.hourly_rate_online,
                         status='active', start_date=excluded.start_date"
                )->execute([$id, $cid, $rate, $rate_on, date('Y-m-d'), 'active']);
            } catch (\Throwable $e) { /* fallback */ }
            flash_set('success','Uczestnik zapisany.');
        }
        header('Location: course.php?id='.$id.'#uczestnicy'); exit;
    }

    if ($op === 'unenroll') {
        $cid = (int)($_POST['client_id'] ?? 0);
        db()->prepare("UPDATE k30_ti_enrollments SET status='inactive' WHERE course_id=? AND client_id=?")->execute([$id,$cid]);
        flash_set('success','Uczestnik wypisany.');
        header('Location: course.php?id='.$id.'#uczestnicy'); exit;
    }

    // ── Zoom: link na poziomie kursu (wspólny dla całej grupy) ─────────────────
    if ($op === 'gen_course_zoom' || $op === 'clear_course_zoom') {
        if (!zoom_enabled()) { flash_set('warning','Integracja Zoom nie jest skonfigurowana — przejdź do Ustawień TI.'); header('Location: course.php?id='.$id.'#zoom'); exit; }
        $api    = new ZoomAPI();
        $uid    = (int)(current_user()['id'] ?? 0);
        if (!empty($course['zoom_meeting_id'])) {
            $api->delete_meeting($course['zoom_meeting_id']);
            $api->log('delete', $course['zoom_meeting_id'], 'ok', 'clear before regenerate', $id, $uid);
        }
        if ($op === 'clear_course_zoom') {
            db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id='', default_meeting_url='', zoom_host_email='' WHERE id=?")->execute([$id]);
            $api->log('delete', $course['zoom_meeting_id'] ?? '', 'ok', 'cleared by admin', $id, $uid);
            flash_set('success','Link Zoom kursu usunięty.');
        } else {
            // Walidacja e-maila prowadzącego przed przypisaniem jako alt_host
            $altEmails = k30_ti_course_zoom_alt_hosts($id);
            $hostEmail = db_one(
                "SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id]
            )['email'] ?? '';
            if ($hostEmail !== '') {
                try {
                    $api->validate_user_email($hostEmail);
                } catch (\RuntimeException $ex) {
                    flash_set('warning', $ex->getMessage() . ' Link Zoom zostanie utworzony bez alternative_hosts.');
                    $altEmails = '';
                    $hostEmail = '';
                }
            }
            try {
                $m = $api->create_meeting($course['name'], 'Kurs TI — stały link grupy', $altEmails);
                db()->prepare(
                    "UPDATE k30_ti_courses SET zoom_meeting_id=?, default_meeting_url=?, zoom_host_email=? WHERE id=?"
                )->execute([$m['meeting_id'], $m['join_url'], $hostEmail, $id]);
                $api->log('create', $m['meeting_id'], 'ok', 'alt_hosts=' . $altEmails, $id, $uid);
                flash_set('success', 'Stały link Zoom kursu wygenerowany.');
            } catch (\RuntimeException $ex) {
                $api->log('create', '', 'error', $ex->getMessage(), $id, $uid);
                flash_set('danger', 'Błąd Zoom: ' . $ex->getMessage());
            }
        }
        header('Location: course.php?id='.$id.'#zoom'); exit;
    }

    // ── Zoom: link per kursant (zajęcia indywidualne) ──────────────────────────
    if ($op === 'gen_student_zoom' || $op === 'clear_student_zoom') {
        $cid = (int)($_POST['client_id'] ?? 0);
        $en  = $cid ? db_one("SELECT * FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$id,$cid]) : null;
        if (!$en) { flash_set('danger','Uczestnik nie znaleziony.'); header('Location: course.php?id='.$id.'#uczestnicy'); exit; }
        if (!zoom_enabled()) { flash_set('warning','Integracja Zoom nie jest skonfigurowana — przejdź do Ustawień TI.'); header('Location: course.php?id='.$id.'#uczestnicy'); exit; }
        $api = new ZoomAPI();
        $uid = (int)(current_user()['id'] ?? 0);
        if (!empty($en['zoom_meeting_id'])) {
            $api->delete_meeting($en['zoom_meeting_id']);
        }
        if ($op === 'clear_student_zoom') {
            db()->prepare("UPDATE k30_ti_enrollments SET zoom_meeting_id='', zoom_meeting_url='' WHERE course_id=? AND client_id=?")->execute([$id,$cid]);
            $api->log('delete', $en['zoom_meeting_id'] ?? '', 'ok', 'student clear client_id=' . $cid, $id, $uid);
            flash_set('success','Stały link Zoom uczestnika usunięty.');
        } else {
            $cname   = db_one("SELECT name FROM k30_clients WHERE id=?", [$cid])['name'] ?? (string)$cid;
            $altEmails = k30_ti_course_zoom_alt_hosts($id);
            $hostEmail = db_one(
                "SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id]
            )['email'] ?? '';
            if ($hostEmail !== '') {
                try { $api->validate_user_email($hostEmail); }
                catch (\RuntimeException $ex) {
                    flash_set('warning', $ex->getMessage() . ' Link bez alternative_hosts.');
                    $altEmails = '';
                }
            }
            try {
                $m = $api->create_meeting($course['name'] . ' — ' . $cname, 'Zajęcia TI', $altEmails);
                db()->prepare("UPDATE k30_ti_enrollments SET zoom_meeting_id=?, zoom_meeting_url=? WHERE course_id=? AND client_id=?")
                     ->execute([$m['meeting_id'], $m['join_url'], $id, $cid]);
                $api->log('create', $m['meeting_id'], 'ok', 'student client_id=' . $cid, $id, $uid);
                flash_set('success', 'Stały link Zoom wygenerowany dla uczestnika ' . $cname . '.');
            } catch (\RuntimeException $ex) {
                $api->log('create', '', 'error', $ex->getMessage(), $id, $uid);
                flash_set('danger', 'Błąd Zoom: ' . $ex->getMessage());
            }
        }
        header('Location: course.php?id='.$id.'#uczestnicy'); exit;
    }

    if ($op === 'update_rate') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $rate = max(0, (float)str_replace(',','.', $_POST['hourly_rate'] ?? '0'));
        db()->prepare("UPDATE k30_ti_enrollments SET hourly_rate=? WHERE course_id=? AND client_id=?")->execute([$rate,$id,$cid]);
        flash_set('success','Stawka zaktualizowana.');
        header('Location: course.php?id='.$id.'#uczestnicy'); exit;
    }

    // Indywidualny model rozliczania kursanta (override). model=0 → dziedziczy z kursu.
    if ($op === 'set_billing') {
        $cid   = (int)($_POST['client_id'] ?? 0);
        $model = (int)($_POST['billing_model'] ?? 0);
        if (!in_array($model, [0,1,2,3], true)) $model = 0;
        $amount = max(0, (float)str_replace(',','.', (string)($_POST['billing_amount'] ?? '0')));
        $rate   = max(0, (float)str_replace(',','.', (string)($_POST['hourly_rate'] ?? '0')));
        $rate_on = max(0, (float)str_replace(',','.', (string)($_POST['hourly_rate_online'] ?? '0')));
        $pay_account = trim($_POST['pay_account'] ?? '');
        $pay_title   = trim($_POST['pay_title'] ?? '');
        $due_days    = ((int)($_POST['pay_due_days'] ?? 0)) ?: null;
        if ($cid) {
            db()->prepare("UPDATE k30_ti_enrollments SET billing_model=?, billing_amount=?, hourly_rate=?, hourly_rate_online=?, pay_account=?, pay_title=?, pay_due_days=? WHERE course_id=? AND client_id=?")
               ->execute([$model, $amount, $rate, $rate_on, $pay_account, $pay_title, $due_days, $id, $cid]);
            flash_set('success', $model > 0 ? 'Ustawiono indywidualny model rozliczania (kod 9999).' : 'Przywrócono model rozliczania kursu.');
        }
        header('Location: course.php?id='.$id.'#uczestnicy'); exit;
    }

    if ($op === 'add_lesson') {
        $tf   = trim($_POST['time_from'] ?? '');
        $tt   = trim($_POST['time_to']   ?? '');
        $dur  = (int)($_POST['duration_min'] ?? 60);
        // Wylicz czas trwania z od-do jeśli podano obie godziny
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 '.$tt) - strtotime('1970-01-01 '.$tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }
        $is_reservation = !empty($_POST['is_reservation']);
        $sess_data = [
            'course_id'    => $id,
            'lesson_date' => trim($_POST['lesson_date'] ?? ''),
            'time_from'    => $tf,
            'time_to'      => $tt,
            'duration_min' => $dur,
            'status'       => $is_reservation ? 'reserved' : 'planned',
            'notes'        => trim($_POST['notes'] ?? ''),
            'meeting_url'  => trim($_POST['meeting_url'] ?? ''),
            'created_by'   => current_user()['id'] ?? null,
            'created_at'   => date('Y-m-d H:i:s'),
        ];
        if (!$sess_data['lesson_date']) { flash_set('danger','Data lekcji jest wymagana.'); header('Location: course.php?id='.$id.'#lekcje'); exit; }
        // Zajęcia tylko w dostępności prowadzącego — admin może nadpisać
        $av = ti_instructor_available_at(ti_course_instructor_id((int)$id), $sess_data['lesson_date'], $tf, $tt);
        if (!$av['ok'] && empty($_POST['ignore_availability'])) {
            flash_set('warning', $av['reason'] . ' Aby dodać mimo to, zaznacz „Dodaj poza dostępnością".');
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }
        // Zamknięty okres nauczania — rozliczony protokołami ocen
        require_once dirname(dirname(__DIR__)) . '/includes/ti_periods.php';
        if ($_pc = ti_period_closed_for_date($sess_data['lesson_date'])) {
            flash_set('danger', ti_period_closed_msg($_pc));
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }

        // Zajętość konta Zoom — twarda blokada, bez nadpisania: jeden host Zoom
        // nie prowadzi dwóch spotkań jednocześnie (ograniczenie techniczne, nie organizacyjne).
        $zc = ti_zoom_slot_check((int)$id, '', $sess_data['lesson_date'], $tf, $tt);
        if (!$zc['ok']) {
            flash_set('danger', $zc['reason']);
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }
        $zw = $zc['warning'] !== '' ? ' ' . $zc['warning'] : '';
        $sid = db_insert('k30_ti_sessions', $sess_data);
        // Wstępnie utwórz obecność dla wszystkich aktywnych uczestników
        $enrolled = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$id]);
        foreach ($enrolled as $e) {
            try { db_insert('k30_ti_attendance', ['session_id'=>$sid,'client_id'=>(int)$e['client_id'],'attended'=>0]); }
            catch(\Throwable $ex) {}
        }
        if ($is_reservation) {
            flash_set('success', 'Rezerwacja terminu utworzona — termin jest zablokowany, ale kursanci jej nie widzą, dopóki nie zostanie potwierdzona.' . $zw);
        } else {
            // Powiadomienia SMS o nowych zajęciach — tylko do kursantów, którzy je włączyli
            $course_row = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$id]);
            $when = $sess_data['lesson_date'] . ($tf !== '' ? ' o ' . $tf : '');
            $sms_sent = 0;
            $stpl = sms_tpl_render('ti_lesson_added', ['course' => (string)($course_row['name'] ?? ''), 'when' => $when]);
            if ($stpl['enabled']) {
                $sms_sent = ti_lesson_sms_notify((int)$id, $stpl['message']);
            }
            flash_set('success', 'Lekcja dodana.' . ($sms_sent ? " Wysłano SMS: {$sms_sent}." : '') . $zw);
        }
        header('Location: lesson.php?id='.$sid); exit;
    }

    // Klonowanie lekcji — kopiuje godziny, pyta o nową datę
    if ($op === 'clone_lesson') {
        $src_id   = (int)($_POST['src_lesson_id'] ?? 0);
        $new_date = trim($_POST['clone_date'] ?? '');
        $src      = $src_id ? db_one("SELECT * FROM k30_ti_sessions WHERE id=?", [$src_id]) : null;
        if (!$src || !$new_date) {
            flash_set('danger','Podaj datę dla sklonowanej lekcji.');
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }
        // Dostępność prowadzącego dla nowego terminu — admin może nadpisać
        $av = ti_instructor_available_at(ti_course_instructor_id((int)$src['course_id']), $new_date, (string)$src['time_from'], (string)$src['time_to']);
        if (!$av['ok'] && empty($_POST['ignore_availability'])) {
            flash_set('warning', $av['reason'] . ' Aby sklonować mimo to, zaznacz „Klonuj poza dostępnością".');
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }
        require_once dirname(dirname(__DIR__)) . '/includes/ti_periods.php';
        if ($_pc = ti_period_closed_for_date($new_date)) {
            flash_set('danger', ti_period_closed_msg($_pc));
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }
        // Zajętość konta Zoom w nowym terminie — twarda blokada
        $zc = ti_zoom_slot_check((int)$src['course_id'], (string)($src['lesson_method'] ?? ''), $new_date, (string)$src['time_from'], (string)$src['time_to']);
        if (!$zc['ok']) {
            flash_set('danger', $zc['reason']);
            header('Location: course.php?id='.$id.'#lekcje'); exit;
        }
        $new_id = db_insert('k30_ti_sessions', [
            'course_id'    => $src['course_id'],
            'lesson_date'  => $new_date,
            'time_from'    => $src['time_from'],
            'time_to'      => $src['time_to'],
            'duration_min' => $src['duration_min'],
            'status'       => 'planned',
            'notes'        => $src['notes'],
            'meeting_url'  => $src['meeting_url'] ?? '',
            'created_by'   => current_user()['id'] ?? null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        // Skopiuj listę uczestników (bez statusu obecności — nowa lekcja)
        $enrolled = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$src['course_id']]);
        foreach ($enrolled as $e) {
            try { db_insert('k30_ti_attendance', ['session_id'=>$new_id,'client_id'=>(int)$e['client_id'],'attended'=>0]); }
            catch(\Throwable $ex) {}
        }
        flash_set('success','Lekcja sklonowana na '.date('d.m.Y', strtotime($new_date)).'.');
        header('Location: lesson.php?id='.$new_id); exit;
    }

    // Potwierdzenie rezerwacji — zamienia ją w zwykłą, zaplanowaną lekcję (widoczną
    // dla kursanta). Termin był zablokowany od chwili rezerwacji, więc tu już nie
    // trzeba ponownie sprawdzać konfliktów.
    if ($op === 'confirm_reservation') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $r = db()->prepare("UPDATE k30_ti_sessions SET status='planned', updated_at=datetime('now') WHERE id=? AND course_id=? AND status='reserved'");
        $r->execute([$sid, $id]);
        flash_set($r->rowCount() ? 'success' : 'danger', $r->rowCount() ? 'Rezerwacja potwierdzona — lekcja jest teraz widoczna dla kursantów.' : 'Ta rezerwacja już nie istnieje albo została wcześniej potwierdzona.');
        header('Location: course.php?id='.$id.'#lekcje'); exit;
    }

    // ── CoProwadzący ───────────────────────────────────────────────────────────
    if ($op === 'coinstr_add') {
        $uid = (int)($_POST['coinstr_user_id'] ?? 0);
        if ($uid && $uid !== (int)$course['instructor_id']) {
            k30_ti_coinstruct_add($id, $uid, (int)(current_user()['id'] ?? 0));
            _ti_zoom_sync_alt_hosts($id, $course);
            flash_set('success', 'CoProwadzący dodany.');
        }
        header('Location: course.php?id='.$id.'#coinstructors'); exit;
    }
    if ($op === 'coinstr_remove') {
        $uid = (int)($_POST['coinstr_user_id'] ?? 0);
        if ($uid) {
            k30_ti_coinstruct_remove($id, $uid);
            _ti_zoom_sync_alt_hosts($id, $course);
            flash_set('success', 'CoProwadzący usunięty.');
        }
        header('Location: course.php?id='.$id.'#coinstructors'); exit;
    }
}

$enrollments   = k30_ti_enrollments($id);
$active_ids    = array_column(array_filter($enrollments, fn($e)=>$e['status']==='active'), 'client_id');
$sessions      = k30_ti_sessions($id, date('Y-m-01', strtotime('-30 days')));
$all_clients   = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$not_enrolled  = array_filter($all_clients, fn($c)=>!in_array((int)$c['id'], array_column($enrollments,'client_id')));
$coinstructors = k30_ti_course_coinstructors($id);
$coinstr_ids   = array_column($coinstructors, 'user_id');
$consultants   = k30_get_consultants();
$coinstr_available = array_filter($consultants, fn($u)=>
    (int)$u['id'] !== (int)$course['instructor_id'] && !in_array((int)$u['id'], $coinstr_ids));

// Billing - ostatnie 3 miesiące
$billing_months = [];
for ($i=0; $i<3; $i++) {
    $billing_months[] = [
        'month' => (int)date('m', strtotime("-$i months")),
        'year'  => (int)date('Y', strtotime("-$i months")),
        'label' => (function($dt){ $m=[1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień']; return $m[(int)$dt->format('n')].' '.$dt->format('Y'); })(new DateTime("-$i months")),
    ];
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<style>
.rate-display { cursor:pointer; border-bottom:1px dashed #94a3b8; }
.rate-edit { display:none }
.ti-term-calendar { padding:.5rem; }
.ti-term-calendar .fc-toolbar-title { font-size:1rem; }
.ti-term-calendar .fc-daygrid-day.ti-selected-day { background:rgba(37,99,235,.18); }
.ti-term-calendar .fc-daygrid-day.ti-selected-day .fc-daygrid-day-number { font-weight:700; color:#2563eb; }
.ti-term-calendar .fc-daygrid-day-frame { cursor:pointer; }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active"><?= h($course['name']) ?></li>
</ol></nav>

<div class="d-flex align-items-center mb-4 gap-2 flex-wrap">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-pc-display text-primary me-2"></i><?= h($course['name']) ?>
      <?php if (!empty($course['subject_abbr'])): ?>
      <span class="badge text-bg-primary font-monospace ms-1 align-middle" style="font-size:.7rem"><?= h($course['subject_abbr']) ?></span>
      <?php endif; ?>
      <?php if (!empty($course['group_code'])): ?>
      <span class="badge bg-light text-secondary border font-monospace ms-1 align-middle" style="font-size:.7rem"><?= h($course['group_code']) ?></span>
      <?php endif; ?>
    </h4>
    <div class="text-muted small mt-1">
      <?php if (!empty($course['subject_name'])): ?>
      <span class="me-2"><i class="bi bi-tags me-1"></i><?= h($course['subject_name']) ?></span>
      <?php endif; ?>
      <?php if ($course['instructor_name']): ?>
      <i class="bi bi-person me-1"></i><?= h($course['instructor_name']) ?>
      <?php endif; ?>
      <?php if ($course['location']): ?>
       · <i class="bi bi-geo-alt me-1"></i><?= h($course['location']) ?>
      <?php endif; ?>
      <span class="ms-2 text-primary-emphasis">
        <i class="bi bi-arrow-repeat me-1"></i>Lekcje definiują daty i godziny
      </span>
      <?php $cbm = (int)($course['billing_model'] ?? 2) ?: 2; ?>
      <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-2">
        <i class="bi bi-cash-coin me-1"></i>Rozliczanie: <?= h(k30_ti_billing_model_label($cbm)) ?> <span class="opacity-75">(kod <?= $cbm ?>)</span><?php if ($cbm !== 2 && (float)($course['billing_amount'] ?? 0) > 0): ?> · <?= number_format((float)$course['billing_amount'],2,',','') ?> zł<?php endif; ?>
      </span>
      <?php if (!empty($course['pay_account']) || !empty($course['pay_title'])): ?>
      <span class="text-muted small ms-2"><i class="bi bi-bank me-1"></i><?= h($course['pay_account'] ?: '—') ?><?php if (!empty($course['pay_title'])): ?> · „<?= h($course['pay_title']) ?>"<?php endif; ?></span>
      <?php endif; ?>
      <span class="text-muted small ms-2"><i class="bi bi-calendar-event me-1"></i>Termin płatności: <?= (int)($course['pay_due_days'] ?? 0) ?: K30_TI_PAY_DUE_DAYS_DEFAULT ?> dni<?php if (empty($course['pay_due_days'])): ?> <span class="opacity-75">(domyślnie)</span><?php endif; ?></span>
    </div>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <?php if (!empty($course['requires_certificate']) && is_admin()): ?>
    <a href="certificate_issue.php?course_id=<?= $id ?>" class="btn btn-sm btn-outline-success">
      <i class="bi bi-patch-check me-1"></i>Certyfikaty X.509
    </a>
    <?php endif; ?>
    <a href="billing.php?course_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-receipt me-1"></i>Rozliczenia miesięczne
    </a>
    <a href="index.php?edit=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil me-1"></i>Edytuj kurs
    </a>
  </div>
</div>

<?= flash_html() ?>

<?php if (zoom_enabled()): ?>
<!-- ── Zoom: stały link kursu ─────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3" id="zoom">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <span class="fw-semibold text-nowrap"><i class="bi bi-camera-video-fill text-primary me-1"></i>Zoom — link kursu</span>
    <?php if (!empty($course['default_meeting_url'])): ?>
      <a href="<?= h($course['default_meeting_url']) ?>" target="_blank" rel="noopener"
         class="text-break small font-monospace"><?= h($course['default_meeting_url']) ?></a>
      <span class="text-muted" style="font-size:.72rem">ID: <?= h($course['zoom_meeting_id']) ?></span>
      <?php if (!empty($course['zoom_host_email'])): ?>
      <span class="badge bg-light text-secondary border" style="font-size:.7rem">
        <i class="bi bi-person me-1"></i><?= h($course['zoom_host_email']) ?>
      </span>
      <?php endif; ?>
    <?php else: ?>
      <span class="text-muted small">Brak stałego linku — kursanci i prowadzący dołączają przez indywidualne linki lub wpisują URL ręcznie.</span>
    <?php endif; ?>
    <?php if ($can_write): ?>
    <div class="ms-auto d-flex gap-2 flex-shrink-0">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="gen_course_zoom">
        <button type="submit" class="btn btn-sm btn-outline-primary"
                onclick="return confirm('<?= empty($course['default_meeting_url']) ? 'Wygenerować stały link Zoom dla kursu?' : 'Zregenerować stały link Zoom? Stary link przestanie działać — kursanci i prowadzący będą musieli użyć nowego.' ?>') ">
          <i class="bi bi-arrow-repeat me-1"></i><?= empty($course['default_meeting_url']) ? 'Wygeneruj link Zoom' : 'Regeneruj link' ?>
        </button>
      </form>
      <?php if (!empty($course['default_meeting_url'])): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="clear_course_zoom">
        <button type="submit" class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Usunąć stały link Zoom kursu? Operacja jest nieodwracalna.')">
          <i class="bi bi-trash me-1"></i>Usuń link
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── CoProwadzący ──────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4" id="coinstructors">
  <div class="card-header fw-semibold d-flex align-items-center py-2">
    <i class="bi bi-people-fill me-2 text-secondary"></i>CoProwadzący
    <span class="badge bg-secondary ms-2"><?= count($coinstructors) ?></span>
    <span class="ms-2 text-muted fw-normal" style="font-size:.8rem">Dostęp do kursu w panelu dydaktyka — bez rozliczenia</span>
  </div>
  <div class="card-body py-2 px-3">
    <?php if ($coinstructors): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php foreach ($coinstructors as $ci): ?>
      <div class="d-flex align-items-center gap-1 border rounded px-2 py-1" style="font-size:.85rem;background:#f8fafc">
        <i class="bi bi-person-badge text-secondary me-1"></i>
        <span><?= h($ci['user_name']) ?></span>
        <span class="text-muted" style="font-size:.75rem"><?= h($ci['user_email']) ?></span>
        <?php if ($can_write): ?>
        <form method="post" class="d-inline ms-1">
          <input type="hidden" name="_csrf"           value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"             value="coinstr_remove">
          <input type="hidden" name="coinstr_user_id" value="<?= (int)$ci['user_id'] ?>">
          <button type="submit" class="btn btn-xs btn-sm btn-outline-danger border-0 p-0 px-1"
                  title="Usuń coProwadzącego"
                  onclick="return confirm('Usunąć <?= h(addslashes($ci['user_name'])) ?> z listy coProwadzących?')">
            <i class="bi bi-x-lg" style="font-size:.7rem"></i>
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <span class="text-muted" style="font-size:.85rem">Brak coProwadzących — kurs dostępny wyłącznie dla głównego prowadzącego.</span>
    <?php endif; ?>

    <?php if ($can_write && $coinstr_available): ?>
    <form method="post" class="d-flex gap-2 align-items-end mt-2 flex-wrap">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="coinstr_add">
      <div>
        <label class="form-label small fw-semibold mb-1">Dodaj coProwadzącego</label>
        <select name="coinstr_user_id" class="form-select form-select-sm" required style="min-width:200px">
          <option value="">— wybierz prowadzącego —</option>
          <?php foreach ($coinstr_available as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name']) ?> <span class="text-muted">(<?= h($u['email']) ?>)</span></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-person-plus me-1"></i>Dodaj
      </button>
    </form>
    <?php elseif ($can_write && !$coinstr_available): ?>
    <div class="text-muted mt-2" style="font-size:.8rem">Wszyscy prowadzący już są dodani jako coProwadzący.</div>
    <?php endif; ?>
  </div>
</div>

<!-- Kalendarz lekcji — GŁÓWNY widok terminów kursu (przeszłe i przyszłe) -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-calendar3-week me-2 text-primary"></i>Kalendarz lekcji
    <span class="ms-2 text-muted fw-normal" style="font-size:.8rem">Kliknij dzień, aby otworzyć lekcję</span>
  </div>
  <div class="card-body">
    <div id="courseCalendar"></div>
  </div>
</div>

<!-- FullCalendar v6 -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
(function(){
  var el = document.getElementById('courseCalendar');
  if (!el) return;
  var cal = new FullCalendar.Calendar(el, {
    locale: 'pl',
    initialView: 'dayGridMonth',
    height: 'auto',
    firstDay: 1,
    buttonText: { today: 'Dziś' },
    headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
    events: function (fetchInfo, successCallback, failureCallback) {
      var s = fetchInfo.startStr.slice(0, 10), e = fetchInfo.endStr.slice(0, 10);
      fetch('?id=<?= (int)$id ?>&_json=1&start=' + s + '&end=' + e)
        .then(function (r) { return r.json(); })
        .then(successCallback)
        .catch(failureCallback);
    },
    eventClick: function (info) {
      info.jsEvent.preventDefault();
      window.location.href = 'lesson.php?id=' + info.event.id;
    }
  });
  cal.render();
})();
</script>

<div class="row g-4">

  <!-- Uczestnicy -->
  <div class="col-lg-6" id="uczestnicy">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-people me-2 text-primary"></i>Uczestnicy
        <span class="badge bg-secondary ms-2"><?= count(array_filter($enrollments,fn($e)=>$e['status']==='active')) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Klient</th><th>Rozliczanie</th><th>Status</th><th class="text-end">Akcje</th></tr>
          </thead>
          <tbody>
            <?php foreach ($enrollments as $e):
              $eff = k30_ti_effective_billing($e, $course);
          // Cena obowiązująca DZIŚ (po zaplanowanej zmianie ceny, jeśli trwa)
          $eff_base = $eff; $eff = ti_price_eff_on($eff, (int)$course['id'], (int)$e['client_id'], date('Y-m-d')); ?>
            <tr class="<?= $e['status']!=='active'?'text-muted opacity-75':'' ?>">
              <td><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$e['client_id'] ?>"><?= h($e['client_name']) ?></a></td>
              <td>
                <?php if ($eff['individual']): ?>
                <span class="badge bg-warning text-dark" title="Indywidualne ustalenia">9999 · indyw.</span>
                <?php else: ?>
                <span class="badge bg-light text-secondary border" title="Kod modelu">kod <?= (int)$eff['code'] ?></span>
                <?php endif; ?>
                <span class="small"><?= h($eff['label']) ?>:</span>
                <span class="fw-semibold small">
                  <?php if ($eff['model'] === 2): ?><?= number_format($eff['hourly_rate'],2,',','') ?> zł/h
                  <?php else: ?><?= number_format($eff['amount'],2,',','') ?> zł<?php endif; ?>
                </span>
                <?php if (!empty($eff['price_change_id'])): ?>
                <span class="badge text-bg-info" style="font-size:.62rem" title="Zmiana ceny #<?= (int)$eff['price_change_id'] ?> — cena bazowa <?= $eff_base['model'] === 2 ? number_format($eff_base['hourly_rate'], 2, ',', '') . ' zł/h' : number_format($eff_base['amount'], 2, ',', '') . ' zł' ?>">po zmianie ceny</span>
                <?php endif; ?>
                <?php if (!empty($eff['pay_account']) || !empty($eff['pay_title'])): ?>
                <div class="text-muted" style="font-size:.72rem"><i class="bi bi-bank me-1"></i><?= h($eff['pay_account'] ?: '—') ?><?php if (!empty($eff['pay_title'])): ?> · „<?= h($eff['pay_title']) ?>"<?php endif; ?></div>
                <?php endif; ?>
                <?php if ($can_write): ?>
                <button type="button" class="btn btn-xs btn-sm btn-link p-0 ms-1 align-baseline" data-bs-toggle="modal" data-bs-target="#bill<?= (int)$e['client_id'] ?>" title="Zmień rozliczanie"><i class="bi bi-pencil"></i></button>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $e['status']==='active'?'bg-success':'bg-secondary' ?>"><?= $e['status']==='active'?'Aktywny':'Nieaktywny' ?></span></td>
              <td class="text-end" style="white-space:nowrap">
                <?php if ($e['status']==='active' && $can_write && zoom_enabled()): ?>
                <button type="button" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2 me-1"
                        data-bs-toggle="modal" data-bs-target="#zoomStu<?= (int)$e['client_id'] ?>"
                        title="<?= !empty($e['zoom_meeting_url']) ? 'Regeneruj / usuń stały link Zoom' : 'Wygeneruj stały link Zoom' ?>">
                  <i class="bi bi-camera-video"></i>
                  <?php if (!empty($e['zoom_meeting_url'])): ?><span class="badge text-bg-success ms-1" style="font-size:.6rem">Zoom</span><?php endif; ?>
                </button>
                <?php endif; ?>
                <?php if ($e['status']==='active' && $can_write): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Wypisać uczestnika?')">
                  <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"       value="unenroll">
                  <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2">
                    <i class="bi bi-x-lg"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$enrollments): ?>
            <tr><td colspan="4" class="text-muted text-center py-3">Brak uczestników.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Modale: indywidualne rozliczanie kursanta (czytelne okienka) -->
      <?php if ($can_write): foreach ($enrollments as $e):
        $course_due = (int)($course['pay_due_days'] ?? 0) ?: K30_TI_PAY_DUE_DAYS_DEFAULT; ?>
      <div class="modal fade" id="bill<?= (int)$e['client_id'] ?>" tabindex="-1" aria-labelledby="billLbl<?= (int)$e['client_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title h5" id="billLbl<?= (int)$e['client_id'] ?>">
                <i class="bi bi-cash-coin text-success me-2" aria-hidden="true"></i>Rozliczanie — <?= h($e['client_name']) ?>
              </h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <form method="post">
              <div class="modal-body">
                <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"       value="set_billing">
                <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
                <div class="mb-3">
                  <label class="form-label small fw-semibold mb-1" for="bm<?= (int)$e['client_id'] ?>">Model (override)</label>
                  <select name="billing_model" id="bm<?= (int)$e['client_id'] ?>" class="form-select form-select-sm bill-model" onchange="billToggle(this)">
                    <option value="0" <?= (int)$e['billing_model']===0?'selected':'' ?>>— jak kurs (<?= h(k30_ti_billing_model_label((int)($course['billing_model']?:2))) ?>) —</option>
                    <?php foreach ([1,2,3] as $code): ?>
                    <option value="<?= $code ?>" <?= (int)$e['billing_model']===$code?'selected':'' ?>>Indywidualny: <?= h(k30_ti_billing_model_label($code)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="row g-2 mb-3">
                  <div class="col-6 bill-rate" style="<?= in_array((int)$e['billing_model'],[1,3],true)?'display:none':'' ?>">
                    <label class="form-label small mb-0">Stawka stacjonarna (zł/h)</label>
                    <input type="number" name="hourly_rate" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)$e['hourly_rate'],2,'.','')) ?>">
                  </div>
                  <div class="col-6 bill-rate" style="<?= in_array((int)$e['billing_model'],[1,3],true)?'display:none':'' ?>">
                    <label class="form-label small mb-0">Stawka online (zł/h)</label>
                    <input type="number" name="hourly_rate_online" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)($e['hourly_rate_online'] ?? 0),2,'.','')) ?>" title="0 = jak stacjonarna">
                  </div>
                  <div class="col-6 bill-amount" style="<?= in_array((int)$e['billing_model'],[1,3],true)?'':'display:none' ?>">
                    <label class="form-label small mb-0">Kwota (zł)</label>
                    <input type="number" name="billing_amount" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)$e['billing_amount'],2,'.','')) ?>">
                  </div>
                </div>
                <div class="row g-2">
                  <div class="col-12">
                    <label class="form-label small mb-0">Nr konta (indyw., gdy kod 9999)</label>
                    <input type="text" name="pay_account" class="form-control form-control-sm font-monospace" value="<?= h($e['pay_account'] ?? '') ?>" placeholder="puste = domyślny kursu">
                  </div>
                  <div class="col-sm-8">
                    <label class="form-label small mb-0">Tytuł wpłaty (indyw.)</label>
                    <input type="text" name="pay_title" class="form-control form-control-sm" value="<?= h($e['pay_title'] ?? '') ?>" placeholder="puste = domyślny kursu">
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label small mb-0">Termin płatn. (dni)</label>
                    <input type="number" name="pay_due_days" class="form-control form-control-sm" min="0" max="365" value="<?= !empty($e['pay_due_days']) ? (int)$e['pay_due_days'] : '' ?>" placeholder="<?= $course_due ?>" title="puste = jak kurs (<?= $course_due ?> dni)">
                  </div>
                </div>
                <p class="form-text mt-2 mb-0">Wybór indywidualnego modelu nadaje kursantowi kod 9999. Dane do wpłat działają tylko przy kodzie 9999 (inaczej obowiązują domyślne kursu).</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>

      <!-- Modale: stały link Zoom per kursant -->
      <?php if ($can_write && zoom_enabled()): foreach ($enrollments as $e): if ($e['status'] !== 'active') continue; ?>
      <div class="modal fade" id="zoomStu<?= (int)$e['client_id'] ?>" tabindex="-1" aria-labelledby="zoomStuLbl<?= (int)$e['client_id'] ?>" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title h5" id="zoomStuLbl<?= (int)$e['client_id'] ?>">
                <i class="bi bi-camera-video text-primary me-2" aria-hidden="true"></i>Stały link Zoom — <?= h($e['client_name']) ?>
              </h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
              <?php if (!empty($e['zoom_meeting_url'])): ?>
              <div class="alert alert-success py-2 mb-3">
                <i class="bi bi-check-circle me-1"></i>Aktywny link:<br>
                <a href="<?= h($e['zoom_meeting_url']) ?>" target="_blank" rel="noopener" class="small"><?= h($e['zoom_meeting_url']) ?></a>
                <div class="text-muted" style="font-size:.72rem">ID spotkania: <?= h($e['zoom_meeting_id']) ?></div>
              </div>
              <p class="mb-0">Generowanie nowego linku <strong>usunie</strong> bieżące spotkanie Zoom i stworzy nowe (stały URL się zmieni).</p>
              <?php else: ?>
              <p>Zostanie utworzone nowe spotkanie Zoom (typ: cykliczne bez stałego terminu) dla kursanta <strong><?= h($e['client_name']) ?></strong>.</p>
              <p class="text-muted small mb-0">Link będzie wyświetlany kursantowi przy każdej zaplanowanej lekcji w zakładce <em>Moje lekcje</em> oraz w zakładce <em>Szkolenia online</em>.</p>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <?php if (!empty($e['zoom_meeting_url'])): ?>
              <form method="post" class="me-auto">
                <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"       value="clear_student_zoom">
                <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-trash me-1"></i>Usuń link
                </button>
              </form>
              <?php endif; ?>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"       value="gen_student_zoom">
                <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-camera-video me-1"></i><?= !empty($e['zoom_meeting_url']) ? 'Regeneruj link' : 'Wygeneruj link' ?>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>

      <?php if ($can_write && $not_enrolled): ?>
      <div class="card-footer bg-light">
        <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="enroll">
          <div>
            <label class="form-label small fw-semibold mb-1">Dodaj uczestnika</label>
            <select name="client_id" class="form-select form-select-sm" required>
              <option value="">— wybierz —</option>
              <?php foreach ($not_enrolled as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label small fw-semibold mb-1">Stawka stacjonarna (zł/h)</label>
            <input type="number" class="form-control form-control-sm" name="hourly_rate" step="0.01" min="0" value="0" style="width:90px">
          </div>
          <div>
            <label class="form-label small fw-semibold mb-1">Stawka online (zł/h)</label>
            <input type="number" class="form-control form-control-sm" name="hourly_rate_online" step="0.01" min="0" value="0" style="width:90px" title="0 = jak stacjonarna">
          </div>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-person-plus me-1"></i>Zapisz
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lekcje -->
  <div class="col-lg-6" id="lekcje">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-calendar3 me-2 text-primary"></i>Lista lekcji
        <span class="text-muted fw-normal" style="font-size:.75rem">(ostatnie 30 dni)</span>
        <span class="badge bg-secondary ms-2"><?= count($sessions) ?></span>
        <button type="button" class="btn btn-xs btn-sm btn-outline-secondary ms-auto py-0 px-2"
                data-bs-toggle="collapse" data-bs-target="#lekcjeListaBody">
          <i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>Rozwiń/zwiń
        </button>
        <?php if ($can_write): ?>
        <button type="button" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2"
                data-bs-toggle="modal" data-bs-target="#addLessonModal">
          <i class="bi bi-plus-lg me-1"></i>Dodaj lekcję
        </button>
        <?php endif; ?>
      </div>
      <div class="collapse" id="lekcjeListaBody">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Data</th><th>Godziny</th><th>Obecność</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $s):
              $st = K30_TI_SESSION_STATUSES[$s['status']] ?? ['label'=>$s['status'],'color'=>'#666','bg'=>'#eee'];
              $is_remote_prep = !empty($s['self_prep_remote']);
            ?>
            <tr<?= $is_remote_prep ? ' style="background:#ecfeff;box-shadow:inset 3px 0 0 #06b6d4"' : '' ?>>
              <td class="text-nowrap">
                <?= date('d.m.Y',strtotime($s['lesson_date'])) ?>
                <?php if ($is_remote_prep): ?>
                <i class="bi bi-laptop text-info ms-1" title="Praca prowadzącego — przygotowanie materiału do wykonania zdalnie"></i>
                <?php endif; ?>
              </td>
              <td class="text-muted small text-nowrap">
                <?= $s['time_from'] ? h($s['time_from']).'–'.h($s['time_to']) : '' ?>
                <span class="text-muted">(<?= (int)$s['duration_min'] ?> min)</span>
              </td>
              <td><?= (int)$s['attended_count'] ?>/<?= (int)$s['total_count'] ?></td>
              <td>
                <span class="badge" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>33;font-size:.72rem">
                  <?= h($st['label']) ?>
                </span>
              </td>
              <td class="text-end">
                <a href="lesson.php?id=<?= (int)$s['id'] ?>"
                   class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 me-1"
                   title="<?= $s['status']==='planned'?'Lista obecności':'Podgląd' ?>">
                  <?= $s['status']==='planned'?'<i class="bi bi-clipboard-check"></i>':'<i class="bi bi-eye"></i>' ?>
                </a>
                <button type="button"
                        class="btn btn-xs btn-sm btn-outline-primary py-0 px-2"
                        title="Klonuj lekcję na inny dzień"
                        onclick="openClone(<?= (int)$s['id'] ?>, '<?= h($s['lesson_date']) ?>', '<?= h($s['time_from']) ?>', '<?= h($s['time_to']) ?>')">
                  <i class="bi bi-copy"></i>
                </button>
                <?php if ($s['status'] === 'reserved'): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="confirm_reservation">
                  <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-success py-0 px-2 ms-1" title="Potwierdź rezerwację">
                    <i class="bi bi-bookmark-check"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$sessions): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">Brak lekcji. <?php if ($can_write): ?><a href="#" data-bs-toggle="modal" data-bs-target="#addLessonModal">Dodaj pierwszą</a>.<?php endif; ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      </div><!-- /lekcjeListaBody -->
    </div>
  </div>

</div>

<!-- Modal: dodanie lekcji (czytelne okienko zamiast ciasnego formularza w stopce) -->
<?php if ($can_write): ?>
<div class="modal fade" id="addLessonModal" tabindex="-1" aria-labelledby="addLessonLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post" id="add_session_form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="add_lesson">
        <div class="modal-header">
          <h2 class="modal-title h5" id="addLessonLabel"><i class="bi bi-calendar-plus text-primary me-2" aria-hidden="true"></i>Dodaj lekcję</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold d-block">Data <span class="text-danger">*</span></label>
            <input type="hidden" name="lesson_date" id="sess_date" value="<?= date('Y-m-d') ?>" required>
            <div id="sess_calendar" class="ti-term-calendar border rounded"></div>
            <div class="form-text mt-1" id="sess_date_display">Dziś: <?= date('d.m.Y') ?> — kliknij inny dzień, aby zmienić.</div>
          </div>
          <div class="row g-2 mb-3 align-items-end">
            <div class="col-5">
              <label class="form-label fw-semibold" for="sess_tf">Godz. od</label>
              <select class="form-select" name="time_from" id="sess_tf" onchange="updateDur()"><?= ti_time_options() ?></select>
            </div>
            <div class="col-5">
              <label class="form-label fw-semibold" for="sess_tt">Godz. do</label>
              <select class="form-select" name="time_to" id="sess_tt" onchange="updateDur()"><?= ti_time_options() ?></select>
            </div>
            <div class="col-2">
              <label class="form-label fw-semibold" for="sess_dur">Min</label>
              <input type="number" class="form-control" name="duration_min" id="sess_dur" min="15" step="15" value="60">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="sess_url">
              <i class="bi bi-camera-video me-1 text-primary" aria-hidden="true"></i>Link do lekcji online <span class="text-muted fw-normal">(opcjonalnie)</span>
            </label>
            <input type="url" class="form-control" name="meeting_url" id="sess_url"
                   placeholder="<?= !empty($course['default_meeting_url']) ? 'puste = stały link grupy' : 'https://… (Teams/Zoom/Meet)' ?>">
            <?php if (!empty($course['default_meeting_url'])): ?>
            <div class="form-text">Puste = stały link grupy: <span class="font-monospace"><?= h($course['default_meeting_url']) ?></span></div>
            <?php endif; ?>
          </div>
          <?php $c_av = ti_instructor_availability(ti_course_instructor_id((int)$id)); ?>
          <?php if ($c_av): ?>
          <div class="form-text mb-2"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Dostępność prowadzącego:
            <?php
              $byd = [];
              foreach ($c_av as $w) { $byd[(int)$w['day_of_week']][] = substr($w['time_from'],0,5).'–'.substr($w['time_to'],0,5); }
              $parts = [];
              foreach ([1,2,3,4,5,6,0] as $dw) { if (!empty($byd[$dw])) $parts[] = mb_substr(K30_TI_DAYS[$dw],0,2,'UTF-8').' '.implode('/', $byd[$dw]); }
              echo h(implode(' · ', $parts));
            ?>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="ignore_availability" id="sess_ignore" value="1">
            <label class="form-check-label" for="sess_ignore">Dodaj poza dostępnością (nadpisanie administratora)</label>
          </div>
          <?php else: ?>
          <p class="form-text mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Prowadzący nie ma zdefiniowanej dostępności — lekcje bez ograniczeń. <a href="availability.php?instructor=<?= ti_course_instructor_id((int)$id) ?>">Ustaw dostępność</a>.</p>
          <?php endif; ?>
          <div class="form-check form-switch mt-2 p-2 rounded" style="background:#EFF6FF">
            <input class="form-check-input" type="checkbox" role="switch" name="is_reservation" id="sess_reservation" value="1">
            <label class="form-check-label" for="sess_reservation">
              <i class="bi bi-bookmark-star me-1" aria-hidden="true"></i>To jest rezerwacja terminu (nie ostateczna lekcja)
            </label>
            <div class="form-text mb-0">Termin zostaje zablokowany, ale kursanci jej nie zobaczą, dopóki nie zostanie potwierdzona.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj lekcję</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function updateDur() {
  var tf = document.getElementById('sess_tf').value;
  var tt = document.getElementById('sess_tt').value;
  if (tf && tt) {
    var m = (new Date('1970-01-01T'+tt) - new Date('1970-01-01T'+tf)) / 60000;
    if (m > 0) document.getElementById('sess_dur').value = Math.round(m);
  }
}
(function(){
  var modalEl = document.getElementById('addLessonModal');
  if (!modalEl) return;
  var calEl = document.getElementById('sess_calendar');
  var cal = null;

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
    document.getElementById('sess_date').value = dateStr;
    calEl.querySelectorAll('.ti-selected-day').forEach(function (d) { d.classList.remove('ti-selected-day'); });
    var cell = calEl.querySelector('[data-date="' + dateStr + '"]');
    if (cell) cell.classList.add('ti-selected-day');
    var disp = document.getElementById('sess_date_display');
    if (disp) {
      var d = new Date(dateStr + 'T00:00:00');
      disp.textContent = 'Wybrano: ' + d.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
  }

  modalEl.addEventListener('shown.bs.modal', function () {
    var c = ensureCalendar();
    c.updateSize();
    var cur = document.getElementById('sess_date').value;
    if (cur) { c.gotoDate(cur); selectDate(cur); }
  });

  document.getElementById('add_session_form').addEventListener('submit', function (e) {
    if (!document.getElementById('sess_date').value) {
      e.preventDefault();
      alert('Wybierz datę lekcji w kalendarzu.');
    }
  });
})();
</script>
<?php endif; ?>

<script>
// Inline edycja stawki
document.querySelectorAll('.rate-form').forEach(function(form) {
  form.querySelector('.rate-display').addEventListener('click', function() {
    this.style.display = 'none';
    form.querySelector('.rate-edit').style.display = 'inline-flex';
    form.querySelector('.rate-edit input').focus();
  });
});
</script>

<!-- Modal klonowania lekcji -->
<div class="modal fade" id="cloneModal" tabindex="-1" aria-labelledby="cloneModalLabel">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"          value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"            value="clone_lesson">
        <input type="hidden" name="src_lesson_id"  id="clone_src_id">
        <div class="modal-header">
          <h5 class="modal-title" id="cloneModalLabel">
            <i class="bi bi-copy me-2 text-primary"></i>Klonuj lekcję
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3" id="clone_src_info"></p>
          <div class="mb-3">
            <label class="form-label fw-semibold d-block">Nowa data lekcji <span class="text-danger">*</span></label>
            <input type="hidden" name="clone_date" id="clone_date" value="<?= date('Y-m-d') ?>" required>
            <div id="clone_calendar" class="ti-term-calendar border rounded"></div>
            <div class="form-text mt-1" id="clone_date_display">Kliknij dzień w kalendarzu, aby wybrać nową datę.</div>
            <div class="form-text">Godziny (od–do) i czas trwania zostaną skopiowane z oryginału.</div>
          </div>
          <?php if (ti_instructor_availability(ti_course_instructor_id((int)$id))): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="ignore_availability" id="clone_ignore" value="1">
            <label class="form-check-label" for="clone_ignore">Klonuj poza dostępnością prowadzącego (nadpisanie)</label>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-copy me-1"></i>Klonuj
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  var modalEl = document.getElementById('cloneModal');
  if (!modalEl) return;
  var calEl = document.getElementById('clone_calendar');
  var cal = null, pendingDate = '';
  var todayStr = '<?= date('Y-m-d') ?>';

  function ensureCalendar() {
    if (cal) return cal;
    cal = new FullCalendar.Calendar(calEl, {
      locale: 'pl', initialView: 'dayGridMonth', height: 'auto', firstDay: 1,
      validRange: { start: todayStr },
      headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
      buttonText: { today: 'Dziś' },
      dateClick: function (info) { selectDate(info.dateStr); }
    });
    cal.render();
    return cal;
  }

  function selectDate(dateStr) {
    document.getElementById('clone_date').value = dateStr;
    calEl.querySelectorAll('.ti-selected-day').forEach(function (d) { d.classList.remove('ti-selected-day'); });
    var cell = calEl.querySelector('[data-date="' + dateStr + '"]');
    if (cell) cell.classList.add('ti-selected-day');
    var disp = document.getElementById('clone_date_display');
    if (disp) {
      var d = new Date(dateStr + 'T00:00:00');
      disp.textContent = 'Wybrano: ' + d.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
  }

  window.tiSetCloneDate = function (dateStr) { pendingDate = dateStr || todayStr; };

  modalEl.addEventListener('shown.bs.modal', function () {
    var c = ensureCalendar();
    c.updateSize();
    if (pendingDate) { c.gotoDate(pendingDate); selectDate(pendingDate); }
  });

  modalEl.querySelector('form').addEventListener('submit', function (e) {
    if (!document.getElementById('clone_date').value) {
      e.preventDefault();
      alert('Wybierz nową datę w kalendarzu.');
    }
  });
})();

function openClone(lessonId, date, timeFrom, timeTo) {
  document.getElementById('clone_src_id').value = lessonId;
  var info = 'Oryginał: ' + date.split('-').reverse().join('.');
  if (timeFrom) info += ', ' + timeFrom + (timeTo ? '–'+timeTo : '');
  document.getElementById('clone_src_info').textContent = info;
  // Zaproponuj następny tydzień
  var d = new Date(date + 'T12:00:00');
  d.setDate(d.getDate() + 7);
  var proposed = d.toISOString().slice(0,10);
  document.getElementById('clone_date').value = proposed;
  if (typeof window.tiSetCloneDate === 'function') window.tiSetCloneDate(proposed);
  new bootstrap.Modal(document.getElementById('cloneModal')).show();
}
// Override rozliczania kursanta — pokaż stawkę (godzinowy) lub kwotę (miesięczny/stały)
function billToggle(sel) {
  var row = sel.closest('form');
  if (!row) return;
  var v = parseInt(sel.value, 10);          // 0=jak kurs, 1=mies., 2=godz., 3=stały
  var amount = (v === 1 || v === 3);
  var rateEl = row.querySelector('.bill-rate');
  var amtEl  = row.querySelector('.bill-amount');
  if (rateEl) rateEl.style.display = amount ? 'none' : '';
  if (amtEl)  amtEl.style.display  = amount ? '' : 'none';
}
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
