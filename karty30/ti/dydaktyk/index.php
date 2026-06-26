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
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$courses   = dyd_courses($uid);
$my_leaves = ti_leaves_for_instructor($uid);   // własne urlopy: trwające + nadchodzące
$my_avail  = ti_instructor_availability($uid);  // własne okna dostępności w tygodniu

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
if (!in_array($tab, ['lekcje', 'zadania', 'materialy', 'dostepnosc', 'testy', 'wiadomosci', 'formalnosci'], true)) $tab = 'lekcje';

// ── Umowy powiązane z kontem dydaktyka ───────────────────────────────────────
$dyd_contracts = [];
$dyd_user_row  = db_one("SELECT email, microsoft_id FROM users WHERE id=?", [$uid]);
$dyd_email     = trim((string)($dyd_user_row['email'] ?? ''));
$dyd_ms_id     = trim((string)($dyd_user_row['microsoft_id'] ?? ''));
foreach ([
    ['zlecenie',    'data_zakonczenia'],
    ['wolontariat', 'data_zakonczenia'],
    ['dzielo',      'termin_oddania'],
    ['praca',       'data_zakonczenia'],
] as [$ctype, $end_col]) {
    $table = "umowy_{$ctype}";
    try {
        $conds  = [];
        $params = [];
        if ($dyd_email) { $conds[] = 'email=?'; $params[] = $dyd_email; }
        if ($dyd_ms_id) { $conds[] = 'm365_user_id=?'; $params[] = $dyd_ms_id; }
        if (!$conds) continue;
        $rows = db_all(
            "SELECT id, '{$ctype}' AS contract_type, numer_umowy, status, data_zawarcia,
                    {$end_col} AS data_zakonczenia, imie_nazwisko,
                    stanowisko, wynagrodzenie_brutto, miejsce_wolontariatu, przedmiot_porozumienia
             FROM {$table} WHERE (" . implode(' OR ', $conds) . ") ORDER BY data_zawarcia DESC",
            $params
        );
        foreach ($rows as $r) $dyd_contracts[] = $r;
    } catch (\Throwable $e) {}
}
// sortuj: aktywne na górze
usort($dyd_contracts, function($a, $b) {
    $active = fn($s) => in_array($s, ['podpisana','w realizacji'], true) ? 0 : 1;
    return $active($a['status']) <=> $active($b['status']) ?: strcmp((string)($b['data_zawarcia'] ?? ''), (string)($a['data_zawarcia'] ?? ''));
});

/** Adres powrotu zachowujący kurs i zakładkę. */
function dyd_back(int $course, string $tab): string {
    return 'index.php?course=' . $course . '&tab=' . $tab;
}
$dt_in = fn($k) => ($v = trim($_POST[$k] ?? '')) !== '' ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;

// ── Operacje zapisu ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op        = $_POST['_op'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);
    $back_tab  = in_array($_POST['_tab'] ?? '', ['lekcje','zadania','materialy'], true) ? $_POST['_tab'] : 'lekcje';

    // ── Dostępność prowadzącego (własna, niezależna od kursu) ───────────────────
    if ($op === 'avail_add') {
        $dw = (int)($_POST['day_of_week'] ?? -1);
        if (!ti_avail_add($uid, $dw, $_POST['time_from'] ?? '', $_POST['time_to'] ?? '')) {
            flash_set('danger', 'Podaj poprawny dzień oraz godziny od–do (od < do).');
        } else {
            flash_set('success', 'Dodano okno dostępności.');
        }
        header('Location: index.php?tab=dostepnosc'); exit;
    }
    if ($op === 'avail_delete') {
        ti_avail_delete((int)($_POST['avail_id'] ?? 0), $uid);
        flash_set('success', 'Usunięto okno dostępności.');
        header('Location: index.php?tab=dostepnosc'); exit;
    }

    // Pozostałe operacje wymagają własności kursu.
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

        // Zajęcia tylko w dostępności prowadzącego (gdy zdefiniowana)
        $av = ti_instructor_available_at(ti_course_instructor_id($course_id), $date, $tf, $tt);
        if (!$av['ok']) { flash_set('danger', $av['reason']); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }

        if ($sid && dyd_owns_session($uid, $sid)) {
            $st = in_array($_POST['status'] ?? '', ['planned','held'], true) ? $_POST['status'] : 'planned';
            db()->prepare(
                "UPDATE k30_ti_sessions
                 SET lesson_date=?, time_from=?, time_to=?, duration_min=?, topic=?, notes=?, status=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$date, $tf, $tt, $dur, $topic, $notes, $st, $sid]);
            k30_ti_session_set_curriculum($sid, (array)($_POST['curriculum_ids'] ?? []));
            flash_set('success', 'Lekcja zaktualizowana.');
        } else {
            $sid = db_insert('k30_ti_sessions', [
                'course_id'   => $course_id, 'lesson_date' => $date,
                'time_from'   => $tf, 'time_to' => $tt, 'duration_min' => $dur,
                'status'      => 'planned', 'topic' => $topic, 'notes' => $notes,
                'created_by'  => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            k30_ti_session_set_curriculum($sid, (array)($_POST['curriculum_ids'] ?? []));
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

    // Seria lekcji — powtarzalne co N tygodni
    if ($op === 'save_lesson_series') {
        $date  = trim($_POST['lesson_date'] ?? '');
        $tf    = trim($_POST['time_from'] ?? '');
        $tt    = trim($_POST['time_to'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $every = max(1, (int)($_POST['weeks'] ?? 1));
        $count = max(1, min(52, (int)($_POST['count'] ?? 1)));
        if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
            flash_set('danger', 'Podaj poprawną datę startową serii.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $dur = 60;
        if ($tf && $tt) { $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60; if ($m > 0) $dur = (int)$m; }
        // Cała seria ma ten sam dzień tygodnia i godziny — sprawdzamy raz
        $av = ti_instructor_available_at(ti_course_instructor_id($course_id), $date, $tf, $tt);
        if (!$av['ok']) { flash_set('danger', $av['reason'] . ' Seria nie została utworzona.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]);
        $created = 0;
        for ($i = 0; $i < $count; $i++) {
            $d = date('Y-m-d', strtotime($date . ' +' . ($i * $every) . ' weeks'));
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $course_id, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => 'planned', 'topic' => $topic, 'notes' => '',
                'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $created++;
        }
        flash_set('success', "Utworzono serię: {$created} lekcji (co {$every} tyg.).");
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    if ($op === 'save_attendance') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if (dyd_owns_session($uid, $sid)) {
            $att = array_map('intval', (array)($_POST['attended'] ?? []));
            k30_ti_save_attendance($sid, $att);
            // Sprawdzenie obecności oznacza, że lekcja się odbyła (gdy była zaplanowana).
            db()->prepare("UPDATE k30_ti_sessions SET status='held', updated_at=datetime('now') WHERE id=? AND status='planned'")->execute([$sid]);
            flash_set('success', 'Obecność zapisana.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Potwierdzenie / odrzucenie prośby kursanta o odwołanie udziału w lekcji
    if ($op === 'confirm_cancel' || $op === 'reject_cancel') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($sid && $cid && dyd_owns_session($uid, $sid)) {
            if ($op === 'confirm_cancel') {
                k30_ti_confirm_cancel_attendance($sid, $cid);
                k30_ti_notify_student_cancel_decision($sid, $cid, true);
                flash_set('success', 'Odwołanie potwierdzone — udział nie będzie liczony do ceny. Kursant został powiadomiony.');
            } else {
                k30_ti_uncancel_attendance($sid, $cid);
                k30_ti_notify_student_cancel_decision($sid, $cid, false);
                flash_set('success', 'Prośba o odwołanie odrzucona — udział przywrócony. Kursant został powiadomiony.');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Bezpośrednie odwołanie / przywrócenie udziału kursanta (prowadzący / admin)
    if ($op === 'cancel_attendee' || $op === 'restore_attendee') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($sid && $cid && dyd_owns_session($uid, $sid)) {
            if ($op === 'cancel_attendee') {
                $reason = trim($_POST['reason'] ?? '');
                $role   = (($me['role'] ?? '') === 'admin') ? 'admin' : 'doradca';
                k30_ti_cancel_attendance($sid, $cid, $reason !== '' ? $reason : 'Odwołane przez prowadzącego', $role, (string)($me['name'] ?? ''));
                flash_set('success', 'Udział kursanta odwołany — nie będzie liczony do ceny.');
            } else {
                k30_ti_uncancel_attendance($sid, $cid);
                flash_set('success', 'Udział kursanta przywrócony.');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Odwołanie / przywrócenie całej lekcji (prowadzący / admin)
    if ($op === 'cancel_session' || $op === 'uncancel_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid && dyd_owns_session($uid, $sid)) {
            if ($op === 'cancel_session') {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') { flash_set('danger', 'Podaj powód odwołania lekcji.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
                $role = (($me['role'] ?? '') === 'admin') ? 'admin' : 'doradca';
                k30_ti_cancel_session($sid, $reason, $role, (string)($me['name'] ?? ''));
                flash_set('success', 'Lekcja odwołana — nie zostanie policzona do ceny.');
            } else {
                db()->prepare(
                    "UPDATE k30_ti_sessions SET status='planned', cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL, updated_at=datetime('now') WHERE id=?"
                )->execute([$sid]);
                flash_set('success', 'Lekcja przywrócona (zaplanowana).');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // ── ZADANIA DOMOWE ──────────────────────────────────────────────────────────
    if ($op === 'save_homework') {
        $hid       = (int)($_POST['homework_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $hint      = trim($_POST['hint'] ?? '');
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
                    'hint'=>$hint, 'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
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
                'hint'=>$hint, 'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
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

    // ── WIADOMOŚCI ────────────────────────────────────────────────────────────
    if ($op === 'dyd_msg_send') {
        $acc_id  = (int)($_POST['account_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        // Sprawdź, czy kursant jest zapisany do kursu prowadzącego
        $myAccId = $acc_id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$acc_id]) : null;
        if (!$myAccId || $body === '') {
            flash_set('danger', $body === '' ? 'Treść wiadomości jest wymagana.' : 'Nie możesz pisać do tego kursanta.');
            header('Location: index.php?tab=wiadomosci'); exit;
        }
        $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
        ti_msg_post_to_student($acc_id, $subject, $body, $uid, $senderName, false);
        flash_set('success', 'Wiadomość wysłana.');
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_reply') {
        $acc_id = (int)($_POST['student_id'] ?? 0);
        $body   = trim($_POST['body'] ?? '');
        $myAccId = $acc_id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$acc_id]) : null;
        if ($myAccId && $body !== '') {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            ti_msg_post_to_student($acc_id, '', $body, $uid, $senderName, true);
            ti_account_log($acc_id, 'msg_sent_by_staff', mb_substr($body, 0, 100), $uid, $senderName);
            flash_set('success', 'Odpowiedź wysłana.');
        }
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_block' || $op === 'dyd_msg_unblock') {
        $acc_id  = (int)($_POST['student_id'] ?? 0);
        $myAccId = $acc_id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$acc_id]) : null;
        if ($myAccId) {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            ti_msg_set_blocked($acc_id, $op === 'dyd_msg_block', $uid, $senderName);
            flash_set('success', $op === 'dyd_msg_block' ? 'Wiadomości od kursanta zablokowane.' : 'Blokada zdjęta.');
        }
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_archive') {
        $msg_id = (int)($_POST['msg_id'] ?? 0);
        // Weryfikuj że wiadomość należy do kursanta z kursu tego prowadzącego
        $msgRow = $msg_id ? db_one(
            "SELECT m.id, m.student_id FROM k30_ti_messages m
             JOIN k30_ti_student_accounts a ON a.id=m.student_id
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE m.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$msg_id]) : null;
        $acc_id = (int)($_POST['student_id'] ?? 0);
        if ($msgRow) {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            ti_msg_archive((int)$msgRow['id'], $uid, $senderName);
            flash_set('success', 'Wiadomość zarchiwizowana.');
        }
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
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
    usort($sessions, fn($a, $b) => strcmp((string)$b['lesson_date'], (string)$a['lesson_date'])
        ?: strcmp((string)($b['time_from'] ?? ''), (string)($a['time_from'] ?? ''))); // najnowsze na górze
    $homeworks    = k30_ti_homework_list($cur_course);
    $materials    = k30_ti_materials_list($cur_course);
    $all_sessions = db_all("SELECT id, lesson_date, topic FROM k30_ti_sessions WHERE course_id=? ORDER BY lesson_date DESC, id DESC", [$cur_course]);
}

// Liczba oczekujących próśb o odwołanie udziału w kursie (do licznika)
$pending_cancel_total = $cur_course ? (int)(db_one(
    "SELECT COUNT(*) n FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
     WHERE s.course_id=? AND a.cancel_pending=1", [$cur_course])['n'] ?? 0) : 0;

$TYPES = k30_ti_material_types();
$STATUS = K30_TI_SESSION_STATUSES;

// ── Dane dla zakładki Wiadomości ─────────────────────────────────────────────
$dyd_msg_student_id = (int)($_GET['student'] ?? 0);
// Wątki: tylko kursanci z kursów tego prowadzącego
$dyd_msg_threads = $course_ids ? db_all(
    "SELECT a.id, COALESCE(cl.name, a.login) AS name, a.login,
            MAX(m.created_at) AS last_at,
            SUM(CASE WHEN m.sender='student' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
     FROM k30_ti_messages m
     JOIN k30_ti_student_accounts a ON a.id=m.student_id
     LEFT JOIN k30_clients cl ON cl.id=a.client_id
     WHERE a.id IN (
         SELECT DISTINCT sa.id FROM k30_ti_student_accounts sa
         JOIN k30_ti_enrollments e ON e.client_id=sa.client_id
         WHERE e.course_id IN (" . implode(',', array_map('intval', $course_ids)) . ") AND e.status='active'
     )
     GROUP BY a.id ORDER BY last_at DESC"
) : [];
$dyd_msg_unread_total = array_sum(array_column($dyd_msg_threads, 'unread'));
// Kursanci tego prowadzącego (do selecta nowej wiadomości)
$dyd_msg_accounts = $course_ids ? db_all(
    "SELECT DISTINCT a.id, COALESCE(cl.name, a.login) AS name, a.login, c.name AS course_name
     FROM k30_ti_student_accounts a
     JOIN k30_ti_enrollments e ON e.client_id=a.client_id
     JOIN k30_ti_courses c ON c.id=e.course_id
     LEFT JOIN k30_clients cl ON cl.id=a.client_id
     WHERE e.course_id IN (" . implode(',', array_map('intval', $course_ids)) . ") AND e.status='active' AND a.is_active=1
     ORDER BY name"
) : [];
$dyd_msg_student = $dyd_msg_student_id
    ? db_one("SELECT a.*, COALESCE(cl.name, a.login) AS client_name
              FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id
              WHERE a.id=?", [$dyd_msg_student_id])
    : null;
if ($dyd_msg_student) ti_msg_mark_read_for_staff((int)$dyd_msg_student['id']);
$dyd_msg_thread      = $dyd_msg_student ? ti_msg_list_for_student((int)$dyd_msg_student['id']) : [];
$dyd_msg_is_blocked  = $dyd_msg_student ? ti_msg_is_blocked((int)$dyd_msg_student['id']) : false;
$dyd_msg_log         = $dyd_msg_student ? ti_account_log_list((int)$dyd_msg_student['id'], 50) : [];

// Lekcje do wyszukiwarki „Powiązana lekcja" (etykiety unikalne — do mapowania w JS)
$session_opts = []; $session_label_by_id = []; $_lbl_seen = [];
foreach ($all_sessions as $s) {
    $lbl = date('d.m.Y', strtotime($s['lesson_date'])) . ($s['topic'] !== '' && $s['topic'] !== null ? ' · ' . mb_substr($s['topic'], 0, 40) : '');
    if (isset($_lbl_seen[$lbl])) { $_lbl_seen[$lbl]++; $lbl .= ' (' . $_lbl_seen[$lbl] . ')'; } else { $_lbl_seen[$lbl] = 1; }
    $session_opts[] = ['id' => (int)$s['id'], 'label' => $lbl];
    $session_label_by_id[(int)$s['id']] = $lbl;
}

/** Wyszukiwarka lekcji (pole tekstowe + lista) zwracająca session_id w ukrytym polu. */
$sessionPicker = function (string $pfx, int $selId) use ($session_label_by_id) { ?>
  <input type="text" class="form-control dyd-lesson-combo" id="<?= $pfx ?>_session_txt"
         list="dyd-session-list" data-target="<?= $pfx ?>_session" autocomplete="off"
         value="<?= h($session_label_by_id[$selId] ?? '') ?>"
         placeholder="Wpisz datę lub temat i wybierz z listy…" aria-describedby="<?= $pfx ?>_session_help">
  <input type="hidden" name="session_id" id="<?= $pfx ?>_session" value="<?= $selId ?: '' ?>">
  <div class="form-text" id="<?= $pfx ?>_session_help">Zacznij pisać, aby wyszukać lekcję. Puste pole = bez powiązania.</div>
<?php };

// ── Formularze renderowane w wyskakujących okienkach (dodawanie + edycja) ─────
// $r = wiersz do edycji lub null (dodawanie). $pfx = unikalny prefiks id pól/modalu.

$lessonFormHtml = function(?array $r, string $pfx) use ($cur_course) {
    $isEdit = (bool)$r; ?>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
      <?php
        // Realizowane punkty planu nauczania — progressive disclosure (rozwijane),
        // natywny multi-select dla pełnej obsługi klawiaturą i czytnikiem ekranu.
        $_curr = k30_ti_curriculum_list($cur_course, true);
        $_sel  = $isEdit ? k30_ti_session_curriculum_ids((int)$r['id']) : [];
      ?>
      <div class="mb-2">
        <?php if ($_curr): ?>
        <details<?= $_sel ? ' open' : '' ?>>
          <summary class="form-label fw-semibold mb-1" style="cursor:pointer">
            Realizowane punkty planu <span class="badge bg-secondary"><?= count($_sel) ?></span>
          </summary>
          <div class="form-text mb-1" id="<?= $pfx ?>_curr_hint">Zaznacz punkty planu realizowane na tej lekcji. Klawiatura: strzałki + spacja (wielokrotny wybór).</div>
          <?php $_bySec = []; foreach ($_curr as $ci) { $_bySec[(string)$ci['section']][] = $ci; } ?>
          <select class="form-select" name="curriculum_ids[]" id="<?= $pfx ?>_curr" multiple
                  size="<?= min(10, max(4, count($_curr))) ?>"
                  aria-describedby="<?= $pfx ?>_curr_hint" aria-label="Realizowane punkty planu nauczania">
            <?php foreach ($_bySec as $sec=>$list): ?>
            <optgroup label="<?= h($sec !== '' ? $sec : 'Bez działu') ?>">
              <?php foreach ($list as $ci): ?>
              <option value="<?= (int)$ci['id'] ?>" <?= in_array((int)$ci['id'],$_sel,true)?'selected':'' ?>><?= h($ci['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </details>
        <?php else: ?>
        <div class="form-text"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak zdefiniowanego planu nauczania dla tego kursu — punkty planu doda administrator.</div>
        <?php endif; ?>
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

// Sprawdzanie obecności na lekcji — lista zapisanych kursantów z polami wyboru.
$attFormHtml = function(array $s, array $rows, string $pfx) use ($cur_course) {
    $present = 0; foreach ($rows as $r) { if ((int)$r['attended'] === 1) $present++; } ?>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="save_attendance">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-people me-2"></i>Obecność — <?= date('d.m.Y', strtotime($s['lesson_date'])) ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <?php if (!$rows): ?>
      <p class="text-body-secondary mb-0">Brak zapisanych kursantów w tym kursie.</p>
      <?php else: ?>
      <div class="d-flex align-items-center mb-2">
        <span class="text-body-secondary small">Zaznacz obecnych (<?= $present ?>/<?= count($rows) ?>).</span>
        <button type="button" class="btn btn-link btn-sm ms-auto p-0 att-toggle-all" data-target="<?= $pfx ?>">Zaznacz / odznacz wszystkich</button>
      </div>
      <div class="list-group">
        <?php foreach ($rows as $r): $cid = (int)$r['client_id']; $canc = (int)($r['cancelled'] ?? 0) === 1; $pend = (int)($r['cancel_pending'] ?? 0) === 1; ?>
        <div class="list-group-item d-flex align-items-center gap-2 <?= $canc?'opacity-75':'' ?>">
          <label class="d-flex align-items-center gap-2 flex-grow-1 mb-0">
            <input class="form-check-input mt-0" type="checkbox" name="attended[]" value="<?= $cid ?>"
                   <?= (int)$r['attended']===1?'checked':'' ?> <?= $canc?'disabled':'' ?>>
            <span><?= h($r['client_name']) ?></span>
          </label>
          <?php if ($canc): ?>
          <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-x-circle me-1"></i>odwołany</span>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Przywróć udział" onclick="dydRestoreAtt(<?= (int)$s['id'] ?>,<?= $cid ?>)"><i class="bi bi-arrow-counterclockwise"></i></button>
          <?php else: ?>
          <?php if ($pend): ?><span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1"></i>czeka</span><?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Odwołaj udział (nie liczone do ceny)" onclick="dydCancelAtt(<?= (int)$s['id'] ?>,<?= $cid ?>)"><i class="bi bi-x-circle"></i></button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="text-body-secondary small mt-2 mb-0">Zapis oznaczy zaplanowaną lekcję jako odbytą. Osób z odwołanym udziałem nie liczy się do obecności.</p>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <?php if ($rows): ?><button type="submit" class="btn btn-primary"><i class="bi bi-check2-square me-1"></i>Zapisz obecność</button><?php endif; ?>
    </div>
  </form>
<?php };

$hwFormHtml = function(?array $r, string $pfx) use ($cur_course, $dtv, $sessionPicker) {
    $isEdit = (bool)$r; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
        <label class="form-label" for="<?= $pfx ?>_hint"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Podpowiedź <span class="text-body-secondary small">(opc.)</span></label>
        <textarea class="form-control" id="<?= $pfx ?>_hint" name="hint" rows="2" placeholder="Wskazówka dla kursanta"><?= h($r['hint'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_session_txt">Powiązana lekcja <span class="text-body-secondary small">(opc.)</span></label>
        <?php $sessionPicker($pfx, (int)($r['session_id'] ?? 0)); ?>
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

$matFormHtml = function(?array $r, string $pfx) use ($cur_course, $TYPES, $dtv, $sessionPicker) {
    $isEdit = (bool)$r; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
        <label class="form-label" for="<?= $pfx ?>_session_txt">Powiązana lekcja <span class="text-body-secondary small">(opc.)</span></label>
        <?php $sessionPicker($pfx, (int)($r['session_id'] ?? 0)); ?>
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
    'logout' => 'logout.php',
];
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<style>
  .dyd-wrap { max-width:1100px; }
  .dyd-course-pills .nav-link { border:1px solid var(--bs-border-color); }
  .dyd-course-pills .nav-link.active { background:#2563eb; border-color:#2563eb; }
  .badge-soft { background:rgba(37,99,235,.12); color:#93c5fd; border:1px solid rgba(37,99,235,.35); }
  .dyd-globalbar { background:var(--bs-body-bg); border-bottom:2px solid var(--bs-border-color); padding:.3rem 1rem; display:flex; align-items:center; gap:.25rem; flex-wrap:wrap; }
  .dyd-globalbar .dyd-gb-link { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .85rem; border-radius:6px; font-size:.88rem; font-weight:600; color:var(--bs-body-color); text-decoration:none; border:1.5px solid transparent; transition:background .12s,color .12s; min-height:40px; }
  .dyd-globalbar .dyd-gb-link:hover { background:var(--bs-tertiary-bg); border-color:var(--bs-border-color); }
  .dyd-globalbar .dyd-gb-link.active { background:#dbeafe; color:#1d4ed8; border-color:#93c5fd; font-weight:700; }
</style>

<!-- ── Globalny pasek nawigacyjny dydaktyka ── -->
<nav class="dyd-globalbar" aria-label="Menu dydaktyka">
  <a class="dyd-gb-link <?= $tab==='formalnosci'?'active':'' ?>" href="index.php?tab=formalnosci"
     <?= $tab==='formalnosci'?'aria-current="page"':'' ?>>
    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Formalności
    <?php $active_cnt = count(array_filter($dyd_contracts, fn($c) => in_array($c['status'],['podpisana','w realizacji'],true))); ?>
    <?php if ($active_cnt): ?>
    <span class="badge bg-success" style="font-size:.65rem"><?= $active_cnt ?></span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link <?= $tab==='dostepnosc'?'active':'' ?>" href="index.php?tab=dostepnosc"
     <?= $tab==='dostepnosc'?'aria-current="page"':'' ?>>
    <i class="bi bi-clock-history" aria-hidden="true"></i>Dostępność
    <?php if (isset($my_avail) && count($my_avail) > 0): ?>
    <span class="badge bg-secondary" style="font-size:.65rem"><?= count($my_avail) ?></span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link <?= $tab==='wiadomosci'?'active':'' ?>" href="index.php?tab=wiadomosci"
     <?= $tab==='wiadomosci'?'aria-current="page"':'' ?>>
    <i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości
    <?php if (!empty($dyd_msg_unread_total)): ?>
    <span class="badge bg-danger" style="font-size:.65rem"><?= (int)$dyd_msg_unread_total ?></span>
    <?php endif; ?>
  </a>
</nav>

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

  <!-- Wybór kursu — tylko gdy aktywna zakładka dotyczy kursu -->
  <?php if (count($courses) > 1 && in_array($tab, ['lekcje','zadania','materialy','testy'], true)): ?>
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

  <!-- Zakładki kursu — widoczne tylko gdy aktywna zakładka należy do obszaru kursu -->
  <?php if (in_array($tab, ['lekcje','zadania','materialy','testy'], true)): ?>
  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php
      $tabs = ['lekcje'=>['Lekcje','calendar-week',count($sessions)],
               'zadania'=>['Zadania','journal-check',count($homeworks)],
               'materialy'=>['Materiały','collection-play',count($materials)],
               'testy'=>['Testy','card-checklist', count(k30_ti_tests_list($cur_course))]];
      foreach ($tabs as $k=>$ti): ?>
    <li class="nav-item" role="presentation">
      <a class="nav-link <?= $tab===$k?'active':'' ?>" href="index.php?course=<?= $cur_course ?>&tab=<?= $k ?>">
        <i class="bi bi-<?= $ti[1] ?> me-1" aria-hidden="true"></i><?= $ti[0] ?>
        <span class="badge bg-secondary ms-1"><?= $ti[2] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="dyd-tabpane">

    <?php /* ═══════════════════════ LEKCJE ═══════════════════════ */ ?>
    <?php if ($tab === 'lekcje'): ?>
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
          <?php if ($all_sessions): ?>
          <a href="attendance_pdf.php?course_id=<?= $cur_course ?>" class="btn btn-outline-secondary btn-sm" title="Eksportuj listę obecności do PDF">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
          </a>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#dydCalModal">
            <i class="bi bi-calendar3 me-1"></i>Kalendarz
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSeries">
            <i class="bi bi-calendar-plus me-1"></i>Seria
          </button>
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
      <div class="list-group list-group-flush" id="dyd-list-lekcje">
        <?php if (!$sessions): ?><div class="list-group-item text-body-secondary py-3">Brak lekcji. Kliknij „Dodaj lekcję", aby utworzyć pierwszą.</div><?php endif; ?>
        <div class="list-group-item dyd-filter-empty text-body-secondary py-3" style="display:none">Brak lekcji pasujących do wyszukiwania.</div>
        <?php foreach ($sessions as $s): $st = $STATUS[$s['status']] ?? $STATUS['planned']; ?>
        <div class="list-group-item" data-filter-item="1">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold"><i class="bi bi-calendar-event me-1 text-primary"></i><?= date('d.m.Y', strtotime($s['lesson_date'])) ?></span>
            <?php if ($s['time_from']): ?><span class="text-body-secondary small"><i class="bi bi-clock me-1"></i><?= h($s['time_from']) ?><?= $s['time_to'] ? '–'.h($s['time_to']) : '' ?></span><?php endif; ?>
            <span class="badge ms-1" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>33"><?= h($st['label']) ?></span>
            <span class="text-body-secondary small ms-auto"><i class="bi bi-people me-1"></i><?= (int)$s['attended_count'] ?>/<?= (int)$s['total_count'] ?></span>
          </div>
          <?php if (!empty($s['topic'])): ?><div class="mt-1"><?= h($s['topic']) ?></div><?php endif; ?>
          <?php
            $pending = db_all(
              "SELECT a.client_id, cl.name, a.cancel_reason
               FROM k30_ti_attendance a JOIN k30_clients cl ON cl.id=a.client_id
               WHERE a.session_id=? AND a.cancel_pending=1 ORDER BY cl.name", [(int)$s['id']]);
            if ($pending): ?>
          <div class="alert alert-warning py-2 px-2 mt-2 mb-0 small">
            <div class="fw-semibold mb-1"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Prośby o odwołanie udziału — czekają na potwierdzenie</div>
            <?php foreach ($pending as $pr): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <span><?= h($pr['name']) ?><?php if ($pr['cancel_reason']): ?> <span class="text-body-secondary">— <?= h($pr['cancel_reason']) ?></span><?php endif; ?></span>
              <div class="ms-auto d-flex gap-1">
                <form method="post" class="d-inline" onsubmit="return confirm('Potwierdzić odwołanie udziału tego kursanta?')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="confirm_cancel">
                  <input type="hidden" name="_tab" value="lekcje">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="client_id" value="<?= (int)$pr['client_id'] ?>">
                  <button class="btn btn-sm btn-danger py-0 px-2"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Potwierdź odwołanie</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="reject_cancel">
                  <input type="hidden" name="_tab" value="lekcje">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="client_id" value="<?= (int)$pr['client_id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Odrzuć</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#attL<?= (int)$s['id'] ?>"><i class="bi bi-people me-1"></i>Obecność</button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edL<?= (int)$s['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
            <?php if (($s['status'] ?? '') === 'cancelled'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Przywrócić lekcję (status: zaplanowana)?')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="uncancel_session">
              <input type="hidden" name="_tab" value="lekcje">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć lekcję</button>
            </form>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                    onclick="dydOpenCancelSession(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode(date('d.m.Y', strtotime($s['lesson_date'])).($s['time_from']?' '.h($s['time_from']):'')), ENT_QUOTES) ?>)">
              <i class="bi bi-x-circle me-1"></i>Odwołaj lekcję
            </button>
            <?php endif; ?>
            <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/lesson.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-list-check me-1"></i>Szczegóły</a>
            <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć lekcję wraz z obecnością?')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
    <div class="modal fade" id="attL<?= (int)$s['id'] ?>" tabindex="-1" aria-labelledby="attL<?= (int)$s['id'] ?>_t" aria-hidden="true">
      <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $attFormHtml($s, k30_ti_session_attendance((int)$s['id']), 'attL'.(int)$s['id']); ?></div></div>
    </div>
    <?php endforeach; ?>

    <!-- Modal: seria lekcji (powtarzalne) -->
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

    <!-- Ukryty formularz akcji obecności (odwołaj/przywróć udział) -->
    <form method="post" id="dydAttAction" class="d-none">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op"         id="daa_op"  value="">
      <input type="hidden" name="_tab"        value="lekcje">
      <input type="hidden" name="course_id"   value="<?= $cur_course ?>">
      <input type="hidden" name="session_id"  id="daa_sid" value="">
      <input type="hidden" name="client_id"   id="daa_cid" value="">
    </form>

    <!-- Modal: odwołanie całej lekcji (z powodem) -->
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
    <?php endif; ?>

    <?php /* ═══════════════════════ ZADANIA ═══════════════════════ */ ?>
    <?php if ($tab === 'zadania'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-journal-check me-2"></i>Zadania domowe</span>
        <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addH">
          <i class="bi bi-plus-lg me-1"></i>Dodaj zadanie
        </button>
        <?php if ($homeworks): ?>
        <div class="w-100">
          <input type="search" class="form-control form-control-sm" placeholder="Szukaj zadań (tytuł, opis, termin…)"
                 aria-label="Filtruj zadania" data-dyd-filterbox="dyd-list-zadania">
        </div>
        <?php endif; ?>
      </div>
      <div class="list-group list-group-flush" id="dyd-list-zadania">
        <?php if (!$homeworks): ?><div class="list-group-item text-body-secondary py-3">Brak zadań. Kliknij „Dodaj zadanie", aby utworzyć pierwsze.</div><?php endif; ?>
        <div class="list-group-item dyd-filter-empty text-body-secondary py-3" style="display:none">Brak zadań pasujących do wyszukiwania.</div>
        <?php foreach ($homeworks as $hw): $av = k30_ti_avail_status($hw['open_at']??null, $hw['close_at']??null); ?>
        <div class="list-group-item <?= $hw['is_active']?'':'opacity-50' ?>" data-filter-item="1">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold"><?= h($hw['title']) ?></span>
            <?php if (!$hw['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
            <?php if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-clock me-1"></i><?= h($av['label']) ?></span>
            <?php elseif ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-lock me-1"></i><?= h($av['label']) ?></span><?php endif; ?>
            <span class="text-body-secondary small ms-auto"><i class="bi bi-inbox me-1"></i><?= (int)$hw['sub_count'] ?> oddań · <?= (int)$hw['graded_count'] ?> ocen.</span>
          </div>
          <?php if ($hw['due_at']): ?><div class="text-body-secondary small mt-1"><i class="bi bi-calendar-check me-1"></i>termin: <?= h(substr($hw['due_at'],0,16)) ?></div><?php endif; ?>
          <?php if ($hw['description']): ?><div class="small mt-1" style="white-space:pre-wrap"><?= nl2br(h($hw['description'])) ?></div><?php endif; ?>
          <?php if (trim((string)($hw['hint'] ?? '')) !== ''): ?><div class="small mt-1 text-info-emphasis" style="white-space:pre-wrap"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i><strong>Podpowiedź:</strong> <?= nl2br(h($hw['hint'])) ?></div><?php endif; ?>
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <?php if ($hw['attach_path']): ?><a href="?dl=hw&id=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-paperclip me-1"></i>załącznik</a><?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edH<?= (int)$hw['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
            <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/homework.php?id=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-check2-square me-1"></i>Oddania / oceny</a>
            <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć zadanie wraz z oddaniami?')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
      <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-collection-play me-2"></i>Materiały / eLearning</span>
        <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addM">
          <i class="bi bi-plus-lg me-1"></i>Dodaj materiał
        </button>
        <?php if ($materials): ?>
        <div class="w-100">
          <input type="search" class="form-control form-control-sm" placeholder="Szukaj materiałów (tytuł, typ, opis…)"
                 aria-label="Filtruj materiały" data-dyd-filterbox="dyd-list-materialy">
        </div>
        <?php endif; ?>
      </div>
      <div class="list-group list-group-flush" id="dyd-list-materialy">
        <?php if (!$materials): ?><div class="list-group-item text-body-secondary py-3">Brak materiałów. Kliknij „Dodaj materiał", aby utworzyć pierwszy.</div><?php endif; ?>
        <div class="list-group-item dyd-filter-empty text-body-secondary py-3" style="display:none">Brak materiałów pasujących do wyszukiwania.</div>
        <?php foreach ($materials as $m): $av = k30_ti_avail_status($m['open_at']??null, $m['close_at']??null); ?>
        <div class="list-group-item <?= $m['is_active']?'':'opacity-50' ?>" data-filter-item="1">
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
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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

  </div><!-- /dyd-tabpane kurs -->
  <?php endif; /* $course */ ?>

    <?php /* ═══════════════════════ DOSTĘPNOŚĆ ═══════════════════════ */ ?>
    <?php if ($tab === 'dostepnosc'):
      $av_by_day = [];
      foreach ($my_avail as $w) { $av_by_day[(int)$w['day_of_week']][] = $w; }
    ?>
    <?php if (!$my_avail): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3 mb-3" role="alert">
      <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
      <div>
        <strong>Nie masz jeszcze ustawionej dostępności.</strong><br>
        Dodaj okna czasowe poniżej, aby koordynatorzy wiedzieli, kiedy możesz prowadzić zajęcia.
        Bez ustawionej dostępności system nie może automatycznie weryfikować terminów lekcji.
      </div>
    </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Moja dostępność w tygodniu</span>
      </div>
      <div class="card-body">
        <p class="text-body-secondary small">Zajęcia można dodać tylko w godzinach Twojej dostępności. Bez zdefiniowanych okien obowiązują dotychczasowe zasady (bez ograniczeń). Możesz dodać kilka okien w jednym dniu.</p>
        <div class="row g-3">
          <?php foreach ([1,2,3,4,5,6,0] as $dw): $wins = $av_by_day[$dw] ?? []; ?>
          <div class="col-md-6 col-lg-4">
            <div class="border rounded p-2 h-100">
              <div class="fw-semibold mb-2"><i class="bi bi-calendar-day me-1 text-primary" aria-hidden="true"></i><?= h(K30_TI_DAYS[$dw]) ?></div>
              <?php if (!$wins): ?><div class="text-body-secondary small mb-2">— niedostępny —</div><?php endif; ?>
              <?php foreach ($wins as $w): ?>
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge text-bg-primary"><?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?></span>
                <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć to okno dostępności?')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="avail_delete">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń okno" aria-label="Usuń okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <hr>
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="avail_add">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <div class="col-sm-4">
            <label class="form-label fw-semibold" for="av_dow">Dzień tygodnia</label>
            <select class="form-select" id="av_dow" name="day_of_week" required>
              <?php foreach ([1,2,3,4,5,6,0] as $dw): ?>
              <option value="<?= $dw ?>"><?= h(K30_TI_DAYS[$dw]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" for="av_from">Od</label>
            <select class="form-select" id="av_from" name="time_from"><?= ti_time_options('09:00') ?></select>
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" for="av_to">Do</label>
            <select class="form-select" id="av_to" name="time_to"><?= ti_time_options('13:00') ?></select>
          </div>
          <div class="col-sm-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'testy'):
      $dyd_tests    = k30_ti_tests_list($cur_course);
      $dyd_students = db_all(
          "SELECT cl.id, cl.name FROM k30_clients cl
           JOIN k30_ti_enrollments e ON e.client_id=cl.id
           WHERE e.course_id=? AND e.status='active' ORDER BY cl.name",
          [$cur_course]);
    ?>
    <?php if (!$dyd_tests): ?>
      <div class="alert alert-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak testów w tym kursie.</div>
    <?php else: ?>

    <?php foreach ($dyd_tests as $dt):
      $dt_id = (int)$dt['id'];
      // Wszystkie zakończone podejścia do tego testu
      $dt_attempts = db_all(
          "SELECT a.*, cl.name AS client_name
           FROM k30_ti_test_attempts a
           JOIN k30_clients cl ON cl.id=a.client_id
           WHERE a.test_id=? ORDER BY cl.name, a.id",
          [$dt_id]);
      // Indeks: client_id → lista podejść
      $dt_att_map = [];
      foreach ($dt_attempts as $da) $dt_att_map[(int)$da['client_id']][] = $da;
      $n_q = (int)$dt['n_questions'];
      $dt_pass = (int)($dt['pass_pct'] ?? 0);
    ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-card-checklist text-primary me-1" aria-hidden="true"></i><?= h($dt['title']) ?>
        <span class="badge bg-secondary"><?= $n_q ?> pytań</span>
        <?php if ($dt_pass > 0): ?><span class="badge bg-light text-dark border">próg <?= $dt_pass ?>%</span><?php endif; ?>
        <?php if (empty($dt['is_active'])): ?><span class="badge bg-warning text-dark"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>ukryty</span><?php endif; ?>
      </div>
      <?php if (!$dyd_students): ?>
        <div class="card-body text-muted small">Brak aktywnych kursantów.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr>
            <th scope="col">Kursant</th>
            <th scope="col" class="text-center">Etap</th>
            <th scope="col" class="text-center">Wynik</th>
            <th scope="col" class="text-center">Podejść</th>
            <th scope="col" class="text-center">Poprawa</th>
          </tr></thead>
          <tbody>
          <?php foreach ($dyd_students as $ds):
            $cid      = (int)$ds['id'];
            $att_list = $dt_att_map[$cid] ?? [];
            // Ostatnie i najlepsze
            $last_att = $att_list ? end($att_list) : null;
            $best_pct = null; $has_retake = false;
            foreach ($att_list as $aa) {
                if ($aa['status'] === 'graded' && $aa['max_score'] > 0) {
                    $p = round(100 * (float)$aa['score'] / (float)$aa['max_score']);
                    if ($best_pct === null || $p > $best_pct) $best_pct = $p;
                }
                if ($aa['attempt_label'] === 'poprawa') $has_retake = true;
            }

            // Ustal etap
            if (!$att_list) {
                $stage = '<span class="text-body-secondary small">Nie rozpoczął</span>';
            } elseif ($last_att['status'] === 'in_progress') {
                $stage = '<span class="badge text-bg-info">W trakcie</span>';
            } elseif ($last_att['status'] === 'submitted' && (int)$last_att['needs_review']) {
                $stage = '<span class="badge text-bg-warning">Oczekuje na ocenę</span>';
            } elseif ($last_att['status'] === 'graded' || $last_att['status'] === 'submitted') {
                $p = $last_att['max_score'] > 0 ? round(100*(float)$last_att['score']/(float)$last_att['max_score']) : 0;
                $pass_thr = ($last_att['attempt_label'] === 'poprawa') ? min(100, $dt_pass + 20) : $dt_pass;
                $ok = $pass_thr === 0 || $p >= $pass_thr;
                $stage = $ok
                    ? '<span class="badge text-bg-success">Zaliczono</span>'
                    : '<span class="badge text-bg-danger">Nie zaliczono</span>';
            } else {
                $stage = '<span class="text-muted small">—</span>';
            }
          ?>
          <tr>
            <td><?= h($ds['name']) ?></td>
            <td class="text-center"><?= $stage ?></td>
            <td class="text-center">
              <?php if ($best_pct !== null): ?>
                <strong><?= $best_pct ?>%</strong>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= count($att_list) ?: '<span class="text-muted">0</span>' ?></td>
            <td class="text-center">
              <?php if ($has_retake): ?><i class="bi bi-arrow-repeat text-warning" title="Pisał poprawę" aria-label="Pisał poprawę"></i><?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; /* testy */ ?>

    <?php if ($tab === 'wiadomosci'): ?>
    <div class="row g-3">
      <!-- Lista wątków -->
      <div class="col-md-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="fw-semibold small text-muted">Wątki</span>
          <button type="button" class="btn btn-sm btn-primary"
                  data-bs-toggle="modal" data-bs-target="#dydMsgNew"
                  <?= empty($dyd_msg_accounts) ? 'disabled title="Brak kursantów w Twoich kursach"' : '' ?>>
            <i class="bi bi-pencil-square me-1"></i>Nowa
          </button>
        </div>
        <div class="list-group list-group-flush border rounded" style="max-height:65vh;overflow-y:auto">
          <?php if (empty($dyd_msg_threads)): ?>
          <div class="list-group-item text-muted small py-3 text-center">
            <i class="bi bi-envelope opacity-50 d-block mb-1" style="font-size:1.5rem"></i>Brak wiadomości
          </div>
          <?php else: ?>
          <?php foreach ($dyd_msg_threads as $th): $isActive = $dyd_msg_student_id === (int)$th['id']; ?>
          <a href="index.php?course=<?= $cur_course ?>&tab=wiadomosci&student=<?= (int)$th['id'] ?>"
             class="list-group-item list-group-item-action py-2 px-3 <?= $isActive ? 'active' : '' ?>">
            <div class="d-flex justify-content-between align-items-start">
              <span class="fw-semibold small"><?= h($th['name']) ?></span>
              <?php if ($th['unread'] > 0): ?>
              <span class="badge bg-danger ms-1"><?= (int)$th['unread'] ?></span>
              <?php endif; ?>
            </div>
            <div class="small <?= $isActive ? 'text-white-50' : 'text-muted' ?>" style="font-size:.75rem">
              <?= h($th['login']) ?> · <?= $th['last_at'] ? date('d.m H:i', strtotime($th['last_at'])) : '' ?>
            </div>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Aktywny wątek -->
      <div class="col-md-8">
        <?php if ($dyd_msg_student): ?>

        <!-- Nagłówek: imię + akcje -->
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <span class="fw-semibold">
            <i class="bi bi-person-circle text-primary me-1"></i>
            <?= h($dyd_msg_student['client_name']) ?>
            <span class="text-muted fw-normal small">(<?= h($dyd_msg_student['login']) ?>)</span>
          </span>
          <?php if ($dyd_msg_is_blocked): ?>
          <span class="badge bg-danger ms-1"><i class="bi bi-slash-circle me-1"></i>Zablokowany</span>
          <?php endif; ?>
          <div class="ms-auto d-flex gap-1">
            <!-- Blokada/odblokowanie -->
            <form method="post" class="d-inline">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="<?= $dyd_msg_is_blocked ? 'dyd_msg_unblock' : 'dyd_msg_block' ?>">
              <input type="hidden" name="course_id" value="<?= $cur_course ?>">
              <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $dyd_msg_is_blocked ? 'btn-outline-success' : 'btn-outline-danger' ?>"
                      onclick="return confirm('<?= $dyd_msg_is_blocked ? 'Odblokować wiadomości od tego kursanta?' : 'Zablokować wysyłanie wiadomości przez kursanta?' ?>')">
                <i class="bi bi-<?= $dyd_msg_is_blocked ? 'unlock' : 'slash-circle' ?> me-1"></i><?= $dyd_msg_is_blocked ? 'Odblokuj' : 'Zablokuj' ?>
              </button>
            </form>
            <!-- Log -->
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#dydMsgLog" aria-expanded="false" aria-controls="dydMsgLog">
              <i class="bi bi-journal-text me-1"></i>Dziennik
            </button>
          </div>
        </div>

        <!-- Dziennik zdarzeń (zwinięty) -->
        <div class="collapse mb-3" id="dydMsgLog">
          <div class="border rounded-2 p-2" style="max-height:200px;overflow-y:auto;font-size:.78rem">
            <?php if (empty($dyd_msg_log)): ?>
            <p class="text-muted mb-0 text-center py-2">Brak wpisów w dzienniku.</p>
            <?php else: ?>
            <table class="table table-sm table-borderless mb-0">
              <thead><tr class="text-muted"><th>Czas</th><th>Zdarzenie</th><th>Kto</th><th>Szczegóły</th><th>Urządzenie / IP</th></tr></thead>
              <tbody>
              <?php
              $action_labels = [
                  'login'               => 'Logowanie',
                  'login_failed'        => 'Nieudane logowanie',
                  'logout'              => 'Wylogowanie',
                  'password_changed'    => 'Zmiana hasła',
                  'alias_changed'       => 'Zmiana aliasu',
                  'msg_sent'            => 'Wiadomość od kursanta',
                  'msg_sent_by_staff'   => 'Odpowiedź prowadzącego',
                  'msg_blocked'         => 'Zablokowano wiadomości',
                  'msg_unblocked'       => 'Odblokowano wiadomości',
                  'msg_blocked_attempt' => 'Próba wysyłki przy blokadzie',
                  'msg_archived'        => 'Zarchiwizowano wiadomość',
              ];
              foreach ($dyd_msg_log as $le): ?>
              <tr>
                <td class="text-nowrap text-muted"><?= $le['created_at'] ? date('d.m H:i', strtotime($le['created_at'])) : '' ?></td>
                <td><?= h($action_labels[$le['action']] ?? $le['action']) ?></td>
                <td><?= $le['by_name'] ? h($le['by_name']) : '<span class="text-muted">kursant</span>' ?></td>
                <td class="text-muted"><?= h($le['detail']) ?></td>
                <td class="text-muted" style="font-size:.75rem">
                  <?php $dev = !empty($le['user_agent']) ? ti_log_device_label($le['user_agent']) : ''; echo $dev ? h($dev) : ''; ?>
                  <?php if (!empty($le['ip'])): ?><br><span style="font-size:.7rem"><?= h($le['ip']) ?></span><?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- Lista wiadomości -->
        <?php if (empty($dyd_msg_thread)): ?>
        <p class="text-muted small text-center mt-3">Brak wiadomości.</p>
        <?php else: ?>
        <ol class="list-unstyled d-flex flex-column gap-2 mb-3" aria-label="Wiadomości">
          <?php foreach ($dyd_msg_thread as $msg):
            $fromStaff = ($msg['sender'] !== 'student');
            $ts = $msg['created_at'] ? date('d.m.Y H:i', strtotime($msg['created_at'])) : '';
            $name = $fromStaff ? ($msg['sender_name'] ?? 'Prowadzący') : $dyd_msg_student['client_name'];
            $isArchived = !empty($msg['is_archived']);
          ?>
          <li <?= $isArchived ? 'style="opacity:.45"' : '' ?>>
            <article>
              <header class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
                <span class="fw-semibold"><?= h($name) ?></span>
                <span class="text-muted"><?= $fromStaff ? 'prowadzący' : 'kursant' ?></span>
                <time class="text-muted ms-auto" datetime="<?= h($msg['created_at'] ?? '') ?>"><?= h($ts) ?></time>
                <?php if (!$isArchived): ?>
                <form method="post" class="d-inline ms-1">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="dyd_msg_archive">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
                  <input type="hidden" name="msg_id" value="<?= (int)$msg['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-muted" title="Archiwizuj"
                          onclick="return confirm('Zarchiwizować tę wiadomość?')">
                    <i class="bi bi-archive" style="font-size:.8rem"></i>
                  </button>
                </form>
                <?php else: ?>
                <span class="badge bg-secondary ms-1" style="font-size:.65rem">arch.</span>
                <?php endif; ?>
              </header>
              <div class="border rounded-2 p-2 <?= $fromStaff ? 'border-primary border-opacity-25 bg-primary bg-opacity-10' : '' ?>"
                   style="font-size:.875rem;white-space:pre-wrap"><?= h($msg['body']) ?></div>
            </article>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <!-- Formularz odpowiedzi -->
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="dyd_msg_reply">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
          <label class="form-label small fw-semibold" for="dydReplyBody">Odpowiedź</label>
          <div class="d-flex gap-2">
            <textarea class="form-control form-control-sm" id="dydReplyBody" name="body" rows="3"
                      placeholder="Napisz odpowiedź…" required style="resize:none"></textarea>
            <button class="btn btn-primary btn-sm align-self-end" type="submit">
              <i class="bi bi-send"></i><span class="visually-hidden">Wyślij</span>
            </button>
          </div>
        </form>

        <?php else: ?>
        <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted" style="min-height:200px">
          <i class="bi bi-envelope-open" style="font-size:2.5rem;opacity:.3"></i>
          <p class="mt-2 small">Wybierz kursanta z listy lub napisz nową wiadomość.</p>
          <button type="button" class="btn btn-sm btn-outline-primary mt-1"
                  data-bs-toggle="modal" data-bs-target="#dydMsgNew"
                  <?= empty($dyd_msg_accounts) ? 'disabled' : '' ?>>
            <i class="bi bi-pencil-square me-1"></i>Nowa wiadomość
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Modal nowej wiadomości -->
    <div class="modal fade" id="dydMsgNew" tabindex="-1" aria-labelledby="dydMsgNewLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="dyd_msg_send">
            <input type="hidden" name="course_id" value="<?= $cur_course ?>">
            <div class="modal-header">
              <h5 class="modal-title" id="dydMsgNewLabel"><i class="bi bi-pencil-square me-2"></i>Nowa wiadomość</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label fw-semibold">Kursant <span class="text-danger">*</span></label>
                <select class="form-select" name="account_id" required>
                  <option value="">— Wybierz kursanta —</option>
                  <?php foreach ($dyd_msg_accounts as $a): ?>
                  <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?> — <?= h($a['course_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Temat</label>
                <input type="text" class="form-control" name="subject" placeholder="Temat wiadomości (opcjonalny)">
              </div>
              <div class="mb-2">
                <label class="form-label fw-semibold">Treść <span class="text-danger">*</span></label>
                <textarea class="form-control" name="body" rows="4" required placeholder="Napisz wiadomość…"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
              <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Wyślij</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; /* wiadomosci */ ?>

  <?php /* ═══════════════════ FORMALNOŚCI ═══════════════════ */ ?>
  <?php if ($tab === 'formalnosci'): ?>
  <?php
    $ct_labels = ['wolontariat'=>'Porozumienie wolontariackie','zlecenie'=>'Umowa zlecenie','dzielo'=>'Umowa o dzieło','praca'=>'Umowa o pracę'];
    $st_labels = ['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'];
    $st_colors = ['podpisana'=>'success','w realizacji'=>'primary','projekt'=>'secondary','zakończona'=>'dark','rozwiązana'=>'warning','anulowana'=>'danger'];
  ?>
  <div class="mt-3">
    <h2 class="h5 fw-bold mb-3"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Twoje formalności</h2>
    <?php if (!$dyd_contracts): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary py-4 text-center">
      <i class="bi bi-file-earmark-x fs-2 d-block mb-2" aria-hidden="true"></i>
      Brak informacji z rejestru umów.
    </div></div>
    <?php endif; ?>
    <?php foreach ($dyd_contracts as $dc):
      $st    = $dc['status'] ?? '';
      $stc   = $st_colors[$st] ?? 'secondary';
      $is_active = in_array($st, ['podpisana','w realizacji'], true);
    ?>
    <div class="card border-0 shadow-sm mb-3 <?= $is_active ? '' : 'opacity-75' ?>">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
          <div>
            <div class="fw-bold fs-6"><?= h($dc['numer_umowy'] ?: '(brak numeru)') ?></div>
            <div class="text-body-secondary small"><?= h($ct_labels[$dc['contract_type']] ?? $dc['contract_type']) ?></div>
          </div>
          <span class="badge bg-<?= $stc ?> ms-auto align-self-start" style="font-size:.78rem"><?= h($st_labels[$st] ?? $st) ?></span>
        </div>
        <dl class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2 mb-0" style="font-size:.88rem">
          <?php if (!empty($dc['imie_nazwisko'])): ?>
          <div class="col"><dt class="text-body-secondary fw-normal small">Imię i nazwisko</dt><dd class="mb-0 fw-semibold"><?= h($dc['imie_nazwisko']) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($dc['data_zawarcia'])): ?>
          <div class="col"><dt class="text-body-secondary fw-normal small">Data zawarcia</dt><dd class="mb-0"><?= date('d.m.Y', strtotime($dc['data_zawarcia'])) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($dc['data_zakonczenia'])): ?>
          <div class="col"><dt class="text-body-secondary fw-normal small">Ważna do</dt>
            <dd class="mb-0 <?= ($dc['data_zakonczenia'] < date('Y-m-d') && $is_active) ? 'text-danger fw-semibold' : '' ?>"><?= date('d.m.Y', strtotime($dc['data_zakonczenia'])) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($dc['stanowisko'])): ?>
          <div class="col"><dt class="text-body-secondary fw-normal small">Stanowisko / rola</dt><dd class="mb-0"><?= h($dc['stanowisko']) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($dc['wynagrodzenie_brutto'])): ?>
          <div class="col"><dt class="text-body-secondary fw-normal small">Wynagrodzenie brutto</dt><dd class="mb-0"><?= number_format((float)$dc['wynagrodzenie_brutto'], 2, ',', ' ') ?> zł</dd></div>
          <?php endif; ?>
          <?php if (!empty($dc['miejsce_wolontariatu'])): ?>
          <div class="col"><dt class="text-body-secondary fw-normal small">Miejsce</dt><dd class="mb-0"><?= h($dc['miejsce_wolontariatu']) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($dc['przedmiot_porozumienia'])): ?>
          <div class="col col-12"><dt class="text-body-secondary fw-normal small">Zakres działania</dt><dd class="mb-0" style="white-space:pre-wrap"><?= h(mb_substr($dc['przedmiot_porozumienia'], 0, 400)) ?></dd></div>
          <?php endif; ?>
        </dl>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="text-body-secondary small mt-2 mb-0">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
      W razie pytań dotyczących umów skontaktuj się z koordynatorem.
    </p>
  </div>
  <?php endif; /* formalnosci */ ?>

  <?php endif; /* $courses */ ?>

  <!-- Wspólna lista lekcji dla wyszukiwarek „Powiązana lekcja" -->
  <datalist id="dyd-session-list">
    <?php foreach ($session_opts as $o): ?>
    <option data-id="<?= (int)$o['id'] ?>" value="<?= h($o['label']) ?>"></option>
    <?php endforeach; ?>
  </datalist>

</main>
<script>
// „Zaznacz / odznacz wszystkich" w oknie sprawdzania obecności.
document.addEventListener('click', function(e){
  var b = e.target.closest('.att-toggle-all'); if (!b) return;
  var modal = b.closest('.modal'); if (!modal) return;
  var boxes = modal.querySelectorAll('input[name="attended[]"]:not(:disabled)');
  var allChecked = Array.prototype.every.call(boxes, function(c){ return c.checked; });
  Array.prototype.forEach.call(boxes, function(c){ c.checked = !allChecked; });
});

// Odwołanie / przywrócenie udziału kursanta (z modalu obecności) — przez ukryty formularz.
function dydCancelAtt(sid, cid) {
  if (!confirm('Odwołać udział tego kursanta? Nie będzie liczony do ceny.')) return;
  document.getElementById('daa_op').value = 'cancel_attendee';
  document.getElementById('daa_sid').value = sid;
  document.getElementById('daa_cid').value = cid;
  document.getElementById('dydAttAction').submit();
}
function dydRestoreAtt(sid, cid) {
  if (!confirm('Przywrócić udział tego kursanta?')) return;
  document.getElementById('daa_op').value = 'restore_attendee';
  document.getElementById('daa_sid').value = sid;
  document.getElementById('daa_cid').value = cid;
  document.getElementById('dydAttAction').submit();
}
// Odwołanie całej lekcji — otwiera modal z powodem.
function dydOpenCancelSession(sid, label) {
  document.getElementById('cs_sid').value = sid;
  document.getElementById('cs_label').textContent = label || '';
  var t = document.getElementById('cs_reason'); if (t) t.value = '';
  new bootstrap.Modal(document.getElementById('cancelSessionModal')).show();
}

// Wyszukiwarka „Powiązana lekcja": tekst → ukryte session_id (mapa etykieta→id).
(function(){
  var map = {};
  document.querySelectorAll('#dyd-session-list option').forEach(function(o){ map[o.value] = o.getAttribute('data-id'); });
  document.addEventListener('input', function(e){
    var inp = e.target.closest('.dyd-lesson-combo'); if (!inp) return;
    var hid = document.getElementById(inp.getAttribute('data-target')); if (!hid) return;
    hid.value = map[inp.value] || '';   // dopasowano z listy → id; w innym wypadku brak powiązania
  });
})();

// Filtrowanie list (lekcje / zadania / materiały) po tekście.
document.addEventListener('input', function(e){
  var box = e.target.closest('[data-dyd-filterbox]'); if (!box) return;
  var list = document.getElementById(box.getAttribute('data-dyd-filterbox')); if (!list) return;
  var q = box.value.toLowerCase().trim();
  var items = list.querySelectorAll('.list-group-item');
  var shown = 0;
  items.forEach(function(item){
    if (!item.dataset.filterItem) return; // stały element (komunikat „brak")
    var match = !q || item.textContent.toLowerCase().indexOf(q) !== -1;
    item.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  // Pokaż / ukryj komunikat „brak wyników"
  var msg = list.querySelector('.dyd-filter-empty');
  if (msg) msg.style.display = (q && shown === 0) ? '' : 'none';
});
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
