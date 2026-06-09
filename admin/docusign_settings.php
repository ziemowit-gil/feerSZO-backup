<?php
/**
 * admin/docusign_settings.php — Konfiguracja integracji DocuSign eSign.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/docusign.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia DocuSign';

// ── Migracja bazy danych ──────────────────────────────────────────────────────
docusign_migrate();

// ── Odczyt ustawień ───────────────────────────────────────────────────────────
$settings = [
    'docusign_enabled'         => docusign_setting('docusign_enabled') ?: '0',
    'docusign_demo'            => docusign_setting('docusign_demo')    ?: '1',
    'docusign_integration_key' => docusign_setting('docusign_integration_key'),
    'docusign_account_id'      => docusign_setting('docusign_account_id'),
    'docusign_user_id'         => docusign_setting('docusign_user_id'),
    'docusign_rsa_private_key' => docusign_setting('docusign_rsa_private_key'),
    'docusign_webhook_key'     => docusign_setting('docusign_webhook_key'),
];

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    csrf_check();

    $save = [
        'docusign_enabled'         => isset($_POST['docusign_enabled']) ? '1' : '0',
        'docusign_demo'            => isset($_POST['docusign_demo'])    ? '1' : '0',
        'docusign_integration_key' => trim($_POST['docusign_integration_key'] ?? ''),
        'docusign_account_id'      => trim($_POST['docusign_account_id']      ?? ''),
        'docusign_user_id'         => trim($_POST['docusign_user_id']          ?? ''),
        'docusign_webhook_key'     => trim($_POST['docusign_webhook_key']      ?? ''),
    ];

    // Klucz RSA — zachowaj stary jeśli pole puste
    $rsa = trim($_POST['docusign_rsa_private_key'] ?? '');
    if ($rsa) {
        $save['docusign_rsa_private_key'] = $rsa;
    } else {
        unset($save['docusign_rsa_private_key']);
    }

    foreach ($save as $k => $v) {
        docusign_save_setting($k, $v);
    }

    $settings = array_merge($settings, $save);
    flash_set('success', 'Ustawienia DocuSign zapisane.');
    header('Location: docusign_settings.php'); exit;
}

// ── Test połączenia ───────────────────────────────────────────────────────────
$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_test'])) {
    csrf_check();
    try {
        $ds = new DocuSignClient();
        if (!$ds->is_configured()) {
            $test_result = ['ok' => false, 'msg' => 'Brakujące ustawienia (klucz, account_id, user_id lub RSA).'];
        } else {
            // Próba pobrania tokenu
            $status = $ds->get_status('dummy-test-call-will-fail');
            $test_result = ['ok' => true, 'msg' => 'Połączenie działa.'];
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'consent_required') || str_contains($msg, 'consent required')) {
            $ds2 = new DocuSignClient();
            $test_result = [
                'ok'  => false,
                'msg' => 'Wymagana zgoda użytkownika (consent). <a href="'
                    . h($ds2->consent_url()) . '" target="_blank" class="alert-link">Kliknij tutaj</a>'
                    . ', zaloguj się do DocuSign i zaakceptuj uprawnienia.',
            ];
        } elseif (str_contains($msg, 'ENVELOPE_DOES_NOT_EXIST') || str_contains($msg, '404')) {
            $test_result = ['ok' => true, 'msg' => 'Połączenie z DocuSign działa (token JWT poprawny).'];
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
    <li class="breadcrumb-item active">DocuSign</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-pen-fill me-2 text-primary"></i>Ustawienia DocuSign</h4>
  <?php if ($settings['docusign_enabled'] === '1' && $settings['docusign_integration_key']): ?>
  <span class="badge bg-success">Aktywne</span>
  <?php elseif ($settings['docusign_integration_key']): ?>
  <span class="badge bg-warning text-dark">Skonfigurowane — nieaktywne</span>
  <?php else: ?>
  <span class="badge bg-secondary">Nieskonfigurowane</span>
  <?php endif; ?>
  <?php if ($settings['docusign_demo'] === '1'): ?>
  <span class="badge bg-info text-dark">DEMO</span>
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
    <input class="form-check-input" type="checkbox" role="switch" name="docusign_enabled"
           id="docusign_enabled" value="1" <?= $settings['docusign_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="docusign_enabled">Integracja aktywna</label>
  </div>

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="docusign_demo"
           id="docusign_demo" value="1" <?= $settings['docusign_demo'] !== '0' ? 'checked' : '' ?>>
    <label class="form-check-label" for="docusign_demo">
      Środowisko demo (odznacz przed przejściem na produkcję)
    </label>
  </div>

  <hr>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Integration Key (Client ID)
      <span class="text-danger">*</span></label>
    <input type="text" name="docusign_integration_key" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['docusign_integration_key']) ?>"
           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
    <div class="form-text">Znajdziesz w DocuSign Dev Console → Apps & Keys → Integration Key.</div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Account ID (GUID)
      <span class="text-danger">*</span></label>
    <input type="text" name="docusign_account_id" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['docusign_account_id']) ?>"
           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
    <div class="form-text">DocuSign → profil użytkownika → Account → API Account ID.</div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">User ID (GUID)
      <span class="text-danger">*</span></label>
    <input type="text" name="docusign_user_id" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['docusign_user_id']) ?>"
           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
    <div class="form-text">DocuSign → profil użytkownika → API Username (to jest GUID).</div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Klucz prywatny RSA (PEM)
      <span class="text-danger">*</span></label>
    <textarea name="docusign_rsa_private_key" class="form-control form-control-sm font-monospace"
              rows="6" placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;...&#10;-----END RSA PRIVATE KEY-----"
              autocomplete="off"><?= $settings['docusign_rsa_private_key']
                ? '(zapisany — zostaw puste by nie zmieniać)' : '' ?></textarea>
    <div class="form-text">Generujesz w Dev Console → Apps & Keys → Add RSA Keypair. Wklej klucz prywatny.</div>
  </div>

  <div class="mb-4">
    <label class="form-label fw-semibold small">Klucz weryfikacji webhooka (HMAC)</label>
    <input type="text" name="docusign_webhook_key" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['docusign_webhook_key']) ?>"
           placeholder="Dowolny ciąg znaków — ustaw identycznie w DocuSign Connect">
    <div class="form-text">
      URL webhooka do ustawienia w DocuSign Connect:
      <code><?= h(APP_URL) ?>/api/docusign_webhook.php</code>
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
    <li class="mb-2">Zaloguj się do <a href="https://admindemo.docusign.com/" target="_blank">DocuSign Developer</a>
      (demo) lub <a href="https://app.docusign.com/" target="_blank">DocuSign</a> (produkcja).</li>
    <li class="mb-2">Przejdź do <strong>Settings → Apps &amp; Keys</strong> i utwórz nową aplikację
      (<em>Add App &amp; Integration Key</em>).</li>
    <li class="mb-2">W sekcji <strong>RSA Keypairs</strong> kliknij <em>Add RSA Keypair</em>.
      Skopiuj klucz prywatny i wklej go powyżej.</li>
    <li class="mb-2">Skopiuj <strong>Integration Key</strong> (UUID) do pola powyżej.</li>
    <li class="mb-2">Skopiuj <strong>API Account ID</strong> z profilu użytkownika.</li>
    <li class="mb-2">Skopiuj <strong>User GUID</strong> z profilu użytkownika.</li>
    <li class="mb-2">Kliknij <em>Testuj połączenie</em> — przy pierwszym uruchomieniu może być wymagana
      zgoda użytkownika (pojawi się link <em>consent</em>).</li>
    <li class="mb-2">Skonfiguruj <strong>DocuSign Connect</strong> (webhook):
      URL: <code><?= h(APP_URL) ?>/api/docusign_webhook.php</code>,
      zdarzenia: <em>Envelope Complete</em>, <em>Decline</em>, <em>Void</em>.</li>
  </ol>
</div>
</div>

<?php if ($settings['docusign_integration_key']): ?>
<div class="card shadow-sm mt-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Consent (zgoda JWT)</div>
<div class="card-body small">
  <p class="mb-2">Jeśli test pokazuje błąd <code>consent_required</code>, kliknij poniższy link,
    zaloguj się do DocuSign i zaakceptuj uprawnienia aplikacji:</p>
  <a href="<?= h((new DocuSignClient())->consent_url()) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-box-arrow-up-right"></i> Udziel zgody w DocuSign
  </a>
</div>
</div>
<?php endif; ?>
</div>

</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
