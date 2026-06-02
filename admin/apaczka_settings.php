<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/apaczka.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia Apaczka';
$success = ''; $errors = []; $test_result = null;

// ── Zapis ustawień ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $fields = [
            'apaczka_app_id', 'apaczka_app_secret',
            'apaczka_sender_name', 'apaczka_sender_line1', 'apaczka_sender_line2',
            'apaczka_sender_postal', 'apaczka_sender_city',
            'apaczka_sender_email', 'apaczka_sender_phone',
            'apaczka_default_service_id',
        ];
        foreach ($fields as $f) {
            apaczka_save($f, trim($_POST[$f] ?? ''));
        }
        // Toggle modułu
        apaczka_save('apaczka_enabled', isset($_POST['apaczka_enabled']) ? '1' : '0');
        $success = 'Ustawienia zapisane.';
    }

    if ($action === 'test') {
        try {
            $g = new Apaczka();
            if (!$g->is_configured()) throw new RuntimeException('Brak app_id lub app_secret.');
            $resp = $g->service_structure();
            if (($resp['status'] ?? 0) === 200) {
                $services = $resp['response']['services'] ?? [];
                // Cache services list in settings
                apaczka_save('apaczka_services_cache', json_encode($services));
                apaczka_save('apaczka_services_cache_at', (string)time());
                $test_result = ['ok' => true, 'services' => $services];
            } else {
                throw new RuntimeException($resp['message'] ?? 'Nieznany błąd API.');
            }
        } catch (\Throwable $e) {
            $test_result = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    if ($action === 'save' && !$errors) {
        flash_set('success', $success);
        header('Location: ' . APP_URL . '/admin/apaczka_settings.php'); exit;
    }
}

// Wczytaj zapisane usługi z cache
$cached_services = json_decode(apaczka_setting('apaczka_services_cache') ?: '[]', true) ?: [];
$cached_at       = (int)apaczka_setting('apaczka_services_cache_at');

include dirname(__DIR__) . '/includes/header.php';
echo flash_html();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-box-seam text-primary me-2"></i>Apaczka — ustawienia API</h4>
  <a href="<?= APP_URL ?>/admin/shipments.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Przesyłki
  </a>
</div>

<?php if ($test_result): ?>
<?php if ($test_result['ok']): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle-fill me-2"></i>
  <strong>Połączenie z API działa!</strong>
  Dostępne usługi: <?= count($test_result['services']) ?>
</div>
<?php else: ?>
<div class="alert alert-danger">
  <i class="bi bi-x-circle-fill me-2"></i>
  <strong>Błąd połączenia:</strong> <?= h($test_result['error']) ?>
</div>
<?php endif; ?>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" value="save">

<div class="row g-4">
<div class="col-lg-7">

  <!-- Włącz moduł -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-toggle-on me-2 text-primary"></i>Moduł przesyłek</div>
    <div class="card-body">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="apaczka_enabled" id="apaczka_enabled" value="1"
               <?= apaczka_setting('apaczka_enabled') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="apaczka_enabled">
          Włącz moduł wysyłki (Apaczka)
        </label>
      </div>
      <div class="form-text">Gdy wyłączony — zakładka Przesyłki nie jest widoczna dla wolontariuszy ani w menu admina.</div>
    </div>
  </div>

  <!-- API credentials -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-key me-2 text-primary"></i>Dane API</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold small">App ID <span class="text-danger">*</span></label>
          <input name="apaczka_app_id" class="form-control font-monospace"
                 value="<?= h(apaczka_setting('apaczka_app_id')) ?>"
                 placeholder="np. 12345">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">App Secret <span class="text-danger">*</span></label>
          <div class="input-group">
            <input name="apaczka_app_secret" type="password" id="apaczkaSecret"
                   class="form-control font-monospace"
                   value="<?= h(apaczka_setting('apaczka_app_secret')) ?>"
                   placeholder="••••••••••••••••">
            <button type="button" class="btn btn-outline-secondary"
                    onclick="var i=document.getElementById('apaczkaSecret');i.type=i.type==='password'?'text':'password'">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="form-text">
            Wygeneruj klucze w panelu Apaczka:
            <a href="https://panel.apaczka.pl/" target="_blank">panel.apaczka.pl</a>
            → Integracje → API
          </div>
        </div>
        <div class="col-12">
          <button type="submit" name="_action" value="test" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-wifi me-1"></i>Testuj połączenie i pobierz usługi
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Adres nadawcy -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-building me-2 text-primary"></i>Adres nadawcy (FEER)</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold small">Nazwa</label>
          <input name="apaczka_sender_name" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_name') ?: ORG_NAME) ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label fw-semibold small">Ulica i numer</label>
          <input name="apaczka_sender_line1" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_line1')) ?>" placeholder="ul. Przykładowa 1">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Lokal / piętro</label>
          <input name="apaczka_sender_line2" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_line2')) ?>" placeholder="m. 5">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Kod pocztowy</label>
          <input name="apaczka_sender_postal" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_postal')) ?>" placeholder="00-001">
        </div>
        <div class="col-md-8">
          <label class="form-label fw-semibold small">Miasto</label>
          <input name="apaczka_sender_city" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_city')) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small">E-mail kontaktowy</label>
          <input name="apaczka_sender_email" type="email" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_email')) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small">Telefon</label>
          <input name="apaczka_sender_phone" class="form-control"
                 value="<?= h(apaczka_setting('apaczka_sender_phone')) ?>" placeholder="48123456789">
        </div>
      </div>
    </div>
  </div>

</div>
<div class="col-lg-5">

  <!-- Domyślna usługa -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-truck me-2 text-primary"></i>Domyślna usługa</div>
    <div class="card-body">
      <?php
      $def_sid = apaczka_setting('apaczka_default_service_id');
      if ($cached_services):
      ?>
      <label class="form-label fw-semibold small">Wybierz domyślną usługę kurierską</label>
      <select name="apaczka_default_service_id" class="form-select">
        <option value="">— brak domyślnej —</option>
        <?php foreach ($cached_services as $svc):
          $sel = (string)($svc['service_id'] ?? '') === (string)$def_sid ? 'selected' : '';
        ?>
        <option value="<?= h($svc['service_id']) ?>" <?= $sel ?>>
          <?= h($svc['name'] ?? '') ?> — <?= h($svc['supplier'] ?? '') ?>
          <?php if (!empty($svc['door_to_door']) && $svc['door_to_door'] === '1'): ?>(D2D)<?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php if ($cached_at): ?>
      <div class="form-text">Cache usług z: <?= date('d.m.Y H:i', $cached_at) ?></div>
      <?php endif; ?>
      <?php else: ?>
      <div class="alert alert-secondary py-2 small mb-2">
        <i class="bi bi-info-circle me-1"></i>
        Najpierw przetestuj połączenie — pobierze listę dostępnych usług.
      </div>
      <input name="apaczka_default_service_id" class="form-control font-monospace"
             value="<?= h($def_sid) ?>" placeholder="np. 1 (ID usługi z API)">
      <?php endif; ?>
    </div>
  </div>

  <!-- Dostępne usługi (cache) -->
  <?php if ($cached_services): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold small"><i class="bi bi-list-ul me-2"></i>Dostępne usługi (<?= count($cached_services) ?>)</div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0 small">
        <thead class="table-light"><tr><th>ID</th><th>Nazwa</th><th>Przewoźnik</th><th>Typ</th></tr></thead>
        <tbody>
        <?php foreach ($cached_services as $svc): ?>
        <tr>
          <td class="font-monospace"><?= h($svc['service_id']) ?></td>
          <td><?= h($svc['name']) ?></td>
          <td><?= h($svc['supplier']) ?></td>
          <td>
            <?php
            $types = [];
            if (($svc['door_to_door'] ?? '0') === '1') $types[] = 'D2D';
            if (($svc['door_to_point'] ?? '0') === '1') $types[] = 'D2P';
            if (($svc['point_to_point'] ?? '0') === '1') $types[] = 'P2P';
            if (($svc['point_to_door'] ?? '0') === '1') $types[] = 'P2D';
            echo implode(', ', $types) ?: '—';
            ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Zapis -->
  <div class="d-grid gap-2">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
    </button>
  </div>

</div>
</div>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
