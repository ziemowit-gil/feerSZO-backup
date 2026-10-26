<?php
/** generator/client.php — podpowiedzi uczestników TI do generatora: ?q=fragment nazwiska (lista id+nazwa) albo ?id=N (dane do uzupełnienia pól). JSON, tylko dla edytorów. */
require_once __DIR__ . '/_boot.php';
require_once dirname(__DIR__) . '/modules/ti_training_contract/logic/trainingContractStore.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!can_edit()) { http_response_code(403); echo '[]'; exit; }
if (isset($_GET['id'])) {
    $d = TiTrainingContractStore::fromClient((int)$_GET['id']);
    echo json_encode(['name' => (string)($d['imie_nazwisko'] ?? ''), 'pesel' => (string)($d['pesel'] ?? ''), 'adres' => (string)($d['adres'] ?? ''),
                      'email' => (string)($d['email'] ?? ''), 'telefon' => (string)($d['telefon'] ?? ''), 'plec' => (string)($d['plec'] ?? 'K')], JSON_UNESCAPED_UNICODE);
    exit;
}
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$like = '%' . str_replace(['%', '_'], '', $q) . '%';
echo json_encode(array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
    db_all("SELECT id, name FROM k30_clients WHERE name LIKE ? ORDER BY name LIMIT 15", [$like])), JSON_UNESCAPED_UNICODE);
