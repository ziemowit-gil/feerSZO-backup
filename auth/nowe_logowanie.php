<?php
/**
 * auth/nowe_logowanie.php — potwierdzenie pierwszego logowania z nowego urządzenia lub adresu IP.
 *
 * Do tej strony kieruje require_login(), gdy auth_new_login_check() (includes/auth_security.php) uzna, że konto
 * loguje się z nieznanego adresu IP albo urządzenia. „To ja” zapisuje IP i urządzenie jako znane i wpuszcza dalej;
 * „To nie ja” wylogowuje i odsyła do zmiany hasła. Wygląd jak CPL (tozsamosc/cpl.php). WCAG 2.1 AA.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

auth_start();
$user = current_user();
if (!$user) { header('Location: ' . APP_URL . '/tozsamosc/login.php'); exit; }

$APP = rtrim(APP_URL, '/');
$raw = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '');
$next = ($raw !== '' && str_starts_with($raw, $APP . '/') && !str_contains($raw, '/auth/nowe_logowanie.php')) ? $raw : auth_default_landing();
$pend = $_SESSION['_new_login_pending'] ?? null;
if (!$pend) { header('Location: ' . $next); exit; }

$uid = (int)$user['id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    if (($_POST['akcja'] ?? '') === 'ok') {
        auth_trust_login($uid, $pend);
        unset($_SESSION['_new_login_pending']);
        authlog_write($uid, 'login_new_confirmed', (string)$user['email'], 'Potwierdzono nowe logowanie: ' . $pend['ip'] . ' · ' . auth_device_label((string)$pend['ua']));
        header('Location: ' . $next); exit;
    }
    // „To nie ja”: wyloguj i skieruj do zmiany hasła
    authlog_write($uid, 'login_new_denied', (string)$user['email'], 'Odrzucono nowe logowanie: ' . $pend['ip'] . ' · ' . auth_device_label((string)$pend['ua']));
    logout_user();
    header('Location: ' . $APP . '/user/verify_reset.php'); exit;
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$geo = auth_geo_label(auth_ip_geo((string)$pend['ip']));
$why = [];
if (!empty($pend['new_ip']))  $why[] = 'adresu IP';
if (!empty($pend['new_dev'])) $why[] = 'urządzenia';
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nowe logowanie — potwierdź, że to Ty · CPL</title>
<style>
  :root{--ink:#1f2937;--brand:#2e2a7a;--blue:#0a7fd1;--blue-d:#075f9e}
  *{box-sizing:border-box}
  body{margin:0;background:#e9ebee;color:var(--ink);font:14px/1.45 Tahoma,"Segoe UI",Verdana,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
  .box{width:100%;max-width:520px;background:#fff;box-shadow:0 0 0 1px #d6d9de}
  .top{padding:18px 24px;border-bottom:1px solid #e1e4e8}
  .top b{color:var(--brand);font-size:22px;font-weight:800}.top span{color:#2e9b3f;font-size:22px;font-weight:800}
  .top small{display:block;font-size:11px;font-weight:700;color:var(--brand)}
  .body{padding:20px 24px;background:#eceeef}
  h1{font-size:18px;margin:0 0 6px}
  .warn{border-left:5px solid #f59e0b;background:#fff7e0;padding:9px 12px;margin:12px 0;color:#5c4400}
  dl{display:grid;grid-template-columns:150px 1fr;gap:6px 10px;margin:14px 0}
  dt{font-weight:700;text-align:right;color:#374151}dd{margin:0;word-break:break-word}
  .btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
  button{min-height:38px;border:0;padding:0 18px;font:700 13px Tahoma,"Segoe UI",sans-serif;cursor:pointer;border-radius:2px}
  .ok{background:var(--blue);color:#fff}.ok:hover{background:var(--blue-d)}
  .no{background:#fff;color:#8a1c12;border:1px solid #b42318}.no:hover{background:#fde8e8}
  button:focus-visible{outline:3px solid #f59e0b;outline-offset:2px}
  @media(max-width:460px){dl{grid-template-columns:1fr}dt{text-align:left}}
</style>
</head>
<body>
<main class="box" aria-labelledby="h">
  <div class="top"><b>CPL</b> <span>Portal</span><small>Centralny Portal Logowania · Fundacja FEER</small></div>
  <div class="body">
    <h1 id="h">To Twoje pierwsze logowanie z tego <?= $e(implode(' i ', $why) ?: 'miejsca') ?></h1>
    <p>Konto <strong><?= $e($user['email']) ?></strong> zalogowano z nieznanego dotąd <?= $e(implode(' lub ', $why) ?: 'miejsca') ?>. Sprawdź dane i potwierdź, że to Ty.</p>
    <dl>
      <dt>Adres IP</dt><dd><?= $e($pend['ip']) ?></dd>
      <dt>Lokalizacja</dt><dd><?= $e($geo) ?> <span style="color:#6b7280">(orientacyjnie, wg adresu IP)</span></dd>
      <dt>Urządzenie</dt><dd><?= $e(auth_device_label((string)$pend['ua'])) ?></dd>
      <dt>Czas</dt><dd><?= $e(date('d.m.Y H:i', strtotime((string)$pend['at']))) ?></dd>
    </dl>
    <p class="warn" role="note">Jeśli to nie Ty, wybierz „To nie ja” — wylogujemy tę sesję, a Ty ustawisz nowe hasło.</p>
    <form method="post" class="btns">
      <?= csrf_field() ?>
      <input type="hidden" name="redirect" value="<?= $e($next) ?>">
      <button type="submit" name="akcja" value="ok" class="ok" autofocus>To ja — kontynuuj</button>
      <button type="submit" name="akcja" value="nie" class="no">To nie ja — zmień hasło</button>
    </form>
  </div>
</main>
</body>
</html>
