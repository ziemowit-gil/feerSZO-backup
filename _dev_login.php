<?php
// ============================================================
// DEV BYPASS LOGOWANIA — USUŃ PRZED COMMITEM / przed wdrożeniem
// Działa TYLKO w środowisku development (APP_ENV=development).
// Użycie: http://localhost/_dev_login.php
// ============================================================
require_once __DIR__ . '/config.php';

if (!defined('APP_ENV') || APP_ENV !== 'development') {
    http_response_code(404);
    die('Not found.');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Już zalogowany? Idź do portalu.
if (current_user()) {
    header('Location: ' . APP_URL . '/portal.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0) {
        $user = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
        if ($user) {
            login_user($user);
            header('Location: ' . APP_URL . '/portal.php'); exit;
        } else {
            $error = 'Nie znaleziono użytkownika.';
        }
    }
}

$users = db_all("SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY role, name LIMIT 100");
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Dev login</title>
<style>
body { font-family: monospace; max-width: 600px; margin: 40px auto; padding: 0 16px; }
h1 { color: #c00; }
select { width: 100%; padding: 8px; font-size: 1rem; margin: 8px 0; }
button { padding: 8px 24px; font-size: 1rem; cursor: pointer; background: #333; color: #fff; border: 0; }
.warn { background: #ffe; border: 1px solid #c80; padding: 8px 12px; margin-bottom: 12px; }
.err  { background: #fee; border: 1px solid #c00; padding: 8px 12px; margin-bottom: 12px; color: #c00; }
optgroup { font-weight: bold; }
</style>
</head>
<body>
<h1>⚠ Dev login</h1>
<p class="warn">Ten skrypt działa TYLKO lokalnie (APP_ENV=development) i pomija całe uwierzytelnianie.<br>
<strong>Nie commituj tego pliku do repozytorium.</strong></p>
<?php if ($error): ?>
<p class="err"><?= htmlspecialchars($error) ?></p>
<?php endif ?>
<form method="post">
<label for="uid">Zaloguj jako:</label>
<select name="user_id" id="uid">
<?php
$by_role = [];
foreach ($users as $u) { $by_role[$u['role']][] = $u; }
foreach ($by_role as $role => $list):
?>
<optgroup label="<?= htmlspecialchars($role) ?>">
<?php foreach ($list as $u): ?>
<option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name'] . ' &lt;' . $u['email'] . '&gt;') ?></option>
<?php endforeach ?>
</optgroup>
<?php endforeach ?>
</select>
<br><br>
<button type="submit">Zaloguj (dev bypass)</button>
</form>
</body>
</html>
