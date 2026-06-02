<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/krs.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Wymagane logowanie.']);
    exit;
}

$krs = preg_replace('/\D/', '', $_GET['krs'] ?? '');
if (!$krs) {
    http_response_code(400);
    echo json_encode(['error' => 'Podaj numer KRS.']);
    exit;
}

$result = krs_lookup($krs);
if (isset($result['error'])) {
    http_response_code(422);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
