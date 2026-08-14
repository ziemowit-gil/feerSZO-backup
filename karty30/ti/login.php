<?php
/**
 * karty30/ti/login.php — Centralny punkt logowania TI.
 *
 * Jeden formularz z zakładkami Kursant / Prowadzący.
 * POST trafia do sub-handlerów (PRG): kursant/login.php lub dydaktyk/login.php.
 * Błędy wracają kodem e=N w query string, unikając sesji po stronie tego pliku.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';

karty30_migrate();

// ── Komunikaty błędów ─────────────────────────────────────────────────────────
static $ERR = [
    1 => 'Nieprawidłowy login lub hasło.',
    2 => 'Dostęp do panelu wstrzymany przez opiekuna. Skontaktuj się z rodzicem lub opiekunem.',
    3 => 'Konto jest nieaktywne. Skontaktuj się z prowadzącym.',
    4 => 'To konto nie ma uprawnień dydaktyka TI. Skontaktuj się z administratorem.',
    5 => 'Zbyt wiele nieudanych prób logowania. Odczekaj chwilę i spróbuj ponownie.',
];
$ec    = (int)($_GET['e'] ?? 0);
$error = $ERR[$ec] ?? null;

// ── Aktywna zakładka ──────────────────────────────────────────────────────────
$tab = ($_GET['tab'] ?? 'kursant') === 'dydaktyk' ? 'dydaktyk' : 'kursant';

// ── Prefill pól po błędzie (z urlencode() w handlerach) ──────────────────────
$prefill_login = h($_GET['l'] ?? '');
$prefill_email = h($_GET['m'] ?? '');

// ── SSO dla dydaktyka ─────────────────────────────────────────────────────────
$office_login_url = rtrim(APP_URL, '/') . '/auth/ms365.php?redirect='
    . urlencode(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php');
$office_available = function_exists('ms_login_available') && ms_login_available();
$dyd_pwd_open     = !$office_available || $ec > 0;

$KP_TITLE      = 'Logowanie — Panel Kursanta';
$KP_BODY_CLASS = 'd-flex align-items-center justify-content-center py-4 px-3 kp-login-bg-page';
include __DIR__ . '/kursant/_layout_head.php';
?>
<style>
/* ── Tło: zdjęcie + ciemna nakładka ─────────────────────────────────── */
body.kp-login-bg-page {
  background: #060c1a !important;
  min-height: 100vh;
  position: relative;
}
body.kp-login-bg-page::before {
  content: '';
  position: fixed;
  inset: 0;
  background: url('assets/login-bg.jpg') center / cover no-repeat;
  filter: brightness(.55) saturate(1.3);
  z-index: 0;
  pointer-events: none;
}
/* ── Karta logowania — glassmorphism ─────────────────────────────────── */
body.kp-login-bg-page .kp-auth-wrap {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 480px;
}
body.kp-login-bg-page .kp-auth-card {
  background: rgba(8, 14, 28, .82) !important;
  backdrop-filter: blur(28px) saturate(1.4);
  -webkit-backdrop-filter: blur(28px) saturate(1.4);
  border: 1px solid rgba(255,255,255,.13) !important;
  border-radius: 1.5rem !important;
  box-shadow: 0 30px 80px rgba(0,0,0,.65), 0 0 0 .5px rgba(255,255,255,.06) !important;
  overflow: hidden;
  animation: kpAuthIn .45s cubic-bezier(.16,.84,.44,1) both;
  color: #fff;
}
/* ── Logo ────────────────────────────────────────────────────────────── */
body.kp-login-bg-page .kp-auth-logo {
  width: 72px; height: 72px; border-radius: 1.2rem;
  background: linear-gradient(150deg,#1e3a8a 0%,#2563eb 45%,#7c3aed 100%);
  color: #fff;
  box-shadow: 0 12px 30px rgba(37,99,235,.45);
  animation: kpLogoFloat 5s ease-in-out infinite;
}
/* ── Zakładki ─────────────────────────────────────────────────────────── */
body.kp-login-bg-page .nav-tabs {
  border-bottom-color: rgba(255,255,255,.15);
}
body.kp-login-bg-page .nav-tabs .nav-link {
  color: rgba(255,255,255,.55);
  border-color: transparent;
  transition: color .15s;
}
body.kp-login-bg-page .nav-tabs .nav-link:hover { color: #fff; }
body.kp-login-bg-page .nav-tabs .nav-link.active {
  background: transparent;
  border-color: transparent transparent #2563eb;
  color: #fff;
  font-weight: 700;
}
/* ── Pola formularza ─────────────────────────────────────────────────── */
body.kp-login-bg-page .form-control,
body.kp-login-bg-page .input-group-text {
  background: rgba(255,255,255,.07);
  border-color: rgba(255,255,255,.15);
  color: #fff;
  border-radius: .65rem !important;
}
body.kp-login-bg-page .input-group > .form-control:not(:last-child) { border-radius: .65rem 0 0 .65rem !important; }
body.kp-login-bg-page .input-group > .btn:last-child { border-radius: 0 .65rem .65rem 0 !important; }
body.kp-login-bg-page .form-control::placeholder { color: rgba(255,255,255,.35); }
body.kp-login-bg-page .form-control:focus {
  background: rgba(255,255,255,.11);
  border-color: #2563eb;
  color: #fff;
  box-shadow: 0 0 0 3px rgba(37,99,235,.3);
}
body.kp-login-bg-page .input-group:focus-within .input-group-text { border-color: #2563eb; color: #90b4ff; }
body.kp-login-bg-page .btn-outline-secondary {
  color: rgba(255,255,255,.65);
  border-color: rgba(255,255,255,.2);
}
body.kp-login-bg-page .btn-outline-secondary:hover {
  background: rgba(255,255,255,.1); color: #fff; border-color: rgba(255,255,255,.35);
}
body.kp-login-bg-page label.form-label { color: #d1d5db; }
body.kp-login-bg-page .form-text { color: rgba(255,255,255,.45); }
body.kp-login-bg-page .text-body-secondary { color: rgba(255,255,255,.55) !important; }
body.kp-login-bg-page small { color: rgba(255,255,255,.45) !important; }
body.kp-login-bg-page hr { border-color: rgba(255,255,255,.15); }
/* ── Alert błędu ─────────────────────────────────────────────────────── */
body.kp-login-bg-page .alert-danger {
  background: rgba(239,68,68,.2);
  border-color: rgba(239,68,68,.35);
  color: #fca5a5;
}
/* ── Przycisk Zaloguj ─────────────────────────────────────────────────── */
body.kp-login-bg-page .btn-primary {
  background: linear-gradient(135deg,#2563eb,#1d4ed8);
  border: none;
  box-shadow: 0 8px 22px rgba(37,99,235,.42);
  transition: filter .15s, box-shadow .15s, transform .12s;
}
body.kp-login-bg-page .btn-primary:hover {
  filter: brightness(1.1);
  box-shadow: 0 12px 28px rgba(37,99,235,.55);
  transform: translateY(-1px);
}
body.kp-login-bg-page .btn-primary:active { transform: translateY(0); }
/* ── Stopka ──────────────────────────────────────────────────────────── */
body.kp-login-bg-page .kp-auth-footer {
  color: rgba(255,255,255,.4);
  font-size: .75rem;
  text-align: center;
}
@media (prefers-reduced-motion: reduce) {
  body.kp-login-bg-page .kp-auth-card,
  body.kp-login-bg-page .kp-auth-logo { animation: none; }
  body.kp-login-bg-page .btn-primary { transition: none; }
}
</style>

<main id="main" class="kp-auth-wrap">
  <div class="card kp-auth-card shadow-lg border-0">
    <div class="card-body p-4 p-lg-5">

      <!-- Logo + tytuł (single-column, centered) -->
      <div class="text-center mb-4">
        <span class="d-inline-flex align-items-center justify-content-center kp-auth-logo mb-3">
          <i class="bi bi-pc-display fs-3" id="hero-icon"></i>
        </span>
        <h1 class="h5 fw-bold mb-1 text-white" id="hero-title">Panel kursanta</h1>
        <p class="mb-0" style="color:rgba(255,255,255,.55);font-size:.87rem"><?= h($KP_ORG) ?> · Zajęcia TI</p>
      </div>

          <!-- Błąd — role="alert" + aria-live powoduje ogłoszenie przez czytnik ekranu -->
          <?php if ($error): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 py-2"
               role="alert" aria-live="assertive" aria-atomic="true">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i>
            <span><?= h($error) ?></span>
          </div>
          <?php endif; ?>

          <!-- Zakładki Kursant / Prowadzący -->
          <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Rodzaj użytkownika">
            <li class="nav-item" role="presentation">
              <button class="nav-link<?= $tab === 'kursant' ? ' active' : '' ?>"
                      id="tab-kursant-btn"
                      data-bs-toggle="tab" data-bs-target="#tab-kursant"
                      type="button" role="tab"
                      aria-controls="tab-kursant"
                      aria-selected="<?= $tab === 'kursant' ? 'true' : 'false' ?>">
                <i class="bi bi-pc-display me-1" aria-hidden="true"></i>Kursant
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link<?= $tab === 'dydaktyk' ? ' active' : '' ?>"
                      id="tab-dydaktyk-btn"
                      data-bs-toggle="tab" data-bs-target="#tab-dydaktyk"
                      type="button" role="tab"
                      aria-controls="tab-dydaktyk"
                      aria-selected="<?= $tab === 'dydaktyk' ? 'true' : 'false' ?>">
                <i class="bi bi-easel2 me-1" aria-hidden="true"></i>Prowadzący
              </button>
            </li>
          </ul>

          <div class="tab-content">

            <!-- ═══ ZAKŁADKA: KURSANT ══════════════════════════════════════ -->
            <div class="tab-pane fade<?= $tab === 'kursant' ? ' show active' : '' ?>"
                 id="tab-kursant"
                 role="tabpanel"
                 aria-labelledby="tab-kursant-btn">

              <form method="post" action="kursant/login.php" autocomplete="on">
                <div class="mb-3">
                  <label class="form-label fw-semibold" for="stu-login">Login</label>
                  <div class="input-group input-group-lg">
                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
                    <input type="text"
                           class="form-control form-control-lg<?= ($ec && $tab === 'kursant') ? ' is-invalid' : '' ?>"
                           id="stu-login" name="login"
                           value="<?= $prefill_login ?>"
                           required
                           <?= $tab === 'kursant' ? 'autofocus' : '' ?>
                           autocomplete="username"
                           placeholder="Login lub własny alias"
                           aria-describedby="stu-login-hint"
                           aria-invalid="<?= ($ec && $tab === 'kursant') ? 'true' : 'false' ?>">
                  </div>
                  <div id="stu-login-hint" class="form-text">
                    Login otrzymujesz od prowadzącego.
                  </div>
                </div>

                <div class="mb-4">
                  <label class="form-label fw-semibold" for="stu-password">Hasło</label>
                  <div class="input-group input-group-lg">
                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                    <input type="password"
                           class="form-control form-control-lg"
                           id="stu-password" name="password"
                           required
                           autocomplete="current-password"
                           placeholder="••••••••"
                           aria-invalid="<?= ($ec && $tab === 'kursant') ? 'true' : 'false' ?>">
                    <button class="btn btn-outline-secondary" type="button"
                            data-kp-pw-toggle="stu-password"
                            aria-label="Pokaż lub ukryj hasło" aria-pressed="false">
                      <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                  </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                  <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
                </button>
              </form>

              <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Nie masz konta? Skontaktuj się z prowadzącym.
              </p>

              <hr class="my-3">

              <div class="kp-login-tiles">
                <a href="kursant/parent.php" class="btn btn-outline-secondary w-100 kp-tile-1">
                  <i class="bi bi-people me-2" aria-hidden="true"></i>Rodzic / opiekun lub osoba upoważniona
                </a>
                <?php if (k30_pfron_enabled()): ?>
                <a href="kursant/pfron.php" class="btn btn-outline-secondary w-100 kp-tile-6">
                  <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Rozliczenia PFRON (Aktywny Samorząd)
                </a>
                <?php endif; ?>
              </div>

            </div><!-- /#tab-kursant -->

            <!-- ═══ ZAKŁADKA: PROWADZĄCY ══════════════════════════════════ -->
            <div class="tab-pane fade<?= $tab === 'dydaktyk' ? ' show active' : '' ?>"
                 id="tab-dydaktyk"
                 role="tabpanel"
                 aria-labelledby="tab-dydaktyk-btn">

              <?php if ($office_available): ?>
              <a href="<?= h($office_login_url) ?>"
                 class="btn btn-lg w-100 fw-semibold mb-2 d-flex align-items-center justify-content-center gap-2"
                 style="background:#2f2f2f;color:#fff">
                <svg width="18" height="18" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
                  <path fill="#f25022" d="M1 1h10v10H1z"/>
                  <path fill="#7fba00" d="M12 1h10v10H12z"/>
                  <path fill="#00a4ef" d="M1 12h10v10H1z"/>
                  <path fill="#ffb900" d="M12 12h10v10H12z"/>
                </svg>
                Zaloguj przez Microsoft 365
              </a>
              <p class="text-body-secondary text-center mb-3" style="font-size:.78rem">
                <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zalecana metoda logowania
              </p>
              <button type="button"
                      class="btn btn-link btn-sm text-body-secondary px-0 mb-2 text-decoration-none<?= $dyd_pwd_open ? ' d-none' : '' ?>"
                      id="dydPwToggle"
                      data-bs-toggle="collapse" data-bs-target="#dydPwCollapse"
                      aria-expanded="<?= $dyd_pwd_open ? 'true' : 'false' ?>"
                      aria-controls="dydPwCollapse">
                <i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Zaloguj hasłem (konta bez Microsoft 365)
              </button>
              <?php endif; ?>

              <div class="collapse<?= $dyd_pwd_open ? ' show' : '' ?>" id="dydPwCollapse">
                <?php if ($office_available): ?>
                <div class="d-flex align-items-center gap-2 my-3 text-body-secondary" aria-hidden="true">
                  <hr class="flex-grow-1 m-0">
                  <span class="small">logowanie hasłem</span>
                  <hr class="flex-grow-1 m-0">
                </div>
                <?php endif; ?>

                <form method="post" action="dydaktyk/login.php" autocomplete="on">
                  <div class="mb-3">
                    <label class="form-label fw-semibold" for="dyd-email">Adres e-mail</label>
                    <div class="input-group input-group-lg">
                      <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                      <input type="email"
                             class="form-control form-control-lg<?= ($ec && $tab === 'dydaktyk') ? ' is-invalid' : '' ?>"
                             id="dyd-email" name="email"
                             value="<?= $prefill_email ?>"
                             required
                             <?= ($tab === 'dydaktyk' && !$office_available) ? 'autofocus' : '' ?>
                             autocomplete="username"
                             placeholder="imie.nazwisko@feer.org.pl"
                             aria-invalid="<?= ($ec && $tab === 'dydaktyk') ? 'true' : 'false' ?>">
                    </div>
                  </div>

                  <div class="mb-4">
                    <label class="form-label fw-semibold" for="dyd-password">Hasło</label>
                    <div class="input-group input-group-lg">
                      <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                      <input type="password"
                             class="form-control form-control-lg"
                             id="dyd-password" name="password"
                             required
                             autocomplete="current-password"
                             placeholder="••••••••"
                             aria-invalid="<?= ($ec && $tab === 'dydaktyk') ? 'true' : 'false' ?>">
                      <button class="btn btn-outline-secondary" type="button"
                              data-kp-pw-toggle="dyd-password"
                              aria-label="Pokaż lub ukryj hasło" aria-pressed="false">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                      </button>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj się
                  </button>
                </form>

                <p class="text-body-secondary mt-3 mb-0" style="font-size:.82rem">
                  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                  Użyj danych logowania do Systemu Zarządzania Organizacją.
                </p>
              </div><!-- /#dydPwCollapse -->

            </div><!-- /#tab-dydaktyk -->

          </div><!-- /.tab-content -->

      <p class="kp-auth-footer mt-4 mb-0">
        <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>
        Panel Kursanta — Zajęcia TI · <?= h($KP_ORG) ?>
      </p>

    </div><!-- /.card-body -->
  </div><!-- /.kp-auth-card -->
</main>

<script>
// ── Ikona + tytuł w logo przy zmianie zakładki ────────────────────────────────
(function(){
  var CFG = {
    kursant:  { icon:'bi-pc-display', title:'Panel kursanta' },
    dydaktyk: { icon:'bi-easel2',     title:'Panel prowadzącego' },
  };
  function applyHero(tab) {
    var c = CFG[tab] || CFG.kursant;
    var ic = document.getElementById('hero-icon');
    var tl = document.getElementById('hero-title');
    if (ic) ic.className = 'bi ' + c.icon + ' fs-3';
    if (tl) tl.textContent = c.title;
  }
  applyHero('<?= $tab ?>');
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(btn){
    btn.addEventListener('shown.bs.tab', function(){
      applyHero((btn.getAttribute('data-bs-target') || '').replace('#tab-',''));
    });
  });
})();

// ── Pokaż/ukryj hasło — wspólny handler dla obu formularzy ───────────────────
document.addEventListener('click', function(e){
  var btn = e.target.closest('[data-kp-pw-toggle]');
  if (!btn) return;
  var pw = document.getElementById(btn.getAttribute('data-kp-pw-toggle'));
  if (!pw) return;
  var show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  var ic = btn.querySelector('i');
  if (ic) ic.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  pw.focus();
});

// ── Rozwinięcie formularza hasła dydaktyka + przesuń fokus na e-mail ─────────
(function(){
  var collapse = document.getElementById('dydPwCollapse');
  var toggle   = document.getElementById('dydPwToggle');
  if (!collapse || !toggle) return;
  collapse.addEventListener('shown.bs.collapse', function(){
    toggle.classList.add('d-none');
    var em = document.getElementById('dyd-email');
    if (em) em.focus();
  });
})();
</script>

<?php include __DIR__ . '/kursant/_layout_foot.php'; ?>
