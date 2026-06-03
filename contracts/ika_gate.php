<?php
/**
 * contracts/ika_gate.php — Brama IKA (Indywidualny Kod Autoryzacyjny).
 *
 * Wyświetlana przed otwarciem formularza dodawania umowy.
 * Po pomyślnej weryfikacji ustawia token w sesji i przekierowuje
 * z powrotem do oryginalnego URL (GET ?to=...).
 *
 * Nie zawiera header.php — pełnoekranowa strona gate (styl spójny z login.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/branding.php';

require_login();

$user    = current_user();
$role    = $user['role'] ?? '';
$user_id = (int)$user['id'];

// IKA wymagana dla admin, editor, crm_user oraz ról crm_only i doradców K30
$_ika_k30 = false;
$_is_crm_only_role = false;
if (!in_array($role, ['admin', 'editor', 'crm_user'], true)) {
    // Sprawdź własne role z flagą crm_only
    try {
        $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$role]);
        $_is_crm_only_role = !empty($r['crm_only']);
    } catch (\Throwable $e) {}

    if (!$_is_crm_only_role) {
        try {
            $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [$user_id]);
            $_ika_k30 = !empty($row['k30_consultant']);
        } catch (\Throwable $e) {}
    }

    if (!$_ika_k30 && !$_is_crm_only_role) {
        // crm_only przez is_crm_only() — sprawdź funkcją
        $_ika_k30 = is_crm_only();
    }

    if (!$_ika_k30 && !$_is_crm_only_role) {
        // Nie ma uprawnień do IKA — przekieruj
        $dest = is_crm_only() ? '/crm/dashboard.php' : '/index.php';
        header('Location: ' . APP_URL . $dest);
        exit;
    }
}

// Walidacja i sanityzacja URL powrotu — MUSI być przed wykryciem kontekstu
$raw_to    = trim($_GET['to'] ?? $_POST['to'] ?? '');
$return_to = '';
if ($raw_to !== '') {
    // Akceptuj: URL zaczynający się od APP_URL (w tym przez proxy z innym schematem)
    // lub relatywną ścieżkę zaczynającą się od /
    $app_path = parse_url(APP_URL, PHP_URL_PATH) ?: '';
    $raw_path = parse_url($raw_to, PHP_URL_PATH) ?? '';
    $raw_host = parse_url($raw_to, PHP_URL_HOST) ?? '';
    $app_host = parse_url(APP_URL, PHP_URL_HOST) ?? '';

    $host_ok = ($raw_host === '' || $raw_host === $app_host);
    $path_ok = ($app_path === '' || $app_path === '/' || str_starts_with($raw_path, $app_path));

    if ($host_ok && $path_ok) {
        $return_to = $raw_to;
    } elseif (str_starts_with($raw_to, APP_URL . '/')) {
        // Fallback: bezpośrednie dopasowanie prefiksu
        $return_to = $raw_to;
    }
}
if ($return_to === '') {
    // crm_only → domyślnie CRM, nie main index
    $return_to = is_crm_only()
        ? APP_URL . '/crm/dashboard.php'
        : APP_URL . '/index.php';
}

// Wykryj kontekst i nazwę modułu — po ustaleniu $return_to
$_ika_context = 'system';
$_ika_module  = 'System';
if (str_contains($return_to, '/karty30/')) {
    $_ika_context = 'karty30';
    $_ika_module  = 'TyfloKonsultacje — Karty 30';
} elseif (str_contains($return_to, '/crm/')) {
    $_ika_context = 'crm';
    $_ika_module  = 'CRM';
} else {
    // Ustal konkretny typ umowy na podstawie URL
    $_contract_map = [
        '/wolontariat/' => 'Umowy wolontariackie',
        '/zlecenie/'    => 'Umowy zlecenie',
        '/dzielo/'      => 'Umowy o dzieło',
        '/praca/'       => 'Umowy o pracę',
        '/uslugi/'      => 'Umowy usługowe',
        '/inne/'        => 'Inne umowy',
    ];
    foreach ($_contract_map as $_seg => $_label) {
        if (str_contains($return_to, $_seg)) {
            $_ika_module = $_label;
            break;
        }
    }
}

// Świeże dane IKA z DB
$u_fresh    = db_one("SELECT cpc_code, cpc_fails, cpc_blocked_until FROM users WHERE id=?", [$user_id]);
$has_code   = !empty($u_fresh['cpc_code']);
$is_blocked = !empty($u_fresh['cpc_blocked_until']) && $u_fresh['cpc_blocked_until'] > date('Y-m-d H:i:s');

$_b = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'System');

$error = '';

// ── POST: weryfikacja kodu ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_start();
    $csrf_ok = hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '');
    if (!$csrf_ok) {
        $error = 'Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.';
    } elseif (!$has_code) {
        $error = 'Nie masz przypisanego kodu IKA. Skontaktuj się z administratorem.';
    } elseif ($is_blocked) {
        $error = 'Kod IKA zablokowany do: ' . date('H:i d.m.Y', strtotime($u_fresh['cpc_blocked_until'])) . '.';
    } else {
        $code   = trim($_POST['ika_code'] ?? '');
        $result = cpc_verify($user_id, $code);

        if ($result['blocked']) {
            $error = 'Zbyt wiele błędnych prób. Kod IKA zablokowany na 15 minut.';
        } elseif ($result['ok']) {
            ika_set_verified();
            header('Location: ' . $return_to);
            exit;
        } else {
            $fails_so_far = (int)($result['fails'] ?? 0);
            $remaining = max(0, 3 - $fails_so_far);
            $error = 'Nieprawidłowy kod IKA.';
            // Pokaż licznik prób dopiero po 2+ nieudanych próbach
            if ($fails_so_far >= 2) {
                $s = $remaining === 1 ? 'próba' : ($remaining === 0 ? 'prób' : 'prób');
                $error .= ' Pozostało ' . $remaining . ' ' . $s . '.';
            }
        }
    }
}

// Dane użytkownika do wyświetlenia
$display_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($display_name === '') $display_name = $user['name'] ?? $user['email'] ?? '';

$initials = '';
foreach (preg_split('/\s+/', trim($display_name)) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
}
$initials   = mb_substr($initials, 0, 2, 'UTF-8') ?: '?';
$role_label = match(true) {
    $role === 'admin'    => 'Administrator',
    $role === 'editor'   => 'Edytor',
    $role === 'crm_user' => 'Użytkownik CRM',
    $_ika_k30            => 'Doradca TyfloKonsultacje',
    default              => ucfirst($role),
};
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weryfikacja IKA — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }

/* ── Split layout ──────────────────────────────────────────────── */
.login-split {
  display: flex;
  min-height: 100vh;
}

/* ── Lewa strona ───────────────────────────────────────────────── */
.login-left {
  width: 380px;
  flex-shrink: 0;
  background: linear-gradient(160deg, var(--c-darker) 0%, var(--c-dark,var(--c)) 50%, var(--c) 100%);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 3rem 2.5rem;
  position: relative;
  overflow: hidden;
}
.login-left::before {
  content: '';
  position: absolute;
  width: 340px; height: 340px;
  border-radius: 50%;
  border: 60px solid rgba(255,255,255,.04);
  bottom: -80px; right: -100px;
  pointer-events: none;
}
.login-left::after {
  content: '';
  position: absolute;
  width: 200px; height: 200px;
  border-radius: 50%;
  border: 40px solid rgba(255,255,255,.05);
  top: -50px; left: -60px;
  pointer-events: none;
}

.left-content { position: relative; color: var(--c-text, #fff); }

.brand-icon-wrap {
  width: 52px; height: 52px;
  border-radius: 13px;
  background: rgba(255,255,255,.12);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; color: #fff;
  margin-bottom: 1.25rem;
}
.brand-system-label {
  font-size: .66rem; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,.4);
  margin-bottom: .5rem;
}
.brand-system-name {
  font-size: 1rem; font-weight: 600;
  color: rgba(255,255,255,.55);
  line-height: 1.3;
  margin-bottom: 2rem;
}
.brand-system-name span { color: #93c5fd; }

/* Blok IKA-info na lewym panelu */
.ika-info-box {
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 12px;
  padding: 1.1rem 1.25rem;
}
.ika-info-box-label {
  font-size: .68rem; font-weight: 600; letter-spacing: .07em;
  text-transform: uppercase; color: rgba(255,255,255,.4);
  margin-bottom: .6rem;
  display: flex; align-items: center; gap: .4rem;
}
.ika-info-box p {
  font-size: .8rem;
  color: rgba(255,255,255,.65);
  line-height: 1.55;
  margin: 0;
}
.ika-info-box p + p { margin-top: .5rem; }

/* Org box */
.org-box {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.09);
  border-radius: 10px;
  padding: .85rem 1.1rem;
  margin-top: 1rem;
}
.org-box-label {
  font-size: .65rem; font-weight: 600; letter-spacing: .07em;
  text-transform: uppercase; color: rgba(255,255,255,.35);
  margin-bottom: .25rem;
}
.org-box-name {
  font-size: .95rem; font-weight: 700;
  color: rgba(255,255,255,.8); line-height: 1.3;
}

/* Left footer */
.left-footer {
  position: relative;
  color: rgba(255,255,255,.28);
  font-size: .72rem;
}
.left-footer a {
  color: rgba(255,255,255,.45);
  text-decoration: none;
  display: inline-flex; align-items: center; gap: .3rem;
}
.left-footer a:hover { color: rgba(255,255,255,.75); }

/* ── Prawa strona ───────────────────────────────────────────────── */
.login-right {
  flex: 1;
  background: #EEF2F7;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2.5rem 2rem;
  overflow-y: auto;
}
.login-box {
  width: 100%;
  max-width: 420px;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 32px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
  padding: 2.25rem 2rem;
}

/* Org header — tylko mobile */
.org-header-mobile {
  display: none;
  margin-bottom: 1.75rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid #e2e8f0;
}
.org-header-mobile .sys-label {
  font-size: .65rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: #94a3b8; margin-bottom: .2rem;
}
.org-header-mobile .sys-name {
  font-size: .82rem; color: #64748b; margin-bottom: .5rem;
}
.org-header-mobile .org-name-big {
  font-size: 1.1rem; font-weight: 700; color: #1e293b; line-height: 1.25;
}

/* Nagłówek formularza */
.form-heading {
  font-size: 1.35rem; font-weight: 700;
  color: #0f172a; margin-bottom: .2rem;
}
.form-sub {
  font-size: .84rem; color: #64748b; margin-bottom: 1.6rem;
}

/* Chip użytkownika */
.user-chip {
  display: flex; align-items: center; gap: .75rem;
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: .6rem;
  padding: .65rem .9rem;
  margin-bottom: 1.4rem;
}
.user-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--c); color: var(--c-text);
  display: flex; align-items: center; justify-content: center;
  font-size: .88rem; font-weight: 700; flex-shrink: 0;
}
.user-info { min-width: 0; }
.user-name {
  font-size: .88rem; font-weight: 600; color: #1e293b;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.user-role { font-size: .75rem; color: #64748b; }

/* Input kodu */
.login-box .form-label {
  font-size: .81rem; font-weight: 600; color: #374151;
  margin-bottom: .3rem;
}
.code-input {
  width: 100%;
  font-size: 2rem; font-weight: 700;
  font-family: 'Courier New', monospace;
  letter-spacing: .5em;
  text-align: center;
  padding: .75rem 1rem;
  border: 2px solid #CBD5E1;
  border-radius: .6rem;
  outline: none;
  transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
  background: #F8FAFC;
  color: #1e293b;
}
.code-input:focus {
  border-color: var(--c);
  box-shadow: 0 0 0 4px var(--c-ring);
  background: #fff;
}
.code-input.is-error { border-color: #ef4444; background: #fff5f5; }

/* Przycisk weryfikacji */
.btn-login {
  background: var(--c); color: var(--c-text);
  border: none; border-radius: .5rem;
  padding: .72rem 1.25rem;
  font-size: .94rem; font-weight: 600;
  width: 100%;
  transition: background .15s, box-shadow .15s;
}
.btn-login:hover:not(:disabled) { background: var(--c-dark); box-shadow: 0 2px 8px var(--c-ring); }
.btn-login:disabled { opacity: .5; cursor: not-allowed; }

/* Spinner inline */
@keyframes _spin { to { transform: rotate(360deg); } }
.ika-spinner {
  display: none;
  width: 1rem; height: 1rem;
  border: 2px solid rgba(255,255,255,.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: _spin .7s linear infinite;
  vertical-align: middle;
  margin-right: .35rem;
}

/* Stan: brak kodu / zablokowany */
.state-box {
  text-align: center;
  padding: 1.25rem 0 .5rem;
}
.state-box .state-icon {
  font-size: 2.4rem;
  display: block;
  margin-bottom: .6rem;
}
.state-box p {
  color: #64748b; font-size: .88rem; line-height: 1.6; margin: 0;
}
.state-box p + p { margin-top: .5rem; }

/* ── Mobile ────────────────────────────────────────────────────── */
@media (max-width: 700px) {
  .login-split { flex-direction: column; }
  .login-left {
    width: 100%; padding: 1.25rem;
    flex-direction: row; align-items: center; gap: .75rem;
    min-height: auto;
  }
  .login-left::before, .login-left::after { display: none; }
  .left-content { display: flex; align-items: center; gap: .75rem; flex: 1; }
  .brand-icon-wrap { width: 36px; height: 36px; border-radius: 9px; font-size: 1.1rem; margin-bottom: 0; }
  .brand-system-label, .brand-system-name, .ika-info-box, .org-box, .left-footer { display: none; }
  .org-header-mobile { display: block; }
  .login-right { padding: 1.5rem 1.2rem; align-items: flex-start; background: #F8FAFC; }
  .login-box { box-shadow: none; padding: 1.75rem 1.25rem; }
}
</style>
</head>
<body>
<div class="login-split">

  <!-- ══ Lewa strona ══════════════════════════════════════════════════════════ -->
  <div class="login-left">
    <div class="left-content">

      <?php if ($_b['logo_url']): ?>
      <div class="brand-icon-wrap" style="background:rgba(255,255,255,.15);width:auto;max-width:160px;height:auto;min-height:52px;border-radius:12px;padding:.4rem;display:flex;align-items:center;margin-bottom:1.25rem">
        <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" style="max-height:44px;max-width:148px;object-fit:contain;filter:brightness(0) invert(1)">
      </div>
      <?php else: ?>
      <div class="brand-icon-wrap">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <?php endif; ?>

      <div class="brand-system-label">Platforma NGO</div>
      <div class="brand-system-name">
        System Zarządzania<br><span>Organizacją i Wolontariatem</span>
      </div>

      <div class="ika-info-box">
        <div class="ika-info-box-label">
          <i class="bi bi-key-fill"></i> Indywidualny Kod Autoryzacyjny
        </div>
        <p>Kod IKA to 6-cyfrowy kod bezpieczeństwa przypisany indywidualnie do Twojego konta.</p>
        <?php if ($_ika_context === 'karty30'): ?>
        <p>
          Chroni dostęp do modułu <strong style="color:#c4b5fd">TyfloKonsultacje — Karty 30</strong>.
          Moduł przetwarza <strong style="color:#fca5a5">dane osobowe beneficjentów</strong>
          (imię, adres, problem zdrowotny) — weryfikacja IKA wymagana przy każdym dostępie.
        </p>
        <?php elseif ($_ika_context === 'crm'): ?>
        <p>Chroni dostęp do modułu <strong style="color:#86efac">CRM</strong> — kontaktów, komunikacji i danych relacyjnych organizacji.</p>
        <?php else: ?>
        <p>Chroni dostęp do sekcji <strong style="color:#93c5fd"><?= h($_ika_module) ?></strong> — rejestrację i modyfikację dokumentów w systemie.</p>
        <?php endif; ?>
        <p>Sesja weryfikacji jest ważna przez <strong style="color:#93c5fd">30 minut</strong>.</p>
      </div>

      <div class="org-box">
        <div class="org-box-label">Organizacja</div>
        <div class="org-box-name"><?= h($org_name) ?></div>
      </div>

    </div>

    <div class="left-footer">
      <a href="<?= h(APP_URL) ?>/index.php">
        <i class="bi bi-arrow-left-circle"></i> Strona główna
      </a>
      <div style="margin-top:.5rem">&copy; <?= date('Y') ?> &nbsp;·&nbsp; System Zarządzania Organizacją</div>
    </div>
  </div>

  <!-- ══ Prawa strona ══════════════════════════════════════════════════════════ -->
  <div class="login-right">
  <div class="login-box">

    <!-- Mobile: nagłówek org zamiast lewego panelu -->
    <div class="org-header-mobile">
      <div class="sys-label">Platforma NGO</div>
      <div class="sys-name">System Zarządzania Organizacją i Wolontariatem</div>
      <div class="org-name-big"><?= h($org_name) ?></div>
    </div>

    <div class="form-heading">
      <?php if ($_ika_context === 'karty30'): ?>
        <i class="bi bi-card-checklist me-2" style="color:#7C3AED;font-size:1.1rem"></i>Weryfikacja IKA
      <?php elseif ($_ika_context === 'crm'): ?>
        <i class="bi bi-diagram-2-fill me-2" style="color:#16A34A;font-size:1.1rem"></i>Weryfikacja IKA
      <?php else: ?>
        <i class="bi bi-shield-lock-fill me-2" style="color:var(--c);font-size:1.1rem"></i>Weryfikacja IKA
      <?php endif; ?>
    </div>
    <div class="form-sub">
      Podaj swój kod IKA, aby kontynuować do:
      <strong style="color:#0f172a"><?= h($_ika_module) ?></strong>
    </div>

    <!-- Chip użytkownika -->
    <div class="user-chip">
      <div class="user-avatar"><?= h($initials) ?></div>
      <div class="user-info">
        <div class="user-name"><?= h($display_name) ?></div>
        <div class="user-role"><?= h($role_label) ?></div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-4"
         style="border-radius:.5rem;font-size:.84rem">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!$has_code): ?>
    <!-- ── Brak kodu IKA ─────────────────────────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-key text-secondary"></i></span>
      <p>Nie masz przypisanego kodu IKA.</p>
      <p>Skontaktuj się z administratorem systemu,<br>
         który nada Ci kod w panelu<br>
         <strong>Administracja → Kody IKA</strong>.</p>
    </div>

    <?php elseif ($is_blocked): ?>
    <!-- ── Zablokowany ───────────────────────────────────────────────────── -->
    <div class="state-box">
      <span class="state-icon"><i class="bi bi-lock-fill text-warning"></i></span>
      <p>Kod IKA jest tymczasowo zablokowany<br>z powodu zbyt wielu błędnych prób.</p>
      <p class="mt-2">Spróbuj ponownie o
        <strong><?= h(date('H:i', strtotime($u_fresh['cpc_blocked_until']))) ?></strong>
        lub skontaktuj się z administratorem.
      </p>
    </div>

    <?php else: ?>
    <!-- ── Formularz kodu ────────────────────────────────────────────────── -->
    <form method="post" id="ikaForm" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="to"   value="<?= h($return_to) ?>">

      <div class="mb-3">
        <label class="form-label" for="ika_code">
          Kod IKA <span style="font-weight:400;color:#94a3b8;font-size:.78rem">(6 cyfr)</span>
        </label>
        <input type="text"
               id="ika_code"
               name="ika_code"
               class="code-input<?= $error ? ' is-error' : '' ?>"
               inputmode="numeric"
               pattern="\d{6}"
               maxlength="6"
               placeholder="––––––"
               autofocus
               autocomplete="one-time-code"
               aria-describedby="ika-hint">
        <div id="ika-hint" style="font-size:.78rem;color:#94a3b8;margin-top:.4rem;text-align:center">
          <i class="bi bi-info-circle me-1"></i>Nie pamiętasz kodu? Skontaktuj się z administratorem.
        </div>
      </div>

      <button type="submit" class="btn-login mb-3" id="btnVerify" disabled>
        <span class="ika-spinner" id="ikaSpinner"></span>
        <span id="btnText">
          <i class="bi bi-shield-check me-1"></i>
          Zweryfikuj i wejdź do: <?= h($_ika_module) ?>
        </span>
      </button>
    </form>

    <div class="text-center">
      <?php
        $back_url = match($_ika_context) {
            'karty30' => APP_URL . '/karty30/index.php',
            'crm'     => APP_URL . '/crm/dashboard.php',
            default   => APP_URL . '/index.php',
        };
      ?>
      <a href="<?= h($back_url) ?>"
         style="font-size:.82rem;color:#64748b;text-decoration:none">
        <i class="bi bi-arrow-left me-1"></i>Anuluj i wróć
      </a>
    </div>
    <?php endif; ?>

  </div><!-- /login-box -->
  </div><!-- /login-right -->

</div><!-- /login-split -->

<script>
(function () {
    'use strict';
    var inp     = document.getElementById('ika_code');
    var btn     = document.getElementById('btnVerify');
    var spinner = document.getElementById('ikaSpinner');
    var form    = document.getElementById('ikaForm');
    if (!inp) return;

    inp.addEventListener('input', function () {
        var v = this.value.replace(/\D/g, '').slice(0, 6);
        this.value = v;
        var ready = /^\d{6}$/.test(v);
        btn.disabled = !ready;
        this.classList.toggle('is-error', v.length === 6 && !ready);
        if (ready) {
            setTimeout(function () {
                if (/^\d{6}$/.test(inp.value)) btn.click();
            }, 150);
        }
    });

    if (form) {
        form.addEventListener('submit', function () {
            if (spinner) spinner.style.display = 'inline-block';
            if (btn) btn.disabled = true;
            var t = document.getElementById('btnText');
            if (t) t.innerHTML = 'Weryfikacja…';
        });
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
