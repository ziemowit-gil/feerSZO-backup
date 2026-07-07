<?php
/**
 * auth/impersonate_request.php — Krok 1: admin z poziomu umowy prosi o wejście
 * na konto powiązanej osoby. Wysyła kod potwierdzający SMS-em lub e-mailem
 * DO WŁAŚCICIELA KONTA i przekierowuje z powrotem, żeby wpisać kod.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/context.php';
require_once dirname(__DIR__) . '/includes/impersonation.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_admin()) {
    header('Location: ' . APP_URL . '/portal.php'); exit;
}
csrf_check();

$type = preg_replace('/[^a-z_]/', '', $_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$method = $_POST['method'] ?? '';

$back = $type && $id ? contract_url($type, $id) : APP_URL . '/portal.php';

if (!in_array($type, ['wolontariat', 'zlecenie'], true) || $id <= 0) {
    flash_set('error', 'Nieprawidłowe żądanie.');
    header('Location: ' . $back); exit;
}

$row = db_one("SELECT * FROM " . table_for_type($type) . " WHERE id=?", [$id]);
if (!$row) {
    flash_set('error', 'Nie znaleziono umowy.');
    header('Location: ' . $back); exit;
}

$target = impersonation_linked_user($type, $row);
if (!$target) {
    flash_set('error', 'Ta umowa nie ma powiązanego aktywnego konta w systemie.');
    header('Location: ' . $back); exit;
}

try {
    $result = impersonation_create_request($target, $type, $id, $reason, $method);
    $_SESSION['imp_pending'] = ['request_id' => $result['request_id'], 'type' => $type, 'id' => $id];
    flash_set('success', 'Kod potwierdzający został wysłany do ' . $result['target_label'] . '.');
} catch (\Throwable $e) {
    flash_set('error', $e->getMessage());
    header('Location: ' . $back); exit;
}

header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'imp=1');
exit;
