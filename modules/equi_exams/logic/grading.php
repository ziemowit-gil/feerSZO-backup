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

/**
 * Dopasowywanie par: {kluczLewego: kluczPrawego}. Puste wybory („— wybierz —")
 * są pomijane — lewy element bez wyboru liczy się jak niedopasowany.
 */
function ex_answer_pairs(array $answer): array {
    $out = [];
    foreach (ex_cfg_map($answer, 'pairs') as $k => $v) {
        if ($v === null || is_array($v)) continue;
        $s = trim((string)$v);
        if ($s !== '') $out[(string)$k] = $s;
    }
    return $out;
}

/** Ustaw w kolejności: {kluczElementu: pozycja 1..N}. Pozycje spoza zakresu ≥1 pomijamy. */
function ex_answer_order(array $answer): array {
    $out = [];
    foreach (ex_cfg_map($answer, 'order') as $k => $v) {
        if ($v === null || is_array($v)) continue;
        $s = trim((string)$v);
        if ($s === '' || !is_numeric($s)) continue;
        $n = (int)round((float)$s);
        if ($n >= 1) $out[(string)$k] = $n;
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Elementy pytań „matching” i „ordering” — klucze i pule
// ═══════════════════════════════════════════════════════════════════════════
//
// Elementy tych typów mieszkają w `config` pytania (nie w tabeli wariantów).
// Kursant odwołuje się do nich przez KLUCZ wyliczony z treści — nieprzezroczysty
// skrót, który nie zdradza ani kolejności poprawnej, ani pary (indeks albo
// rosnące id wariantu zdradzałyby odpowiedź komuś, kto zajrzy w kod strony).

/** Klucz elementu wyliczony z treści (stały między podejściami, nie zdradza pozycji). */
function ex_item_key(string $label): string {
    return 'k' . substr(sha1(trim($label)), 0, 12);
}

/** Pary w kolejności autora: [['key','left','right','rightKey'], …]. */
function ex_matching_pairs(array $config): array {
    $out = [];
    foreach (ex_cfg_list($config, 'pairs') as $pRaw) {
        $p = is_array($pRaw) ? $pRaw : [];
        $left  = trim(ex_cfg_str($p, 'left', ''));
        $right = trim(ex_cfg_str($p, 'right', ''));
        if ($left === '' || $right === '') continue;
        $out[] = ['key' => ex_item_key($left), 'left' => $left,
                  'right' => $right, 'rightKey' => ex_item_key($right)];
    }
    return $out;
}

/**
 * Prawa kolumna do wyboru: prawe strony par + dystraktory, bez powtórzeń
 * (ten sam prawy element może pasować do kilku lewych — pokazujemy go raz).
 */
function ex_matching_right_pool(array $config): array {
    $out = []; $seen = [];
    $labels = array_map(fn($p) => $p['right'], ex_matching_pairs($config));
    foreach (ex_cfg_strings($config, 'distractors') as $d) $labels[] = trim($d);
    foreach ($labels as $label) {
        if ($label === '') continue;
        $key = ex_item_key($label);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['key' => $key, 'label' => $label];
    }
    return $out;
}

/** Elementy do ułożenia w POPRAWNEJ kolejności: [['key','label'], …]. */
function ex_ordering_items(array $config): array {
    $out = [];
    foreach (ex_cfg_strings($config, 'items') as $label) {
        $label = trim($label);
        if ($label === '') continue;
        $out[] = ['key' => ex_item_key($label), 'label' => $label];
    }
    return $out;
}

/** Pozycje wskazane więcej niż jednemu elementowi (posortowane rosnąco). */
function ex_ordering_duplicates(array $order): array {
    $dups = [];
    foreach (array_count_values(array_map('intval', array_values($order))) as $pos => $n) {
        if ($n > 1) $dups[] = (int)$pos;
    }
    sort($dups);
    return $dups;
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

/**
 * Punkty za ułamek trafień wg reguły pytania/egzaminu. Dla dopasowywania
 * i kolejności nie ma „zgadywania” do ukarania (każdy element ma dokładnie
 * jedno miejsce), więc 'partial' i 'none' liczą proporcjonalnie, a
 * 'all_or_nothing' daje punkty tylko za komplet.
 */
function ex_items_score(float $points, int $hits, int $total, string $rule): float {
    if ($total <= 0) return 0.0;
    if ($rule === 'all_or_nothing') return $hits === $total ? $points : 0.0;
    return $points * ($hits / $total);
}

function ex_grade_matching(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $config = ex_cfg_map($question, 'config');
    $pairs  = ex_matching_pairs($config);
    if (count($pairs) < 1) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Pytanie nie ma zdefiniowanych par — do sprawdzenia przez prowadzącego.');
    }

    $given  = ex_answer_pairs($answer);
    $labels = [];
    foreach (ex_matching_right_pool($config) as $p) $labels[$p['key']] = $p['label'];

    $hits = 0; $answered = 0; $rows = [];
    foreach ($pairs as $p) {
        $g  = $given[$p['key']] ?? null;
        $ok = $g !== null && $g === $p['rightKey'];
        if ($g !== null) $answered++;
        if ($ok) $hits++;
        $row = [
            'key'        => $p['key'],
            'left'       => $p['left'],
            'answered'   => $g !== null,
            'given'      => $g,
            'givenLabel' => $g !== null ? ($labels[$g] ?? '') : '',
            'correct'    => $ok,
        ];
        if (!empty($ctx['revealAnswers'])) $row['expected'] = $p['right'];
        $rows[] = $row;
    }

    $total = count($pairs);
    $rule  = ex_cfg_str($config, 'negMarking', $ctx['negMarking']);
    if ($answered === 0) {
        $r = ex_qr_award($r, 0);
        $r['details'] = ['pairs' => $rows, 'hits' => 0, 'total' => $total, 'negMarking' => $rule];
        return ex_qr_feedback($r, 'Brak odpowiedzi.');
    }

    $r = ex_qr_award($r, ex_items_score(ex_question_points($question), $hits, $total, $rule));
    $r['details']['pairs']      = $rows;
    $r['details']['hits']       = $hits;
    $r['details']['total']      = $total;
    $r['details']['skipped']    = $total - $answered;
    $r['details']['negMarking'] = $rule;
    $fb = "Poprawnie dopasowane pary: {$hits} z {$total}";
    if ($total - $answered > 0) $fb .= ' (bez wyboru: ' . ($total - $answered) . ')';
    if ($rule === 'all_or_nothing' && $hits < $total) $fb .= ' — punkty tylko za komplet';
    return ex_qr_feedback($r, $fb . '.');
}

function ex_grade_ordering(array $question, array $answer, array $ctx): array {
    $r = ex_qr_new($question);
    $config = ex_cfg_map($question, 'config');
    $items  = ex_ordering_items($config);
    $n      = count($items);
    if ($n < 2) {
        $r['needsReview'] = true;
        return ex_qr_feedback(ex_qr_award($r, 0), 'Pytanie nie ma elementów do ułożenia — do sprawdzenia przez prowadzącego.');
    }

    // Pozycje spoza 1..N i nieznane klucze odrzucamy; pozycja wskazana kilku
    // elementom jest niejednoznaczna — każdy z tych elementów liczy się jako błędny.
    $order = [];
    $keys  = array_column($items, 'key');
    foreach (ex_answer_order($answer) as $k => $pos) {
        if ($pos <= $n && in_array($k, $keys, true)) $order[$k] = $pos;
    }
    $dups = ex_ordering_duplicates($order);

    $rows = []; $posHits = 0;
    foreach ($items as $i => $it) {
        $g   = $order[$it['key']] ?? null;
        $dup = $g !== null && in_array($g, $dups, true);
        $ok  = $g !== null && !$dup && $g === $i + 1;
        if ($ok) $posHits++;
        $row = ['key' => $it['key'], 'label' => $it['label'], 'answered' => $g !== null,
                'given' => $g, 'duplicate' => $dup, 'correct' => $ok];
        if (!empty($ctx['revealAnswers'])) $row['expected'] = $i + 1;
        $rows[] = $row;
    }

    $scoring = ex_cfg_str($config, 'scoring', 'position') === 'pairs' ? 'pairs' : 'position';
    if ($scoring === 'pairs') {
        // Sąsiednie pary: element i+1 stoi tuż za elementem i (względna kolejność).
        $hits = 0; $total = $n - 1;
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $rows[$i]; $b = $rows[$i + 1];
            if ($a['given'] !== null && $b['given'] !== null && !$a['duplicate'] && !$b['duplicate']
                && $b['given'] === $a['given'] + 1) $hits++;
        }
    } else {
        $hits = $posHits; $total = $n;
    }

    $rule = ex_cfg_str($config, 'negMarking', $ctx['negMarking']);
    $details = ['items' => $rows, 'hits' => $hits, 'total' => $total, 'positionHits' => $posHits,
                'scoring' => $scoring, 'duplicates' => $dups, 'negMarking' => $rule];

    if (empty($order)) {
        $r = ex_qr_award($r, 0);
        $r['details'] = $details;
        return ex_qr_feedback($r, 'Brak odpowiedzi.');
    }

    $r = ex_qr_award($r, ex_items_score(ex_question_points($question), $hits, $total, $rule));
    $r['details'] = array_merge($r['details'], $details);
    $fb = $scoring === 'pairs'
        ? "Poprawnie ułożone sąsiednie pary: {$hits} z {$total}"
        : "Elementy na właściwej pozycji: {$hits} z {$total}";
    if ($dups) {
        $fb .= '. Pozycja ' . implode(', ', $dups) . ' została wskazana kilku elementom — liczone jako błędne';
    }
    if ($rule === 'all_or_nothing' && $hits < $total) $fb .= ' — punkty tylko za komplet';
    return ex_qr_feedback($r, $fb . '.');
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
            case 'matching':        return ex_grade_matching($question, $answer, $ctx);
            case 'ordering':        return ex_grade_ordering($question, $answer, $ctx);
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
