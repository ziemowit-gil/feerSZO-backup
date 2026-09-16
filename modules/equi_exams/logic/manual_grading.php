<?php
/**
 * modules/equi_exams/logic/manual_grading.php — ocenianie ręczne pytań
 * opisowych (ManualGrading.java z exam-engine).
 *
 * Silnik decyduje o: przycięciu punktów do zakresu pytania, uznaniu
 * odpowiedzi za poprawną, zdjęciu znacznika „do sprawdzenia", przeliczeniu
 * sumy podejścia i o tym, czy praca jest już w pełni oceniona i zaliczona.
 */

require_once __DIR__ . '/json_helpers.php';
require_once __DIR__ . '/authoring.php'; // ex_form_clamp/ex_form_decimal

/** Wstawiany, gdy prowadzący nie napisał komentarza — znacznik decyzji człowieka. */
const EX_MANUAL_GRADING_DEFAULT_NOTE = 'Ocena prowadzącego';

function ex_manual_grading_round(float $v): float {
    return round($v, 3);
}

/**
 * Odpowiednik ManualGrading.apply(). $req: passPct, questions:[{id,points}],
 * answers:[{questionId,points?,needsReview?,maxPoints?}], marks:{qid:"pts"}, notes:{qid:"…"}.
 */
function ex_manual_grading_apply(array $req): array {
    $passPct = ex_cfg_num($req, 'passPct', 0.0);

    $maxByQuestion = [];
    foreach (ex_cfg_list($req, 'questions') as $qRaw) {
        $q = is_array($qRaw) ? $qRaw : [];
        $maxByQuestion[ex_cfg_int($q, 'id', 0)] = ex_cfg_num($q, 'points', 0.0);
    }

    $marks = ex_cfg_map($req, 'marks');
    $notes = ex_cfg_map($req, 'notes');

    $rows = [];
    $score = 0.0; $maxScore = 0.0; $stillPending = false;

    foreach (ex_cfg_list($req, 'answers') as $aRaw) {
        $a = is_array($aRaw) ? $aRaw : [];
        $qid = ex_cfg_int($a, 'questionId', 0);
        $key = (string)$qid;
        $max = array_key_exists($qid, $maxByQuestion) ? $maxByQuestion[$qid] : ex_cfg_num($a, 'maxPoints', 0.0);
        $maxScore += $max;

        $hasMark = array_key_exists($key, $marks) && trim(ex_cfg_str($marks, $key, '')) !== '';
        $note = trim(ex_cfg_str($notes, $key, ''));
        $changed = false;

        if ($hasMark) {
            $points = ex_form_clamp(ex_form_decimal($marks, $key, 0.0), 0, $max);
            $review = false;
            $changed = true;
            if ($note === '') $note = EX_MANUAL_GRADING_DEFAULT_NOTE;
        } elseif (ex_cfg_has($a, 'points')) {
            $points = ex_form_clamp(ex_cfg_num($a, 'points', 0.0), 0, $max);
            $review = ex_cfg_bool($a, 'needsReview', false);
        } else {
            $points = 0.0;
            $review = true;
        }

        if ($review) $stillPending = true;
        $score += $review ? 0.0 : $points;

        $rows[] = [
            'questionId'  => $qid,
            'points'      => $review ? null : ex_manual_grading_round($points),
            'maxPoints'   => ex_manual_grading_round($max),
            'correct'     => !$review && $max > 0 && $points >= $max - 1e-9,
            'needsReview' => $review,
            'note'        => $note,
            'changed'     => $changed,
        ];
    }

    $pct = $maxScore > 0 ? (100.0 * $score / $maxScore) : 0.0;

    $attempt = [
        'score'       => ex_manual_grading_round($score),
        'maxScore'    => ex_manual_grading_round($maxScore),
        'percent'     => round($pct, 2),
        'needsReview' => $stillPending,
        'status'      => $stillPending ? 'submitted' : 'graded',
        'passed'      => !$stillPending && ($passPct <= 0 || $pct >= $passPct),
    ];

    return ['answers' => $rows, 'attempt' => $attempt];
}
