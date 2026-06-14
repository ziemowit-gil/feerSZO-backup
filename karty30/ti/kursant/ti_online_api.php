<?php
/**
 * karty30/ti/kursant/ti_online_api.php — endpoint AJAX „nauki online" dla kursanta.
 * Akcje: list | ms_create | ms_delete | moodle_create.
 * Operacje modyfikujące wymagają tokenu CSRF kursanta i metody POST,
 * i działają WYŁĄCZNIE na koncie zalogowanego kursanta.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_online.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

karty30_migrate();
$student = student_current();
if (!$student) { http_response_code(401); echo json_encode(['ok' => false, 'msg' => 'Sesja wygasła.']); exit; }

$sid    = (int)$student['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

/** Payload stanu dla front-endu. */
function ti_online_payload(int $sid): array {
    return ti_student_online_state($sid) + [
        'meetings' => ti_upcoming_meetings(),
    ];
}

$modifying = in_array($action, ['ms_create', 'ms_delete', 'moodle_create', 'moodle_password'], true);
if ($modifying) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'msg' => 'Metoda niedozwolona.']); exit; }
    student_token_check();
}

try {
    switch ($action) {
        case 'list':
            echo json_encode(['ok' => true] + ti_online_payload($sid));
            break;

        case 'ms_create': {
            $res = ti_ms_provision($sid);
            // Hasło pokazujemy jednorazowo w odpowiedzi (kursant musi je przepisać/zmienić)
            echo json_encode($res + ['data' => ti_online_payload($sid)]);
            break;
        }

        case 'ms_delete': {
            $res = ti_ms_delete($sid);
            echo json_encode($res + ['data' => ti_online_payload($sid)]);
            break;
        }

        case 'moodle_create': {
            $res = ti_moodle_provision($sid);
            echo json_encode($res + ['data' => ti_online_payload($sid)]);
            break;
        }

        case 'moodle_password': {
            $res = ti_moodle_set_password($sid, (string)($_POST['password'] ?? ''));
            echo json_encode($res + ['data' => ti_online_payload($sid)]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Nieznana akcja.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Błąd serwera: ' . $e->getMessage()]);
}
