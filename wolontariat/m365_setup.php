<?php
/**
 * wolontariat/m365_setup.php — Strona aktywacji konta Microsoft 365
 * Dostęp: jednorazowy token (bez logowania). Admin wysyła link przez m365_action.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';
it_migrate();

// ─── Migracja kolumn tokena ──────────────────────────────────────────────────
try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN m365_setup_token TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN m365_setup_token_used_at DATETIME"); } catch (\Throwable $e) {}

$token = trim($_GET['token'] ?? '');
$error = '';
$row   = null;

if ($token === '') {
    $error = 'Link jest nieprawidłowy lub wygasł.';
} else {
    $row = db_one(
        "SELECT * FROM umowy_wolontariat WHERE m365_setup_token = ? AND m365_setup_token_used_at IS NULL",
        [$token]
    );
    if (!$row) {
        $error = 'Link jest nieprawidłowy, wygasł lub już został użyty. Poproś administratora o nowy link.';
    }
}

// ─── Pobierz dane M365 (ostatnie wydane hasło) ───────────────────────────────
$m365_data  = null;
$show_creds = false;

if (!$error && $row) {
    // Oznacz token jako użyty
    db()->prepare(
        "UPDATE umowy_wolontariat SET m365_setup_token_used_at = datetime('now','localtime') WHERE id = ?"
    )->execute([(int)$row['id']]);

    $login = $row['m365_login'] ?? '';

    // Pobierz najnowsze (nie-superseded) hasło dla tego konta z it_service_passwords
    $svc = db_one("SELECT id FROM it_services WHERE slug='m365'");
    $pwd_row = null;
    if ($svc && $login) {
        $pwd_row = db_one(
            "SELECT password_enc, issued_at FROM it_service_passwords
             WHERE service_id=? AND login=? AND is_superseded=0
             ORDER BY issued_at DESC LIMIT 1",
            [(int)$svc['id'], $login]
        );
    }

    $plain_password = null;
    if ($pwd_row && !empty($pwd_row['password_enc'])) {
        $plain_password = it_decrypt_password($pwd_row['password_enc']);
    }

    if ($login) {
        $m365_data = [
            'login'    => $login,
            'password' => $plain_password,
        ];
        $show_creds = true;
    } else {
        $error = 'Brak danych konta M365 w systemie. Skontaktuj się z administratorem.';
    }
}

// ─── Dane organizacji ─────────────────────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : (function_exists('org_setting') ? (org_setting('org_name') ?: 'Organizacja') : 'Organizacja');
$org_logo = function_exists('org_setting') ? org_setting('org_logo') : '';
$app_url  = defined('APP_URL') ? APP_URL : '';

if (!function_exists('h')) {
    function h(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aktywacja konta Microsoft 365 — <?= h($org_name) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body          { background:#f0f4f8; font-family:'Segoe UI',system-ui,sans-serif; min-height:100vh; }
    .page-wrap    { max-width:560px; margin:0 auto; padding:2.5rem 1rem 4rem; }
    .brand-header { text-align:center; padding:1.5rem 1rem 1rem; }
    .brand-header img { max-height:56px; margin-bottom:.6rem; }
    .brand-header h1  { font-size:1.25rem; font-weight:800; color:#1a1a2e; margin-bottom:.2rem; }
    .brand-header .sub{ color:#6c757d; font-size:.9rem; }

    .cred-card {
      background:#fff; border-radius:16px;
      box-shadow:0 4px 24px rgba(0,78,212,.13);
      overflow:hidden;
    }
    .cred-header {
      background:linear-gradient(135deg,#0078d4,#106ebe);
      padding:1.5rem 1.75rem;
      display:flex; align-items:center; gap:1rem;
    }
    .cred-header-icon {
      width:48px; height:48px; border-radius:12px;
      background:rgba(255,255,255,.2); display:flex;
      align-items:center; justify-content:center;
      font-size:1.4rem; color:#fff; flex-shrink:0;
    }
    .cred-header h2 { color:#fff; font-size:1.05rem; font-weight:800; margin:0; }
    .cred-header p  { color:rgba(255,255,255,.82); font-size:.82rem; margin:0; }

    .cred-body { padding:1.75rem; }

    .cred-field { margin-bottom:1.1rem; }
    .cred-field label {
      font-size:.72rem; font-weight:700; text-transform:uppercase;
      letter-spacing:.07em; color:#6B7280; display:block; margin-bottom:.35rem;
    }
    .cred-field-inner {
      display:flex; align-items:center; gap:.5rem;
      background:#F8FAFC; border:1.5px solid #E5E7EB;
      border-radius:10px; padding:.65rem 1rem;
    }
    .cred-value {
      flex:1; font-family:monospace; font-size:1.05rem;
      font-weight:700; color:#0F172A; letter-spacing:.03em;
      word-break:break-all;
    }
    .cred-copy-btn {
      background:none; border:1.5px solid #E5E7EB; border-radius:7px;
      padding:.3rem .6rem; font-size:.82rem; color:#6B7280;
      cursor:pointer; transition:all .12s; flex-shrink:0;
    }
    .cred-copy-btn:hover { background:#EFF6FF; border-color:#2563EB; color:#2563EB; }

    .pass-hidden  { filter:blur(5px); user-select:none; transition:filter .2s; }
    .pass-visible { filter:none; }

    .reveal-btn {
      background:none; border:1.5px solid #E5E7EB; border-radius:7px;
      padding:.3rem .6rem; font-size:.82rem; color:#6B7280;
      cursor:pointer; transition:all .12s; flex-shrink:0; white-space:nowrap;
    }
    .reveal-btn:hover { background:#FFF8F0; border-color:#F59E0B; color:#D97706; }

    .warn-box {
      background:#FFF8E1; border:1px solid #FDE68A; border-left:4px solid #F59E0B;
      border-radius:8px; padding:.9rem 1.1rem; font-size:.85rem; color:#92400E;
      margin-bottom:1.25rem;
    }
    .step-list { list-style:none; padding:0; margin:0; }
    .step-list li {
      display:flex; align-items:flex-start; gap:.75rem;
      padding:.5rem 0; border-bottom:1px solid #F9FAFB; font-size:.88rem;
    }
    .step-list li:last-child { border-bottom:none; }
    .step-num {
      width:24px; height:24px; border-radius:50%; flex-shrink:0;
      background:#0078d4; color:#fff; font-size:.75rem; font-weight:700;
      display:flex; align-items:center; justify-content:center;
    }

    .action-btn {
      display:block; width:100%; padding:.85rem; border-radius:10px;
      text-align:center; font-size:1rem; font-weight:700;
      text-decoration:none; transition:opacity .15s;
      background:linear-gradient(135deg,#0078d4,#106ebe); color:#fff;
      border:none; margin-bottom:.6rem;
    }
    .action-btn:hover { opacity:.9; color:#fff; }

    .error-card {
      background:#fff; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.08);
      padding:3rem 2rem; text-align:center;
    }
    footer { text-align:center; font-size:.75rem; color:#aaa; margin-top:2rem; }
  </style>
</head>
<body>
<div class="page-wrap">

  <!-- Header marki -->
  <div class="brand-header">
    <?php if ($org_logo): ?>
      <img src="<?= h($org_logo) ?>" alt="<?= h($org_name) ?>">
    <?php else: ?>
      <div style="font-size:2.5rem;color:#0078d4;margin-bottom:.4rem">
        <i class="bi bi-microsoft"></i>
      </div>
    <?php endif; ?>
    <h1><?= h($org_name) ?></h1>
    <div class="sub">Aktywacja konta Microsoft 365</div>
  </div>

  <?php if ($error): ?>
  <!-- Błąd tokenu -->
  <div class="error-card">
    <div style="font-size:3rem;color:#e74c3c;margin-bottom:1rem">
      <i class="bi bi-shield-x"></i>
    </div>
    <h2 class="h5 fw-bold text-danger mb-3">Link wygasł lub jest nieprawidłowy</h2>
    <p class="text-muted mb-4"><?= h($error) ?></p>
    <?php if ($app_url): ?>
    <a href="<?= h($app_url) ?>/panel/index.php" class="btn btn-outline-primary">
      <i class="bi bi-house me-2"></i>Przejdź do panelu
    </a>
    <?php endif; ?>
  </div>

  <?php elseif ($show_creds && $m365_data): ?>
  <!-- Dane logowania -->
  <div class="cred-card">
    <div class="cred-header">
      <div class="cred-header-icon">
        <i class="bi bi-microsoft"></i>
      </div>
      <div>
        <h2>Twoje dane logowania M365</h2>
        <p>Zapisz je w bezpiecznym miejscu</p>
      </div>
    </div>

    <div class="cred-body">

      <div class="warn-box">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Ten link jest <strong>jednorazowy</strong> — po zamknięciu strony nie będzie dostępny ponownie.
        Zapisz dane logowania teraz!
      </div>

      <!-- Login -->
      <div class="cred-field">
        <label><i class="bi bi-envelope me-1"></i>Login (adres e-mail)</label>
        <div class="cred-field-inner">
          <span class="cred-value" id="loginVal"><?= h($m365_data['login']) ?></span>
          <button type="button" class="cred-copy-btn" onclick="copyText('loginVal', this)"
                  title="Kopiuj login">
            <i class="bi bi-clipboard"></i>
          </button>
        </div>
      </div>

      <!-- Hasło -->
      <div class="cred-field">
        <label><i class="bi bi-key me-1"></i>Hasło tymczasowe</label>
        <?php if ($m365_data['password']): ?>
        <div class="cred-field-inner">
          <span class="cred-value pass-hidden" id="passVal"><?= h($m365_data['password']) ?></span>
          <button type="button" class="reveal-btn" id="revealBtn" onclick="revealPass()"
                  title="Pokaż / ukryj hasło">
            <i class="bi bi-eye" id="revealIcon"></i> Pokaż
          </button>
          <button type="button" class="cred-copy-btn" onclick="copyText('passVal', this)"
                  title="Kopiuj hasło">
            <i class="bi bi-clipboard"></i>
          </button>
        </div>
        <div style="font-size:.78rem;color:#6B7280;margin-top:.4rem">
          <i class="bi bi-info-circle me-1"></i>
          Po zalogowaniu system poprosi o ustawienie własnego hasła.
        </div>
        <?php else: ?>
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Nie udało się pobrać hasła z bazy. Skontaktuj się z administratorem.
        </div>
        <?php endif; ?>
      </div>

      <!-- Kroki -->
      <div style="background:#F0F9FF;border-radius:10px;padding:1.1rem 1.25rem;margin-bottom:1.5rem">
        <div style="font-size:.8rem;font-weight:700;color:#0369A1;margin-bottom:.65rem;text-transform:uppercase;letter-spacing:.06em">
          <i class="bi bi-list-check me-1"></i>Co dalej?
        </div>
        <ol class="step-list">
          <li>
            <div class="step-num">1</div>
            <div>Kliknij <strong>Zaloguj do Microsoft 365</strong> poniżej</div>
          </li>
          <li>
            <div class="step-num">2</div>
            <div>Wpisz login i hasło tymczasowe (pamiętaj o skopiowaniu)</div>
          </li>
          <li>
            <div class="step-num">3</div>
            <div>Postępuj zgodnie z instrukcjami — ustaw własne hasło i skonfiguruj weryfikację dwustopniową</div>
          </li>
          <li>
            <div class="step-num">4</div>
            <div>Po zalogowaniu masz dostęp do Outlook, Teams, OneDrive i innych aplikacji organizacji</div>
          </li>
        </ol>
      </div>

      <!-- CTA -->
      <a href="https://portal.office.com" target="_blank" rel="noopener noreferrer"
         class="action-btn">
        <i class="bi bi-box-arrow-up-right me-2"></i>Zaloguj do Microsoft 365
      </a>

      <?php
      // Po ustawieniu hasła poczty szuka się już pod NASZYM adresem, nie pod
      // outlook.office.com — jeden adres do zapamiętania.
      require_once dirname(__DIR__) . '/includes/webmail_clients.php';
      ?>
      <p class="text-muted" style="font-size:.85rem;margin:.6rem 0 1rem;text-align:center">
        Pocztę otwierasz potem zawsze tutaj:
        <a href="<?= h(webmail_chooser_url()) ?>" target="_blank" rel="noopener noreferrer"><strong><?= h(webmail_chooser_label()) ?></strong></a>
      </p>

      <?php if ($app_url): ?>
      <a href="<?= h($app_url) ?>/panel/index.php" class="btn btn-outline-secondary w-100" style="border-radius:10px">
        <i class="bi bi-house me-2"></i>Wróć do panelu wolontariusza
      </a>
      <?php endif; ?>

    </div>
  </div>

  <?php endif; ?>

  <footer>
    &copy; <?= date('Y') ?> <?= h($org_name) ?> &mdash; Dane chronione zgodnie z RODO.
  </footer>
</div>

<script>
function copyText(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  var text = el.textContent.trim();
  navigator.clipboard.writeText(text).then(function() {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
    btn.style.borderColor = '#16a34a';
    setTimeout(function() {
      btn.innerHTML = orig;
      btn.style.borderColor = '';
    }, 1800);
  }).catch(function() {
    // fallback
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
  });
}

var passRevealed = false;
function revealPass() {
  passRevealed = !passRevealed;
  var val  = document.getElementById('passVal');
  var icon = document.getElementById('revealIcon');
  var btn  = document.getElementById('revealBtn');
  if (passRevealed) {
    val.classList.remove('pass-hidden');
    val.classList.add('pass-visible');
    icon.className = 'bi bi-eye-slash';
    btn.innerHTML  = '<i class="bi bi-eye-slash" id="revealIcon"></i> Ukryj';
  } else {
    val.classList.add('pass-hidden');
    val.classList.remove('pass-visible');
    icon.className = 'bi bi-eye';
    btn.innerHTML  = '<i class="bi bi-eye" id="revealIcon"></i> Pokaż';
  }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
