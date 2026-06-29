<?php
/**
 * Panel kursanta TI — logowanie.
 * Całkowicie niezależne od systemu głównego i K30.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Już zalogowany → redirect
if (student_current()) {
    header('Location: index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login']    ?? '');
    $password = $_POST['password'] ?? '';

    $account = db_one(
        "SELECT * FROM k30_ti_student_accounts
         WHERE (login=? OR (login_alias!='' AND login_alias=?)) AND is_active=1",
        [$login, $login]
    );

    $ip_log = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($account && password_verify($password, $account['password_hash'])) {
        if (!empty($account['child_access_blocked'])) {
            $error = 'Dostęp do panelu został wstrzymany przez opiekuna. Skontaktuj się z rodzicem/opiekunem.';
        } else {
            student_login_user($account);
            db()->prepare("UPDATE k30_ti_student_accounts SET last_login=datetime('now') WHERE id=?")
               ->execute([$account['id']]);
            if (function_exists('ti_account_log')) ti_account_log((int)$account['id'], 'login', "Zalogowano: {$login} | IP: {$ip_log}");
            header('Location: index.php'); exit;
        }
    } else {
        if ($account) {
            if (function_exists('ti_account_log')) ti_account_log((int)$account['id'], 'login_failed', "Nieudana próba dla: {$login} | IP: {$ip_log}");
        }
        $error = 'Nieprawidłowy login lub hasło.';
    }
}

$KP_TITLE = 'Logowanie — Panel kursanta';
$KP_BODY_CLASS = 'd-flex align-items-center justify-content-center py-4 px-3';
include __DIR__ . '/_layout_head.php';
?>
<main id="main" class="kp-auth-wrap">
  <div class="card kp-auth-card shadow-lg border-0">
    <div class="row g-0">

      <!-- ── Panel marki (dekoracyjny — ukryty na telefonie) ───────────────── -->
      <div class="col-md-5 kp-auth-hero d-none d-md-flex flex-column justify-content-between p-4 p-lg-5"
           aria-hidden="true">
        <div>
          <span class="d-inline-flex align-items-center justify-content-center kp-auth-logo mb-4">
            <i class="bi bi-pc-display fs-2"></i>
          </span>
          <h2 class="h3 fw-bold mb-2">Panel kursanta</h2>
          <p class="mb-0 opacity-75"><?= h($KP_ORG) ?><br></p>
        </div>
        <ul class="list-unstyled d-flex flex-column gap-3 mt-5 mb-0 small">
          <li class="kp-auth-feat"><i class="bi bi-calendar-check"></i><span>Twoje lekcje, frekwencja i terminy zajęć</span></li>
          <li class="kp-auth-feat"><i class="bi bi-journal-check"></i><span>Zadania do wykonania wraz z terminami</span></li>
          <li class="kp-auth-feat"><i class="bi bi-hdd-stack"></i><span>VLab, licencje i szkolenia online</span></li>
        </ul>
      </div>

      <!-- ── Formularz logowania ──────────────────────────────────────────── -->
      <div class="col-md-7">
        <div class="card-body p-4 p-lg-5">
          <!-- Nagłówek widoczny tylko na telefonie (panel marki ukryty) -->
          <div class="text-center mb-4 d-md-none">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-2"
                  style="width:56px;height:56px;background:linear-gradient(135deg,#2563eb,#7c3aed)" aria-hidden="true">
              <i class="bi bi-pc-display fs-3 text-white"></i>
            </span>
            <h1 class="h4 fw-bold mb-1">Panel kursanta</h1>
            <p class="text-body-secondary small mb-0"><?= h($KP_ORG) ?> · Zajęcia TI</p>
          </div>
          <div class="d-none d-md-block mb-4">
            <h1 class="h4 fw-bold mb-1">Zaloguj się</h1>
            <p class="text-body-secondary mb-0">Wpisz dane otrzymane od prowadzącego.</p>
          </div>

          <?php if ($error): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i>
            <span><?= h($error) ?></span>
          </div>
          <?php endif; ?>

          <form method="post" autocomplete="on">
            <div class="mb-3">
              <label class="form-label fw-semibold" for="login">Login</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
                <input type="text" class="form-control form-control-lg" id="login" name="login"
                       value="<?= h($_POST['login'] ?? '') ?>" required
                       autofocus autocomplete="username" placeholder="Login lub własny alias">
              </div>
            </div>
            <div class="mb-4">
              <label class="form-label fw-semibold" for="password">Hasło</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control form-control-lg" id="password" name="password"
                       required autocomplete="current-password" placeholder="••••••••">
                <button class="btn btn-outline-secondary" type="button" id="kp-pw-toggle"
                        aria-label="Pokaż lub ukryj hasło" aria-pressed="false" title="Pokaż / ukryj hasło">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
              <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
            </button>
          </form> 

          <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Nie masz konta? Skontaktuj się z prowadzącym.
          </p>

          <hr class="my-4">

          <a href="parent.php" class="btn btn-outline-secondary w-100">
            <i class="bi bi-people me-2" aria-hidden="true"></i>Logowanie dla rodzica / opiekuna
          </a>
          

          <a href="pfron.php" class="btn btn-outline-secondary w-100">
            <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Rozliczenia PFRON (Aktywny Samorząd) </a>

          <a href="../dydaktyk/login.php" class="btn btn-outline-secondary w-100 mt-2">
            <i class="bi bi-easel me-2" aria-hidden="true"></i>Panel dydaktyka (prowadzącego)
          </a>

          <p class="text-body-secondary mt-4 mb-0 text-center" style="font-size:.78rem">
            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Moduł „Kursant” jest częścią systemu <strong>System Zarządzania Organizacją</strong> i służy do obsługi szkoleń.
          </p>
        </div>
      </div>

    </div>
  </div>
</main>
<script>
// Pokaż/ukryj hasło — dostępne z klawiatury, aktualizuje aria-pressed (WCAG 4.1.2)
(function(){
  var btn = document.getElementById('kp-pw-toggle'), pw = document.getElementById('password');
  if (!btn || !pw) return;
  btn.addEventListener('click', function(){
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    pw.focus();
  });
})();
</script>
<?php include __DIR__ . '/_layout_foot.php'; ?>
