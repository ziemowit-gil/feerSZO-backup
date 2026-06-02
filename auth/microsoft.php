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
        // Nowy użytkownik z domeny M365 — automatycznie viewer
        $new_id = db_insert('users', [
            'name'         => $name,
            'email'        => $email,
            'microsoft_id' => $ms_id,
            'role'         => 'viewer',
            'is_active'    => 1,
        ]);
        $user = db_one("SELECT * FROM users WHERE id = ?", [$new_id]);
    }

    log_auth_action((int)$user['id'], 'login_ms', 'Logowanie Microsoft: ' . ($user['email'] ?? ''));
    login_user($user);
    header('Location: ' . $redirect_after);
    exit;
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
  <div class="text-center mb-3">
    <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:2rem"></i>
    <h5 class="mt-2">Błąd logowania Microsoft 365</h5>
  </div>
  <div class="alert alert-danger small"><?= h($error) ?></div>
  <div class="d-grid gap-2">
    <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary">
      <i class="bi bi-arrow-left"></i> Wróć do logowania
    </a>
  </div>
</div>
</div>
</body>
</html>
