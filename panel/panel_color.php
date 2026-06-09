<?php
/**
 * panel/panel_color.php — Kolor panelu wolontariusza (per-user)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
$cu = current_user();

// Migracja: kolumna panel_color w users
try { db()->exec("ALTER TABLE users ADD COLUMN panel_color TEXT"); } catch (\Throwable $e) {}

// Pobierz aktualny kolor użytkownika i org fallback
$user_row    = db_one("SELECT panel_color FROM users WHERE id=?", [(int)$cu['id']]);
$org_color   = org_setting('volunteer_color') ?: '#2563eb';
$saved_color = ($user_row['panel_color'] ?? '') ?: $org_color;

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['reset'])) {
        db()->prepare("UPDATE users SET panel_color = NULL WHERE id=?")->execute([(int)$cu['id']]);
        flash_set('success', 'Kolor panelu przywrócony do domyślnego.');
        header('Location: panel_color.php'); exit;
    }

    $color = trim($_POST['panel_color'] ?? '');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $error = 'Nieprawidłowy format koloru.';
    } else {
        db()->prepare("UPDATE users SET panel_color = ? WHERE id=?")->execute([$color, (int)$cu['id']]);
        flash_set('success', 'Kolor panelu został zapisany.');
        header('Location: panel_color.php'); exit;
    }
}

$PAGE_TITLE = 'Kolor panelu';
include __DIR__ . '/includes/header_panel.php';
?>

<div class="pv-shell">
<div class="pv-content">

<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:44px;height:44px;border-radius:12px;background:var(--vol-bg);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--vol-color)">
    <i class="bi bi-palette2"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Kolor panelu</h4>
    <div class="text-muted small">Dostosuj kolor akcentu swojego widoku</div>
  </div>
</div>

<?= flash_html() ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-4">

  <!-- Picker -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header py-2 fw-semibold" style="font-size:.88rem">
        <i class="bi bi-palette me-1" style="color:var(--vol-color)"></i>Wybierz kolor
      </div>
      <div class="card-body">
        <form method="post" id="colorForm">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="panel_color" id="colorValue" value="<?= h($saved_color) ?>">

          <!-- Picker + current hex -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <input type="color" id="colorPicker" value="<?= h($saved_color) ?>"
                   style="width:54px;height:44px;padding:2px;border-radius:10px;border:1px solid #dee2e6;cursor:pointer">
            <div>
              <div class="fw-semibold" id="colorHexLabel" style="font-size:1.05rem;font-family:monospace;color:var(--vol-color)"><?= h($saved_color) ?></div>
              <div class="text-muted small">Aktualny kolor</div>
            </div>
          </div>

          <!-- Presety -->
          <div class="mb-3">
            <div class="text-muted small fw-semibold mb-2">Szybki wybór:</div>
            <div class="d-flex flex-wrap gap-2" id="presetGrid">
              <?php foreach ([
                '#2563eb' => 'Niebieski',
                '#0f766e' => 'Turkusowy',
                '#7c3aed' => 'Fioletowy',
                '#dc2626' => 'Czerwony',
                '#16a34a' => 'Zielony',
                '#d97706' => 'Pomarańczowy',
                '#db2777' => 'Różowy',
                '#0891b2' => 'Cyjanowy',
                '#374151' => 'Grafitowy',
                '#0f172a' => 'Czarny',
              ] as $hex => $name): ?>
              <button type="button"
                      class="preset-btn"
                      data-color="<?= h($hex) ?>"
                      title="<?= h($name) ?>"
                      style="width:34px;height:34px;background:<?= h($hex) ?>;
                             border-radius:8px;border:2px solid <?= $saved_color === $hex ? '#000' : 'transparent' ?>;
                             cursor:pointer;transition:transform .12s,border-color .12s;flex-shrink:0">
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-sm px-3 fw-semibold"
                    style="background:var(--vol-color);color:#fff;border:none">
              <i class="bi bi-check-lg me-1"></i>Zapisz
            </button>
            <form method="post" class="d-inline" id="resetForm">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="reset" value="1">
              <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślny
              </button>
            </form>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Podgląd -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header py-2 fw-semibold" style="font-size:.88rem">
        <i class="bi bi-eye me-1"></i>Podgląd
      </div>
      <div class="card-body p-3">
        <div id="pvPreview" style="border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)">
          <!-- Topbar preview -->
          <div id="pvTopbar" style="background:<?= h($saved_color) ?>;padding:10px 16px;display:flex;align-items:center;gap:12px">
            <div style="width:22px;height:22px;background:rgba(255,255,255,.2);border-radius:5px;flex-shrink:0"></div>
            <span style="color:#fff;font-weight:800;font-size:.88rem;flex:1">Panel</span>
            <div style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#fff;font-weight:700">
              <?= h(mb_strtoupper(mb_substr($cu['name'] ?? 'U', 0, 1))) ?>
            </div>
          </div>
          <!-- Sidebar + content preview -->
          <div style="display:flex;background:#fff;min-height:120px">
            <div style="width:140px;border-right:1px solid #f0f0f0;padding:8px 6px;flex-shrink:0">
              <div id="pvNavActive" style="background:<?= h(_pv_light_bg($saved_color)) ?>;color:<?= h($saved_color) ?>;border-left:3px solid <?= h($saved_color) ?>;padding:5px 8px;border-radius:0 6px 6px 0;font-size:.72rem;font-weight:700;margin-bottom:4px">
                <i class="bi bi-person-circle me-1"></i>Moja umowa
              </div>
              <div style="color:#6b7280;padding:5px 8px;font-size:.72rem">
                <i class="bi bi-clock-history me-1"></i>Ewidencja
              </div>
              <div style="color:#6b7280;padding:5px 8px;font-size:.72rem">
                <i class="bi bi-gear me-1"></i>Ustawienia
              </div>
            </div>
            <div style="flex:1;padding:12px;background:#f8fafc">
              <div id="pvBtn" style="display:inline-block;background:<?= h($saved_color) ?>;color:#fff;padding:5px 14px;border-radius:7px;font-size:.75rem;font-weight:600">
                Przykładowy przycisk
              </div>
            </div>
          </div>
        </div>
        <div class="text-muted small mt-2 text-center">Podgląd zmieniany na żywo</div>
      </div>
    </div>
  </div>

</div><!-- /row -->

</div><!-- /pv-content -->
<?php include __DIR__ . '/includes/footer_panel.php'; ?>
</div><!-- /pv-shell -->

<script>
(function(){
  var picker   = document.getElementById('colorPicker');
  var hidden   = document.getElementById('colorValue');
  var hexLabel = document.getElementById('colorHexLabel');
  var presets  = document.querySelectorAll('.preset-btn');

  // Preview elements
  var pvTopbar    = document.getElementById('pvTopbar');
  var pvNavActive = document.getElementById('pvNavActive');
  var pvBtn       = document.getElementById('pvBtn');

  function lightBg(hex) {
    hex = hex.replace('#','');
    var r = parseInt(hex.slice(0,2),16), g = parseInt(hex.slice(2,4),16), b = parseInt(hex.slice(4,6),16);
    var mix = function(c){ return Math.round(c*.1 + 255*.9); };
    return 'rgb('+mix(r)+','+mix(g)+','+mix(b)+')';
  }

  function applyColor(hex) {
    hidden.value   = hex;
    hexLabel.textContent = hex;
    hexLabel.style.color = hex;
    picker.value   = hex;

    pvTopbar.style.background    = hex;
    pvNavActive.style.background = lightBg(hex);
    pvNavActive.style.color      = hex;
    pvNavActive.style.borderLeftColor = hex;
    pvBtn.style.background       = hex;

    presets.forEach(function(b){
      b.style.borderColor = b.dataset.color === hex ? '#000' : 'transparent';
    });
  }

  picker.addEventListener('input', function(){ applyColor(this.value); });

  presets.forEach(function(btn){
    btn.addEventListener('click', function(){ applyColor(this.dataset.color); });
  });
})();
</script>
