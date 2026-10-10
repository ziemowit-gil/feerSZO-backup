<?php
/**
 * tozsamosc/login.php — dedykowane okno logowania do Systemu Tożsamości (wygląd logowania TI, METRO).
 *
 * Lewa kolumna wyjaśnia, czym jest System Tożsamości i SSO. Prawa: Microsoft 365 (SSO) albo e-mail i hasło.
 * Formularz NIE ma własnej kryptografii — wysyła dane do /auth/login.php (anty-brute, „tylko Office”, 2FA,
 * bramka WebAuthn, wymuszona zmiana hasła). Pole „from” sprawia, że po błędzie wracamy tutaj z ?err=.
 * Rzadsze metody (kod jednorazowy, SMS, certyfikat X.509) — przez pełny ekran /auth/login.php (?pelne=1).
 *
 * WCAG 2.1 AA: etykiety pól, błąd w role=alert, widoczny fokus (#facc15), obsługa klawiaturą, cele ≥ 44 px.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/inc/loginShell/loginShell.php';

auth_start();

$APP      = rtrim(APP_URL, '/');
$SELF     = $APP . '/tozsamosc/login.php';
$index    = $APP . '/tozsamosc/index.php';
// Dokąd po zalogowaniu — tylko adresy w obrębie Tożsamości.
$raw      = (string)($_GET['redirect'] ?? '');
$redirect = ($raw !== '' && str_starts_with($raw, $APP . '/tozsamosc/')) ? $raw : $index;

// Już zalogowany → prosto do portalu tożsamości.
if (current_user()) { header('Location: ' . $redirect); exit; }

$err      = trim((string)($_GET['err'] ?? ''));
$token    = csrf_token();
$self_url = $SELF . ($redirect !== $index ? '?redirect=' . urlencode($redirect) : '');
$action   = $APP . '/auth/login.php?redirect=' . urlencode($redirect);
$full_url = $APP . '/auth/login.php?pelne=1&redirect=' . urlencode($redirect);   // kod / SMS / certyfikat
$ms_ok    = function_exists('ms_login_available') && ms_login_available();
$ms_url   = ($ms_ok && function_exists('ms_auth_url')) ? ms_auth_url($redirect) : '';
$sso_url  = $APP . '/karty30/ti/sso.php';
$e        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Lewa kolumna: czym jest System Tożsamości (krótko, zwykłym językiem)
$side = '<div class="tzt-opis">'
      . '<p class="tzt-lead">Jedno konto i jedno logowanie do systemów Fundacji FEER.</p>'
      . '<ul class="tzt-lista">'
      . '<li><i class="bi bi-key" aria-hidden="true"></i><span><strong>Jedno logowanie (SSO).</strong> Logujesz się raz, a SZO, panel TI, CRM, poczta i połączone aplikacje otwierają się bez ponownego wpisywania hasła.</span></li>'
      . '<li><i class="bi bi-shield-lock" aria-hidden="true"></i><span><strong>Twoje bezpieczeństwo.</strong> Tu zmienisz hasło, telefon do kodów SMS, logowanie dwuetapowe i klucze bezpieczeństwa (passkey).</span></li>'
      . '<li><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span><strong>Dostępy i role.</strong> Widzisz, do których modułów i aplikacji masz dostęp i w jakiej roli.</span></li>'
      . '<li><i class="bi bi-file-earmark-lock" aria-hidden="true"></i><span><strong>Twoje dane (RODO).</strong> Sprawdzisz, co dzieje się z Twoimi danymi, i pobierzesz ich kopię.</span></li>'
      . '</ul>'
      . '<p class="tzt-uwaga"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>'
      . '<span>Hasło wpisuj tylko na stronach <strong>' . $e(parse_url($APP, PHP_URL_HOST) ?: 'szo.feer.org.pl') . '</strong>. '
      . 'Fundacja nigdy nie prosi o hasło ani kod SMS przez telefon czy e-mail. '
      . '<a href="' . $e($sso_url) . '">Więcej o SSO</a></span></p>'
      . '</div>';

loginShellHead([
    'title'     => 'Logowanie — System Tożsamości · Fundacja FEER',
    'orgLabel'  => 'FEER · System Tożsamości',
    'sideTitle' => 'Czym jest System Tożsamości?',
    'sideHtml'  => $side,
    'mainId'    => 'tzt-main',
    'extraHead' => '<style>'
        . '.tzt-lead{color:#e5e7eb;font-size:1.05rem;margin:.25rem 0 1.25rem}'
        . '.tzt-lista{list-style:none;padding:0!important;margin:0!important;display:grid;gap:.85rem;max-width:460px}'
        . '.tzt-lista li{display:flex;gap:.75rem;align-items:flex-start;margin:0!important;font-size:.95rem;line-height:1.5;color:#e5e7eb}'
        . '.tzt-lista strong{color:#fff}'
        . '.tzt-lista .bi{flex:0 0 2.2rem;height:2.2rem;display:flex;align-items:center;justify-content:center;background:#1ba1e2;color:#fff;font-size:1.1rem}'
        . '.tzt-uwaga{display:flex;gap:.6rem;align-items:flex-start;margin:1.5rem 0 0;padding:.8rem 1rem;max-width:460px;border-left:5px solid #facc15;background:rgba(255,255,255,.07);font-size:.88rem;color:#e5e7eb}'
        . '.tzt-uwaga .bi{color:#facc15;font-size:1.1rem}'
        . '.tzt-ms{display:flex!important;align-items:center;justify-content:center;gap:.6rem;min-height:48px}'
        . '.tzt-card-h{font-size:1.5rem;font-weight:700;margin:0 0 .25rem}'
        . '.tz-btn{min-height:48px;display:flex;align-items:center;justify-content:center}'
        . '.tzt-nowy{display:flex;gap:.6rem;align-items:flex-start;margin:0 0 1.25rem;padding:.75rem .9rem;background:#e6f4fb;border-left:6px solid #1ba1e2;font-size:.9rem;color:#1d1d1d}'
        . '.tzt-nowy .bi{color:#0f6fa8;font-size:1.15rem}'
        . '.tzt-inne{display:grid;gap:.5rem;margin-top:.5rem}'
        . '.tzt-inne a{display:flex;align-items:center;gap:.5rem;min-height:44px;padding:.4rem .2rem;color:#1d4ed8;font-weight:600}'
        . '.tzt-inne a .bi{color:#1ba1e2;font-size:1.1rem}'
        . '</style>',
]);
?>
      <div class="tzt-nowy" role="note">
        <i class="bi bi-calendar-check" aria-hidden="true"></i>
        <span><strong>Od 10.10.2026 obowiązuje nowy system zarządzania tożsamością.</strong>
        Konta założone wcześniej logują się jak za pierwszym razem — dotychczasowych haseł nie dało się przenieść.</span>
      </div>
      <h1 class="tzt-card-h">Zaloguj się</h1>
      <p class="tz-hint mt-0 mb-3">do Systemu Tożsamości Fundacji FEER</p>

      <?php if ($err !== ''): ?>
      <div class="alert alert-danger small py-2" role="alert" id="tzt-err"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><?= $e($err) ?></div>
      <?php endif; ?>

      <?php if ($ms_ok): ?>
      <a href="<?= $e($ms_url) ?>" class="tz-btn tz-btn-ghost tzt-ms"
         aria-label="Zaloguj przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
          <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
          <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
        </svg>
        Zaloguj przez Microsoft 365
      </a>
      <p class="tz-hint mt-2 mb-0">Konto <strong>@feer.org.pl</strong> — logowanie jednokrotne (SSO), bez wpisywania hasła.</p>
      <div class="tz-sep">lub e-mailem i hasłem</div>
      <?php endif; ?>

      <form method="post" action="<?= $e($action) ?>" novalidate autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= $e($token) ?>">
        <input type="hidden" name="_method" value="local">
        <input type="hidden" name="from" value="<?= $e($self_url) ?>">

        <div class="mb-3">
          <label for="tzt-email">Adres e-mail</label>
          <input type="email" class="form-control form-control-lg" id="tzt-email" name="email"
                 autocomplete="username" inputmode="email" required <?= $ms_ok ? '' : 'autofocus' ?>
                 <?= $err !== '' ? 'aria-invalid="true" aria-describedby="tzt-err"' : '' ?>>
        </div>
        <div class="mb-2">
          <label for="tzt-pass">Hasło</label>
          <div class="pass-wrap">
            <input type="password" class="form-control form-control-lg" id="tzt-pass" name="password"
                   autocomplete="current-password" required <?= $err !== '' ? 'aria-invalid="true"' : '' ?>>
            <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" onclick="tzPokaz('tzt-pass', this)">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <p class="mb-4"><a href="<?= $e($APP . '/user/verify_reset.php') ?>">Nie pamiętasz hasła?</a></p>

        <button type="submit" class="tz-btn">Zaloguj do Tożsamości</button>
      </form>

      <p class="tz-hint mt-4 mb-1 fw-semibold">Inne sposoby logowania</p>
      <div class="tzt-inne">
        <a href="<?= $e($full_url) ?>"><i class="bi bi-phone" aria-hidden="true"></i>Kod SMS, kod jednorazowy lub certyfikat</a>
        <a href="<?= $e($APP . '/tozsamosc/kursant.php') ?>"><i class="bi bi-mortarboard" aria-hidden="true"></i>Jestem kursantem TI</a>
      </div>
      <p class="tz-hint mb-0">Logowanie chronione: logowanie dwuetapowe i klucze bezpieczeństwa działają tak jak w całym systemie.</p>
<?php
loginShellFoot([
    'links' => [
        ['url' => $APP . '/auth/report_login_issue.php', 'label' => 'Problem z logowaniem'],
        ['url' => $sso_url,                              'label' => 'Czym jest SSO?'],
        ['url' => 'https://feer.org.pl',                 'label' => 'Strona Fundacji FEER'],
    ],
]);
