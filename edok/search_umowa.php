<?php
/**
 * AJAX — wyszukiwanie umów z Rejestru Umów dla „Wpływ na podstawie umowy” (edok/add.php, edok/view.php).
 * GET ?q=fraza → JSON [{contract_type, id, nr_rejestru, numer_umowy, strona, typ_label, status}]
 * Przeszukuje WSZYSTKIE umowy (edok_umowy_wszystkie(), także bez nr RU) po numerze rejestru, numerze umowy i stronie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
edok_require_access();

$q = mb_strtoupper(trim((string)($_GET['q'] ?? '')));
$out = [];
foreach (edok_umowy_wszystkie() as $r) {
    $hay = mb_strtoupper(implode(' ', [(string)$r['nr_rejestru'], (string)$r['numer_umowy'], (string)$r['strona'], (string)$r['contract_type_label']]));
    if ($q !== '' && !str_contains($hay, $q)) continue;
    $out[] = ['contract_type' => $r['contract_type'], 'id' => (int)$r['id'], 'nr_rejestru' => (string)$r['numer'], 'numer_umowy' => (string)$r['numer_umowy'],
              'strona' => (string)$r['strona'], 'typ_label' => (string)$r['contract_type_label'], 'status' => (string)$r['status'], 'data' => (string)($r['data_zawarcia'] ?? '')];
    if (count($out) >= 500) break;
}
usort($out, fn($a, $b) => strcmp((string)$b['data'], (string)$a['data']) ?: strcmp($b['nr_rejestru'], $a['nr_rejestru']));
echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
