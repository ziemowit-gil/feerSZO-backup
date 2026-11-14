<?php
/**
 * wolontariat/m365_setup.php — strona z jednorazowego linku „dane logowania” wysyłanego z umowy
 * (contracts/m365_action.php, akcja send_m365_setup_link). Dostęp: jednorazowy token, bez logowania.
 *
 * Od 14.11.2026 poczta działa w dPoczcie, a Microsoft 365 wyłączamy 20.11.2026, więc strona nie pokazuje
 * już hasła tymczasowego Microsoft 365 ani nie odsyła do portalu Microsoft (polecenie 2026-11-14).
 * Pokazuje adres w organizacji (login) i prowadzi do /nowehaslo oraz dPoczty. Nazwa pliku zostaje,
 * bo prowadzą do niej linki z wysłanych już wiadomości.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webmail_clients.php';

// ─── Migracja kolumn tokena ──────────────────────────────────────────────────
try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN m365_setup_token TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN m365_setup_token_used_at DATETIME"); } catch (\Throwable $e) {}

$token = trim($_GET['token'] ?? '');
$error = '';
$row   = null;
$login = '';

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

if (!$error && $row) {
    // Oznacz token jako użyty
    db()->prepare(
        "UPDATE umowy_wolontariat SET m365_setup_token_used_at = datetime('now','localtime') WHERE id = ?"
    )->execute([(int)$row['id']]);

    $login = trim((string)($row['m365_login'] ?? ''));
    if ($login === '') {
        $error = 'W systemie nie ma Twojego adresu w organizacji. Napisz do administratora.';
    }
}

// ─── Dane organizacji ─────────────────────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : (function_exists('org_setting') ? (org_setting('org_name') ?: 'Organizacja') : 'Organizacja');
$org_logo = function_exists('org_setting') ? org_setting('org_logo') : '';
$app_url  = defined('APP_URL') ? APP_URL : '';
$newpass  = webmail_szo_url('/nowehaslo');
$dpoczta  = webmail_dpoczta_url();

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
  <title>Twoje konto w organizacji · <?= h($org_name) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body          { background:#f0f4f8; font-family:'Segoe UI',system-ui,sans-serif; min-height:100vh; }
    .page-wrap    { max-width:560px; margin:0 auto; padding:2.5rem 1rem 4rem; }
    .brand-header { text-align:center; padding:1.5rem 1rem 1rem; }
    .brand-header img { max-height:56px; margin-bottom:.6rem; }
    .brand-header h1  { font-size:1.25rem; font-weight:800; color:#1a1a2e; margin-bottom:.2rem; }
    .brand-header .sub{ color:#4b5563; font-size:.9rem; }

    .cred-card {
      background:#fff; border-radius:16px;
      box-shadow:0 4px 24px rgba(30,64,175,.13);
      overflow:hidden;
    }
    .cred-header {
      background:linear-gradient(135deg,#1e40af,#1d4ed8);
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
    .cred-header p  { color:#fff; font-size:.82rem; margin:0; }

    .cred-body { padding:1.75rem; }

    .cred-field { margin-bottom:1.1rem; }
    .cred-field .cred-label {
      font-size:.72rem; font-weight:700; text-transform:uppercase;
      letter-spacing:.07em; color:#4b5563; display:block; margin-bottom:.35rem;
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
      background:none; border:1.5px solid #9CA3AF; border-radius:7px;
      padding:.3rem .6rem; font-size:.82rem; color:#374151;
      cursor:pointer; transition:all .12s; flex-shrink:0;
    }
    .cred-copy-btn:hover { background:#EFF6FF; border-color:#1d4ed8; color:#1d4ed8; }

    .warn-box {
      background:#FFF8E1; border:1px solid #FDE68A; border-left:4px solid #F59E0B;
      border-radius:8px; padding:.9rem 1.1rem; font-size:.85rem; color:#92400E;
      margin-bottom:1.25rem;
    }
    .step-list { list-style:none; padding:0; margin:0; }
    .step-list li {
      display:flex; align-items:flex-start; gap:.75rem;
      padding:.5rem 0; border-bottom:1px solid #E0F2FE; font-size:.88rem;
    }
    .step-list li:last-child { border-bottom:none; }
    .step-num {
      width:24px; height:24px; border-radius:50%; flex-shrink:0;
      background:#1d4ed8; color:#fff; font-size:.75rem; font-weight:700;
      display:flex; align-items:center; justify-content:center;
    }
    .step-list a, .cred-body p a { color:#1d4ed8; text-decoration:underline; }

    .action-btn {
      display:block; width:100%; padding:.85rem; border-radius:10px;
      text-align:center; font-size:1rem; font-weight:700;
      text-decoration:none; transition:opacity .15s;
      background:linear-gradient(135deg,#1e40af,#1d4ed8); color:#fff;
      border:none; margin-bottom:.6rem;
    }
    .action-btn:hover { opacity:.9; color:#fff; }
    a:focus-visible, button:focus-visible { outline:3px solid #1d4ed8; outline-offset:2px; }

    .error-card {
      background:#fff; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.08);
      padding:3rem 2rem; text-align:center;
    }
    footer { text-align:center; font-size:.75rem; color:#4b5563; margin-top:2rem; }
  </style>
</head>
<body>
<main class="page-wrap">

  <!-- Header marki -->
  <div class="brand-header">
    <?php if ($org_logo): ?>
      <img src="<?= h($org_logo) ?>" alt="<?= h($org_name) ?>">
    <?php else: ?>
      <div style="font-size:2.5rem;color:#1d4ed8;margin-bottom:.4rem" aria-hidden="true">
        <i class="bi bi-person-badge"></i>
      </div>
    <?php endif; ?>
    <h1><?= h($org_name) ?></h1>
    <div class="sub">Twoje konto w organizacji</div>
  </div>

  <?php if ($error): ?>
  <!-- Błąd tokenu -->
  <div class="error-card" role="alert">
    <div style="font-size:3rem;color:#b91c1c;margin-bottom:1rem" aria-hidden="true">
      <i class="bi bi-shield-x"></i>
    </div>
    <h2 class="h5 fw-bold text-danger mb-3">Nie da się otworzyć tego linku</h2>
    <p class="mb-4" style="color:#4b5563"><?= h($error) ?></p>
    <?php if ($app_url): ?>
    <a href="<?= h($app_url) ?>/panel/index.php" class="btn btn-outline-primary">
      <i class="bi bi-house me-2" aria-hidden="true"></i>Przejdź do panelu
    </a>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <!-- Adres w organizacji i co dalej -->
  <div class="cred-card">
    <div class="cred-header">
      <div class="cred-header-icon" aria-hidden="true">
        <i class="bi bi-person-badge"></i>
      </div>
      <div>
        <h2>Twój adres w organizacji</h2>
        <p>Tym adresem logujesz się do poczty i do SZO</p>
      </div>
    </div>

    <div class="cred-body">

      <div class="warn-box">
        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
        Ten link działa <strong>tylko raz</strong>. Zapisz adres, zanim zamkniesz stronę.
      </div>

      <!-- Login -->
      <div class="cred-field">
        <span class="cred-label" id="loginLbl"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Login (adres e-mail)</span>
        <div class="cred-field-inner">
          <span class="cred-value" id="loginVal" aria-labelledby="loginLbl"><?= h($login) ?></span>
          <button type="button" class="cred-copy-btn" onclick="copyText('loginVal', this)"
                  aria-label="Kopiuj adres do schowka">
            <i class="bi bi-clipboard" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <!-- Kroki -->
      <div style="background:#F0F9FF;border-radius:10px;padding:1.1rem 1.25rem;margin-bottom:1.5rem">
        <h3 style="font-size:.8rem;font-weight:700;color:#075985;margin-bottom:.65rem;text-transform:uppercase;letter-spacing:.06em">
          <i class="bi bi-list-check me-1" aria-hidden="true"></i>Co dalej?
        </h3>
        <ol class="step-list">
          <li>
            <div class="step-num" aria-hidden="true">1</div>
            <div>Ustaw hasła na stronie <a href="<?= h($newpass) ?>">Nowe hasło</a>. Kod potwierdzający dostaniesz SMS-em albo e-mailem.</div>
          </li>
          <li>
            <div class="step-num" aria-hidden="true">2</div>
            <div>Masz dwa hasła. Jedno służy do logowania jednokrotnego w SZO i w chmurze plików, drugie tylko do poczty.</div>
          </li>
          <li>
            <div class="step-num" aria-hidden="true">3</div>
            <div>Pocztę otwierasz w dPoczcie adresem e-mail i hasłem do poczty.</div>
          </li>
        </ol>
      </div>

      <!-- CTA -->
      <a href="<?= h($newpass) ?>" class="action-btn">
        <i class="bi bi-key me-2" aria-hidden="true"></i>Ustaw hasła
      </a>

      <p style="font-size:.85rem;margin:.6rem 0 1rem;text-align:center;color:#4b5563">
        Pocztę otwierasz w <a href="<?= h($dpoczta) ?>" target="_blank" rel="noopener noreferrer">dPoczcie<span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>.
        Jak ustawić ją w telefonie albo programie, opisuje strona
        <a href="<?= h(webmail_chooser_url()) ?>" target="_blank" rel="noopener noreferrer"><strong><?= h(webmail_chooser_label()) ?></strong><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>.
      </p>

      <?php if ($app_url): ?>
      <a href="<?= h($app_url) ?>/panel/index.php" class="btn btn-outline-secondary w-100" style="border-radius:10px">
        <i class="bi bi-house me-2" aria-hidden="true"></i>Wróć do panelu współpracownika
      </a>
      <?php endif; ?>

    </div>
  </div>

  <?php endif; ?>

  <footer>
    &copy; <?= date('Y') ?> <?= h($org_name) ?>. Dane chronione zgodnie z RODO.
  </footer>
</main>

<script>
function copyText(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  var text = el.textContent.trim();
  var done = function () {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden">Skopiowano</span>';
    btn.style.borderColor = '#16a34a';
    setTimeout(function() { btn.innerHTML = orig; btn.style.borderColor = ''; }, 1800);
  };
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(done).catch(fallback);
  } else {
    fallback();
  }
  function fallback() {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
    done();
  }
}
</script>
</body>
</html>
