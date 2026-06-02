<?php
/**
 * admin/impersonate.php — przełączenie admina na konto wybranego użytkownika.
 * Wymaga POST z CSRF. Po przełączeniu przekierowuje do panelu użytkownika.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
auth_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

csrf_check();

// Zabezpieczenie — nie można się podszywać będąc już w trybie podglądu
if (!empty($_SESSION['_admin_original'])) {
    flash_set('danger', 'Jesteś już w trybie podglądu. Najpierw wróć do swojego konta.');
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

$me        = current_user();
$target_id = intval($_POST['user_id'] ?? 0);

if (!$target_id) {
    flash_set('danger', 'Nie wskazano użytkownika.');
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

if ((int)$me['id'] === $target_id) {
    flash_set('danger', 'Nie możesz podszywać się pod samego siebie.');
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

$target = db_one(
    "SELECT id, name, email, role, microsoft_id, is_active FROM users WHERE id = ?",
    [$target_id]
);

if (!$target) {
    flash_set('danger', 'Użytkownik nie istnieje.');
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

if (!$target['is_active']) {
    flash_set('danger', 'Nie można przełączyć na nieaktywne konto.');
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

// Zapisz oryginalną sesję admina
$_SESSION['_admin_original'] = $_SESSION['user'];

// Podmień bieżącą sesję na docelowego użytkownika
$_SESSION['user'] = [
    'id'           => (int)$target['id'],
    'name'         => $target['name'],
    'email'        => $target['email'],
    'role'         => $target['role'],
    'microsoft_id' => $target['microsoft_id'] ?? null,
    'is_active'    => (int)$target['is_active'],
];

// Zaloguj zdarzenie
log_user_action(
    (int)$target['id'],
    (int)$me['id'],
    'admin_impersonate',
    'Admin ' . $me['name'] . ' przełączył się na konto: '
        . $target['name'] . ' <' . $target['email'] . '>'
);

// Przekieruj do panelu użytkownika
header('Location: ' . APP_URL . '/panel/index.php');
exit;
