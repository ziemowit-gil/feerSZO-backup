<?php
/**
 * crm/contact/import_contract.php — Import danych osobowych z umów na kartę CRM.
 *
 * GET  ?id=X            → JSON { ok, fields:[{field,label,value,source,current}] }
 * POST id, _csrf, fields[]  → aktualizuje wybrane pola kontaktu → JSON { ok, updated }
 *
 * Źródłem są umowy głównego systemu dopasowane do kontaktu (person_id / e-mail).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('import', 'write');

header('Content-Type: application/json; charset=UTF-8');

if (!can_write('crm') && !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

$LABELS = [
    'imie_nazwisko'  => 'Imię i nazwisko',
    'pesel'          => 'PESEL',
    'adres'          => 'Adres',
    'telefon'        => 'Telefon',
    'email'          => 'E-mail',
    'data_urodzenia' => 'Data urodzenia',
];

$id      = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$contact = CrmManager::getContact($id);
if (!$contact) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Kontakt nie istnieje.']);
    exit;
}

$available = CrmManager::getContractDataForContact($contact);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selected = (array)($_POST['fields'] ?? []);
    $update   = [];
    foreach ($selected as $f) {
        if (isset($available[$f])) {
            $update[$f] = $available[$f]['value'];
        }
    }
    if ($update) {
        CrmManager::updateContact($id, $update);
    }
    echo json_encode(['ok' => true, 'updated' => array_keys($update)]);
    exit;
}

// GET → lista dostępnych pól z aktualną wartością kontaktu (do porównania w modalu)
$out = [];
foreach ($available as $f => $info) {
    $out[] = [
        'field'   => $f,
        'label'   => $LABELS[$f] ?? $f,
        'value'   => $info['value'],
        'source'  => $info['source'],
        'current' => trim((string)($contact[$f] ?? '')),
    ];
}
echo json_encode(['ok' => true, 'fields' => $out]);
