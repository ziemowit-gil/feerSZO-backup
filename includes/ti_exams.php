<?php
/**
 * includes/ti_exams.php
 * Moduł „Testy wiedzy i umiejętności" (Egzaminy) dla TI.
 *
 * Podział odpowiedzialności:
 *   • ten plik (includes/)            — trwałość (SQLite/MySQL), uprawnienia,
 *                                       sesje, interfejs,
 *   • silnik (modules/equi_exams/logic) — modele pytań, składanie wariantów,
 *                                       weryfikacja i ocenianie (czysty PHP,
 *                                       port dawnego silnika Java),
 *   • piaskownica (sandbox.php)       — uruchamianie kodu kursanta jako osobny,
 *                                       nieuprzywilejowany użytkownik systemowy
 *                                       (`sudo -u examrun`, cli/exam_sandbox_worker.php).
 *
 * Silnik jest BEZSTANOWY: nie dotyka bazy, dostaje komplet definicji pytań
 * i odpowiedzi w tablicach (ten sam kształt co dawne żądania JSON do usługi
 * Java), oddaje punktację. Do bazy pisze wyłącznie ta warstwa. Wywołania
 * przechodzą przez ti_exam_engine_post(), która dziś jest lokalnym
 * dispatcherem po ścieżce — sygnatura została zachowana, żeby nie ruszać
 * miejsc wywołań.
 *
 * Moduł jest ODRĘBNY od starszego modułu „Testy" (k30_ti_tests*). Tamten działa
 * bez zmian; tu jest własna przestrzeń tabel k30_ti_exam* oraz import pytań
 * ze starego modułu (ti_exam_import_from_test).
 *
 * Wymaga wcześniejszego includes/db.php oraz includes/karty30.php.
 */

require_once __DIR__ . '/ti_exams_schema.php';

// Silnik egzaminów (Equi Exams) — czysty PHP, bez usługi sieciowej.
foreach (['json_helpers', 'text_match', 'sandbox', 'grading', 'code_grading',
          'generator', 'authoring', 'manual_grading', 'stats'] as $_ex_logic) {
    require_once dirname(__DIR__) . '/modules/equi_exams/logic/' . $_ex_logic . '.php';
}
unset($_ex_logic);

// ═══════════════════════════════════════════════════════════════════════════
//  SŁOWNIKI
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Typy pytań. Klucze MUSZĄ odpowiadać ex_qa_types() w modules/equi_exams/logic/authoring.php.
 *   options — pytanie ma warianty/twierdzenia,
 *   cases   — pytanie ma przypadki testowe wejście/wyjście,
 *   code    — pytanie operuje na kodzie (edytor monospace, wybór języka).
 */
const K30_TI_EXAM_TYPES = [
    'single' => [
        'label' => 'Jednokrotny wybór',
        'hint'  => 'Klasyczne ABC — dokładnie jedna odpowiedź poprawna.',
        'icon'  => 'record-circle', 'options' => true,  'cases' => false, 'code' => false,
    ],
    'multi' => [
        'label' => 'Wielokrotny wybór',
        'hint'  => 'Kilka poprawnych wariantów; punktacja z karą za zgadywanie.',
        'icon'  => 'check2-square', 'options' => true,  'cases' => false, 'code' => false,
    ],
    'truefalse' => [
        'label' => 'Prawda / Fałsz',
        'hint'  => 'Zestaw twierdzeń — kursant ocenia każde osobno.',
        'icon'  => 'toggles', 'options' => true,  'cases' => false, 'code' => false,
    ],
    'fill_blank' => [
        'label' => 'Pytanie z luką',
        'hint'  => 'Wpisanie brakującego słowa kluczowego lub pojęcia.',
        'icon'  => 'input-cursor-text', 'options' => false, 'cases' => false, 'code' => false,
    ],
    'short_answer' => [
        'label' => 'Krótka odpowiedź',
        'hint'  => 'Odpowiedź opisowa — automat po słowach kluczowych albo ocena prowadzącego.',
        'icon'  => 'chat-left-text', 'options' => false, 'cases' => false, 'code' => false,
    ],
    // matching/ordering: elementy w config (pairs/distractors, items) — nie w k30_ti_exam_options.
    'matching' => [
        'label' => 'Dopasowywanie par',
        'hint'  => 'Do każdego pojęcia po lewej kursant wybiera pasujący element z listy; możliwe dodatkowe elementy-pułapki.',
        'icon'  => 'arrow-left-right', 'options' => false, 'cases' => false, 'code' => false,
    ],
    'ordering' => [
        'label' => 'Ustaw w kolejności',
        'hint'  => 'Kursant nadaje elementom numery pozycji — np. kroki procedury albo chronologię.',
        'icon'  => 'sort-numeric-down', 'options' => false, 'cases' => false, 'code' => false,
    ],
    'code_fix' => [
        'label' => 'Analiza / poprawa kodu',
        'hint'  => 'Fragment kodu z błędem — wskazanie linii albo przepisanie poprawnie.',
        'icon'  => 'bug', 'options' => false, 'cases' => true,  'code' => true,
    ],
    'code_completion' => [
        'label' => 'Luki w kodzie',
        'hint'  => 'Uzupełnienie brakujących słów kluczowych i konstrukcji składniowych.',
        'icon'  => 'braces', 'options' => false, 'cases' => true,  'code' => true,
    ],
    'code_run' => [
        'label' => 'Zadanie programistyczne',
        'hint'  => 'Kod uruchamiany w piaskownicy i sprawdzany na danych wejście/wyjście.',
        'icon'  => 'terminal', 'options' => false, 'cases' => true,  'code' => true,
    ],
];

/**
 * Formuły testu — WYŁĄCZNIE etykiety do interfejsu.
 * Wartości domyślne i reguły spójności formuł należą do silnika
 * (ex_exam_authoring_normalize w authoring.php); tutaj świadomie ich nie powtarzamy.
 */
const K30_TI_EXAM_MODES = [
    'exam' => [
        'label' => 'Kolokwium / egzamin',
        'hint'  => 'Ograniczony czasowo, jedno podejście, losowa kolejność pytań i wariantów, bez podpowiedzi.',
        'icon'  => 'mortarboard',
    ],
    'quiz' => [
        'label' => 'Wejściówka / kartkówka',
        'hint'  => 'Krótki zestaw 3–5 pytań z ostrym limitem czasu.',
        'icon'  => 'lightning-charge',
    ],
    'training' => [
        'label' => 'Tryb treningowy',
        'hint'  => 'Bez limitu czasu; po każdej odpowiedzi wynik i wyjaśnienie, dowolna liczba podejść.',
        'icon'  => 'arrow-repeat',
    ],
];

/** Zasady punktacji pytań wielokrotnego wyboru, prawda/fałsz, dopasowywania i kolejności. */
const K30_TI_EXAM_NEG = [
    'partial'        => 'Cząstkowa z karą — (trafione − błędne) / poprawnych, nie mniej niż 0',
    'none'           => 'Cząstkowa bez kary — trafione / poprawnych',
    'all_or_nothing' => 'Wszystko albo nic — punkty tylko za komplet',
];

/** Języki zadań programistycznych — muszą pokrywać się z ex_lang_registry() w sandbox.php. */
const K30_TI_EXAM_LANGS = [
    'python'     => 'Python 3',
    'php'        => 'PHP',
    'javascript' => 'JavaScript (Node.js)',
    'java'       => 'Java',
    'c'          => 'C',
    'cpp'        => 'C++',
    'shell'      => 'Powłoka (sh)',
];

/** Tryby porównywania wyjścia programu z oczekiwanym. */
const K30_TI_EXAM_MATCH = [
    'trim'    => 'Pomijaj białe znaki na końcach linii (zalecane)',
    'exact'   => 'Dosłownie, znak w znak',
    'tokens'  => 'Ciąg tokenów rozdzielonych białymi znakami',
    'numeric' => 'Jak tokeny, liczby z tolerancją',
    'regex'   => 'Oczekiwane wyjście jest wyrażeniem regularnym',
];

function ti_exam_type_label(string $type): string {
    return K30_TI_EXAM_TYPES[$type]['label'] ?? $type;
}

function ti_exam_mode_label(string $mode): string {
    return K30_TI_EXAM_MODES[$mode]['label'] ?? $mode;
}

// ═══════════════════════════════════════════════════════════════════════════
//  SILNIK — LOKALNY DISPATCHER (modules/equi_exams/logic)
// ═══════════════════════════════════════════════════════════════════════════
//
// Dawniej ten blok był klientem HTTP usługi Java (`exam-engine`). Ścieżki
// z API zostały jako KLUCZE dispatchera, żeby osiem miejsc wywołań w tym pliku
// nie musiało się zmieniać; kształty żądań i odpowiedzi są identyczne.

/**
 * Wywołanie silnika. Zwraca tablicę z odpowiedzią albo null przy awarii;
 * powód awarii ląduje w $error (do pokazania prowadzącemu, nie kursantowi).
 *
 * @param int $timeout zachowany dla zgodności sygnatury — lokalny silnik nie
 *                     ma limitu czasu na tym poziomie (piaskownica kodu ma
 *                     własny, patrz ex_sandbox_config()).
 */
function ti_exam_engine_post(string $path, array $payload, ?string &$error = null, int $timeout = 40): ?array {
    $error = null;
    try {
        switch ($path) {
            case '/api/exam/generate':       return ex_generate_exam($payload);
            case '/api/exam/grade':          return ex_grade_attempt($payload);
            case '/api/exam/manual-grade':   return ex_manual_grading_apply($payload);
            case '/api/exam/stats':          return ex_stats_compute($payload);
            case '/api/authoring/exam':      return ex_exam_authoring_normalize($payload);
            case '/api/authoring/question':  return ex_question_authoring_build($payload);
        }
    } catch (\Throwable $e) {
        $error = 'Silnik egzaminów zgłosił błąd (' . $path . '): ' . $e->getMessage();
        return null;
    }
    $error = 'Nieznana operacja silnika egzaminów: ' . $path;
    return null;
}

/**
 * Podpowiedź diagnostyczna dla prowadzącego, gdy zadania programistyczne nie
 * mają działającej piaskownicy. Sam silnik (ocenianie, losowanie, walidacja)
 * działa zawsze — w procesie PHP; piaskownicy wymaga tylko uruchamianie kodu.
 */
function ti_exam_engine_hint(): string {
    $cfg = ex_sandbox_config();
    return 'Kod kursanta uruchamia robotnik cli/exam_sandbox_worker.php jako użytkownik '
         . $cfg['user'] . ' (sudo -n -u ' . $cfg['user'] . '). Sprawdź, czy w obrazie (docker/Dockerfile) '
         . 'istnieje to konto, wpis sudoers i interpretery wybranych języków.';
}

/** Stan silnika — używane w panelu prowadzącego i przy diagnostyce. Bez sieci. */
function ti_exam_engine_health(): array {
    $langs = [];
    foreach (ex_lang_describe_all() as $l) {
        $l['available'] = ex_sandbox_available((string)$l['id']);
        $langs[] = $l;
    }
    return ['ok' => true, 'status' => 'ok', 'engine' => 'php', 'languages' => $langs];
}

/** Języki, dla których piaskownica ma działający interpreter/kompilator. */
function ti_exam_available_languages(): array {
    $out = [];
    foreach (ti_exam_engine_health()['languages'] as $l) {
        $id = (string)($l['id'] ?? '');
        if ($id !== '' && !empty($l['available']) && isset(K30_TI_EXAM_LANGS[$id])) {
            $out[$id] = K30_TI_EXAM_LANGS[$id];
        }
    }
    return $out ?: K30_TI_EXAM_LANGS;
}

// ═══════════════════════════════════════════════════════════════════════════
//  EGZAMINY — CRUD
// ═══════════════════════════════════════════════════════════════════════════

function ti_exam_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_exams WHERE id=?", [$id]);
}

function ti_exams_list(int $course_id, bool $only_active = false): array {
    $sql = "SELECT e.*,
                   (SELECT COUNT(*) FROM k30_ti_exam_questions q WHERE q.exam_id=e.id) AS n_questions,
                   (SELECT COUNT(*) FROM k30_ti_exam_attempts  a WHERE a.exam_id=e.id) AS n_attempts,
                   (SELECT COUNT(*) FROM k30_ti_exam_attempts  a WHERE a.exam_id=e.id AND a.needs_review=1) AS n_review
            FROM k30_ti_exams e WHERE e.course_id=?";
    if ($only_active) $sql .= " AND e.is_active=1";
    $sql .= " ORDER BY e.is_active DESC, e.id DESC";
    return db_all($sql, [$course_id]);
}

/**
 * Zapis egzaminu z wartości ZNORMALIZOWANYCH PRZEZ SILNIK.
 *
 * $norm to obiekt `exam` zwrócony przez '/api/authoring/exam' — o tym, co
 * jest dozwoloną wartością którego pola, decyduje wyłącznie silnik
 * (ex_exam_authoring_normalize w modules/equi_exams/logic/authoring.php).
 * Ta funkcja tylko przekłada klucze
 * na kolumny; nie podejmuje żadnych decyzji o treści ustawień.
 */
function ti_exam_save(array $norm, int $course_id, ?int $id = null, ?int $user_id = null): int {
    $f = [
        'course_id'         => $course_id,
        'session_id'        => !empty($norm['sessionId']) ? (int)$norm['sessionId'] : null,
        'title'             => (string)($norm['title'] ?? ''),
        'description'       => (string)($norm['description'] ?? ''),
        'mode'              => (string)($norm['mode'] ?? 'exam'),
        'time_limit_min'    => (int)($norm['timeLimitMin'] ?? 0),
        'pass_pct'          => (int)($norm['passPct'] ?? 0),
        'max_attempts'      => (int)($norm['maxAttempts'] ?? 1),
        'shuffle_questions' => !empty($norm['shuffleQuestions']) ? 1 : 0,
        'shuffle_options'   => !empty($norm['shuffleOptions'])   ? 1 : 0,
        'fixed_draw'        => (int)($norm['fixedDraw'] ?? 0),
        'bank_draw'         => (int)($norm['bankDraw']  ?? 0),
        'show_feedback'     => (string)($norm['showFeedback'] ?? 'never'),
        'neg_marking'       => (string)($norm['negMarking']   ?? 'partial'),
        'open_at'           => ($norm['openAt']  ?? '') !== '' ? (string)$norm['openAt']  : null,
        'close_at'          => ($norm['closeAt'] ?? '') !== '' ? (string)$norm['closeAt'] : null,
        'is_active'         => !empty($norm['isActive'])  ? 1 : 0,
        'sync_grade'        => !empty($norm['syncGrade']) ? 1 : 0,
        'grade_weight'      => (int)($norm['gradeWeight'] ?? 3),
        'updated_at'        => date('Y-m-d H:i:s'),
    ];
    if ($id) { db_update('k30_ti_exams', $f, $id); return $id; }
    $f['created_by'] = $user_id;
    $f['created_at'] = date('Y-m-d H:i:s');
    return db_insert('k30_ti_exams', $f);
}

/**
 * Waliduje i zapisuje ustawienia egzaminu — cała ścieżka autorska prowadzącego.
 *
 * @param array       $form   surowe pola formularza (klucze jak w ExamAuthoring)
 * @param array|null  $issues wyjściowo: ['errors'=>[…], 'warnings'=>[…]]
 * @param string|null $error  wyjściowo: powód niedostępności silnika
 * @return int|null           identyfikator egzaminu albo null gdy zapis nie doszedł do skutku
 */
function ti_exam_save_via_engine(int $course_id, ?int $exam_id, array $form,
                                 ?int $user_id, ?array &$issues = null, ?string &$error = null): ?int {
    $issues = ['errors' => [], 'warnings' => []];
    if ($exam_id) $form['questionCount'] = count(ti_exam_questions($exam_id));

    $res = ti_exam_engine_post('/api/authoring/exam', ['form' => $form], $error, 15);
    if (!$res) return null;

    $issues['errors']   = (array)($res['errors']   ?? []);
    $issues['warnings'] = (array)($res['warnings'] ?? []);
    if (empty($res['ok'])) return null;

    return ti_exam_save((array)($res['exam'] ?? []), $course_id, $exam_id ?: null, $user_id);
}

function ti_exam_delete(int $id): void {
    // Kasujemy jawnie zamiast liczyć na ON DELETE CASCADE — klucze obce
    // bywają w SQLite wyłączone, a moduł ma działać też na MySQL.
    $pdo = db();
    $pdo->prepare("DELETE FROM k30_ti_exam_answers WHERE attempt_id IN (SELECT id FROM k30_ti_exam_attempts WHERE exam_id=?)")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exam_attempts WHERE exam_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exam_options  WHERE question_id IN (SELECT id FROM k30_ti_exam_questions WHERE exam_id=?)")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exam_cases    WHERE question_id IN (SELECT id FROM k30_ti_exam_questions WHERE exam_id=?)")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exam_questions WHERE exam_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exams WHERE id=?")->execute([$id]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  PYTANIA, WARIANTY, PRZYPADKI TESTOWE
// ═══════════════════════════════════════════════════════════════════════════

function ti_exam_questions(int $exam_id, ?bool $in_bank = null): array {
    $sql = "SELECT * FROM k30_ti_exam_questions WHERE exam_id=?";
    $par = [$exam_id];
    if ($in_bank !== null) { $sql .= " AND in_bank=?"; $par[] = $in_bank ? 1 : 0; }
    return db_all($sql . " ORDER BY position, id", $par);
}

function ti_exam_question_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_exam_questions WHERE id=?", [$id]);
}

function ti_exam_options(int $question_id): array {
    return db_all("SELECT * FROM k30_ti_exam_options WHERE question_id=? ORDER BY position, id", [$question_id]);
}

function ti_exam_cases(int $question_id): array {
    return db_all("SELECT * FROM k30_ti_exam_cases WHERE question_id=? ORDER BY position, id", [$question_id]);
}

/** Konfiguracja pytania jako tablica (kolumna trzyma JSON). */
function ti_exam_config(array $question): array {
    $raw = (string)($question['config'] ?? '');
    if ($raw === '') return [];
    $cfg = json_decode($raw, true);
    return is_array($cfg) ? $cfg : [];
}

/**
 * Zapis pytania z definicji ZBUDOWANEJ PRZEZ SILNIK.
 *
 * $norm to obiekt `question` zwrócony przez '/api/authoring/question'.
 * O tym, jak wygląda poprawne pytanie danego typu (jakie pola konfiguracji,
 * jakie warianty, jakie przypadki testowe), decyduje wyłącznie silnik
 * (ex_question_authoring_build w modules/equi_exams/logic/authoring.php).
 * Ta warstwa wykonuje tylko zapis do bazy.
 */
function ti_exam_question_save(array $norm, int $exam_id, ?int $id = null): int {
    $f = [
        'type'        => (string)($norm['type'] ?? 'single'),
        'prompt'      => (string)($norm['prompt'] ?? ''),
        'points'      => (float)($norm['points'] ?? 1),
        'in_bank'     => !empty($norm['inBank']) ? 1 : 0,
        'explanation' => (string)($norm['explanation'] ?? ''),
        'config'      => json_encode((array)($norm['config'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $pdo = db();
    if ($id) {
        db_update('k30_ti_exam_questions', $f, $id);
    } else {
        $pos = (int)(db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ti_exam_questions WHERE exam_id=?", [$exam_id])['p'] ?? 1);
        $id  = db_insert('k30_ti_exam_questions', ['exam_id' => $exam_id, 'position' => $pos] + $f);
    }

    // Warianty i przypadki nadpisujemy w całości. Identyfikatory wariantów się
    // przy tym zmieniają, ale nie psuje to starszych podejść: każda odpowiedź
    // trzyma własną kopię wskazań w kolumnie `payload`, a wynik w `result`.
    $pdo->prepare("DELETE FROM k30_ti_exam_options WHERE question_id=?")->execute([$id]);
    $pos = 0;
    foreach ((array)($norm['options'] ?? []) as $opt) {
        db_insert('k30_ti_exam_options', [
            'question_id' => $id,
            'position'    => $pos++,
            'label'       => (string)($opt['label'] ?? ''),
            'is_correct'  => !empty($opt['correct']) ? 1 : 0,
            'feedback'    => (string)($opt['feedback'] ?? ''),
        ]);
    }

    $pdo->prepare("DELETE FROM k30_ti_exam_cases WHERE question_id=?")->execute([$id]);
    $pos = 0;
    foreach ((array)($norm['cases'] ?? []) as $case) {
        db_insert('k30_ti_exam_cases', [
            'question_id' => $id,
            'position'    => $pos++,
            'name'        => (string)($case['name'] ?? ''),
            'stdin'       => (string)($case['stdin'] ?? ''),
            'expected'    => (string)($case['expected'] ?? ''),
            'match_mode'  => (string)($case['matchMode'] ?? 'trim'),
            'weight'      => (float)($case['weight'] ?? 1),
            'is_hidden'   => !empty($case['hidden']) ? 1 : 0,
            'tolerance'   => (float)($case['tolerance'] ?? 0.000001),
        ]);
    }
    return (int)$id;
}

/**
 * Waliduje formularz pytania w silniku i zapisuje wynik.
 *
 * @param array       $req    ['type'=>…, 'prompt'=>…, 'points'=>…, 'inBank'=>…,
 *                             'explanation'=>…, 'form'=>[…surowe pola…]]
 * @param array|null  $issues wyjściowo: ['errors'=>[…], 'warnings'=>[…]]
 * @param string|null $error  wyjściowo: powód niedostępności silnika
 * @return int|null           identyfikator pytania albo null gdy nie zapisano
 */
function ti_exam_question_save_via_engine(int $exam_id, ?int $question_id, array $req,
                                          ?array &$issues = null, ?string &$error = null): ?int {
    $issues = ['errors' => [], 'warnings' => []];
    $res = ti_exam_engine_post('/api/authoring/question', $req, $error, 20);
    if (!$res) return null;

    $issues['errors']   = (array)($res['errors']   ?? []);
    $issues['warnings'] = (array)($res['warnings'] ?? []);
    if (empty($res['ok'])) return null;

    return ti_exam_question_save((array)($res['question'] ?? []), $exam_id, $question_id ?: null);
}

function ti_exam_question_delete(int $id): void {
    $pdo = db();
    $pdo->prepare("DELETE FROM k30_ti_exam_options WHERE question_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exam_cases   WHERE question_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM k30_ti_exam_questions WHERE id=?")->execute([$id]);
}

/** Przesuwa pytanie o jedną pozycję w górę (-1) albo w dół (+1). */
function ti_exam_question_move(int $id, int $dir): void {
    $q = ti_exam_question_get($id);
    if (!$q) return;
    $rows = ti_exam_questions((int)$q['exam_id']);
    $idx  = null;
    foreach ($rows as $i => $r) if ((int)$r['id'] === $id) { $idx = $i; break; }
    if ($idx === null) return;
    $j = $idx + ($dir < 0 ? -1 : 1);
    if ($j < 0 || $j >= count($rows)) return;
    [$rows[$idx], $rows[$j]] = [$rows[$j], $rows[$idx]];
    $stmt = db()->prepare("UPDATE k30_ti_exam_questions SET position=? WHERE id=?");
    foreach ($rows as $i => $r) $stmt->execute([$i + 1, (int)$r['id']]);
}

/** Maksymalna punktacja zestawu (opcjonalnie ograniczona do wylosowanych pytań). */
function ti_exam_max_score(int $exam_id, ?array $ids = null): float {
    if ($ids !== null) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return 0.0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $r  = db_one("SELECT COALESCE(SUM(points),0) AS s FROM k30_ti_exam_questions WHERE id IN ($ph) AND exam_id=?", [...$ids, $exam_id]);
    } else {
        $r = db_one("SELECT COALESCE(SUM(points),0) AS s FROM k30_ti_exam_questions WHERE exam_id=?", [$exam_id]);
    }
    return (float)($r['s'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SERIALIZACJA DLA SILNIKA
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Pytanie w formacie oczekiwanym przez silnik (modules/equi_exams/logic).
 *
 * @param bool $withAnswers false = wersja dla kursanta (bez poprawnych odpowiedzi
 *                          i bez ukrytych przypadków) — używane, gdy trzeba
 *                          przekazać definicję do przeglądarki.
 */
function ti_exam_question_payload(array $q, bool $withAnswers = true): array {
    $type = (string)$q['type'];
    $meta = K30_TI_EXAM_TYPES[$type] ?? K30_TI_EXAM_TYPES['single'];
    $cfg  = ti_exam_config($q);

    $payload = [
        'id'          => (int)$q['id'],
        'type'        => $type,
        'position'    => (int)$q['position'],
        'points'      => (float)$q['points'],
        'inBank'      => (int)$q['in_bank'] === 1,
        'prompt'      => (string)$q['prompt'],
        'explanation' => $withAnswers ? (string)$q['explanation'] : '',
        'config'      => $cfg,
        'options'     => [],
        'cases'       => [],
    ];

    if (!empty($meta['options'])) {
        foreach (ti_exam_options((int)$q['id']) as $o) {
            $row = ['id' => (int)$o['id'], 'label' => (string)$o['label']];
            if ($withAnswers) {
                $row['isCorrect'] = (int)$o['is_correct'] === 1;
                $row['feedback']  = (string)$o['feedback'];
            }
            $payload['options'][] = $row;
        }
    }
    // Wersja bez odpowiedzi: config dopasowywania/kolejności zdradza pary i
    // kolejność — zostawiamy same elementy w kolejności neutralnej (po kluczu).
    if (!$withAnswers && ($type === 'matching' || $type === 'ordering')) {
        $byKey = function (array $rows): array { usort($rows, fn($a, $b) => strcmp($a['key'], $b['key'])); return $rows; };
        $payload['config'] = $type === 'matching'
            ? ['lefts' => array_map(fn($p) => ['key' => $p['key'], 'label' => $p['left']], ex_matching_pairs($cfg)),
               'rights' => $byKey(ex_matching_right_pool($cfg))]
            : ['items' => $byKey(ex_ordering_items($cfg))];
    }
    if (!empty($meta['cases'])) {
        foreach (ti_exam_cases((int)$q['id']) as $c) {
            if (!$withAnswers && (int)$c['is_hidden'] === 1) continue;
            $row = [
                'name'      => (string)$c['name'],
                'stdin'     => (string)$c['stdin'],
                'matchMode' => (string)$c['match_mode'],
                'weight'    => (float)$c['weight'],
                'hidden'    => (int)$c['is_hidden'] === 1,
                'tolerance' => (float)$c['tolerance'],
            ];
            if ($withAnswers) $row['expected'] = (string)$c['expected'];
            $payload['cases'][] = $row;
        }
    }
    return $payload;
}

// ═══════════════════════════════════════════════════════════════════════════
//  DOSTĘPNOŚĆ EGZAMINU DLA KURSANTA
// ═══════════════════════════════════════════════════════════════════════════

/** upcoming | open | closed — okno czasowe udostępnienia egzaminu. */
function ti_exam_window_status(array $exam): string {
    $now  = time();
    $open = trim((string)($exam['open_at']  ?? ''));
    $shut = trim((string)($exam['close_at'] ?? ''));
    if ($open !== '' && $now < strtotime($open)) return 'upcoming';
    if ($shut !== '' && $now > strtotime($shut)) return 'closed';
    return 'open';
}

function ti_exam_attempts_for_client(int $exam_id, int $client_id): array {
    return db_all(
        "SELECT * FROM k30_ti_exam_attempts WHERE exam_id=? AND client_id=? ORDER BY attempt_no, id",
        [$exam_id, $client_id]);
}

function ti_exam_open_attempt(int $exam_id, int $client_id): ?array {
    return db_one(
        "SELECT * FROM k30_ti_exam_attempts
         WHERE exam_id=? AND client_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1",
        [$exam_id, $client_id]);
}

/** Najlepsze ocenione podejście (do listy i do e-dziennika). */
function ti_exam_best_attempt(int $exam_id, int $client_id): ?array {
    return db_one(
        "SELECT * FROM k30_ti_exam_attempts
         WHERE exam_id=? AND client_id=? AND status IN ('submitted','graded')
         ORDER BY (CASE WHEN max_score > 0 THEN score / max_score ELSE 0 END) DESC, id DESC LIMIT 1",
        [$exam_id, $client_id]);
}

/**
 * Czy kursant może rozpocząć nowe podejście.
 * Powód odmowy trafia do $why w formie gotowej do pokazania kursantowi.
 */
function ti_exam_can_start(array $exam, int $client_id, ?string &$why = null): bool {
    $why = null;
    if (empty($exam['is_active'])) { $why = 'Egzamin nie jest udostępniony.'; return false; }

    $w = ti_exam_window_status($exam);
    if ($w === 'upcoming') {
        $why = 'Egzamin będzie dostępny od ' . date('d.m.Y H:i', strtotime((string)$exam['open_at'])) . '.';
        return false;
    }
    if ($w === 'closed') { $why = 'Termin rozwiązywania tego egzaminu już minął.'; return false; }

    if (!ti_exam_questions((int)$exam['id'])) { $why = 'Egzamin nie ma jeszcze pytań.'; return false; }

    $max = (int)$exam['max_attempts'];
    if ($max > 0) {
        $used = (int)(db_one(
            "SELECT COUNT(*) AS n FROM k30_ti_exam_attempts WHERE exam_id=? AND client_id=? AND status<>'in_progress'",
            [(int)$exam['id'], $client_id])['n'] ?? 0);
        if ($used >= $max) {
            $why = $max === 1
                ? 'Ten egzamin można rozwiązać tylko raz — Twoje podejście zostało już zapisane.'
                : 'Wykorzystano wszystkie dostępne podejścia (' . $max . ').';
            return false;
        }
    }
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
//  PODEJŚCIA
// ═══════════════════════════════════════════════════════════════════════════

function ti_exam_attempt_get(int $id): ?array {
    return db_one("SELECT * FROM k30_ti_exam_attempts WHERE id=?", [$id]);
}

function ti_exam_attempts_for_exam(int $exam_id): array {
    return db_all(
        "SELECT a.*, cl.name AS client_name
         FROM k30_ti_exam_attempts a
         JOIN k30_clients cl ON cl.id = a.client_id
         WHERE a.exam_id=? ORDER BY cl.name, a.attempt_no, a.id",
        [$exam_id]);
}

/**
 * Rozpoczyna podejście: prosi silnik (ex_generate_exam) o złożenie wariantu
 * (losowanie z banku, kolejność pytań i wariantów) i zapisuje go w podejściu.
 *
 * Gdyby generator zgłosił wyjątek, wariant składa awaryjnie
 * ti_exam_compose_fallback — kursant nie zostaje zablokowany przy wejściu na
 * salę. To dotyczy WYŁĄCZNIE ułożenia zestawu; ocenianie nie ma ścieżki
 * awaryjnej (patrz ti_exam_submit), bo wynik musi pochodzić z jednego,
 * autorytatywnego miejsca.
 */
function ti_exam_start_attempt(int $exam_id, int $client_id): ?array {
    $exam = ti_exam_get($exam_id);
    if (!$exam) return null;

    $open = ti_exam_open_attempt($exam_id, $client_id);
    if ($open) return $open;

    $questions = ti_exam_questions($exam_id);
    if (!$questions) return null;

    $payload = [];
    foreach ($questions as $q) $payload[] = ti_exam_question_payload($q, true);

    $seed = random_int(1, PHP_INT_MAX);
    $req  = [
        'seed'      => $seed,
        'blueprint' => [
            'fixedDraw'        => (int)$exam['fixed_draw'],
            'bankDraw'         => (int)$exam['bank_draw'],
            'shuffleQuestions' => (int)$exam['shuffle_questions'] === 1,
            'shuffleOptions'   => (int)$exam['shuffle_options'] === 1,
        ],
        'questions' => $payload,
    ];

    $err  = null;
    $plan = ti_exam_engine_post('/api/exam/generate', $req, $err, 15);
    $engine_status = 'ok';
    if (!$plan || empty($plan['questionOrder'])) {
        $plan = ti_exam_compose_fallback($exam, $questions, $seed);
        $engine_status = 'fallback';
    }

    $ids       = array_map('intval', (array)$plan['questionOrder']);
    $max_score = ti_exam_max_score($exam_id, $ids);
    $limit     = (int)$exam['time_limit_min'];
    $now       = time();

    $attempt_no = 1 + (int)(db_one(
        "SELECT COALESCE(MAX(attempt_no),0) AS n FROM k30_ti_exam_attempts WHERE exam_id=? AND client_id=?",
        [$exam_id, $client_id])['n'] ?? 0);

    // Twardy koniec podejścia to minimum z limitu czasu i terminu zamknięcia
    // egzaminu — inaczej wejście tuż przed zamknięciem dawałoby pełny limit.
    $deadline = $limit > 0 ? $now + $limit * 60 : null;
    $close    = trim((string)($exam['close_at'] ?? ''));
    if ($close !== '') {
        $close_ts = strtotime($close);
        $deadline = $deadline === null ? $close_ts : min($deadline, $close_ts);
    }

    $id = db_insert('k30_ti_exam_attempts', [
        'exam_id'       => $exam_id,
        'client_id'     => $client_id,
        'attempt_no'    => $attempt_no,
        'status'        => 'in_progress',
        'score'         => 0,
        'max_score'     => $max_score,
        'needs_review'  => 0,
        'seed'          => (int)($plan['seed'] ?? $seed),
        'drawn_ids'     => json_encode($ids),
        'option_order'  => json_encode($plan['optionOrder'] ?? new stdClass()),
        'engine_status' => $engine_status,
        'started_at'    => date('Y-m-d H:i:s', $now),
        'deadline_at'   => $deadline ? date('Y-m-d H:i:s', $deadline) : null,
    ]);
    return ti_exam_attempt_get($id);
}

/**
 * Awaryjne złożenie wariantu — używane tylko gdy generator silnika zawiedzie
 * (wyjątek albo pusty plan). Odwzorowuje regułę silnika: pytania stałe +
 * losowanie z banku, opcjonalne przetasowanie pytań i wariantów, wszystko
 * deterministycznie względem ziarna. Celowo pozostawione jako zabezpieczenie.
 */
function ti_exam_compose_fallback(array $exam, array $questions, int $seed): array {
    mt_srand($seed);
    $fixed = $bank = [];
    foreach ($questions as $q) {
        if ((int)$q['in_bank'] === 1) $bank[] = $q; else $fixed[] = $q;
    }
    $pick = function (array $pool, int $n): array {
        if ($n <= 0 || $n >= count($pool)) return $pool;
        $keys = array_keys($pool);
        shuffle($keys);
        $keys = array_slice($keys, 0, $n);
        sort($keys);
        return array_map(fn($k) => $pool[$k], $keys);
    };
    $chosen = array_merge(
        $pick($fixed, (int)$exam['fixed_draw']),
        $pick($bank,  (int)$exam['bank_draw'])
    );
    if ((int)$exam['shuffle_questions'] === 1) shuffle($chosen);

    $order = [];
    $opts  = [];
    foreach ($chosen as $q) {
        $qid     = (int)$q['id'];
        $order[] = $qid;
        // Dopasowywanie / kolejność: ta sama reguła co w generatorze (zawsze tasowane).
        $items = ex_items_shuffle_order(['type' => (string)$q['type'], 'config' => ti_exam_config($q)]);
        if ($items !== null) { $opts[(string)$qid] = $items; continue; }
        $ids     = array_map(fn($o) => (int)$o['id'], ti_exam_options($qid));
        if (!$ids) continue;
        // Kolejność wariantów tasujemy tylko tam, gdzie ma to sens — w lukach
        // i zadaniach z kodem warianty nie występują.
        if ((int)$exam['shuffle_options'] === 1) shuffle($ids);
        $opts[(string)$qid] = $ids;
    }
    mt_srand();
    return ['seed' => $seed, 'questionOrder' => $order, 'optionOrder' => $opts];
}

/** Pytania podejścia w kolejności zapisanej przy losowaniu. */
function ti_exam_attempt_questions(array $attempt): array {
    $ids = json_decode((string)($attempt['drawn_ids'] ?? ''), true);
    if (!is_array($ids) || !$ids) return ti_exam_questions((int)$attempt['exam_id']);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_all("SELECT * FROM k30_ti_exam_questions WHERE id IN ($ph)", $ids);
    $by   = [];
    foreach ($rows as $r) $by[(int)$r['id']] = $r;
    $out = [];
    foreach ($ids as $id) if (isset($by[$id])) $out[] = $by[$id];
    return $out;
}

/** Kolejność wariantów wylosowana dla podejścia: [question_id => [option_id, …]]. */
function ti_exam_attempt_option_order(array $attempt): array {
    $raw = json_decode((string)($attempt['option_order'] ?? ''), true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $qid => $ids) $out[(int)$qid] = array_map('intval', (array)$ids);
    return $out;
}

/** Warianty pytania w kolejności z podejścia (albo naturalnej, gdy brak zapisu). */
function ti_exam_options_ordered(int $question_id, array $order_map): array {
    $opts = ti_exam_options($question_id);
    $want = $order_map[$question_id] ?? [];
    if (!$want) return $opts;
    $by = [];
    foreach ($opts as $o) $by[(int)$o['id']] = $o;
    $out = [];
    foreach ($want as $id) if (isset($by[$id])) { $out[] = $by[$id]; unset($by[$id]); }
    foreach ($by as $o) $out[] = $o;   // warianty dodane po rozpoczęciu podejścia — na końcu
    return $out;
}

/**
 * Elementy pytania 'matching'/'ordering' w kolejności pokazywanej kursantowi.
 *
 * $items to lista z ex_matching_right_pool() albo ex_ordering_items(); $perm —
 * permutacja indeksów zapisana przy losowaniu (option_order). Gdy zapisu brak
 * albo nie pasuje (prowadzący zmienił pytanie po starcie podejścia), elementy
 * idą w kolejności kluczy — neutralnej, niezwiązanej z kolejnością poprawną.
 */
function ti_exam_items_ordered(array $items, array $perm): array {
    $n = count($items);
    $p = array_map('intval', $perm);
    $ok = count($p) === $n;
    if ($ok) { $s = $p; sort($s); $ok = $s === range(0, max(0, $n - 1)) || $n === 0; }
    if ($ok) return array_map(fn($i) => $items[$i], $p);
    usort($items, fn($a, $b) => strcmp((string)$a['key'], (string)$b['key']));
    return $items;
}

/**
 * Komunikat o niejednoznacznej odpowiedzi do pokazania kursantowi przed
 * oddaniem pracy (dziś: powtórzone pozycje w 'ordering'). null = wszystko gra.
 * Punktacja i tak się liczy — powtórzone pozycje silnik uznaje za błędne.
 */
function ti_exam_answer_issue(array $question, array $payload): ?string {
    if ((string)$question['type'] !== 'ordering') return null;
    $dups = ex_ordering_duplicates(ex_answer_order($payload));
    if (!$dups) return null;
    return (count($dups) === 1 ? 'Pozycja ' . $dups[0] . ' jest przypisana' : 'Pozycje ' . implode(', ', $dups) . ' są przypisane')
         . ' więcej niż jednemu elementowi. Każdy element powinien mieć inny numer — powtórzone pozycje zostaną policzone jako błędne.';
}

function ti_exam_answers(int $attempt_id): array {
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_exam_answers WHERE attempt_id=?", [$attempt_id]) as $r) {
        $out[(int)$r['question_id']] = $r;
    }
    return $out;
}

/** Odpowiedź kursanta jako tablica (kolumna trzyma JSON). */
function ti_exam_answer_payload(?array $row): array {
    if (!$row) return [];
    $p = json_decode((string)($row['payload'] ?? ''), true);
    return is_array($p) ? $p : [];
}

/**
 * Przekłada surowe pola arkusza (`a[idPytania][...]`) na kanoniczny kształt
 * odpowiedzi rozumiany przez silnik. To wyłącznie zmiana formatu — o tym, czy
 * odpowiedź jest poprawna, decyduje silnik (grading.php).
 */
function ti_exam_payload_from_post($raw): array {
    if (!is_array($raw)) return [];
    $out = [];

    if (isset($raw['optionIds'])) {
        $out['optionIds'] = array_values(array_map('intval', (array)$raw['optionIds']));
    }
    if (isset($raw['statements']) && is_array($raw['statements'])) {
        $st = [];
        foreach ($raw['statements'] as $k => $v) {
            if ($v === '' || $v === null) continue;      // twierdzenie pominięte
            $st[(string)(int)$k] = ((string)$v === '1');
        }
        $out['statements'] = $st;
    }
    if (isset($raw['blanks']) && is_array($raw['blanks'])) {
        $bl = [];
        foreach ($raw['blanks'] as $k => $v) $bl[(string)$k] = (string)$v;
        $out['blanks'] = $bl;
    }
    // Dopasowywanie par: a[q][pairs][kluczLewego] = kluczPrawego; kolejność:
    // a[q][order][kluczElementu] = pozycja. Klucze to skróty z silnika (ex_item_key).
    if (isset($raw['pairs']) && is_array($raw['pairs'])) {
        $pr = [];
        foreach ($raw['pairs'] as $k => $v) {
            if (is_array($v)) continue;
            $k = preg_replace('/[^A-Za-z0-9]/', '', (string)$k);
            $v = preg_replace('/[^A-Za-z0-9]/', '', (string)$v);
            if ($k !== '' && $v !== '') $pr[$k] = $v;
        }
        $out['pairs'] = $pr;
    }
    if (isset($raw['order']) && is_array($raw['order'])) {
        $or = [];
        foreach ($raw['order'] as $k => $v) {
            if (is_array($v)) continue;
            $k = preg_replace('/[^A-Za-z0-9]/', '', (string)$k);
            $n = (int)$v;
            if ($k !== '' && $n >= 1) $or[$k] = $n;
        }
        $out['order'] = $or;
    }
    if (isset($raw['text']) && trim((string)$raw['text']) !== '') $out['text'] = (string)$raw['text'];
    if (isset($raw['code']) && trim((string)$raw['code']) !== '') $out['code'] = (string)$raw['code'];
    if (isset($raw['line']) && (string)$raw['line'] !== '')       $out['line'] = (int)$raw['line'];

    return $out;
}

/** Zapis (autozapis) pojedynczej odpowiedzi w trwającym podejściu. */
function ti_exam_answer_save(int $attempt_id, int $question_id, array $payload): void {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now  = date('Y-m-d H:i:s');
    $has  = db_one("SELECT id FROM k30_ti_exam_answers WHERE attempt_id=? AND question_id=?", [$attempt_id, $question_id]);
    if ($has) {
        db()->prepare("UPDATE k30_ti_exam_answers SET payload=?, answered_at=? WHERE id=?")
            ->execute([$json, $now, (int)$has['id']]);
    } else {
        db_insert('k30_ti_exam_answers', [
            'attempt_id'  => $attempt_id,
            'question_id' => $question_id,
            'payload'     => $json,
            'answered_at' => $now,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  MARKA MODUŁU — „Equi Exams"
// ═══════════════════════════════════════════════════════════════════════════
//
// Jedno narzędzie, dwie nazwy w interfejsie:
//   • prowadzący / administracja → „Egzaminy",
//   • kursant / opiekun          → „Testy".
// Nazwa własna narzędzia pojawia się w stopce obu paneli.

const EQUI_EXAMS_NAME        = 'Equi Exams';
const EQUI_EXAMS_STAFF_LABEL = 'Egzaminy';
const EQUI_EXAMS_STUDENT_LABEL = 'Testy';
const EQUI_EXAMS_AUTHOR      = 'Ziemowit Gil';

/** Etykieta modułu zależna od odbiorcy: 'staff' albo 'student'. */
function equi_exams_label(string $audience = 'staff'): string {
    return $audience === 'student' ? EQUI_EXAMS_STUDENT_LABEL : EQUI_EXAMS_STAFF_LABEL;
}

/** Stopka „Powered by…". Zwraca gotowy HTML — treść jest stała, nic do ucieczki. */
function equi_exams_footer_html(string $class = 'text-body-secondary small mt-4 pt-3 border-top'): string {
    return '<p class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">'
         . '<span class="fw-semibold">Powered by Equi Exam</span>'
         . ' <span aria-hidden="true">|</span> Wykonanie: ' . EQUI_EXAMS_AUTHOR
         . '</p>';
}

// ═══════════════════════════════════════════════════════════════════════════
//  ODDANIE I OCENA
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Oddanie podejścia: komplet odpowiedzi jedzie do silnika (ex_grade_attempt),
 * wraca punktacja.
 *
 * Ocenianie nie ma drugiej, „awaryjnej” implementacji — świadomie. Gdyby ta
 * warstwa liczyła punkty „na wszelki wypadek", istniałyby dwie implementacje
 * tej samej reguły i prędzej czy później rozjechałyby się wynikami. Gdy silnik
 * zgłosi błąd (np. piaskownica kodu nie działa), podejście zostaje zapisane
 * jako oddane, ze znacznikiem `engine_status='pending'` i `needs_review=1`;
 * prowadzący widzi je na liście „do oceny" i może uruchomić ponowną ocenę
 * jednym przyciskiem.
 *
 * @return array{ok:bool, error:?string, attempt:?array}
 */
function ti_exam_submit(int $attempt_id, bool $late_allowed = true): array {
    $att = ti_exam_attempt_get($attempt_id);
    if (!$att) return ['ok' => false, 'error' => 'Nie ma takiego podejścia.', 'attempt' => null];
    if ($att['status'] !== 'in_progress') {
        return ['ok' => true, 'error' => null, 'attempt' => $att];  // powtórny submit — nic nie psujemy
    }

    $late = false;
    $dl   = trim((string)($att['deadline_at'] ?? ''));
    if ($dl !== '' && time() > strtotime($dl) + 30) {   // 30 s marginesu na opóźnienie sieci
        if (!$late_allowed) return ['ok' => false, 'error' => 'Czas na rozwiązanie testu minął.', 'attempt' => $att];
        $late = true;
    }

    db()->prepare("UPDATE k30_ti_exam_attempts SET status='submitted', submitted_at=?, is_late=? WHERE id=?")
        ->execute([date('Y-m-d H:i:s'), $late ? 1 : 0, $attempt_id]);

    return ti_exam_regrade($attempt_id);
}

/**
 * Wysyła (ponownie) podejście do oceny w silniku i zapisuje wynik.
 * Wywoływane przy oddaniu testu oraz z panelu prowadzącego („Oceń ponownie").
 */
function ti_exam_regrade(int $attempt_id): array {
    $att = ti_exam_attempt_get($attempt_id);
    if (!$att) return ['ok' => false, 'error' => 'Nie ma takiego podejścia.', 'attempt' => null];
    $exam = ti_exam_get((int)$att['exam_id']);
    if (!$exam) return ['ok' => false, 'error' => 'Nie ma takiego egzaminu.', 'attempt' => $att];

    $questions = ti_exam_attempt_questions($att);
    $answers   = ti_exam_answers($attempt_id);

    $qp = [];
    $ap = [];
    foreach ($questions as $q) {
        $qp[] = ti_exam_question_payload($q, true);
        $ap[(string)(int)$q['id']] = ti_exam_answer_payload($answers[(int)$q['id']] ?? null);
    }

    $err    = null;
    $result = ti_exam_engine_post('/api/exam/grade', [
        'mode'          => (string)$exam['mode'],
        'negMarking'    => (string)$exam['neg_marking'],
        'passPct'       => (int)$exam['pass_pct'],
        'revealAnswers' => true,     // szczegóły zapisujemy w bazie; komu je pokazać, decyduje widok
        'questions'     => $qp,
        'answers'       => $ap,
    ], $err, 120);

    if (!$result) {
        db()->prepare("UPDATE k30_ti_exam_attempts SET needs_review=1, engine_status='pending', engine_error=? WHERE id=?")
            ->execute([(string)$err, $attempt_id]);
        return ['ok' => false, 'error' => $err, 'attempt' => ti_exam_attempt_get($attempt_id)];
    }

    ti_exam_store_results($attempt_id, $result);
    $att = ti_exam_attempt_get($attempt_id);
    if ($att && $att['status'] === 'graded') ti_exam_sync_grade($attempt_id);
    return ['ok' => true, 'error' => null, 'attempt' => $att];
}

/** Zapisuje odpowiedź silnika: punkty per pytanie + podsumowanie podejścia. */
function ti_exam_store_results(int $attempt_id, array $result): void {
    $pdo = db();
    $upd = $pdo->prepare(
        "UPDATE k30_ti_exam_answers
         SET result=?, points_awarded=?, is_correct=?, needs_review=?, feedback=?
         WHERE attempt_id=? AND question_id=?");

    $needs_review = false;
    foreach ((array)($result['results'] ?? []) as $r) {
        $qid    = (int)($r['questionId'] ?? 0);
        if (!$qid) continue;
        $review = !empty($r['needsReview']);
        $needs_review = $needs_review || $review;

        // Pytanie ocenione ręcznie wcześniej zachowuje punkty prowadzącego —
        // ponowna ocena automatem nie może kasować decyzji człowieka.
        $prev = db_one("SELECT points_awarded, teacher_note FROM k30_ti_exam_answers WHERE attempt_id=? AND question_id=?", [$attempt_id, $qid]);
        $manual_kept = $prev && $prev['points_awarded'] !== null && trim((string)($prev['teacher_note'] ?? '')) !== '';

        $points = $manual_kept ? (float)$prev['points_awarded'] : ($review ? null : (float)($r['points'] ?? 0));
        if ($manual_kept) $review = false;

        $upd->execute([
            json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $points,
            !empty($r['correct']) ? 1 : 0,
            $review ? 1 : 0,
            (string)($r['feedback'] ?? ''),
            $attempt_id, $qid,
        ]);
    }

    $pdo->prepare("UPDATE k30_ti_exam_attempts SET max_score=?, engine_status='ok', engine_error=NULL WHERE id=?")
        ->execute([(float)($result['maxScore'] ?? 0), $attempt_id]);

    ti_exam_recalc($attempt_id);
}

/** Przelicza wynik podejścia z sumy punktów przy odpowiedziach. */
function ti_exam_recalc(int $attempt_id): void {
    $row = db_one(
        "SELECT COALESCE(SUM(points_awarded),0) AS s,
                SUM(CASE WHEN points_awarded IS NULL OR needs_review=1 THEN 1 ELSE 0 END) AS pending
         FROM k30_ti_exam_answers WHERE attempt_id=?", [$attempt_id]);
    $pending = (int)($row['pending'] ?? 0);
    $score   = (float)($row['s'] ?? 0);

    db()->prepare(
        "UPDATE k30_ti_exam_attempts
         SET score=?, needs_review=?, status=?, graded_at=" . ($pending ? "NULL" : "?") . "
         WHERE id=?")
        ->execute($pending
            ? [$score, 1, 'submitted', $attempt_id]
            : [$score, 0, 'graded', date('Y-m-d H:i:s'), $attempt_id]);
}

/**
 * Ocena ręczna pytań otwartych i opisowych przez prowadzącego.
 *
 * Punkty wpisane w panelu jadą do silnika (ex_manual_grading_apply), który
 * przycina je do zakresu pytania, rozstrzyga o poprawności, zdejmuje znacznik
 * „do sprawdzenia” i przelicza sumę podejścia. PHP zapisuje to, co wróci —
 * reguły oceniania nie mają drugiej implementacji.
 *
 * @param array $points question_id => punkty wpisane przez prowadzącego
 * @param array $notes  question_id => komentarz dla kursanta
 * @return array{ok:bool, error:?string}
 */
function ti_exam_grade_manual(int $attempt_id, array $points, array $notes = [], ?int $by = null): array {
    $att = ti_exam_attempt_get($attempt_id);
    if (!$att) return ['ok' => false, 'error' => 'Nie ma takiego podejścia.'];
    $exam = ti_exam_get((int)$att['exam_id']);
    if (!$exam) return ['ok' => false, 'error' => 'Nie ma takiego egzaminu.'];

    $questions = ti_exam_attempt_questions($att);
    $answers   = ti_exam_answers($attempt_id);

    $qp = $ap = [];
    foreach ($questions as $q) {
        $qid  = (int)$q['id'];
        $qp[] = ['id' => $qid, 'points' => (float)$q['points']];
        $row  = $answers[$qid] ?? null;
        $ap[] = [
            'questionId'  => $qid,
            'points'      => $row && $row['points_awarded'] !== null ? (float)$row['points_awarded'] : null,
            'maxPoints'   => (float)$q['points'],
            'needsReview' => $row ? ((int)$row['needs_review'] === 1) : true,
        ];
    }

    $marks = $notes_out = [];
    foreach ($points as $qid => $v) {
        if (trim((string)$v) === '') continue;
        $marks[(string)(int)$qid] = (string)$v;
    }
    foreach ($notes as $qid => $v) $notes_out[(string)(int)$qid] = (string)$v;

    $err = null;
    $res = ti_exam_engine_post('/api/exam/manual-grade', [
        'passPct'   => (int)$exam['pass_pct'],
        'questions' => $qp,
        'answers'   => $ap,
        'marks'     => $marks,
        'notes'     => $notes_out,
    ], $err, 20);
    if (!$res) return ['ok' => false, 'error' => $err];

    $upd = db()->prepare(
        "UPDATE k30_ti_exam_answers
         SET points_awarded=?, is_correct=?, needs_review=?, teacher_note=?
         WHERE attempt_id=? AND question_id=?");
    foreach ((array)($res['answers'] ?? []) as $r) {
        if (empty($r['changed'])) continue;   // nie ruszamy odpowiedzi, których prowadzący nie oceniał
        $upd->execute([
            $r['points'] === null ? null : (float)$r['points'],
            !empty($r['correct']) ? 1 : 0,
            !empty($r['needsReview']) ? 1 : 0,
            (string)($r['note'] ?? ''),
            $attempt_id, (int)$r['questionId'],
        ]);
    }

    $a = (array)($res['attempt'] ?? []);
    db()->prepare(
        "UPDATE k30_ti_exam_attempts
         SET score=?, max_score=?, needs_review=?, status=?, graded_by=?, graded_at=" .
         (!empty($a['needsReview']) ? "NULL" : "?") . " WHERE id=?")
        ->execute(!empty($a['needsReview'])
            ? [(float)($a['score'] ?? 0), (float)($a['maxScore'] ?? 0), 1, 'submitted', $by, $attempt_id]
            : [(float)($a['score'] ?? 0), (float)($a['maxScore'] ?? 0), 0, 'graded', $by, date('Y-m-d H:i:s'), $attempt_id]);

    if (empty($a['needsReview'])) ti_exam_sync_grade($attempt_id);
    return ['ok' => true, 'error' => null];
}

/**
 * Natychmiastowa weryfikacja jednej odpowiedzi — tryb treningowy.
 * Zwraca wynik pojedynczego pytania albo null, gdy silnik nie odpowiada.
 */
function ti_exam_check_single(array $exam, array $question, array $payload, ?string &$error = null): ?array {
    $res = ti_exam_engine_post('/api/exam/grade', [
        'mode'          => 'training',
        'negMarking'    => (string)$exam['neg_marking'],
        'revealAnswers' => true,
        'questions'     => [ti_exam_question_payload($question, true)],
        'answers'       => [(string)(int)$question['id'] => $payload],
    ], $error, 60);
    if (!$res || empty($res['results'][0])) return null;
    return $res['results'][0];
}

// ═══════════════════════════════════════════════════════════════════════════
//  E-DZIENNIK
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Dokłada kolumnę `exam_attempt_id` do dziennika ocen.
 *
 * Ten jeden ALTER nie może iść razem z resztą schematu w ti_exams_schema.php:
 * tabela k30_ti_grades powstaje w karty30_migrate(), które bywa wołane PÓŹNIEJ
 * niż dołączenie tego pliku. Na świeżej bazie ALTER wtedy przepada po cichu,
 * a wystawienie oceny wywracałoby się na „no such column".
 */
function ti_exam_grades_column_heal(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE k30_ti_grades ADD COLUMN exam_attempt_id INTEGER"); }
    catch (\Throwable $e) { /* kolumna już jest — to normalny przypadek */ }
}

/** Zapis wyniku ocenionego podejścia do dziennika ocen (jeśli egzamin tak ma ustawione). */
function ti_exam_sync_grade(int $attempt_id): void {
    ti_exam_grades_column_heal();
    $att = ti_exam_attempt_get($attempt_id);
    if (!$att || $att['status'] !== 'graded') return;
    $exam = ti_exam_get((int)$att['exam_id']);
    if (!$exam || empty($exam['sync_grade'])) return;
    if (function_exists('k30_ti_grades_allowed')
        && !k30_ti_grades_allowed((int)$exam['course_id'], (int)$att['client_id'])) return;

    $max = (float)$att['max_score'];
    if ($max <= 0) return;
    $pct = 100 * (float)$att['score'] / $max;

    // Skala szkolna z procentów — identyczna jak w starszym module Testy,
    // żeby oceny z obu źródeł były porównywalne w jednym dzienniku.
    $grade = $pct >= 90 ? '5' : ($pct >= 75 ? '4' : ($pct >= 60 ? '3' : ($pct >= 50 ? '2' : '1')));
    $desc  = ti_exam_mode_label((string)$exam['mode']) . ': ' . (string)$exam['title']
           . ' (' . round($pct) . '%)';

    $existing = db_one("SELECT id FROM k30_ti_grades WHERE exam_attempt_id=?", [$attempt_id]);
    if ($existing) {
        db()->prepare("UPDATE k30_ti_grades SET value_text=?, value_num=?, description=?, graded_at=? WHERE id=?")
            ->execute([$grade, (float)$grade, $desc, date('Y-m-d H:i:s'), (int)$existing['id']]);
        return;
    }
    db_insert('k30_ti_grades', [
        'course_id'       => (int)$exam['course_id'],
        'client_id'       => (int)$att['client_id'],
        'exam_attempt_id' => $attempt_id,
        'category'        => 'sprawdzian',
        'value_text'      => $grade,
        'value_num'       => (float)$grade,
        'weight'          => (int)($exam['grade_weight'] ?? 3),
        'description'     => $desc,
        'graded_by_text'  => EQUI_EXAMS_NAME,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  NARZĘDZIA POMOCNICZE
// ═══════════════════════════════════════════════════════════════════════════

/** Ile podejść w kursie czeka na ocenę prowadzącego. */
function ti_exam_pending_review_count(int $course_id): int {
    return (int)(db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_exam_attempts a
         JOIN k30_ti_exams e ON e.id = a.exam_id
         WHERE e.course_id=? AND a.needs_review=1 AND a.status='submitted'",
        [$course_id])['n'] ?? 0);
}

/**
 * Import pytań ze starszego modułu Testy (k30_ti_tests) do egzaminu.
 * Każde pytanie przechodzi przez tę samą walidację co pytanie tworzone ręcznie,
 * więc import nie może wprowadzić do zestawu pozycji, której silnik nie oceni.
 * Typ `open` staje się krótką odpowiedzią z oceną prowadzącego.
 *
 * @return array{imported:int, skipped:int, error:?string}
 */
function ti_exam_import_from_test(int $exam_id, int $test_id): array {
    if (!function_exists('k30_ti_test_questions')) {
        return ['imported' => 0, 'skipped' => 0, 'error' => 'Starszy moduł Testy jest niedostępny.'];
    }
    $imported = $skipped = 0;
    foreach (k30_ti_test_questions($test_id) as $q) {
        $type = match ((string)$q['type']) {
            'multi' => 'multi',
            'open'  => 'short_answer',
            default => 'single',
        };
        $form = [];
        if ($type === 'short_answer') {
            $form['manual'] = true;
        } else {
            $opts = [];
            foreach (k30_ti_test_options((int)$q['id']) as $o) {
                $opts[] = ['label' => (string)$o['label'], 'correct' => (int)$o['is_correct'] === 1];
            }
            $form['options'] = $opts;
        }
        $err = null;
        $new = ti_exam_question_save_via_engine($exam_id, null, [
            'type'    => $type,
            'prompt'  => (string)$q['prompt'],
            'points'  => (float)$q['points'],
            'inBank'  => (int)($q['in_bank'] ?? 0) === 1,
            'form'    => $form,
        ], $issues, $err);
        if ($err !== null) return ['imported' => $imported, 'skipped' => $skipped, 'error' => $err];
        if ($new === null) { $skipped++; continue; }
        $imported++;
    }
    return ['imported' => $imported, 'skipped' => $skipped, 'error' => null];
}

/**
 * Statystyka zestawu liczona przez silnik: łatwość i moc różnicująca pytań,
 * rozkład wyników, odsetek zaliczeń. Zwraca null, gdy silnik nie odpowiada.
 */
function ti_exam_stats(int $exam_id, ?string &$error = null): ?array {
    $exam = ti_exam_get($exam_id);
    if (!$exam) { $error = 'Nie ma takiego egzaminu.'; return null; }

    $questions = [];
    foreach (ti_exam_questions($exam_id) as $q) {
        $questions[] = [
            'id'     => (int)$q['id'],
            'prompt' => mb_strimwidth(trim(strip_tags((string)$q['prompt'])), 0, 120, '…'),
            'type'   => (string)$q['type'],
            'points' => (float)$q['points'],
        ];
    }

    $attempts = [];
    foreach (db_all("SELECT * FROM k30_ti_exam_attempts WHERE exam_id=?", [$exam_id]) as $a) {
        $answers = [];
        foreach (db_all("SELECT question_id, points_awarded FROM k30_ti_exam_answers WHERE attempt_id=?", [(int)$a['id']]) as $r) {
            if ($r['points_awarded'] === null) continue;
            $q = ti_exam_question_get((int)$r['question_id']);
            $answers[] = [
                'questionId' => (int)$r['question_id'],
                'points'     => (float)$r['points_awarded'],
                'maxPoints'  => (float)($q['points'] ?? 0),
            ];
        }
        $attempts[] = [
            'id'       => (int)$a['id'],
            'score'    => (float)$a['score'],
            'maxScore' => (float)$a['max_score'],
            'status'   => (string)$a['status'],
            'answers'  => $answers,
        ];
    }

    return ti_exam_engine_post('/api/exam/stats', [
        'passPct'   => (int)$exam['pass_pct'],
        'questions' => $questions,
        'attempts'  => $attempts,
    ], $error, 30);
}

/** Procent wyniku podejścia (0 gdy brak punktacji maksymalnej). */
function ti_exam_pct(array $attempt): int {
    $max = (float)($attempt['max_score'] ?? 0);
    if ($max <= 0) return 0;
    return (int)round(100 * (float)$attempt['score'] / $max);
}

/** Czy podejście zaliczone wg progu egzaminu. */
function ti_exam_passed(array $exam, array $attempt): bool {
    $threshold = (int)($exam['pass_pct'] ?? 0);
    return $threshold <= 0 || ti_exam_pct($attempt) >= $threshold;
}

/** Ile sekund zostało do końca podejścia (null = bez limitu). */
function ti_exam_seconds_left(array $attempt): ?int {
    $dl = trim((string)($attempt['deadline_at'] ?? ''));
    if ($dl === '') return null;
    return max(0, strtotime($dl) - time());
}
