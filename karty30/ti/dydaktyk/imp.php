<?php
/**
 * Odbiera jednorazowy token impersonacji i loguje admina jako prowadzącego.
 * Musi startować sesję panelu PRZED jakimkolwiek include'em który startuje sesję SZO.
 */

// 1. Wczytaj DB i definicje bez uruchamiania sesji SZO
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

$token = (string)($_GET['t'] ?? '');
if (!$token) {
    http_response_code(400);
    die('<p>Brak tokenu.</p>');
}

karty30_migrate();

$imp = k30_imp_token_consume($token);
if (!$imp || $imp['type'] !== 'dyd') {
    http_response_code(403);
    die('<p>Token wygasł lub jest nieprawidłowy. Wróć i spróbuj ponownie.</p>');
}

$u = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$imp['target_id']]);
if (!$u) {
    http_response_code(404);
    die('<p>Nie znaleziono prowadzącego.</p>');
}

$profile = dyd_profile_from_user($u);
if (!$profile) {
    http_response_code(403);
    die('<p>Ten użytkownik nie ma uprawnień do panelu prowadzącego.</p>');
}

// Oznacz jako impersonację
$admin = db_one("SELECT name, email FROM users WHERE id=?", [(int)$imp['admin_id']]);
$profile['imp'] = [
    'by'   => (int)$imp['admin_id'],
    'name' => $admin['name'] ?? $admin['email'] ?? 'Admin',
];

dyd_login_user($profile);

header('Location: index.php');
exit;
