<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) {
    flash_set('danger', 'Tylko administrator może zarządzać ustawieniami CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();

$PAGE_TITLE = 'CRM — Ustawienia';

function _crm_setting_save(string $key, string $value): void {
    try {
        db()->prepare(
            "INSERT INTO settings (key_, value) VALUES (?, ?)
             ON CONFLICT(key_) DO UPDATE SET value=excluded.value"
        )->execute([$key, $value]);
    } catch (\Throwable $e) {
        $exists = db_one("SELECT key_ FROM settings WHERE key_=?", [$key]);
        if ($exists) {
            db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
        } else {
            db_insert('settings', ['key_' => $key, 'value' => $value]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    _crm_setting_save('crm_email_footer', trim($_POST['crm_email_footer'] ?? ''));
    flash_set('success', 'Ustawienia CRM zostały zapisane.');
    header('Location: ' . APP_URL . '/crm/settings/');
    exit;
}

$crm_email_footer = org_setting('crm_email_footer');

include __DIR__ . '/../includes/header_crm.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item active">Ustawienia</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-gear-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Ustawienia CRM</h1>
    <div class="crm-object-count">Konfiguracja modułu CRM</div>
  </div>
  <div class="crm-object-actions d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/crm/settings/signature.php" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-pen me-1"></i>Mój podpis
    </a>
    <a href="<?= APP_URL ?>/crm/settings/roles.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-people-fill me-1"></i>Role CRM
    </a>
    <a href="<?= APP_URL ?>/crm/settings/statuses.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-bookmark-fill me-1"></i>Statusy
    </a>
    <a href="<?= APP_URL ?>/crm/settings/field_groups.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-layers me-1"></i>Grupy pól
    </a>
    <a href="<?= APP_URL ?>/crm/settings/fields.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-layout-text-sidebar-reverse me-1"></i>Pola formularza
    </a>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/crm/settings/ika.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-shield-lock me-1"></i>Wymaganie IKA
    </a>
    <a href="<?= APP_URL ?>/admin/teryt_import.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-geo-alt me-1"></i>Import TERYT
    </a>
    <a href="<?= APP_URL ?>/admin/crm_database.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-database-gear me-1"></i>Baza danych CRM
    </a>
    <a href="<?= APP_URL ?>/crm/settings/nozbe.php" class="btn btn-sm btn-outline-secondary">
      <img src="https://nozbe.com/favicon.ico" style="width:13px;height:13px;margin-right:4px" alt="">Nozbe
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<div class="card border-0 shadow-sm mb-4" style="max-width:780px">
  <div class="card-body">
    <div class="crm-section-title">Stopka e-mail (globalna)</div>
    <p class="text-muted small mb-3">
      Kod HTML dołączany automatycznie na końcu <strong>każdego maila wysyłanego z CRM</strong>
      (komunikaty indywidualne i masowe). Zostaw puste jeśli stopka nie jest potrzebna.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <div class="mb-3">
        <label for="crm_email_footer" class="form-label fw-semibold">Stopka HTML</label>
        <textarea class="form-control font-monospace"
                  id="crm_email_footer" name="crm_email_footer"
                  rows="8"
                  placeholder="np. &lt;hr&gt;&lt;p style=&quot;font-size:12px;color:#888&quot;&gt;Wiadomość wysłana przez CRM fundacji.&lt;/p&gt;"><?= h($crm_email_footer) ?></textarea>
        <div class="form-text">Akceptowany jest dowolny HTML. Wstawiany za treścią wiadomości, poprzedzony <code>&lt;hr&gt;</code>.</div>
      </div>

      <?php if ($crm_email_footer): ?>
      <div class="mb-3">
        <p class="form-label fw-semibold mb-1">Podgląd</p>
        <div class="border rounded p-3 bg-white" style="font-size:.9rem">
          <p class="text-muted fst-italic">(Przykładowa treść wiadomości CRM…)</p>
          <hr>
          <?= $crm_email_footer ?>
        </div>
      </div>
      <?php endif; ?>

      <button type="submit" class="btn btn-crm-primary">
        <i class="bi bi-check-lg me-1"></i>Zapisz stopkę
      </button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
