<?php
/**
 * modules/equi_exams/logic/generator.php — kompozycja wariantu zestawu dla
 * podejścia (ExamGenerator.java z exam-engine).
 *
 * UWAGA o losowości: PHP (Mersenne Twister, mt_srand) i Java (java.util.Random,
 * LCG) to RÓŻNE generatory — to samo ziarno NIE odtworzy wariantu, jaki dawał
 * kiedyś silnik Java. To nieistotne dla poprawności: wariant (`drawn_ids`/
 * `option_order`) jest zapisywany RAZ przy starcie podejścia
 * (ti_exam_start_attempt → k30_ti_exam_attempts) i nigdy nie jest odtwarzany
 * ponownie z samego ziarna — ziarno to tylko zapis/ślad. Liczy się WYŁĄCZNIE
 * wewnętrzna powtarzalność PHP (to samo ziarno → ten sam wariant w PHP,
 * zawsze), a tę mt_srand()+shuffle() dają — dokładnie tak, jak już robił
 * istniejący ti_exam_compose_fallback() w includes/ti_exams.php (ten plik go
 * zastępuje jako JEDYNĄ ścieżkę, nie fallback, i naprawia różnicę opisaną
 * niżej w ex_options_shufflable()).
 */

require_once __DIR__ . '/json_helpers.php';
require_once __DIR__ . '/grading.php'; // ex_question_options / ex_question_points / ex_cfg_*

/**
 * Czy warianty odpowiedzi tego typu pytania wolno tasować (Question.optionsShufflable).
 * Tylko single/multi/truefalse — NIGDY fill_blank/short_answer/code_*. Stary
 * ti_exam_compose_fallback() tasował opcje dla KAŻDEGO typu bez wyjątków —
 * to był błąd względem Javy, tu naprawiony.
 */
function ex_options_shufflable(string $type): bool {
    // matching/ordering nie mają wariantów w tabeli opcji — ich elementy tasuje
    // ex_items_shuffle_order() niezależnie od ustawienia shuffleOptions.
    return !in_array($type, ['fill_blank', 'short_answer', 'code_fix', 'code_completion', 'code_run',
                             'matching', 'ordering'], true);
}

/**
 * Kolejność elementów pokazywana kursantowi dla 'matching' (prawa kolumna)
 * i 'ordering' (elementy do ułożenia) — permutacja indeksów 0..N-1 albo null
 * dla pozostałych typów.
 *
 * Tasujemy ZAWSZE, bez względu na shuffleOptions: kolejność autora to dokładnie
 * odpowiedź (ordering) albo układ „para obok pary” (matching). Permutacja
 * tożsamościowa jest odrzucana, więc przy N ≥ 2 kursant nigdy nie zobaczy
 * kolejności poprawnej. Korzysta z bieżącego stanu mt_rand — wołający ustawia
 * ziarno (mt_srand), dzięki czemu wynik jest powtarzalny.
 */
function ex_items_shuffle_order(array $question): ?array {
    $type = ex_cfg_str($question, 'type', '');
    $config = ex_cfg_map($question, 'config');
    if ($type === 'matching')      $n = count(ex_matching_right_pool($config));
    elseif ($type === 'ordering')  $n = count(ex_ordering_items($config));
    else return null;

    $perm = range(0, max(0, $n - 1));
    if ($n < 2) return $n === 1 ? [0] : [];
    $identity = $perm;
    for ($try = 0; $try < 20; $try++) {
        shuffle($perm);
        if ($perm !== $identity) return $perm;
    }
    // Skrajnie mało prawdopodobne — przesunięcie cykliczne o 1 nigdy nie jest tożsamością.
    return array_merge(array_slice($identity, 1), [0]);
}

/** Zwraca całą pulę gdy $n<=0 lub $n>=count(pool); inaczej losowe $n pozycji (ExamGenerator.pick). */
function ex_generator_pick(array $pool, int $n): array {
    if ($n <= 0 || $n >= count($pool)) return array_values($pool);
    $keys = array_keys($pool);
    shuffle($keys);
    $keys = array_slice($keys, 0, $n);
    $out = [];
    foreach ($keys as $k) $out[] = $pool[$k];
    return $out;
}

/**
 * Odpowiednik ExamGenerator.generate() — jedyna ścieżka kompozycji wariantu.
 * $request: seed?, blueprint:{fixedDraw,bankDraw|drawCount,shuffleQuestions,shuffleOptions}, questions[].
 */
function ex_generate_exam(array $request): array {
    $bp   = ex_cfg_map($request, 'blueprint');
    $seed = ex_cfg_int($request, 'seed', random_int(1, PHP_INT_MAX));

    mt_srand($seed);
    try {
        $fixed = []; $bank = [];
        foreach (ex_cfg_list($request, 'questions') as $qRaw) {
            $q = is_array($qRaw) ? $qRaw : [];
            $q['id']       = ex_cfg_int($q, 'id', 0);
            $q['position'] = ex_cfg_int($q, 'position', 0);
            $q['points']   = ex_question_points($q);
            $q['type']     = ex_cfg_str($q, 'type', 'single');
            $q['inBank']   = ex_cfg_bool($q, 'inBank', false);
            $q['options']  = ex_question_options($q);
            $q['config']   = ex_cfg_map($q, 'config');
            if ($q['inBank']) $bank[] = $q; else $fixed[] = $q;
        }

        $fixedDraw = ex_cfg_int($bp, 'fixedDraw', 0);
        $bankDraw  = ex_cfg_has($bp, 'bankDraw') ? ex_cfg_int($bp, 'bankDraw', 0) : ex_cfg_int($bp, 'drawCount', 0);

        // Kolejność wywołań (najpierw stałe, potem bank) zużywa RNG w tym
        // samym porządku co Java — istotne tylko dla powtarzalności PHP↔PHP.
        $chosen = array_merge(ex_generator_pick($fixed, $fixedDraw), ex_generator_pick($bank, $bankDraw));

        if (ex_cfg_bool($bp, 'shuffleQuestions', false)) {
            shuffle($chosen);
        } else {
            usort($chosen, function (array $a, array $b): int {
                if ($a['position'] !== $b['position']) return $a['position'] <=> $b['position'];
                return $a['id'] <=> $b['id'];
            });
        }

        $shuffleOptions = ex_cfg_bool($bp, 'shuffleOptions', false);
        $order = []; $optionOrder = []; $maxScore = 0.0;

        foreach ($chosen as $q) {
            $order[] = $q['id'];
            $maxScore += $q['points'];
            $items = ex_items_shuffle_order($q);
            if ($items !== null) {
                // matching/ordering: indeksy elementów (prawej kolumny / do ułożenia).
                $optionOrder[(string)$q['id']] = $items;
                continue;
            }
            if (empty($q['options'])) continue;
            $ids = array_map(fn($o) => $o['id'], $q['options']);
            if ($shuffleOptions && ex_options_shufflable($q['type'])) shuffle($ids);
            $optionOrder[(string)$q['id']] = $ids;
        }

        return [
            'seed'          => $seed,
            'questionOrder' => $order,
            'optionOrder'   => $optionOrder,
            'maxScore'      => round($maxScore, 3),
            'count'         => count($order),
        ];
    } finally {
        mt_srand(); // reset do losowego stanu — nie zostawiaj deterministycznego RNG dla reszty żądania
    }
}
