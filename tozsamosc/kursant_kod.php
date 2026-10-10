<?php
/**
 * tozsamosc/kursant_kod.php — kod SMS przy pierwszym logowaniu kursanta (moduł Tożsamość).
 * Po poprawnym kodzie: sesja kursanta, wybór obrazka (jeśli brak), potem wymuszona zmiana hasła w panelu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_student_otp.php';
require_once dirname(__DIR__) . '/includes/ti_sec_image.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
ti_sec_image_migrate();
student_start();

$p = $_SESSION['k30_ti_student_otp'] ?? null;
if (!$p || time() - (int)($p['ts'] ?? 0) > 600) {
    unset($_SESSION['k30_ti_student_otp']);
    header('Location: ' . APP_URL . '/tozsamosc/kursant.php'); exit;
}
$acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id = ? AND is_active = 1", [(int)$p['id']]);
if (!$acc) {
    unset($_SESSION['k30_ti_student_otp']);
    header('Location: ' . APP_URL . '/tozsamosc/kursant.php'); exit;
}

$blad = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ti_student_otp_check((int)$acc['id'], (string)($_POST['code'] ?? ''))) {
        unset($_SESSION['k30_ti_student_otp']);
        db()->prepare("UPDATE k30_ti_student_accounts SET must_change_password = 1, updated_at = datetime('now') WHERE id = ?")
            ->execute([(int)$acc['id']]);
        $acc['must_change_password'] = 1;
        student_login_user($acc, 'otp', 'pierwsze logowanie (kod SMS, Tożsamość)');
        $wybrany = db_one("SELECT sec_chosen FROM k30_ti_student_accounts WHERE id = ?", [(int)$acc['id']]);
        header('Location: ' . APP_URL . (empty($wybrany['sec_chosen'])
            ? '/karty30/ti/kursant/sec_choose.php'
            : '/karty30/ti/kursant/index.php')); exit;
    }
    $blad = 'Nieprawidłowy kod albo kod wygasł. Wróć do logowania, aby dostać nowy.';
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kod z SMS-a — System Tożsamości</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; }
  .metro-bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .metro-card { background: #fff; border: 0; border-radius: 0; padding: 2rem; max-width: 420px; margin: 2rem auto; }
  .metro-card .form-control { border-radius: 0; box-shadow: none; }
  .metro-btn { border-radius: 0; background: #1ba1e2; border-color: #1ba1e2; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #1790c9; border-color: #1790c9; color: #fff; }
  .metro-btn:focus-visible, .form-control:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<div role="region" aria-label="Informacja o logowaniu" style="background:#1ba1e2;color:#fff;padding:.6rem 1rem;text-align:center;font-weight:600;font-size:.95rem">
  Logowanie centralne przez System Tożsamości — adresy i zapisane linki bez zmian.
  <a href="../karty30/ti/sso.php" style="color:#fff;text-decoration:underline;margin-left:.5rem">Czym jest SSO?</a>
</div>
<header class="metro-bar">System Tożsamości · Kod z SMS-a</header>
<main class="metro-card">
  <h1 class="h5 fw-bold mb-2">Wpisz kod z SMS-a</h1>
  <p class="small text-body-secondary mb-3">Wysłaliśmy 6-cyfrowy kod na telefon z Twojej karty. Kod jest ważny 5 minut.</p>
  <?php if ($blad !== ''): ?>
  <div class="alert alert-danger small py-2 rounded-0" role="alert"><?= htmlspecialchars($blad, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="otp-code" class="form-label fw-semibold">Kod</label>
    <input type="text" id="otp-code" name="code" class="form-control form-control-lg mb-3" inputmode="numeric"
           pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
    <button type="submit" class="btn btn-lg metro-btn w-100">Zaloguj</button>
  </form>
  <p class="small mt-3 mb-0"><a href="kursant.php">Wróć do logowania</a></p>
</main>
</body>
</html>
