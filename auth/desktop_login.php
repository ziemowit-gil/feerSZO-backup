<?php
/**
 * Ekran zatwierdzenia logowania aplikacji EZD Client (OAuth Authorization Code + PKCE, loopback).
 * Użytkownik loguje się standardowo (Microsoft 365), a tu tylko zatwierdza dostęp aplikacji.
 * Opis przepływu: includes/desktop_auth.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/desktop_auth.php';

require_login();
desktop_auth_migrate();

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$redirect  = (string)($src['redirect_uri'] ?? '');
$state     = (string)($src['state'] ?? '');
$challenge = (string)($src['code_challenge'] ?? '');
$device    = desktop_clean_device((string)($src['device'] ?? ''));

$bad = !desktop_valid_redirect($redirect)
    || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state)
    || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
if ($bad) {
    http_response_code(400);
    exit('Nieprawidłowe żądanie logowania aplikacji. Uruchom logowanie ponownie z aplikacji EZD Client.');
}

$user   = current_user();
$scopes = desktop_scopes_for_user($user);
$back   = static fn(array $q) => $redirect . '?' . http_build_query($q + ['state' => $state]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['decision'] ?? '') !== 'approve' || !$scopes) {
        header('Location: ' . $back(['error' => 'access_denied']));
        exit;
    }
    $code = bin2hex(random_bytes(32));
    db()->prepare("DELETE FROM desktop_auth_codes WHERE expires_at < ?")->execute([time() - 3600]);
    db()->prepare(
        "INSERT INTO desktop_auth_codes (code_hash, user_id, code_challenge, device, expires_at) VALUES (?,?,?,?,?)"
    )->execute([hash('sha256', $code), (int)$user['id'], $challenge, $device, time() + DESKTOP_CODE_TTL_SEC]);
    header('Location: ' . $back(['code' => $code]));
    exit;
}
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zaloguj aplikację EZD Client</title>
<style>
 body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f7f9;color:#14171a;margin:0;display:grid;place-items:center;min-height:100vh;padding:16px}
 main{background:#fff;border:1px solid #c9d0d8;border-radius:10px;padding:28px;max-width:480px;width:100%}
 h1{font-size:1.3rem;margin:0 0 12px} ul{padding-left:1.2em} .muted{color:#4a5560;font-size:.9rem}
 button{font:inherit;padding:9px 18px;border-radius:6px;border:1px solid #c9d0d8;background:#fff;cursor:pointer}
 button.primary{background:#0b5fff;color:#fff;border-color:#0b5fff;font-weight:600}
 .row{display:flex;gap:10px;margin-top:18px} :focus-visible{outline:3px solid #c25e00;outline-offset:2px}
 .err{color:#b3261e;font-weight:600}
</style></head><body>
<main aria-labelledby="h">
  <h1 id="h">Zalogować aplikację EZD Client?</h1>
  <p>Aplikacja<?= $device !== '' ? ' na urządzeniu <strong>' . h($device) . '</strong>' : '' ?> prosi o dostęp do Twojego konta:
     <strong><?= h($user['name']) ?></strong> (<?= h($user['email'] ?? '') ?>).</p>
  <?php if (!$scopes): ?>
    <p class="err" role="alert">To konto nie ma uprawnień do EZD ani CRM — logowanie aplikacji nie jest możliwe. Skontaktuj się z administratorem.</p>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="redirect_uri" value="<?= h($redirect) ?>"><input type="hidden" name="state" value="<?= h($state) ?>">
      <input type="hidden" name="code_challenge" value="<?= h($challenge) ?>"><input type="hidden" name="device" value="<?= h($device) ?>">
      <div class="row"><button name="decision" value="deny">Wróć do aplikacji</button></div>
    </form>
  <?php else: ?>
    <p>Aplikacja będzie działać z Twoimi uprawnieniami, a wpisy w EZD i CRM będą zapisywane na Twoje nazwisko:</p>
    <ul>
      <?php if (in_array('ezd:read', $scopes, true))   echo '<li>EZD — przeglądanie koszulek i pism</li>'; ?>
      <?php if (in_array('ezd:write', $scopes, true))  echo '<li>EZD — dodawanie plików i skanów, rejestracja koszulek, pism i wpisów RPW</li>'; ?>
      <?php if (in_array('crm:read', $scopes, true))   echo '<li>CRM — przeglądanie kontaktów</li>'; ?>
      <?php if (in_array('crm:write', $scopes, true))  echo '<li>CRM — dodawanie i edycja kontaktów, notatki</li>'; ?>
    </ul>
    <p class="muted">Dostęp wygasa po <?= DESKTOP_TOKEN_TTL_DAYS ?> dniach nieużywania; możesz go cofnąć, wylogowując się w aplikacji.</p>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="redirect_uri" value="<?= h($redirect) ?>"><input type="hidden" name="state" value="<?= h($state) ?>">
      <input type="hidden" name="code_challenge" value="<?= h($challenge) ?>"><input type="hidden" name="device" value="<?= h($device) ?>">
      <div class="row">
        <button class="primary" name="decision" value="approve" autofocus>Zezwól i zaloguj</button>
        <button name="decision" value="deny">Anuluj</button>
      </div>
    </form>
  <?php endif; ?>
</main></body></html>
