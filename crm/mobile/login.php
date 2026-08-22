<?php
/**
 * crm/mobile/login.php — szybkie wejście do dialera: numer konta (UID) + PIN.
 *
 * Ścieżka alternatywna dla MS365, dostępna TYLKO w widoku mobilnym i otwierająca
 * TYLKO dialer. Po PIN-ie obowiązuje jeszcze weryfikacja IKA (jak w całym CRM) —
 * wymusza ją crm_mobile_guard() na stronie dialera.
 *
 * Reguły PIN-u, blokady i zakres sesji: includes/mobile_auth.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/mobile.php';
require_once dirname(__DIR__, 2) . '/includes/mobile_auth.php';

auth_start();
mobile_pin_schema_heal();

$base   = crm_mobile_base();
$pin_on = mobile_pin_module_enabled();

// Już zalogowany — nie ma po co pokazywać formularza.
if (current_user()) {
    header('Location: ' . $base . '/');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$pin_on) {
        $error = 'Logowanie PIN-em jest wyłączone.';
    } elseif (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        $error = 'Sesja wygasła. Spróbuj ponownie.';
    } else {
        $uid = (int)preg_replace('/\D+/', '', (string)($_POST['uid'] ?? ''));
        $pin = trim((string)($_POST['pin'] ?? ''));
        $res = mobile_pin_attempt($uid, $pin);
        if ($res['ok']) {
            mobile_session_login($res['user']);
            header('Location: ' . $base . '/');
            exit;
        }
        $error = (string)$res['error'];
    }
}

$csrf     = csrf_token();
$asset_v  = (int)@filemtime(__DIR__ . '/assets/app.css');
$ms_login = APP_URL . '/auth/login.php?redirect=' . urlencode($base . '/');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2E844A" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1F1F1F" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex, nofollow">
<title>Zaloguj — Dzwoń</title>
<link rel="manifest" href="<?= h($base) ?>/manifest.php">
<link rel="stylesheet" href="<?= h($base) ?>/assets/app.css?v=<?= $asset_v ?>">
</head>
<body class="d-login-body">

<main class="d-login">
  <h1 class="d-login-title">Dzwoń</h1>
  <p class="d-login-lead">Szybkie dzwonienie do kontaktów CRM</p>

  <?php if ($error !== ''): ?>
    <p class="d-login-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if ($pin_on): ?>
  <form method="post" action="<?= h($base) ?>/login.php" class="d-login-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <label class="d-field">
      <span class="d-field-label">Numer konta (UID)</span>
      <input type="text" name="uid" inputmode="numeric" pattern="[0-9]*"
             autocomplete="username" required autofocus
             value="<?= h((string)($_POST['uid'] ?? '')) ?>">
    </label>

    <label class="d-field">
      <span class="d-field-label">PIN (<?= MOBILE_PIN_LENGTH ?> cyfry)</span>
      <input type="password" name="pin" inputmode="numeric" pattern="[0-9]*"
             minlength="<?= MOBILE_PIN_LENGTH ?>" maxlength="<?= MOBILE_PIN_LENGTH ?>"
             autocomplete="current-password" required>
    </label>

    <button type="submit" class="d-btn d-btn-primary d-login-submit">Zaloguj</button>
  </form>

  <p class="d-login-hint">
    Numer konta i PIN znajdziesz w module
    <a href="<?= APP_URL ?>/tozsamosc/index.php">Tożsamość → Bezpieczeństwo</a>.
    Po zalogowaniu poprosimy jeszcze o kod IKA.
  </p>
  <?php endif; ?>

  <div class="d-login-alt">
    <a href="<?= h($ms_login) ?>">Zaloguj przez Microsoft 365</a>
  </div>
</main>

</body>
</html>
