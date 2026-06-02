<?php
/**
 * admin/impersonate_stop.php — przywrócenie sesji admina po trybie podglądu.
 * Nie wymaga formularza — dostępne przez GET (link z bannera).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

auth_start();

if (empty($_SESSION['_admin_original'])) {
    // Nie jesteśmy w trybie podglądu — wróć do panelu
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

$impersonated = $_SESSION['user'];
$original     = $_SESSION['_admin_original'];

// Przywróć sesję admina
$_SESSION['user'] = $original;
unset($_SESSION['_admin_original']);

// Zaloguj zdarzenie
log_user_action(
    (int)($impersonated['id'] ?? 0),
    (int)$original['id'],
    'admin_impersonate_stop',
    'Admin ' . $original['name'] . ' zakończył podgląd konta: '
        . ($impersonated['name'] ?? '?') . ' <' . ($impersonated['email'] ?? '') . '>'
);

$target_name = $impersonated['name'] ?? 'użytkownika';
flash_set('success', 'Powróciłeś do swojego konta. Zakończono podgląd konta: <strong>' . h($target_name) . '</strong>');
header('Location: ' . APP_URL . '/admin/users.php');
exit;
