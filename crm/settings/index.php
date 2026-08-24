<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();
crm_require('settings', 'read');

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
    _crm_setting_save('roundcube_url',    rtrim(trim($_POST['roundcube_url'] ?? ''), '/'));
    flash_set('success', 'Ustawienia CRM zostały zapisane.');
    header('Location: ' . APP_URL . '/crm/settings/');
    exit;
}

$crm_email_footer = org_setting('crm_email_footer');
$roundcube_url    = crm_setting('roundcube_url');

include __DIR__ . '/../includes/header_crm.php';
?>

<?php require_once __DIR__ . '/_nav.php'; ?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item active">Ustawienia</li>
  </ol>
</nav>

<h1 class="fw-bold mb-3" style="font-size:1.25rem">
  <i class="bi bi-gear-fill me-2" style="color:var(--crm-primary)"></i>Ustawienia CRM
</h1>

<?= flash_html() ?>

<div class="card border-0 shadow-sm mb-4" style="max-width:680px">
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

<div class="card border-0 shadow-sm mb-4" style="max-width:680px">
  <div class="card-body">
    <div class="crm-section-title">
      <i class="bi bi-envelope-at me-1" aria-hidden="true"></i>Roundcube Webmail
    </div>
    <p class="text-muted small mb-3">
      Jeśli masz zainstalowany Roundcube, wpisz jego adres URL. Na karcie kontaktu pojawi się
      przycisk <strong>Roundcube</strong>, który otworzy compositor maila z wypełnionym adresem.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"            value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="crm_email_footer" value="<?= h(org_setting('crm_email_footer')) ?>">
      <div class="mb-3">
        <label for="roundcube_url" class="form-label fw-semibold">URL Roundcube</label>
        <input type="url" class="form-control" id="roundcube_url" name="roundcube_url"
               value="<?= h($roundcube_url) ?>"
               placeholder="https://webmail.twojadomena.pl">
        <div class="form-text">Zostaw puste, jeśli nie korzystasz z Roundcube.</div>
      </div>
      <?php if ($roundcube_url): ?>
      <div class="mb-3">
        <a href="<?= h($roundcube_url . '/?_task=mail&_action=compose&_to=test@example.com') ?>"
           class="btn btn-sm btn-outline-secondary"
           target="_blank" rel="noopener noreferrer">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Testuj link
        </a>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-crm-primary">
        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
