<?php
/**
 * karty30/ti/dydaktyk/index.php — Panel dydaktyka (prowadzącego) TI.
 *
 * Logowanie jak do SZO; dostęp dla doradców TyfloKonsultacji (k30_consultant)
 * oraz pracowników K30/admina. Dydaktyk widzi WYŁĄCZNIE swoje kursy i może w nich:
 *   • dodawać / edytować / usuwać lekcje,
 *   • dodawać / edytować / usuwać zadania domowe,
 *   • dodawać / edytować / usuwać materiały (eLearning).
 * Wzorowane na panelu kursanta (wspólny layout, ciemny motyw, WCAG).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_leaves.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['id'];

$courses   = dyd_courses($uid);
$my_leaves = ti_leaves_for_instructor($uid);   // własne urlopy: trwające + nadchodzące

// ── Pobieranie załączników (zadania / materiały) — tylko z własnych kursów ────
if (isset($_GET['dl'])) {
    $kind = $_GET['dl'];
    if ($kind === 'hw') {
        $hw = k30_ti_homework_get((int)($_GET['id'] ?? 0));
        if ($hw && dyd_owns_course($uid, (int)$hw['course_id']) && $hw['attach_path'] !== '')
            k30_ti_homework_send_file($hw['attach_path'], $hw['attach_name']);
    } elseif ($kind === 'mat') {
        $m = k30_ti_material_get((int)($_GET['id'] ?? 0));
        if ($m && dyd_owns_course($uid, (int)$m['course_id']) && $m['attach_path'] !== '')
            k30_ti_homework_send_file($m['attach_path'], $m['attach_name']);
    }
    http_response_code(404); exit('Plik nie istnieje.');
}

// ── Bieżący kurs i zakładka ───────────────────────────────────────────────────
$course_ids = array_map(fn($c) => (int)$c['id'], $courses);
$cur_course = (int)($_GET['course'] ?? 0);
if (!in_array($cur_course, $course_ids, true)) $cur_course = $course_ids[0] ?? 0;
$tab = $_GET['tab'] ?? 'lekcje';
if (!in_array($tab, ['lekcje', 'zadania', 'materialy'], true)) $tab = 'lekcje';

/** Adres powrotu zachowujący kurs i zakładkę. */
function dyd_back(int $course, string $tab): string {
    return 'index.php?course=' . $course . '&tab=' . $tab;
}
$dt_in = fn($k) => ($v = trim($_POST[$k] ?? '')) !== '' ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;

// ── Operacje zapisu ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op        = $_POST['_op'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);
    $back_tab  = in_array($_POST['_tab'] ?? '', ['lekcje','zadania','materialy'], true) ? $_POST['_tab'] : 'lekcje';

    // Każda operacja wymaga własności kursu.
    if (!dyd_owns_course($uid, $course_id)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }

    // ── LEKCJE ────────────────────────────────────────────────────────────────
    if ($op === 'save_lesson') {
        $sid   = (int)($_POST['session_id'] ?? 0);
        $date  = trim($_POST['lesson_date'] ?? '');
        $tf    = trim($_POST['time_from'] ?? '');
        $tt    = trim($_POST['time_to'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $dur   = 60;
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }
        if ($date === '') { flash_set('danger', 'Data lekcji jest wymagana.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }

        if ($sid && dyd_owns_session($uid, $sid)) {
            $st = in_array($_POST['status'] ?? '', ['planned','held'], true) ? $_POST['status'] : 'planned';
            db()->prepare(
                "UPDATE k30_ti_sessions
                 SET lesson_date=?, time_from=?, time_to=?, duration_min=?, topic=?, notes=?, status=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$date, $tf, $tt, $dur, $topic, $notes, $st, $sid]);
            flash_set('success', 'Lekcja zaktualizowana.');
        } else {
            $sid = db_insert('k30_ti_sessions', [
                'course_id'   => $course_id, 'lesson_date' => $date,
                'time_from'   => $tf, 'time_to' => $tt, 'duration_min' => $dur,
                'status'      => 'planned', 'topic' => $topic, 'notes' => $notes,
                'created_by'  => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            // Wstępna obecność dla aktywnych uczestników
            foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]) as $e) {
                try { db_insert('k30_ti_attendance', ['session_id'=>$sid, 'client_id'=>(int)$e['client_id'], 'attended'=>0]); }
                catch (\Throwable $ex) {}
            }
            $msg = 'Lekcja dodana.';
            if (isset($_POST['notify'])) {
                $cn = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
                $when = $date . ($tf !== '' ? ' o ' . $tf : '');
                $n = ti_lesson_sms_notify($course_id, 'Nowe zajecia: ' . ($cn['name'] ?? '') . ' — ' . $when . '. Szczegoly w panelu kursanta.');
                if ($n) $msg .= " Wysłano SMS: {$n}.";
            }
            flash_set('success', $msg);
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    if ($op === 'delete_lesson') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if (dyd_owns_session($uid, $sid)) {
            db()->prepare("DELETE FROM k30_ti_sessions WHERE id=?")->execute([$sid]);
            flash_set('success', 'Lekcja usunięta.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // ── ZADANIA DOMOWE ──────────────────────────────────────────────────────────
    if ($op === 'save_homework') {
        $hid       = (int)($_POST['homework_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $due       = trim($_POST['due_at'] ?? '');
        $due_sql   = $due !== '' ? str_replace('T', ' ', $due) . (strlen($due) === 16 ? ':00' : '') : null;
        $open_at   = $dt_in('open_at');
        $close_at  = $dt_in('close_at');
        if ($title === '') { flash_set('danger', 'Podaj tytuł zadania.'); header('Location: ' . dyd_back($course_id, 'zadania')); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id])) $session_id = null;

        try { $up = k30_ti_homework_upload('attach', 'hw'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: ' . dyd_back($course_id, 'zadania')); exit; }

        if ($hid) {
            $hw = k30_ti_homework_get($hid);
            if (!$hw || !dyd_owns_course($uid, (int)$hw['course_id'])) { http_response_code(403); exit('Brak uprawnień.'); }
            $set = ['course_id'=>$course_id, 'session_id'=>$session_id, 'title'=>$title, 'description'=>$desc,
                    'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
                    'is_active'=>isset($_POST['is_active'])?1:0];
            if ($up) {
                if ($hw['attach_path'] !== '') k30_ti_homework_delete_file($hw['attach_path']);
                $set['attach_name'] = $up['name']; $set['attach_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($set as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=$hid;
            db()->prepare("UPDATE k30_ti_homework SET ".implode(',',$cols)." WHERE id=?")->execute($p);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Zmiana w zadaniu: ' . $title,
                    'Prowadzący zaktualizował zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '".',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': zmiana w zadaniu "' . $title . '".');
            }
            flash_set('success', 'Zadanie zaktualizowane.');
        } else {
            db_insert('k30_ti_homework', [
                'course_id'=>$course_id, 'session_id'=>$session_id, 'title'=>$title, 'description'=>$desc,
                'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
                'attach_name'=>$up['name']??'', 'attach_path'=>$up['stored']??'',
                'is_active'=>1, 'created_by'=>$uid,
            ]);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Nowe zadanie: ' . $title,
                    'Prowadzący dodał nowe zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '"'
                        . ($due_sql ? ' (termin: ' . substr($due_sql,0,16) . ')' : '') . '.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': nowe zadanie "' . $title . '"' . ($due_sql ? ', termin ' . substr($due_sql,0,16) : '') . '.');
            }
            flash_set('success', 'Zadanie dodane.');
        }
        header('Location: ' . dyd_back($course_id, 'zadania')); exit;
    }

    if ($op === 'delete_homework') {
        $hid = (int)($_POST['homework_id'] ?? 0);
        $hw  = $hid ? k30_ti_homework_get($hid) : null;
        if ($hw && dyd_owns_course($uid, (int)$hw['course_id'])) {
            foreach (db_all("SELECT file_path FROM k30_ti_homework_submissions WHERE homework_id=?", [$hid]) as $s)
                k30_ti_homework_delete_file($s['file_path']);
            k30_ti_homework_delete_file($hw['attach_path']);
            db()->prepare("DELETE FROM k30_ti_homework WHERE id=?")->execute([$hid]);
            flash_set('success', 'Zadanie usunięte.');
        }
        header('Location: ' . dyd_back($course_id, 'zadania')); exit;
    }

    // ── MATERIAŁY / eLEARNING ───────────────────────────────────────────────────
    if ($op === 'save_material') {
        $TYPES     = k30_ti_material_types();
        $mid       = (int)($_POST['material_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $type      = trim($_POST['type'] ?? 'inne');
        if (!isset($TYPES[$type])) $type = 'inne';
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $url       = trim($_POST['url'] ?? '');
        $open_at   = $dt_in('open_at');
        $close_at  = $dt_in('close_at');
        if ($title === '') { flash_set('danger', 'Podaj tytuł materiału.'); header('Location: ' . dyd_back($course_id, 'materialy')); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id])) $session_id = null;

        try { $up = k30_ti_homework_upload('attach', 'mat'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: ' . dyd_back($course_id, 'materialy')); exit; }

        if ($mid) {
            $m = k30_ti_material_get($mid);
            if (!$m || !dyd_owns_course($uid, (int)$m['course_id'])) { http_response_code(403); exit('Brak uprawnień.'); }
            $set = ['course_id'=>$course_id, 'session_id'=>$session_id, 'type'=>$type,
                    'title'=>$title, 'description'=>$desc, 'url'=>$url,
                    'open_at'=>$open_at, 'close_at'=>$close_at,
                    'is_active'=>isset($_POST['is_active'])?1:0];
            if ($up) {
                if ($m['attach_path'] !== '') k30_ti_homework_delete_file($m['attach_path']);
                $set['attach_name'] = $up['name']; $set['attach_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($set as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=$mid;
            db()->prepare("UPDATE k30_ti_materials SET ".implode(',',$cols)." WHERE id=?")->execute($p);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Zmiana w materiale: ' . $title,
                    'Prowadzący zaktualizował materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': zaktualizowano material "' . $title . '".');
            }
            flash_set('success', 'Materiał zaktualizowany.');
        } else {
            db_insert('k30_ti_materials', [
                'course_id'=>$course_id, 'session_id'=>$session_id, 'type'=>$type,
                'title'=>$title, 'description'=>$desc, 'url'=>$url,
                'open_at'=>$open_at, 'close_at'=>$close_at,
                'attach_name'=>$up['name']??'', 'attach_path'=>$up['stored']??'',
                'is_active'=>1, 'created_by'=>$uid,
            ]);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Nowy materiał: ' . $title,
                    'Prowadzący dodał nowy materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': nowy material "' . $title . '" w panelu kursanta.');
            }
            flash_set('success', 'Materiał dodany.');
        }
        header('Location: ' . dyd_back($course_id, 'materialy')); exit;
    }

    if ($op === 'delete_material') {
        $mid = (int)($_POST['material_id'] ?? 0);
        $m   = $mid ? k30_ti_material_get($mid) : null;
        if ($m && dyd_owns_course($uid, (int)$m['course_id'])) {
            k30_ti_homework_delete_file($m['attach_path']);
            db()->prepare("DELETE FROM k30_ti_materials WHERE id=?")->execute([$mid]);
            flash_set('success', 'Materiał usunięty.');
        }
        header('Location: ' . dyd_back($course_id, 'materialy')); exit;
    }
}

// ── Dane do widoku ──────────────────────────────────────────────────────────
$course   = null;
foreach ($courses as $c) { if ((int)$c['id'] === $cur_course) { $course = $c; break; } }
$dtv      = fn($v) => $v ? h(str_replace(' ', 'T', substr($v, 0, 16))) : '';

$sessions = $materials = $homeworks = [];
$all_sessions = [];
if ($cur_course) {
    $sessions     = k30_ti_sessions($cur_course);
    $homeworks    = k30_ti_homework_list($cur_course);
    $materials    = k30_ti_materials_list($cur_course);
    $all_sessions = db_all("SELECT id, lesson_date, topic FROM k30_ti_sessions WHERE course_id=? ORDER BY lesson_date DESC, id DESC", [$cur_course]);
}

$TYPES = k30_ti_material_types();
$STATUS = K30_TI_SESSION_STATUSES;

// ── Formularze renderowane w wyskakujących okienkach (dodawanie + edycja) ─────
// $r = wiersz do edycji lub null (dodawanie). $pfx = unikalny prefiks id pól/modalu.

$lessonFormHtml = function(?array $r, string $pfx) use ($cur_course) {
    $isEdit = (bool)$r; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="save_lesson">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" value="<?= (int)($r['id'] ?? 0) ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'calendar-plus' ?> me-2"></i><?= $isEdit?'Edytuj lekcję':'Nowa lekcja' ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_date">Data <span class="text-danger">*</span></label>
        <input type="date" class="form-control" id="<?= $pfx ?>_date" name="lesson_date" required value="<?= h($r['lesson_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_from">Od</label>
          <select class="form-select" id="<?= $pfx ?>_from" name="time_from"><?= ti_time_options($r['time_from'] ?? '') ?></select>
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_to">Do</label>
          <select class="form-select" id="<?= $pfx ?>_to" name="time_to"><?= ti_time_options($r['time_to'] ?? '') ?></select>
        </div>
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_topic">Temat lekcji</label>
        <input type="text" class="form-control" id="<?= $pfx ?>_topic" name="topic" value="<?= h($r['topic'] ?? '') ?>" placeholder="np. Podstawy HTML">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_notes">Notatki</label>
        <textarea class="form-control" id="<?= $pfx ?>_notes" name="notes" rows="2"><?= h($r['notes'] ?? '') ?></textarea>
      </div>
      <?php if ($isEdit): ?>
      <div class="mb-1">
        <label class="form-label" for="<?= $pfx ?>_status">Status</label>
        <select class="form-select" id="<?= $pfx ?>_status" name="status">
          <option value="planned" <?= ($r['status']??'')==='planned'?'selected':'' ?>>Zaplanowana</option>
          <option value="held" <?= ($r['status']??'')==='held'?'selected':'' ?>>Odbyła się</option>
        </select>
        <?php if (($r['status']??'')==='cancelled'): ?><div class="form-text text-warning">Lekcja odwołana — zapis zmieni status.</div><?php endif; ?>
      </div>
      <?php else: ?>
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" name="notify" id="<?= $pfx ?>_notify" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_notify">Powiadom kursantów SMS o nowych zajęciach</label>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><?= $isEdit?'Zapisz zmiany':'Dodaj lekcję' ?></button>
    </div>
  </form>
<?php };

$hwFormHtml = function(?array $r, string $pfx) use ($cur_course, $all_sessions, $dtv) {
    $isEdit = (bool)$r; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="save_homework">
    <input type="hidden" name="_tab" value="zadania">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="homework_id" value="<?= (int)($r['id'] ?? 0) ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'journal-plus' ?> me-2"></i><?= $isEdit?'Edytuj zadanie':'Nowe zadanie' ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_title">Tytuł <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="<?= $pfx ?>_title" name="title" required value="<?= h($r['title'] ?? '') ?>" placeholder="np. Ćwiczenie 1 — formularz HTML">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_desc">Polecenie / opis</label>
        <textarea class="form-control" id="<?= $pfx ?>_desc" name="description" rows="3"><?= h($r['description'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_session">Powiązana lekcja <span class="text-body-secondary small">(opc.)</span></label>
        <select class="form-select" id="<?= $pfx ?>_session" name="session_id">
          <option value="">— bez powiązania —</option>
          <?php foreach ($all_sessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)($r['session_id']??0)===(int)$s['id']?'selected':'' ?>><?= h(date('d.m.Y', strtotime($s['lesson_date']))) ?><?= $s['topic']?' · '.h(mb_substr($s['topic'],0,30)):'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_due">Termin oddania <span class="text-body-secondary small">(opc.)</span></label>
        <input type="datetime-local" class="form-control" id="<?= $pfx ?>_due" name="due_at" value="<?= $dtv($r['due_at'] ?? '') ?>">
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_open">Otwarcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_open" name="open_at" value="<?= $dtv($r['open_at'] ?? '') ?>">
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_close">Zamknięcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_close" name="close_at" value="<?= $dtv($r['close_at'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_attach">Załącznik <span class="text-body-secondary small">(opc., maks. 25 MB)</span></label>
        <input type="file" class="form-control" id="<?= $pfx ?>_attach" name="attach">
        <?php if (!empty($r['attach_name'])): ?><div class="form-text">Obecny: <?= h($r['attach_name']) ?> (prześlij nowy, aby zastąpić)</div><?php endif; ?>
      </div>
      <?php if ($isEdit): ?>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="<?= $pfx ?>_act" <?= $r['is_active']?'checked':'' ?>>
        <label class="form-check-label" for="<?= $pfx ?>_act">Widoczne dla kursantów</label>
      </div>
      <?php endif; ?>
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" name="notify" id="<?= $pfx ?>_notify" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_notify">Powiadom kursantów (e-mail / SMS)</label>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><?= $isEdit?'Zapisz zmiany':'Dodaj zadanie' ?></button>
    </div>
  </form>
<?php };

$matFormHtml = function(?array $r, string $pfx) use ($cur_course, $all_sessions, $TYPES, $dtv) {
    $isEdit = (bool)$r; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="save_material">
    <input type="hidden" name="_tab" value="materialy">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="material_id" value="<?= (int)($r['id'] ?? 0) ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'collection' ?> me-2"></i><?= $isEdit?'Edytuj materiał':'Nowy materiał' ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_type">Typ <span class="text-danger">*</span></label>
        <select class="form-select" id="<?= $pfx ?>_type" name="type" required>
          <?php foreach ($TYPES as $slug=>$ti): ?>
          <option value="<?= h($slug) ?>" <?= ($r['type']??'zadanie')===$slug?'selected':'' ?>><?= h($ti['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_title">Tytuł <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="<?= $pfx ?>_title" name="title" required value="<?= h($r['title'] ?? '') ?>" placeholder="np. Dokumentacja HTML — MDN">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_desc">Opis</label>
        <textarea class="form-control" id="<?= $pfx ?>_desc" name="description" rows="3"><?= h($r['description'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_session">Powiązana lekcja <span class="text-body-secondary small">(opc.)</span></label>
        <select class="form-select" id="<?= $pfx ?>_session" name="session_id">
          <option value="">— bez powiązania —</option>
          <?php foreach ($all_sessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)($r['session_id']??0)===(int)$s['id']?'selected':'' ?>><?= h(date('d.m.Y', strtotime($s['lesson_date']))) ?><?= $s['topic']?' · '.h(mb_substr($s['topic'],0,30)):'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_url">Link (URL) <span class="text-body-secondary small">(opc.)</span></label>
        <input type="url" class="form-control" id="<?= $pfx ?>_url" name="url" value="<?= h($r['url'] ?? '') ?>" placeholder="https://…">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_attach">Plik <span class="text-body-secondary small">(opc., maks. 25 MB)</span></label>
        <input type="file" class="form-control" id="<?= $pfx ?>_attach" name="attach">
        <?php if (!empty($r['attach_name'])): ?><div class="form-text">Obecny: <?= h($r['attach_name']) ?> (prześlij nowy, aby zastąpić)</div><?php endif; ?>
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_open">Otwarcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_open" name="open_at" value="<?= $dtv($r['open_at'] ?? '') ?>">
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_close">Zamknięcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_close" name="close_at" value="<?= $dtv($r['close_at'] ?? '') ?>">
        </div>
      </div>
      <?php if ($isEdit): ?>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="<?= $pfx ?>_act" <?= $r['is_active']?'checked':'' ?>>
        <label class="form-check-label" for="<?= $pfx ?>_act">Widoczne dla kursantów</label>
      </div>
      <?php endif; ?>
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" name="notify" id="<?= $pfx ?>_notify" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_notify">Powiadom kursantów (e-mail / SMS)</label>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><?= $isEdit?'Zapisz zmiany':'Dodaj materiał' ?></button>
    </div>
  </form>
<?php };

$KP_TITLE  = 'Panel dydaktyka';
$KP_TOPBAR = [
    'brand'  => 'Panel dydaktyka',
    'icon'   => 'easel2',
    'user'   => $me['name'] ?? '',
    'logout' => rtrim(APP_URL, '/') . '/auth/logout.php',
];
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<style>
  .dyd-wrap { max-width:1100px; }
  .dyd-course-pills .nav-link { border:1px solid var(--bs-border-color); }
  .dyd-course-pills .nav-link.active { background:#2563eb; border-color:#2563eb; }
  .badge-soft { background:rgba(37,99,235,.12); color:#93c5fd; border:1px solid rgba(37,99,235,.35); }
</style>

<main id="main" class="container dyd-wrap py-4">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-easel2 text-primary me-2" aria-hidden="true"></i>Panel dydaktyka</h1>
    <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/index.php" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-grid me-1" aria-hidden="true"></i>Pełny moduł TI
    </a>
  </div>

  <?= flash_html() ?>

  <?php // Komunikat o zaplanowanej / trwającej nieobecności prowadzącego
  if ($my_leaves): $today = date('Y-m-d');
    foreach ($my_leaves as $lv):
      $current = $lv['date_from'] <= $today;
      $range   = date('d.m.Y', strtotime($lv['date_from']));
      if ($lv['date_to'] !== $lv['date_from']) $range .= ' – ' . date('d.m.Y', strtotime($lv['date_to']));
  ?>
  <div class="alert <?= $current ? 'alert-warning' : 'alert-info' ?> d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-airplane-fill fs-5 mt-1 flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong><?= $current ? 'Trwa Twoja nieobecność' : 'Masz zaplanowaną nieobecność' ?>
        (<?= h(ti_leave_type_label($lv['type'])) ?>)</strong> — termin <strong><?= h($range) ?></strong>.
      <?php if (!empty($lv['note'])): ?><div class="small mt-1"><?= h($lv['note']) ?></div><?php endif; ?>
      <div class="small mt-1">W tym czasie zaplanuj odwołanie lub przełożenie lekcji.
        <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/urlopy.php">Nieobecności prowadzących</a>.</div>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php if (!$courses): ?>
    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-body-secondary">
      <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
      Nie prowadzisz obecnie żadnego kursu. Skontaktuj się z administratorem, aby przypisać Cię jako prowadzącego.
    </div></div>
  <?php else: ?>

  <!-- Wybór kursu -->
  <?php if (count($courses) > 1): ?>
  <nav class="dyd-course-pills mb-3" aria-label="Wybór kursu">
    <ul class="nav nav-pills gap-2 flex-wrap">
      <?php foreach ($courses as $c): ?>
      <li class="nav-item">
        <a class="nav-link <?= (int)$c['id']===$cur_course ? 'active' : '' ?>"
           href="index.php?course=<?= (int)$c['id'] ?>&tab=<?= h($tab) ?>">
          <i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($c['name']) ?>
          <span class="badge badge-soft ms-1"><?= (int)$c['enrolled_count'] ?> os.</span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>

  <?php if ($course): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-body py-3 d-flex flex-wrap align-items-center gap-2">
    <div>
      <span class="fw-semibold"><i class="bi bi-pc-display text-primary me-1" aria-hidden="true"></i><?= h($course['name']) ?></span>
      <?php if (!empty($course['location'])): ?><span class="text-body-secondary small ms-2"><i class="bi bi-geo-alt me-1"></i><?= h($course['location']) ?></span><?php endif; ?>
    </div>
    <span class="text-body-secondary small ms-auto"><?= (int)($course['enrolled_count'] ?? 0) ?> aktywnych kursantów</span>
  </div></div>

  <!-- Zakładki -->
  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php
      $tabs = ['lekcje'=>['Lekcje','calendar-week',count($sessions)],
               'zadania'=>['Zadania','journal-check',count($homeworks)],
               'materialy'=>['Materiały','collection-play',count($materials)]];
      foreach ($tabs as $k=>$ti): ?>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= $tab===$k?'active':'' ?>" href="index.php?course=<?= $cur_course ?>&tab=<?= $k ?>">
        <i class="bi bi-<?= $ti[1] ?> me-1" aria-hidden="true"></i><?= $ti[0] ?>
        <span class="badge bg-secondary ms-1"><?= $ti[2] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="dyd-tabpane">

    <?php /* ═══════════════════════ LEKCJE ═══════════════════════ */ ?>
    <?php if ($tab === 'lekcje'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-calendar-week me-2"></i>Lekcje</span>
        <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addL">
          <i class="bi bi-plus-lg me-1"></i>Dodaj lekcję
        </button>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$sessions): ?><div class="list-group-item text-body-secondary py-3">Brak lekcji. Kliknij „Dodaj lekcję", aby utworzyć pierwszą.</div><?php endif; ?>
        <?php foreach ($sessions as $s): $st = $STATUS[$s['status']] ?? $STATUS['planned']; ?>
        <div class="list-group-item">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold"><i class="bi bi-calendar-event me-1 text-primary"></i><?= date('d.m.Y', strtotime($s['lesson_date'])) ?></span>
            <?php if ($s['time_from']): ?><span class="text-body-secondary small"><i class="bi bi-clock me-1"></i><?= h($s['time_from']) ?><?= $s['time_to'] ? '–'.h($s['time_to']) : '' ?></span><?php endif; ?>
            <span class="badge ms-1" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>33"><?= h($st['label']) ?></span>
            <span class="text-body-secondary small ms-auto"><i class="bi bi-people me-1"></i><?= (int)$s['attended_count'] ?>/<?= (int)$s['total_count'] ?></span>
          </div>
          <?php if (!empty($s['topic'])): ?><div class="mt-1"><?= h($s['topic']) ?></div><?php endif; ?>
          <div class="mt-2 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edL<?= (int)$s['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
            <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/lesson.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-list-check me-1"></i>Obecność / szczegóły</a>
            <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć lekcję wraz z obecnością?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="delete_lesson">
              <input type="hidden" name="_tab" value="lekcje">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń lekcję"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Wyskakujące okienka: dodawanie + edycja lekcji -->
    <div class="modal fade" id="addL" tabindex="-1" aria-labelledby="addL_t" aria-hidden="true">
      <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $lessonFormHtml(null, 'addL'); ?></div></div>
    </div>
    <?php foreach ($sessions as $s): ?>
    <div class="modal fade" id="edL<?= (int)$s['id'] ?>" tabindex="-1" aria-labelledby="edL<?= (int)$s['id'] ?>_t" aria-hidden="true">
      <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $lessonFormHtml($s, 'edL'.(int)$s['id']); ?></div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ ZADANIA ═══════════════════════ */ ?>
    <?php if ($tab === 'zadania'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-journal-check me-2"></i>Zadania domowe</span>
        <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addH">
          <i class="bi bi-plus-lg me-1"></i>Dodaj zadanie
        </button>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$homeworks): ?><div class="list-group-item text-body-secondary py-3">Brak zadań. Kliknij „Dodaj zadanie", aby utworzyć pierwsze.</div><?php endif; ?>
        <?php foreach ($homeworks as $hw): $av = k30_ti_avail_status($hw['open_at']??null, $hw['close_at']??null); ?>
        <div class="list-group-item <?= $hw['is_active']?'':'opacity-50' ?>">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold"><?= h($hw['title']) ?></span>
            <?php if (!$hw['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
            <?php if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-clock me-1"></i><?= h($av['label']) ?></span>
            <?php elseif ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-lock me-1"></i><?= h($av['label']) ?></span><?php endif; ?>
            <span class="text-body-secondary small ms-auto"><i class="bi bi-inbox me-1"></i><?= (int)$hw['sub_count'] ?> oddań · <?= (int)$hw['graded_count'] ?> ocen.</span>
          </div>
          <?php if ($hw['due_at']): ?><div class="text-body-secondary small mt-1"><i class="bi bi-calendar-check me-1"></i>termin: <?= h(substr($hw['due_at'],0,16)) ?></div><?php endif; ?>
          <?php if ($hw['description']): ?><div class="small mt-1"><?= nl2br(h(mb_substr($hw['description'],0,160))) ?></div><?php endif; ?>
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <?php if ($hw['attach_path']): ?><a href="?dl=hw&id=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-paperclip me-1"></i>załącznik</a><?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edH<?= (int)$hw['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
            <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/homework.php?id=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-check2-square me-1"></i>Oddania / oceny</a>
            <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć zadanie wraz z oddaniami?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="delete_homework">
              <input type="hidden" name="_tab" value="zadania">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="homework_id" value="<?= (int)$hw['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń zadanie"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Wyskakujące okienka: dodawanie + edycja zadań -->
    <div class="modal fade" id="addH" tabindex="-1" aria-labelledby="addH_t" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $hwFormHtml(null, 'addH'); ?></div></div>
    </div>
    <?php foreach ($homeworks as $hw): ?>
    <div class="modal fade" id="edH<?= (int)$hw['id'] ?>" tabindex="-1" aria-labelledby="edH<?= (int)$hw['id'] ?>_t" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $hwFormHtml($hw, 'edH'.(int)$hw['id']); ?></div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ MATERIAŁY ═══════════════════════ */ ?>
    <?php if ($tab === 'materialy'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-collection-play me-2"></i>Materiały / eLearning</span>
        <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addM">
          <i class="bi bi-plus-lg me-1"></i>Dodaj materiał
        </button>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$materials): ?><div class="list-group-item text-body-secondary py-3">Brak materiałów. Kliknij „Dodaj materiał", aby utworzyć pierwszy.</div><?php endif; ?>
        <?php foreach ($materials as $m): $av = k30_ti_avail_status($m['open_at']??null, $m['close_at']??null); ?>
        <div class="list-group-item <?= $m['is_active']?'':'opacity-50' ?>">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge badge-soft"><i class="bi bi-<?= h(k30_ti_material_type_icon($m['type'])) ?> me-1"></i><?= h(k30_ti_material_type_label($m['type'])) ?></span>
            <span class="fw-semibold"><?= h($m['title']) ?></span>
            <?php if (!$m['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
            <?php if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-clock me-1"></i><?= h($av['label']) ?></span>
            <?php elseif ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-lock me-1"></i><?= h($av['label']) ?></span><?php endif; ?>
          </div>
          <?php if ($m['session_date']): ?><div class="text-body-secondary small mt-1"><i class="bi bi-calendar-event me-1"></i>lekcja <?= h(date('d.m.Y', strtotime($m['session_date']))) ?></div><?php endif; ?>
          <?php if ($m['description']): ?><div class="small mt-1"><?= nl2br(h(mb_substr($m['description'],0,160))) ?></div><?php endif; ?>
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <?php if ($m['attach_path']): ?><a href="?dl=mat&id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-download me-1"></i><?= h(mb_substr($m['attach_name'],0,20)) ?></a><?php endif; ?>
            <?php if ($m['url']): ?><a href="<?= h($m['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-box-arrow-up-right me-1"></i>link</a><?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edM<?= (int)$m['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
            <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć materiał?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="delete_material">
              <input type="hidden" name="_tab" value="materialy">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń materiał"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Wyskakujące okienka: dodawanie + edycja materiałów -->
    <div class="modal fade" id="addM" tabindex="-1" aria-labelledby="addM_t" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $matFormHtml(null, 'addM'); ?></div></div>
    </div>
    <?php foreach ($materials as $m): ?>
    <div class="modal fade" id="edM<?= (int)$m['id'] ?>" tabindex="-1" aria-labelledby="edM<?= (int)$m['id'] ?>_t" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $matFormHtml($m, 'edM'.(int)$m['id']); ?></div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div>
  <?php endif; /* $course */ ?>
  <?php endif; /* $courses */ ?>

</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
