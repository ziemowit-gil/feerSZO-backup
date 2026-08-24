<?php
/**
 * crm/webmail.php — Webmail (Roundcube) osadzony w shellu CRM.
 *
 * Generuje SSO token podpisany HMAC-SHA256, przekazywany do Roundcube
 * jako ?_crm_token=xxx. Plugin feer_crm waliduje token i inicjuje OAuth.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

$PAGE_TITLE = 'CRM — Webmail';

// Bez skonfigurowanego Roundcube'a strona pokazywała pustą ramkę wskazującą na
// localhost:8880 — wyglądało to na awarię. Skrzynka CRM robi dziś to samo
// zadanie (widoki Odebrane/Wysłane/Kopie robocze), więc tam odsyłamy.
$rc_conf = trim((string)crm_setting('roundcube_url'));
if ($rc_conf === '') {
    flash_set('info', 'Webmail (Roundcube) nie jest skonfigurowany — korespondencję prowadzisz w Skrzynce CRM.');
    header('Location: ' . APP_URL . '/crm/inbox.php');
    exit;
}
$rc_base = rtrim($rc_conf, '/');

// ── Generuj SSO token (ważny 90s) ────────────────────────────────────────────
$current_user  = current_user();
$user_email    = $current_user['email'] ?? '';
$sso_secret    = db_one("SELECT value FROM settings WHERE key_ = 'roundcube_sso_secret'")['value'] ?? '';

$rc_src = $rc_base . '/';

if ($user_email && $sso_secret) {
    $expiry = time() + 90;
    $payload = $user_email . '|' . $expiry;
    $sig   = hash_hmac('sha256', $payload, $sso_secret);
    $token = base64_encode($payload . '|' . $sig);
    $token = strtr($token, '+/', '-_');  // URL-safe base64
    $rc_src = $rc_base . '/?_crm_token=' . urlencode($token);
}

// ── Adres do compose (z aktywnego kontaktu) ───────────────────────────────────
$compose_to = trim($_GET['compose_to'] ?? '');
if ($compose_to) {
    $rc_src = $rc_base . '/?_task=mail&_action=compose&_to=' . urlencode($compose_to);
    if ($user_email && $sso_secret) {
        $rc_src .= '&_crm_token=' . urlencode($token ?? '');
    }
}

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.crm-webmail-wrap {
    display: flex;
    flex-direction: column;
    height: calc(100vh - var(--crm-topbar-h, 56px) - 1rem);
    min-height: 500px;
}
.crm-webmail-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .4rem .75rem;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px 8px 0 0;
    font-size: .82rem;
    gap: .75rem;
}
.crm-webmail-frame {
    flex: 1;
    width: 100%;
    border: 1px solid #E5E7EB;
    border-top: none;
    border-radius: 0 0 8px 8px;
    background: #fff;
}
#crm-wm-status { font-size: .75rem; color: #9CA3AF; display: flex; align-items: center; gap: .4rem }
</style>

<div class="crm-webmail-wrap">

  <!-- Pasek narzędziowy -->
  <div class="crm-webmail-bar">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-envelope-at-fill" style="color:var(--crm-primary);font-size:1.1rem" aria-hidden="true"></i>
      <span class="fw-semibold">FEER Webmail</span>
      <?php if ($user_email): ?>
      <span class="text-muted">&lt;<?= h($user_email) ?>&gt;</span>
      <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-2">
      <div id="crm-wm-status">
        <span class="spinner-border spinner-border-sm" role="status" id="crm-wm-spinner"
              aria-label="Ładowanie…"></span>
        <span id="crm-wm-status-text">Ładowanie…</span>
      </div>
      <a href="<?= h($rc_base . '/') ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-outline-secondary py-0 px-2"
         title="Otwórz w nowej karcie">
        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
      </a>
      <button type="button"
              class="btn btn-sm btn-outline-secondary py-0 px-2"
              onclick="document.getElementById('crm-wm-frame').src = document.getElementById('crm-wm-frame').src"
              title="Odśwież">
        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
      </button>
    </div>
  </div>

  <!-- Iframe -->
  <iframe id="crm-wm-frame"
          src="<?= h($rc_src) ?>"
          class="crm-webmail-frame"
          title="FEER Webmail — Roundcube"
          allow="clipboard-read; clipboard-write"
          aria-label="Webmail FEER"></iframe>

</div>

<script>
(function () {
  var frame   = document.getElementById('crm-wm-frame');
  var spinner = document.getElementById('crm-wm-spinner');
  var status  = document.getElementById('crm-wm-status-text');

  frame.addEventListener('load', function () {
    spinner.style.display = 'none';
    status.textContent = 'Załadowano';
    setTimeout(function () {
      document.getElementById('crm-wm-status').style.opacity = '0';
    }, 1500);
  });

  frame.addEventListener('error', function () {
    spinner.style.display = 'none';
    status.textContent = 'Błąd ładowania';
    status.style.color = '#E31010';
  });
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
