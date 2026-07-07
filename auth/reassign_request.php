<?php
/**
 * auth/reassign_request.php — Krok 1 "Przepisz użytkownika": admin podaje
 * powód i nowy adres e-mail. System generuje numer dokumentu i przekierowuje
 * z powrotem, żeby wydrukować oświadczenie i przesłać skan.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/impersonation.php';
require_once dirname(__DIR__) . '/includes/user_reassignment.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_admin()) {
    header('Location: ' . APP_URL . '/portal.php'); exit;
}
csrf_check();

$type      = preg_replace('/[^a-z_]/', '', $_POST['type'] ?? '');
$id        = (int)($_POST['id'] ?? 0);
$reason    = trim($_POST['reason'] ?? '');
$new_email = trim($_POST['new_email'] ?? '');

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
    $result = reassignment_create_request($target, $type, $id, $reason, $new_email);
    $_SESSION['reassign_pending'] = ['request_id' => $result['request_id'], 'type' => $type, 'id' => $id];
    flash_set('success', 'Zgłoszenie utworzone — dokument nr ' . $result['doc_number'] . '. Wydrukuj oświadczenie i prześlij skan po podpisie.');
} catch (\Throwable $e) {
    flash_set('error', $e->getMessage());
    header('Location: ' . $back); exit;
}

header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'reassign=1');
exit;
