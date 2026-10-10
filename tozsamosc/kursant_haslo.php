<?php
/**
 * tozsamosc/kursant_haslo.php — zmiana hasła kursanta w module Tożsamość.
 * Dostęp: zalogowany kursant. Zmiana działa tak samo jak w panelu kursanta (min. 8 znaków, aktualne hasło wymagane).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_messages.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
$student = student_current();
if (!$student) { header('Location: ' . APP_URL . '/tozsamosc/kursant.php'); exit; }

$blad = '';
$_mcp = db_one("SELECT must_change_password FROM k30_ti_student_accounts WHERE id = ?", [(int)$student['id']]);
$wymuszona = !empty($_mcp['must_change_password']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $cnf = (string)($_POST['confirm'] ?? '');
    $acc = db_one("SELECT password_hash, ms_user_id, ms_upn FROM k30_ti_student_accounts WHERE id = ?", [(int)$student['id']]);
    // Po logowaniu kodem SMS (wymuszona zmiana) nie pytamy o stare hasło.
    if (!$acc || (!$wymuszona && !password_verify($cur, (string)$acc['password_hash']))) {
        $blad = 'Aktualne hasło jest nieprawidłowe.';
    } elseif (mb_strlen($new) < 8) {
        $blad = 'Nowe hasło musi mieć co najmniej 8 znaków.';
    } elseif ($new !== $cnf) {
        $blad = 'Nowe hasła nie są identyczne.';
    } elseif (!$wymuszona && $new === $cur) {
        $blad = 'Nowe hasło musi różnić się od dotychczasowego.';
    } else {
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash = ?, must_change_password = 0, updated_at = datetime('now') WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), (int)$student['id']]);
        $log_note = 'Zmieniono hasło (Tożsamość).';
        // Jedno hasło do wszystkiego: jeśli kursant ma konto Microsoft (EDU), ustaw też tam.
        if (!empty($acc['ms_user_id'])) {
            try {
                m365_edu()->set_password($acc['ms_user_id'], $new, false);
                $log_note .= ' Zaktualizowano też hasło konta Microsoft (' . $acc['ms_upn'] . ').';
            } catch (\Throwable $e) {
                $log_note .= ' UWAGA: nie udało się zaktualizować hasła konta Microsoft: ' . $e->getMessage();
            }
        }
        ti_account_log((int)$student['id'], 'password_changed', $log_note);
        flash_set('success', 'Hasło zostało zmienione.');
        // Po wymuszonej zmianie (pierwsze logowanie) zachęć do klucza sprzętowego.
        header('Location: ' . APP_URL . ($wymuszona ? '/tozsamosc/kursant_klucz.php?nowy=1' : '/tozsamosc/kursant_haslo.php?ok=1')); exit;
    }
}
$ok = isset($_GET['ok']);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zmiana hasła — System Tożsamości</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; }
  .metro-bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .metro-card { background: #fff; padding: 2rem; max-width: 460px; margin: 2rem auto; }
  .metro-btn { border-radius: 0; background: #1ba1e2; border-color: #1ba1e2; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #1790c9; border-color: #1790c9; color: #fff; }
  .metro-card .form-control { border-radius: 0; box-shadow: none; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<header class="metro-bar">System Tożsamości · Zmiana hasła</header>
<main class="metro-card">
  <h1 class="h5 fw-bold mb-2">Zmień hasło</h1>
  <p class="small text-body-secondary mb-3">Nowe hasło musi mieć co najmniej 8 znaków. Obowiązuje do panelu kursanta.</p>

  <?php if ($ok): ?>
  <div class="alert alert-success small py-2 rounded-0" role="status">Hasło zostało zmienione.</div>
  <?php endif; ?>
  <?php if ($blad !== ''): ?>
  <div class="alert alert-danger small py-2 rounded-0" role="alert"><?= h($blad) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <?php if (!$wymuszona): ?>
    <div class="mb-3">
      <label for="hs-cur" class="form-label fw-semibold">Aktualne hasło</label>
      <input type="password" class="form-control form-control-lg" id="hs-cur" name="current" required autocomplete="current-password">
    </div>
    <?php endif; ?>
    <div class="mb-3">
      <label for="hs-new" class="form-label fw-semibold">Nowe hasło</label>
      <input type="password" class="form-control form-control-lg" id="hs-new" name="new" required minlength="8" autocomplete="new-password">
    </div>
    <div class="mb-4">
      <label for="hs-cnf" class="form-label fw-semibold">Powtórz nowe hasło</label>
      <input type="password" class="form-control form-control-lg" id="hs-cnf" name="confirm" required minlength="8" autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-lg metro-btn w-100">Zapisz hasło</button>
  </form>
  <p class="small mt-3 mb-1"><a href="<?= h(APP_URL) ?>/tozsamosc/kursant_klucz.php">Klucze sprzętowe i passkey</a></p>
  <p class="small mt-2 mb-0"><a href="<?= h(APP_URL) ?>/karty30/ti/kursant/index.php">Wróć do panelu kursanta</a></p>
</main>
</body>
</html>
