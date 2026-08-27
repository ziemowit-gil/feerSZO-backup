<?php
/**
 * karty30/ti/dydaktyk/exam_build.php
 * Equi Exams — kreator egzaminów dla prowadzącego (panel dydaktyka).
 *
 * Widoki (sterowane parametrami GET):
 *   ?course_id=N            — lista egzaminów kursu + zakładanie nowego,
 *   ?exam_id=N              — ustawienia egzaminu + lista pytań,
 *   ?exam_id=N&new=TYP      — formularz nowego pytania danego typu,
 *   ?exam_id=N&q=ID         — edycja istniejącego pytania.
 *
 * Typ pytania wybiera się PRZED formularzem, więc każdy formularz jest
 * renderowany po stronie serwera tylko z polami, które w danym typie mają sens.
 * Nie ma pól chowanych JS-em — czytnik ekranu widzi dokładnie to, co jest.
 *
 * Autoryzacja: dyd_require() + kontrola przynależności kursu do prowadzącego.
 * CSRF: dyd_token() w polu `_token` (NIE csrf_token — panel ma osobną sesję).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_exams.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$my_courses = dyd_courses($uid);
$my_cids    = array_map(fn($c) => (int)$c['id'], $my_courses);

$assert_course = function (int $cid) use ($my_cids): void {
    if (!in_array($cid, $my_cids, true)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }
};
$assert_exam = function (int $eid) use ($my_cids): array {
    $e = $eid ? ti_exam_get($eid) : null;
    if (!$e || !in_array((int)$e['course_id'], $my_cids, true)) {
        http_response_code(403); exit('Brak uprawnień do tego egzaminu.');
    }
    return $e;
};

// ═══════════════════════════════ OBSŁUGA POST ═══════════════════════════════
//
// Ta warstwa nie podejmuje decyzji merytorycznych. Zbiera pola formularza,
// oddaje je silnikowi Java do walidacji i normalizacji, a wynik zapisuje.
// Komunikaty błędów pochodzą z silnika — dzięki temu reguła „co jest poprawnym
// pytaniem" istnieje w jednym miejscu.

/** Zapamiętuje błędy walidacji z silnika, żeby pokazać je przy formularzu. */
function exam_stash_issues(?array $issues, ?string $error): void {
    if ($error !== null) {
        flash_set('danger', 'Silnik Equi Exams nie odpowiada — nie zapisano zmian. ' . $error);
        return;
    }
    $msgs = [];
    foreach ((array)($issues['errors'] ?? []) as $e) $msgs[] = (string)($e['message'] ?? '');
    flash_set('danger', $msgs ? implode(' ', $msgs) : 'Nie udało się zapisać — sprawdź wprowadzone dane.');
}

/** Ostrzeżenia z silnika (zapis się udał, ale coś warto poprawić). */
function exam_flash_warnings(?array $issues): void {
    $msgs = [];
    foreach ((array)($issues['warnings'] ?? []) as $w) $msgs[] = (string)($w['message'] ?? '');
    if ($msgs) flash_set('warning', implode(' ', $msgs));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'save_exam') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $assert_course($cid);
        $eid = (int)($_POST['exam_id'] ?? 0);
        if ($eid) $assert_exam($eid);

        $form = [
            'title'            => (string)($_POST['title'] ?? ''),
            'description'      => (string)($_POST['description'] ?? ''),
            'mode'             => (string)($_POST['mode'] ?? 'exam'),
            'timeLimitMin'     => (string)($_POST['time_limit_min'] ?? ''),
            'passPct'          => (string)($_POST['pass_pct'] ?? ''),
            'maxAttempts'      => (string)($_POST['max_attempts'] ?? ''),
            'shuffleQuestions' => isset($_POST['shuffle_questions']),
            'shuffleOptions'   => isset($_POST['shuffle_options']),
            'fixedDraw'        => (string)($_POST['fixed_draw'] ?? ''),
            'bankDraw'         => (string)($_POST['bank_draw'] ?? ''),
            'showFeedback'     => (string)($_POST['show_feedback'] ?? ''),
            'negMarking'       => (string)($_POST['neg_marking'] ?? ''),
            'openAt'           => trim((string)($_POST['open_at'] ?? '')),
            'closeAt'          => trim((string)($_POST['close_at'] ?? '')),
            'isActive'         => isset($_POST['is_active']),
            'syncGrade'        => isset($_POST['sync_grade']),
            'gradeWeight'      => (string)($_POST['grade_weight'] ?? ''),
            'sessionId'        => (string)($_POST['session_id'] ?? ''),
        ];

        $issues = null; $err = null;
        $saved  = ti_exam_save_via_engine($cid, $eid ?: null, $form, $uid, $issues, $err);
        if ($saved === null) {
            exam_stash_issues($issues, $err);
            header('Location: exam_build.php?' . ($eid ? 'exam_id=' . $eid : 'course_id=' . $cid)); exit;
        }
        exam_flash_warnings($issues);
        flash_set('success', $eid ? 'Ustawienia egzaminu zapisane.' : 'Egzamin utworzony — dodaj pytania.');
        header('Location: exam_build.php?exam_id=' . $saved); exit;
    }

    if ($op === 'toggle_active') {
        $e = $assert_exam((int)($_POST['exam_id'] ?? 0));
        // Także tu decyduje silnik: udostępnienie zestawu bez pytań jest błędem.
        $form = [
            'title'            => (string)$e['title'],
            'description'      => (string)$e['description'],
            'mode'             => (string)$e['mode'],
            'timeLimitMin'     => (int)$e['time_limit_min'],
            'passPct'          => (int)$e['pass_pct'],
            'maxAttempts'      => (int)$e['max_attempts'],
            'shuffleQuestions' => (int)$e['shuffle_questions'] === 1,
            'shuffleOptions'   => (int)$e['shuffle_options'] === 1,
            'fixedDraw'        => (int)$e['fixed_draw'],
            'bankDraw'         => (int)$e['bank_draw'],
            'showFeedback'     => (string)$e['show_feedback'],
            'negMarking'       => (string)$e['neg_marking'],
            'openAt'           => (string)($e['open_at'] ?? ''),
            'closeAt'          => (string)($e['close_at'] ?? ''),
            'isActive'         => empty($e['is_active']),
            'syncGrade'        => (int)$e['sync_grade'] === 1,
            'gradeWeight'      => (int)$e['grade_weight'],
            'sessionId'        => (int)($e['session_id'] ?? 0),
        ];
        $issues = null; $err = null;
        $saved  = ti_exam_save_via_engine((int)$e['course_id'], (int)$e['id'], $form, $uid, $issues, $err);
        if ($saved === null) exam_stash_issues($issues, $err);
        else flash_set('success', empty($e['is_active'])
            ? 'Egzamin udostępniony kursantom.' : 'Egzamin ukryty przed kursantami.');
        header('Location: exam_build.php?exam_id=' . (int)$e['id']); exit;
    }

    if ($op === 'delete_exam') {
        $e = $assert_exam((int)($_POST['exam_id'] ?? 0));
        ti_exam_delete((int)$e['id']);
        flash_set('success', 'Egzamin usunięty wraz z pytaniami i podejściami.');
        header('Location: exam_build.php?course_id=' . (int)$e['course_id']); exit;
    }

    if ($op === 'import_test') {
        $e   = $assert_exam((int)($_POST['exam_id'] ?? 0));
        $tid = (int)($_POST['test_id'] ?? 0);
        $t   = $tid && function_exists('k30_ti_test_get') ? k30_ti_test_get($tid) : null;
        if (!$t || (int)$t['course_id'] !== (int)$e['course_id']) {
            flash_set('danger', 'Wybrany test nie należy do tego kursu.');
        } else {
            $r = ti_exam_import_from_test((int)$e['id'], $tid);
            if ($r['error'] !== null) {
                flash_set('danger', 'Import przerwany: ' . $r['error']);
            } else {
                flash_set('success', 'Zaimportowano pytań: ' . $r['imported']
                    . ($r['skipped'] ? '. Pominięto ' . $r['skipped'] . ' (silnik uznał je za niekompletne).' : '.')
                    . ' Sprawdź punktację i kryteria pytań opisowych.');
            }
        }
        header('Location: exam_build.php?exam_id=' . (int)$e['id']); exit;
    }

    if ($op === 'move_question') {
        $q = ti_exam_question_get((int)($_POST['question_id'] ?? 0));
        if ($q) {
            $assert_exam((int)$q['exam_id']);
            ti_exam_question_move((int)$q['id'], (int)($_POST['dir'] ?? 1));
        }
        header('Location: exam_build.php?exam_id=' . (int)($q['exam_id'] ?? 0)); exit;
    }

    if ($op === 'delete_question') {
        $q = ti_exam_question_get((int)($_POST['question_id'] ?? 0));
        if ($q) {
            $assert_exam((int)$q['exam_id']);
            ti_exam_question_delete((int)$q['id']);
            flash_set('success', 'Pytanie usunięte.');
        }
        header('Location: exam_build.php?exam_id=' . (int)($q['exam_id'] ?? 0)); exit;
    }

    if ($op === 'save_question') {
        $e    = $assert_exam((int)($_POST['exam_id'] ?? 0));
        $qid  = (int)($_POST['question_id'] ?? 0);
        $type = (string)($_POST['type'] ?? 'single');
        $back = 'exam_build.php?exam_id=' . (int)$e['id'] . ($qid ? '&q=' . $qid : '&new=' . urlencode($type));

        // Repeatery formularza przekładamy jeden do jednego na listy obiektów.
        // Rozbijanie pól wielolinijkowych, przycinanie liczb i cała walidacja
        // dzieją się w silniku — tu nie ma żadnej reguły merytorycznej.
        // Jednokrotny wybór przychodzi jako jedna grupa radiów (indeks wariantu),
        // pozostałe typy jako niezależne pola wyboru.
        $single_pick = (string)($_POST['opt_correct_single'] ?? '');
        $options = [];
        foreach ((array)($_POST['opt_label'] ?? []) as $i => $label) {
            $options[] = [
                'label'    => (string)$label,
                'correct'  => $type === 'single'
                                ? ($single_pick !== '' && (int)$single_pick === (int)$i)
                                : !empty($_POST['opt_correct'][$i]),
                'feedback' => (string)($_POST['opt_feedback'][$i] ?? ''),
            ];
        }
        $cases = [];
        foreach ((array)($_POST['case_expected'] ?? []) as $i => $expected) {
            $cases[] = [
                'name'      => (string)($_POST['case_name'][$i] ?? ''),
                'stdin'     => (string)($_POST['case_stdin'][$i] ?? ''),
                'expected'  => (string)$expected,
                'matchMode' => (string)($_POST['case_match'][$i] ?? 'trim'),
                'weight'    => (string)($_POST['case_weight'][$i] ?? '1'),
                'hidden'    => !empty($_POST['case_hidden'][$i]),
                'tolerance' => (string)($_POST['case_tol'][$i] ?? '0.000001'),
            ];
        }
        $blanks = [];
        foreach ((array)($_POST['blank_key'] ?? []) as $i => $key) {
            $blanks[] = [
                'key'    => (string)$key,
                'accept' => (string)($_POST['blank_accept'][$i] ?? ''),
                'hint'   => (string)($_POST['blank_hint'][$i] ?? ''),
                'points' => (string)($_POST['blank_points'][$i] ?? '1'),
            ];
        }
        $criteria = [];
        foreach ((array)($_POST['crit_label'] ?? []) as $i => $label) {
            $criteria[] = [
                'label'    => (string)$label,
                'any'      => (string)($_POST['crit_any'][$i] ?? ''),
                'points'   => (string)($_POST['crit_points'][$i] ?? '1'),
                'required' => !empty($_POST['crit_required'][$i]),
            ];
        }

        $form = [
            'options'            => $options,
            'cases'              => $cases,
            'blanks'             => $blanks,
            'criteria'           => $criteria,
            'language'           => (string)($_POST['language'] ?? 'python'),
            'snippet'            => (string)($_POST['snippet'] ?? ''),
            'template'           => (string)($_POST['template'] ?? ''),
            'starter'            => (string)($_POST['starter'] ?? ''),
            'answerMode'         => (string)($_POST['answer_mode'] ?? 'line'),
            'acceptLines'        => (string)($_POST['accept_lines'] ?? ''),
            'requireExplanation' => isset($_POST['require_explanation']),
            'runAfterFill'       => isset($_POST['run_after_fill']),
            'timeLimitMs'        => (string)($_POST['time_limit_ms'] ?? '3000'),
            'memoryMb'           => (string)($_POST['memory_mb'] ?? '128'),
            'forbidden'          => (string)($_POST['forbidden'] ?? ''),
            'required'           => (string)($_POST['required_patterns'] ?? ''),
            'allOrNothing'       => isset($_POST['all_or_nothing']),
            'negMarking'         => (string)($_POST['q_neg_marking'] ?? ''),
            'caseSensitive'      => isset($_POST['case_sensitive']),
            'ignoreAccents'      => isset($_POST['ignore_accents']),
            'regex'              => isset($_POST['blank_regex']),
            'manual'             => isset($_POST['manual_review']),
            'minChars'           => (string)($_POST['min_chars'] ?? '0'),
            'runLimits'          => true,
        ];

        $issues = null; $err = null;
        $saved  = ti_exam_question_save_via_engine((int)$e['id'], $qid ?: null, [
            'type'        => $type,
            'prompt'      => (string)($_POST['prompt'] ?? ''),
            'points'      => (string)($_POST['points'] ?? '1'),
            'inBank'      => isset($_POST['in_bank']),
            'explanation' => (string)($_POST['explanation'] ?? ''),
            'form'        => $form,
        ], $issues, $err);

        if ($saved === null) {
            exam_stash_issues($issues, $err);
            header('Location: ' . $back); exit;
        }
        exam_flash_warnings($issues);
        flash_set('success', $qid ? 'Pytanie zapisane.' : 'Pytanie dodane do zestawu.');
        header('Location: exam_build.php?exam_id=' . (int)$e['id'] . '&q=' . $saved); exit;
    }

    header('Location: exam_build.php'); exit;
}

// ═══════════════════════════════ WIDOK ══════════════════════════════════════
$exam_id   = (int)($_GET['exam_id'] ?? 0);
$exam      = $exam_id ? $assert_exam($exam_id) : null;
$course_id = $exam ? (int)$exam['course_id'] : (int)($_GET['course_id'] ?? 0);
if (!$exam && $course_id) $assert_course($course_id);
if (!$course_id && $my_cids) $course_id = $my_cids[0];

$course    = $course_id ? k30_ti_course_get($course_id) : null;
$exams     = $course_id ? ti_exams_list($course_id) : [];
$questions = $exam ? ti_exam_questions((int)$exam['id']) : [];

$new_type  = (string)($_GET['new'] ?? '');
if ($new_type !== '' && !isset(K30_TI_EXAM_TYPES[$new_type])) $new_type = '';
$edit_q    = isset($_GET['q']) ? ti_exam_question_get((int)$_GET['q']) : null;
if ($edit_q && (int)$edit_q['exam_id'] !== (int)($exam['id'] ?? 0)) $edit_q = null;

$health    = ti_exam_engine_health();
$languages = ti_exam_available_languages();

// Strona stoi poza systemem zakładek panelu — bez tego skrypt „pamiętaj zakładkę"
// z _layout_foot.php przekierowałby kreator na ostatnio oglądaną zakładkę.
$KP_SKIP_TAB_MEMORY = true;

$KP_TITLE  = $exam ? ('Egzamin: ' . $exam['title']) : ('Egzaminy — ' . (string)($course['name'] ?? ''));
$KP_TOPBAR = ['brand' => EQUI_EXAMS_NAME . ' — ' . EQUI_EXAMS_STAFF_LABEL, 'icon' => 'patch-question',
              'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
include dirname(__DIR__) . '/kursant/_layout_head.php';
require __DIR__ . '/_exam_build_view.php';
include dirname(__DIR__) . '/kursant/_layout_foot.php';
