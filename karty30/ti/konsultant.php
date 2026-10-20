<?php
/**
 * karty30/ti/konsultant.php — osobne logowanie konsultantów loginem zastępczym „…-kz” (zamiast Microsoft 365).
 * Login + hasło (modules/ti_consultant_access) → obowiązkowy kod TOTP (dydaktyk/totp_gate.php) → panel tylko w trybie
 * Konsultacje. Limit prób jak na wspólnym logowaniu TI (TiLogin, prefiks „ti:”). Login ustawia sam konsultant
 * w panelu: Konsultacje › Moje › Login zastępczy. Wspólne logowanie TI (login.php) loginów „-kz” nie przyjmuje.
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
    if ($login !== '' && !str_contains($login, '@') && !str_ends_with($login, TiConsultantAccess::ALT_SUFFIX)) $login .= TiConsultantAccess::ALT_SUFFIX;
    $pass = (string)($_POST['password'] ?? '');
    if (!TiConsultantAccess::isAltLogin($login)) {
        $error = 'Wpisz login zastępczy (kończy się na -kz). Konto z adresem e-mail loguje się na ' . TiLogin::url() . '.';
    } elseif (TiLogin::throttled($login)) {
        $error = TiLogin::ERROR_THROTTLE;
    } else {
        $url = TiLogin::completeAltConsultant($login, $pass);
        if ($url !== null) { TiLogin::clearAttempts($login); header('Location: ' . $url, true, 303); exit; }
        TiLogin::recordAttempt($login);
        $error = TiLogin::ERROR;
    }
}

$KP_TITLE = 'Logowanie konsultanta (login zastępczy)';
$KP_TOPBAR = ['brand' => 'Panel konsultacji', 'icon' => 'clipboard2-check', 'user' => '', 'logout' => '', 'no_enrich' => true];
$KP_BODY_CLASS = 'ti-skin';
include __DIR__ . '/kursant/_layout_head.php';
?>
<main id="main" class="container py-4" style="max-width:460px">
  <h1 class="h4 fw-bold mb-1"><i class="bi bi-key text-primary me-2" aria-hidden="true"></i>Logowanie konsultanta</h1>
  <p class="small text-body-secondary mb-3">Login zastępczy zamiast Microsoft 365 — tylko Konsultacje. Po haśle poprosimy o kod z aplikacji uwierzytelniającej.</p>
  <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="card border-0 shadow-sm" autocomplete="on"><div class="card-body">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <label class="form-label" for="kl-login">Login zastępczy</label>
    <input class="form-control mb-2 font-monospace" id="kl-login" name="login" required maxlength="60" placeholder="jan.kowalski-kz" value="<?= h($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false">
    <label class="form-label" for="kl-pass">Hasło</label>
    <input class="form-control mb-3" type="password" id="kl-pass" name="password" required maxlength="72" autocomplete="current-password">
    <button class="btn btn-primary w-100">Zaloguj</button>
  </div></form>
  <p class="small mt-3 mb-1"><a href="<?= h(TiLogin::officeUrl()) ?>">Zaloguj przez Microsoft 365</a> · <a href="<?= h(TiLogin::url()) ?>">Logowanie TI (e-mail / uczestnik)</a></p>
  <p class="small text-body-secondary">Nie masz loginu zastępczego? Ustawisz go po zalogowaniu Microsoft 365: Konsultacje › Moje › Login zastępczy.</p>
</main>
<?php include __DIR__ . '/kursant/_layout_foot.php'; ?>
