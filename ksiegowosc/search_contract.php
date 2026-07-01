<?php
/**
 * ksiegowosc/search_contract.php — szybkie wyszukiwanie umów (zlecenie/dzieło/usługi)
 * do podpięcia pod dokument typu "Rachunek do umowy" (EOD Dokumentów Księgowych, KDOK).
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();

if (!is_admin() && !kdok_has_role('upload')) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

echo json_encode(['results' => kdok_contract_search(trim($_GET['q'] ?? ''))]);
