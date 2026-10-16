<?php
/**
 * karty30/ti/dydaktyk/kontrolaWpisow.php — Kontrola Lekcji i Wpisów (panel dydaktyka).
 *
 * Kierownik / administrator (dyd_is_staff) = panel audytora: kolejka wpisów z filtrami, szczegóły lekcji
 * obok listy (master-detail, bez modali), decyzje Zatwierdź / Do poprawy / Zakwestionuj / Cofnij
 * z komentarzem, hurtowe zatwierdzanie PRAWIDŁOWYCH, historia zdarzeń, ustawienia reguł i wysyłek,
 * podgląd obu e-maili. Prowadzący = „Moje wpisy”: własne lekcje ze statusem i uwagami audytora.
 * Logika: modules/ti_lesson_control/logic/ (lessonControl.php). Nie zastępuje Audytu dzienników
 * (audyt_dziennikow.php) — ten pokazuje braki zbiorczo i linkuje tutaj.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_lesson_control/logic/lessonControl.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();
tiLcMigrate();
$SELF = 'kontrolaWpisow.php';

$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
$f = [
    'from' => $isDate($_GET['from'] ?? '') ?: date('Y-m-d', strtotime('-30 days')),
    'to'   => $isDate($_GET['to'] ?? '') ?: date('Y-m-d'),
    'i'    => $staff ? max(0, (int)($_GET['i'] ?? 0)) : $uid,
    'c'    => max(0, (int)($_GET['c'] ?? 0)),
    'a'    => isset(TiLcStatus::AUTO[$_GET['a'] ?? '']) ? $_GET['a'] : '',
    'r'    => isset(TiLcStatus::REVIEW[$_GET['r'] ?? '']) ? $_GET['r'] : '',
    'v'    => in_array($_GET['v'] ?? '', ['kolejka', 'wszystkie', 'ustawienia'], true) ? $_GET['v'] : ($staff ? 'kolejka' : 'wszystkie'),
];
if (!$staff && $f['v'] === 'ustawienia') $f['v'] = 'wszystkie';
$qs = fn(array $extra = []) => $SELF . '?' . http_build_query(array_filter(array_merge($f, $extra), fn($v) => $v !== '' && $v !== 0 && $v !== null));

// ── Podgląd e-maili (tylko audytor) ─────────────────────────────────────────
if ($staff && in_array($_GET['preview'] ?? '', ['reminder', 'report'], true)) {
    $now = new DateTimeImmutable();
    if ($_GET['preview'] === 'report') {
        $p = LessonControlNotifier::reportPeriod($now);
        $mail = LessonControlMailTemplates::supervisorReport((new LessonControlNotifier())->reportData($p['from'], $p['to'], $p['label']));
    } else {
        $by = (new LessonControlNotifier())->pendingReminders($now);
        $iid = (int)($_GET['i'] ?? 0) ?: (int)(array_key_first($by) ?? 0);
        $name = (string)(db_one("SELECT name FROM users WHERE id=?", [$iid])['name'] ?? 'Prowadzący');
        $mail = LessonControlMailTemplates::instructorReminder($name, $by[$iid] ?? [], ['reminder_no' => 1,
            'escalate_after' => LessonControlSettings::int('ti_lc_escalate_after'), 'deadline_label' => LessonControlPolicy::forCourse(0)->deadlineLabel()]);
    }
    if (($_GET['format'] ?? '') === 'text') { header('Content-Type: text/plain; charset=utf-8'); echo 'Temat: ' . $mail['subject'] . "\n\n" . $mail['text']; exit; }
    header('Content-Type: text/html; charset=utf-8'); echo $mail['html']; exit;
}

// ── Akcje ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op   = (string)($_POST['_op'] ?? '');
    $back = (string)($_POST['_back'] ?? $qs());
    if (!str_starts_with($back, $SELF)) $back = $qs();
    if (!$staff) { flash_set('danger', 'Weryfikować wpisy może tylko kierownik lub administrator.'); header('Location: ' . $back); exit; }
    $actor = ['id' => $uid, 'role' => dyd_is_admin() ? 'admin' : 'kierownik', 'is_admin' => dyd_is_admin()];
    try {
        if ($op === 'decide') {
            $sid = (int)($_POST['session_id'] ?? 0); $act = (string)($_POST['action'] ?? '');
            (new ReviewWorkflow())->decide($sid, $act, $actor, (int)($_POST['revision'] ?? 0), (string)($_POST['comment'] ?? ''));
            if ($act === ReviewWorkflow::RETURN) { try { (new LessonControlNotifier())->notifyReturned($sid); } catch (\Throwable $e) {} }
            flash_set('success', 'Zapisano decyzję: ' . ReviewWorkflow::TRANSITIONS[$act][4] . '.');
        } elseif ($op === 'bulk_approve') {
            $res = (new ReviewWorkflow())->approveBulk(array_map('intval', (array)($_POST['ids'] ?? [])), $actor);
            flash_set($res['approved'] ? 'success' : 'warning', 'Zatwierdzono: ' . $res['approved'] . ($res['skipped'] ? ', pominięto: ' . count($res['skipped']) . ' (tylko wpisy prawidłowe, nie własne)' : '') . '.');
        } elseif ($op === 'run_now') {
            [$from, $to] = LessonControlService::defaultRange();
            $sum = (new LessonControlService())->run(min($from, $f['from']), $to);
            flash_set('success', "Kontrola wykonana: sprawdzono {$sum->checked} lekcji, zmian statusu: " . count($sum->changes) . '.');
        } elseif ($op === 'save_settings') {
            $b = fn(string $k) => !empty($_POST[$k]) ? '1' : '0';
            $int = fn(string $k, int $min, int $max) => (string)max($min, min($max, (int)($_POST[$k] ?? 0)));
            foreach (['ti_lc_enabled', 'ti_lc_remind_enabled', 'ti_lc_sms_enabled', 'ti_lc_notify_on_return', 'ti_lc_report_enabled'] as $k) LessonControlSettings::set($k, $b($k));
            LessonControlSettings::set('ti_lc_lookback_days', $int('ti_lc_lookback_days', 1, 365));
            LessonControlSettings::set('ti_lc_remind_hour', $int('ti_lc_remind_hour', 6, 22));
            LessonControlSettings::set('ti_lc_remind_max', $int('ti_lc_remind_max', 1, 10));
            LessonControlSettings::set('ti_lc_remind_max_age_days', $int('ti_lc_remind_max_age_days', 1, 90));
            LessonControlSettings::set('ti_lc_escalate_after', $int('ti_lc_escalate_after', 1, 10));
            LessonControlSettings::set('ti_lc_report_hour', $int('ti_lc_report_hour', 6, 22));
            LessonControlSettings::set('ti_lc_report_weekday', $int('ti_lc_report_weekday', 1, 7));
            LessonControlSettings::set('ti_lc_report_frequency', ($_POST['ti_lc_report_frequency'] ?? '') === 'weekly' ? 'weekly' : 'daily');
            LessonControlSettings::set('ti_lc_start_date', $isDate($_POST['ti_lc_start_date'] ?? ''));
            $emails = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['ti_lc_report_recipients'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
            LessonControlSettings::set('ti_lc_report_recipients', implode(', ', $emails));
            flash_set('success', 'Zapisano ustawienia kontroli wpisów.');
        } elseif ($op === 'save_rule' || $op === 'delete_rule') {
            $cid = max(0, (int)($_POST['course_id'] ?? 0));
            if ($op === 'delete_rule') {
                if ($cid > 0) db_exec("DELETE FROM k30_ti_lc_rules WHERE course_id = ?", [$cid]);
                flash_set('success', 'Grupa wraca do reguły globalnej.');
            } else {
                $v = [
                    'deadline_mode' => ($_POST['deadline_mode'] ?? '') === 'hours_after_end' ? 'hours_after_end' : 'end_of_day',
                    'deadline_hours' => max(1, min(168, (int)($_POST['deadline_hours'] ?? 24))), 'grace_min' => max(0, min(1440, (int)($_POST['grace_min'] ?? 0))),
                    'req_topic' => !empty($_POST['req_topic']) ? 1 : 0, 'topic_min_len' => max(1, min(100, (int)($_POST['topic_min_len'] ?? 3))),
                    'req_attendance' => !empty($_POST['req_attendance']) ? 1 : 0, 'req_curriculum' => !empty($_POST['req_curriculum']) ? 1 : 0,
                    'req_materials' => !empty($_POST['req_materials']) ? 1 : 0, 'req_homework_if_flag' => !empty($_POST['req_homework_if_flag']) ? 1 : 0,
                ];
                db_exec("INSERT OR IGNORE INTO k30_ti_lc_rules (course_id) VALUES (?)", [$cid]);
                db_exec("UPDATE k30_ti_lc_rules SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ", updated_by = ?, updated_at = ? WHERE course_id = ?",
                    [...array_values($v), $uid, date('Y-m-d H:i:s'), $cid]);
                LessonControlPolicy::resetCache();
                flash_set('success', $cid ? 'Zapisano regułę grupy.' : 'Zapisano regułę globalną. Statusy przeliczą się przy najbliższej kontroli.');
            }
        }
    } catch (ReviewException $e) {
        flash_set('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('kontrolaWpisow: ' . $e->getMessage());
        flash_set('danger', 'Nie udało się wykonać operacji.');
    }
    header('Location: ' . $back); exit;
}

// ── Dane listy ──────────────────────────────────────────────────────────────
$where = ["k.lesson_date BETWEEN ? AND ?", "k.auto_status <> 'NIE_DOTYCZY'"]; $p = [$f['from'], $f['to']];
if ($f['i']) { $where[] = 'k.instructor_id = ?'; $p[] = $f['i']; }
if ($f['c']) { $where[] = 'k.course_id = ?'; $p[] = $f['c']; }
if ($f['a']) { $where[] = 'k.auto_status = ?'; $p[] = $f['a']; }
if ($f['r']) { $where[] = 'k.review_status = ?'; $p[] = $f['r']; }
if ($staff && $f['v'] === 'kolejka' && !$f['r']) $where[] = "k.review_status IN ('NIEZWERYFIKOWANY','POPRAWIONY','ZAKWESTIONOWANY') AND k.auto_status <> 'OCZEKUJE'";
$rows = db_all("SELECT k.*, s.time_from, s.time_to, s.topic, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name, COALESCE(u.name,'') AS instructor_name
                  FROM k30_ti_lc_checks k
                  JOIN k30_ti_sessions s ON s.id = k.session_id
                  JOIN k30_ti_courses c ON c.id = k.course_id
                  LEFT JOIN users u ON u.id = k.instructor_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY (k.review_status = 'POPRAWIONY') DESC, (k.review_status = 'ZAKWESTIONOWANY') DESC, k.lesson_date DESC, s.time_from DESC
                 LIMIT 300", $p);
$counts = ['auto' => [], 'review' => []];
foreach ($rows as $r) { $counts['auto'][$r['auto_status']] = ($counts['auto'][$r['auto_status']] ?? 0) + 1; }

// Szczegóły wybranej lekcji (przeliczone na świeżo)
$sel = null; $selEvents = []; $selActions = [];
$sid = (int)($_GET['s'] ?? 0);
if ($sid && dyd_owns_session($uid, $sid)) {
    try { $sel = (new LessonControlService())->refreshOne($sid); } catch (\Throwable $e) { $sel = LessonControlService::check($sid); }
    if ($sel) {
        $sel += db_one("SELECT s.time_from, s.time_to, s.topic, s.notes, s.status AS lesson_status, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name,
                               COALESCE(u.name,'') AS instructor_name, COALESCE(r.name,'') AS reviewer_name
                          FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
                          LEFT JOIN users u ON u.id = ? LEFT JOIN users r ON r.id = ?
                         WHERE s.id = ?", [(int)$sel['instructor_id'], (int)$sel['reviewed_by'], $sid]) ?? [];
        $selEvents  = TiLcEventLog::forSession($sid);
        $selActions = $staff ? ReviewWorkflow::allowedActions($sel) : [];
        if ($staff && (int)$sel['instructor_id'] === $uid && !dyd_is_admin()) $selActions = [];
    }
}

$instructors = $staff ? k30_ti_instructors() : [];
$courses = db_all("SELECT DISTINCT c.id, COALESCE(NULLIF(c.display_name,''), c.name) AS name FROM k30_ti_lc_checks k JOIN k30_ti_courses c ON c.id = k.course_id"
                  . ($staff ? '' : ' WHERE k.instructor_id = ' . $uid) . " ORDER BY 2 COLLATE NOCASE");
$S = LessonControlSettings::all();
$rules = db_all("SELECT r.*, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name FROM k30_ti_lc_rules r LEFT JOIN k30_ti_courses c ON c.id = r.course_id ORDER BY r.course_id");
$global = LessonControlPolicy::forCourse(0);
$lastRun = (string)(db_one("SELECT MAX(last_checked_at) AS t FROM k30_ti_lc_checks")['t'] ?? '');
$badge = fn(string $s) => '<span class="badge text-bg-' . h(TiLcStatus::color($s)) . '">' . h(TiLcStatus::label($s)) . '</span>';
$missing = fn(array $r) => implode(', ', array_map(fn($x) => (string)($x['label'] ?? ''), json_decode((string)$r['missing_json'], true) ?: []));
$when = fn(array $r) => date('d.m.Y', strtotime((string)$r['lesson_date'])) . ' ' . substr((string)$r['time_from'], 0, 5);
$EVENT_LABELS = [
    'auto_checked' => 'Pierwsza kontrola', 'auto_status_changed' => 'Zmiana statusu (automat)', 'reminder_sent' => 'Przypomnienie wysłane',
    'escalated' => 'Eskalacja do kierownika', 'review_approved' => 'Zatwierdzono', 'review_returned' => 'Zwrócono do poprawy',
    'review_disputed' => 'Zakwestionowano', 'review_reopened' => 'Cofnięto do weryfikacji', 'entry_corrected' => 'Prowadzący poprawił wpis',
    'entry_changed' => 'Zmieniono treść wpisu', 'entry_changed_after_approval' => 'Zmiana po zatwierdzeniu', 'return_notified' => 'E-mail o zwrocie wysłany',
];

$KP_TITLE  = 'Kontrola wpisów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .lc-list tr.is-sel td { background: #e8f0fb; }
  .lc-list td a.lc-open { font-weight: 600; }
  .lc-ev { border-left: 3px solid #cbd5e1; padding: .25rem 0 .25rem .6rem; margin-bottom: .4rem; }
  .lc-ev.is-human { border-left-color: #1d4ed8; }
  @media (min-width: 992px) { .lc-detail { position: sticky; top: 1rem; } }
</style>

<?php if ($staff): $KIER_CUR = $SELF; $KIER_LABEL = 'Kontrola wpisów'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1400px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-journal-check text-primary me-2" aria-hidden="true"></i><?= $staff ? 'Kontrola wpisów' : 'Moje wpisy z zajęć' ?></h1>
  <span class="text-body-secondary small ms-lg-2">Termin wpisu: <?= h($global->deadlineLabel()) ?><?= $lastRun ? ' · ostatnia kontrola ' . h(date('d.m H:i', strtotime($lastRun))) : '' ?></span>
  <?php if ($staff): ?>
  <form method="post" class="ms-auto">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="run_now"><input type="hidden" name="_back" value="<?= h($qs()) ?>">
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Sprawdź teraz</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($staff): ?>
<nav aria-label="Widoki kontroli" class="mb-3">
  <ul class="nav nav-pills small">
    <?php foreach (['kolejka' => ['Do weryfikacji', 'inbox'], 'wszystkie' => ['Wszystkie wpisy', 'list-ul'], 'ustawienia' => ['Ustawienia i szablony', 'gear']] as $k => [$lbl, $ico]): ?>
    <li class="nav-item"><a class="nav-link<?= $f['v'] === $k ? ' active' : '' ?>" <?= $f['v'] === $k ? 'aria-current="page"' : '' ?> href="<?= h($qs(['v' => $k, 's' => ''])) ?>"><i class="bi bi-<?= $ico ?> me-1" aria-hidden="true"></i><?= $lbl ?></a></li>
    <?php endforeach; ?>
    <li class="nav-item"><a class="nav-link" href="audyt_dziennikow.php"><i class="bi bi-clipboard2-check me-1" aria-hidden="true"></i>Audyt dzienników</a></li>
  </ul>
</nav>
<?php else: ?>
<p class="text-body-secondary small">Twoje lekcje z ostatnich tygodni i wynik kontroli wpisów. Kompletny wpis to temat zajęć, obecność oraz — jeśli grupa ma sylabus — zaznaczony zrealizowany materiał.
  Uwagi kierownika zobaczysz po kliknięciu lekcji; po poprawce wpis sam wraca do weryfikacji.</p>
<?php endif; ?>

<?php if ($f['v'] === 'ustawienia'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card border-0 shadow-sm">
      <div class="card-header"><h2 class="h6 mb-0 fw-semibold">Kontrola i wysyłki</h2></div>
      <div class="card-body small">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_settings"><input type="hidden" name="_back" value="<?= h($qs()) ?>">
        <?php $chk = fn(string $k, string $label, string $hint = '') => '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="' . $k . '" name="' . $k . '" value="1"' . ($S[$k] === '1' ? ' checked' : '') . '><label class="form-check-label" for="' . $k . '">' . $label . ($hint ? ' <span class="text-body-secondary">— ' . $hint . '</span>' : '') . '</label></div>'; ?>
        <?= $chk('ti_lc_enabled', 'Automatyczna kontrola wpisów', 'agent co godzinę oznacza statusy') ?>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label" for="lb">Ile dni wstecz</label><input class="form-control form-control-sm" id="lb" type="number" min="1" max="365" name="ti_lc_lookback_days" value="<?= h($S['ti_lc_lookback_days']) ?>"></div>
          <div class="col-6"><label class="form-label" for="sd">Nie wcześniej niż</label><input class="form-control form-control-sm" id="sd" type="date" name="ti_lc_start_date" value="<?= h($S['ti_lc_start_date']) ?>"></div>
        </div>
        <fieldset class="border rounded p-2 mb-3"><legend class="float-none w-auto px-1 fs-6">Przypomnienia dla prowadzących</legend>
          <?= $chk('ti_lc_remind_enabled', 'Wysyłaj e-mail o zaległych wpisach', 'jeden zbiorczy dziennie') ?>
          <?= $chk('ti_lc_sms_enabled', 'Dodatkowo SMS', 'rodzaj „Zaległe wpisy z zajęć”') ?>
          <?= $chk('ti_lc_notify_on_return', 'E-mail od razu po „Do poprawy”') ?>
          <div class="row g-2">
            <div class="col-6 col-md-3"><label class="form-label" for="rh">Od godziny</label><input class="form-control form-control-sm" id="rh" type="number" min="6" max="22" name="ti_lc_remind_hour" value="<?= h($S['ti_lc_remind_hour']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="rm">Max o lekcji</label><input class="form-control form-control-sm" id="rm" type="number" min="1" max="10" name="ti_lc_remind_max" value="<?= h($S['ti_lc_remind_max']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="ra">Nie starsze niż (dni)</label><input class="form-control form-control-sm" id="ra" type="number" min="1" max="90" name="ti_lc_remind_max_age_days" value="<?= h($S['ti_lc_remind_max_age_days']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="ea">Eskalacja po</label><input class="form-control form-control-sm" id="ea" type="number" min="1" max="10" name="ti_lc_escalate_after" value="<?= h($S['ti_lc_escalate_after']) ?>"></div>
          </div>
        </fieldset>
        <fieldset class="border rounded p-2 mb-3"><legend class="float-none w-auto px-1 fs-6">Raport dla nadzorującego</legend>
          <?= $chk('ti_lc_report_enabled', 'Wysyłaj raport z wyniku kontroli') ?>
          <div class="row g-2">
            <div class="col-6 col-md-4"><label class="form-label" for="rf">Częstotliwość</label>
              <select class="form-select form-select-sm" id="rf" name="ti_lc_report_frequency"><option value="daily"<?= $S['ti_lc_report_frequency'] === 'daily' ? ' selected' : '' ?>>codziennie (za wczoraj)</option><option value="weekly"<?= $S['ti_lc_report_frequency'] === 'weekly' ? ' selected' : '' ?>>co tydzień (za poprzedni)</option></select></div>
            <div class="col-6 col-md-4"><label class="form-label" for="rw">Dzień tygodnia</label>
              <select class="form-select form-select-sm" id="rw" name="ti_lc_report_weekday"><?php foreach ([1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobota', 7 => 'niedziela'] as $n => $d): ?><option value="<?= $n ?>"<?= (int)$S['ti_lc_report_weekday'] === $n ? ' selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-4"><label class="form-label" for="rph">Od godziny</label><input class="form-control form-control-sm" id="rph" type="number" min="6" max="22" name="ti_lc_report_hour" value="<?= h($S['ti_lc_report_hour']) ?>"></div>
            <div class="col-12"><label class="form-label" for="rr">Odbiorcy (e-maile po przecinku)</label><input class="form-control form-control-sm" id="rr" name="ti_lc_report_recipients" value="<?= h($S['ti_lc_report_recipients']) ?>" placeholder="puste = kierownicy TI">
              <div class="form-text">Teraz trafiłby do: <?= h(implode(', ', array_column(LessonControlNotifier::reportRecipients(), 'email')) ?: '—') ?></div></div>
          </div>
        </fieldset>
        <button class="btn btn-primary btn-sm">Zapisz ustawienia</button>
        <p class="mt-3 mb-0">Podgląd e-maili:
          <a href="<?= h($SELF . '?preview=reminder') ?>" target="_blank" rel="noopener">przypomnienie (HTML)</a> · <a href="<?= h($SELF . '?preview=reminder&format=text') ?>" target="_blank" rel="noopener">tekst</a> ·
          <a href="<?= h($SELF . '?preview=report') ?>" target="_blank" rel="noopener">raport (HTML)</a> · <a href="<?= h($SELF . '?preview=report&format=text') ?>" target="_blank" rel="noopener">tekst</a></p>
      </div>
    </form>
  </div>
  <div class="col-lg-6">
    <?php
    $ruleForm = function (?array $r, array $courses) {
        $p = LessonControlPolicy::fromRow($r ?? []); $cid = (int)($r['course_id'] ?? -1); $isNew = $r === null;
        $id = 'r' . ($isNew ? 'new' : $cid);
        ob_start(); ?>
        <form method="post" class="card border-0 shadow-sm mb-3">
          <div class="card-header d-flex align-items-center"><h2 class="h6 mb-0 fw-semibold"><?= $isNew ? 'Własna reguła dla grupy' : ($cid === 0 ? 'Reguła globalna' : 'Grupa: ' . h($r['course_name'] ?: '#' . $cid)) ?></h2></div>
          <div class="card-body small">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_rule">
            <?php if ($isNew): ?>
              <label class="form-label" for="<?= $id ?>c">Grupa</label>
              <select class="form-select form-select-sm mb-2" id="<?= $id ?>c" name="course_id" required><option value="">— wybierz —</option>
                <?php foreach (k30_ti_courses(false) as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select>
            <?php else: ?><input type="hidden" name="course_id" value="<?= $cid ?>"><?php endif; ?>
            <div class="row g-2 mb-2">
              <div class="col-6"><label class="form-label" for="<?= $id ?>m">Termin wpisu</label>
                <select class="form-select form-select-sm" id="<?= $id ?>m" name="deadline_mode"><option value="end_of_day"<?= $p->deadlineMode === 'end_of_day' ? ' selected' : '' ?>>do końca dnia zajęć</option><option value="hours_after_end"<?= $p->deadlineMode === 'hours_after_end' ? ' selected' : '' ?>>N godzin od końca lekcji</option></select></div>
              <div class="col-3"><label class="form-label" for="<?= $id ?>h">N godzin</label><input class="form-control form-control-sm" id="<?= $id ?>h" type="number" min="1" max="168" name="deadline_hours" value="<?= $p->deadlineHours ?>"></div>
              <div class="col-3"><label class="form-label" for="<?= $id ?>g">Tolerancja (min)</label><input class="form-control form-control-sm" id="<?= $id ?>g" type="number" min="0" max="1440" name="grace_min" value="<?= $p->graceMin ?>"></div>
            </div>
            <?php foreach (['req_topic' => [$p->reqTopic, 'Temat zajęć'], 'req_attendance' => [$p->reqAttendance, 'Obecność (gdy grupa prowadzi listę)'], 'req_curriculum' => [$p->reqCurriculum, 'Zrealizowany materiał (gdy grupa ma sylabus)'],
                               'req_materials' => [$p->reqMaterials, 'Materiały przypięte do lekcji'], 'req_homework_if_flag' => [$p->reqHomeworkIfFlag, 'Treść zadania, gdy zaznaczono zadanie domowe']] as $k => [$on, $lbl]): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="<?= $id . $k ?>" name="<?= $k ?>" value="1"<?= $on ? ' checked' : '' ?>><label class="form-check-label" for="<?= $id . $k ?>"><?= $lbl ?></label></div>
            <?php endforeach; ?>
            <div class="row g-2 mt-1"><div class="col-6"><label class="form-label" for="<?= $id ?>t">Min. długość tematu</label><input class="form-control form-control-sm" id="<?= $id ?>t" type="number" min="1" max="100" name="topic_min_len" value="<?= $p->topicMinLen ?>"></div></div>
            <div class="mt-2 d-flex gap-2"><button class="btn btn-primary btn-sm">Zapisz</button>
              <?php if (!$isNew && $cid > 0): ?><button class="btn btn-outline-danger btn-sm" name="_op" value="delete_rule">Usuń — wróć do globalnej</button><?php endif; ?></div>
          </div>
        </form>
        <?php return ob_get_clean();
    };
    foreach ($rules as $r) echo $ruleForm($r, $courses);
    echo $ruleForm(null, $courses);
    ?>
  </div>
</div>

<?php else: ?>
<form method="get" class="card border-0 shadow-sm mb-3" role="search" aria-label="Filtry wpisów">
  <div class="card-body row g-2 align-items-end small">
    <input type="hidden" name="v" value="<?= h($f['v']) ?>">
    <div class="col-6 col-md-2"><label class="form-label" for="ff">Od</label><input class="form-control form-control-sm" id="ff" type="date" name="from" value="<?= h($f['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="ft">Do</label><input class="form-control form-control-sm" id="ft" type="date" name="to" value="<?= h($f['to']) ?>"></div>
    <?php if ($staff): ?>
    <div class="col-6 col-md-2"><label class="form-label" for="fi">Prowadzący</label><select class="form-select form-select-sm" id="fi" name="i"><option value="">wszyscy</option>
      <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>"<?= $f['i'] === (int)$ins['id'] ? ' selected' : '' ?>><?= h($ins['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-6 col-md-2"><label class="form-label" for="fc">Grupa</label><select class="form-select form-select-sm" id="fc" name="c"><option value="">wszystkie</option>
      <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $f['c'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="fa">Wynik kontroli</label><select class="form-select form-select-sm" id="fa" name="a"><option value="">każdy</option>
      <?php foreach (TiLcStatus::AUTO as $k => [$l]) if ($k !== TiLcStatus::NIE_DOTYCZY): ?><option value="<?= $k ?>"<?= $f['a'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endif; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="fr">Weryfikacja</label><select class="form-select form-select-sm" id="fr" name="r"><option value="">każda</option>
      <?php foreach (TiLcStatus::REVIEW as $k => [$l]): ?><option value="<?= $k ?>"<?= $f['r'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj</button><a class="btn btn-outline-secondary btn-sm" href="<?= h($SELF . '?v=' . $f['v']) ?>">Wyczyść</a>
      <span class="ms-auto align-self-center text-body-secondary" aria-live="polite">Wpisów: <strong><?= count($rows) ?></strong><?= count($rows) >= 300 ? ' (pierwsze 300 — zawęź filtr)' : '' ?></span></div>
  </div>
</form>

<div class="row g-3">
  <div class="<?= $sel ? 'col-lg-7' : 'col-12' ?>">
    <?php if (!$rows): ?>
      <div class="alert alert-success d-flex align-items-center gap-2" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <span><?= $staff && $f['v'] === 'kolejka' ? 'Kolejka pusta — nic nie czeka na weryfikację.' : 'Brak wpisów dla wybranych filtrów.' ?></span></div>
    <?php else: ?>
    <form method="post" id="lc-bulk">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="bulk_approve"><input type="hidden" name="_back" value="<?= h($qs(['s' => $sid ?: ''])) ?>">
      <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0 lc-list">
          <caption class="visually-hidden">Wpisy z lekcji i wynik kontroli</caption>
          <thead><tr>
            <?php if ($staff): ?><th scope="col"><span class="visually-hidden">Zaznacz</span></th><?php endif; ?>
            <th scope="col">Lekcja</th><th scope="col">Grupa</th><?php if ($staff): ?><th scope="col">Prowadzący</th><?php endif; ?>
            <th scope="col">Kontrola</th><th scope="col">Czego brakuje</th><th scope="col">Weryfikacja</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $rid = (int)$r['session_id']; ?>
            <tr class="<?= $rid === $sid ? 'is-sel' : '' ?>">
              <?php if ($staff): ?><td><?php if ($r['auto_status'] === TiLcStatus::PRAWIDLOWY && in_array($r['review_status'], [TiLcStatus::NIEZWERYFIKOWANY, TiLcStatus::POPRAWIONY], true) && ((int)$r['instructor_id'] !== $uid || dyd_is_admin())): ?>
                <input class="form-check-input" type="checkbox" name="ids[]" value="<?= $rid ?>" aria-label="Zaznacz lekcję <?= h($when($r)) ?> do zatwierdzenia"><?php endif; ?></td><?php endif; ?>
              <td class="text-nowrap"><a class="lc-open" href="<?= h($qs(['s' => $rid])) ?>#lc-detail"><?= h($when($r)) ?></a></td>
              <td><?= h($r['course_name']) ?><div class="small text-body-secondary text-truncate" style="max-width:220px"><?= h($r['topic'] ?: '— bez tematu —') ?></div></td>
              <?php if ($staff): ?><td class="small"><?= h($r['instructor_name'] ?: '—') ?></td><?php endif; ?>
              <td><?= $badge($r['auto_status']) ?><?php if ((int)$r['delay_min'] > 0): ?><div class="small text-body-secondary"><?= h(round($r['delay_min'] / 60, 1)) ?> h po terminie</div><?php endif; ?></td>
              <td class="small"><?= h($missing($r) ?: '—') ?></td>
              <td><?= $badge($r['review_status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
      <?php if ($staff): ?><button class="btn btn-sm btn-success mt-2"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Zatwierdź zaznaczone (tylko prawidłowe)</button><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($sel): ?>
  <div class="col-lg-5">
    <section class="card border-0 shadow-sm lc-detail" id="lc-detail" aria-labelledby="lc-detail-h" tabindex="-1">
      <div class="card-header d-flex align-items-start gap-2">
        <h2 class="h6 mb-0 fw-semibold" id="lc-detail-h"><?= h($sel['course_name'] ?? '') ?><span class="d-block small fw-normal text-body-secondary"><?= h($when($sel)) ?>–<?= h(substr((string)($sel['time_to'] ?? ''), 0, 5)) ?> · <?= h($sel['instructor_name'] ?: 'bez prowadzącego') ?></span></h2>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= h($qs(['s' => ''])) ?>">Zamknij</a>
      </div>
      <div class="card-body small">
        <dl class="row mb-2">
          <dt class="col-5">Wynik kontroli</dt><dd class="col-7"><?= $badge($sel['auto_status']) ?></dd>
          <dt class="col-5">Weryfikacja</dt><dd class="col-7"><?= $badge($sel['review_status']) ?><?php if ($sel['reviewed_at']): ?><div class="text-body-secondary"><?= h($sel['reviewer_name'] ?? '') ?>, <?= h(date('d.m.Y H:i', strtotime((string)$sel['reviewed_at']))) ?></div><?php endif; ?></dd>
          <dt class="col-5">Termin wpisu</dt><dd class="col-7"><?= $sel['due_at'] ? h(date('d.m.Y H:i', strtotime((string)$sel['due_at']))) : '—' ?></dd>
          <dt class="col-5">Wpis złożony</dt><dd class="col-7"><?= $sel['entry_submitted_at'] ? h(date('d.m.Y H:i', strtotime((string)$sel['entry_submitted_at']))) : '—' ?><?= (int)$sel['delay_min'] > 0 ? ' <span class="text-danger">(' . h(round($sel['delay_min'] / 60, 1)) . ' h po terminie)</span>' : '' ?></dd>
          <dt class="col-5">Czego brakuje</dt><dd class="col-7"><?php $ms = json_decode((string)$sel['missing_json'], true) ?: []; if (!$ms): ?>—<?php else: ?><ul class="mb-0 ps-3"><?php foreach ($ms as $m): ?><li><strong><?= h($m['label'] ?? '') ?></strong><?= !empty($m['detail']) ? ': ' . h($m['detail']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?></dd>
          <dt class="col-5">Temat</dt><dd class="col-7"><?= h(($sel['topic'] ?? '') ?: '—') ?></dd>
          <?php if (trim((string)($sel['notes'] ?? '')) !== ''): ?><dt class="col-5">Notatki</dt><dd class="col-7"><?= nl2br(h($sel['notes'])) ?></dd><?php endif; ?>
          <?php if ($sel['review_comment'] !== ''): ?><dt class="col-5">Uwagi audytora</dt><dd class="col-7"><div class="alert alert-warning py-1 px-2 mb-0"><?= nl2br(h($sel['review_comment'])) ?></div></dd><?php endif; ?>
          <dt class="col-5">Przypomnienia</dt><dd class="col-7"><?= (int)$sel['reminder_count'] ?><?= $sel['escalated_at'] ? ' · <span class="text-danger">eskalacja</span>' : '' ?></dd>
        </dl>
        <p class="mb-3"><a href="index.php?course=<?= (int)$sel['course_id'] ?>&amp;tab=lekcje"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i><?= $staff ? 'Otwórz lekcje grupy' : 'Uzupełnij wpis w Lekcjach' ?></a></p>

        <?php if ($selActions): ?>
        <form method="post" class="border rounded p-2 mb-3">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="decide">
          <input type="hidden" name="session_id" value="<?= $sid ?>"><input type="hidden" name="revision" value="<?= (int)$sel['revision'] ?>"><input type="hidden" name="_back" value="<?= h($qs(['s' => $sid])) ?>">
          <label class="form-label fw-semibold" for="lc-comment">Komentarz <span class="fw-normal text-body-secondary">(wymagany przy „Do poprawy”, „Zakwestionuj”, „Cofnij” i przy zatwierdzaniu niepełnego wpisu)</span></label>
          <textarea class="form-control form-control-sm mb-2" id="lc-comment" name="comment" rows="3" maxlength="2000"></textarea>
          <div class="d-flex flex-wrap gap-2">
            <?php $btn = [ReviewWorkflow::APPROVE => 'success', ReviewWorkflow::RETURN => 'warning', ReviewWorkflow::DISPUTE => 'outline-danger', ReviewWorkflow::REOPEN => 'outline-secondary'];
            foreach ($selActions as $a): ?>
            <button class="btn btn-sm btn-<?= $btn[$a] ?>" name="action" value="<?= h($a) ?>"><?= h(ReviewWorkflow::TRANSITIONS[$a][4]) ?></button>
            <?php endforeach; ?>
          </div>
        </form>
        <?php elseif ($staff && (int)$sel['instructor_id'] === $uid && !dyd_is_admin()): ?>
        <p class="text-body-secondary">To Twoja lekcja — weryfikuje ją inny kierownik.</p>
        <?php endif; ?>

        <h3 class="h6 fw-semibold">Historia (ślad rewizyjny)</h3>
        <?php if (!$selEvents): ?><p class="text-body-secondary">Brak zdarzeń.</p><?php endif; ?>
        <?php foreach ($selEvents as $e): $human = $e['actor_role'] !== 'system'; ?>
          <div class="lc-ev<?= $human ? ' is-human' : '' ?>">
            <div><strong><?= h($EVENT_LABELS[$e['event_type']] ?? $e['event_type']) ?></strong>
              <?php if ($e['from_status'] !== $e['to_status'] && $e['to_status'] !== ''): ?><span class="text-body-secondary"><?= h($e['from_status'] ? TiLcStatus::label($e['from_status']) . ' → ' : '') . h(TiLcStatus::label($e['to_status'])) ?></span><?php endif; ?></div>
            <div class="text-body-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$e['created_at']))) ?> · <?= $human ? h(($e['actor_name'] ?: '#' . $e['actor_user_id']) . ' (' . $e['actor_role'] . ')') : 'system' ?></div>
            <?php if ($e['comment'] !== ''): ?><div>„<?= h($e['comment']) ?>”</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
