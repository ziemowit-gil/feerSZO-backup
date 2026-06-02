<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/furgonetka.php';

require_role('admin');
$PAGE_TITLE = 'Furgonetka — ustawienia';

$test_result = null;

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_credentials') {
        furgonetka_save('furgonetka_client_id',     trim($_POST['furgonetka_client_id']     ?? ''));
        furgonetka_save('furgonetka_client_secret', trim($_POST['furgonetka_client_secret'] ?? ''));
        furgonetka_save('furgonetka_enabled',        isset($_POST['furgonetka_enabled']) ? '1' : '0');
        flash_set('success', 'Dane API Furgonetka zostały zapisane.');
        header('Location: ' . APP_URL . '/admin/furgonetka_settings.php'); exit;
    }

    if ($action === 'test_connection') {
        try {
            $api = new Furgonetka();
            if (!$api->is_configured()) {
                throw new RuntimeException('Brak client_id lub client_secret — uzupełnij dane API i zapisz.');
            }
            $services = $api->services();
            furgonetka_save('furgonetka_services_cache',    json_encode($services));
            furgonetka_save('furgonetka_services_cache_at', (string)time());
            $test_result = ['ok' => true, 'services' => $services];
        } catch (\Throwable $e) {
            $test_result = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    if ($action === 'save_sender') {
        $sender_fields = [
            'furgonetka_sender_name',
            'furgonetka_sender_company',
            'furgonetka_sender_street',
            'furgonetka_sender_postal',
            'furgonetka_sender_city',
            'furgonetka_sender_email',
            'furgonetka_sender_phone',
        ];
        foreach ($sender_fields as $f) {
            furgonetka_save($f, trim($_POST[$f] ?? ''));
        }
        flash_set('success', 'Dane nadawcy zostały zapisane.');
        header('Location: ' . APP_URL . '/admin/furgonetka_settings.php'); exit;
    }

    if ($action === 'save_defaults') {
        furgonetka_save('furgonetka_service_code', trim($_POST['furgonetka_service_code'] ?? ''));
        flash_set('success', 'Domyślna usługa została zapisana.');
        header('Location: ' . APP_URL . '/admin/furgonetka_settings.php'); exit;
    }
}

// ── Load cached services ──────────────────────────────────────────────────────
$cached_services = json_decode(furgonetka_setting('furgonetka_services_cache') ?: '[]', true) ?: [];
$cached_at       = (int)furgonetka_setting('furgonetka_services_cache_at');

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0 fw-bold">
    <i class="bi bi-truck text-primary me-2"></i>Furgonetka — ustawienia
  </h4>
  <a href="<?= APP_URL ?>/admin/shipments.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Przesyłki
  </a>
</div>

<?= flash_html() ?>

<?php if ($test_result !== null): ?>
<?php if ($test_result['ok']): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle-fill me-2"></i>
  <strong>Połączenie z API działa!</strong>
  Pobrano <?= count($test_result['services']) ?> usług. Lista zaktualizowana poniżej.
</div>
<?php else: ?>
<div class="alert alert-danger">
  <i class="bi bi-x-circle-fill me-2"></i>
  <strong>Błąd połączenia:</strong> <?= h($test_result['error']) ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-7">

  <!-- Karta 1: Dane API ────────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-key me-2 text-primary"></i>Dane API (OAuth2)
    </div>
    <div class="card-body">

      <!-- Włącz moduł + credentials w jednym formularzu -->
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_credentials">

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch"
                 name="furgonetka_enabled" id="furgonetka_enabled" value="1"
                 <?= furgonetka_setting('furgonetka_enabled') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="furgonetka_enabled">
            Włącz moduł wysyłki (Furgonetka)
          </label>
          <div class="form-text">Gdy wyłączony — zakładka Przesyłki ukrywa opcje Furgonetki.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="furgonetka_client_id">
            Client ID <span class="text-danger">*</span>
          </label>
          <input type="text" name="furgonetka_client_id" id="furgonetka_client_id"
                 class="form-control font-monospace"
                 value="<?= h(furgonetka_setting('furgonetka_client_id')) ?>"
                 placeholder="np. abc123">
          <div class="form-text">
            Znajdziesz go w panelu Furgonetka:
            <a href="https://furgonetka.pl/logowanie" target="_blank" rel="noopener">furgonetka.pl</a>
            → Ustawienia → API
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="furgonetkaSecret">
            Client Secret <span class="text-danger">*</span>
          </label>
          <div class="input-group">
            <input type="password" name="furgonetka_client_secret" id="furgonetkaSecret"
                   class="form-control font-monospace"
                   value="<?= h(furgonetka_setting('furgonetka_client_secret')) ?>"
                   placeholder="••••••••••••••••">
            <button type="button" class="btn btn-outline-secondary"
                    onclick="var i=document.getElementById('furgonetkaSecret');i.type=i.type==='password'?'text':'password'">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm me-2">
          <i class="bi bi-floppy me-1"></i>Zapisz dane API
        </button>
      </form>

      <hr class="my-3">

      <!-- Test połączenia — osobny formularz -->
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="test_connection">
        <button type="submit" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-wifi me-1"></i>Testuj połączenie i pobierz usługi
        </button>
      </form>

      <?php if ($cached_services): ?>
      <div class="mt-3">
        <p class="small text-muted mb-2">
          <i class="bi bi-clock me-1"></i>
          Cache usług z: <?= date('d.m.Y H:i', $cached_at) ?>
          (<?= count($cached_services) ?> usług)
        </p>
        <div class="table-responsive">
          <table class="table table-sm table-bordered small mb-0">
            <thead class="table-light">
              <tr>
                <th>Kod usługi</th>
                <th>Nazwa</th>
                <th>Przewoźnik</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($cached_services as $svc): ?>
            <tr>
              <td class="font-monospace"><?= h($svc['code'] ?? $svc['service_code'] ?? '') ?></td>
              <td><?= h($svc['name'] ?? '') ?></td>
              <td><?= h($svc['carrier'] ?? $svc['courier'] ?? $svc['supplier'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Karta 2: Dane nadawcy ────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-building me-2 text-primary"></i>Dane nadawcy (FEER)
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_sender">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold small" for="furgonetka_sender_name">Imię i nazwisko</label>
            <input type="text" name="furgonetka_sender_name" id="furgonetka_sender_name"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_name') ?: ORG_NAME) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small" for="furgonetka_sender_company">Nazwa firmy / organizacji</label>
            <input type="text" name="furgonetka_sender_company" id="furgonetka_sender_company"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_company') ?: ORG_NAME) ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small" for="furgonetka_sender_street">Ulica i numer</label>
            <input type="text" name="furgonetka_sender_street" id="furgonetka_sender_street"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_street')) ?>"
                   placeholder="ul. Przykładowa 1">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small" for="furgonetka_sender_postal">Kod pocztowy</label>
            <input type="text" name="furgonetka_sender_postal" id="furgonetka_sender_postal"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_postal')) ?>"
                   placeholder="00-001">
          </div>
          <div class="col-md-8">
            <label class="form-label fw-semibold small" for="furgonetka_sender_city">Miasto</label>
            <input type="text" name="furgonetka_sender_city" id="furgonetka_sender_city"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_city')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small" for="furgonetka_sender_email">E-mail kontaktowy</label>
            <input type="email" name="furgonetka_sender_email" id="furgonetka_sender_email"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_email')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small" for="furgonetka_sender_phone">Telefon</label>
            <input type="text" name="furgonetka_sender_phone" id="furgonetka_sender_phone"
                   class="form-control"
                   value="<?= h(furgonetka_setting('furgonetka_sender_phone')) ?>"
                   placeholder="48123456789">
            <div class="form-text">Format: numer bez spacji, np. 48123456789.</div>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-floppy me-1"></i>Zapisz dane nadawcy
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

</div><!-- /col-lg-7 -->
<div class="col-lg-5">

  <!-- Karta 3: Domyślna usługa ─────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-truck-front me-2 text-primary"></i>Domyślna usługa
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_defaults">

        <?php
        $def_code = furgonetka_setting('furgonetka_service_code');
        if ($cached_services):
        ?>
        <label class="form-label fw-semibold small" for="furgonetka_service_code">
          Domyślna usługa kurierska
        </label>
        <select name="furgonetka_service_code" id="furgonetka_service_code" class="form-select mb-2">
          <option value="">— brak domyślnej —</option>
          <?php foreach ($cached_services as $svc):
            $code    = $svc['code'] ?? $svc['service_code'] ?? '';
            $label   = $svc['name'] ?? $code;
            $carrier = $svc['carrier'] ?? $svc['courier'] ?? $svc['supplier'] ?? '';
            $sel     = ($code === $def_code) ? 'selected' : '';
          ?>
          <option value="<?= h($code) ?>" <?= $sel ?>>
            <?= h($label) ?><?= $carrier !== '' ? ' — ' . h($carrier) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if ($cached_at): ?>
        <div class="form-text mb-3">Cache z: <?= date('d.m.Y H:i', $cached_at) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="alert alert-secondary py-2 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Najpierw przetestuj połączenie — zostanie pobrana lista dostępnych usług.
        </div>
        <label class="form-label fw-semibold small" for="furgonetka_service_code">
          Kod usługi (ręcznie)
        </label>
        <input type="text" name="furgonetka_service_code" id="furgonetka_service_code"
               class="form-control font-monospace mb-2"
               value="<?= h($def_code) ?>"
               placeholder="np. inpost_courier">
        <div class="form-text mb-3">Kod usługi zwracany przez API Furgonetka (np. <code>inpost_courier</code>).</div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-floppy me-1"></i>Zapisz
        </button>
      </form>
    </div>
  </div>

</div><!-- /col-lg-5 -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
