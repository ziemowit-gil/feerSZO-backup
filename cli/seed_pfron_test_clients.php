<?php
/**
 * cli/seed_pfron_test_clients.php — zakłada 4 testowych klientów z umowami PFRON
 * (kursy finansowane z PFRON, seria dokumentu PFRON-AS), w tym 2 Ukrainki.
 *
 * Tworzy w k30_clients + k30_pfron_contracts:
 *   Anna Kowalska (TEST)      — kobieta, PL
 *   Piotr Nowak (TEST)        — mężczyzna, PL
 *   Olena Kowalenko (TEST)    — kobieta, Ukraina
 *   Iryna Melnyk (TEST)       — kobieta, Ukraina
 *
 * Każdy klient dostaje aktywną umowę PFRON (hours_total=30, hours_training=25,
 * ważną 6 miesięcy od dziś). Numer dokumentu (doc_number, seria PFRON-AS/xx/rrrr)
 * CELOWO zostaje pusty — nadaje go dopiero podpisanie umowy (karty30/pfron/sign.php),
 * żeby dane testowe nie zużywały realnej sekwencji numeracji.
 *
 * Idempotentne: jeśli klient o danej nazwie już istnieje, pomija go.
 *
 * Użycie:
 *   php cli/seed_pfron_test_clients.php
 *
 * Po testach: php cli/cleanup_pfron_test_clients.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/karty30.php';

karty30_migrate();

$validFrom = date('Y-m-d');
$validTo   = date('Y-m-d', strtotime('+6 months'));

$clients = [
    ['name' => 'Anna Kowalska (TEST)',    'gender' => 'female', 'phone' => '500100001', 'contract_seq' => 1],
    ['name' => 'Piotr Nowak (TEST)',      'gender' => 'male',   'phone' => '500100002', 'contract_seq' => 2],
    ['name' => 'Olena Kowalenko (TEST)',  'gender' => 'female', 'phone' => '500100003', 'contract_seq' => 3],
    ['name' => 'Iryna Melnyk (TEST)',     'gender' => 'female', 'phone' => '500100004', 'contract_seq' => 4],
];

$year = (int)date('Y');

foreach ($clients as $c) {
    $existing = db_one("SELECT id FROM k30_clients WHERE name=?", [$c['name']]);
    if ($existing) {
        echo "POMINIĘTO {$c['name']} — już istnieje (client_id={$existing['id']}).\n";
        continue;
    }

    $clientId = db_insert('k30_clients', [
        'name'   => $c['name'],
        'phone'  => $c['phone'],
        'gender' => $c['gender'],
        'status' => 'enrolled',
        'notes'  => 'Dane testowe — kurs finansowany z PFRON (seed_pfron_test_clients.php).',
    ]);

    $contractNumber = sprintf('PFRON/%d/%05d', $year, $c['contract_seq']);
    $contractId = db_insert('k30_pfron_contracts', [
        'client_id'       => $clientId,
        'contract_number' => $contractNumber,
        'hours_total'     => 30,
        'hours_training'  => 25,
        'hours_limit'     => 25,
        'hours_used'      => 0,
        'valid_from'      => $validFrom,
        'valid_to'        => $validTo,
        'status'          => 'active',
        'notes'           => 'Umowa testowa (seed_pfron_test_clients.php).',
    ]);

    echo "OK {$c['name']} — client_id=$clientId, umowa PFRON $contractNumber (contract_id=$contractId)\n";
}

echo "\nGotowe.\n";
echo "Numer umowy do logowania w portalu PFRON: podany wyżej (contract_number), telefon: jak przy każdym kliencie.\n";
echo "Po testach uruchom: php cli/cleanup_pfron_test_clients.php\n";
