<?php
/**
 * Usuwanie umowy — wspólny handler dla wszystkich typów.
 * POST: type, id, _csrf
 * Admin: może usunąć każdą. Editor: tylko własne (created_by = jego id).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin', 'editor');
csrf_check();

$type = $_POST['type'] ?? '';
$id   = (int)($_POST['id'] ?? 0);

$type_to_table = [
    'wolontariat' => 'umowy_wolontariat',
    'zlecenie'    => 'umowy_zlecenie',
    'uslugi'      => 'umowy_uslugi',
    'dzielo'      => 'umowy_dzielo',
    'praca'       => 'umowy_praca',
    'powierzenie' => 'umowy_powierzenie',
    'inne'        => 'umowy_inne',
];

if (!isset($type_to_table[$type]) || $id <= 0) {
    flash_set('danger', 'Nieprawidłowe żądanie.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$table = $type_to_table[$type];
$user  = current_user();
$role  = $user['role'] ?? '';

// Pobierz rekord
$row = db_one("SELECT id, numer_umowy, created_by FROM {$table} WHERE id = ?", [$id]);
if (!$row) {
    flash_set('danger', 'Umowa nie istnieje.');
    header('Location: ' . APP_URL . "/contracts/{$type}/list.php");
    exit;
}

// Sprawdź uprawnienia: editor tylko własne
if ($role === 'editor' && (int)$row['created_by'] !== (int)$user['id']) {
    flash_set('danger', 'Możesz usuwać tylko umowy dodane przez siebie.');
    header('Location: ' . APP_URL . "/contracts/{$type}/list.php");
    exit;
}

$numer = $row['numer_umowy'] ?? "#{$id}";

// Usuń powiązane dane
try {
    db()->beginTransaction();

    // Logi i akceptacje
    try { db()->exec("DELETE FROM contract_audit_log WHERE contract_type='{$type}' AND contract_id={$id}"); } catch (\Throwable $e) {}
    try { db()->exec("DELETE FROM contract_approvals WHERE contract_type='{$type}' AND contract_id={$id}"); } catch (\Throwable $e) {}

    // Sama umowa
    db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);

    db()->commit();
    log_contract_action($type, $id, (int)$user['id'], 'delete', "Usunięto umowę {$numer}");
} catch (\Throwable $e) {
    db()->rollBack();
    flash_set('danger', 'Błąd podczas usuwania: ' . $e->getMessage());
    header('Location: ' . APP_URL . "/contracts/{$type}/list.php");
    exit;
}

flash_set('success', "Umowa {$numer} została usunięta.");
header('Location: ' . APP_URL . "/contracts/{$type}/list.php");
exit;
