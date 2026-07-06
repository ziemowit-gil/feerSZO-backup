<?php
/**
 * Audyt umowy: oznaczenie "do uzupełnienia" / zdjęcie flagi — wspólny handler
 * dla wszystkich typów umów (wzorzec jak contracts/delete.php).
 * POST: type, id, action (mark|resolve), correction_reason (wymagany dla mark), _csrf
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/contract_access.php';
require_once dirname(__DIR__) . '/includes/contract_correction_schema.php';
require_once dirname(__DIR__) . '/includes/contract_correction.php';
// notatka_do_realizacji istnieje tylko na tych dwóch tabelach (CORRECTION_NOTATKA_TYPES) —
// dogrzewamy schemat tutaj, bo mark_correction.php to jedyne miejsce, które ją zapisuje.
require_once dirname(__DIR__) . '/includes/zlecenie_schema.php';
require_once dirname(__DIR__) . '/includes/wolontariat_schema.php';

require_role('admin', 'editor'); // TODO: zawęzić do dedykowanej roli audytora, gdy powstanie
csrf_check();

$type   = $_POST['type'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!isset(CONTRACT_TYPES[$type]) || $id <= 0 || !in_array($action, ['mark', 'resolve'], true)) {
    flash_set('danger', 'Nieprawidłowe żądanie.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$table = table_for_type($type);
$user  = current_user();

$row = db_one("SELECT id, numer_umowy FROM {$table} WHERE id = ?", [$id]);
if (!$row) {
    flash_set('danger', 'Umowa nie istnieje.');
    header('Location: ' . APP_URL . "/contracts/{$type}/list.php");
    exit;
}
if (!contract_can_access($type, $row)) {
    flash_set('danger', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . "/contracts/{$type}/list.php");
    exit;
}

if ($action === 'mark') {
    $errors = ContractCorrectionValidator::validateMarkRequest($_POST);
    if ($errors) {
        flash_set('danger', implode(' ', $errors));
        header('Location: ' . APP_URL . "/contracts/{$type}/view.php?id={$id}");
        exit;
    }
    ContractCorrectionService::markForCorrection($type, $id, $_POST['correction_reason'], (int)$user['id'], $_POST['notatka_do_realizacji'] ?? null);
    flash_set('success', 'Umowa oznaczona jako „do uzupełnienia" — powód zapisany w historii.');
} else {
    ContractCorrectionService::resolveCorrection($type, $id, (int)$user['id']);
    flash_set('success', 'Flaga „do uzupełnienia" zdjęta.');
}

header('Location: ' . APP_URL . "/contracts/{$type}/view.php?id={$id}");
exit;
