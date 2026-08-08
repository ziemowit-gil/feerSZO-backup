<?php
/**
 * Panel kursanta TI — logowanie.
 * Całkowicie niezależne od systemu głównego i D3.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/pfron.php';
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
            student_login_user($account, 'password', "login: {$login}");
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

      <!-- ── Panel marki: mockup terminala (ukryty na telefonie) ───────────── -->
      <div class="col-md-5 kp-term-hero p-4 p-lg-5" aria-hidden="true">
        <div>
          <span class="d-inline-flex align-items-center justify-content-center kp-auth-logo mb-3">
            <i class="bi bi-pc-display fs-3"></i>
          </span>
          <h2 class="h4 fw-bold mb-1">Panel kursanta</h2>
          <p class="mb-0" style="color:rgba(255,255,255,.6)"><?= h($KP_ORG) ?></p>
        </div>
        <div class="kp-term-window mt-4">
          <div class="kp-term-bar">
            <span class="kp-term-dot kp-term-dot-r"></span><span class="kp-term-dot kp-term-dot-y"></span><span class="kp-term-dot kp-term-dot-g"></span>
            <span class="kp-term-title">kursant@feer:~</span>
          </div>
          <div class="kp-term-body">
            <div class="kp-term-line"><span class="kp-term-prompt">$</span>whoami</div>
            <div class="kp-term-line kp-term-dim">kursant</div>
            <div class="kp-term-line"><span class="kp-term-prompt">$</span>ls</div>
            <div class="kp-term-line kp-term-dim">lekcje  zadania  vlab  licencje  szkolenia</div>
            <div class="kp-term-line"><span class="kp-term-prompt">$</span><span class="kp-term-cursor"></span></div>
          </div>
        </div>
      </div>

      <!-- ── Formularz logowania ──────────────────────────────────────────── -->
      <div class="col-md-7">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4 d-md-none">
            <span class="d-inline-flex align-items-center justify-content-center kp-auth-logo mb-3">
              <i class="bi bi-pc-display fs-3"></i>
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

      <div class="kp-login-tiles">
        <?php if (defined('KURSANT_NEW_UI_URL')): ?>
        <a href="<?= h(rtrim(KURSANT_NEW_UI_URL, '/') . '/') ?>"
           class="btn btn-outline-primary w-100">
          <i class="bi bi-stars me-2" aria-hidden="true"></i>Wypróbuj nowy panel (Angular)
        </a>
        <?php endif; ?>
        <a href="parent.php" class="btn btn-outline-secondary w-100 kp-tile-1">
          <i class="bi bi-people me-2" aria-hidden="true"></i>Rodzic / opiekun lub osoba upoważniona
        </a>
        <?php if (k30_pfron_enabled()): ?>
        <a href="pfron.php" class="btn btn-outline-secondary w-100 kp-tile-6">
          <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Rozliczenia PFRON (Aktywny Samorząd)
        </a>
        <?php endif; ?>
        <a href="../dydaktyk/login.php" class="btn btn-outline-secondary w-100 kp-tile-2">
          <i class="bi bi-easel me-2" aria-hidden="true"></i>Panel dydaktyka (prowadzącego)
        </a>
      </div>

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
