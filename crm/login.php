<?php
/**
 * crm/login.php — logowanie do CRM (crm.feer.org.pl).
 *
 * Osobny, brandowany ekran, ale TEN SAM silnik uwierzytelniania co /auth/login.php:
 * ochrona przed atakiem słownikowym (brute_*), polityka „konto służbowe tylko przez
 * Microsoft 365", 2FA (/auth/2fa.php), bramka WebAuthn i wymuszona zmiana hasła.
 * Wcześniej ten ekran robił własne `password_verify` z pominięciem tych mechanizmów —
 * czyli logowanie do CRM było słabiej chronione niż do reszty systemu.
 *
 * Warstwa CRM-owa zostaje: sprawdzenie uprawnień do modułu, bramka IKA dla kont
 * „tylko CRM" i powrót na host aliasu (crm.feer.org.pl), a nie na szo.feer.org.pl.
 *
 * WCAG: etykiety powiązane z polami, komunikat błędu jako role="alert",
 * widoczny fokus, obsługa klawiaturą, kontrast tekstu na tle marki.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/crm_sender_trust.php';   // domena organizacji
require_once dirname(__DIR__) . '/includes/approval.php';   // log_auth_action()

auth_start();

// Alias crm.feer.org.pl → zostajemy na tym hoście; APP_URL wskazuje szo.feer.org.pl,
// więc bez tego użytkownik po zalogowaniu wylądowałby na innej domenie.
$crm_base = crm_alias_base_url() ?? APP_URL;
$redirect = $crm_base . '/crm/dashboard.php';

if (current_user()) { header('Location: ' . $redirect); exit; }

$_b       = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'System');

$org_domain   = crm_trusted_domains()[0] ?? 'feer.org.pl';
$ms_available = ms_login_available();
$ms_url       = $ms_available ? ms_auth_url($redirect) : '';

$error = '';

/** Czy rola użytkownika daje wstęp do CRM (role systemowe + własne z flagą crm_only). */
function _crm_login_role_ok(array $user): bool {
    if (in_array($user['role'], ['admin', 'editor', 'viewer', 'crm_user'], true)) return true;
    try {
        $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$user['role']]);
        return !empty($r['crm_only']);
    } catch (\Throwable $e) { return false; }
}

/** Konto „tylko CRM" — takie przechodzi przez bramkę IKA. */
function _crm_login_is_crm_only(array $user): bool {
    if ($user['role'] === 'crm_user') return true;
    try {
        $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$user['role']]);
        return !empty($r['crm_only']);
    } catch (\Throwable $e) { return false; }
}

// ── POST: logowanie hasłem ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_csrf = $_SESSION['csrf'] ?? '';
    if ($session_csrf === '' || !hash_equals($session_csrf, (string)($_POST['_csrf'] ?? ''))) {
        unset($_SESSION['csrf']); csrf_token();
        $error = 'Token sesji wygasł. Spróbuj ponownie.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');

        $blocked_sec = brute_check($email);
        if ($blocked_sec !== null) {
            $mins  = (int)ceil($blocked_sec / 60);
            $error = "Konto tymczasowo zablokowane po zbyt wielu nieudanych próbach. Spróbuj ponownie za {$mins} min.";
            authlog_write(null, 'login_blocked', $email, 'Zablokowany dostęp (CRM) z IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        } else {
            $user = db_one("SELECT * FROM users WHERE email=? AND (is_active=1 OR email='serwis@local')", [$email]);

            if ($user && account_is_office_only($user) && empty($user['allow_local_fallback'])) {
                authlog_write((int)$user['id'], 'login_blocked_office', $user['email'],
                    'Konto służbowe — wymagane logowanie przez Microsoft 365 (CRM)');
                $error = 'Konto służbowe @feer.org.pl loguje się wyłącznie przez Microsoft 365 — użyj przycisku wyżej.';

            } elseif ($user && $user['password'] && password_verify($pass, $user['password'])) {
                brute_clear($email);

                if (!_crm_login_role_ok($user)) {
                    authlog_write((int)$user['id'], 'login_denied', $user['email'], 'Brak uprawnień do CRM');
                    $error = 'Twoje konto nie ma uprawnień do modułu CRM.';
                } else {
                    // Konto „tylko CRM" bez kodu IKA nie wejdzie — chyba że admin je zwolnił
                    $ika_flag   = isset($user['crm_ika_required']) && $user['crm_ika_required'] !== null
                                  ? (int)$user['crm_ika_required'] : null;
                    $ika_exempt = ($ika_flag === 0);
                    $ika_forced = ($ika_flag === 1);
                    $crm_only   = _crm_login_is_crm_only($user);

                    if (!$ika_exempt && ($crm_only || $ika_forced) && empty($user['cpc_code'])) {
                        $error = 'Twoje konto wymaga aktywacji kodu IKA przed pierwszym logowaniem. '
                               . 'Skontaktuj się z administratorem systemu.';
                    } else {
                        $after_login = (!$ika_exempt && !empty($user['cpc_code']))
                            ? APP_URL . '/contracts/ika_gate.php?to=' . urlencode($redirect)
                            : $redirect;

                        // 2FA — wspólny ekran, ten sam co przy logowaniu systemowym
                        if (!empty($user['twofa_method'])) {
                            $_SESSION['2fa_uid']      = $user['id'];
                            $_SESSION['2fa_method']   = $user['twofa_method'];
                            $_SESSION['2fa_phone']    = $user['twofa_phone'] ?? '';
                            $_SESSION['2fa_attempts'] = 0;
                            if ($user['twofa_method'] === 'sms' && !empty($user['twofa_phone'])) {
                                try {
                                    require_once dirname(__DIR__) . '/includes/sms.php';
                                    $otp = sms_generate_otp($user['twofa_phone'], $user['id']);
                                    sms_send_with_fallback($user['twofa_phone'], "Kod 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '');
                                    $_SESSION['2fa_sms_sent'] = true;
                                } catch (\Throwable $e) {}
                            }
                            header('Location: ' . APP_URL . '/auth/2fa.php?redirect=' . urlencode($after_login));
                            exit;
                        }

                        log_auth_action((int)$user['id'], 'login', 'Logowanie CRM: ' . $user['email']);
                        authlog_write((int)$user['id'], 'login', $user['email'], 'Logowanie lokalne (CRM)');

                        // Klucz sprzętowy dla ról z podwyższonym ryzykiem
                        require_once dirname(__DIR__) . '/includes/webauthn.php';
                        if (webauthn_login_gate($user, $after_login)) exit;

                        login_user($user);
                        crm_migrate();

                        if (auth_must_change_password($user)) {
                            flash_set('warning', 'Administrator zresetował Twoje hasło. Ustaw nowe przed kontynuowaniem.');
                            header('Location: ' . APP_URL . '/panel/password.php?force=1'); exit;
                        }
                        header('Location: ' . $after_login); exit;
                    }
                }
            } else {
                brute_record_fail($email);
                authlog_write(null, 'login_fail', $email, 'Nieudana próba logowania (CRM)');
                $error = 'Nieprawidłowy adres e-mail lub hasło.';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logowanie do CRM — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
body {
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  background: #F6F7F9; color: #111827;
  display: flex; align-items: center; justify-content: center; padding: 2rem 1rem;
}

/* Jedna karta na spokojnym tle — bez wielkiego panelu marketingowego z lewej */
.cl-card { width: 100%; max-width: 420px; }
.cl-brand { display: flex; align-items: center; gap: .7rem; margin-bottom: 1.4rem; }
.cl-brand-icon {
  width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
  background: var(--c, #2E844A); color: #fff;
  display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
}
.cl-brand-name { font-size: 1.05rem; font-weight: 800; letter-spacing: .2px; }
.cl-brand-org  { font-size: .78rem; color: #6B7280; }

.cl-box { background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; padding: 1.5rem; }
.cl-h1  { font-size: 1.15rem; font-weight: 700; margin: 0 0 .25rem; }
.cl-sub { font-size: .84rem; color: #6B7280; margin: 0 0 1.15rem; }

.cl-err {
  display: flex; gap: .5rem; align-items: flex-start;
  background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B;
  border-radius: 10px; padding: .6rem .75rem; font-size: .82rem; margin-bottom: 1rem;
}

.cl-ms {
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  width: 100%; height: 44px; border-radius: 10px; border: 1px solid #E5E7EB;
  background: #fff; color: #111827; font-size: .9rem; font-weight: 600;
  text-decoration: none; cursor: pointer; transition: background .12s, border-color .12s;
}
.cl-ms:hover { background: #F3F4F6; border-color: #D1D5DB; }
.cl-ms img { width: 18px; height: 18px; }

.cl-or { display: flex; align-items: center; gap: .75rem; margin: 1.1rem 0; }
.cl-or::before, .cl-or::after { content: ''; flex: 1; height: 1px; background: #E5E7EB; }
.cl-or span { font-size: .73rem; color: #9CA3AF; white-space: nowrap; }

.cl-lbl { display: block; font-size: .78rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
.cl-in {
  width: 100%; height: 42px; padding: 0 .8rem; font-size: .9rem; color: #111827;
  border: 1px solid #E5E7EB; border-radius: 10px; background: #fff;
}
.cl-in:focus { border-color: var(--c, #2E844A); box-shadow: 0 0 0 3px rgba(46,132,74,.14); outline: none; }
.cl-row { margin-bottom: .9rem; position: relative; }
.cl-hint { font-size: .74rem; color: #9CA3AF; margin-top: .3rem; line-height: 1.45; }
.cl-eye {
  position: absolute; right: .4rem; top: 26px; height: 34px; width: 34px;
  border: 0; background: transparent; color: #9CA3AF; cursor: pointer; border-radius: 8px;
}
.cl-eye:hover { color: #374151; background: #F3F4F6; }

.cl-btn {
  width: 100%; height: 44px; border: 0; border-radius: 10px;
  background: var(--c, #2E844A); color: #fff; font-size: .92rem; font-weight: 700; cursor: pointer;
}
.cl-btn:hover { filter: brightness(1.06); }
.cl-btn:focus-visible, .cl-ms:focus-visible, .cl-in:focus-visible { outline: 3px solid var(--c, #2E844A); outline-offset: 2px; }

.cl-foot { margin-top: 1rem; font-size: .78rem; color: #9CA3AF; display: flex; flex-wrap: wrap; gap: .25rem .9rem; }
.cl-foot a { color: #6B7280; text-decoration: none; }
.cl-foot a:hover { color: #111827; text-decoration: underline; }
.cl-note { margin-top: 1.1rem; font-size: .75rem; color: #9CA3AF; line-height: 1.55; }
</style>
</head>
<body>
<main class="cl-card">

  <div class="cl-brand">
    <div class="cl-brand-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
    <div>
      <div class="cl-brand-name">CRM</div>
      <div class="cl-brand-org"><?= h($org_name) ?></div>
    </div>
  </div>

  <div class="cl-box">
    <h1 class="cl-h1">Zaloguj się</h1>
    <p class="cl-sub">Kontakty, sprawy, oferty i korespondencja organizacji.</p>

    <?php if ($error): ?>
    <div class="cl-err" role="alert">
      <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($ms_available): ?>
    <a class="cl-ms" href="<?= h($ms_url) ?>">
      <i class="bi bi-microsoft" aria-hidden="true"></i>Zaloguj przez Microsoft 365
    </a>
    <p class="cl-hint" style="text-align:center;margin-top:.5rem">
      Konta służbowe <strong>@<?= h($org_domain) ?></strong> logują się wyłącznie tą drogą.
    </p>
    <div class="cl-or"><span>albo e-mailem i hasłem</span></div>
    <?php endif; ?>

    <form method="post" autocomplete="on" novalidate>
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="cl-row">
        <label class="cl-lbl" for="email">Adres e-mail</label>
        <input type="email" class="cl-in" name="email" id="email" required
               autocomplete="email" placeholder="nazwa@domena.pl"
               value="<?= h($_POST['email'] ?? '') ?>"
               aria-describedby="emailHint" <?= $ms_available ? '' : 'autofocus' ?>>
        <div class="cl-hint" id="emailHint">
          Adres prywatny podany przy współpracy z fundacją albo konto z hasłem awaryjnym.
        </div>
      </div>

      <div class="cl-row">
        <label class="cl-lbl" for="password">Hasło</label>
        <input type="password" class="cl-in" name="password" id="password" required
               autocomplete="current-password" style="padding-right:2.6rem">
        <button type="button" class="cl-eye" id="eyeBtn" aria-label="Pokaż hasło">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
      </div>

      <button type="submit" class="cl-btn">
        <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj
      </button>
    </form>

    <div class="cl-foot">
      <a href="<?= APP_URL ?>/user/verify_reset.php"><i class="bi bi-key me-1" aria-hidden="true"></i>Nie pamiętam hasła</a>
      <a href="<?= APP_URL ?>/tozsamosc/index.php"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Moja tożsamość</a>
      <?php if (!CRM_STANDALONE): ?>
      <a href="<?= APP_URL ?>/auth/login.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Logowanie systemowe</a>
      <?php endif; ?>
    </div>
  </div>

  <p class="cl-note">
    Logowanie chronione tak samo jak w całym systemie: blokada po serii nieudanych prób,
    drugi składnik (2FA) i klucz sprzętowy dla ról administracyjnych.
  </p>

</main>

<script>
// Podgląd hasła — bez inline onclick, żeby CSP nie musiała go dopuszczać
(function () {
  var b = document.getElementById('eyeBtn'), i = document.getElementById('password');
  if (!b || !i) return;
  b.addEventListener('click', function () {
    var show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    b.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    b.setAttribute('aria-label', show ? 'Ukryj hasło' : 'Pokaż hasło');
  });
})();
</script>
</body>
</html>
