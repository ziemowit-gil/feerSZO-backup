<?php
/**
 * karty30/ti/dydaktyk/planner_ajax.php — AJAX endpoint dla SZO Planner.
 *
 * POST akcje (pole `action`):
 *   block_save        — utwórz / zaktualizuj blok biblioteki
 *   block_delete      — usuń blok biblioteki
 *   schedule_create   — utwórz nowy harmonogram
 *   schedule_save     — zapisz stan dni harmonogramu (JSON payload)
 *   schedule_delete   — usuń harmonogram
 */
define('SKIP_CONSENT_CHECK', true);
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$me  = dyd_require();
$uid = (int)$me['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'POST required']);
    exit;
}

dyd_token_check();

$action = trim($_POST['action'] ?? '');

function planner_ok(array $data = [], string $msg = ''): never {
    echo json_encode(['ok' => true, 'msg' => $msg] + $data);
    exit;
}

function planner_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

switch ($action) {

    /* ── Bloki ─────────────────────────────────────────────────────── */

    case 'block_save': {
        $id    = (int)($_POST['block_id'] ?? 0) ?: null;
        $title = trim($_POST['title'] ?? '');
        if ($title === '') planner_err('Podaj nazwę bloku.');

        // Upewnij się, że blok należy do tego prowadzącego
        if ($id) {
            $existing = szo_block_get($id);
            if (!$existing || (int)$existing['instructor_id'] !== $uid) planner_err('Brak dostępu.', 403);
        }

        $new_id = szo_block_save([
            'instructor_id'   => $uid,
            'title'           => $title,
            'category'        => $_POST['category'] ?? 'workshop',
            'duration_min'    => $_POST['duration_min'] ?? 60,
            'difficulty'      => $_POST['difficulty'] ?? 2,
            'energy_impact'   => $_POST['energy_impact'] ?? 0,
            'min_break_after' => $_POST['min_break_after'] ?? 0,
            'notes'           => $_POST['notes'] ?? '',
            'locked'          => !empty($_POST['locked']),
            'tags'            => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))),
        ], $id);

        $block = szo_block_get($new_id);
        planner_ok(['block' => $block], $id ? 'Blok zaktualizowany.' : 'Blok dodany do biblioteki.');
    }

    case 'block_delete': {
        $id = (int)($_POST['block_id'] ?? 0);
        $b  = $id ? szo_block_get($id) : null;
        if (!$b || (int)$b['instructor_id'] !== $uid) planner_err('Brak bloku.', 404);
        szo_block_delete($id, $uid);
        planner_ok([], 'Blok usunięty.');
    }

    /* ── Harmonogramy ──────────────────────────────────────────────── */

    case 'schedule_create': {
        $title    = trim($_POST['title'] ?? '');
        $num_days = max(2, min(7, (int)($_POST['num_days'] ?? 3)));
        if ($title === '') planner_err('Podaj tytuł harmonogramu.');
        $sid      = szo_schedule_create($uid, $title, $num_days);
        $schedule = szo_schedule_get($sid, $uid);
        planner_ok(['schedule' => $schedule], 'Harmonogram utworzony.');
    }

    case 'schedule_save': {
        $sid  = (int)($_POST['schedule_id'] ?? 0);
        $raw  = $_POST['days_json'] ?? '[]';
        $days = json_decode($raw, true);
        if (!is_array($days)) planner_err('Nieprawidłowy format danych.');
        if (!szo_schedule_save_days($sid, $uid, $days)) planner_err('Brak harmonogramu.', 404);
        planner_ok([], 'Zapisano.');
    }

    case 'schedule_delete': {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        if (!db_one("SELECT id FROM k30_szo_schedules WHERE id=? AND instructor_id=?", [$sid, $uid])) {
            planner_err('Brak harmonogramu.', 404);
        }
        szo_schedule_delete($sid, $uid);
        planner_ok([], 'Harmonogram usunięty.');
    }

    default:
        planner_err('Nieznana akcja.');
}
