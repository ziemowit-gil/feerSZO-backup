<?php
/**
 * karty30/login.php — Samodzielne wejście do modułu Dydaktyka (Dydaktyka 3).
 *
 * Branded ekran logowania modułu. Aby NIE obchodzić zabezpieczeń systemu
 * (brute-force, 2FA, WebAuthn, wymuszona zmiana hasła), formularz wysyła dane
 * do istniejącego /auth/login.php z parametrem redirect wracającym do modułu.
 *
 * Odporność: korzysta wyłącznie z rdzenia (config/db/auth/functions). Gdy część
 * opcjonalna jest nieobecna, strona i tak się renderuje.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/inc/loginShell/loginShell.php';

$APP   = rtrim(APP_URL, '/');
$index = $APP . '/karty30/index.php';

// Dokąd wrócić po zalogowaniu — tylko adresy w obrębie aplikacji.
$raw      = $_GET['redirect'] ?? '';
$redirect = ($raw && str_starts_with($raw, $APP . '/')) ? $raw : $index;
// Domyślnie wracamy do modułu Dydaktyka 3 (a nie do portalu).
if (!str_contains($redirect, '/karty30/')) $redirect = $index;

// Już zalogowany z dostępem → prosto do modułu.
if (function_exists('current_user') && current_user()) {
    $has = (function_exists('can_read') && can_read('karty30')) || (function_exists('is_admin') && is_admin());
    if (!$has) {
        try { $u = current_user(); $r = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)($u['id'] ?? 0)]); $has = !empty($r['k30_consultant']); } catch (\Throwable $e) {}
    }
    if ($has) { header('Location: ' . $redirect); exit; }
}

$org   = function_exists('org_setting') ? (org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')) : (defined('ORG_NAME') ? ORG_NAME : '');
$token = function_exists('csrf_token') ? csrf_token() : '';
$err   = trim((string)($_GET['err'] ?? ''));
// Akcja formularza: pełne zabezpieczenia obsługuje /auth/login.php
$action    = $APP . '/auth/login.php?redirect=' . urlencode($redirect);
$dyd_url   = $APP . '/karty30/ti/dydaktyk/login.php';            // panel prowadzącego (osobna sesja)
$admin_url = $APP . '/auth/login.php?redirect=' . urlencode($redirect); // pełne logowanie (admin: 2FA/WebAuthn/x509/MS365)
// Microsoft 365 SSO — dostępne, gdy skonfigurowano tenant + client_id
$ms_ok  = function_exists('ms_login_available') && ms_login_available();
$ms_url = $ms_ok && function_exists('ms_auth_url') ? ms_auth_url($redirect) : '';
function h_($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
loginShellHead([
    'title'     => 'Logowanie — Dydaktyka' . ($org ? ' · ' . $org : ''),
    'sideTitle' => 'Dydaktyka 3',
    'sideSub'   => 'Jak się zalogować:',
    'sideSteps' => [
        'Masz konto @feer.org.pl? Wybierz „Zaloguj przez Microsoft 365”.',
        'Pozostali: wpisz e-mail i hasło.',
        'Prowadzisz zajęcia TI? Użyj logowania prowadzącego.',
    ],
    'mainId'    => 'k30-login-main',
    'account'   => ['register'],   // „Nie pamiętasz hasła?” jest w karcie, pod polem hasła
    'registerOpen' => function_exists('register_is_open') && register_is_open(),
]);
?>
      <h1 class="h4 fw-bold mb-1">Zaloguj się</h1>
      <p class="tz-hint mt-0 mb-3">Moduł Dydaktyka<?= $org ? ' · ' . h_($org) : '' ?></p>

      <?php if ($err !== ''): ?>
      <div class="alert alert-danger small py-2" role="alert" aria-live="assertive"><?= h_($err) ?></div>
      <?php endif; ?>

      <?php if ($ms_ok): ?>
      <a href="<?= h_($ms_url) ?>" class="tz-btn tz-btn-ghost"
         aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">Zaloguj przez Microsoft 365</a>
      <div class="tz-sep">lub e-mailem i hasłem</div>
      <?php endif; ?>

      <form method="post" action="<?= h_($action) ?>" novalidate autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= h_($token) ?>">
        <input type="hidden" name="_method" value="local">
        <input type="hidden" name="from" value="<?= h_($APP . '/karty30/login.php?redirect=' . urlencode($redirect)) ?>">

        <div class="mb-3">
          <label for="email">Adres e-mail</label>
          <input type="email" class="form-control form-control-lg" id="email" name="email"
                 autocomplete="username" required autofocus placeholder="imie.nazwisko@org.pl"
                 <?php if ($err !== ''): ?>aria-invalid="true"<?php endif; ?>>
        </div>

        <div class="mb-4">
          <label for="password">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="password" name="password"
                   autocomplete="current-password" required>
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('password', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
          <a href="<?= h_($APP) ?>/user/verify_reset.php" class="d-inline-block mt-2 small">Nie pamiętasz hasła?</a>
        </div>

        <button type="submit" class="tz-btn">Zaloguj do modułu</button>
      </form>

      <p class="tz-hint mt-4 mb-2 fw-semibold">Logujesz się w innej roli?</p>
      <a href="<?= h_($dyd_url) ?>" class="tz-btn tz-btn-ghost">Prowadzący zajęcia TI</a>
      <a href="<?= h_($admin_url) ?>" class="tz-btn tz-btn-ghost">Administrator<?= $ms_ok ? ' (także Microsoft 365)' : '' ?></a>
      <p class="tz-hint">Logowanie chronione: 2FA / WebAuthn obsługiwane przez system główny.</p>
<?php
loginShellFoot([
    'links' => [
        ['url' => 'https://feer.org.pl', 'label' => 'Strona Fundacji FEER'],
        ['url' => $APP . '/index.php',   'label' => 'System główny'],
    ],
]);
