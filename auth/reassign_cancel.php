<?php
/**
 * auth/reassign_cancel.php — Anulowanie oczekującego zgłoszenia przepisania
 * konta (przed przesłaniem skanu — żądanie zostaje w bazie jako niewykonane).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_admin()) {
    header('Location: ' . APP_URL . '/portal.php'); exit;
}
csrf_check();

$pending = $_SESSION['reassign_pending'] ?? null;
$back = (is_array($pending) && !empty($pending['type']) && !empty($pending['id']))
    ? contract_url($pending['type'], (int)$pending['id'])
    : APP_URL . '/portal.php';

unset($_SESSION['reassign_pending']);
header('Location: ' . $back);
exit;
