<?php
/**
 * admin/autenti_settings.php — Konfiguracja integracji Autenti eSign.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/autenti.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia Autenti';

autenti_migrate();

$settings = [
    'autenti_enabled'       => autenti_setting('autenti_enabled')       ?: '0',
    'autenti_sandbox'       => autenti_setting('autenti_sandbox')       ?: '1',
    'autenti_client_id'     => autenti_setting('autenti_client_id'),
    'autenti_client_secret' => autenti_setting('autenti_client_secret'),
    'autenti_webhook_secret'=> autenti_setting('autenti_webhook_secret'),
];

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    csrf_check();

    $save = [
        'autenti_enabled'        => isset($_POST['autenti_enabled']) ? '1' : '0',
        'autenti_sandbox'        => isset($_POST['autenti_sandbox']) ? '1' : '0',
        'autenti_client_id'      => trim($_POST['autenti_client_id']      ?? ''),
        'autenti_webhook_secret' => trim($_POST['autenti_webhook_secret'] ?? ''),
    ];

    // Secret — zachowaj stary jeśli pole puste
    $secret = trim($_POST['autenti_client_secret'] ?? '');
    if ($secret) {
        $save['autenti_client_secret'] = $secret;
    }

    foreach ($save as $k => $v) {
        autenti_save_setting($k, $v);
    }

    $settings = array_merge($settings, $save);
    flash_set('success', 'Ustawienia Autenti zapisane.');
    header('Location: autenti_settings.php'); exit;
}

// ── Test połączenia ───────────────────────────────────────────────────────────
$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_test'])) {
    csrf_check();
    try {
        $at = new AutentiClient();
        if (!$at->is_configured()) {
            $test_result = ['ok' => false, 'msg' => 'Brakujące ustawienia (Client ID lub Client Secret).'];
        } else {
            $status = $at->get_status('test-nieistniejacy-dokument');
            $test_result = ['ok' => true, 'msg' => 'Połączenie z Autenti działa.'];
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '404') || str_contains($msg, 'not found') || str_contains($msg, 'DOCUMENT_NOT_FOUND')) {
            $test_result = ['ok' => true, 'msg' => 'Połączenie z Autenti działa (token OAuth2 poprawny).'];
        } elseif (str_contains($msg, '401') || str_contains($msg, 'Unauthorized') || str_contains($msg, 'access_token')) {
            $test_result = ['ok' => false, 'msg' => 'Błąd autoryzacji — sprawdź Client ID i Client Secret.'];
        } else {
            $test_result = ['ok' => false, 'msg' => 'Błąd: ' . h($msg)];
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Autenti</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0">
    <i class="bi bi-pen-fill me-2 text-primary"></i>Ustawienia Autenti
  </h4>
  <?php if ($settings['autenti_enabled'] === '1' && $settings['autenti_client_id']): ?>
  <span class="badge bg-success">Aktywne</span>
  <?php elseif ($settings['autenti_client_id']): ?>
  <span class="badge bg-warning text-dark">Skonfigurowane — nieaktywne</span>
  <?php else: ?>
  <span class="badge bg-secondary">Nieskonfigurowane</span>
  <?php endif; ?>
  <?php if ($settings['autenti_sandbox'] === '1'): ?>
  <span class="badge bg-info text-dark">SANDBOX</span>
  <?php else: ?>
  <span class="badge bg-danger">PRODUKCJA</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($test_result !== null): ?>
<div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> mb-3">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle' : 'exclamation-triangle' ?>"></i>
  <?= $test_result['msg'] ?>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- Konfiguracja -->
<div class="col-lg-7">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Konfiguracja API</div>
<div class="card-body">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="autenti_enabled"
           id="autenti_enabled" value="1" <?= $settings['autenti_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="autenti_enabled">Integracja aktywna</label>
  </div>

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="autenti_sandbox"
           id="autenti_sandbox" value="1" <?= $settings['autenti_sandbox'] !== '0' ? 'checked' : '' ?>>
    <label class="form-check-label" for="autenti_sandbox">
      Środowisko sandbox (odznacz przed przejściem na produkcję)
    </label>
  </div>

  <hr>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Client ID <span class="text-danger">*</span></label>
    <input type="text" name="autenti_client_id" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['autenti_client_id']) ?>"
           placeholder="Twój Client ID z panelu Autenti">
    <div class="form-text">Znajdziesz w panelu Autenti → Ustawienia → Integracje → OAuth2.</div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Client Secret <span class="text-danger">*</span></label>
    <input type="password" name="autenti_client_secret" class="form-control form-control-sm font-monospace"
           autocomplete="new-password"
           placeholder="<?= $settings['autenti_client_secret'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Twój Client Secret' ?>">
    <div class="form-text">Znajdziesz w panelu Autenti → Ustawienia → Integracje → OAuth2.</div>
  </div>

  <div class="mb-4">
    <label class="form-label fw-semibold small">Klucz weryfikacji webhooka</label>
    <input type="text" name="autenti_webhook_secret" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['autenti_webhook_secret']) ?>"
           placeholder="Dowolny ciąg znaków — ustaw identycznie w panelu Autenti">
    <div class="form-text">
      URL webhooka do ustawienia w Autenti:
      <code><?= h(APP_URL) ?>/api/autenti_webhook.php</code>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" name="_save" class="btn btn-primary btn-sm">
      <i class="bi bi-floppy"></i> Zapisz
    </button>
    <button type="submit" name="_test" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-plug"></i> Testuj połączenie
    </button>
  </div>
</form>
</div>
</div>
</div>

<!-- Instrukcja -->
<div class="col-lg-5">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-book me-1"></i>Jak skonfigurować</div>
<div class="card-body small">
  <ol class="mb-0 ps-3">
    <li class="mb-2">Zaloguj się do
      <a href="https://app.sandbox.autenti.com" target="_blank">Autenti Sandbox</a>
      lub <a href="https://app.autenti.com" target="_blank">Autenti</a> (produkcja).</li>
    <li class="mb-2">Przejdź do <strong>Ustawienia → Integracje → API / OAuth2</strong>
      i utwórz nową aplikację.</li>
    <li class="mb-2">Skopiuj <strong>Client ID</strong> i <strong>Client Secret</strong>
      i wklej powyżej.</li>
    <li class="mb-2">Skonfiguruj webhook w Autenti:<br>
      URL: <code><?= h(APP_URL) ?>/api/autenti_webhook.php</code><br>
      Zdarzenia: <em>DOCUMENT_COMPLETED</em>, <em>DOCUMENT_DECLINED</em>,
      <em>DOCUMENT_CANCELLED</em>, <em>DOCUMENT_EXPIRED</em>.</li>
    <li class="mb-2">Jeśli ustawiasz klucz webhooka — wpisz ten sam ciąg w polu
      powyżej i w konfiguracji webhooka w Autenti.</li>
    <li class="mb-2">Kliknij <em>Testuj połączenie</em> — powinien pojawić się
      komunikat o poprawnym tokenie OAuth2.</li>
  </ol>
</div>
</div>

<div class="card shadow-sm mt-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Statusy dokumentów</div>
<div class="card-body small">
  <table class="table table-sm mb-0">
    <tbody>
      <?php foreach (AUTENTI_STATUS_LABELS as $s => $l): ?>
      <tr>
        <td><span class="badge <?= AUTENTI_STATUS_BADGES[$s] ?>"><?= h($l) ?></span></td>
        <td class="text-muted font-monospace"><?= h($s) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</div>
</div>

</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
