<?php
/**
 * modules/equi_exams/logic/code_grading.php — ocenianie pytań z kodem
 * (CodeGrading.java + Code{Fix,Completion,Run}Question.java z exam-engine).
 *
 * Dopina do dispatchera ex_grade_question() (grading.php) funkcje
 * ex_grade_code_fix/ex_grade_code_completion/ex_grade_code_run. Musi być
 * wymagany RAZEM z grading.php i sandbox.php (patrz nagłówek grading.php).
 */

require_once __DIR__ . '/json_helpers.php';
require_once __DIR__ . '/text_match.php';
require_once __DIR__ . '/sandbox.php';
require_once __DIR__ . '/grading.php';

/** Odpowiednik TestCase.fromJson. */
function ex_testcase_from_raw(array $m, int $index): array {
    $name = ex_cfg_str($m, 'name', '');
    $weight = ex_cfg_num($m, 'weight', 1.0);
    $matchMode = ex_cfg_str($m, 'matchMode', 'trim');
    return [
        'index'     => $index,
        'name'      => $name !== '' ? $name : ('Przypadek ' . ($index + 1)),
        'stdin'     => ex_cfg_str($m, 'stdin', ''),
        'expected'  => ex_cfg_has($m, 'expected') ? ex_cfg_str($m, 'expected', '') : ex_cfg_str($m, 'expectedStdout', ''),
        'matchMode' => $matchMode !== '' ? $matchMode : 'trim',
        'weight'    => $weight > 0 ? $weight : 1.0,
        'hidden'    => ex_cfg_bool($m, 'hidden', false),
        'tolerance' => ex_cfg_num($m, 'tolerance', 0.000001),
    ];
}

function ex_question_cases(array $question): array {
    $out = [];
    foreach (ex_cfg_list($question, 'cases') as $i => $c) {
        $out[] = ex_testcase_from_raw(is_array($c) ? $c : [], $i);
    }
    return $out;
}

/**
 * Sprawdza wzorce treści kodu (forbidden/required).
 * $mustNotMatch: true = lista zakazana, false = lista wymagana.
 * Zwraca komunikat naruszenia albo null gdy w porządku.
 */
function ex_code_check_patterns(string $code, array $patterns, bool $mustNotMatch): ?string {
    foreach ($patterns as $p) {
        if ($p === null) continue;
        $p = (string)$p;
        if (trim($p) === '') continue;
        $r = @preg_match('~' . $p . '~uis', $code);
        $found = ($r === false)
            ? str_contains(mb_strtolower($code, 'UTF-8'), mb_strtolower($p, 'UTF-8'))
            : ($r === 1);
        if ($mustNotMatch && $found)   return 'Rozwiązanie używa konstrukcji wykluczonej w tym zadaniu.';
        if (!$mustNotMatch && !$found) return 'Rozwiązanie nie zawiera konstrukcji wymaganej w tym zadaniu.';
    }
    return null;
}

/** Odpowiednik CodeGrading.runCases — uruchamia kod na wszystkich przypadkach testowych. */
function ex_code_run_cases(array $question, string $languageId, string $code, array $cases, array $ctx): array {
    $out = [
        'fraction' => 0.0, 'compiled' => true, 'compileError' => '', 'engineError' => '',
        'blocked' => false, 'blockReason' => '', 'rows' => [], 'passed' => 0, 'total' => count($cases),
    ];

    if (trim($code) === '') {
        $out['blocked'] = true;
        $out['blockReason'] = 'Nie przesłano kodu.';
        return $out;
    }

    $config = ex_cfg_map($question, 'config');
    $block = ex_code_check_patterns($code, ex_cfg_strings($config, 'forbidden'), true);
    if ($block === null) $block = ex_code_check_patterns($code, ex_cfg_strings($config, 'required'), false);
    if ($block !== null) {
        $out['blocked'] = true;
        $out['blockReason'] = $block;
        return $out;
    }

    if (empty($ctx['sandboxEnabled'])) {
        $out['engineError'] = 'Piaskownica jest niedostępna — zadanie wymaga ręcznej oceny.';
        return $out;
    }
    $lang = ex_lang_by_id($languageId);
    if (!$lang) {
        $out['engineError'] = 'Nieobsługiwany język zadania: ' . $languageId;
        return $out;
    }
    if (!ex_sandbox_available($lang['id'])) {
        $out['engineError'] = 'W obrazie silnika brakuje narzędzi dla języka: ' . $lang['label'];
        return $out;
    }
    if (empty($cases)) {
        $out['engineError'] = 'Zadanie nie ma przypadków testowych — wymaga oceny prowadzącego.';
        return $out;
    }

    $timeoutMs = ex_cfg_int($config, 'timeLimitMs', 3000);
    $memoryMb  = ex_cfg_int($config, 'memoryMb', 128);

    $gained = 0.0; $totalWeight = 0.0;
    foreach ($cases as $tc) {
        $totalWeight += $tc['weight'];
        $rr = ex_sandbox_run($lang['id'], $code, $tc['stdin'], $timeoutMs, $memoryMb);

        if ($rr['engineError'] !== '') {
            $out['engineError'] = $rr['engineError'];
            return $out;
        }
        if (!$rr['compiled']) {
            $out['compiled']     = false;
            $out['compileError'] = $rr['compileError'];
            $out['fraction']     = 0.0;
            $out['rows']         = [];
            return $out;
        }

        $passed = $rr['exitCode'] === 0 && !$rr['timedOut'] && ex_text_output_matches($rr['stdout'], $tc);
        if ($passed) { $gained += $tc['weight']; $out['passed']++; }

        $row = [
            'index' => $tc['index'], 'name' => $tc['name'], 'passed' => $passed,
            'hidden' => $tc['hidden'], 'timedOut' => $rr['timedOut'], 'exitCode' => $rr['exitCode'],
            'timeMs' => $rr['durationMs'],
        ];
        // Treść wejścia/wyjścia pokazujemy tylko dla przypadków jawnych — ukryte
        // służą do wykrywania rozwiązań „pod testy" i pozostają tajne.
        if (!$tc['hidden']) {
            $row['stdin']  = ex_text_cap($tc['stdin'], 2000);
            $row['stdout'] = ex_text_cap($rr['stdout'], 4000);
            $row['stderr'] = ex_text_cap($rr['stderr'], 2000);
            if (!empty($ctx['revealAnswers'])) $row['expected'] = ex_text_cap($tc['expected'], 4000);
        }
        $out['rows'][] = $row;
    }

    $out['fraction'] = $totalWeight > 0 ? $gained / $totalWeight : 0.0;
    return $out;
}

/** Odpowiednik CodeGrading.apply — przenosi wynik przebiegu do wyniku pytania. */
function ex_code_grading_apply(array $outcome, array $question, array $r): array {
    $r['details']['cases']  = $outcome['rows'];
    $r['details']['passed'] = $outcome['passed'];
    $r['details']['total']  = $outcome['total'];

    if ($outcome['blocked']) {
        return ex_qr_feedback(ex_qr_award($r, 0), $outcome['blockReason']);
    }
    if ($outcome['engineError'] !== '') {
        $r['needsReview'] = true;
        $r = ex_qr_feedback(ex_qr_award($r, 0), $outcome['engineError']);
        $r['details']['engineError'] = $outcome['engineError'];
        return $r;
    }
    if (!$outcome['compiled']) {
        $r['details']['compileError'] = $outcome['compileError'];
        return ex_qr_feedback(ex_qr_award($r, 0), 'Kod nie kompiluje się — zobacz komunikat kompilatora.');
    }

    $config = ex_cfg_map($question, 'config');
    $allOrNothing = ex_cfg_bool($config, 'allOrNothing', false);
    $points = ex_question_points($question);
    $score = $allOrNothing
        ? ($outcome['passed'] === $outcome['total'] ? $points : 0.0)
        : $points * $outcome['fraction'];
    $r = ex_qr_award($r, $score);
    return ex_qr_feedback($r, "Zdane przypadki testowe: {$outcome['passed']} z {$outcome['total']}.");
}

// ═══════════════════════════════════════════════════════════════════════════
//  code_run — uruchomienie na przypadkach testowych
// ═══════════════════════════════════════════════════════════════════════════

function ex_grade_code_run(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $config = ex_cfg_map($question, 'config');
    $lang = ex_cfg_str($config, 'language', 'python');
    $r['details']['language'] = $lang;

    $outcome = ex_code_run_cases($question, $lang, ex_answer_code($answer), ex_question_cases($question), $ctx);
    return ex_code_grading_apply($outcome, $question, $r);
}

// ═══════════════════════════════════════════════════════════════════════════
//  code_fix — wskazanie linii albo przepisanie kodu
// ═══════════════════════════════════════════════════════════════════════════

function ex_code_fix_accepted_lines(array $config): array {
    $out = [];
    foreach (ex_cfg_list($config, 'acceptLines') as $o) {
        if ($o === null) continue;
        $s = trim((string)$o);
        if (is_numeric($s)) $out[] = (int)round((float)$s);
    }
    $single = ex_cfg_int($config, 'correctLine', 0);
    if ($single > 0 && !in_array($single, $out, true)) $out[] = $single;
    return $out;
}

function ex_grade_code_fix(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $config = ex_cfg_map($question, 'config');
    $lang = ex_cfg_str($config, 'language', 'python');
    $r['details']['language'] = $lang;

    if (ex_cfg_str($config, 'answerMode', 'line') === 'rewrite') {
        $outcome = ex_code_run_cases($question, $lang, ex_answer_code($answer), ex_question_cases($question), $ctx);
        return ex_code_grading_apply($outcome, $question, $r);
    }

    // ── Tryb „wskaż błędną linię" ────────────────────────────────────────────
    $accept = ex_code_fix_accepted_lines($config);
    $given  = ex_answer_line($answer);
    $r['details']['givenLine'] = $given;
    if (!empty($ctx['revealAnswers'])) $r['details']['acceptLines'] = $accept;

    if (empty($accept)) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Pytanie nie ma wskazanej błędnej linii — do sprawdzenia przez prowadzącego.');
    }
    if ($given <= 0) return ex_qr_feedback(ex_qr_award($r, 0), 'Nie wskazano żadnej linii.');

    $hit = in_array($given, $accept, true);
    $needExplanation = ex_cfg_bool($config, 'requireExplanation', false);
    $points = ex_question_points($question);

    if (!$needExplanation) {
        $r = ex_qr_award($r, $hit ? $points : 0.0);
        return ex_qr_feedback($r, $hit ? 'Wskazana linia jest tą z błędem.' : 'To nie jest linia zawierająca błąd.');
    }

    // Uzasadnienie ocenia prowadzący; trafienie linii idzie jako podpowiedź.
    $suggested = $hit ? $points / 2.0 : 0.0;
    $r['needsReview'] = true;
    $r['details']['explanation']     = ex_text_cap(ex_answer_text($answer), 4000);
    $r['details']['lineCorrect']     = $hit;
    $r['details']['suggestedPoints'] = round($suggested, 3);
    $r = ex_qr_award($r, 0);
    return ex_qr_feedback($r, $hit
        ? 'Linia wskazana poprawnie — uzasadnienie czeka na ocenę prowadzącego.'
        : 'Wskazana linia jest inna niż oczekiwana — uzasadnienie czeka na ocenę prowadzącego.');
}

// ═══════════════════════════════════════════════════════════════════════════
//  code_completion — luki w kodzie, opcjonalnie z uruchomieniem po złożeniu
// ═══════════════════════════════════════════════════════════════════════════

/** Podstawia odpowiedzi kursanta w miejsca znaczników ___klucz___ / [[klucz]] w szablonie. */
function ex_code_completion_assemble(string $template, array $blanksConfig, array $given): string {
    $out = $template;
    foreach ($blanksConfig as $i => $bRaw) {
        $b = is_array($bRaw) ? $bRaw : [];
        $key = ex_cfg_str($b, 'key', (string)($i + 1));
        $val = $given[$key] ?? ($given[(string)($i + 1)] ?? '');
        $out = str_replace('___' . $key . '___', $val, $out);
        $out = str_replace('[[' . $key . ']]', $val, $out);
    }
    return $out;
}

function ex_grade_code_completion(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $config = ex_cfg_map($question, 'config');
    $lang = ex_cfg_str($config, 'language', 'python');
    $r['details']['language'] = $lang;

    $blanks = ex_cfg_list($config, 'blanks');
    $given  = ex_answer_blanks($answer);
    $cases  = ex_question_cases($question);

    $run = ex_cfg_bool($config, 'runAfterFill', false) && !empty($cases);
    if ($run) {
        $assembled = ex_code_completion_assemble(ex_cfg_str($config, 'template', ''), $blanks, $given);
        $r['details']['assembledCode'] = ex_text_cap($assembled, 8000);
        $outcome = ex_code_run_cases($question, $lang, $assembled, $cases, $ctx);
        return ex_code_grading_apply($outcome, $question, $r);
    }

    if (empty($blanks)) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Zadanie nie ma zdefiniowanych luk — do sprawdzenia przez prowadzącego.');
    }

    $bs = ex_score_blanks($config, $blanks, $given, !empty($ctx['revealAnswers']));
    $allOrNothing = ex_cfg_bool($config, 'allOrNothing', false);
    $points = ex_question_points($question);
    $score = $allOrNothing
        ? ($bs['hits'] === count($blanks) ? $points : 0.0)
        : ($bs['totalWeight'] > 0 ? $points * ($bs['gained'] / $bs['totalWeight']) : 0.0);

    $r = ex_qr_award($r, $score);
    $r['details']['blanks'] = $bs['rows'];
    return ex_qr_feedback($r, "Poprawnie uzupełnione luki: {$bs['hits']} z " . count($blanks) . '.');
}
