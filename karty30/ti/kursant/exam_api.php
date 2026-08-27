<?php
/**
 * karty30/ti/kursant/exam_api.php
 * Equi Exams — punkt końcowy arkusza kursanta (JSON).
 *
 * Operacje:
 *   save  — autozapis pojedynczej odpowiedzi w trwającym podejściu,
 *   check — natychmiastowa weryfikacja odpowiedzi (tylko w trybie treningowym).
 *
 * To wyłącznie wygoda pracy: bez JavaScriptu arkusz działa normalnie, bo cały
 * komplet odpowiedzi leci w formularzu przy oddaniu pracy.
 *
 * Bezpieczeństwo: sesja kursanta + token CSRF w treści żądania (nagłówek
 * Content-Type: application/json wyklucza wysłanie formularzem z obcej strony,
 * ale token sprawdzamy niezależnie od tego).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_exams.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Zwraca odpowiedź i kończy przetwarzanie. */
function exam_api_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$student = student_current();
if (!$student) exam_api_out(['ok' => false, 'error' => 'Sesja wygasła — zaloguj się ponownie.'], 401);

$raw  = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) exam_api_out(['ok' => false, 'error' => 'Nieprawidłowe żądanie.'], 400);

if (!hash_equals(student_token(), (string)($body['_token'] ?? ''))) {
    exam_api_out(['ok' => false, 'error' => 'Nieprawidłowy token sesji.'], 403);
}

$attempt = ti_exam_attempt_get((int)($body['attempt_id'] ?? 0));
if (!$attempt || (int)$attempt['client_id'] !== (int)$student['client_id']) {
    exam_api_out(['ok' => false, 'error' => 'Nie ma takiego podejścia.'], 404);
}
if ($attempt['status'] !== 'in_progress') {
    exam_api_out(['ok' => false, 'error' => 'To podejście zostało już oddane.'], 409);
}

$exam = ti_exam_get((int)$attempt['exam_id']);
if (!$exam) exam_api_out(['ok' => false, 'error' => 'Nie ma takiego egzaminu.'], 404);

// Pytanie musi należeć do wariantu wylosowanego dla TEGO podejścia — inaczej
// dałoby się przez to wejście podejrzeć poprawną odpowiedź spoza swojego zestawu.
$qid   = (int)($body['question_id'] ?? 0);
$drawn = array_map('intval', (array)json_decode((string)$attempt['drawn_ids'], true));
if (!in_array($qid, $drawn, true)) {
    exam_api_out(['ok' => false, 'error' => 'To pytanie nie należy do Twojego zestawu.'], 403);
}
$question = ti_exam_question_get($qid);
if (!$question) exam_api_out(['ok' => false, 'error' => 'Nie ma takiego pytania.'], 404);

$payload = ti_exam_payload_from_post($body['answer'] ?? []);
$op      = (string)($body['op'] ?? 'save');

if ($op === 'save') {
    ti_exam_answer_save((int)$attempt['id'], $qid, $payload);
    exam_api_out(['ok' => true, 'savedAt' => date('c')]);
}

if ($op === 'check') {
    // Podpowiedzi wyłącznie tam, gdzie prowadzący na to pozwolił.
    if ((string)$exam['show_feedback'] !== 'immediate') {
        exam_api_out(['ok' => false, 'error' => 'W tym teście odpowiedzi sprawdzane są dopiero po oddaniu pracy.'], 403);
    }
    ti_exam_answer_save((int)$attempt['id'], $qid, $payload);

    $error = null;
    $res   = ti_exam_check_single($exam, $question, $payload, $error);
    if (!$res) {
        exam_api_out(['ok' => false,
                      'error' => 'Sprawdzanie jest chwilowo niedostępne — Twoja odpowiedź została zapisana.'], 503);
    }
    exam_api_out([
        'ok'          => true,
        'correct'     => !empty($res['correct']),
        'needsReview' => !empty($res['needsReview']),
        'points'      => (float)($res['points'] ?? 0),
        'maxPoints'   => (float)($res['maxPoints'] ?? 0),
        'feedback'    => (string)($res['feedback'] ?? ''),
        'explanation' => (string)($res['explanation'] ?? ''),
    ]);
}

exam_api_out(['ok' => false, 'error' => 'Nieznana operacja.'], 400);
