<?php
/**
 * correspondence/tracking_update.php
 * AJAX POST — ręczna aktualizacja numeru śledzenia na istniejącym rekordzie.
 *
 * POST JSON: {csrf_token, corr_id, tracking_number}
 * Response:  {ok, tracking_number, msg}
 */
if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/correspondence.php';

header('Content-Type: application/json; charset=utf-8');

function _tu_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

require_login();
if (!can_write('correspondence') && !can_edit()) _tu_err('Brak uprawnień.', 403);

$raw  = file_get_contents('php://input');
$post = ($raw && ($j = json_decode($raw, true)) !== null) ? $j : $_POST;

csrf_check($post['csrf_token'] ?? '');

$corr_id  = (int)($post['corr_id'] ?? 0);
$tracking = trim($post['tracking_number'] ?? '');

if (!$corr_id) _tu_err('Brak corr_id.');

$row = db_one("SELECT id, direction FROM correspondence WHERE id=?", [$corr_id]);
if (!$row || $row['direction'] !== 'outgoing') _tu_err('Nie znaleziono rekordu.', 404);

db()->prepare(
    "UPDATE correspondence SET tracking_number=?, updated_at=? WHERE id=?"
)->execute([$tracking, date('Y-m-d H:i:s'), $corr_id]);

echo json_encode([
    'ok'              => true,
    'tracking_number' => $tracking,
    'msg'             => $tracking ? 'Numer śledzenia zapisany.' : 'Numer śledzenia wyczyszczony.',
]);
