<?php
/**
 * AJAX: Wyszukiwarka punktów nadania (InPost / UPS / Poczta) przez Apaczka API.
 * GET params: type (INPOST|UPS|POCZTA), q (kod pocztowy lub miasto)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/apaczka.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(403); echo json_encode(['error' => 'Brak dostępu.']); exit;
}

$type = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_GET['type'] ?? 'INPOST'));
$q    = trim($_GET['q'] ?? '');

try {
    $g = new Apaczka();
    if (!$g->is_configured()) {
        echo json_encode(['error' => 'API nie skonfigurowane.']); exit;
    }
    $points = $g->points($type);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]); exit;
}

if (!$points) {
    echo json_encode(['results' => []]); exit;
}

// Filtruj po zapytaniu (kod pocztowy lub miasto)
$q_clean = strtolower(str_replace([' ', '-'], '', $q));
$results = [];

foreach ($points as $id => $pt) {
    if (count($results) >= 20) break;

    $addr    = $pt['address'] ?? [];
    $city    = strtolower($addr['city']        ?? '');
    $postal  = str_replace([' ', '-'], '', strtolower($addr['postal_code'] ?? ''));
    $name    = strtolower($pt['name']          ?? '');
    $line1   = strtolower($addr['line1']       ?? '');

    $match = !$q_clean
          || str_contains($city,   $q_clean)
          || str_contains($postal, $q_clean)
          || str_contains($name,   $q_clean)
          || str_contains($line1,  $q_clean);

    if ($match && ($pt['option_send'] ?? false)) {
        $results[] = [
            'id'          => (string)$id,
            'name'        => $pt['name']             ?? $id,
            'subtype'     => $pt['subtype']           ?? '',
            'line1'       => $addr['line1']           ?? '',
            'postal_code' => $addr['postal_code']     ?? '',
            'city'        => $addr['city']            ?? '',
            'open_hours'  => $pt['open_hours']        ?? '',
            'option_cod'  => (bool)($pt['option_cod'] ?? false),
        ];
    }
}

echo json_encode(['results' => $results]);
