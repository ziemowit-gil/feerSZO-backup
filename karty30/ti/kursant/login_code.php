<?php
/**
 * karty30/ti/kursant/login_code.php — wpisanie kodu SMS przy pierwszym logowaniu kursanta.
 * Po poprawnym kodzie: sesja kursanta + wymuszona zmiana hasła (must_change_password).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_student_otp.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
if (student_current()) { header('Location: index.php'); exit; }

student_start();
$p = $_SESSION['k30_ti_student_otp'] ?? null;
if (!$p || time() - (int)($p['ts'] ?? 0) > 600) {
    unset($_SESSION['k30_ti_student_otp']);
    header('Location: ../login.php?tab=kursant'); exit;
}
$acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id = ? AND is_active = 1", [(int)$p['id']]);
if (!$acc) {
    unset($_SESSION['k30_ti_student_otp']);
    header('Location: ../login.php?tab=kursant'); exit;
}

$blad = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ti_student_otp_check((int)$acc['id'], (string)($_POST['code'] ?? ''))) {
        unset($_SESSION['k30_ti_student_otp']);
        db()->prepare("UPDATE k30_ti_student_accounts SET must_change_password = 1, updated_at = datetime('now') WHERE id = ?")
            ->execute([(int)$acc['id']]);
        $acc['must_change_password'] = 1;
        student_login_user($acc, 'otp', 'pierwsze logowanie (kod SMS)');
        // Najpierw wybór obrazka bezpieczeństwa (jeśli jeszcze nie wybrano), potem panel (hasło wymuszone).
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_sec_image.php';
        ti_sec_image_migrate();
        $wybrany = db_one("SELECT sec_chosen FROM k30_ti_student_accounts WHERE id = ?", [(int)$acc['id']]);
        header('Location: ' . (empty($wybrany['sec_chosen']) ? 'sec_choose.php' : 'index.php')); exit;
    }
    $blad = 'Nieprawidłowy kod albo kod wygasł. Spróbuj ponownie lub wróć do logowania, aby dostać nowy.';
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kod z SMS-a — panel kursanta</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width:420px">
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold mb-2">Wpisz kod z SMS-a</h1>
      <p class="small text-body-secondary mb-3">Wysłaliśmy 6-cyfrowy kod na telefon z Twojej karty. Kod jest ważny 5 minut.</p>
      <?php if ($blad !== ''): ?>
      <div class="alert alert-danger small py-2" role="alert"><?= htmlspecialchars($blad, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <label for="otp-code" class="form-label fw-semibold">Kod</label>
        <input type="text" id="otp-code" name="code" class="form-control form-control-lg mb-3" inputmode="numeric"
               pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
        <button type="submit" class="btn btn-primary w-100">Zaloguj</button>
      </form>
      <p class="small mt-3 mb-0"><a href="../login.php?tab=kursant">Wróć do logowania</a></p>
    </div>
  </div>
</main>
</body>
</html>
