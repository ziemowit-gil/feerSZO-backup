<?php
/**
 * Odbiera jednorazowy token impersonacji i loguje admina jako kursanta.
 * Musi startować sesję panelu PRZED jakimkolwiek include'em który startuje sesję SZO.
 */

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
if (!$imp || $imp['type'] !== 'stu') {
    http_response_code(403);
    die('<p>Token wygasł lub jest nieprawidłowy. Wróć i spróbuj ponownie.</p>');
}

$account = db_one(
    "SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1",
    [(int)$imp['target_id']]
);
if (!$account) {
    http_response_code(404);
    die('<p>Nie znaleziono konta kursanta.</p>');
}

$admin = db_one("SELECT name, email FROM users WHERE id=?", [(int)$imp['admin_id']]);
$admin_name = $admin['name'] ?? $admin['email'] ?? 'Admin';

student_impersonate($account, (int)$imp['admin_id'], $admin_name);

header('Location: index.php');
exit;
