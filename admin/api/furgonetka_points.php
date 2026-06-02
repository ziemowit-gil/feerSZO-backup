<?php
/**
 * AJAX: Wyszukiwarka punktów odbioru przez Furgonetka API.
 * GET params: service (kod usługi np. inpost_locker), q (kod pocztowy lub miasto)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/furgonetka.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(403); echo json_encode(['error' => 'Brak dostępu.']); exit;
}

$service = trim($_GET['service'] ?? 'inpost_locker');
$q       = trim($_GET['q'] ?? '');

try {
    $f = new Furgonetka();
    if (!$f->is_configured()) {
        echo json_encode(['error' => 'Furgonetka API nie skonfigurowane.']); exit;
    }
    $shops = $f->parcel_shops($service, $q);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]); exit;
}

if (!$shops) {
    echo json_encode(['results' => []]); exit;
}

// Normalizuj wyniki — Furgonetka może zwracać różną strukturę
$q_clean = strtolower(str_replace([' ', '-'], '', $q));
$results = [];

foreach ((array)$shops as $pt) {
    if (count($results) >= 20) break;

    // Obsługa różnych kluczy adresu w odpowiedzi Furgonetka
    $code    = $pt['code']         ?? $pt['name']    ?? '';
    $name    = $pt['name']         ?? $code;
    $street  = $pt['street']       ?? $pt['address'] ?? ($pt['address_details']['street'] ?? '');
    $postal  = $pt['postal_code']  ?? ($pt['address_details']['postal_code'] ?? '');
    $city    = $pt['city']         ?? ($pt['address_details']['city'] ?? '');
    $hours   = $pt['opening_hours']?? $pt['open_hours'] ?? '';

    // Filtruj jeśli podano query (gdy Furgonetka zwróciła bez filtra)
    if ($q_clean) {
        $postal_c = str_replace([' ', '-'], '', strtolower($postal));
        $city_c   = strtolower($city);
        $name_c   = strtolower($name);
        $match = str_contains($city_c, $q_clean)
              || str_contains($postal_c, $q_clean)
              || str_contains($name_c,   $q_clean);
        if (!$match) continue;
    }

    $results[] = [
        'id'          => (string)$code,
        'name'        => $name,
        'line1'       => $street,
        'postal_code' => $postal,
        'city'        => $city,
        'open_hours'  => is_array($hours) ? implode(', ', $hours) : $hours,
    ];
}

echo json_encode(['results' => $results]);
