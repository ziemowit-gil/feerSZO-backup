<?php
// ============================================================
// DEV BYPASS LOGOWANIA — TYLKO DO TYMCZASOWEGO UŻYTKU
// Chroni się przez secret wygenerowany z APP_KEY.
// URL: /_dev_login.php?s=<secret>   lub lokalnie bez parametru.
//
// Secret: php -r "require 'config.php'; echo substr(hash_hmac('sha256','dev_bypass',APP_KEY),0,16);"
// ============================================================
require_once __DIR__ . '/config.php';

$is_dev = (defined('APP_ENV') && APP_ENV === 'development');

if (!$is_dev) {
    // Na produkcji wymagany secret z APP_KEY
    $expected = substr(hash_hmac('sha256', 'dev_bypass', APP_KEY), 0, 16);
    $provided = $_GET['s'] ?? $_POST['s'] ?? '';
    if (!hash_equals($expected, $provided)) {
        http_response_code(404);
        die('Not found.');
    }
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
            $dest = APP_URL . '/portal.php';
            header('Location: ' . $dest); exit;
        } else {
            $error = 'Nie znaleziono użytkownika.';
        }
    }
}

$users = db_all("SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY role, name LIMIT 100");
$secret_param = $is_dev ? '' : ('?s=' . htmlspecialchars($_GET['s'] ?? ''));
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
</style>
</head>
<body>
<h1>⚠ Dev login</h1>
<p class="warn">Bypass logowania — pomija hasło i CSRF.<br>
<strong>Usuń ten plik gdy nie jest już potrzebny.</strong></p>
<?php if ($error): ?>
<p class="err"><?= htmlspecialchars($error) ?></p>
<?php endif ?>
<form method="post" action="<?= htmlspecialchars('/_dev_login.php' . $secret_param) ?>">
<?php if (!$is_dev && isset($_GET['s'])): ?>
<input type="hidden" name="s" value="<?= htmlspecialchars($_GET['s']) ?>">
<?php endif ?>
<label for="uid">Zaloguj jako:</label>
<select name="user_id" id="uid">
<?php
$by_role = [];
foreach ($users as $u) { $by_role[$u['role']][] = $u; }
foreach ($by_role as $role => $list):
?>
<optgroup label="<?= htmlspecialchars($role) ?>">
<?php foreach ($list as $u): ?>
<option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name'] . ' <' . $u['email'] . '>') ?></option>
<?php endforeach ?>
</optgroup>
<?php endforeach ?>
</select>
<br><br>
<button type="submit">Zaloguj (dev bypass)</button>
</form>
</body>
</html>
