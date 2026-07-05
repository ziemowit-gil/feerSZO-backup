<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

auth_start();

if (!ms_login_available()) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$error = '';
$code  = trim($_GET['code']  ?? '');
$state = trim($_GET['state'] ?? '');

// Błąd zwrócony przez Microsoft
if (!empty($_GET['error'])) {
    $error = 'Microsoft: ' . ($_GET['error_description'] ?? $_GET['error']);
}

// Walidacja state — sesja + DB backup
$_oauth_db_row = null;
if (!$error && $state) {
    // Źródło 1: sesja
    $expected = $_SESSION['ms_login_state'] ?? $_SESSION['ms_state'] ?? '';

    // Źródło 2: DB oauth_states (fallback gdy sesja zgubiona przy przekierowaniu OAuth)
    if (!$expected || $expected !== $state) {
        try {
            $_oauth_db_row = db_one(
                "SELECT * FROM oauth_states WHERE state=? AND created_at > ?",
                [$state, time() - 900]
            );
            if ($_oauth_db_row) {
                // Odtwórz dane sesji z DB
                $_SESSION['ms_login_state']    = $state;
                $_SESSION['ms_login_verifier'] = $_oauth_db_row['verifier'];
                $_SESSION['ms_login_redirect'] = $_oauth_db_row['redirect_to'];
                $expected = $state;
            }
        } catch (\Throwable $e) {}
    }

    if (!$state || $state !== $expected) {
        $error = 'Błąd autoryzacji — nieprawidłowy parametr state. Spróbuj zalogować się ponownie.';
    }
}

if (!$error && !$state) {
    $error = 'Błąd autoryzacji — brak parametru state.';
}

if (!$error && !$code) {
    $error = 'Brak kodu autoryzacyjnego w odpowiedzi Microsoft.';
}

$redirect_after = APP_URL . '/portal.php';
if (!$error) {
    $redirect_after = $_SESSION['ms_login_redirect'] ?? APP_URL . '/portal.php';

    // Wymieniamy kod na token PRZED usunięciem ms_login_verifier z sesji
    $tokens = ms_exchange_code($code);

    // Usuń stan z DB (jednorazowe użycie)
    try {
        db()->prepare("DELETE FROM oauth_states WHERE state=?")->execute([$state]);
    } catch (\Throwable $e) {}

    unset($_SESSION['ms_login_state'], $_SESSION['ms_login_verifier'],
          $_SESSION['ms_login_client_id'], $_SESSION['ms_login_redirect'],
          $_SESSION['ms_state']); // legacy

    if (empty($tokens['access_token'])) {
        $err_desc = $tokens['error_description'] ?? $tokens['error'] ?? 'nieznany błąd';
        $error = 'Nie udało się uzyskać tokenu: ' . $err_desc;
    }
}

if (!$error) {
    $ms_user = ms_get_user($tokens['access_token']);
    if (empty($ms_user['mail']) && empty($ms_user['userPrincipalName'])) {
        $error = 'Nie udało się pobrać danych konta Microsoft.';
    }
}

if (!$error) {
    $email = $ms_user['mail'] ?? $ms_user['userPrincipalName'];
    $name  = $ms_user['displayName'] ?? $email;
    $ms_id = $ms_user['id'] ?? '';

    // Szukaj użytkownika po e-mailu lub microsoft_id (niezależnie od is_active)
    $existing = db_one("SELECT * FROM users WHERE (email = ? OR microsoft_id = ?) LIMIT 1",
                       [$email, $ms_id]);

    if ($existing && !$existing['is_active']) {
        $error = 'Konto zostało dezaktywowane. Skontaktuj się z administratorem.';
    }
}

if (!$error) {
    if ($existing) {
        // Aktualizuj microsoft_id i email jeśli się zmieniły
        $upd = [];
        if (($existing['microsoft_id'] ?? '') !== $ms_id) $upd['microsoft_id'] = $ms_id;
        if ($existing['email']                !== $email)  $upd['email']        = $email;
        if ($upd) db_update('users', $upd, $existing['id']);
        $user = $existing;
    } else {
        // Brak konta w systemie — nie twórz automatycznie, pokaż komunikat
        $error            = 'no_account';
        $ms_display_name  = $name  ?? '';
        $ms_display_email = $email ?? '';
    }

    // Logowanie przez Microsoft 365 jest zarezerwowane dla administracji i
    // koordynatorów (konta @feer.org.pl w roli admin/editor — account_is_office_only).
    // Wolontariusze i współpracownicy logują się prywatnym e-mailem i hasłem.
    // Wyjątek: wejście do panelu dydaktyka (osobna weryfikacja uprawnień w
    // office_enter.php) — tam Office mogą użyć też prowadzący w innych rolach.
    if (!$error && isset($user)) {
        $is_dyd_flow = str_contains((string)$redirect_after, '/karty30/ti/dydaktyk/');
        if (!$is_dyd_flow && !account_is_office_only($user)) {
            if (function_exists('authlog_write')) {
                authlog_write((int)$user['id'], 'login_blocked_office', $user['email'] ?? '',
                    'Konto wolontariusza/współpracownika — logowanie przez Microsoft 365 zablokowane');
            }
            $error = 'office_not_allowed';
        }
    }

    // Zaloguj i przekieruj tylko gdy nie ma błędu
    if (!$error && isset($user)) {
        // Sprawdź czy crm_only — czy ma kod IKA (wymagany)
        $u_crm_only = ($user['role'] === 'crm_user');
        if (!$u_crm_only) {
            try {
                $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$user['role']]);
                $u_crm_only = !empty($r['crm_only']);
            } catch (\Throwable $e) {}
        }

        if ($u_crm_only && empty($user['cpc_code'])) {
            // Brak kodu IKA — nie loguj, pokaż błąd
            $error = 'Twoje konto wymaga aktywacji kodu IKA przed pierwszym logowaniem. Skontaktuj się z administratorem.';
        } else {
            log_auth_action((int)$user['id'], 'login_ms', 'Logowanie Microsoft: ' . ($user['email'] ?? ''));

            // Docelowy adres liczymy PRZED zalogowaniem — potrzebny też, gdy trzeba
            // przejść przez weryfikację klucza WebAuthn (admin/editor).
            $final_redirect = $redirect_after;
            if ($u_crm_only) {
                $crm_base = APP_URL . '/crm/';
                if (!str_starts_with($final_redirect, $crm_base) && $final_redirect !== APP_URL . '/crm') {
                    $final_redirect = APP_URL . '/crm/dashboard.php';
                }
            }

            // ezd_only → zawsze do EZD Wirtualne biurko (bez bramki IKA)
            $u_ezd_only = ($user['role'] === 'ezd_user');
            if (!$u_ezd_only) {
                try { $r = db_one("SELECT ezd_only FROM roles WHERE name=?", [$user['role']]); $u_ezd_only = !empty($r['ezd_only']); } catch (\Throwable $e) {}
            }
            if ($u_ezd_only) {
                $final_url = APP_URL . '/ezd/index.php';
            } elseif (!empty($user['cpc_code'])) {
                // Przez IKA gate jeśli kod ustawiony
                $final_url = APP_URL . '/contracts/ika_gate.php?to=' . urlencode($final_redirect);
            } else {
                $final_url = $final_redirect;
            }

            // Oznacz sesję założoną na potrzeby mostka do panelu dydaktyka — jeśli
            // konto nie jest dydaktykiem, office_enter.php może ją wycofać.
            if (!empty($is_dyd_flow)) $_SESSION['ms_dyd_bridge'] = 1;

            // Admini/edytorzy z zarejestrowanym kluczem sprzętowym muszą go użyć
            // RÓWNIEŻ przy logowaniu przez Microsoft 365 — nie tylko lokalnym hasłem.
            require_once dirname(__DIR__) . '/includes/webauthn.php';
            if (webauthn_login_gate($user, $final_url)) exit;

            login_user($user);
            header('Location: ' . $final_url);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Błąd logowania — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>body{background:#f0f4f8}.card{max-width:480px;margin:100px auto}</style>
</head>
<body>
<div class="card shadow-sm">
<div class="card-body p-4">

<?php if ($error === 'office_not_allowed'): ?>
  <!-- Konto wolontariusza/współpracownika — Office niedozwolone -->
  <div class="text-center mb-3">
    <div style="width:64px;height:64px;border-radius:16px;background:#DBEAFE;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;margin-bottom:.75rem">
      ✉️
    </div>
    <h5 class="fw-bold mb-1">Zaloguj się prywatnym e-mailem</h5>
    <p class="text-muted small mb-0">
      To konto (wolontariusz / współpracownik) loguje się <strong>prywatnym e-mailem i hasłem</strong>.
      Logowanie przez Microsoft 365 jest zarezerwowane dla administracji i koordynatorów.
    </p>
  </div>
  <div class="d-grid gap-2">
    <a href="<?= APP_URL ?>/auth/login.php?view=priv" class="btn btn-primary btn-sm">
      <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj się e-mailem i hasłem
    </a>
    <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Wróć do strony logowania
    </a>
  </div>

<?php elseif ($error === 'no_account'): ?>
  <!-- Brak konta w systemie -->
  <div class="text-center mb-3">
    <div style="width:64px;height:64px;border-radius:16px;background:#FEF3C7;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;margin-bottom:.75rem">
      🔒
    </div>
    <h5 class="fw-bold mb-1">Brak dostępu do systemu</h5>
    <p class="text-muted small mb-0">
      Twoje konto Microsoft zostało rozpoznane, ale nie masz jeszcze dostępu do systemu organizacji.
    </p>
  </div>

  <div class="alert alert-warning d-flex gap-2 py-2 mb-3" style="font-size:.85rem">
    <i class="bi bi-person-x-fill flex-shrink-0 mt-1" style="color:#d97706"></i>
    <div>
      Zalogowano jako: <strong><?= h($ms_display_name ?? '') ?></strong><br>
      <span class="text-muted"><?= h($ms_display_email ?? '') ?></span>
    </div>
  </div>

  <div class="bg-light rounded p-3 mb-3" style="font-size:.84rem">
    <div class="fw-semibold mb-1"><i class="bi bi-info-circle text-primary me-1"></i>Co zrobić?</div>
    <ul class="mb-0 ps-3" style="line-height:1.7">
      <li>Skontaktuj się z administratorem organizacji i poproś o nadanie dostępu.</li>
      <li>Jeśli masz umowę wolontariacką — opiekun może przypisać Ci konto po zalogowaniu.</li>
      <li>Możesz też spróbować zalogować się <strong>kodem jednorazowym</strong> otrzymanym od admina.</li>
    </ul>
  </div>

  <div class="d-grid gap-2">
    <a href="<?= APP_URL ?>/auth/login.php?tab=code" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-key me-1"></i>Zaloguj się kodem jednorazowym
    </a>
    <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Wróć do strony logowania
    </a>
  </div>

<?php else: ?>
  <!-- Ogólny błąd -->
  <div class="text-center mb-3">
    <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:2rem"></i>
    <h5 class="mt-2">Błąd logowania Microsoft 365</h5>
  </div>
  <div class="alert alert-danger small"><?= h($error) ?></div>
  <div class="d-grid gap-2">
    <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary">
      <i class="bi bi-arrow-left me-1"></i>Wróć do logowania
    </a>
  </div>
<?php endif; ?>

</div>
</div>
</body>
</html>
