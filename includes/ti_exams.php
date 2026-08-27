<?php
/**
 * includes/ti_exams.php
 * Moduł „Testy wiedzy i umiejętności" (Egzaminy) dla TI.
 *
 * Podział odpowiedzialności:
 *   • PHP  — trwałość (SQLite/MySQL), uprawnienia, sesje, interfejs,
 *   • Java — modele pytań, składanie wariantów, weryfikacja i ocenianie oraz
 *            uruchamianie kodu kursanta w piaskownicy (kontener `exam-engine`).
 *
 * Silnik jest BEZSTANOWY: nie dotyka bazy, dostaje komplet definicji pytań
 * i odpowiedzi w JSON, oddaje punktację. Dzięki temu do pliku SQLite pisze
 * dalej wyłącznie PHP i nie ma dwóch pisarzy tej samej bazy.
 *
 * Moduł jest ODRĘBNY od starszego modułu „Testy" (k30_ti_tests*). Tamten działa
 * bez zmian; tu jest własna przestrzeń tabel k30_ti_exam* oraz import pytań
 * ze starego modułu (ti_exam_import_from_test).
 *
 * Wymaga wcześniejszego includes/db.php oraz includes/karty30.php.
 */

require_once __DIR__ . '/ti_exams_schema.php';

// ═══════════════════════════════════════════════════════════════════════════
//  SŁOWNIKI
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Typy pytań. Klucze MUSZĄ odpowiadać stałym w pl.feer.exam.model.Question.
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

/** Formuły testu — sterują limitem czasu, liczbą podejść i podpowiedziami. */
const K30_TI_EXAM_MODES = [
    'exam' => [
        'label'    => 'Kolokwium / egzamin',
        'hint'     => 'Ograniczony czasowo, jedno podejście, losowa kolejność pytań i wariantów, bez podpowiedzi.',
        'icon'     => 'mortarboard',
        'defaults' => ['time_limit_min' => 45, 'max_attempts' => 1, 'shuffle_questions' => 1,
                       'shuffle_options' => 1, 'show_feedback' => 'never'],
    ],
    'quiz' => [
        'label'    => 'Wejściówka / kartkówka',
        'hint'     => 'Krótki zestaw 3–5 pytań z ostrym limitem czasu.',
        'icon'     => 'lightning-charge',
        'defaults' => ['time_limit_min' => 5, 'max_attempts' => 1, 'shuffle_questions' => 1,
                       'shuffle_options' => 1, 'show_feedback' => 'after_submit'],
    ],
    'training' => [
        'label'    => 'Tryb treningowy',
        'hint'     => 'Bez limitu czasu; po każdej odpowiedzi wynik i wyjaśnienie, dowolna liczba podejść.',
        'icon'     => 'arrow-repeat',
        'defaults' => ['time_limit_min' => 0, 'max_attempts' => 0, 'shuffle_questions' => 0,
                       'shuffle_options' => 0, 'show_feedback' => 'immediate'],
    ],
];

/** Zasady punktacji pytań wielokrotnego wyboru i prawda/fałsz. */
const K30_TI_EXAM_NEG = [
    'partial'        => 'Cząstkowa z karą — (trafione − błędne) / poprawnych, nie mniej niż 0',
    'none'           => 'Cząstkowa bez kary — trafione / poprawnych',
    'all_or_nothing' => 'Wszystko albo nic — punkty tylko za komplet',
];

/** Języki zadań programistycznych — muszą pokrywać się z pl.feer.exam.sandbox.Language. */
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
//  KLIENT SILNIKA JAVA
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Adres silnika. Kolejność źródeł: config.local.php → zmienna środowiskowa →
 * nazwa usługi w compose. Poza Dockerem wygodnie ustawić http://127.0.0.1:8090.
 */
function ti_exam_engine_url(): string {
    if (defined('EXAM_ENGINE_URL') && EXAM_ENGINE_URL !== '') return rtrim((string)EXAM_ENGINE_URL, '/');
    $env = getenv('EXAM_ENGINE_URL');
    if (is_string($env) && $env !== '') return rtrim($env, '/');
    return 'http://exam-engine:8090';
}

function ti_exam_engine_token(): string {
    if (defined('EXAM_ENGINE_TOKEN') && EXAM_ENGINE_TOKEN !== '') return (string)EXAM_ENGINE_TOKEN;
    $env = getenv('EXAM_ENGINE_TOKEN');
    return is_string($env) ? $env : '';
}

/**
 * Wywołanie silnika. Zwraca tablicę z odpowiedzią albo null przy awarii;
 * powód awarii ląduje w $error (do pokazania prowadzącemu, nie kursantowi).
 */
function ti_exam_engine_post(string $path, array $payload, ?string &$error = null, int $timeout = 40): ?array {
    $error = null;
    $json  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { $error = 'Nie udało się zserializować żądania: ' . json_last_error_msg(); return null; }

    $headers = "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n";
    $token   = ti_exam_engine_token();
    if ($token !== '') $headers .= 'X-Exam-Token: ' . $token . "\r\n";

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => $headers,
        'content'       => $json,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);

    $url  = ti_exam_engine_url() . $path;
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $error = 'Silnik egzaminów jest nieosiągalny (' . $url . ').';
        return null;
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $status = (int)$m[1];
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) { $error = 'Silnik zwrócił odpowiedź, której nie da się odczytać.'; return null; }
    if ($status >= 400) {
        $error = 'Silnik odrzucił żądanie (HTTP ' . $status . '): ' . (string)($data['error'] ?? 'bez opisu');
        return null;
    }
    return $data;
}

/** Stan silnika — używane w panelu prowadzącego i przy diagnostyce. */
function ti_exam_engine_health(): array {
    $url = ti_exam_engine_url() . '/health';
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'error' => 'Silnik nie odpowiada pod ' . $url];
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'Nieczytelna odpowiedź silnika.'];
    $data['ok'] = (($data['status'] ?? '') === 'ok');
    return $data;
}

/** Języki faktycznie dostępne w obrazie silnika (przecięcie słownika PHP i /health). */
function ti_exam_available_languages(): array {
    $h = ti_exam_engine_health();
    if (empty($h['ok']) || empty($h['languages'])) return K30_TI_EXAM_LANGS;
    $out = [];
    foreach ($h['languages'] as $l) {
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

/** Zapis egzaminu. Puste pola liczbowe biorą wartości domyślne formuły. */
function ti_exam_save(array $data, ?int $id = null, ?int $user_id = null): int {
    $mode = isset(K30_TI_EXAM_MODES[$data['mode'] ?? '']) ? $data['mode'] : 'exam';
    $def  = K30_TI_EXAM_MODES[$mode]['defaults'];

    $f = [
        'course_id'         => (int)($data['course_id'] ?? 0),
        'session_id'        => !empty($data['session_id']) ? (int)$data['session_id'] : null,
        'title'             => trim((string)($data['title'] ?? '')),
        'description'       => trim((string)($data['description'] ?? '')),
        'mode'              => $mode,
        'time_limit_min'    => max(0, (int)($data['time_limit_min']    ?? $def['time_limit_min'])),
        'pass_pct'          => max(0, min(100, (int)($data['pass_pct'] ?? 0))),
        'max_attempts'      => max(0, (int)($data['max_attempts']      ?? $def['max_attempts'])),
        'shuffle_questions' => !empty($data['shuffle_questions']) ? 1 : 0,
        'shuffle_options'   => !empty($data['shuffle_options'])   ? 1 : 0,
        'fixed_draw'        => max(0, (int)($data['fixed_draw'] ?? 0)),
        'bank_draw'         => max(0, (int)($data['bank_draw']  ?? 0)),
        'show_feedback'     => in_array($data['show_feedback'] ?? '', ['never','after_submit','immediate'], true)
                               ? $data['show_feedback'] : $def['show_feedback'],
        'neg_marking'       => isset(K30_TI_EXAM_NEG[$data['neg_marking'] ?? '']) ? $data['neg_marking'] : 'partial',
        'open_at'           => trim((string)($data['open_at']  ?? '')) ?: null,
        'close_at'          => trim((string)($data['close_at'] ?? '')) ?: null,
        'is_active'         => !empty($data['is_active'])  ? 1 : 0,
        'sync_grade'        => !empty($data['sync_grade']) ? 1 : 0,
        'grade_weight'      => max(1, (int)($data['grade_weight'] ?? 3)),
        'updated_at'        => date('Y-m-d H:i:s'),
    ];

    if ($id) { db_update('k30_ti_exams', $f, $id); return $id; }
    $f['created_by'] = $user_id;
    $f['created_at'] = date('Y-m-d H:i:s');
    return db_insert('k30_ti_exams', $f);
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
 * Zapis pytania wraz z wariantami i przypadkami testowymi.
 *
 * @param array      $data    pola pytania + 'config' (tablica), 'options', 'cases'
 * @param int|null   $id      null = nowe pytanie
 */
function ti_exam_question_save(array $data, ?int $id = null): int {
    $type = isset(K30_TI_EXAM_TYPES[$data['type'] ?? '']) ? $data['type'] : 'single';
    $meta = K30_TI_EXAM_TYPES[$type];

    $cfg = is_array($data['config'] ?? null) ? $data['config'] : [];
    $f = [
        'type'        => $type,
        'prompt'      => (string)($data['prompt'] ?? ''),
        'points'      => max(0, (float)str_replace(',', '.', (string)($data['points'] ?? 1))),
        'in_bank'     => !empty($data['in_bank']) ? 1 : 0,
        'explanation' => (string)($data['explanation'] ?? ''),
        'config'      => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $pdo = db();
    if ($id) {
        db_update('k30_ti_exam_questions', $f, $id);
    } else {
        $exam_id = (int)($data['exam_id'] ?? 0);
        $pos = (int)(db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ti_exam_questions WHERE exam_id=?", [$exam_id])['p'] ?? 1);
        $id  = db_insert('k30_ti_exam_questions', ['exam_id' => $exam_id, 'position' => $pos] + $f);
    }

    // Warianty — nadpisujemy komplet; identyfikatory wariantów zmieniają się,
    // dlatego edycja pytania z istniejącymi podejściami nie unieważnia ocen
    // (odpowiedzi trzymają własną kopię wskazań w kolumnie payload).
    $pdo->prepare("DELETE FROM k30_ti_exam_options WHERE question_id=?")->execute([$id]);
    if (!empty($meta['options'])) {
        $pos = 0;
        foreach ((array)($data['options'] ?? []) as $opt) {
            $label = trim((string)($opt['label'] ?? ''));
            if ($label === '') continue;
            db_insert('k30_ti_exam_options', [
                'question_id' => $id,
                'position'    => $pos++,
                'label'       => $label,
                'is_correct'  => !empty($opt['is_correct']) ? 1 : 0,
                'feedback'    => (string)($opt['feedback'] ?? ''),
            ]);
        }
    }

    $pdo->prepare("DELETE FROM k30_ti_exam_cases WHERE question_id=?")->execute([$id]);
    if (!empty($meta['cases'])) {
        $pos = 0;
        foreach ((array)($data['cases'] ?? []) as $case) {
            $expected = (string)($case['expected'] ?? '');
            $stdin    = (string)($case['stdin'] ?? '');
            if ($expected === '' && $stdin === '') continue;
            db_insert('k30_ti_exam_cases', [
                'question_id' => $id,
                'position'    => $pos++,
                'name'        => trim((string)($case['name'] ?? '')),
                'stdin'       => $stdin,
                'expected'    => $expected,
                'match_mode'  => isset(K30_TI_EXAM_MATCH[$case['match_mode'] ?? '']) ? $case['match_mode'] : 'trim',
                'weight'      => max(0.01, (float)str_replace(',', '.', (string)($case['weight'] ?? 1))),
                'is_hidden'   => !empty($case['is_hidden']) ? 1 : 0,
                'tolerance'   => max(0, (float)str_replace(',', '.', (string)($case['tolerance'] ?? 0.000001))),
            ]);
        }
    }
    return (int)$id;
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
 * Pytanie w formacie oczekiwanym przez silnik Java.
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
 * Rozpoczyna podejście: prosi silnik o złożenie wariantu (losowanie z banku,
 * kolejność pytań i wariantów) i zapisuje go w podejściu.
 *
 * Gdy silnik nie odpowiada, wariant składa awaryjnie PHP — kursant nie zostaje
 * zablokowany przy wejściu na salę. To dotyczy WYŁĄCZNIE ułożenia zestawu;
 * ocenianie nie ma ścieżki awaryjnej (patrz ti_exam_submit), bo wynik musi
 * pochodzić z jednego, autorytatywnego miejsca.
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
 * Awaryjne złożenie wariantu po stronie PHP — używane tylko gdy silnik milczy.
 * Odwzorowuje regułę silnika: pytania stałe + losowanie z banku, opcjonalne
 * przetasowanie pytań i wariantów, wszystko deterministycznie względem ziarna.
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
