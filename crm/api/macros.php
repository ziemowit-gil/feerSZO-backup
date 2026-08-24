<?php
/**
 * crm/api/macros.php — zapis wyboru przypiętych szybkich akcji.
 *
 * Ustawienie jest PER UŻYTKOWNIK (tabela user_macros) — każdy układa sobie pasek
 * pod swoją robotę i nie rusza tym nikomu innemu.
 *
 * POST JSON {_csrf, keys:[…]} → {ok, error}
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_macros.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Tylko POST.']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']);
    exit;
}

$keys = array_slice((array)($in['keys'] ?? []), 0, 12);
$ok   = crm_macros_save($keys);

echo json_encode($ok
    ? ['ok' => true, 'error' => '']
    : ['ok' => false, 'error' => 'Nie udało się zapisać ustawienia.']);
