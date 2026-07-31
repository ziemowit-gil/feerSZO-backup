<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', ''); ezd_require_access();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo '{"ok":false,"error":"Method not allowed"}'; exit;
}

if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403); echo '{"ok":false,"error":"Błąd CSRF."}'; exit;
}

$action  = $_POST['_action'] ?? '';
$id      = (int)($_POST['id'] ?? 0);
$user_id = (int)current_user()['id'];

$pismo = ezd_pismo_get($id);
if (!$pismo) { echo json_encode(['ok' => false, 'error' => 'Pismo nie istnieje.']); exit; }

$sprawa = ezd_sprawa_get((int)$pismo['sprawa_id']);
$access = $sprawa ? ezd_sprawa_access($sprawa, $user_id) : null;
if ($access !== 'write') {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Brak uprawnień.']); exit;
}
if (($pismo['sprawa_status'] ?? '') === 'closed') {
    echo json_encode(['ok' => false, 'error' => 'Koszulka jest zamknięta.']); exit;
}

if ($action === 'set_status') {
    $status  = $_POST['status'] ?? '';
    $allowed = ['nowe', 'w_toku', 'odpowiedziano', 'archiwum'];
    if (!in_array($status, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy status.']); exit;
    }
    db()->prepare("UPDATE ezd_pisma SET status=?,updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
    ezd_log(null, (int)$pismo['sprawa_id'], $id, null, $user_id, 'pismo_status_changed',
        'Status: ' . $pismo['status'] . ' → ' . $status);
    echo json_encode(['ok' => true, 'msg' => 'Status zmieniony.']); exit;
}

if ($action === 'upload_file') {
    $err = ezd_upload('file', (int)$pismo['sprawa_id'], $user_id, $id);
    if ($err) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
    echo json_encode(['ok' => true, 'msg' => 'Plik dodany.']); exit;
}

echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
