<?php
/**
 * auth/reassign_upload.php — Krok 2 "Przepisz użytkownika": admin przesyła
 * skan podpisanego oświadczenia. Po udanym przesłaniu system OD RAZU
 * wykonuje przepisanie konta (nowe konto, zamknięcie starego, przepięcie
 * umowy) — zob. includes/user_reassignment.php::reassignment_execute().
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/user_reassignment.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_admin()) {
    header('Location: ' . APP_URL . '/portal.php'); exit;
}
csrf_check();

$pending = $_SESSION['reassign_pending'] ?? null;
$back = (is_array($pending) && !empty($pending['type']) && !empty($pending['id']))
    ? contract_url($pending['type'], (int)$pending['id'])
    : APP_URL . '/portal.php';

if (!is_array($pending) || empty($pending['request_id'])) {
    flash_set('error', 'Brak aktywnego zgłoszenia przepisania konta.');
    header('Location: ' . $back); exit;
}

$scan_path = handle_upload('scan', 'user_reassignment');
if (!$scan_path) {
    flash_set('error', 'Nie udało się przesłać skanu — dozwolone formaty: PDF, JPG, PNG (max 20 MB).');
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'reassign=1');
    exit;
}

$result = reassignment_execute((int)$pending['request_id'], $scan_path);

if ($result['ok']) {
    unset($_SESSION['reassign_pending']);
    flash_set('success', 'Konto zostało przepisane na nowy adres e-mail. Nowe konto otrzyma e-mail z linkiem do ustawienia hasła.');
    header('Location: ' . $back); exit;
}

flash_set('error', $result['error']);
header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'reassign=1');
exit;
