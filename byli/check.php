<?php
/**
 * Live-sprawdzenie przy zawieraniu umowy: czy osoba o danym imieniu i nazwisku
 * figuruje w rejestrze byłych współpracowników.
 *
 * GET ?name=Imię Nazwisko
 * Zwraca JSON: { ok, enabled, count, items:[{imie_nazwisko, miasto, data_od, data_do,
 *                wrazliwe, powod, uwagi, sensitive_hidden}] }
 *
 * Pola wrażliwe (powód odejścia, uwagi) zwracane są tylko dla zarządu — w przeciwnym
 * razie ustawiamy sensitive_hidden=true, by interfejs zasygnalizował ukrytą treść.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/byli.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

// Moduł wyłączony → cicho zwróć pustkę (interfejs nic nie pokaże)
if (!module_enabled('byli_enabled')) {
    echo json_encode(['ok' => true, 'enabled' => false, 'count' => 0, 'items' => []]);
    exit;
}

$name  = trim($_GET['name'] ?? '');
$items = [];

foreach (byli_match_name($name) as $r) {
    $see = byli_can_see_sensitive($r);
    $hasSensitiveContent = ((string)$r['powod_odejscia'] !== '' || (string)$r['uwagi'] !== '');
    $items[] = [
        'imie_nazwisko'    => trim($r['imie'] . ' ' . $r['nazwisko']),
        'miasto'           => $r['miasto'],
        'data_od'          => $r['data_od'],
        'data_do'          => $r['data_do'],
        'wrazliwe'         => (int)$r['wrazliwe'],
        'powod'            => $see ? $r['powod_odejscia'] : null,
        'uwagi'            => $see ? $r['uwagi'] : null,
        'sensitive_hidden' => (!$see && $hasSensitiveContent),
    ];
}

echo json_encode(['ok' => true, 'enabled' => true, 'count' => count($items), 'items' => $items]);
