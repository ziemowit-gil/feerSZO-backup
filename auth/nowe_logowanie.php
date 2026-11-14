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

// Widok: jasny wygląd ekranów wejścia (includes/auth_screen.php, tryb 'light'; przebudowa 2026-11-14). Logika powyżej bez zmian.
require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'           => 'Nowe logowanie — potwierdź, że to Ty',
    'light'           => true,
    'width'           => 520,
    'main_id'         => 'nl-main',
    'account_buttons' => [],
    'head_html'       => '<style>'
        . '.nl-dl{display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1rem;margin:0 0 1.25rem;padding:1rem 1.1rem;'
        . 'background:#f9fafb;border:1px solid var(--au-line);border-radius:var(--au-rs)}'
        . '.nl-dl dt{font-weight:600;color:var(--au-ink2)}.nl-dl dd{margin:0;overflow-wrap:anywhere}.nl-dl small{color:var(--au-muted)}'
        . '.au .nl-no{background:#fff;border-color:var(--au-err);color:var(--au-err)}'
        . '.au .nl-no:hover{background:var(--au-err-bg);border-color:#991b1b;color:#991b1b}'
        . '@media(max-width:420px){.nl-dl{grid-template-columns:1fr}.nl-dl dd{margin-bottom:.35rem}}'
        . '</style>',
]);
?>
<p class="au-kicker">Bezpieczeństwo konta</p>
<h1 class="ks-h1" id="h">To Twoje pierwsze logowanie z tego <?= $e(implode(' i ', $why) ?: 'miejsca') ?></h1>
<p class="ks-lead">Konto <strong><?= $e($user['email']) ?></strong> zalogowano z nieznanego dotąd <?= $e(implode(' lub ', $why) ?: 'miejsca') ?>. Sprawdź dane i potwierdź, że to Ty.</p>
<dl class="nl-dl">
  <dt>Adres IP</dt><dd><?= $e($pend['ip']) ?></dd>
  <dt>Lokalizacja</dt><dd><?= $e($geo) ?> <small>(orientacyjnie, wg adresu IP)</small></dd>
  <dt>Urządzenie</dt><dd><?= $e(auth_device_label((string)$pend['ua'])) ?></dd>
  <dt>Czas</dt><dd><?= $e(date('d.m.Y H:i', strtotime((string)$pend['at']))) ?></dd>
</dl>
<div class="l-alert l-alert-warn" role="note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Jeśli to nie Ty, wybierz „To nie ja” — wylogujemy tę sesję, a Ty ustawisz nowe hasło.</span></div>
<form method="post" aria-labelledby="h">
  <?= csrf_field() ?>
  <input type="hidden" name="redirect" value="<?= $e($next) ?>">
  <button type="submit" name="akcja" value="ok" class="ks-btn ks-btn--primary" autofocus>To ja — kontynuuj</button>
  <button type="submit" name="akcja" value="nie" class="ks-btn nl-no">To nie ja — zmień hasło</button>
</form>
<?php
auth_screen_foot(['no_new_tiles' => true]);
