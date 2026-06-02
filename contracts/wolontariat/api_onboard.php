<?php
/**
 * API: Zapisz org_unit_id z modala onboardingowego
 * POST { _csrf, contract_id, org_unit_id }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
require_role('admin', 'editor');

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// CSRF
auth_start();
$token = $body['_csrf'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    echo json_encode(['ok'=>false,'error'=>'Nieprawidłowy token CSRF.']);
    exit;
}

$contract_id = (int)($body['contract_id'] ?? 0);
$org_unit_id = (int)($body['org_unit_id'] ?? 0);

if (!$contract_id) {
    echo json_encode(['ok'=>false,'error'=>'Brak ID umowy.']); exit;
}

$row = db_one("SELECT id FROM umowy_wolontariat WHERE id=?", [$contract_id]);
if (!$row) {
    echo json_encode(['ok'=>false,'error'=>'Nie znaleziono umowy.']); exit;
}

if ($org_unit_id) {
    $unit = db_one("SELECT id FROM org_units WHERE id=?", [$org_unit_id]);
    if (!$unit) {
        echo json_encode(['ok'=>false,'error'=>'Nieprawidłowa jednostka.']); exit;
    }
    db_update('umowy_wolontariat', ['org_unit_id' => $org_unit_id], $contract_id);
}

echo json_encode(['ok'=>true]);
