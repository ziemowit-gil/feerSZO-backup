<?php
/**
 * crm/login.php — CRM standalone login.
 *
 * Używa tej samej bazy użytkowników co system główny,
 * ale renderuje bez header.php i sidebara.
 * Po zalogowaniu → crm/dashboard.php (wybór modułu).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/branding.php';

auth_start();

// Już zalogowany → dashboard
if (current_user()) {
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}

$_b = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'System');

// URL powrotu po zalogowaniu
$redirect = APP_URL . '/crm/dashboard.php';

$error = '';

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_csrf = $_SESSION['csrf'] ?? '';
    $post_csrf    = $_POST['_csrf']   ?? '';
    $csrf_ok      = $session_csrf !== '' && hash_equals($session_csrf, $post_csrf);
    if (!$csrf_ok) {
        // Wygeneruj świeży token — kolejne przesłanie formularza zadziała
        unset($_SESSION['csrf']);
        csrf_token();
        $error = 'Token sesji wygasł. Spróbuj ponownie.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        $user = db_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
        if ($user && $user['password'] && password_verify($pass, $user['password'])) {
            // Sprawdź uprawnienia CRM
            if (!in_array($user['role'], ['admin', 'editor', 'viewer', 'crm_user'], true)) {
                $error = 'Twoje konto nie ma uprawnień do modułu CRM.';
            } else {
                login_user($user);
                crm_migrate(); // upewnij się że tabele istnieją
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            $error = 'Nieprawidłowy adres e-mail lub hasło.';
        }
    }
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logowanie CRM — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }

.login-split { display: flex; min-height: 100vh; }

/* ── Lewa — CRM green ────────────────────────────────────── */
.login-left {
  width: 360px; flex-shrink: 0;
  /* gradient z branding.php via CSS vars */
  display: flex; flex-direction: column; justify-content: space-between;
  padding: 3rem 2.5rem;
  position: relative; overflow: hidden;
}
.login-left::before {
  content: ''; position: absolute;
  width: 320px; height: 320px; border-radius: 50%;
  border: 55px solid rgba(255,255,255,.04);
  bottom: -80px; right: -90px; pointer-events: none;
}
.login-left::after {
  content: ''; position: absolute;
  width: 180px; height: 180px; border-radius: 50%;
  border: 35px solid rgba(255,255,255,.05);
  top: -50px; left: -55px; pointer-events: none;
}
.left-content { position: relative; }
.brand-icon-wrap {
  width: 52px; height: 52px; border-radius: 13px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; color: #fff; margin-bottom: 1.25rem;
}
.brand-label  { font-size: .66rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.4); margin-bottom: .4rem; }
.brand-name   { font-size: 1.05rem; font-weight: 700; color: rgba(255,255,255,.6); line-height: 1.3; margin-bottom: 2rem; }
.brand-name span { color: #91DB8B; }

.crm-info-box {
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.13);
  border-radius: 12px; padding: 1.1rem 1.25rem;
}
.crm-info-box-label {
  font-size: .68rem; font-weight: 600; letter-spacing: .07em;
  text-transform: uppercase; color: rgba(255,255,255,.4); margin-bottom: .55rem;
}
.crm-info-box ul {
  margin: 0; padding: 0; list-style: none;
}
.crm-info-box ul li {
  font-size: .79rem; color: rgba(255,255,255,.65);
  line-height: 1.6; display: flex; align-items: center; gap: .5rem;
}
.crm-info-box ul li::before { content: '✓'; color: #91DB8B; font-weight: 700; }

.org-box {
  background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.09);
  border-radius: 10px; padding: .8rem 1.1rem; margin-top: 1rem;
}
.org-box-label { font-size: .65rem; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.35); margin-bottom: .2rem; }
.org-box-name  { font-size: .9rem; font-weight: 700; color: rgba(255,255,255,.8); }

.left-footer { position: relative; color: rgba(255,255,255,.28); font-size: .72rem; }
.left-footer a { color: rgba(255,255,255,.45); text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; }
.left-footer a:hover { color: rgba(255,255,255,.75); }

/* ── Prawa ───────────────────────────────────────────────── */
.login-right {
  flex: 1; background: #f3f3f3;
  display: flex; align-items: center; justify-content: center;
  padding: 2.5rem 2rem; overflow-y: auto;
}
.login-box { width: 100%; max-width: 380px; }

.org-header-mobile { display: none; margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; }
.org-header-mobile .sys-label { font-size: .65rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; margin-bottom: .2rem; }
.org-header-mobile .org-name-big { font-size: 1.05rem; font-weight: 700; color: #1e293b; }

.form-heading { font-size: 1.3rem; font-weight: 700; color: #0f172a; margin-bottom: .2rem; }
.form-sub     { font-size: .84rem; color: #64748b; margin-bottom: 1.5rem; }

.form-label   { font-size: .81rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
.form-control { border-color: #d1d5db; border-radius: .5rem; font-size: .94rem; padding: .6rem .85rem; transition: border-color .15s, box-shadow .15s; }
.form-control:focus { border-color: var(--c); box-shadow: 0 0 0 3px var(--c-ring); }

.btn-crm-login {
  background: var(--c); color: var(--c-text); border: none; border-radius: .5rem;
  padding: .72rem 1.25rem; font-size: .94rem; font-weight: 600; width: 100%;
  transition: background .15s, box-shadow .15s;
}
.btn-crm-login:hover { background: var(--c-dark); box-shadow: 0 2px 8px var(--c-ring); }

.pass-wrap { position: relative; }
.pass-toggle { position: absolute; right: .75rem; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 0; color: #9ca3af; cursor: pointer; font-size: 1rem; }
.pass-toggle:hover { color: #374151; }

.system-login-link {
  text-align: center; margin-top: 1.25rem; font-size: .8rem;
}
.system-login-link a { color: #64748b; text-decoration: none; }
.system-login-link a:hover { color: var(--c); }

@media (max-width: 680px) {
  .login-split { flex-direction: column; }
  .login-left  { width: 100%; padding: 1.1rem 1.25rem; flex-direction: row; align-items: center; gap: .75rem; min-height: auto; }
  .login-left::before, .login-left::after { display: none; }
  .left-content { display: flex; align-items: center; gap: .75rem; flex: 1; }
  .brand-icon-wrap { width: 36px; height: 36px; border-radius: 9px; font-size: 1.1rem; margin-bottom: 0; }
  .brand-label, .brand-name, .crm-info-box, .org-box, .left-footer { display: none; }
  .org-header-mobile { display: block; }
  .login-right { padding: 1.5rem 1.2rem; align-items: flex-start; }
}
</style>
</head>
<body>
<div class="login-split">

  <!-- Lewa — CRM panel -->
  <div class="login-left">
    <div class="left-content">
      <?php if ($_b['logo_url']): ?>
      <div class="brand-icon-wrap" aria-hidden="true" style="background:rgba(255,255,255,.15);width:auto;max-width:160px;height:auto;min-height:52px;border-radius:12px;padding:.4rem;display:flex;align-items:center;margin-bottom:1.25rem">
        <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" style="max-height:44px;max-width:148px;object-fit:contain;filter:brightness(0) invert(1)">
      </div>
      <?php else: ?>
      <div class="brand-icon-wrap" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
      <?php endif; ?>
      <div class="brand-label">Platforma NGO</div>
      <div class="brand-name">System <span>CRM</span><br>Zarządzania Kontaktami</div>

      <div class="crm-info-box">
        <div class="crm-info-box-label"><i class="bi bi-people-fill me-1"></i>Funkcje modułu</div>
        <ul>
          <li>Kartoteki osób i firm</li>
          <li>Powiązania i relacje</li>
          <li>Historia komunikacji SMS/email</li>
          <li>Szablony i synchronizacja</li>
        </ul>
      </div>

      <div class="org-box">
        <div class="org-box-label">Organizacja</div>
        <div class="org-box-name"><?= h($org_name) ?></div>
      </div>
    </div>

    <div class="left-footer">
      <?php if (!defined('CRM_STANDALONE') || !CRM_STANDALONE): ?>
      <a href="<?= APP_URL ?>/auth/login.php">
        <i class="bi bi-arrow-left-circle"></i> Logowanie systemowe
      </a>
      <?php endif; ?>
      <div style="margin-top:.4rem">&copy; <?= date('Y') ?> · Rejestr Umów NGO</div>
    </div>
  </div>

  <!-- Prawa — formularz -->
  <div class="login-right">
  <div class="login-box">

    <div class="org-header-mobile">
      <div class="sys-label">CRM · Platforma NGO</div>
      <div class="org-name-big"><?= h($org_name) ?></div>
    </div>

    <div class="form-heading">Logowanie do CRM</div>
    <div class="form-sub">Wprowadź dane konta, aby zarządzać kontaktami.</div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3"
         style="border-radius:.5rem;font-size:.84rem" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="on" novalidate>
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label" for="email">Adres e-mail</label>
        <input type="email" name="email" id="email"
               class="form-control"
               value="<?= h($_POST['email'] ?? '') ?>"
               placeholder="nazwa@domena.pl"
               autocomplete="email"
               autofocus required>
      </div>

      <div class="mb-4">
        <label class="form-label" for="password">Hasło</label>
        <div class="pass-wrap">
          <input type="password" name="password" id="password"
                 class="form-control"
                 autocomplete="current-password" required>
          <button type="button" class="pass-toggle" tabindex="-1"
                  onclick="var i=document.getElementById('password');
                           i.type=i.type==='password'?'text':'password';
                           this.querySelector('i').className=i.type==='password'?'bi bi-eye':'bi bi-eye-slash';"
                  aria-label="Pokaż/ukryj hasło">
            <i class="bi bi-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-crm-login">
        <i class="bi bi-diagram-2-fill me-1"></i>Zaloguj do CRM
      </button>
    </form>

    <?php if (!defined('CRM_STANDALONE') || !CRM_STANDALONE): ?>
    <div class="system-login-link">
      <a href="<?= APP_URL ?>/auth/login.php">
        <i class="bi bi-arrow-left me-1"></i>Wróć do logowania systemowego
      </a>
    </div>
    <?php endif; ?>

  </div>
  </div>

</div><!-- /login-split -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
