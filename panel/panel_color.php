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

// Pobierz aktualny kolor
$user_row    = db_one("SELECT panel_color FROM users WHERE id=?", [(int)$cu['id']]);
$org_color   = org_setting('volunteer_color') ?: '#2563eb';
$saved_color = ($user_row['panel_color'] ?? '') ?: $org_color;

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['reset'])) {
        db()->prepare("UPDATE users SET panel_color = NULL WHERE id=?")->execute([(int)$cu['id']]);
        flash_set('success', 'Kolor panelu przywrócony do domyślnego organizacji.');
        header('Location: panel_color.php'); exit;
    }

    $color = trim($_POST['panel_color'] ?? '');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        flash_set('danger', 'Nieprawidłowy format koloru — użyj formatu #RRGGBB.');
        header('Location: panel_color.php'); exit;
    }

    db()->prepare("UPDATE users SET panel_color = ? WHERE id=?")->execute([$color, (int)$cu['id']]);
    flash_set('success', 'Kolor panelu zapisany!');
    header('Location: panel_color.php'); exit;
}

$PAGE_TITLE = 'Kolor panelu';
include __DIR__ . '/includes/header_panel.php';

// Helper do generowania jasnego tła (identyczny z header_panel.php)
if (!function_exists('_pv_light_bg')) {
    function _pv_light_bg(string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
        return sprintf('#%02X%02X%02X',
            (int)round($r*.1 + 255*.9),
            (int)round($g*.1 + 255*.9),
            (int)round($b*.1 + 255*.9)
        );
    }
}

$presets = [
    '#2563eb' => 'Niebieski',
    '#0f766e' => 'Turkusowy',
    '#7c3aed' => 'Fioletowy',
    '#dc2626' => 'Czerwony',
    '#16a34a' => 'Zielony',
    '#d97706' => 'Pomarańczowy',
    '#db2777' => 'Różowy',
    '#0891b2' => 'Cyjanowy',
    '#0078d4' => 'Microsoft Blue',
    '#374151' => 'Grafitowy',
    '#0f172a' => 'Granatowy',
    '#064e3b' => 'Butelkowa zieleń',
];

$initials = mb_strtoupper(mb_substr($cu['name'] ?? 'U', 0, 1));
?>

<!-- Nagłówek strony -->
<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:44px;height:44px;border-radius:12px;background:var(--vol-bg);
              display:flex;align-items:center;justify-content:center;
              font-size:1.3rem;color:var(--vol-color)">
    <i class="bi bi-palette2" aria-hidden="true"></i>
  </div>
  <div>
    <h1 class="mb-0 fw-bold" style="font-size:1.2rem">Kolor panelu</h1>
    <div class="text-muted small">Personalizuj kolor akcentu swojego widoku</div>
  </div>
</div>

<?php // flash messages są wyświetlane przez header_panel.php ?>

<div class="row g-4">

  <!-- ═══ Picker + presety ═══════════════════════════════════════════════════ -->
  <div class="col-lg-6">

    <!-- Formularz zapisu koloru -->
    <form method="post" id="colorSaveForm">
      <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
      <input type="hidden" name="panel_color" id="colorValue" value="<?= h($saved_color) ?>">

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header py-2 d-flex align-items-center gap-2"
             style="font-size:.85rem;font-weight:700">
          <i class="bi bi-palette me-1" style="color:var(--vol-color)"></i>
          Wybierz kolor
        </div>
        <div class="card-body">

          <!-- Color picker + wartość hex -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <input type="color" id="colorPicker" value="<?= h($saved_color) ?>"
                   aria-label="Picker koloru"
                   style="width:56px;height:48px;padding:3px;border-radius:10px;
                          border:1.5px solid #E5E7EB;cursor:pointer">
            <div>
              <div id="colorHexLabel"
                   style="font-size:1.1rem;font-family:monospace;font-weight:700;
                          color:<?= h($saved_color) ?>">
                <?= h($saved_color) ?>
              </div>
              <div class="text-muted small">Bieżący kolor</div>
            </div>
          </div>

          <!-- Presety -->
          <div class="mb-4">
            <div class="text-muted fw-semibold mb-2" style="font-size:.8rem">Szybki wybór:</div>
            <div class="d-flex flex-wrap gap-2" id="presetGrid" role="group"
                 aria-label="Gotowe kolory panelu">
              <?php foreach ($presets as $hex => $name): ?>
              <button type="button"
                      class="preset-btn"
                      data-color="<?= h($hex) ?>"
                      title="<?= h($name) ?>"
                      aria-label="<?= h($name) ?> (<?= h($hex) ?>)"
                      style="width:36px;height:36px;background:<?= h($hex) ?>;
                             border-radius:9px;
                             border:3px solid <?= $saved_color === $hex ? '#000' : 'transparent' ?>;
                             cursor:pointer;transition:transform .12s,border-color .12s;
                             flex-shrink:0;outline-offset:3px">
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Przyciski -->
          <div class="d-flex gap-2">
            <button type="submit" form="colorSaveForm"
                    class="btn btn-sm fw-semibold px-4"
                    style="background:var(--vol-color);color:#fff;border:none;border-radius:8px">
              <i class="bi bi-check-lg me-1"></i>Zapisz kolor
            </button>
          </div>

        </div>
      </div>
    </form>

    <!-- Formularz resetu — ODDZIELNY (brak zagnieżdżenia) -->
    <form method="post" id="colorResetForm">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="reset"  value="1">
      <button type="submit"
              class="btn btn-sm btn-outline-secondary w-100"
              style="border-radius:8px"
              onclick="return confirm('Przywrócić kolor domyślny organizacji?')">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć kolor domyślny
        <span class="text-muted ms-1" style="font-size:.8rem">(<?= h($org_color) ?>)</span>
      </button>
    </form>

  </div>

  <!-- ═══ Podgląd live ════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header py-2 d-flex align-items-center gap-2"
           style="font-size:.85rem;font-weight:700">
        <i class="bi bi-eye me-1"></i>Podgląd na żywo
      </div>
      <div class="card-body p-3">

        <!-- Mini-topbar -->
        <div id="pvTopbar"
             style="background:<?= h($saved_color) ?>;padding:10px 14px;
                    display:flex;align-items:center;gap:10px;
                    border-radius:10px 10px 0 0">
          <div style="width:22px;height:22px;background:rgba(255,255,255,.2);
                      border-radius:6px;flex-shrink:0"></div>
          <span style="color:#fff;font-weight:800;font-size:.85rem;flex:1">Panel</span>
          <div style="width:28px;height:28px;border-radius:50%;
                      background:rgba(255,255,255,.25);
                      display:flex;align-items:center;justify-content:center;
                      font-size:.65rem;color:#fff;font-weight:700">
            <?= h($initials) ?>
          </div>
        </div>

        <!-- Mini sidebar + content -->
        <div style="display:flex;background:#fff;border-radius:0 0 10px 10px;
                    overflow:hidden;border:1.5px solid #E5E7EB;border-top:none;
                    min-height:140px">
          <!-- Sidebar -->
          <div style="width:148px;border-right:1px solid #F0F0F0;padding:8px 6px;flex-shrink:0">
            <div id="pvNavActive"
                 style="background:<?= h(_pv_light_bg($saved_color)) ?>;
                        color:<?= h($saved_color) ?>;
                        border-left:3px solid <?= h($saved_color) ?>;
                        padding:5px 8px;border-radius:0 6px 6px 0;
                        font-size:.72rem;font-weight:700;margin-bottom:4px">
              <i class="bi bi-person-circle me-1"></i>Moja umowa
            </div>
            <div style="color:#9CA3AF;padding:5px 8px;font-size:.72rem">
              <i class="bi bi-headset me-1"></i>Helpdesk IT
            </div>
            <div style="color:#9CA3AF;padding:5px 8px;font-size:.72rem">
              <i class="bi bi-gear me-1"></i>Ustawienia
            </div>
          </div>
          <!-- Content -->
          <div style="flex:1;padding:12px;background:#F8FAFC">
            <div id="pvBtn"
                 style="display:inline-block;background:<?= h($saved_color) ?>;
                        color:#fff;padding:5px 14px;border-radius:7px;
                        font-size:.75rem;font-weight:600;margin-bottom:8px">
              Przykładowy przycisk
            </div>
            <div style="display:flex;gap:6px">
              <div id="pvBadge1"
                   style="background:<?= h(_pv_light_bg($saved_color)) ?>;
                          color:<?= h($saved_color) ?>;
                          border-radius:2rem;padding:2px 10px;font-size:.7rem;font-weight:600">
                Aktywne
              </div>
              <div style="background:#F3F4F6;color:#6B7280;border-radius:2rem;
                          padding:2px 10px;font-size:.7rem">
                Zakończone
              </div>
            </div>
          </div>
        </div>

        <div class="text-muted mt-2 text-center" style="font-size:.75rem">
          Podgląd aktualizuje się natychmiast po wyborze koloru
        </div>
      </div>
    </div>

    <!-- Informacja o aktualnym kolorze -->
    <div class="mt-3 p-3 rounded-3" style="background:#fff;border:1.5px solid #E5E7EB;font-size:.83rem">
      <div class="d-flex align-items-center gap-2">
        <div id="pvSwatch"
             style="width:28px;height:28px;border-radius:7px;
                    background:<?= h($saved_color) ?>;flex-shrink:0;
                    border:1px solid rgba(0,0,0,.1)"></div>
        <div>
          <div class="fw-semibold">Twój bieżący kolor</div>
          <div class="text-muted font-monospace" style="font-size:.8rem" id="pvSwatchLabel">
            <?= h($saved_color) ?>
          </div>
        </div>
        <?php if (($user_row['panel_color'] ?? '') === ''): ?>
        <span class="ms-auto badge bg-light text-secondary border" style="font-size:.72rem">
          domyślny organizacji
        </span>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>

<script>
(function(){
  var picker     = document.getElementById('colorPicker');
  var hidden     = document.getElementById('colorValue');
  var hexLabel   = document.getElementById('colorHexLabel');
  var presets    = document.querySelectorAll('.preset-btn');

  // Preview
  var pvTopbar    = document.getElementById('pvTopbar');
  var pvNavActive = document.getElementById('pvNavActive');
  var pvBtn       = document.getElementById('pvBtn');
  var pvBadge1    = document.getElementById('pvBadge1');
  var pvSwatch    = document.getElementById('pvSwatch');
  var pvSwLbl     = document.getElementById('pvSwatchLabel');

  function hexToRgb(hex) {
    hex = hex.replace('#','');
    return {
      r: parseInt(hex.slice(0,2),16),
      g: parseInt(hex.slice(2,4),16),
      b: parseInt(hex.slice(4,6),16)
    };
  }
  function lightBg(hex) {
    var c = hexToRgb(hex);
    return 'rgb('+Math.round(c.r*.1+255*.9)+','+Math.round(c.g*.1+255*.9)+','+Math.round(c.b*.1+255*.9)+')';
  }

  function applyColor(hex) {
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;

    // Update form + labels
    hidden.value             = hex;
    hexLabel.textContent     = hex;
    hexLabel.style.color     = hex;
    picker.value             = hex;
    pvSwLbl.textContent      = hex;
    pvSwatch.style.background= hex;

    // Update preview
    pvTopbar.style.background        = hex;
    pvNavActive.style.background     = lightBg(hex);
    pvNavActive.style.color          = hex;
    pvNavActive.style.borderLeftColor= hex;
    pvBtn.style.background           = hex;
    if (pvBadge1) {
      pvBadge1.style.background = lightBg(hex);
      pvBadge1.style.color      = hex;
    }

    // Highlight matching preset
    presets.forEach(function(b){
      b.style.borderColor = b.dataset.color.toLowerCase() === hex.toLowerCase() ? '#000' : 'transparent';
    });
  }

  picker.addEventListener('input', function(){ applyColor(this.value); });

  presets.forEach(function(btn){
    btn.addEventListener('click', function(){
      applyColor(this.dataset.color);
    });
    // Keyboard support
    btn.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        applyColor(this.dataset.color);
      }
    });
  });
})();
</script>
