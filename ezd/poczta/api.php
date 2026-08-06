<?php
/**
 * ezd/poczta/api.php — AJAX API dla modułu Poczty EZD.
 *
 * GET  ?action=search_sprawa&q=...       → szukaj spraw EZD
 * GET  ?action=get_signatures            → podpis usera + stopka org
 * POST {action, id, ...}                 → operacje na wiadomościach
 *
 * Wszystkie POST wymagają _csrf. Zwracają JSON.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');

if (!current_user()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Niezalogowany']); exit; }
require_module_enabled('ezd_enabled', 'Moduł EZD');
ezd_require_access();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    if ($action === 'search_sprawa') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['rows' => []]); exit; }
        $rows = db_all(
            "SELECT id, znak_sprawy, title, status FROM ezd_sprawy
             WHERE (znak_sprawy LIKE ? OR title LIKE ?) AND status != 'closed'
             ORDER BY updated_at DESC LIMIT 20",
            ['%'.$q.'%', '%'.$q.'%']
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'get_signatures') {
        $sigs = EzdMailService::fetchSignatures();
        echo json_encode(['ok' => true, 'user' => $sigs['user'], 'org' => $sigs['org']]);
        exit;
    }

    if ($action === 'thread') {
        $key = trim($_GET['key'] ?? '');
        if (!$key) { echo json_encode(['ok'=>false,'error'=>'Brak klucza wątku']); exit; }
        $svc  = new EzdMailService();
        $msgs = $svc->getThread($key);
        echo json_encode(['ok' => true, 'messages' => $msgs]);
        exit;
    }

    if ($action === 'inbox_stats') {
        $stats = [
            'unread' => (int)(db_one(
                "SELECT COUNT(*) AS n FROM crm_communications WHERE direction='in' AND is_read=0 AND inbox_status='active'"
            )['n'] ?? 0),
            'active' => (int)(db_one(
                "SELECT COUNT(*) AS n FROM crm_communications WHERE direction='in' AND inbox_status='active'"
            )['n'] ?? 0),
        ];
        echo json_encode(['ok' => true] + $stats);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']); exit; }

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? $_POST['action'] ?? '';

if (($data['_csrf'] ?? '') !== csrf_token()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Błąd CSRF.']);
    exit;
}

$svc    = new EzdMailService();
$comm_id = (int)($data['id'] ?? 0);

// Weryfikacja, że wiadomość istnieje i użytkownik ma do niej dostęp
$comm = $comm_id ? db_one("SELECT * FROM crm_communications WHERE id=?", [$comm_id]) : null;
if ($comm_id && !$comm) {
    echo json_encode(['ok' => false, 'error' => 'Wiadomość nie istnieje.']);
    exit;
}

switch ($action) {

    case 'mark_read':
        if (!$comm) break;
        $svc->markRead($comm_id, true);
        echo json_encode(['ok' => true]);
        break;

    case 'mark_unread':
        if (!$comm) break;
        $svc->markRead($comm_id, false);
        echo json_encode(['ok' => true]);
        break;

    case 'mark_all_read':
        db()->exec("UPDATE crm_communications SET is_read=1 WHERE direction='in' AND inbox_status='active' AND is_read=0");
        db()->exec("UPDATE ezd_mail_threads SET unread_count=0");
        echo json_encode(['ok' => true]);
        break;

    case 'set_status':
        if (!$comm) break;
        $status = $data['status'] ?? '';
        $svc->markStatus($comm_id, $status);
        echo json_encode(['ok' => true]);
        break;

    case 'assign_sprawa':
        if (!$comm) break;
        if (!can_write('ezd') && !is_admin()) {
            echo json_encode(['ok'=>false,'error'=>'Brak uprawnień do zapisu EZD.']); break;
        }
        $sprawa_id = (int)($data['sprawa_id'] ?? 0);
        if (!$sprawa_id) { echo json_encode(['ok'=>false,'error'=>'Brak ID sprawy.']); break; }
        try {
            $pismo_id = $svc->assignToSprawa($comm_id, $sprawa_id);
            $sprawa   = db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
            echo json_encode([
                'ok'       => true,
                'pismo_id' => $pismo_id,
                'message'  => 'Wiadomość przypisana do sprawy ' . ($sprawa['znak_sprawy'] ?? '') . '.',
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'assign_user':
        if (!$comm) break;
        $user_id = (int)($data['user_id'] ?? 0) ?: null;
        $svc->assignToUser($comm_id, $user_id);
        $uname = $user_id ? (db_one("SELECT name FROM users WHERE id=?", [$user_id])['name'] ?? '') : '(odpisano)';
        echo json_encode(['ok' => true, 'message' => "Przypisano do: {$uname}."]);
        break;

    case 'convert_to_sprawa':
        // Konwersja wiadomości na nową sprawę EZD
        if (!$comm) break;
        if (!can_write('ezd') && !is_admin()) {
            echo json_encode(['ok'=>false,'error'=>'Brak uprawnień.']); break;
        }
        $teczka_id = (int)($data['teczka_id'] ?? 0);
        if (!$teczka_id) { echo json_encode(['ok'=>false,'error'=>'Wybierz teczkę.']); break; }
        try {
            $user_id = (int)(current_user()['id'] ?? 0);
            $numer   = (int)(db_one("SELECT COALESCE(MAX(numer),0)+1 AS n FROM ezd_sprawy WHERE teczka_id=?", [$teczka_id])['n'] ?? 1);
            $teczka  = db_one("SELECT symbol FROM ezd_teczki WHERE id=?", [$teczka_id]);
            $znak    = $teczka['symbol'] . '/' . date('Y') . '/' . str_pad($numer, 4, '0', STR_PAD_LEFT);

            db()->prepare(
                "INSERT INTO ezd_sprawy (teczka_id, znak_sprawy, numer, title, owner_id, created_by)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$teczka_id, $znak, $numer,
                        mb_substr($comm['subject'] ?? 'Nowa sprawa z e-mail', 0, 200),
                        $user_id, $user_id]);
            $new_sprawa_id = (int)db()->lastInsertId();

            $pismo_id = $svc->linkCommToSprawa($comm_id, $new_sprawa_id);
            echo json_encode([
                'ok'        => true,
                'sprawa_id' => $new_sprawa_id,
                'pismo_id'  => $pismo_id,
                'znak'      => $znak,
                'url'       => APP_URL . '/ezd/sprawy/view.php?id=' . $new_sprawa_id,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Nieznana akcja: {$action}."]);
}
