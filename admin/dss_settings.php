<?php
/**
 * Ustawienia integracji EU DSS (Digital Signature Service).
 * Walidacja podpisów eIDAS: PAdES, XAdES, CAdES, ASiC.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/dss_client.php';

require_role('admin');

$PAGE_TITLE = 'Ustawienia EU DSS';

$settings_keys = ['dss_enabled', 'dss_url', 'dss_timeout'];

$msg = $msg_type = '';

// Test połączenia (AJAX)
if (($_GET['_action'] ?? '') === 'test' && is_ajax()) {
    header('Content-Type: application/json; charset=UTF-8');
    csrf_check_header();
    $test_url = trim($_GET['url'] ?? '');
    if ($test_url) {
        db()->prepare("INSERT INTO settings (key_, value) VALUES ('dss_url', ?)
            ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$test_url]);
    }
    echo json_encode(dss_test_connection(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values = [
        'dss_enabled' => isset($_POST['dss_enabled']) ? '1' : '0',
        'dss_url'     => rtrim(trim($_POST['dss_url'] ?? ''), '/'),
        'dss_timeout' => (string)max(5, min(120, (int)($_POST['dss_timeout'] ?? 30))),
    ];
    foreach ($values as $k => $v) {
        db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?)
            ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$k, $v]);
    }
    flash_set('success', 'Ustawienia EU DSS zapisane.');
    header('Location: dss_settings.php'); exit;
}

$cfg = [];
foreach ($settings_keys as $k) $cfg[$k] = org_setting($k);

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-shield-check text-primary me-2"></i>EU Digital Signature Service</h4>
  <a href="<?= APP_URL ?>/admin/ezd_settings.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left me-1"></i>Ustawienia EZD
  </a>
</div>

<?= flash_html() ?>

<div style="max-width:820px">

  <!-- Info -->
  <div class="alert alert-info d-flex gap-2 mb-4" style="font-size:.84rem">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>
      <strong>EU DSS</strong> (Digital Signature Service) to otwarte oprogramowanie Komisji Europejskiej
      do walidacji podpisów elektronicznych zgodnie z eIDAS: PAdES, XAdES, CAdES, ASiC.
      Sprawdza łańcuch zaufania przez europejskie listy zaufanych dostawców (LOTL).<br>
      <strong>Wymagany własny serwer DSS</strong> — uruchom przez:
      <code>docker compose -f docker-compose.yml -f docker-compose.dss.yml up -d dss</code><br>
      <a href="https://ec.europa.eu/digital-building-blocks/sites/display/DIGITAL/Digital+Signature+Service+-++DSS"
         target="_blank" rel="noopener" class="alert-link">Dokumentacja EU DSS ↗</a>
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <!-- Włącznik -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold"><i class="bi bi-toggles me-2 text-primary"></i>Ogólne</div>
      <div class="card-body">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="dss_enabled"
                 name="dss_enabled" <?= $cfg['dss_enabled'] === '1' ? 'checked' : '' ?>>
          <label class="form-check-label" for="dss_enabled">
            Włącz walidację EU DSS
          </label>
        </div>
        <div class="form-text mt-1">
          Gdy włączone, przycisk „Waliduj EU DSS" pojawia się przy każdym podpisanym załączniku EZD
          oraz na stronach widoku umów. Wymaga działającego serwera DSS (pole URL poniżej).
        </div>
      </div>
    </div>

    <!-- Połączenie -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold"><i class="bi bi-hdd-network me-2 text-primary"></i>Serwer DSS</div>
      <div class="card-body">

        <div class="mb-3">
          <label class="form-label fw-semibold mb-1" for="dss_url">URL serwera EU DSS</label>
          <div class="d-flex gap-2">
            <input type="url" class="form-control" id="dss_url" name="dss_url"
                   value="<?= h($cfg['dss_url']) ?>"
                   placeholder="http://localhost:8765" style="max-width:420px">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="dss-test-btn">
              <i class="bi bi-plug me-1"></i>Testuj połączenie
            </button>
          </div>
          <div class="form-text">
            Dev: <code>http://localhost:8765</code> &nbsp;·&nbsp;
            Prod (sieć Docker): <code>http://dss:8080</code>
          </div>
          <div id="dss-test-result" class="mt-2" style="font-size:.84rem"></div>
        </div>

        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label fw-semibold mb-1" for="dss_timeout">Timeout (s)</label>
            <input type="number" class="form-control" id="dss_timeout" name="dss_timeout"
                   value="<?= h($cfg['dss_timeout'] ?: '30') ?>" min="5" max="120" style="max-width:120px">
            <div class="form-text">5–120 sekund. Walidacja dużych plików lub pierwsza weryfikacja LOTL może trwać dłużej.</div>
          </div>
        </div>

      </div>
    </div>

    <!-- Presety URL -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold"><i class="bi bi-list-check me-2 text-primary"></i>Gotowe konfiguracje</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <button type="button" class="btn btn-sm btn-outline-secondary dss-preset"
                  data-url="http://localhost:8765"
                  title="Lokalny kontener Docker (docker-compose.dss.yml)">
            <i class="bi bi-box me-1"></i>Lokalny Docker (:8765)
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary dss-preset"
                  data-url="http://dss:8080"
                  title="Serwer DSS wewnątrz sieci Docker feer (nazwa usługi)">
            <i class="bi bi-diagram-3 me-1"></i>Sieć Docker (dss:8080)
          </button>
          <button type="button" class="btn btn-sm btn-outline-warning dss-preset"
                  data-url="https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo"
                  title="Demo EC — tylko do testów, nie do produkcji!">
            <i class="bi bi-cloud me-1"></i>Demo EC (tylko testy!)
          </button>
        </div>
        <div class="form-text text-warning">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Serwer demonstracyjny EC jest publiczny — nie przesyłaj tam rzeczywistych dokumentów!
        </div>
      </div>
    </div>

    <!-- Status aktualny -->
    <?php if ($cfg['dss_enabled'] === '1' && $cfg['dss_url']): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold"><i class="bi bi-activity me-2 text-primary"></i>Status</div>
      <div class="card-body">
        <?php $status = dss_test_connection(); ?>
        <?php if ($status['ok']): ?>
        <div class="d-flex align-items-center gap-2 text-success">
          <i class="bi bi-check-circle-fill"></i>
          <span>Połączono z <code><?= h($cfg['dss_url']) ?></code></span>
          <?php if ($status['version']): ?>
          <span class="badge bg-success">v<?= h($status['version']) ?></span>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="d-flex align-items-center gap-2 text-danger">
          <i class="bi bi-x-circle-fill"></i>
          <span><?= h($status['error']) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>Zapisz ustawienia
    </button>
  </form>

  <!-- Instrukcja uruchomienia -->
  <div class="card shadow-sm mt-4">
    <div class="card-header fw-semibold"><i class="bi bi-terminal me-2 text-primary"></i>Uruchomienie serwera DSS</div>
    <div class="card-body">
      <p class="text-muted mb-2" style="font-size:.83rem">
        Serwer EU DSS wymaga pierwszego budowania obrazu Docker (pobieranie JAR ~200 MB):
      </p>
      <pre class="bg-light border rounded p-3 mb-2" style="font-size:.78rem;overflow-x:auto"><code># Pierwsze uruchomienie (buduje obraz z GitHub Releases)
docker compose -f docker-compose.yml -f docker-compose.dss.yml up -d --build dss

# Status i logi
docker compose -f docker-compose.yml -f docker-compose.dss.yml ps dss
docker compose -f docker-compose.yml -f docker-compose.dss.yml logs -f dss

# DSS gotowy gdy widać:
# Started DssDemoApplication in X seconds
# Listening on port 8080</code></pre>
      <div class="form-text">
        Po pierwszym starcie DSS pobiera europejskie listy zaufania (LOTL) — może to zająć do 2 minut.
        Kolejne starty są szybsze dzięki wolumenowi <code>feer-dss-cache</code>.
      </div>
    </div>
  </div>

</div>

<script>
(function () {
  var csrfToken = <?= json_encode(csrf_token()) ?>;
  var appUrl    = <?= json_encode(APP_URL) ?>;

  // Presety URL
  document.querySelectorAll('.dss-preset').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('dss_url').value = this.dataset.url;
    });
  });

  // Test połączenia
  document.getElementById('dss-test-btn').addEventListener('click', function () {
    var btn = this;
    var url = document.getElementById('dss_url').value.trim();
    var res = document.getElementById('dss-test-result');
    if (!url) { res.innerHTML = '<span class="text-danger">Podaj URL serwera DSS.</span>'; return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testuję…';
    res.innerHTML = '';

    fetch(appUrl + '/admin/dss_settings.php?_action=test&url=' + encodeURIComponent(url), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          res.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Połączono'
            + (d.version ? ' · DSS v' + d.version : '') + '</span>';
        } else {
          res.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + (d.error || 'Błąd połączenia') + '</span>';
        }
      })
      .catch(function () { res.innerHTML = '<span class="text-danger">Błąd połączenia z panelem SZO.</span>'; })
      .finally(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i>Testuj połączenie';
      });
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
