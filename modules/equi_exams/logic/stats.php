<?php
/**
 * modules/equi_exams/logic/stats.php — statystyki zestawu dla prowadzącego
 * (ExamStats.java z exam-engine).
 *
 * Liczone miary:
 *   • łatwość pytania (p) — średni ułamek zdobytych punktów; blisko 1 = zbyt
 *     łatwe, blisko 0 = zbyt trudne albo źle sformułowane,
 *   • moc różnicująca (D) — różnica łatwości między najlepszą i najsłabszą
 *     ćwiartką podejść (klasyczny podział 27%/27%); poniżej 0,2 pytanie nie
 *     odróżnia poziomów, poniżej 0 działa odwrotnie (typowy objaw błędu
 *     w kluczu odpowiedzi),
 *   • rozkład wyników i odsetek zaliczeń dla całego zestawu.
 *
 * UWAGA o zaokrągleniach: w odróżnieniu od Grader/ManualGrading (score/
 * maxScore do 3 miejsc, percent do 2), tutaj WSZYSTKO — łącznie z polami
 * procentowymi (mean/median/min/max/passRate) — zaokrąglane jest do 3
 * miejsc, dokładnie jak w Javie. Nie ujednolicać z resztą silnika.
 */

require_once __DIR__ . '/json_helpers.php';

function ex_stats_round(float $v): float {
    return round($v, 3);
}

function ex_stats_fraction(array $attempt): float {
    $max = ex_cfg_num($attempt, 'maxScore', 0.0);
    return $max > 0 ? ex_cfg_num($attempt, 'score', 0.0) / $max : 0.0;
}

/** Średni ułamek zdobytych punktów za dane pytanie w zbiorze podejść. */
function ex_stats_question_ease(array $attempts, int $qid): float {
    $sum = 0.0; $n = 0;
    foreach ($attempts as $a) {
        foreach (ex_cfg_list($a, 'answers') as $ansRaw) {
            $ans = is_array($ansRaw) ? $ansRaw : [];
            if (ex_cfg_int($ans, 'questionId', -1) !== $qid) continue;
            $max = ex_cfg_num($ans, 'maxPoints', 0.0);
            if ($max <= 0) continue;
            $sum += ex_cfg_num($ans, 'points', 0.0) / $max;
            $n++;
        }
    }
    return $n > 0 ? $sum / $n : 0.0;
}

function ex_stats_count_answered(array $attempts, int $qid): int {
    $n = 0;
    foreach ($attempts as $a) {
        foreach (ex_cfg_list($a, 'answers') as $ansRaw) {
            $ans = is_array($ansRaw) ? $ansRaw : [];
            if (ex_cfg_int($ans, 'questionId', -1) === $qid) $n++;
        }
    }
    return $n;
}

/** Etykieta ostrzeżenia dla prowadzącego — kolejność sprawdzeń ma znaczenie. */
function ex_stats_flag(float $ease, float $disc, int $answered): string {
    if ($answered < 5) return 'insufficient';
    if ($disc < 0)     return 'inverted';
    if ($ease > 0.95)  return 'too_easy';
    if ($ease < 0.20)  return 'too_hard';
    if ($disc < 0.20)  return 'weak';
    return 'ok';
}

function ex_stats_histogram(array $pcts): array {
    $buckets = [0, 0, 0, 0, 0]; // 0–19, 20–39, 40–59, 60–79, 80–100
    foreach ($pcts as $d) {
        $b = (int)floor($d / 20.0);
        if ($b < 0) $b = 0;
        if ($b > 4) $b = 4;
        $buckets[$b]++;
    }
    $labels = ['0–19%', '20–39%', '40–59%', '60–79%', '80–100%'];
    $out = [];
    foreach ($buckets as $i => $count) $out[] = ['label' => $labels[$i], 'count' => $count];
    return $out;
}

function ex_stats_mean(array $v): float {
    if (empty($v)) return 0.0;
    return array_sum($v) / count($v);
}

function ex_stats_median(array $sorted): float {
    $n = count($sorted);
    if ($n === 0) return 0.0;
    if ($n % 2 === 1) return $sorted[intdiv($n, 2)];
    return ($sorted[intdiv($n, 2) - 1] + $sorted[intdiv($n, 2)]) / 2.0;
}

/**
 * Odpowiednik ExamStats.compute(). $req: passPct, questions:[{id,prompt,type,points}],
 * attempts:[{score,maxScore,status,answers:[{questionId,points,maxPoints}]}].
 * Tylko podejścia status="graded" wchodzą do obliczeń.
 */
function ex_stats_compute(array $req): array {
    $passPct = ex_cfg_num($req, 'passPct', 0.0);
    $questions = ex_cfg_list($req, 'questions');

    $attempts = [];
    foreach (ex_cfg_list($req, 'attempts') as $aRaw) {
        $a = is_array($aRaw) ? $aRaw : [];
        if (ex_cfg_str($a, 'status', '') === 'graded') $attempts[] = $a;
    }

    // ── Podsumowanie zestawu ────────────────────────────────────────────────
    $pcts = []; $passed = 0;
    foreach ($attempts as $a) {
        $max = ex_cfg_num($a, 'maxScore', 0.0);
        $pct = $max > 0 ? 100.0 * ex_cfg_num($a, 'score', 0.0) / $max : 0.0;
        $pcts[] = $pct;
        if ($passPct <= 0 || $pct >= $passPct) $passed++;
    }
    $sorted = $pcts;
    sort($sorted);

    $summary = [
        'attempts'  => count($attempts),
        'mean'      => ex_stats_round(ex_stats_mean($pcts)),
        'median'    => ex_stats_round(ex_stats_median($sorted)),
        'min'       => empty($sorted) ? 0.0 : ex_stats_round($sorted[0]),
        'max'       => empty($sorted) ? 0.0 : ex_stats_round($sorted[count($sorted) - 1]),
        'passRate'  => empty($attempts) ? 0.0 : ex_stats_round(100.0 * $passed / count($attempts)),
        'histogram' => ex_stats_histogram($pcts),
    ];

    // ── Ćwiartki do mocy różnicującej (klasyczny podział top/bottom 27%) ────
    $ranked = $attempts;
    usort($ranked, fn($x, $y) => ex_stats_fraction($y) <=> ex_stats_fraction($x));
    $cut = max(1, (int)round(count($ranked) * 0.27));
    $top    = array_slice($ranked, 0, min($cut, count($ranked)));
    $bottom = array_slice($ranked, max(0, count($ranked) - $cut));

    // ── Statystyka per pytanie ────────────────────────────────────────────
    $rows = [];
    foreach ($questions as $qRaw) {
        $q = is_array($qRaw) ? $qRaw : [];
        $qid = ex_cfg_int($q, 'id', 0);

        $p       = ex_stats_question_ease($ranked, $qid);
        $pTop    = ex_stats_question_ease($top, $qid);
        $pBottom = ex_stats_question_ease($bottom, $qid);
        $answered = ex_stats_count_answered($ranked, $qid);

        $rows[] = [
            'questionId'     => $qid,
            'prompt'         => ex_cfg_str($q, 'prompt', ''),
            'type'           => ex_cfg_str($q, 'type', ''),
            'points'         => ex_cfg_num($q, 'points', 0.0),
            'answered'       => $answered,
            'ease'           => ex_stats_round($p),
            'discrimination' => ex_stats_round($pTop - $pBottom),
            'flag'           => ex_stats_flag($p, $pTop - $pBottom, $answered),
        ];
    }

    return ['summary' => $summary, 'questions' => $rows];
}
