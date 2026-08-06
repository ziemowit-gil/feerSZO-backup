<?php
/**
 * reports/oplacalnosc.php — Kalkulator opłacalności w module Raporty.
 *
 * Osadzona wersja kalkulatora bez własnej nawigacji — używa renderera
 * z tools/oplacalnosc.php (ta sama funkcja _opl_render_result).
 * Dostęp: każdy zalogowany użytkownik z uprawnieniami do raportów.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/oplacalnosc.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('reports_enabled', 'Moduł zestawień');

$PAGE_TITLE = 'Kalkulator opłacalności działań';

/* ── AJAX ────────────────────────────────────────────────────────────────── */
$is_ajax = (($_GET['_ajax'] ?? '') === '1')
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p    = _opl_rp_params();
    $errs = oplacalnosc_validate($p);
    if ($errs) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'errors' => $errs]); exit; }
    $r = oplacalnosc_oblicz($p);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'html' => _opl_render_result($r)]);
    exit;
}

/* ── POST ────────────────────────────────────────────────────────────────── */
$result = null;
$errors = [];
$params = _opl_rp_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $params = _opl_rp_params();
    $errors = oplacalnosc_validate($params);
    if (!$errors) {
        $result = oplacalnosc_oblicz($params);
    }
}

/* ── Pomocnicze ──────────────────────────────────────────────────────────── */

function _opl_rp_defaults(): array {
    return ['tryb' => 'online', 'przychod' => '', 'czas_pracy' => '',
            'stawka' => '', 'czas_dojazdu' => '', 'bilety' => '', 'model_zus' => 'zlecenie'];
}

function _opl_rp_params(): array {
    $c = fn(string $k) => trim($_POST[$k] ?? '');
    return [
        'tryb'         => $c('tryb') === 'wyjazdowy' ? 'wyjazdowy' : 'online',
        'przychod'     => str_replace(',', '.', $c('przychod')),
        'czas_pracy'   => str_replace(',', '.', $c('czas_pracy')),
        'stawka'       => str_replace(',', '.', $c('stawka')),
        'czas_dojazdu' => str_replace(',', '.', $c('czas_dojazdu')),
        'bilety'       => str_replace(',', '.', $c('bilety')),
        'model_zus'    => array_key_exists($c('model_zus'), OPLACALNOSC_TAX_MODELS) ? $c('model_zus') : 'zlecenie',
    ];
}

function _opl_field(string $label, float $amount, string $class = ''): string {
    $cls = $class ? " {$class}" : '';
    return '<tr><td class="text-muted">' . htmlspecialchars($label) . '</td>'
         . '<td class="text-end fw-semibold' . $cls . '">' . money($amount) . '</td></tr>';
}

/* ══ HTML ════════════════════════════════════════════════════════════════════ */
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/reports/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Raporty
  </a>
  <h4 class="mb-0">
    <i class="bi bi-calculator text-primary"></i>
    <?= htmlspecialchars($PAGE_TITLE) ?>
  </h4>
</div>

<div class="row g-4">
  <!-- Formularz -->
  <div class="col-lg-4 col-xl-3">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">Parametry</div>
      <div class="card-body">
        <?php if ($errors): ?>
          <div class="alert alert-danger py-2 small">
            <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
          </div>
        <?php endif; ?>

        <form method="post" id="oplRpForm" autocomplete="off">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold small">Tryb</label>
            <div class="btn-group w-100 btn-group-sm" role="group">
              <input type="radio" class="btn-check" name="tryb" id="rp_online"
                     value="online" <?= $params['tryb'] === 'online' ? 'checked' : '' ?>>
              <label class="btn btn-outline-primary" for="rp_online">Online</label>
              <input type="radio" class="btn-check" name="tryb" id="rp_wyjazd"
                     value="wyjazdowy" <?= $params['tryb'] === 'wyjazdowy' ? 'checked' : '' ?>>
              <label class="btn btn-outline-primary" for="rp_wyjazd">Wyjazdowy</label>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label small">Przychód (PLN)</label>
            <input type="number" name="przychod" class="form-control form-control-sm"
                   min="0" step="0.01" placeholder="2000"
                   value="<?= h($params['przychod']) ?>" required>
          </div>

          <div class="mb-2">
            <label class="form-label small">Czas pracy (h)</label>
            <input type="number" name="czas_pracy" class="form-control form-control-sm"
                   min="0" step="0.25" placeholder="4"
                   value="<?= h($params['czas_pracy']) ?>" required>
          </div>

          <div id="rpWyjazdFields" class="<?= $params['tryb'] === 'wyjazdowy' ? '' : 'd-none' ?>">
            <div class="mb-2">
              <label class="form-label small">Stawka robocza (zł/h)</label>
              <input type="number" name="stawka" class="form-control form-control-sm"
                     min="0" step="0.5" placeholder="80"
                     value="<?= h($params['stawka']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small">Czas dojazdu (h)</label>
              <input type="number" name="czas_dojazdu" class="form-control form-control-sm"
                     min="0" step="0.25" placeholder="1.5"
                     value="<?= h($params['czas_dojazdu']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small">Bilety (PLN)</label>
              <input type="number" name="bilety" class="form-control form-control-sm"
                     min="0" step="0.01" placeholder="0"
                     value="<?= h($params['bilety']) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small">Model obciążeń</label>
            <select name="model_zus" class="form-select form-select-sm">
              <?php foreach (OPLACALNOSC_TAX_MODELS as $k => $m): ?>
                <option value="<?= $k ?>" <?= $params['model_zus'] === $k ? 'selected' : '' ?>>
                  <?= h($m['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-graph-up-arrow me-1"></i> Oblicz
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Wyniki -->
  <div class="col-lg-8 col-xl-9" id="rpResultCol">
    <?php if ($result): ?>
      <?= _opl_render_result($result) ?>
    <?php else: ?>
      <div class="card shadow-sm d-flex align-items-center justify-content-center text-muted"
           style="min-height:280px" id="rpPlaceholder">
        <div class="text-center p-4">
          <i class="bi bi-calculator display-6 opacity-50"></i>
          <p class="mt-3 mb-0">Wypełnij formularz i kliknij <strong>Oblicz</strong>.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  const form    = document.getElementById('oplRpForm');
  const wFields = document.getElementById('rpWyjazdFields');
  const radios  = form.querySelectorAll('input[name="tryb"]');
  const res     = document.getElementById('rpResultCol');

  function syncMode() {
    const isW = form.tryb.value === 'wyjazdowy';
    wFields.classList.toggle('d-none', !isW);
    ['stawka','czas_dojazdu'].forEach(n => {
      const el = form.elements[n]; if (el) el.required = isW;
    });
  }
  radios.forEach(r => r.addEventListener('change', syncMode));
  syncMode();

  let timer;
  function liveCalc() {
    const przychod   = parseFloat(form.przychod.value);
    const czas_pracy = parseFloat(form.czas_pracy.value);
    if (isNaN(przychod) || przychod < 0 || isNaN(czas_pracy) || czas_pracy <= 0) return;
    if (form.tryb.value === 'wyjazdowy' && !(parseFloat(form.stawka.value) > 0)) return;

    const fd = new FormData(form);
    fetch('?_ajax=1', { method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest'}, body: fd })
      .then(r => r.json())
      .then(d => { if (d.ok) { document.getElementById('rpPlaceholder')?.remove(); res.innerHTML = d.html; } })
      .catch(() => {});
  }

  form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(liveCalc, 600); });
  form.querySelectorAll('select').forEach(s => s.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(liveCalc, 600); }));
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
