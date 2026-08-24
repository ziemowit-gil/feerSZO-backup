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
 * WYGLĄD: ta sama powłoka co logowanie do systemu (includes/auth_screen.php) —
 * pasek dostępności (rozmiar tekstu, wysoki kontrast), tło marki z geometrią,
 * biała karta i te same kontrolki. Wcześniej ekran miał własny arkusz stylów
 * i wyglądał jak inna aplikacja, choć uwierzytelnia dokładnie tak samo; każda
 * poprawka wyglądu albo dostępności trzeba było robić dwa razy.
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

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Logowanie do CRM',
    'tab'       => '',            // CRM nie ma rejestracji — zakładki byłyby ślepą uliczką
    'bootstrap' => true,
    'main_id'   => 'crm-login-main',
]);
?>

    <?php if ($error): ?>
    <div class="l-alert l-alert-danger" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <h1 class="ks-h1">CRM <?= h($org_name) ?></h1>
    <p class="ks-lead">Kontakty, sprawy, oferty i korespondencja organizacji.</p>

    <?php if ($ms_available): ?>
    <a href="<?= h($ms_url) ?>" class="ks-btn ks-btn--ghost"
       aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
        <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      Zaloguj przez Microsoft 365
    </a>
    <p class="ks-hint">
      Konta służbowe <strong>@<?= h($org_domain) ?></strong> logują się wyłącznie tą drogą.
    </p>
    <div class="ks-or"><span>lub e-mailem i hasłem</span></div>
    <?php endif; ?>

    <form method="post" novalidate autocomplete="on" aria-label="Logowanie e-mailem i hasłem">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="ks-field">
        <label for="f-email">Adres e-mail</label>
        <input type="email" name="email" id="f-email" class="form-control"
               autocomplete="email" inputmode="email" required
               value="<?= h($_POST['email'] ?? '') ?>"
               aria-describedby="emailHint"
               <?= $ms_available ? '' : 'autofocus' ?>
               <?= $error ? 'aria-invalid="true"' : '' ?>>
        <p class="ks-fieldhint" id="emailHint">
          Adres prywatny podany przy współpracy z fundacją albo konto z hasłem awaryjnym.
        </p>
      </div>

      <div class="ks-field">
        <label for="f-pass">Hasło</label>
        <div class="pass-wrap">
          <input type="password" name="password" id="f-pass" class="form-control"
                 autocomplete="current-password" required
                 <?= $error ? 'aria-invalid="true"' : '' ?>>
          <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
                  onclick="togglePass('f-pass', this)">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
        <a href="<?= APP_URL ?>/user/verify_reset.php" class="ks-forgot">Nie pamiętasz hasła?</a>
      </div>

      <button type="submit" class="ks-btn ks-btn--primary">Zaloguj</button>
    </form>

    <hr class="ks-sep">

    <p class="ks-hint">
      Logowanie chronione tak samo jak w całym systemie: blokada po serii nieudanych prób,
      drugi składnik (2FA) i klucz sprzętowy dla ról administracyjnych.
    </p>

<?php
// Odnośniki pod kartą — te same, które ekran miał w stopce, w powłoce systemowej
$_crm_links = [
    ['url' => APP_URL . '/tozsamosc/index.php', 'label' => 'Moja tożsamość', 'icon' => 'bi-person-vcard'],
    ['url' => APP_URL . '/user/verify_reset.php', 'label' => 'Odzyskaj dostęp', 'icon' => 'bi-key'],
];
if (!CRM_STANDALONE) {
    $_crm_links[] = ['url' => APP_URL . '/auth/login.php', 'label' => 'Logowanie systemowe', 'icon' => 'bi-box-arrow-in-right'];
}
auth_screen_foot(['links' => $_crm_links]);
