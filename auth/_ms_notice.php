<?php
/**
 * auth/_ms_notice.php — strona dla wejść na auth/microsoft.php, które nie są
 * odpowiedzią na logowanie SZO (dołączana z auth/microsoft.php, patrz $_ms_notice):
 *  • popup selektora plików OneDrive (?oauth=… / #access_token=…) — ładuje
 *    OneDrive.js v7.2, który sam przekierowuje do Microsoftu i oddaje token
 *    oknu-rodzicowi (Zadania, CRM),
 *  • goły adres — zalogowany widzi „jesteś już zalogowany/a jako …” z przyciskiem
 *    dalej, niezalogowany — prośbę o ponowne zalogowanie.
 * Niczego nie loguje i nie zmienia sesji.
 */
if (!defined('APP_URL')) { http_response_code(404); exit; }

$_n_user  = current_user();
$_n_name  = trim((string)($_n_user['name'] ?? ''));
$_n_email = trim((string)($_n_user['email'] ?? ''));
$_n_next  = auth_default_landing();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $_n_user ? 'Jesteś zalogowany/a' : 'Logowanie Microsoft 365' ?> — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background:#f0f4f8}
.card{max-width:480px;margin:100px auto}
.ms-badge{width:64px;height:64px;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;margin-bottom:.75rem}
</style>
</head>
<body>
<main class="card shadow-sm">
<div class="card-body p-4">

  <!-- Popup selektora OneDrive — widoczne tylko podczas przekazywania logowania -->
  <div id="od-wait" class="text-center" role="status" aria-live="polite" hidden>
    <div class="spinner-border text-primary mb-3" aria-hidden="true"></div>
    <h1 class="h5 fw-bold mb-1">Łączenie z OneDrive…</h1>
    <p class="text-muted small mb-0" id="od-wait-msg">To okno zamknie się samo po wybraniu konta Microsoft.</p>
  </div>

  <div id="ms-notice">
  <?php if ($_n_user): ?>
    <div class="text-center mb-3">
      <div class="ms-badge" style="background:#DCFCE7" aria-hidden="true">
        <i class="bi bi-person-check-fill text-success"></i>
      </div>
      <h1 class="h5 fw-bold mb-1">Jesteś już zalogowany/a</h1>
      <p class="text-muted small mb-0">
        Jako <strong><?= h($_n_name !== '' ? $_n_name : $_n_email) ?></strong>
        <?php if ($_n_name !== '' && $_n_email !== ''): ?><br><span><?= h($_n_email) ?></span><?php endif; ?>
      </p>
    </div>
    <div class="d-grid gap-2">
      <a href="<?= h($_n_next) ?>" class="btn btn-primary" id="ms-next">
        <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Przejdź dalej
      </a>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="ms-close" hidden>
        <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Zamknij to okno
      </button>
    </div>
  <?php else: ?>
    <div class="text-center mb-3">
      <div class="ms-badge" style="background:#DBEAFE" aria-hidden="true">
        <i class="bi bi-microsoft text-primary"></i>
      </div>
      <h1 class="h5 fw-bold mb-1">Zaloguj się ponownie</h1>
      <p class="text-muted small mb-0">
        Logowanie przez Microsoft 365 nie zostało dokończone albo sesja wygasła.
        Rozpocznij logowanie jeszcze raz.
      </p>
    </div>
    <div class="d-grid gap-2">
      <a href="<?= h(APP_URL) ?>/auth/login.php" class="btn btn-primary">
        <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Przejdź do logowania
      </a>
    </div>
  <?php endif; ?>
  </div>

</div>
</main>
<script>
(function () {
  var q = location.search, hs = location.hash;
  // Ten sam warunek co OneDrive.js (onAuth): ?oauth=… albo access_token/error + state.
  var od = /[?&]oauth=/.test(q)
        || (/(?:[#?&])(access_token|error)=/.test(q + hs) && /(?:[#?&])state=/.test(q + hs));
  var close = document.getElementById('ms-close');
  if (window.opener && close) {
    close.hidden = false;
    close.addEventListener('click', function () { window.close(); });
  }
  if (!od || !window.opener) return;
  document.getElementById('ms-notice').hidden = true;
  document.getElementById('od-wait').hidden = false;
  var s = document.createElement('script');
  s.src = 'https://js.live.net/v7.2/OneDrive.js';
  s.onerror = function () {
    document.getElementById('od-wait').querySelector('.spinner-border').hidden = true;
    document.getElementById('od-wait-msg').textContent =
      'Nie udało się załadować OneDrive. Zamknij to okno i spróbuj ponownie.';
  };
  document.head.appendChild(s);
})();
</script>
</body>
</html>
