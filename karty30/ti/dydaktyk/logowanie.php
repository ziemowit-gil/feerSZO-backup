<?php
/**
 * karty30/ti/dydaktyk/logowanie.php — alternatywna, samodzielna strona logowania
 * TYLKO do panelu dydaktyka (bez zakładek Kursant/Rodzic wspólnego ekranu).
 *
 * Do rozdawania prowadzącym — zwłaszcza kontom panelowym (dydaktyk_ti), które
 * logują się wyłącznie tutaj. POST idzie do istniejącego handlera login.php
 * (back=alt wraca z błędem na tę stronę); SSO Microsoft 365 przez office_enter.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/org_case.php';

karty30_migrate();

// Już zalogowany → panel
if (dyd_current()) { header('Location: index.php'); exit; }

static $ERR = [
    1 => 'Nieprawidłowy e-mail lub hasło.',
    4 => 'To konto nie ma uprawnień dydaktyka TI. Skontaktuj się z kierownikiem lub administratorem.',
    5 => 'Zbyt wiele nieudanych prób logowania. Odczekaj chwilę i spróbuj ponownie.',
];
$ec    = (int)($_GET['e'] ?? 0);
$error = $ERR[$ec] ?? ($ec > 0 ? 'Nie udało się zalogować. Spróbuj ponownie.' : null);
$prefill_email = h($_GET['m'] ?? '');

$office_login_url = rtrim(APP_URL, '/') . '/auth/ms365.php?redirect='
    . urlencode(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/office_enter.php');
$office_available = function_exists('ms_login_available') && ms_login_available();

$KP_TITLE      = org_login_title('Panel prowadzącego');
$KP_BODY_CLASS = 'ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';   // $KP_TOPBAR nieustawione = bez paska
?>
<style>
  body.ti-skin { background: #eef2f7; }
  .dlg-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
  .dlg-card { width: 100%; max-width: 420px; background: #fff; border: 1px solid #d7dde6;
              border-radius: 10px; padding: 2rem 1.75rem; box-shadow: 0 10px 30px rgba(15,40,80,.08); }
  .dlg-brand { font-size: .74rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
               color: #6b7280; margin-bottom: .35rem; }
  .dlg-card h1 { font-size: 1.15rem; margin-bottom: 1.25rem; }
  .dlg-sep { display: flex; align-items: center; gap: .75rem; color: #9ca3af; font-size: .78rem;
             margin: 1.1rem 0; text-transform: uppercase; letter-spacing: .06em; }
  .dlg-sep::before, .dlg-sep::after { content: ""; flex: 1; border-top: 1px solid #e5e7eb; }
  .dlg-foot { font-size: .78rem; color: #6b7280; margin-top: 1.25rem; text-align: center; }
</style>

<main id="main" class="dlg-wrap">
  <div class="dlg-card">
    <div class="dlg-brand"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?></div>
    <h1><?= h(org_login_title('Panel prowadzącego')) ?></h1>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span class="small"><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($office_available): ?>
    <a href="<?= h($office_login_url) ?>" class="btn btn-primary w-100">
      <i class="bi bi-microsoft me-2" aria-hidden="true"></i>Zaloguj przez Microsoft 365
    </a>
    <div class="dlg-sep">albo hasłem</div>
    <?php endif; ?>

    <form method="post" action="login.php" class="vstack gap-3">
      <input type="hidden" name="back" value="alt">
      <div>
        <label class="form-label small fw-semibold mb-1" for="dlg-email">E-mail</label>
        <input type="email" class="form-control" id="dlg-email" name="email" required
               autocomplete="username" value="<?= $prefill_email ?>" <?= $prefill_email === '' ? 'autofocus' : '' ?>>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1" for="dlg-pass">Hasło</label>
        <input type="password" class="form-control" id="dlg-pass" name="password" required
               autocomplete="current-password" <?= $prefill_email !== '' ? 'autofocus' : '' ?>>
      </div>
      <button type="submit" class="btn btn-<?= $office_available ? 'outline-primary' : 'primary' ?> w-100">
        <i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Zaloguj do panelu
      </button>
    </form>

    <div class="dlg-foot">
      Logowanie wyłącznie do panelu dydaktyka.
      Kursant lub rodzic? <a href="../login.php">Wspólna strona logowania</a>.
    </div>
  </div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
