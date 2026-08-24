<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/modules_catalog.php';

require_role('admin');
$PAGE_TITLE = 'Moduły';

$module_groups = modules_catalog();

// Wczytaj aktualne wartości
$cfg = [];
foreach ($module_groups as $group_modules) {
    foreach ($group_modules as $k => $_) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
        $cfg[$k] = ($r['value'] ?? '1');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($module_groups as $group_modules) {
        foreach ($group_modules as $k => $_) {
            $v = isset($_POST[$k]) ? '1' : '0';
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
            }
            $cfg[$k] = $v;
        }
    }
    log_system_action((int)current_user()['id'], 'settings_save', 'Ustawienia modułów zapisane.');
    flash_set('success', 'Ustawienia modułów zapisane.');
    header('Location: modules_settings.php'); exit;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0"><i class="bi bi-toggles2 text-primary me-2"></i>Moduły</h4>
</div>
<?= flash_html() ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-4">
<?php
$_group_icons = [
    'Moduły systemu'               => 'bi-grid-3x3-gap',
    'Integracje i bezpieczeństwo'  => 'bi-plug',
    'Typy umów'                    => 'bi-file-earmark-text',
];
$_group_index = 0;
foreach ($module_groups as $group_name => $group_modules):
    $group_key     = preg_replace('/[^a-z0-9]/i', '_', strtolower($group_name));
    $group_icon    = $_group_icons[$group_name] ?? 'bi-grid-3x3-gap';
    $enabled_count = array_sum(array_map(fn($k) => $cfg[$k] === '1' ? 1 : 0, array_keys($group_modules)));
    $total_count   = count($group_modules);
    $_group_index++;
?>
<div class="col-lg-6">
  <div class="card shadow-sm h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span class="fw-semibold">
        <i class="bi <?= $group_icon ?> me-1 text-primary"></i>
        <?= h($group_name) ?>
      </span>
      <span class="badge bg-<?= $enabled_count === $total_count ? 'success' : ($enabled_count === 0 ? 'secondary' : 'warning text-dark') ?>">
        <?= $enabled_count ?>/<?= $total_count ?> aktywnych
      </span>
    </div>
    <div class="card-body p-0">
      <?php foreach ($group_modules as $k => $m):
        $on = $cfg[$k] === '1';
      ?>
      <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom module-row<?= $on ? '' : ' module-row-off' ?>"
           data-module="<?= $k ?>">
        <div class="form-check form-switch mb-0 flex-shrink-0">
          <input class="form-check-input module-toggle" type="checkbox" role="switch"
                 name="<?= $k ?>" id="mod_<?= $k ?>" value="1"
                 <?= $on ? 'checked' : '' ?>
                 onchange="updateRow(this)">
        </div>
        <label class="flex-grow-1 mb-0" for="mod_<?= $k ?>" style="cursor:pointer">
          <div class="fw-semibold d-flex align-items-center gap-1" style="font-size:.88rem">
            <i class="bi <?= $m['icon'] ?> text-<?= $on ? 'primary' : 'secondary' ?> mod-icon"></i>
            <?= h($m['label']) ?>
            <?php if (!empty($m['config']) && $on): ?>
            <a href="<?= APP_URL ?>/admin/<?= h($m['config']) ?>"
               class="ms-1 text-muted" style="font-size:.7rem;font-weight:400"
               onclick="event.stopPropagation()">
              <i class="bi bi-gear-fill"></i> Konfiguruj
            </a>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.75rem;line-height:1.3"><?= h($m['desc']) ?></div>
        </label>
        <span class="badge bg-<?= $on ? 'success' : 'secondary' ?> flex-shrink-0 mod-badge" style="font-size:.68rem;min-width:58px;text-align:center">
          <?= $on ? 'Aktywny' : 'Wyłączony' ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer d-flex gap-2 py-2">
      <button type="button" class="btn btn-outline-secondary btn-sm"
              onclick="toggleGroup(<?= $_group_index - 1 ?>, true)">
        <i class="bi bi-check-all me-1"></i>Włącz wszystkie
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm"
              onclick="toggleGroup(<?= $_group_index - 1 ?>, false)">
        <i class="bi bi-dash-square me-1"></i>Wyłącz wszystkie
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<div class="mt-4">
  <button type="submit" class="btn btn-primary px-4">
    <i class="bi bi-floppy me-1"></i> Zapisz ustawienia
  </button>
  <span class="text-muted small ms-3">
    <i class="bi bi-info-circle"></i>
    Wyłączone moduły są ukryte w nawigacji. Bezpośredni dostęp przez URL jest blokowany.
  </span>
</div>
</form>

<style>
.module-row { transition: background .15s; }
.module-row-off { background: #f8fafc; }
.module-row-off .fw-semibold { color: #94a3b8 !important; }
.module-row-off .text-muted { opacity: .7; }
</style>
<script>
function updateRow(checkbox) {
    var row   = checkbox.closest('.module-row');
    var badge = row.querySelector('.mod-badge');
    var icon  = row.querySelector('.mod-icon');
    var on    = checkbox.checked;
    row.classList.toggle('module-row-off', !on);
    if (badge) {
        badge.textContent = on ? 'Aktywny' : 'Wyłączony';
        badge.className   = 'badge flex-shrink-0 mod-badge ' + (on ? 'bg-success' : 'bg-secondary');
        badge.style.fontSize   = '.68rem';
        badge.style.minWidth   = '58px';
        badge.style.textAlign  = 'center';
    }
    if (icon) {
        icon.className = icon.className.replace(/text-\w+/, 'text-' + (on ? 'primary' : 'secondary'));
    }
    // Update group counter
    var card  = checkbox.closest('.card');
    var rows  = card.querySelectorAll('.module-toggle');
    var total = rows.length;
    var enab  = Array.from(rows).filter(c => c.checked).length;
    var gbadge = card.querySelector('.card-header .badge');
    if (gbadge) {
        gbadge.textContent = enab + '/' + total + ' aktywnych';
        gbadge.className   = 'badge bg-' + (enab === total ? 'success' : (enab === 0 ? 'secondary' : 'warning text-dark'));
    }
}

function toggleGroup(idx, enable) {
    var cards = document.querySelectorAll('.col-lg-6');
    var card  = cards[idx];
    if (!card) return;
    card.querySelectorAll('.module-toggle').forEach(function(cb) {
        if (cb.checked !== enable) {
            cb.checked = enable;
            updateRow(cb);
        }
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
