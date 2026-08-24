<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Konfiguracja menu';

// ── Definicje ──────────────────────────────────────────────────────────────────

// Pozycje menu admina/edytora (ukrywanie — nie widać w sidebarze)
$ADMIN_MENU = [
    'grants'   => ['label' => 'Granty',          'icon' => 'bi-cash-coin',      'section' => 'Finanse i zasoby'],
    'actions'  => ['label' => 'Działania',        'icon' => 'bi-calendar-event', 'section' => 'Finanse i zasoby'],
];

// Pozycje panelu wolontariusza (ukrywanie + wyłączanie z redirect)
$PANEL_ITEMS = [
    'komunikaty'    => ['label' => 'Komunikaty',           'icon' => 'bi-megaphone',        'page' => 'komunikaty/index.php'],
    'katalog'       => ['label' => 'Książka telefoniczna', 'icon' => 'bi-person-lines-fill','page' => 'directory/'],
    'wiadomosci'    => ['label' => 'Wiadomości',           'icon' => 'bi-chat-left-text',   'page' => 'panel/messages.php'],
    'zasady'        => ['label' => 'Zasady organizacji',   'icon' => 'bi-building-heart',   'page' => 'org_intro/index.php'],
    'asystent'      => ['label' => 'Asystent AI',          'icon' => 'bi-stars',            'page' => 'panel/asystent.php'],
    'kursy'         => ['label' => 'Moje kursy (Moodle)',  'icon' => 'bi-mortarboard',      'page' => 'panel/moodle.php'],
    'pisma'         => ['label' => 'Moje pisma',           'icon' => 'bi-archive',          'page' => 'panel/letters.php'],
    'wnioski'       => ['label' => 'Wyślij wniosek/pismo', 'icon' => 'bi-send',             'page' => 'panel/apply.php'],
    'zaswiadczenia' => ['label' => 'Zaświadczenia',        'icon' => 'bi-award',            'page' => 'panel/certificates.php'],
    'rozwiazanie'   => ['label' => 'Rozwiązanie umowy',    'icon' => 'bi-file-earmark-x',   'page' => 'panel/terminations.php'],
    'godziny'       => ['label' => 'Ewidencja godzin',     'icon' => 'bi-clock-history',    'page' => 'panel/timesheets.php'],
    'przesylki'     => ['label' => 'Przesyłki',            'icon' => 'bi-box-seam',         'page' => 'panel/shipments.php'],
    'zwroty'        => ['label' => 'Zwroty kosztów',       'icon' => 'bi-receipt-cutoff',   'page' => 'panel/zwroty.php'],
];

// ── POST ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tab = $_POST['_tab'] ?? 'admin';
    $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value");

    if ($tab === 'admin') {
        foreach ($ADMIN_MENU as $key => $_) {
            $stmt->execute(['menu_hide_' . $key, isset($_POST['hide'][$key]) ? '1' : '0']);
        }
        flash_set('success', 'Konfiguracja menu admina zapisana.');
        header('Location: ' . APP_URL . '/admin/menu_config.php?tab=admin'); exit;
    }

    if ($tab === 'panel') {
        foreach ($PANEL_ITEMS as $key => $_) {
            $stmt->execute(['panel_disable_' . $key, isset($_POST['disable'][$key]) ? '1' : '0']);
        }
        flash_set('success', 'Konfiguracja panelu wolontariusza zapisana.');
        header('Location: ' . APP_URL . '/admin/menu_config.php?tab=panel'); exit;
    }
}

// ── Wczytaj aktualną konfigurację ─────────────────────────────────────────────
$current_hide    = [];
$current_disable = [];
try {
    $rows = db_all("SELECT key_, value FROM settings WHERE key_ LIKE 'menu_hide_%' OR key_ LIKE 'panel_disable_%'");
    foreach ($rows as $r) {
        if ($r['value'] !== '1') continue;
        if (str_starts_with($r['key_'], 'menu_hide_'))    $current_hide[substr($r['key_'], 10)] = true;
        if (str_starts_with($r['key_'], 'panel_disable_')) $current_disable[substr($r['key_'], 14)] = true;
    }
} catch (\Throwable $e) {}

$active_tab = $_GET['tab'] ?? 'admin';

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="mb-0"><i class="bi bi-layout-sidebar text-primary me-2"></i>Konfiguracja menu</h4>
</div>
<?= flash_html() ?>

<!-- Zakładki -->
<ul class="nav nav-tabs mb-0" id="menuConfigTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link <?= $active_tab === 'admin' ? 'active' : '' ?>"
            data-bs-toggle="tab" data-bs-target="#tab-admin" type="button">
      <i class="bi bi-shield-shaded me-1"></i>Menu admina/edytora
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link <?= $active_tab === 'panel' ? 'active' : '' ?>"
            data-bs-toggle="tab" data-bs-target="#tab-panel" type="button">
      <i class="bi bi-person-circle me-1"></i>Panel wolontariusza
      <?php $panel_disabled_count = count($current_disable);
      if ($panel_disabled_count > 0): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $panel_disabled_count ?></span>
      <?php endif; ?>
    </button>
  </li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white shadow-sm p-4 mb-4" id="menuConfigTabContent">

<!-- ══ TAB: Menu admina ══════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'admin' ? 'show active' : '' ?>" id="tab-admin">

  <div class="alert alert-light border d-flex gap-2 py-2 mb-3">
    <i class="bi bi-info-circle text-primary flex-shrink-0 mt-1"></i>
    <div class="small">
      <strong>Ukrywanie</strong> — pozycja znika z bocznego paska dla wszystkich edytorów/adminów.
      Strona nadal działa po bezpośrednim URL. Uzupełnij wyłączaniem modułu w Ustawieniach dla pełnej blokady.
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_tab"  value="admin">

    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:50px" class="ps-3 text-center">Ukryj</th>
          <th>Pozycja</th>
          <th class="text-muted small fw-normal">Sekcja</th>
          <th>Stan</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ADMIN_MENU as $key => $item): $hidden = !empty($current_hide[$key]); ?>
      <tr class="<?= $hidden ? 'table-warning' : '' ?>">
        <td class="ps-3 text-center">
          <input class="form-check-input" type="checkbox" name="hide[<?= h($key) ?>]"
                 id="ah_<?= h($key) ?>" value="1" <?= $hidden ? 'checked' : '' ?>>
        </td>
        <td>
          <label for="ah_<?= h($key) ?>" class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer">
            <i class="bi <?= h($item['icon']) ?> text-secondary"></i>
            <span class="<?= $hidden ? 'text-muted text-decoration-line-through' : 'fw-semibold' ?>"><?= h($item['label']) ?></span>
          </label>
        </td>
        <td class="text-muted small"><?= h($item['section']) ?></td>
        <td>
          <?php if ($hidden): ?>
          <span class="badge bg-warning text-dark"><i class="bi bi-eye-slash me-1"></i>Ukryte</span>
          <?php else: ?>
          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i class="bi bi-eye me-1"></i>Widoczne</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <button type="submit" class="btn btn-primary btn-sm">
      <i class="bi bi-floppy me-1"></i>Zapisz
    </button>
  </form>
</div>

<!-- ══ TAB: Panel wolontariusza ═════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'panel' ? 'show active' : '' ?>" id="tab-panel">

  <div class="alert alert-light border d-flex gap-2 py-2 mb-3">
    <i class="bi bi-info-circle text-primary flex-shrink-0 mt-1"></i>
    <div class="small">
      <strong>Wyłączanie dla wolontariusza</strong> — pozycja znika z menu wolontariusza.
      Jeśli wolontariusz spróbuje wejść bezpośrednio przez URL, zostaje przekierowany na stronę główną
      z komunikatem <em>„sekcja jest niedostępna"</em>.
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
    <input type="hidden" name="_tab"   value="panel">

    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:50px" class="ps-3 text-center">Wyłącz</th>
          <th>Pozycja panelu</th>
          <th>Stan</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($PANEL_ITEMS as $key => $item): $disabled = !empty($current_disable[$key]); ?>
      <tr class="<?= $disabled ? 'table-danger bg-opacity-50' : '' ?>">
        <td class="ps-3 text-center">
          <input class="form-check-input" type="checkbox" name="disable[<?= h($key) ?>]"
                 id="pd_<?= h($key) ?>" value="1" <?= $disabled ? 'checked' : '' ?>>
        </td>
        <td>
          <label for="pd_<?= h($key) ?>" class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer">
            <i class="bi <?= h($item['icon']) ?> text-secondary"></i>
            <span class="<?= $disabled ? 'text-muted text-decoration-line-through' : 'fw-semibold' ?>"><?= h($item['label']) ?></span>
          </label>
        </td>
        <td>
          <?php if ($disabled): ?>
          <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Wyłączone</span>
          <?php else: ?>
          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i class="bi bi-check-circle me-1"></i>Dostępne</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <button type="submit" class="btn btn-danger btn-sm">
      <i class="bi bi-floppy me-1"></i>Zapisz konfigurację panelu
    </button>
  </form>
</div>

</div><!-- /tab-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
  var tab = new URLSearchParams(location.search).get('tab') || 'admin';
  var btn = document.querySelector('[data-bs-target="#tab-' + tab + '"]');
  if (btn) new bootstrap.Tab(btn).show();
  document.querySelectorAll('#menuConfigTabs [data-bs-toggle="tab"]').forEach(function(b) {
    b.addEventListener('shown.bs.tab', function(e) {
      var id = e.target.dataset.bsTarget.replace('#tab-', '');
      history.replaceState(null, '', '?tab=' + id);
    });
  });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
