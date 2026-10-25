<?php
/**
 * karty30/ti/dydaktyk/urlopy.php — Urlopy / dostępność WSZYSTKICH prowadzących.
 *
 * Przeniesione z modułu administracyjnego (karty30/ti/urlopy.php, teraz
 * przekierowanie) — wzorzec jak dni_wolne.php/okresy.php. dyd_is_staff()
 * zastępuje dawne is_admin() — kierownik nie ma konta SZO.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_leaves.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php';

$me  = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid = (int)$me['user_id'];
karty30_migrate();
ti_leaves_migrate();
ti_sched_migrate();

$can_write  = true;  // dyd_is_staff() już wymuszone bramką wyżej
$can_delete = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_leave') {
        $lid  = (int)($_POST['leave_id'] ?? 0);
        $iid  = (int)($_POST['instructor_id'] ?? 0);
        $from = trim($_POST['date_from'] ?? '');
        $to   = trim($_POST['date_to'] ?? '');
        $type = array_key_exists($_POST['type'] ?? '', TI_LEAVE_TYPES) ? $_POST['type'] : 'urlop';
        $note = trim($_POST['note'] ?? '');
        // Godziny: oba pola albo żadne (puste = cały dzień); okno dotyczy każdego dnia zakresu
        $tf = trim($_POST['time_from'] ?? ''); $tt = trim($_POST['time_to'] ?? '');
        if ($tf !== '' || $tt !== '') {
            if (!preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tt <= $tf) {
                flash_set('danger', 'Podaj poprawne godziny nieobecności (od wcześniejszej do późniejszej) albo zostaw oba pola puste.');
                header('Location: urlopy.php' . ($lid ? '?edit=' . $lid : '')); exit;
            }
        }
        $okFrom = DateTime::createFromFormat('Y-m-d', $from);
        $okTo   = DateTime::createFromFormat('Y-m-d', $to);
        if (!$iid || !$okFrom || !$okTo) {
            flash_set('danger', 'Wybierz prowadzącego oraz poprawny zakres dat.');
            header('Location: urlopy.php'); exit;
        }
        if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }
        if ($lid) {
            db()->prepare("UPDATE k30_ti_instructor_leaves SET instructor_id=?, date_from=?, date_to=?, time_from=?, time_to=?, type=?, note=? WHERE id=?")
               ->execute([$iid, $from, $to, $tf, $tt, $type, $note, $lid]);
            $saved_id = $lid; $msg = 'Nieobecność zaktualizowana.';
        } else {
            $saved_id = db_insert('k30_ti_instructor_leaves', [
                'instructor_id' => $iid, 'date_from' => $from, 'date_to' => $to, 'time_from' => $tf, 'time_to' => $tt,
                'type' => $type, 'note' => $note, 'created_by' => $uid,
            ]);
            $msg = 'Nieobecność dodana.';
        }
        $sms_n = 0;
        $n = ti_leaves_notify((int)$saved_id, false, !empty($_POST['notify_sms']), $sms_n);
        if ($n > 0) $msg .= " Wysłano powiadomienie e-mail o nieobecności do kursantów ({$n}).";
        if ($sms_n > 0) $msg .= " Wysłano SMS do uczestników zajęć kolidujących z nieobecnością ({$sms_n}).";
        // Kolidujące zajęcia trafiają do rozstrzygnięcia (zastępstwo / przełożenie / odwołanie)
        $res = sc_open_leave_resolution((int)$saved_id, $uid);
        $conf = count(ti_leave_conflicts((array)ti_leave_get((int)$saved_id)));
        if ($conf > 0) $msg .= " Zajęcia kolidujące z nieobecnością: {$conf} — rozstrzygnij je poniżej.";
        flash_set($conf > 0 ? 'warning' : 'success', $msg);
        header('Location: urlopy.php' . ($conf > 0 ? '?conflicts=' . (int)$saved_id . '#kolidujace' : '')); exit;
    }

    // Zbiorcze odwołanie zaznaczonych zajęć kolidujących z nieobecnością (+ e-mail / SMS do uczestników)
    if ($op === 'cancel_conflicts') {
        $lid  = (int)($_POST['leave_id'] ?? 0);
        $leave = $lid ? ti_leave_get($lid) : null;
        $ids  = array_values(array_filter(array_map('intval', (array)($_POST['session_ids'] ?? []))));
        $back = 'urlopy.php?conflicts=' . $lid . ((int)($_POST['group'] ?? 0) ? '&group=' . (int)$_POST['group'] : '') . '#kolidujace';
        if (!$leave || !$ids) { flash_set('danger', 'Zaznacz zajęcia do odwołania.'); header('Location: ' . $back); exit; }
        // Tylko zajęcia, które faktycznie kolidują z tą nieobecnością (nie ufamy identyfikatorom z formularza)
        $valid = array_map('intval', array_column(ti_leave_conflicts($leave), 'id'));
        $ids   = array_values(array_intersect($ids, $valid));
        if (!$ids) { flash_set('danger', 'Zaznaczone zajęcia nie kolidują już z tą nieobecnością.'); header('Location: ' . $back); exit; }
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') $reason = 'Nieobecność prowadzącego (' . mb_strtolower(ti_leave_type_label((string)$leave['type'])) . ')';
        $r = ti_absence_cancel_sessions($ids, $reason, (string)($me['name'] ?? ''), (int)$leave['instructor_id'], !empty($_POST['notify_email']), !empty($_POST['notify_sms']), !empty($_POST['ask_contact']));
        // Pozycje zgłoszenia oznaczamy jako odwołane
        db()->prepare("UPDATE k30_sc_request_items SET resolution='cancel', resolved_by=?, resolved_at=datetime('now')
                        WHERE resolution='pending' AND session_id IN (" . implode(',', $ids) . ")
                          AND request_id IN (SELECT id FROM k30_sc_requests WHERE leave_id=?)")->execute([$uid, $lid]);
        flash_set('success', 'Odwołano zajęć: ' . $r['cancelled'] . ($r['emails'] ? ", e-maili do uczestników: {$r['emails']}" : '') . ($r['sms'] ? ", SMS: {$r['sms']}" : '') . '.');
        header('Location: ' . $back); exit;
    }

    if ($op === 'delete_leave') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        if ($lid) {
            db()->prepare("DELETE FROM k30_ti_instructor_leaves WHERE id=?")->execute([$lid]);
            // Powiązane zgłoszenie przestaje wisieć w kolejce „do rozstrzygnięcia”
            db()->prepare("UPDATE k30_sc_requests SET status='cancelled', leave_id=NULL WHERE leave_id=? AND status='approved'")->execute([$lid]);
        }
        flash_set('success', 'Nieobecność usunięta.');
        header('Location: urlopy.php'); exit;
    }
}

$conf_id    = (int)($_GET['conflicts'] ?? 0);
$conf_leave = $conf_id ? ti_leave_get($conf_id) : null;
$conf_all   = $conf_leave ? ti_leave_conflicts($conf_leave) : [];
// Grupy (kursy) z kolidującymi zajęciami; ?group=ID zawęża listę i odwołanie do jednej grupy
$conf_groups = [];
foreach ($conf_all as $c) { $conf_groups[(int)$c['course_id']]['name'] = (string)$c['course_name']; $conf_groups[(int)$c['course_id']]['n'] = ($conf_groups[(int)$c['course_id']]['n'] ?? 0) + 1; }
$conf_group = (int)($_GET['group'] ?? 0);
if (!isset($conf_groups[$conf_group])) $conf_group = 0;
$conf_rows  = $conf_group ? array_values(array_filter($conf_all, fn($c) => (int)$c['course_id'] === $conf_group)) : $conf_all;
$instructors = k30_get_consultants();
$current  = ti_leaves_current();
$upcoming = ti_leaves_upcoming(30);
$all      = ti_leaves_all();

$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? ti_leave_get($edit_id) : null;
$ef = $edit_row ?: ['id'=>0,'instructor_id'=>0,'date_from'=>'','date_to'=>'','time_from'=>'','time_to'=>'','type'=>'urlop','note'=>''];

/** Czytelny zakres dat. */
function _urlopy_range(array $l): string {
    $f = date('d.m.Y', strtotime($l['date_from']));
    $t = date('d.m.Y', strtotime($l['date_to']));
    $r = $f === $t ? $f : "$f – $t";
    return ti_leave_is_hourly($l) ? $r . ', ' . ti_leave_hours_label($l) : $r;
}
/** Liczba dni urlopu (włącznie). */
function _urlopy_days(array $l): int {
    $d1 = new DateTime($l['date_from']); $d2 = new DateTime($l['date_to']);
    return (int)$d1->diff($d2)->days + 1;
}
$type_badge = ['urlop'=>'primary','chorobowe'=>'danger','okolicznosc'=>'info','inne'=>'secondary'];

$KP_TITLE  = 'Urlopy prowadzących — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'urlopy.php'; $KIER_LABEL = 'Urlopy prowadzących';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-airplane text-primary me-2" aria-hidden="true"></i>Urlopy / dostępność prowadzących</h1>
</div>

<?php if ($conf_leave): ?>
<section class="card border-0 shadow-sm mb-4" id="kolidujace" aria-labelledby="conf-h">
  <div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-exclamation-triangle text-warning" aria-hidden="true"></i>
    <span id="conf-h">Zajęcia kolidujące z nieobecnością: <?= h(sc_user_name((int)$conf_leave['instructor_id'])) ?>, <?= h(_urlopy_range($conf_leave)) ?></span>
    <span class="badge bg-warning text-dark"><?= count($conf_all) ?></span>
    <a class="btn btn-sm btn-outline-secondary ms-auto" href="urlopy.php">Zamknij</a>
  </div>
  <div class="card-body">
  <?php if (count($conf_groups) > 1): ?>
    <form method="get" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="conflicts" value="<?= (int)$conf_leave['id'] ?>">
      <div class="col-md-6">
        <label class="form-label fw-semibold" for="conf_group">Grupa</label>
        <select class="form-select" id="conf_group" name="group" onchange="this.form.submit()">
          <option value="0">Wszystkie grupy (<?= count($conf_all) ?>)</option>
          <?php foreach ($conf_groups as $gid => $g): ?><option value="<?= (int)$gid ?>" <?= $conf_group === $gid ? 'selected' : '' ?>><?= h($g['name']) ?> (<?= (int)$g['n'] ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-outline-primary">Pokaż grupę</button></div>
    </form>
  <?php endif; ?>
  <?php if (!$conf_rows): ?>
    <p class="mb-0 text-body-secondary"><i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i>Żadne zaplanowane zajęcia nie kolidują już z tą nieobecnością.</p>
  <?php else: ?>
    <p class="small text-body-secondary">Odwołaj zbiorczo zaznaczone zajęcia albo rozstrzygnij je pojedynczo (zastępstwo, przełożenie) w <a href="zgloszenia.php">Zgłoszeniach trenerów</a>. Odwołane zajęcia nie są wliczane do rozliczenia.</p>
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="cancel_conflicts">
      <input type="hidden" name="leave_id" value="<?= (int)$conf_leave['id'] ?>">
      <input type="hidden" name="group" value="<?= (int)$conf_group ?>">
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Zajęcia kolidujące z nieobecnością prowadzącego</caption>
          <thead class="table-light"><tr>
            <th scope="col" style="width:2.5rem"><input type="checkbox" class="form-check-input" id="conf_all" aria-label="Zaznacz wszystkie zajęcia" checked></th>
            <th scope="col">Termin</th><th scope="col">Kurs</th><th scope="col">Stan</th></tr></thead>
          <tbody>
          <?php foreach ($conf_rows as $c): ?>
            <tr>
              <td><input type="checkbox" class="form-check-input conf-chk" name="session_ids[]" value="<?= (int)$c['id'] ?>" id="cf<?= (int)$c['id'] ?>" checked aria-label="Odwołaj zajęcia <?= h($c['course_name'] . ' ' . $c['lesson_date']) ?>"></td>
              <td class="text-nowrap"><?= h(date('d.m.Y', strtotime((string)$c['lesson_date']))) ?>, <?= h(substr((string)$c['time_from'], 0, 5)) ?>–<?= h(substr((string)$c['time_to'], 0, 5)) ?></td>
              <td><?= h($c['course_name']) ?></td>
              <td class="small"><?= $c['status'] === 'reserved' ? 'Zarezerwowane' : 'Zaplanowane' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label fw-semibold" for="conf_reason">Powód odwołania (widoczny dla uczestników)</label>
          <input type="text" class="form-control" id="conf_reason" name="reason" maxlength="200" placeholder="np. Nieobecność prowadzącego (<?= h(mb_strtolower(ti_leave_type_label((string)$conf_leave['type']))) ?>)">
        </div>
        <div class="col-md-6 d-flex flex-wrap gap-3 pb-1">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="notify_email" id="conf_mail" value="1" checked><label class="form-check-label" for="conf_mail">E-mail do uczestników</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="notify_sms" id="conf_sms" value="1" checked><label class="form-check-label" for="conf_sms">SMS do uczestników</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="ask_contact" id="conf_contact" value="1" checked aria-describedby="conf_contact_h"><label class="form-check-label" for="conf_contact">Poproś o kontakt w sprawie nowego terminu</label></div>
        </div>
        <div class="col-12 form-text mt-0" id="conf_contact_h">Wiadomość (e-mail i SMS) dostanie dopisek z prośbą o kontakt; w e-mailu — odnośnik do zakładki Wiadomości w panelu kursanta.</div>
      </div>
      <div class="mt-3"><button type="submit" class="btn btn-danger"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>Odwołaj zaznaczone zajęcia</button></div>
    </form>
    <script>
    (function(){
      var all=document.getElementById('conf_all'); if(!all) return;
      var boxes=[].slice.call(document.querySelectorAll('.conf-chk'));
      all.addEventListener('change',function(){ boxes.forEach(function(b){ b.checked=all.checked; }); });
      boxes.forEach(function(b){ b.addEventListener('change',function(){ all.checked=boxes.every(function(x){return x.checked;}); }); });
    })();
    </script>
  <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<div class="row g-4">
  <!-- Dashboard dostępności -->
  <div class="col-lg-7">
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="cur-h">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-person-x text-danger me-2" aria-hidden="true"></i><span id="cur-h">Nieobecni teraz</span>
        <span class="badge bg-danger ms-2"><?= count($current) ?></span>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$current): ?>
        <li class="list-group-item text-body-secondary"><i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i>Wszyscy prowadzący są dziś dostępni.</li>
        <?php endif; ?>
        <?php foreach ($current as $l):
          $left = (new DateTime(date('Y-m-d')))->diff(new DateTime($l['date_to']))->days; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
          <span class="fw-semibold"><?= h($l['instructor_name']) ?></span>
          <span class="badge text-bg-<?= $type_badge[$l['type']] ?? 'secondary' ?>"><?= h(ti_leave_type_label($l['type'])) ?></span>
          <span class="text-body-secondary small"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h(_urlopy_range($l)) ?></span>
          <span class="badge bg-warning text-dark ms-auto">wraca za <?= $left === 0 ? 'dziś (ostatni dzień)' : ($left.' dn.') ?></span>
          <?php if ($l['note']): ?><span class="w-100 text-body-secondary small"><?= h($l['note']) ?></span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="card border-0 shadow-sm" aria-labelledby="up-h">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-calendar-event text-warning me-2" aria-hidden="true"></i><span id="up-h">Nadchodzące (30 dni)</span>
        <span class="badge bg-secondary ms-2"><?= count($upcoming) ?></span>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$upcoming): ?>
        <li class="list-group-item text-body-secondary">Brak zaplanowanych urlopów w najbliższych 30 dniach.</li>
        <?php endif; ?>
        <?php foreach ($upcoming as $l):
          $inDays = (new DateTime(date('Y-m-d')))->diff(new DateTime($l['date_from']))->days; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
          <span class="fw-semibold"><?= h($l['instructor_name']) ?></span>
          <span class="badge text-bg-<?= $type_badge[$l['type']] ?? 'secondary' ?>"><?= h(ti_leave_type_label($l['type'])) ?></span>
          <span class="text-body-secondary small"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h(_urlopy_range($l)) ?> (<?= _urlopy_days($l) ?> dn.)</span>
          <span class="badge bg-light text-secondary border ms-auto">za <?= $inDays ?> dn.</span>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <!-- Formularz + pełna lista -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $edit_row ? 'Edytuj nieobecność' : 'Dodaj nieobecność' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_token"    value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"        value="save_leave">
          <input type="hidden" name="leave_id"   value="<?= (int)$ef['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="l_instr">Prowadzący <span class="text-danger">*</span></label>
            <select class="form-select" name="instructor_id" id="l_instr" required>
              <option value="">— wybierz —</option>
              <?php foreach ($instructors as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)$ef['instructor_id']===(int)$u['id']?'selected':'' ?>><?= h($u['display_name'] ?? $u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label fw-semibold" for="l_from">Od <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_from" id="l_from" value="<?= h($ef['date_from']) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="l_to">Do <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_to" id="l_to" value="<?= h($ef['date_to']) ?>" required>
            </div>
          </div>
          <div class="row g-2 mb-1">
            <div class="col-6">
              <label class="form-label fw-semibold" for="l_tf">Godzina od <span class="fw-normal text-body-secondary">(opcjonalnie)</span></label>
              <input type="time" class="form-control" name="time_from" id="l_tf" value="<?= h(substr((string)($ef['time_from'] ?? ''), 0, 5)) ?>" aria-describedby="l_th">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="l_tt">Godzina do</label>
              <input type="time" class="form-control" name="time_to" id="l_tt" value="<?= h(substr((string)($ef['time_to'] ?? ''), 0, 5)) ?>" aria-describedby="l_th">
            </div>
          </div>
          <div class="form-text mb-2" id="l_th">Puste godziny = nieobecność przez cały dzień. Dla kilku dni okno godzin dotyczy każdego dnia zakresu — kolidują tylko zajęcia nakładające się na te godziny.</div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="l_type">Rodzaj</label>
            <select class="form-select" name="type" id="l_type">
              <?php foreach (TI_LEAVE_TYPES as $tk => $tl): ?>
              <option value="<?= h($tk) ?>" <?= ($ef['type'] ?? 'urlop')===$tk?'selected':'' ?>><?= h($tl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="l_note">Notatka</label>
            <input type="text" class="form-control" name="note" id="l_note" value="<?= h($ef['note'] ?? '') ?>" placeholder="np. zastępstwo: …">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="notify_sms" id="l_sms" value="1" checked>
            <label class="form-check-label" for="l_sms">Powiadom SMS uczestników zajęć kolidujących z nieobecnością</label>
            <div class="form-text">E-mail do kursantów prowadzącego idzie zawsze. SMS dostaną tylko osoby, które włączyły SMS o lekcjach.</div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz' : 'Dodaj nieobecność' ?></button>
            <?php if ($edit_row): ?><a href="urlopy.php" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-list-ul me-2" aria-hidden="true"></i>Wszystkie urlopy <span class="badge bg-secondary ms-1"><?= count($all) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <caption class="visually-hidden">Lista wszystkich urlopów prowadzących</caption>
          <thead class="table-light"><tr><th>Prowadzący</th><th>Zakres</th><th>Rodzaj</th><th>Zajęcia</th><th class="text-end">Akcje</th></tr></thead>
          <tbody>
            <?php if (!$all): ?><tr><td colspan="5" class="text-center text-muted py-3">Brak urlopów.</td></tr><?php endif; ?>
            <?php $today = date('Y-m-d'); foreach ($all as $l):
              $past = $l['date_to'] < $today; ?>
            <tr class="<?= $past ? 'opacity-50' : '' ?>">
              <td class="fw-semibold"><?= h($l['instructor_name']) ?></td>
              <td class="small text-nowrap"><?= h(_urlopy_range($l)) ?></td>
              <td><span class="badge text-bg-<?= $type_badge[$l['type']] ?? 'secondary' ?>"><?= h(ti_leave_type_label($l['type'])) ?></span></td>
              <td class="small text-nowrap">
                <?php $nconf = $past ? 0 : count(ti_leave_conflicts($l)); ?>
                <?php if ($nconf > 0): ?><a href="?conflicts=<?= (int)$l['id'] ?>#kolidujace" class="badge text-bg-warning text-decoration-none" aria-label="Zajęcia kolidujące z nieobecnością: <?= $nconf ?> — rozstrzygnij"><?= $nconf ?> do rozstrzygnięcia</a><?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a href="?edit=<?= (int)$l['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj" aria-label="Edytuj urlop"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten urlop?')">
                  <input type="hidden" name="_token"    value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op"        value="delete_leave">
                  <input type="hidden" name="leave_id"   value="<?= (int)$l['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń urlop"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
