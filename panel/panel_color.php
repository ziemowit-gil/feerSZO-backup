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

// Migracja: kolumny motywu
try { db()->exec("ALTER TABLE users ADD COLUMN panel_color TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN panel_theme TEXT"); } catch (\Throwable $e) {}

// Pobierz aktualny kolor i motyw
$user_row    = db_one("SELECT panel_color, panel_theme FROM users WHERE id=?", [(int)$cu['id']]);
$org_color   = org_setting('volunteer_color') ?: '#2563eb';
$saved_color = ($user_row['panel_color'] ?? '') ?: $org_color;
$saved_theme = $user_row['panel_theme'] ?? '';

// ─── POST — zmiana wersji kontrastowej ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme_action'])) {
    csrf_check();
    $ok_themes = ['', 'light', 'dark', 'hc'];
    $t = trim($_POST['panel_theme'] ?? '');
    if (!in_array($t, $ok_themes, true)) {
        flash_set('danger', 'Nieprawidłowa wersja kontrastowa.');
    } else {
        db()->prepare("UPDATE users SET panel_theme = ? WHERE id=?")->execute([$t ?: null, (int)$cu['id']]);
        flash_set('success', 'Wersja kontrastowa zapisana!');
    }
    header('Location: panel_color.php'); exit;
}

// ─── POST — kolor ─────────────────────────────────────────────────────────────
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

$PAGE_TITLE = 'Motyw i kontrast';
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

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-palette" aria-hidden="true"></i>Motyw i kontrast</h1>
    <p class="pv-page-sub">Dostosuj kolor akcentowy i wersję kontrastową panelu</p>
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

      <div class="tz-card mb-3">
        <div class="tz-card__hd">
          <i class="bi bi-palette me-1" aria-hidden="true"></i>
          Wybierz kolor
        </div>
        <div class="tz-card__bd">

          <!-- Color picker + wartość hex -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <label for="colorPicker" class="visually-hidden">Kolor akcentowy panelu</label>
            <input type="color" id="colorPicker" value="<?= h($saved_color) ?>"
                   style="width:56px;height:48px;padding:3px;border-radius:10px;cursor:pointer">
            <div>
              <div id="colorHexLabel"
                   class="font-monospace fw-bold fs-5"
                   style="color:<?= h($saved_color) ?>">
                <?= h($saved_color) ?>
              </div>
              <div class="text-muted small">Bieżący kolor</div>
            </div>
          </div>

          <!-- Presety -->
          <div class="mb-4">
            <div class="text-muted fw-semibold mb-2 small">Szybki wybór:</div>
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
            <button type="submit" form="colorSaveForm" class="tz-btn">
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
              class="tz-btn tz-btn--ghost w-100"
              onclick="return confirm('Przywrócić kolor domyślny organizacji?')">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć kolor domyślny
        <span class="text-muted ms-1 small">(<?= h($org_color) ?>)</span>
      </button>
    </form>

  </div>

  <!-- ═══ Podgląd live ════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="tz-card">
      <div class="tz-card__hd">
        <i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd na żywo
      </div>
      <div class="tz-card__bd p-3">

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
                      font-size:.65rem;color:#fff;font-weight:700"
               aria-hidden="true">
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

        <p class="text-muted mt-2 text-center small">
          Podgląd aktualizuje się natychmiast po wyborze koloru
        </p>
      </div>
    </div>

    <!-- Informacja o aktualnym kolorze -->
    <div class="tz-card mt-3">
      <div class="tz-card__bd">
        <div class="d-flex align-items-center gap-2">
          <div id="pvSwatch"
               style="width:28px;height:28px;border-radius:7px;
                      background:<?= h($saved_color) ?>;flex-shrink:0"
               aria-label="Podgląd bieżącego koloru: <?= h($saved_color) ?>"></div>
          <div>
            <div class="fw-semibold">Twój bieżący kolor</div>
            <div class="text-muted font-monospace small" id="pvSwatchLabel">
              <?= h($saved_color) ?>
            </div>
          </div>
          <?php if (($user_row['panel_color'] ?? '') === ''): ?>
          <span class="ms-auto badge bg-light text-secondary border small">
            domyślny organizacji
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ═══ Wersja kontrastowa ════════════════════════════════════════════════════ -->
<style>
.theme-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem}
@media(min-width:600px){.theme-grid{grid-template-columns:repeat(4,1fr)}}
.theme-card{display:flex;flex-direction:column;align-items:center;text-align:center;
  border:2px solid var(--tz-line);border-radius:14px;padding:.9rem .7rem .75rem;
  cursor:pointer;transition:border-color .12s,background .12s;position:relative}
.theme-card:hover{border-color:var(--tz)}
.theme-card--active{border-color:var(--tz);background:var(--tz-50)}
.theme-preview{width:100%;height:56px;border-radius:10px;margin-bottom:.65rem;overflow:hidden;
  border:1.5px solid var(--tz-line);position:relative;flex-shrink:0}
.theme-preview--system{background:linear-gradient(135deg,#f4f6f9 50%,#0f172a 50%)}
.theme-preview--light{background:#f4f6f9}
.theme-preview--dark{background:#0f172a}
.theme-preview--hc{background:#fff;border:2px solid #000}
/* Pasek nawigacji w miniaturce */
.theme-preview::before{content:'';position:absolute;top:0;left:0;right:0;height:14px}
.theme-preview--system::before{background:linear-gradient(90deg,<?= h($saved_color) ?> 50%,<?= h($saved_color) ?> 50%)}
.theme-preview--light::before{background:<?= h($saved_color) ?>}
.theme-preview--dark::before{background:<?= h($saved_color) ?>}
.theme-preview--hc::before{background:#000}
/* Kafelki w miniaturce */
.theme-preview::after{content:'';position:absolute;top:21px;left:5px;right:5px;height:8px;border-radius:3px}
.theme-preview--light::after{background:#dde3ee}
.theme-preview--dark::after{background:#1e2535}
.theme-preview--hc::after{background:#000;border-radius:2px}
.theme-preview--system::after{background:linear-gradient(90deg,#dde3ee 50%,#1e2535 50%)}
.theme-card__ico{font-size:1.2rem;color:var(--tz);margin-bottom:.25rem}
.theme-card__name{font-weight:700;font-size:.9rem;color:var(--tz-ink);line-height:1.2}
.theme-card__desc{font-size:.7rem;color:var(--tz-muted);margin-top:.2rem;line-height:1.35}
.theme-card__check{position:absolute;top:.45rem;right:.45rem;width:20px;height:20px;
  border-radius:50%;background:var(--tz);color:#fff;font-size:.7rem;
  display:flex;align-items:center;justify-content:center}
</style>
<form method="post" id="themeForm" class="mt-2">
  <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
  <input type="hidden" name="theme_action" value="1">

  <div class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-circle-half me-1" aria-hidden="true"></i>
      Wersja kontrastowa
    </div>
    <div class="tz-card__bd">
      <p class="text-muted small mb-3">
        Wybierz sposób wyświetlania panelu — jasno, ciemno lub z bardzo wysokim kontrastem (WCAG&nbsp;AA+).
      </p>

      <div class="theme-grid" role="radiogroup" aria-label="Wersja kontrastowa panelu">

        <?php
        $themes = [
            ''      => ['ico' => 'bi-circle-half',      'name' => 'Systemowy',       'desc' => 'Podąża za ustawieniami systemu operacyjnego'],
            'light' => ['ico' => 'bi-sun',               'name' => 'Jasny',           'desc' => 'Białe tło, standardowy kontrast'],
            'dark'  => ['ico' => 'bi-moon-stars',        'name' => 'Ciemny',          'desc' => 'Ciemne tło, mniej zmęczenia oczu'],
            'hc'    => ['ico' => 'bi-universal-access',  'name' => 'Wysoki kontrast', 'desc' => 'Maksymalny kontrast, WCAG AA+'],
        ];
        foreach ($themes as $val => $th):
            $is_active = ($saved_theme === $val);
        ?>
        <label class="theme-card<?= $is_active ? ' theme-card--active' : '' ?>">
          <input type="radio" name="panel_theme" value="<?= h($val) ?>"
                 class="visually-hidden"
                 <?= $is_active ? 'checked' : '' ?>>
          <div class="theme-preview theme-preview--<?= h($val ?: 'system') ?>"
               aria-hidden="true"></div>
          <i class="bi <?= h($th['ico']) ?> theme-card__ico" aria-hidden="true"></i>
          <span class="theme-card__name"><?= h($th['name']) ?></span>
          <span class="theme-card__desc"><?= h($th['desc']) ?></span>
          <?php if ($is_active): ?>
          <span class="theme-card__check" aria-label="Aktywny motyw"><i class="bi bi-check-lg"></i></span>
          <?php endif; ?>
        </label>
        <?php endforeach; ?>

      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="tz-btn">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz wersję kontrastową
        </button>
      </div>
    </div>
  </div>
</form>

</div><!-- .pv-wrap -->

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

// Podgląd na żywo zmiany motywu
(function(){
  var radios = document.querySelectorAll('[name="panel_theme"]');
  var html   = document.documentElement;
  radios.forEach(function(r){
    r.addEventListener('change', function(){
      if (this.value) {
        html.setAttribute('data-theme', this.value);
      } else {
        html.removeAttribute('data-theme');
      }
      // Zaznacz aktywną kartę
      document.querySelectorAll('.theme-card').forEach(function(c){ c.classList.remove('theme-card--active'); });
      this.closest('.theme-card').classList.add('theme-card--active');
    });
  });
})();
</script>
