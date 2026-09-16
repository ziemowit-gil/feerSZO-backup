<?php
/**
 * modules/equi_exams/logic/grading.php — ocenianie odpowiedzi (Grader.java +
 * model/Question.java + per-typ ocenianie z exam-engine, Java).
 *
 * Pytania kodu (code_fix/code_completion/code_run) są dopięte do dispatchera
 * ex_grade_question() TUTAJ, ale ich implementacja (ex_grade_code_fix/
 * ex_grade_code_completion/ex_grade_code_run) żyje w code_grading.php — ten
 * plik i code_grading.php muszą być wymagane razem (patrz faza 9: podpięcie
 * w ti_exams.php ładuje oba).
 */

require_once __DIR__ . '/json_helpers.php';
require_once __DIR__ . '/text_match.php';

// ═══════════════════════════════════════════════════════════════════════════
//  Answer.java — dostęp do surowej odpowiedzi kursanta
// ═══════════════════════════════════════════════════════════════════════════

/** Odpowiednik Answer.fromJson: mapa → jak jest, string → {"text":…}, inaczej → puste. */
function ex_answer_from_raw($raw): array {
    if (is_array($raw)) return $raw;
    if (is_string($raw)) return ['text' => $raw];
    return [];
}

function ex_answer_option_ids(array $answer): array {
    $v = array_key_exists('optionIds', $answer) ? $answer['optionIds'] : ($answer['option_ids'] ?? null);
    $out = [];
    if (is_int($v) || is_float($v)) { $out[] = (int)round($v); return $out; }
    if (is_string($v)) {
        foreach (explode(',', $v) as $part) {
            $p = trim($part);
            if ($p !== '' && preg_match('/^-?\d+$/', $p)) $out[] = (int)$p;
        }
        return $out;
    }
    if (is_array($v)) {
        foreach ($v as $o) {
            if ($o === null) continue;
            $s = trim((string)$o);
            if (is_numeric($s)) $out[] = (int)round((float)$s);
        }
    }
    return $out;
}

function ex_answer_text(array $answer): string  { return ex_cfg_str($answer, 'text', ''); }
function ex_answer_code(array $answer): string  { return ex_cfg_str($answer, 'code', ''); }
function ex_answer_line(array $answer): int     { return ex_cfg_int($answer, 'line', -1); }

function ex_answer_blanks(array $answer): array {
    $out = [];
    foreach (ex_cfg_map($answer, 'blanks') as $k => $v) $out[(string)$k] = $v === null ? '' : (string)$v;
    return $out;
}

function ex_answer_statements(array $answer): array {
    $out = [];
    foreach (ex_cfg_map($answer, 'statements') as $k => $v) {
        if ($v === null) continue;
        $out[(string)$k] = ex_cfg_bool(['v' => $v], 'v', false);
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
//  GradingContext.java
// ═══════════════════════════════════════════════════════════════════════════

function ex_grading_context(string $mode, string $negMarking, bool $revealAnswers, bool $sandboxEnabled = true): array {
    return [
        'mode'           => $mode !== '' ? $mode : 'exam',
        'negMarking'     => $negMarking !== '' ? $negMarking : 'partial',
        'revealAnswers'  => $revealAnswers,
        'sandboxEnabled' => $sandboxEnabled,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Question.java — pola wspólne
// ═══════════════════════════════════════════════════════════════════════════

/** Odpowiednik Question.fromJson: points domyślnie 1.0, nigdy ujemne. */
function ex_question_points(array $question): float {
    $p = ex_cfg_num($question, 'points', 1.0);
    return $p < 0 ? 0.0 : $p;
}

/** Odpowiednik Option.fromJson — isCorrect z domyślną wartością odczytaną z "correct". */
function ex_option_from_raw(array $o): array {
    $correctDefault = ex_cfg_bool($o, 'correct', false);
    return [
        'id'       => ex_cfg_int($o, 'id', 0),
        'label'    => ex_cfg_str($o, 'label', ''),
        'correct'  => ex_cfg_bool($o, 'isCorrect', $correctDefault),
        'feedback' => ex_cfg_str($o, 'feedback', ''),
    ];
}

function ex_question_options(array $question): array {
    return array_map('ex_option_from_raw', ex_cfg_list($question, 'options'));
}

/** Odpowiednik Question.correctOptions() — wspólne dla single/multi. */
function ex_correct_answer_ids(array $options): array {
    $ids = [];
    foreach ($options as $o) if ($o['correct']) $ids[] = $o['id'];
    return ['correctOptionIds' => $ids];
}

// ═══════════════════════════════════════════════════════════════════════════
//  QuestionResult.java
// ═══════════════════════════════════════════════════════════════════════════

function ex_qr_new(array $question): array {
    return [
        'questionId'  => ex_cfg_int($question, 'id', 0),
        'type'        => ex_cfg_str($question, 'type', 'single'),
        'points'      => 0.0,
        'maxPoints'   => ex_question_points($question),
        'correct'     => false,
        'needsReview' => false,
        'feedback'    => '',
        'explanation' => ex_cfg_str($question, 'explanation', ''),
        'details'     => [],
    ];
}

/** Przycina punktację do [0, maxPoints] — odpowiednik QuestionResult.award(). */
function ex_qr_award(array $r, float $raw): array {
    $v = $raw;
    if (is_nan($v) || $v < 0) $v = 0.0;
    if ($v > $r['maxPoints']) $v = $r['maxPoints'];
    $r['points']  = round($v, 3);
    $r['correct'] = $r['maxPoints'] > 0 && $r['points'] >= $r['maxPoints'] - 1e-9;
    return $r;
}

function ex_qr_feedback(array $r, string $f): array {
    $r['feedback'] = $f;
    return $r;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Ocenianie per typ pytania
// ═══════════════════════════════════════════════════════════════════════════

function ex_grade_single(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $options = ex_question_options($question);
    $picked  = ex_answer_option_ids($answer);

    if (empty($picked)) return ex_qr_feedback(ex_qr_award($r, 0), 'Brak odpowiedzi.');
    if (count($picked) > 1) {
        return ex_qr_feedback(ex_qr_award($r, 0), 'Wskazano więcej niż jedną odpowiedź — pytanie dopuszcza jedną.');
    }

    $pick = $picked[0];
    $hit = false; $optFeedback = '';
    foreach ($options as $o) {
        if ($o['id'] === $pick) { $hit = $o['correct']; $optFeedback = $o['feedback']; break; }
    }
    $r = ex_qr_award($r, $hit ? ex_question_points($question) : 0.0);
    $r = ex_qr_feedback($r, $optFeedback !== '' ? $optFeedback : ($hit ? 'Odpowiedź poprawna.' : 'Odpowiedź niepoprawna.'));
    if (!empty($ctx['revealAnswers'])) $r['details'] = array_merge($r['details'], ex_correct_answer_ids($options));
    return $r;
}

function ex_grade_multi(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $options = ex_question_options($question);
    $correctOpts = array_values(array_filter($options, fn($o) => $o['correct']));

    if (empty($correctOpts)) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Pytanie nie ma wskazanej poprawnej odpowiedzi — do sprawdzenia przez prowadzącego.');
    }

    $picked = array_values(array_unique(ex_answer_option_ids($answer)));
    if (empty($picked)) return ex_qr_feedback(ex_qr_award($r, 0), 'Brak odpowiedzi.');

    $correctIds = array_map(fn($o) => $o['id'], $correctOpts);
    $allIds     = array_map(fn($o) => $o['id'], $options);

    $hits = 0; $misses = 0;
    foreach ($picked as $id) {
        if (!in_array($id, $allIds, true)) continue; // wariant spoza pytania — ignorujemy
        if (in_array($id, $correctIds, true)) $hits++; else $misses++;
    }

    $config = ex_cfg_map($question, 'config');
    $rule   = ex_cfg_str($config, 'negMarking', $ctx['negMarking']);
    $totalCorrect = count($correctIds);
    $points = ex_question_points($question);

    if ($rule === 'all_or_nothing') {
        $score = ($hits === $totalCorrect && $misses === 0) ? $points : 0.0;
    } elseif ($rule === 'none') {
        $score = $points * ($hits / $totalCorrect);
    } else { // partial — z karą za zgadywanie
        $score = $points * max(0.0, ($hits - $misses) / $totalCorrect);
    }

    $r = ex_qr_award($r, $score);
    $r['details']['hits']         = $hits;
    $r['details']['misses']       = $misses;
    $r['details']['totalCorrect'] = $totalCorrect;
    $r['details']['negMarking']   = $rule;
    $r = ex_qr_feedback($r, ex_multi_feedback($hits, $misses, $totalCorrect, $rule));
    if (!empty($ctx['revealAnswers'])) $r['details'] = array_merge($r['details'], ex_correct_answer_ids($options));
    return $r;
}

function ex_multi_feedback(int $hits, int $misses, int $total, string $rule): string {
    if ($hits === $total && $misses === 0) return 'Wszystkie poprawne warianty zaznaczone.';
    $s = "Trafione {$hits} z {$total}";
    if ($misses > 0) {
        $s .= ", błędnie zaznaczone: {$misses}";
        if ($rule === 'partial') $s .= ' (obniża punktację)';
    }
    return $s . '.';
}

function ex_grade_truefalse(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $options = ex_question_options($question);
    if (empty($options)) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Pytanie nie zawiera twierdzeń — do sprawdzenia przez prowadzącego.');
    }

    $given = ex_answer_statements($answer);
    $hits = 0; $misses = 0; $skipped = 0; $rows = [];
    foreach ($options as $o) {
        $key = (string)$o['id'];
        $row = ['optionId' => $o['id']];
        if (!array_key_exists($key, $given)) {
            $skipped++;
            $row['answered'] = false;
            $row['correct']  = false;
        } else {
            $v  = $given[$key];
            $ok = $v === $o['correct'];
            if ($ok) $hits++; else $misses++;
            $row['answered'] = true;
            $row['given']    = $v;
            $row['correct']  = $ok;
        }
        if (!empty($ctx['revealAnswers'])) $row['expected'] = $o['correct'];
        $rows[] = $row;
    }

    $config = ex_cfg_map($question, 'config');
    $rule   = ex_cfg_str($config, 'negMarking', $ctx['negMarking']);
    $total  = count($options);
    $points = ex_question_points($question);

    if ($rule === 'all_or_nothing') {
        $score = ($hits === $total) ? $points : 0.0;
    } elseif ($rule === 'none') {
        $score = $points * ($hits / $total);
    } else {
        $score = $points * max(0.0, ($hits - $misses) / $total);
    }

    $r = ex_qr_award($r, $score);
    $r['details']['statements'] = $rows;
    $r['details']['hits']       = $hits;
    $r['details']['misses']     = $misses;
    $r['details']['skipped']    = $skipped;
    return ex_qr_feedback($r, "Poprawnie ocenione twierdzenia: {$hits} z {$total}"
        . ($skipped > 0 ? " (pominięto {$skipped})" : '') . '.');
}

/** Wspólna ocena luk — używana przez fill_blank i (w code_grading.php) code_completion. */
function ex_score_blanks(array $questionConfig, array $blanksConfig, array $given, bool $revealAnswers): array {
    $hits = 0; $gained = 0.0; $totalWeight = 0.0; $rows = [];
    foreach ($blanksConfig as $i => $bRaw) {
        $b = is_array($bRaw) ? $bRaw : [];
        $key = ex_cfg_str($b, 'key', (string)($i + 1));
        $w = ex_cfg_num($b, 'points', 1.0);
        if ($w <= 0) $w = 1.0;
        $totalWeight += $w;

        // Ustawienia normalizacji: najpierw pytanie, luka nadpisuje.
        $norm = array_merge($questionConfig, $b);

        $value = $given[$key] ?? ($given[(string)($i + 1)] ?? null);
        $accept = ex_cfg_strings($b, 'accept');
        $ok = $value !== null && ex_text_matches_any($value, $accept, $norm);
        if ($ok) { $hits++; $gained += $w; }

        $row = ['key' => $key, 'given' => $value ?? '', 'correct' => $ok];
        if ($revealAnswers) $row['accept'] = $accept;
        $rows[] = $row;
    }
    return ['hits' => $hits, 'gained' => $gained, 'totalWeight' => $totalWeight, 'rows' => $rows];
}

function ex_grade_fill_blank(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $config = ex_cfg_map($question, 'config');
    $blanks = ex_cfg_list($config, 'blanks');

    if (empty($blanks)) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Pytanie nie ma zdefiniowanych luk — do sprawdzenia przez prowadzącego.');
    }

    $given = ex_answer_blanks($answer);
    $bs = ex_score_blanks($config, $blanks, $given, !empty($ctx['revealAnswers']));

    $allOrNothing = ex_cfg_bool($config, 'allOrNothing', false);
    $points = ex_question_points($question);
    $score = $allOrNothing
        ? ($bs['hits'] === count($blanks) ? $points : 0.0)
        : ($bs['totalWeight'] > 0 ? $points * ($bs['gained'] / $bs['totalWeight']) : 0.0);

    $r = ex_qr_award($r, $score);
    $r['details']['blanks'] = $bs['rows'];
    $r['details']['filled'] = $bs['hits'];
    $r['details']['total']  = count($blanks);
    return ex_qr_feedback($r, "Uzupełnione poprawnie: {$bs['hits']} z " . count($blanks) . '.');
}

function ex_grade_short_answer(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $text   = ex_answer_text($answer);
    $config = ex_cfg_map($question, 'config');
    $criteria = ex_cfg_list($config, 'keywords');
    $manual   = ex_cfg_bool($config, 'manual', false);
    $minChars = ex_cfg_int($config, 'minChars', 0);
    $points   = ex_question_points($question);

    if (trim($text) === '') {
        return ex_qr_feedback(ex_qr_award($r, 0), 'Brak odpowiedzi.');
    }
    if ($minChars > 0 && mb_strlen(trim($text), 'UTF-8') < $minChars) {
        $r['needsReview'] = $manual;
        return ex_qr_feedback(ex_qr_award($r, 0), "Odpowiedź jest krótsza niż wymagane {$minChars} znaków.");
    }
    if (empty($criteria)) {
        $r['needsReview'] = true;
        $r['details']['suggestedPoints'] = 0.0;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Odpowiedź czeka na ocenę prowadzącego.');
    }

    $gained = 0.0; $total = 0.0; $missingRequired = false; $rows = [];
    foreach ($criteria as $cRaw) {
        $c = is_array($cRaw) ? $cRaw : [];
        $w = ex_cfg_num($c, 'points', 1.0);
        if ($w <= 0) $w = 1.0;
        $total += $w;

        $norm = array_merge($config, $c);
        $any = ex_cfg_strings($c, 'any');
        if (empty($any)) $any = ex_cfg_strings($c, 'keywords');

        $hit = ex_text_contains_any($text, $any, $norm);
        if ($hit) $gained += $w;
        elseif (ex_cfg_bool($c, 'required', false)) $missingRequired = true;

        $row = ['label' => ex_cfg_str($c, 'label', empty($any) ? 'kryterium' : $any[0]), 'met' => $hit];
        if (!empty($ctx['revealAnswers'])) $row['any'] = $any;
        $rows[] = $row;
    }

    $auto = ($missingRequired || $total <= 0) ? 0.0 : $points * ($gained / $total);
    $r['details']['criteria']        = $rows;
    $r['details']['suggestedPoints'] = round($auto, 3);
    $r['details']['missingRequired'] = $missingRequired;

    if ($manual) {
        $r['needsReview'] = true;
        $r = ex_qr_award($r, 0);
        return ex_qr_feedback($r, 'Odpowiedź czeka na ocenę prowadzącego (wstępna punktacja automatu: '
            . ex_short_answer_fmt($auto) . ' / ' . ex_short_answer_fmt($points) . ').');
    }

    $r = ex_qr_award($r, $auto);
    if ($missingRequired) return ex_qr_feedback($r, 'W odpowiedzi brakuje kryterium wymaganego w tym pytaniu.');
    $met = count(array_filter($rows, fn($row) => $row['met']));
    return ex_qr_feedback($r, "Spełnione kryteria: {$met} z " . count($criteria) . '.');
}

function ex_short_answer_fmt(float $d): string {
    if ($d == round($d)) return (string)(int)round($d);
    return (string)(round($d * 100) / 100);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Dispatch + Grader.java (ocenianie całego podejścia)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Ocenia jedno pytanie z izolacją błędów — odpowiednik pętli w Grader.grade()
 * (jedno wadliwe pytanie nigdy nie wywraca całego podejścia).
 */
function ex_grade_question(array $question, $answerRaw, array $ctx): array {
    try {
        $answer = ex_answer_from_raw($answerRaw);
        $type = ex_cfg_str($question, 'type', 'single');
        switch ($type) {
            case 'multi':           return ex_grade_multi($question, $answer, $ctx);
            case 'truefalse':       return ex_grade_truefalse($question, $answer, $ctx);
            case 'fill_blank':      return ex_grade_fill_blank($question, $answer, $ctx);
            case 'short_answer':    return ex_grade_short_answer($question, $answer, $ctx);
            case 'code_fix':        return ex_grade_code_fix($question, $answer, $ctx);
            case 'code_completion': return ex_grade_code_completion($question, $answer, $ctx);
            case 'code_run':        return ex_grade_code_run($question, $answer, $ctx);
            case 'single':
            default:                return ex_grade_single($question, $answer, $ctx);
        }
    } catch (\Throwable $e) {
        $r = ex_qr_award(ex_qr_new($question), 0);
        $r['needsReview'] = true;
        return ex_qr_feedback($r, 'Błąd oceniania pytania: ' . get_class($e)
            . ($e->getMessage() !== '' ? ' — ' . $e->getMessage() : ''));
    }
}

/**
 * Odpowiednik Grader.grade() — ocenia całe podejście. $req: mode, negMarking,
 * revealAnswers, passPct, questions[], answers{questionId: …}.
 */
function ex_grade_attempt(array $req): array {
    $mode          = ex_cfg_str($req, 'mode', 'exam');
    $negMarking    = ex_cfg_str($req, 'negMarking', 'partial');
    $revealAnswers = ex_cfg_has($req, 'revealAnswers')
        ? ex_cfg_bool($req, 'revealAnswers', false)
        : ($mode === 'training');
    $passPct   = ex_cfg_num($req, 'passPct', 0.0);
    $questions = ex_cfg_list($req, 'questions');
    $answersMap = ex_cfg_map($req, 'answers');

    $ctx = ex_grading_context($mode, $negMarking, $revealAnswers);

    $score = 0.0; $maxScore = 0.0; $needsReview = false;
    $results = [];
    foreach ($questions as $qRaw) {
        $question = is_array($qRaw) ? $qRaw : [];
        $qid = ex_cfg_int($question, 'id', 0);
        $answerRaw = $answersMap[(string)$qid] ?? null;

        $result = ex_grade_question($question, $answerRaw, $ctx);
        if (!$ctx['revealAnswers']) $result['explanation'] = '';

        $score       += $result['points'];
        $maxScore    += $result['maxPoints'];
        $needsReview = $needsReview || $result['needsReview'];
        $results[]   = $result;
    }

    $percent = $maxScore > 0 ? 100 * $score / $maxScore : 0.0;
    return [
        'score'       => round($score, 3),
        'maxScore'    => round($maxScore, 3),
        'percent'     => round($percent, 2),
        'passed'      => !$needsReview && ($passPct <= 0 || $percent >= $passPct),
        'needsReview' => $needsReview,
        'mode'        => $ctx['mode'],
        'results'     => $results,
    ];
}
