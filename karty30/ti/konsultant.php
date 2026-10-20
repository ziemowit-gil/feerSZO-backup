<?php
/**
 * karty30/ti/konsultant.php — osobne logowanie konsultantów: numer zastępczy (3 cyfry) zamiast Microsoft 365.
 * Login + hasło (modules/ti_consultant_access) → od razu panel tylko w trybie Konsultacje (bez kodu TOTP). Limit prób jak na wspólnym logowaniu TI (TiLogin, prefiks „ti:”). Login ustawia sam konsultant
 * w panelu: Konsultacje › Moje › Login zastępczy. Wspólne logowanie TI (login.php) numerów zastępczych nie przyjmuje.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once __DIR__ . '/dydaktyk/auth.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_login/logic/TiLogin.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_consultant_access/logic/consultantAccess.php';

header('X-Robots-Tag: noindex, nofollow');
$error = ''; $login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $login = TiConsultantAccess::normalize((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (!TiConsultantAccess::isAltLogin($login)) {
        // tylko numer zastępczy — e-mail (konsultant zewnętrzny, kadra) loguje się na wspólnej stronie TI / przez Microsoft 365
        $error = 'Wpisz numer zastępczy (3 cyfry). Konto z adresem e-mail loguje się na stronie ' . TiLogin::url() . '.';
    } elseif (TiLogin::throttled($login)) {
        $error = TiLogin::ERROR_THROTTLE;
    } else {
        $url = TiLogin::completeAltConsultant($login, $pass);   // numer zastępczy → od razu Konsultacje
        if ($url !== null) { TiLogin::clearAttempts($login); header('Location: ' . $url, true, 303); exit; }
        TiLogin::recordAttempt($login);
        $error = TiLogin::ERROR;
    }
}

// Token przed jakimkolwiek wyjściem — dyd_token() otwiera sesję panelu (inaczej ostrzeżenia „headers already sent”).
$_tok = dyd_token();
$_org = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie — Konsultacje — <?= h($_org) ?></title>
<meta name="robots" content="noindex,nofollow">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* Osobna strona logowania konsultantów — Metro UI (płaskie kafle, zero zaokrągleń, Segoe UI), akcent fiolet Konsultacji */
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#111;background:#fff;display:flex;min-height:100vh}
a{color:#4b00b5}
:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.kl-side{flex:0 0 42%;background:#4b00b5;color:#fff;padding:3rem 3.2rem;display:flex;flex-direction:column;justify-content:space-between}
.kl-brand{font-size:.8rem;font-weight:600;letter-spacing:.2em;text-transform:uppercase;opacity:.85}
.kl-big{font-size:clamp(2.2rem,4.5vw,3.6rem);font-weight:300;line-height:1.05;margin:1.2rem 0 .8rem;letter-spacing:-.02em}
.kl-sub{font-size:1.05rem;font-weight:300;opacity:.9;max-width:30rem;line-height:1.5}
.kl-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;max-width:28rem;margin-top:2.2rem}
.kl-tiles div{aspect-ratio:1;display:flex;flex-direction:column;justify-content:flex-end;padding:.6rem;font-size:.78rem;font-weight:600}
.kl-tiles .bi{font-size:1.6rem;margin-bottom:auto}
.kl-org{font-size:.85rem;opacity:.8}
.kl-main{flex:1;display:flex;align-items:center;justify-content:center;padding:3rem 2rem}
.kl-box{width:100%;max-width:420px}
.kl-h{font-size:2rem;font-weight:300;margin:0 0 .3rem;letter-spacing:-.01em}
.kl-lead{color:#5f5f5f;margin:0 0 1.6rem}
.kl-field{margin-bottom:1.1rem}
.kl-field label{display:block;font-weight:600;margin-bottom:.35rem}
.kl-in{width:100%;min-height:46px;border:2px solid #8a8a8a;padding:.5rem .7rem;font:inherit;font-size:1.05rem;background:#fff;border-radius:0}
.kl-in:focus{border-color:#4b00b5;outline:none;box-shadow:inset 0 0 0 1px #4b00b5}
.kl-hint{font-size:.9rem;color:#5f5f5f;margin:.35rem 0 0}
.kl-btn{width:100%;min-height:48px;border:0;background:#4b00b5;color:#fff;font:inherit;font-weight:600;font-size:1.05rem;cursor:pointer}
.kl-btn:hover{background:#2f0073}
.kl-or{display:flex;align-items:center;gap:.8rem;margin:1.5rem 0 1rem;color:#5f5f5f;font-size:.9rem}
.kl-or::before,.kl-or::after{content:"";flex:1;border-top:1px solid #e1e1e1}
.kl-ms{display:flex;align-items:center;justify-content:center;gap:.5rem;min-height:48px;border:2px solid #111;color:#111;text-decoration:none;font-weight:600}
.kl-ms:hover{background:#f2f2f2}
.kl-err{background:#fde8ec;border-left:6px solid #a20025;padding:.7rem .9rem;margin:0 0 1.2rem}
.kl-foot{margin-top:2rem;font-size:.85rem;color:#5f5f5f}
@media(max-width:820px){body{flex-direction:column}.kl-side{flex:none;padding:1.6rem 1.4rem}.kl-tiles{display:none}.kl-big{margin:.6rem 0 .4rem}.kl-main{padding:2rem 1.2rem}}
</style>
</head>
<body>
<aside class="kl-side" aria-label="Panel konsultacji">
  <div>
    <div class="kl-brand">Panel konsultacji</div>
    <p class="kl-big">Konsultacje<br>tyfloinformatyczne</p>
    <p class="kl-sub">Karty konsultacji, rezerwacje terminów i klienci — wejście dla konsultantów.</p>
    <div class="kl-tiles" aria-hidden="true">
      <div style="background:#6a00ff"><i class="bi bi-clipboard2-check"></i>Karty</div>
      <div style="background:#00807e"><i class="bi bi-calendar-check"></i>Terminy</div>
      <div style="background:#0f6fa8"><i class="bi bi-people"></i>Klienci</div>
    </div>
  </div>
  <div class="kl-org"><?= h($_org) ?></div>
</aside>
<main class="kl-main" id="main">
  <div class="kl-box">
    <h1 class="kl-h">Zaloguj się</h1>
    <p class="kl-lead">Numer zastępczy konsultanta (3 cyfry) i hasło.</p>
    <?php if ($error !== ''): ?><div class="kl-err" role="alert" id="kl-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="_token" value="<?= h($_tok) ?>">
      <div class="kl-field">
        <label for="kl-login">Numer zastępczy</label>
        <input class="kl-in" id="kl-login" name="login" required maxlength="3" inputmode="numeric" pattern="\d{3}" value="<?= h($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus
               aria-describedby="kl-login-h<?= $error !== '' ? ' kl-err' : '' ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?>>
        <p class="kl-hint" id="kl-login-h">Np. <strong>417</strong> — wejście bez kodu, tylko Konsultacje. Konsultant z adresem e-mail loguje się na <a href="<?= h(TiLogin::url()) ?>">stronie logowania TI</a>.</p>
      </div>
      <div class="kl-field">
        <label for="kl-pass">Hasło</label>
        <input class="kl-in" type="password" id="kl-pass" name="password" maxlength="72" autocomplete="current-password">
      </div>
      <button class="kl-btn">Zaloguj się</button>
    </form>
    <div class="kl-or">kadra organizacji</div>
    <a class="kl-ms" href="<?= h(TiLogin::officeUrl()) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> Zaloguj przez Microsoft 365</a>
    <p class="kl-foot">Numer zastępczy dostaniesz po zalogowaniu Microsoft 365: Konsultacje › Moje › Login zastępczy.</p>
  </div>
</main>
</body>
</html>
