<?php
/**
 * modules/equi_exams/logic/authoring.php — walidacja/normalizacja formularzy
 * pytania i egzaminu (authoring/{Forms,QuestionAuthoring,ExamAuthoring}.java
 * z exam-engine).
 *
 * Reguły „co jest poprawnym pytaniem/egzaminem" mieszkają WYŁĄCZNIE tutaj —
 * PHP (includes/ti_exams.php) nie decyduje o niczym, tylko przekazuje pola
 * formularza i zapisuje to, co wróci (patrz komentarz w ti_exams.php przy
 * ti_exam_save()/ti_exam_question_save()).
 */

require_once __DIR__ . '/json_helpers.php';
require_once __DIR__ . '/sandbox.php'; // ex_lang_by_id — walidacja pola "language"

// ═══════════════════════════════════════════════════════════════════════════
//  Forms.java — czytniki surowych pól formularza
// ═══════════════════════════════════════════════════════════════════════════

/** Pole wielolinijkowe → lista niepustych wierszy. */
function ex_form_lines(array $m, string $key): array {
    $out = [];
    $v = $m[$key] ?? null;
    if (is_array($v)) {
        foreach ($v as $o) {
            if ($o === null) continue;
            $s = trim((string)$o);
            if ($s !== '') $out[] = $s;
        }
        return $out;
    }
    $raw = ex_cfg_str($m, $key, '');
    foreach (preg_split('/\R/u', $raw) as $line) {
        $s = trim($line);
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

/** Pole „7, 8, 12” → lista liczb całkowitych dodatnich, bez duplikatów. */
function ex_form_int_list(array $m, string $key): array {
    $out = [];
    $v = $m[$key] ?? null;
    $parts = [];
    if (is_array($v)) {
        foreach ($v as $o) $parts[] = (string)$o;
    } else {
        $parts = preg_split('/[,;\s]+/u', ex_cfg_str($m, $key, ''));
    }
    foreach ($parts as $p) {
        $s = trim((string)$p);
        if ($s === '' || !is_numeric($s)) continue;
        $n = (int)round((float)$s);
        if ($n > 0 && !in_array($n, $out, true)) $out[] = $n;
    }
    return $out;
}

/** Liczba z pola tekstowego — akceptuje przecinek dziesiętny. */
function ex_form_decimal(array $m, string $key, float $default): float {
    $v = $m[$key] ?? null;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = str_replace(',', '.', trim(ex_cfg_str($m, $key, '')));
    if ($s === '' || !is_numeric($s)) return $default;
    return (float)$s;
}

function ex_form_clamp_int(float $v, int $lo, int $hi): int {
    $i = (int)round($v);
    if ($i < $lo) return $lo;
    if ($i > $hi) return $hi;
    return $i;
}

function ex_form_clamp(float $v, float $lo, float $hi): float {
    if (is_nan($v)) return $lo;
    return max($lo, min($hi, $v));
}

function ex_form_error(string $field, string $message): array {
    return ['field' => $field, 'message' => $message];
}

// ═══════════════════════════════════════════════════════════════════════════
//  QuestionAuthoring.java
// ═══════════════════════════════════════════════════════════════════════════

function ex_qa_types(): array {
    return ['single', 'multi', 'truefalse', 'fill_blank', 'short_answer', 'code_fix', 'code_completion', 'code_run'];
}

function ex_qa_match_modes(): array {
    return ['trim', 'exact', 'tokens', 'numeric', 'regex'];
}

/** Które artefakty (warianty/przypadki) dany typ faktycznie używa — reszta jest czyszczona po zmianie typu. */
function ex_qa_type_meta(): array {
    return [
        'single'          => ['options' => true,  'cases' => false],
        'multi'           => ['options' => true,  'cases' => false],
        'truefalse'       => ['options' => true,  'cases' => false],
        'fill_blank'      => ['options' => false, 'cases' => false],
        'short_answer'    => ['options' => false, 'cases' => false],
        'code_fix'        => ['options' => false, 'cases' => true],
        'code_completion' => ['options' => false, 'cases' => true],
        'code_run'        => ['options' => false, 'cases' => true],
    ];
}

function ex_qa_read_options(array $form): array {
    $out = [];
    foreach (ex_cfg_list($form, 'options') as $oRaw) {
        $m = is_array($oRaw) ? $oRaw : [];
        $label = trim(ex_cfg_str($m, 'label', ''));
        if ($label === '') continue;
        $out[] = [
            'label'    => $label,
            'correct'  => ex_cfg_bool($m, 'correct', false),
            'feedback' => trim(ex_cfg_str($m, 'feedback', '')),
        ];
    }
    return $out;
}

function ex_qa_read_cases(array $form): array {
    $out = []; $i = 0;
    foreach (ex_cfg_list($form, 'cases') as $oRaw) {
        $m = is_array($oRaw) ? $oRaw : [];
        $expected = ex_cfg_str($m, 'expected', '');
        $stdin = ex_cfg_str($m, 'stdin', '');
        if (trim($expected) === '' && trim($stdin) === '') continue;
        $mode = ex_cfg_str($m, 'matchMode', 'trim');
        if (!in_array($mode, ex_qa_match_modes(), true)) $mode = 'trim';
        $name = trim(ex_cfg_str($m, 'name', ''));
        if ($name === '') $name = 'Przypadek ' . ($i + 1);
        $out[] = [
            'name'      => $name,
            'stdin'     => $stdin,
            'expected'  => $expected,
            'matchMode' => $mode,
            'weight'    => ex_form_clamp(ex_form_decimal($m, 'weight', 1.0), 0.01, 1000),
            'hidden'    => ex_cfg_bool($m, 'hidden', false),
            'tolerance' => ex_form_clamp(ex_form_decimal($m, 'tolerance', 0.000001), 0, 1),
        ];
        $i++;
    }
    return $out;
}

function ex_qa_normalization(array $form, array &$config): void {
    $config['caseSensitive']  = ex_cfg_bool($form, 'caseSensitive', false);
    $config['ignoreAccents']  = ex_cfg_bool($form, 'ignoreAccents', false);
    $config['collapseSpaces'] = true;
    $config['trim']           = true;
}

function ex_qa_neg_marking(array $form, array &$config): void {
    $neg = trim(ex_cfg_str($form, 'negMarking', ''));
    if (in_array($neg, ['partial', 'none', 'all_or_nothing'], true)) $config['negMarking'] = $neg;
}

function ex_qa_count_correct(array $options): int {
    $n = 0;
    foreach ($options as $o) if (!empty($o['correct'])) $n++;
    return $n;
}

function ex_qa_language(array $form, array &$config, array &$errors): string {
    $lang = trim(ex_cfg_str($form, 'language', 'python'));
    if (ex_lang_by_id($lang) === null) {
        $errors[] = ex_form_error('language', 'Nieobsługiwany język zadania: ' . $lang);
        $lang = 'python';
    }
    $config['language'] = $lang;
    return $lang;
}

function ex_qa_run_limits(array $form, array &$config, array &$warnings): void {
    $config['timeLimitMs']  = ex_form_clamp_int(ex_form_decimal($form, 'timeLimitMs', 3000), 200, 15000);
    $config['memoryMb']     = ex_form_clamp_int(ex_form_decimal($form, 'memoryMb', 128), 32, 512);
    $config['forbidden']    = ex_form_lines($form, 'forbidden');
    $config['required']     = ex_form_lines($form, 'required');
    $config['allOrNothing'] = ex_cfg_bool($form, 'allOrNothing', false);
    $config['starter']      = ex_cfg_str($form, 'starter', '');
    ex_qa_check_patterns($config, $warnings);
}

function ex_qa_check_patterns(array $config, array &$warnings): void {
    foreach (['forbidden', 'required'] as $key) {
        foreach (($config[$key] ?? []) as $p) {
            $p = (string)$p;
            $r = @preg_match('~' . $p . '~u', '');
            if ($r === false) {
                $warnings[] = ex_form_error($key, 'Wzorzec „' . $p . '” nie jest poprawnym wyrażeniem regularnym — zadziała jak zwykły tekst.');
            }
        }
    }
}

// ── Reguły poszczególnych typów ───────────────────────────────────────────

function ex_qa_single(array $options, array &$errors): void {
    if (count($options) < 2) $errors[] = ex_form_error('options', 'Podaj co najmniej dwa warianty odpowiedzi.');
    $c = ex_qa_count_correct($options);
    if ($c === 0) $errors[] = ex_form_error('options', 'Zaznacz wariant poprawny.');
    if ($c > 1)   $errors[] = ex_form_error('options', 'Pytanie jednokrotnego wyboru może mieć tylko jeden poprawny wariant.');
}

function ex_qa_multi(array $form, array &$config, array $options, array &$errors): void {
    ex_qa_neg_marking($form, $config);
    if (count($options) < 2) $errors[] = ex_form_error('options', 'Podaj co najmniej dwa warianty odpowiedzi.');
    $correct = ex_qa_count_correct($options);
    if ($correct === 0) $errors[] = ex_form_error('options', 'Zaznacz przynajmniej jeden poprawny wariant.');
    if ($correct === count($options) && count($options) > 1) {
        $errors[] = ex_form_error('options', 'Wszystkie warianty są poprawne — pytanie nie rozróżnia odpowiedzi.');
    }
}

function ex_qa_truefalse(array $form, array &$config, array $options, array &$errors): void {
    ex_qa_neg_marking($form, $config);
    if (count($options) < 2) $errors[] = ex_form_error('options', 'Podaj co najmniej dwa twierdzenia do oceny.');
}

function ex_qa_read_blanks(array $form, array &$errors): array {
    $out = []; $keys = []; $i = 0;
    foreach (ex_cfg_list($form, 'blanks') as $oRaw) {
        $m = is_array($oRaw) ? $oRaw : [];
        $key = trim(ex_cfg_str($m, 'key', ''));
        if ($key === '') $key = (string)($i + 1);
        $accept = ex_form_lines($m, 'accept');
        if (empty($accept)) { $i++; continue; }
        if (in_array($key, $keys, true)) {
            $errors[] = ex_form_error('blanks', 'Klucz luki „' . $key . '” powtarza się.');
            $i++;
            continue;
        }
        $keys[] = $key;
        $out[] = [
            'key'    => $key,
            'accept' => $accept,
            'hint'   => trim(ex_cfg_str($m, 'hint', '')),
            'points' => ex_form_clamp(ex_form_decimal($m, 'points', 1.0), 0.01, 1000),
        ];
        $i++;
    }
    return $out;
}

function ex_qa_fill_blank(array $form, array &$config, string $prompt, array &$errors, array &$warnings): void {
    ex_qa_normalization($form, $config);
    $config['regex']        = ex_cfg_bool($form, 'regex', false);
    $config['allOrNothing'] = ex_cfg_bool($form, 'allOrNothing', false);

    $blanks = ex_qa_read_blanks($form, $errors);
    $config['blanks'] = $blanks;
    if (empty($blanks)) {
        $errors[] = ex_form_error('blanks', 'Zdefiniuj przynajmniej jedną lukę.');
        return;
    }
    foreach ($blanks as $b) {
        $key = (string)($b['key'] ?? '');
        if (!str_contains($prompt, '[[' . $key . ']]')) {
            $warnings[] = ex_form_error('prompt', 'Treść nie zawiera znacznika [[' . $key . ']] — kursant nie zobaczy pola dla tej luki w tekście.');
        }
    }
}

function ex_qa_short_answer(array $form, array &$config, array &$errors, array &$warnings): void {
    ex_qa_normalization($form, $config);
    $manual = ex_cfg_bool($form, 'manual', false);
    $config['manual']   = $manual;
    $config['minChars'] = ex_form_clamp_int(ex_form_decimal($form, 'minChars', 0), 0, 10000);

    $crit = [];
    foreach (ex_cfg_list($form, 'criteria') as $oRaw) {
        $m = is_array($oRaw) ? $oRaw : [];
        $any = ex_form_lines($m, 'any');
        if (empty($any)) continue;
        $label = trim(ex_cfg_str($m, 'label', ''));
        if ($label === '') $label = $any[0];
        $crit[] = [
            'label'    => $label,
            'any'      => $any,
            'points'   => ex_form_clamp(ex_form_decimal($m, 'points', 1.0), 0.01, 1000),
            'required' => ex_cfg_bool($m, 'required', false),
        ];
    }
    $config['keywords'] = $crit;

    if (empty($crit) && !$manual) {
        $errors[] = ex_form_error('criteria', 'Bez kryteriów słów kluczowych zaznacz „ocena prowadzącego” — inaczej pytania nie da się ocenić.');
    }
    if (!empty($crit) && !$manual) {
        $warnings[] = ex_form_error('criteria', 'Ocena po słowach kluczowych bywa zawodna przy odpowiedziach opisowych — rozważ kontrolę ręczną.');
    }
}

function ex_qa_code_fix(array $form, array &$config, array $cases, array &$errors, array &$warnings): void {
    ex_qa_language($form, $config, $errors);
    $snippet = ex_cfg_str($form, 'snippet', '');
    $config['snippet'] = $snippet;
    if (trim($snippet) === '') $errors[] = ex_form_error('snippet', 'Wklej fragment kodu do analizy.');

    $rewrite = ex_cfg_str($form, 'answerMode', 'line') === 'rewrite';
    $config['answerMode'] = $rewrite ? 'rewrite' : 'line';

    if ($rewrite) {
        ex_qa_run_limits($form, $config, $warnings);
        if (empty($cases)) {
            $errors[] = ex_form_error('cases', 'W trybie przepisania kodu potrzebny jest choć jeden przypadek testowy.');
        }
        return;
    }

    $config['requireExplanation'] = ex_cfg_bool($form, 'requireExplanation', false);
    $lines = ex_form_int_list($form, 'acceptLines');
    $config['acceptLines'] = $lines;
    if (empty($lines)) {
        $errors[] = ex_form_error('acceptLines', 'Podaj numer linii zawierającej błąd.');
        return;
    }
    $total = $snippet === '' ? 0 : count(preg_split('/\R/u', $snippet));
    foreach ($lines as $n) {
        if ($total > 0 && $n > $total) {
            $errors[] = ex_form_error('acceptLines', "Linia {$n} wykracza poza fragment, który ma tylko {$total} linii.");
        }
    }
}

function ex_qa_code_completion(array $form, array &$config, array $cases, array &$errors, array &$warnings): void {
    ex_qa_normalization($form, $config);
    ex_qa_language($form, $config, $errors);
    $config['regex']        = ex_cfg_bool($form, 'regex', false);
    $config['allOrNothing'] = ex_cfg_bool($form, 'allOrNothing', false);

    $template = ex_cfg_str($form, 'template', '');
    $config['template'] = $template;
    if (trim($template) === '') $errors[] = ex_form_error('template', 'Wklej szablon kodu z lukami.');

    $run = ex_cfg_bool($form, 'runAfterFill', false);
    $config['runAfterFill'] = $run;
    if ($run) ex_qa_run_limits($form, $config, $warnings);

    $blanks = ex_qa_read_blanks($form, $errors);
    $config['blanks'] = $blanks;

    if (empty($blanks) && !$run) {
        $errors[] = ex_form_error('blanks', 'Zdefiniuj luki albo włącz sprawdzanie uruchomieniowe.');
    }
    if ($run && empty($cases)) {
        $errors[] = ex_form_error('cases', 'Sprawdzanie uruchomieniowe wymaga przypadków testowych.');
    }
    foreach ($blanks as $b) {
        $key = (string)($b['key'] ?? '');
        if (!str_contains($template, '___' . $key . '___') && !str_contains($template, '[[' . $key . ']]')) {
            $errors[] = ex_form_error('template', 'Szablon nie zawiera znacznika ___' . $key . '___ dla zdefiniowanej luki.');
        }
    }
}

function ex_qa_code_run(array $form, array &$config, array $cases, array &$errors, array &$warnings): void {
    ex_qa_language($form, $config, $errors);
    ex_qa_run_limits($form, $config, $warnings);
    if (empty($cases)) {
        $errors[] = ex_form_error('cases', 'Dodaj przynajmniej jeden przypadek testowy wejście/wyjście.');
        return;
    }
    $anyVisible = false;
    foreach ($cases as $c) if (empty($c['hidden'])) $anyVisible = true;
    if (!$anyVisible) {
        $errors[] = ex_form_error('cases', 'Zostaw przynajmniej jeden przypadek jawny — kursant musi widzieć, czego dotyczy zadanie.');
    }
}

/**
 * Odpowiednik QuestionAuthoring.build(). $req: type, prompt, points, inBank,
 * explanation, form:{...pola formularza...}.
 */
function ex_question_authoring_build(array $req): array {
    $type = ex_cfg_str($req, 'type', 'single');
    if (!in_array($type, ex_qa_types(), true)) $type = 'single';

    $form = ex_cfg_map($req, 'form');
    $errors = []; $warnings = [];

    $prompt = trim(ex_cfg_str($req, 'prompt', ''));
    if ($prompt === '') $errors[] = ex_form_error('prompt', 'Treść polecenia jest wymagana.');

    $points = ex_form_decimal($req, 'points', 1.0);
    if ($points <= 0) {
        $errors[] = ex_form_error('points', 'Liczba punktów musi być większa od zera.');
        $points = 1.0;
    }
    $points = ex_form_clamp($points, 0.01, 1000);

    $config = [];
    $options = ex_qa_read_options($form);
    $cases   = ex_qa_read_cases($form);

    switch ($type) {
        case 'single':          ex_qa_single($options, $errors); break;
        case 'multi':           ex_qa_multi($form, $config, $options, $errors); break;
        case 'truefalse':       ex_qa_truefalse($form, $config, $options, $errors); break;
        case 'fill_blank':      ex_qa_fill_blank($form, $config, $prompt, $errors, $warnings); break;
        case 'short_answer':    ex_qa_short_answer($form, $config, $errors, $warnings); break;
        case 'code_fix':        ex_qa_code_fix($form, $config, $cases, $errors, $warnings); break;
        case 'code_completion': ex_qa_code_completion($form, $config, $cases, $errors, $warnings); break;
        case 'code_run':        ex_qa_code_run($form, $config, $cases, $errors, $warnings); break;
    }

    // Warianty i przypadki mają sens tylko tam, gdzie typ ich używa — resztę
    // odrzucamy, żeby w bazie nie zostawały sieroty po zmianie typu.
    $meta = ex_qa_type_meta()[$type];
    if (!$meta['options']) $options = [];
    if (!$meta['cases'])   $cases = [];

    $question = [
        'type'        => $type,
        'prompt'      => $prompt,
        'points'      => $points,
        'inBank'      => ex_cfg_bool($req, 'inBank', false),
        'explanation' => trim(ex_cfg_str($req, 'explanation', '')),
        'config'      => $config,
        'options'     => $options,
        'cases'       => $cases,
    ];

    return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings, 'question' => $question];
}

/** Słownik typów dla interfejsu prowadzącego — odpowiednik QuestionAuthoring.catalogue(). */
function ex_question_authoring_catalogue(): array {
    $meta = ex_qa_type_meta();
    $defs = [
        ['single', 'Jednokrotny wybór', 'record-circle', 'Klasyczne ABC — dokładnie jedna odpowiedź poprawna.'],
        ['multi', 'Wielokrotny wybór', 'check2-square', 'Kilka poprawnych wariantów; punktacja z karą za zgadywanie.'],
        ['truefalse', 'Prawda / Fałsz', 'toggles', 'Zestaw twierdzeń — kursant ocenia każde osobno.'],
        ['fill_blank', 'Pytanie z luką', 'input-cursor-text', 'Wpisanie brakującego słowa kluczowego lub pojęcia.'],
        ['short_answer', 'Krótka odpowiedź', 'chat-left-text', 'Odpowiedź opisowa — automat po słowach kluczowych albo ocena prowadzącego.'],
        ['code_fix', 'Analiza / poprawa kodu', 'bug', 'Fragment kodu z błędem — wskazanie linii albo przepisanie poprawnie.'],
        ['code_completion', 'Luki w kodzie', 'braces', 'Uzupełnienie brakujących słów kluczowych i konstrukcji składniowych.'],
        ['code_run', 'Zadanie programistyczne', 'terminal', 'Kod uruchamiany w piaskownicy i sprawdzany na danych wejście/wyjście.'],
    ];
    $out = [];
    foreach ($defs as [$id, $label, $icon, $hint]) {
        $out[] = ['id' => $id, 'label' => $label, 'icon' => $icon, 'hint' => $hint,
                  'options' => $meta[$id]['options'], 'cases' => $meta[$id]['cases']];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
//  ExamAuthoring.java
// ═══════════════════════════════════════════════════════════════════════════

function ex_exam_authoring_default_time_limit(string $mode): int {
    if ($mode === 'quiz') return 5;
    if ($mode === 'training') return 0;
    return 45;
}

function ex_exam_authoring_default_attempts(string $mode): int {
    return $mode === 'training' ? 0 : 1;
}

function ex_exam_authoring_default_feedback(string $mode): string {
    if ($mode === 'training') return 'immediate';
    if ($mode === 'quiz') return 'after_submit';
    return 'never';
}

function ex_exam_authoring_describe_attempts(int $n): string {
    if ($n === 0) return 'brak limitu';
    return $n . ($n === 1 ? ' podejście' : ' podejścia/podejść');
}

/** Odpowiednik ExamAuthoring.normalize(). $req: form:{...pola formularza...}. */
function ex_exam_authoring_normalize(array $req): array {
    $f = ex_cfg_map($req, 'form');
    $errors = []; $warnings = [];

    $mode = ex_cfg_str($f, 'mode', 'exam');
    if (!in_array($mode, ['exam', 'quiz', 'training'], true)) $mode = 'exam';

    $title = trim(ex_cfg_str($f, 'title', ''));
    if ($title === '') $errors[] = ex_form_error('title', 'Podaj tytuł egzaminu.');

    $timeLimit   = ex_form_clamp_int(ex_form_decimal($f, 'timeLimitMin', (float)ex_exam_authoring_default_time_limit($mode)), 0, 600);
    $passPct     = ex_form_clamp_int(ex_form_decimal($f, 'passPct', 0), 0, 100);
    $maxAttempts = ex_form_clamp_int(ex_form_decimal($f, 'maxAttempts', (float)ex_exam_authoring_default_attempts($mode)), 0, 50);
    $gradeWeight = ex_form_clamp_int(ex_form_decimal($f, 'gradeWeight', 3), 1, 10);
    $fixedDraw   = ex_form_clamp_int(ex_form_decimal($f, 'fixedDraw', 0), 0, 500);
    $bankDraw    = ex_form_clamp_int(ex_form_decimal($f, 'bankDraw', 0), 0, 500);

    $feedback = ex_cfg_str($f, 'showFeedback', ex_exam_authoring_default_feedback($mode));
    if (!in_array($feedback, ['never', 'after_submit', 'immediate'], true)) $feedback = ex_exam_authoring_default_feedback($mode);

    $neg = ex_cfg_str($f, 'negMarking', 'partial');
    if (!in_array($neg, ['partial', 'none', 'all_or_nothing'], true)) $neg = 'partial';

    // ── Reguły spójności formuły ─────────────────────────────────────────────
    if ($mode === 'exam') {
        if ($maxAttempts !== 1) {
            $warnings[] = ex_form_error('maxAttempts', 'Kolokwium zwykle daje jedno podejście — ustawiono '
                . ex_exam_authoring_describe_attempts($maxAttempts) . '.');
        }
        if ($feedback === 'immediate') {
            $errors[] = ex_form_error('showFeedback', 'W kolokwium nie wolno pokazywać poprawnych odpowiedzi w trakcie rozwiązywania.');
            $feedback = 'never';
        }
    }
    if ($mode === 'quiz') {
        if ($timeLimit === 0) {
            $warnings[] = ex_form_error('timeLimitMin', 'Kartkówka bez limitu czasu traci sens — rozważ 5–10 minut.');
        }
        if ($timeLimit > 20) {
            $warnings[] = ex_form_error('timeLimitMin', 'To już nie jest kartkówka — przy tym limicie wybierz formułę kolokwium.');
        }
    }
    if ($mode === 'training') {
        // Tryb treningowy z natury pokazuje wynik od razu i nie ogranicza podejść.
        $feedback = 'immediate';
        $maxAttempts = 0;
        if ($timeLimit > 0) {
            $warnings[] = ex_form_error('timeLimitMin', 'W trybie treningowym limit czasu zwykle przeszkadza.');
        }
    }

    $openAt  = trim(ex_cfg_str($f, 'openAt', ''));
    $closeAt = trim(ex_cfg_str($f, 'closeAt', ''));
    if ($openAt !== '' && $closeAt !== '' && strcmp($closeAt, $openAt) < 0) {
        $errors[] = ex_form_error('closeAt', 'Termin zamknięcia wypada przed terminem otwarcia.');
    }

    $active = ex_cfg_bool($f, 'isActive', false);
    if ($active && ex_cfg_int($f, 'questionCount', 0) === 0) {
        $errors[] = ex_form_error('isActive', 'Nie da się udostępnić egzaminu bez pytań.');
        $active = false;
    }
    if (ex_cfg_bool($f, 'syncGrade', false) && $passPct === 0) {
        $warnings[] = ex_form_error('passPct', 'Bez progu zaliczenia ocena w dzienniku powstaje wyłącznie z procentów.');
    }

    $exam = [
        'title'            => $title,
        'description'      => trim(ex_cfg_str($f, 'description', '')),
        'mode'             => $mode,
        'timeLimitMin'     => $timeLimit,
        'passPct'          => $passPct,
        'maxAttempts'      => $maxAttempts,
        'shuffleQuestions' => ex_cfg_bool($f, 'shuffleQuestions', $mode !== 'training'),
        'shuffleOptions'   => ex_cfg_bool($f, 'shuffleOptions', $mode !== 'training'),
        'fixedDraw'        => $fixedDraw,
        'bankDraw'         => $bankDraw,
        'showFeedback'     => $feedback,
        'negMarking'       => $neg,
        'openAt'           => $openAt,
        'closeAt'          => $closeAt,
        'isActive'         => $active,
        'syncGrade'        => ex_cfg_bool($f, 'syncGrade', false),
        'gradeWeight'      => $gradeWeight,
        // Górna granica jak w Javie (Integer.MAX_VALUE) — kolumna DB ma zakres 32-bit.
        'sessionId'        => ex_form_clamp_int(ex_form_decimal($f, 'sessionId', 0), 0, 2147483647),
    ];

    return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings, 'exam' => $exam];
}

/** Opis formuł dla interfejsu prowadzącego — odpowiednik ExamAuthoring.modes(). */
function ex_exam_authoring_modes(): array {
    return [
        ['id' => 'exam', 'label' => 'Kolokwium / egzamin', 'icon' => 'mortarboard',
         'hint' => 'Ograniczony czasowo, jedno podejście, losowa kolejność pytań i wariantów, bez podpowiedzi.'],
        ['id' => 'quiz', 'label' => 'Wejściówka / kartkówka', 'icon' => 'lightning-charge',
         'hint' => 'Krótki zestaw 3–5 pytań z ostrym limitem czasu.'],
        ['id' => 'training', 'label' => 'Tryb treningowy', 'icon' => 'arrow-repeat',
         'hint' => 'Bez limitu czasu; po każdej odpowiedzi wynik i wyjaśnienie, dowolna liczba podejść.'],
    ];
}
