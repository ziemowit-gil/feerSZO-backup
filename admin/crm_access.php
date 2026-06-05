<?php
/**
 * admin/crm_access.php — Konfiguracja dostępu użytkowników CRM-only.
 * Określa, do których modułów poza CRM mają dostęp użytkownicy z rolą crm_user.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Dostęp CRM-only';

$MODULES = [
    'actions'   => ['label'=>'Działania',          'icon'=>'bi-calendar-event',     'desc'=>'Moduł działań i projektów (/strategy/actions/)'],
    'grants'    => ['label'=>'Granty',              'icon'=>'bi-cash-coin',          'desc'=>'Moduł grantów (/grants/)'],
    'persons'   => ['label'=>'Osoby',               'icon'=>'bi-people',             'desc'=>'Rejestr osób (/persons/)'],
    'reports'   => ['label'=>'Raporty',             'icon'=>'bi-bar-chart-line',     'desc'=>'Raporty systemowe (/reports/)'],
    'directory' => ['label'=>'Katalog osób',        'icon'=>'bi-person-lines-fill',  'desc'=>'Katalog współpracowników (/directory/)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selected = array_filter(array_keys($MODULES), fn($k) => isset($_POST['mod_' . $k]));
    $value    = implode(',', $selected);

    // Upsert
    try {
        db()->prepare("INSERT INTO settings (key_, value) VALUES ('crm_extra_modules',?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
            ->execute([$value]);
    } catch (\Throwable $e) {
        $exists = db_one("SELECT key_ FROM settings WHERE key_='crm_extra_modules'");
        if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_='crm_extra_modules'")->execute([$value]);
        else db_insert('settings', ['key_'=>'crm_extra_modules','value'=>$value]);
    }
    flash_set('success', 'Ustawienia dostępu CRM zapisane.');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$current_raw = db_one("SELECT value FROM settings WHERE key_='crm_extra_modules'");
$current     = $current_raw ? array_filter(explode(',', $current_raw['value'])) : [];

// Liczba użytkowników crm_only
$crm_only_count = 0;
try {
    $crm_only_count = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE role='crm_user' AND is_active=1")['c'] ?? 0);
    $crm_only_custom = (int)(db_one("SELECT COUNT(DISTINCT u.id) AS c FROM users u JOIN roles r ON r.name=u.role WHERE r.crm_only=1 AND r.name!='crm_user' AND u.is_active=1")['c'] ?? 0);
    $crm_only_count += $crm_only_custom;
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4" style="max-width:700px">

  <div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#2563eb">
      <i class="bi bi-diagram-2-fill"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Dostęp użytkowników CRM-only</h1>
      <p class="text-muted mb-0 small">
        Konfiguruje które moduły systemowe są dostępne dla użytkowników z rolą
        <code>crm_user</code> lub inną rolą z flagą <em>crm_only</em>.
      </p>
    </div>
  </div>

  <?= flash_get() ?>

  <div class="alert alert-info py-2 px-3 small mb-3">
    <i class="bi bi-people-fill me-1"></i>
    Aktualnie <strong><?= $crm_only_count ?></strong>
    <?= $crm_only_count === 1 ? 'użytkownik ma' : 'użytkowników ma' ?>
    ustawioną rolę CRM-only.
    <a href="<?= APP_URL ?>/admin/users.php?role=crm_user" class="ms-1">Zarządzaj →</a>
  </div>

  <form method="post">
    <?= csrf_field() ?>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white py-2">
        <span class="fw-semibold">Dodatkowe moduły dostępne dla CRM-only</span>
        <span class="text-muted small ms-2">Moduł CRM jest zawsze dostępny.</span>
      </div>
      <div class="list-group list-group-flush">
        <!-- Zawsze -->
        <div class="list-group-item py-3 d-flex align-items-center gap-3 bg-success-subtle">
          <div style="width:36px;height:36px;border-radius:8px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#16a34a">
            <i class="bi bi-diagram-2-fill"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold small">CRM</div>
            <div class="text-muted" style="font-size:.75rem">Zawsze dostępny — kontakty, grupy, komunikacja, formularze</div>
          </div>
          <span class="badge bg-success">Zawsze włączony</span>
        </div>

        <?php foreach ($MODULES as $key => $m): ?>
        <div class="list-group-item py-3 d-flex align-items-center gap-3">
          <div style="width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#64748b">
            <i class="bi <?= h($m['icon']) ?>"></i>
          </div>
          <div class="flex-grow-1">
            <label for="mod_<?= $key ?>" class="fw-semibold small d-block mb-0" style="cursor:pointer">
              <?= h($m['label']) ?>
            </label>
            <div class="text-muted" style="font-size:.75rem"><?= h($m['desc']) ?></div>
          </div>
          <div class="form-check form-switch mb-0">
            <input type="checkbox" class="form-check-input" role="switch"
                   id="mod_<?= $key ?>" name="mod_<?= $key ?>" value="1"
                   <?= in_array($key, $current) ? 'checked' : '' ?>>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white py-2 fw-semibold small">Jak to działa</div>
      <div class="card-body small text-muted">
        <ul class="mb-0 ps-3">
          <li>Użytkownicy z rolą <code>crm_user</code> widzą tylko wybrane moduły — paski nawigacji innych są ukryte.</li>
          <li>Dostęp URL jest wymuszany w <code>require_login()</code> — próba wejścia na zablokowaną stronę przekierowuje do <code>/crm/dashboard</code>.</li>
          <li>Jeśli zaznaczysz <strong>Działania</strong>, crm-only użytkownik zobaczy moduł działań i będzie mógł przeglądać powiązane kontakty CRM z poziomu działania.</li>
          <li>Rola <strong>editor / admin</strong> zawsze ma pełny dostęp niezależnie od tych ustawień.</li>
        </ul>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save me-1"></i>Zapisz ustawienia
      </button>
      <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-outline-secondary">Anuluj</a>
    </div>
  </form>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
