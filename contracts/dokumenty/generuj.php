<?php
/**
 * contracts/dokumenty/generuj.php
 * Krok 1 — generuje edytowalny dokument z wzorca dla wskazanej umowy
 * i przekierowuje do live edycji (edytuj.php).
 *
 * POST params: template_id, contract_type, contract_id
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_document_engine.php';

require_login();
if (!can_edit()) { http_response_code(403); exit('Brak uprawnień.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Metoda niedozwolona.'); }
csrf_check();

$template_id   = (int)($_POST['template_id'] ?? 0);
$contract_type = $_POST['contract_type'] ?? '';
$contract_id   = (int)($_POST['contract_id'] ?? 0);

if (!$template_id || !$contract_id || !array_key_exists($contract_type, CGD_CONTRACT_TABLES)) {
    http_response_code(400); exit('Nieprawidłowe parametry.');
}

$doc_id = cgd_create($template_id, $contract_type, $contract_id, (int)current_user()['id']);
if (!$doc_id) {
    flash_set('error', 'Nie udało się wygenerować dokumentu — wzorzec nie istnieje lub jest nieaktywny.');
    header('Location: ' . APP_URL . '/contracts/' . $contract_type . '/view.php?id=' . $contract_id . '&tab=docs');
    exit;
}

header('Location: ' . APP_URL . '/contracts/dokumenty/edytuj.php?id=' . $doc_id);
exit;
