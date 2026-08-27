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

/** Zamienia pole wielolinijkowe na listę niepustych wartości. */
$lines = function ($raw): array {
    $out = [];
    foreach (preg_split('/\R/', (string)$raw) as $l) {
        $l = trim($l);
        if ($l !== '') $out[] = $l;
    }
    return $out;
};

// ═══════════════════════════════ OBSŁUGA POST ═══════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'save_exam') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $assert_course($cid);
        $eid = (int)($_POST['exam_id'] ?? 0);
        if ($eid) $assert_exam($eid);
        if (trim((string)($_POST['title'] ?? '')) === '') {
            flash_set('danger', 'Podaj tytuł egzaminu.');
            header('Location: exam_build.php?' . ($eid ? 'exam_id=' . $eid : 'course_id=' . $cid)); exit;
        }
        $new = ti_exam_save([
            'course_id'         => $cid,
            'session_id'        => $_POST['session_id']     ?? null,
            'title'             => $_POST['title']           ?? '',
            'description'       => $_POST['description']     ?? '',
            'mode'              => $_POST['mode']            ?? 'exam',
            'time_limit_min'    => $_POST['time_limit_min']  ?? 0,
            'pass_pct'          => $_POST['pass_pct']        ?? 0,
            'max_attempts'      => $_POST['max_attempts']    ?? 1,
            'shuffle_questions' => isset($_POST['shuffle_questions']) ? 1 : 0,
            'shuffle_options'   => isset($_POST['shuffle_options'])   ? 1 : 0,
            'fixed_draw'        => $_POST['fixed_draw']      ?? 0,
            'bank_draw'         => $_POST['bank_draw']       ?? 0,
            'show_feedback'     => $_POST['show_feedback']   ?? 'never',
            'neg_marking'       => $_POST['neg_marking']     ?? 'partial',
            'open_at'           => $_POST['open_at']         ?? '',
            'close_at'          => $_POST['close_at']        ?? '',
            'is_active'         => isset($_POST['is_active'])  ? 1 : 0,
            'sync_grade'        => isset($_POST['sync_grade']) ? 1 : 0,
            'grade_weight'      => $_POST['grade_weight']    ?? 3,
        ], $eid ?: null, $uid);
        flash_set('success', $eid ? 'Ustawienia egzaminu zapisane.' : 'Egzamin utworzony — dodaj pytania.');
        header('Location: exam_build.php?exam_id=' . ($eid ?: $new)); exit;
    }

    if ($op === 'toggle_active') {
        $e = $assert_exam((int)($_POST['exam_id'] ?? 0));
        db()->prepare("UPDATE k30_ti_exams SET is_active=?, updated_at=? WHERE id=?")
            ->execute([empty($e['is_active']) ? 1 : 0, date('Y-m-d H:i:s'), (int)$e['id']]);
        flash_set('success', empty($e['is_active'])
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
            $n = ti_exam_import_from_test((int)$e['id'], $tid);
            flash_set('success', 'Zaimportowano pytań: ' . $n . '. Sprawdź punktację i kryteria pytań opisowych.');
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
        if (!isset(K30_TI_EXAM_TYPES[$type])) $type = 'single';
        $back = 'exam_build.php?exam_id=' . (int)$e['id'] . ($qid ? '&q=' . $qid : '&new=' . $type);

        if (trim((string)($_POST['prompt'] ?? '')) === '') {
            flash_set('danger', 'Treść polecenia jest wymagana.');
            header('Location: ' . $back); exit;
        }

        // ── Konfiguracja zależna od typu ────────────────────────────────────
        $cfg = [];
        $normalization = [
            'caseSensitive'  => isset($_POST['case_sensitive']),
            'ignoreAccents'  => isset($_POST['ignore_accents']),
            'collapseSpaces' => true,
            'trim'           => true,
        ];

        if ($type === 'multi' || $type === 'truefalse') {
            if (isset(K30_TI_EXAM_NEG[$_POST['q_neg_marking'] ?? ''])) {
                $cfg['negMarking'] = (string)$_POST['q_neg_marking'];
            }
        }

        if ($type === 'fill_blank' || $type === 'code_completion') {
            $cfg = array_merge($cfg, $normalization);
            $cfg['regex']        = isset($_POST['blank_regex']);
            $cfg['allOrNothing'] = isset($_POST['all_or_nothing']);
            $blanks = [];
            foreach ((array)($_POST['blank_key'] ?? []) as $i => $key) {
                $key    = trim((string)$key);
                $accept = $lines($_POST['blank_accept'][$i] ?? '');
                if ($key === '' || !$accept) continue;
                $blanks[] = [
                    'key'    => $key,
                    'accept' => $accept,
                    'hint'   => trim((string)($_POST['blank_hint'][$i] ?? '')),
                    'points' => max(0.01, (float)str_replace(',', '.', (string)($_POST['blank_points'][$i] ?? 1))),
                ];
            }
            $cfg['blanks'] = $blanks;
            if ($type === 'code_completion') {
                $cfg['language']     = (string)($_POST['language'] ?? 'python');
                $cfg['template']     = (string)($_POST['template'] ?? '');
                $cfg['runAfterFill'] = isset($_POST['run_after_fill']);
            }
        }

        if ($type === 'short_answer') {
            $cfg = array_merge($cfg, $normalization);
            $cfg['manual']   = isset($_POST['manual_review']);
            $cfg['minChars'] = max(0, (int)($_POST['min_chars'] ?? 0));
            $crit = [];
            foreach ((array)($_POST['crit_label'] ?? []) as $i => $label) {
                $any = $lines($_POST['crit_any'][$i] ?? '');
                if (!$any) continue;
                $crit[] = [
                    'label'    => trim((string)$label) !== '' ? trim((string)$label) : $any[0],
                    'any'      => $any,
                    'points'   => max(0.01, (float)str_replace(',', '.', (string)($_POST['crit_points'][$i] ?? 1))),
                    'required' => !empty($_POST['crit_required'][$i]),
                ];
            }
            $cfg['keywords'] = $crit;
        }

        if ($type === 'code_fix') {
            $cfg['language']           = (string)($_POST['language'] ?? 'python');
            $cfg['snippet']            = (string)($_POST['snippet'] ?? '');
            $cfg['answerMode']         = ($_POST['answer_mode'] ?? 'line') === 'rewrite' ? 'rewrite' : 'line';
            $cfg['requireExplanation'] = isset($_POST['require_explanation']);
            $cfg['acceptLines']        = array_values(array_filter(array_map(
                fn($v) => (int)trim($v),
                explode(',', (string)($_POST['accept_lines'] ?? ''))
            )));
        }

        if ($type === 'code_run' || ($type === 'code_fix' && ($cfg['answerMode'] ?? '') === 'rewrite')) {
            $cfg['language']    = (string)($_POST['language'] ?? 'python');
            $cfg['starter']     = (string)($_POST['starter'] ?? '');
            $cfg['timeLimitMs'] = max(200, min(15000, (int)($_POST['time_limit_ms'] ?? 3000)));
            $cfg['memoryMb']    = max(32,  min(512,   (int)($_POST['memory_mb'] ?? 128)));
            $cfg['forbidden']   = $lines($_POST['forbidden'] ?? '');
            $cfg['required']    = $lines($_POST['required_patterns'] ?? '');
            $cfg['allOrNothing'] = isset($_POST['all_or_nothing']);
        }

        // ── Warianty ────────────────────────────────────────────────────────
        $options = [];
        foreach ((array)($_POST['opt_label'] ?? []) as $i => $label) {
            if (trim((string)$label) === '') continue;
            $options[] = [
                'label'      => (string)$label,
                'is_correct' => !empty($_POST['opt_correct'][$i]),
                'feedback'   => (string)($_POST['opt_feedback'][$i] ?? ''),
            ];
        }

        // ── Przypadki testowe ───────────────────────────────────────────────
        $cases = [];
        foreach ((array)($_POST['case_expected'] ?? []) as $i => $expected) {
            $stdin = (string)($_POST['case_stdin'][$i] ?? '');
            if (trim((string)$expected) === '' && trim($stdin) === '') continue;
            $cases[] = [
                'name'       => (string)($_POST['case_name'][$i] ?? ''),
                'stdin'      => $stdin,
                'expected'   => (string)$expected,
                'match_mode' => (string)($_POST['case_match'][$i] ?? 'trim'),
                'weight'     => (string)($_POST['case_weight'][$i] ?? 1),
                'is_hidden'  => !empty($_POST['case_hidden'][$i]),
                'tolerance'  => (string)($_POST['case_tol'][$i] ?? '0.000001'),
            ];
        }

        // ── Walidacja zależna od typu — chroni przed pytaniem bez klucza ─────
        $meta = K30_TI_EXAM_TYPES[$type];
        $err  = null;
        if (!empty($meta['options'])) {
            if (count($options) < 2) $err = 'Podaj co najmniej dwa warianty odpowiedzi.';
            elseif (!array_filter(array_column($options, 'is_correct')) && $type !== 'truefalse') {
                $err = 'Zaznacz przynajmniej jeden poprawny wariant.';
            }
        }
        if ($type === 'fill_blank' && empty($cfg['blanks'])) $err = 'Zdefiniuj przynajmniej jedną lukę.';
        if ($type === 'code_completion' && empty($cfg['blanks']) && empty($cfg['runAfterFill'])) {
            $err = 'Zdefiniuj luki albo włącz sprawdzanie uruchomieniowe.';
        }
        if ($type === 'code_run' && !$cases) $err = 'Dodaj przynajmniej jeden przypadek testowy.';
        if ($type === 'code_fix' && ($cfg['answerMode'] ?? '') === 'line' && empty($cfg['acceptLines'])) {
            $err = 'Podaj numer linii zawierającej błąd.';
        }
        if ($type === 'code_fix' && ($cfg['answerMode'] ?? '') === 'rewrite' && !$cases) {
            $err = 'W trybie przepisania kodu potrzebny jest choć jeden przypadek testowy.';
        }
        if ($err !== null) { flash_set('danger', $err); header('Location: ' . $back); exit; }

        $saved = ti_exam_question_save([
            'exam_id'     => (int)$e['id'],
            'type'        => $type,
            'prompt'      => (string)$_POST['prompt'],
            'points'      => $_POST['points'] ?? 1,
            'in_bank'     => isset($_POST['in_bank']) ? 1 : 0,
            'explanation' => (string)($_POST['explanation'] ?? ''),
            'config'      => $cfg,
            'options'     => $options,
            'cases'       => $cases,
        ], $qid ?: null);

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
