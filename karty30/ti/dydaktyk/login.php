<?php
/**
 * Panel dydaktyka TI — logowanie.
 * Logowanie danymi SZO (e-mail + hasło). Osobna sesja, więc działa też na
 * subdomenie ti.* niezależnie od sesji głównej aplikacji.
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();

if (dyd_current()) { header('Location: index.php'); exit; }

$error = '';
if (($_GET['office'] ?? '') === 'denied') {
    $error = 'Zalogowano przez Microsoft 365, ale to konto nie ma uprawnień dydaktyka TI.';
}

// Start logowania Office — wspólny callback aplikacji, powrót do panelu dydaktyka.
$office_login_url = rtrim(APP_URL, '/') . '/auth/ms365.php?redirect='
    . urlencode(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php');
$office_available = function_exists('ms_login_available') && ms_login_available();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $data     = dyd_authenticate($email, $password);
    if ($data) {
        dyd_login_user($data);
        try { db()->prepare("UPDATE users SET last_login=datetime('now') WHERE id=?")->execute([$data['user_id']]); } catch (\Throwable $e) {}
        header('Location: index.php'); exit;
    }
    $error = 'Nieprawidłowy e-mail lub hasło, albo konto nie ma uprawnień dydaktyka.';
}

$KP_TITLE = 'Logowanie — Panel dydaktyka';
$KP_BODY_CLASS = 'd-flex align-items-center justify-content-center py-4 px-3';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<main id="main" class="kp-auth-wrap">
  <div class="card kp-auth-card shadow-lg border-0">
    <div class="row g-0">

      <div class="col-md-5 kp-auth-hero d-none d-md-flex flex-column justify-content-between p-4 p-lg-5" aria-hidden="true">
        <div>
          <span class="d-inline-flex align-items-center justify-content-center kp-auth-logo mb-4">
            <i class="bi bi-easel2 fs-2"></i>
          </span>
          <h2 class="h3 fw-bold mb-2">Panel dydaktyka</h2>
          <p class="mb-0 opacity-75"><?= h($KP_ORG) ?></p>
        </div>
        <ul class="list-unstyled d-flex flex-column gap-3 mt-5 mb-0 small">
          <li class="kp-auth-feat"><i class="bi bi-calendar-check"></i><span>Lekcje, frekwencja i terminy Twoich grup</span></li>
          <li class="kp-auth-feat"><i class="bi bi-journal-check"></i><span>Zadania domowe i materiały do lekcji</span></li>
          <li class="kp-auth-feat"><i class="bi bi-people"></i><span>Sprawdzanie obecności kursantów</span></li>
        </ul>
      </div>

      <div class="col-md-7">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4 d-md-none">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-2"
                  style="width:56px;height:56px;background:linear-gradient(135deg,#2563eb,#7c3aed)" aria-hidden="true">
              <i class="bi bi-easel2 fs-3 text-white"></i>
            </span>
            <h1 class="h4 fw-bold mb-1">Panel dydaktyka</h1>
            <p class="text-body-secondary small mb-0"><?= h($KP_ORG) ?> · Zajęcia TI</p>
          </div>
          <div class="d-none d-md-block mb-4">
            <h1 class="h4 fw-bold mb-1">Zaloguj się</h1>
            <?php if ($office_available): ?>
            <p class="text-body-secondary mb-0">Zalecamy logowanie przez <strong>Microsoft&nbsp;365</strong>.</p>
            <?php else: ?>
            <p class="text-body-secondary mb-0">Użyj swojego <strong>e-maila i hasła do SZO</strong>.</p>
            <?php endif; ?>
          </div>

          <?php if ($error): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i><span><?= h($error) ?></span>
          </div>
          <?php endif; ?>

          <?php
            // Office/M365 jest zalecaną metodą — formularz hasła jest domyślnie zwinięty
            // (delikatnie ukryty), żeby nie odciągać uwagi. Rozwijamy go od razu, gdy nie ma
            // logowania Office, albo gdy użytkownik już próbował logować się hasłem (błąd/POST) —
            // wtedy zwijanie byłoby mylące.
            $dyd_pwd_open = !$office_available || $error !== '' || $_SERVER['REQUEST_METHOD'] === 'POST';
          ?>
          <?php if ($office_available): ?>
          <a href="<?= h($office_login_url) ?>" class="btn btn-lg w-100 fw-semibold mb-2 d-flex align-items-center justify-content-center gap-2"
             style="background:#2f2f2f;color:#fff">
            <svg width="18" height="18" viewBox="0 0 23 23" aria-hidden="true"><path fill="#f25022" d="M1 1h10v10H1z"/><path fill="#7fba00" d="M12 1h10v10H12z"/><path fill="#00a4ef" d="M1 12h10v10H1z"/><path fill="#ffb900" d="M12 12h10v10H12z"/></svg>
            Zaloguj przez Microsoft 365
          </a>
          <p class="text-body-secondary text-center mb-3" style="font-size:.78rem">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zalecana metoda logowania
          </p>
          <button type="button" class="btn btn-link btn-sm text-body-secondary px-0 mb-2 text-decoration-none <?= $dyd_pwd_open ? 'd-none' : '' ?>"
                  id="dydPwToggle" data-bs-toggle="collapse" data-bs-target="#dydPwCollapse"
                  aria-expanded="<?= $dyd_pwd_open ? 'true' : 'false' ?>" aria-controls="dydPwCollapse">
            <i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Zaloguj hasłem (konta bez Microsoft 365)
          </button>
          <?php endif; ?>

          <div class="collapse<?= $dyd_pwd_open ? ' show' : '' ?>" id="dydPwCollapse">
          <?php if ($office_available): ?>
          <div class="d-flex align-items-center gap-2 my-3 text-body-secondary" aria-hidden="true">
            <hr class="flex-grow-1 m-0"><span class="small">logowanie hasłem</span><hr class="flex-grow-1 m-0">
          </div>
          <?php endif; ?>

          <form method="post" autocomplete="on">
            <div class="mb-3">
              <label class="form-label fw-semibold" for="email">Adres e-mail</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control form-control-lg" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>" required <?= $office_available ? '' : 'autofocus' ?> autocomplete="username" placeholder="np. imie.nazwisko@feer.org.pl">
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
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Logujesz się tymi samymi danymi, co do Systemu Zarządzania Organizacją.
          </p>
          </div>

          <hr class="my-4">

          <a href="../kursant/login.php" class="btn btn-outline-secondary w-100">
            <i class="bi bi-pc-display me-2" aria-hidden="true"></i>Jesteś kursantem? Przejdź do panelu kursanta
          </a>

          <p class="text-body-secondary mt-4 mb-0 text-center" style="font-size:.78rem">
            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Panel dydaktyka jest częścią systemu <strong>System Zarządzania Organizacją</strong>.
          </p>
        </div>
      </div>

    </div>
  </div>
</main>
<script>
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
<script>
// Rozwinięcie zwiniętego formularza hasła (Office jest metodą zalecaną) — po rozwinięciu
// chowa przycisk i przenosi fokus na e-mail (dostępność).
(function(){
  var collapseEl = document.getElementById('dydPwCollapse');
  var toggle     = document.getElementById('dydPwToggle');
  if (!collapseEl || !toggle) return;
  collapseEl.addEventListener('shown.bs.collapse', function(){
    toggle.classList.add('d-none');
    var email = document.getElementById('email');
    if (email) email.focus();
  });
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
