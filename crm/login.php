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

// Microsoft 365 login — dostępny jeśli skonfigurowany
$ms_crm_available = ms_login_available();
$ms_crm_url       = $ms_crm_available
    ? ms_auth_url(APP_URL . '/crm/dashboard.php')
    : '';

// Dodatkowe moduły dla CRM-only (do wyświetlenia)
$crm_modules_raw = '';
try {
    $r = db_one("SELECT value FROM settings WHERE key_='crm_extra_modules'");
    $crm_modules_raw = $r['value'] ?? '';
} catch (\Throwable $e) {}
$crm_module_labels = [
    'actions'   => 'Działania',
    'grants'    => 'Granty',
    'persons'   => 'Osoby',
    'reports'   => 'Raporty',
    'directory' => 'Katalog osób',
];
$crm_extra = array_filter(array_map('trim', explode(',', $crm_modules_raw)));
$crm_scope_label = 'CRM' . ($crm_extra
    ? ' + ' . implode(', ', array_map(fn($m) => $crm_module_labels[$m] ?? $m, $crm_extra))
    : '');

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
            $allowed_roles = ['admin', 'editor', 'viewer', 'crm_user'];
            $role_ok = in_array($user['role'], $allowed_roles, true);
            // Sprawdź też role z flagą crm_only (własne role)
            if (!$role_ok) {
                try {
                    $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$user['role']]);
                    $role_ok = !empty($r['crm_only']);
                } catch (\Throwable $e) {}
            }
            if (!$role_ok) {
                $error = 'Twoje konto nie ma uprawnień do modułu CRM.';
            } else {
                // Sprawdź czy crm_only i czy ma kod IKA
                $is_crm_only_user = ($user['role'] === 'crm_user');
                if (!$is_crm_only_user) {
                    try {
                        $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$user['role']]);
                        $is_crm_only_user = !empty($r['crm_only']);
                    } catch (\Throwable $e) {}
                }

                // Nadpisanie per-user: crm_ika_required=0 zwalnia z IKA nawet dla crm_only
                $crm_ika_flag = isset($user['crm_ika_required']) && $user['crm_ika_required'] !== null
                    ? (int)$user['crm_ika_required'] : null;
                $ika_exempt   = ($crm_ika_flag === 0);
                $ika_forced   = ($crm_ika_flag === 1);

                // Wymuś IKA dla crm_only — musi mieć ustawiony kod (chyba że admin zwolnił)
                if (!$ika_exempt && ($is_crm_only_user || $ika_forced) && empty($user['cpc_code'])) {
                    $error = 'Twoje konto wymaga aktywacji kodu IKA przed pierwszym logowaniem. Skontaktuj się z administratorem systemu.';
                } else {
                    login_user($user);
                    crm_migrate();
                    // Przekieruj przez IKA jeśli kod ustawiony i nie jest zwolniony
                    if (!$ika_exempt && !empty($user['cpc_code'])) {
                        header('Location: ' . APP_URL . '/contracts/ika_gate.php?to=' . urlencode($redirect));
                    } else {
                        header('Location: ' . $redirect);
                    }
                    exit;
                }
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
.form-sub     { font-size: .84rem; color: #64748b; margin-bottom: 1.25rem; }

/* ── Przewodnik: dwie ścieżki logowania ──────────────────── */
.login-paths { border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 1.4rem; overflow: hidden; background: #fff; }
.login-path  { display: flex; align-items: flex-start; gap: .7rem; padding: .8rem .95rem; }
.login-path + .login-path { border-top: 1px solid #eef2f7; }
.login-path-badge { width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: .95rem; background: var(--c-ring, #eef7ee); color: var(--c, #2f7d32); }
.login-path-badge.alt { background: #f1f5f9; color: #475569; }
.login-path-title { font-size: .84rem; font-weight: 700; color: #0f172a; line-height: 1.3; }
.login-path-desc  { font-size: .77rem; color: #64748b; line-height: 1.55; margin-top: .1rem; }
.login-path-desc strong { color: #334155; font-weight: 700; }

.form-label   { font-size: .81rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
.form-control { border-color: #d1d5db; border-radius: .5rem; font-size: .94rem; padding: .6rem .85rem; transition: border-color .15s, box-shadow .15s; }
.form-control:focus { border-color: var(--c); box-shadow: 0 0 0 3px var(--c-ring); }

.btn-crm-login {
  background: var(--c); color: var(--c-text); border: none; border-radius: .5rem;
  padding: .72rem 1.25rem; font-size: .94rem; font-weight: 600; width: 100%;
  transition: background .15s, box-shadow .15s;
}
.btn-crm-login:hover { background: var(--c-dark); box-shadow: 0 2px 8px var(--c-ring); }

.btn-ms-login {
  display: flex; align-items: center; justify-content: center; gap: .65rem;
  width: 100%; padding: .68rem 1.25rem; border-radius: .5rem;
  background: #fff; color: #3c4043; font-size: .9rem; font-weight: 600;
  border: 1.5px solid #dadce0; text-decoration: none;
  transition: background .15s, box-shadow .15s;
}
.btn-ms-login:hover { background: #f8f9fa; box-shadow: 0 1px 6px rgba(60,64,67,.2); color: #3c4043; }
.btn-ms-login:focus { outline: 3px solid #2563eb; outline-offset: 2px; }

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
        <div class="crm-info-box-label"><i class="bi bi-grid-fill me-1"></i>Twój dostęp</div>
        <ul>
          <li><i class="bi bi-diagram-2-fill me-1"></i>CRM — kontakty, grupy, komunikacja</li>
          <?php foreach ($crm_extra as $m): ?>
          <li><i class="bi bi-check-circle me-1"></i><?= h($crm_module_labels[$m] ?? $m) ?></li>
          <?php endforeach; ?>
          <?php if (!$crm_extra): ?>
          <li><i class="bi bi-info-circle me-1" style="opacity:.6"></i><span style="opacity:.75">tylko moduł CRM</span></li>
          <?php endif; ?>
        </ul>
        <?php if ($crm_extra): ?>
        <div style="font-size:.72rem;margin-top:.5rem;opacity:.7">
          Skonfigurowane przez administratora systemu
        </div>
        <?php endif; ?>
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
    <div class="form-sub">Wybierz sposób logowania do panelu CRM.</div>

    <!-- Przewodnik: która ścieżka logowania dla kogo -->
    <div class="login-paths" role="note" aria-label="Jak się zalogować">
      <div class="login-path">
        <span class="login-path-badge" aria-hidden="true"><i class="bi <?= $ms_crm_available ? 'bi-microsoft' : 'bi-envelope-at-fill' ?>"></i></span>
        <div>
          <div class="login-path-title">Masz konto @feer.org.pl</div>
          <div class="login-path-desc">
            <?php if ($ms_crm_available): ?>
            Zaloguj się przez <strong>Microsoft 365</strong> lub e-mailem służbowym i hasłem. Administracja zawsze kontem <strong>@feer.org.pl</strong>.
            <?php else: ?>
            Zaloguj się e-mailem służbowym <strong>@feer.org.pl</strong> i hasłem.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="login-path">
        <span class="login-path-badge alt" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
        <div>
          <div class="login-path-title">Nie masz konta @feer.org.pl</div>
          <div class="login-path-desc">
            Zaloguj się lokalnie swoim <strong>prywatnym e-mailem</strong> — tym podanym do WiadomościFEER — i ustawionym hasłem.
          </div>
        </div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3"
         style="border-radius:.5rem;font-size:.84rem" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($ms_crm_available): ?>
    <!-- ── Microsoft 365 — główna metoda ── -->
    <a href="<?= h($ms_crm_url) ?>"
       class="btn-ms-login"
       aria-label="Zaloguj się kontem Microsoft 365 swojej organizacji">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" style="flex-shrink:0">
        <path fill="#f3f3f3" d="M0 0h23v23H0z"/>
        <path fill="#f35325" d="M1 1h10v10H1z"/>
        <path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H1z"/>
        <path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      <span>Zaloguj przez Microsoft 365</span>
    </a>

    <!-- Separator — fallback dla adminów -->
    <div style="display:flex;align-items:center;gap:.75rem;margin:1.5rem 0">
      <div style="flex:1;height:1px;background:#E5E7EB"></div>
      <span style="font-size:.72rem;color:#9CA3AF;white-space:nowrap">lub e-mailem i hasłem</span>
      <div style="flex:1;height:1px;background:#E5E7EB"></div>
    </div>
    <?php endif; ?>

    <!-- ── Email + hasło — fallback / admini ── -->
    <details <?= $ms_crm_available ? '' : 'open' ?> style="border:1px solid #E5E7EB;border-radius:.5rem">
      <summary style="padding:.65rem 1rem;cursor:pointer;font-size:.84rem;font-weight:600;color:#374151;user-select:none;list-style:none;display:flex;align-items:center;justify-content:space-between">
        <span><i class="bi bi-envelope me-2" style="color:#6B7280"></i>Logowanie e-mail + hasło</span>
        <i class="bi bi-chevron-down" style="color:#9CA3AF;font-size:.75rem"></i>
      </summary>
      <div style="padding:.75rem 1rem 1rem;border-top:1px solid #F3F4F6">
        <form method="post" autocomplete="on" novalidate>
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <div class="mb-3">
            <label class="form-label" for="email">Adres e-mail</label>
            <input type="email" name="email" id="email"
                   class="form-control"
                   value="<?= h($_POST['email'] ?? '') ?>"
                   placeholder="nazwa@domena.pl"
                   autocomplete="email"
                   aria-describedby="crm-email-hint"
                   <?= !$ms_crm_available ? 'autofocus' : '' ?> required>
            <div id="crm-email-hint" style="font-size:.75rem;color:#94a3b8;margin-top:.3rem;line-height:1.45">
              E-mail służbowy <strong>@feer.org.pl</strong> albo prywatny e-mail podany do WiadomościFEER.
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Hasło</label>
            <div class="pass-wrap">
              <input type="password" name="password" id="password"
                     class="form-control"
                     autocomplete="current-password" required>
              <button type="button" class="pass-toggle" tabindex="-1"
                      onclick="var i=document.getElementById('password');i.type=i.type==='password'?'text':'password';this.querySelector('i').className=i.type==='password'?'bi bi-eye':'bi bi-eye-slash';"
                      aria-label="Pokaż/ukryj hasło">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn-crm-login" style="font-size:.88rem;padding:.6rem 1rem">
            <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj
          </button>
        </form>
      </div>
    </details>

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
