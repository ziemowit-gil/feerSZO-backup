<?php
/**
 * admin/redmine_settings.php — konfiguracja integracji Helpdesk → Redmine (REST API).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia Redmine';

$KEYS = [
    'redmine_enabled', 'redmine_url', 'redmine_api_key',
    'redmine_default_project_id', 'redmine_default_tracker_id',
];
$settings = [];
foreach ($KEYS as $k) { $settings[$k] = org_setting($k); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    csrf_check();
    $save = [
        'redmine_enabled'            => isset($_POST['redmine_enabled']) ? '1' : '0',
        'redmine_url'                => rtrim(trim($_POST['redmine_url'] ?? ''), '/'),
        'redmine_default_project_id' => trim($_POST['redmine_default_project_id'] ?? ''),
        'redmine_default_tracker_id' => trim($_POST['redmine_default_tracker_id'] ?? ''),
    ];
    // Klucz API — zachowaj stary, gdy pole puste.
    $key = trim($_POST['redmine_api_key'] ?? '');
    if ($key !== '') $save['redmine_api_key'] = $key;

    foreach ($save as $k => $v) { org_setting_set($k, $v); }
    $settings = array_merge($settings, $save);
    flash_set('success', 'Ustawienia Redmine zapisane.');
    header('Location: redmine_settings.php'); exit;
}

$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_test'])) {
    csrf_check();
    $test_result = redmine_test();
}

$configured = $settings['redmine_url'] !== '' && $settings['redmine_api_key'] !== '';
$lib_ok     = class_exists(\Redmine\Client\NativeCurlClient::class);

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Redmine</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-kanban me-2 text-primary"></i>Integracja Redmine (Helpdesk)</h4>
  <?php if ($settings['redmine_enabled'] === '1' && $configured): ?>
    <span class="badge bg-success">Aktywna</span>
  <?php elseif ($configured): ?>
    <span class="badge bg-warning text-dark">Skonfigurowana — nieaktywna</span>
  <?php else: ?>
    <span class="badge bg-secondary">Nieskonfigurowana</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (!$lib_ok): ?>
<div class="alert alert-danger">
  <i class="bi bi-exclamation-triangle"></i>
  Biblioteka <code>kbsali/redmine-api</code> nie jest załadowana — uruchom <code>composer install</code> na serwerze.
</div>
<?php endif; ?>

<?php if ($test_result !== null): ?>
<div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> mb-3">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle' : 'exclamation-triangle' ?>"></i>
  <?= $test_result['ok']
        ? 'Połączenie z Redmine działa (zalogowano jako: ' . h($test_result['user'] ?? '—') . ').'
        : 'Błąd: ' . h($test_result['error'] ?? 'nieznany') ?>
</div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-7">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Konfiguracja API</div>
<div class="card-body">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="redmine_enabled"
           id="redmine_enabled" value="1" <?= $settings['redmine_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="redmine_enabled">
      Wysyłaj nowe zgłoszenia Helpdesk do Redmine
    </label>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Adres Redmine <span class="text-danger">*</span></label>
    <input type="text" name="redmine_url" class="form-control form-control-sm font-monospace"
           value="<?= h($settings['redmine_url']) ?>" placeholder="https://feer.usermd.net">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">Klucz API <span class="text-danger">*</span></label>
    <input type="password" name="redmine_api_key" class="form-control form-control-sm font-monospace"
           autocomplete="new-password"
           placeholder="<?= $settings['redmine_api_key'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Klucz API z Redmine' ?>">
    <div class="form-text">Redmine → <em>Moje konto</em> → panel po prawej „Klucz API" → Pokaż.
      Wymaga włączonego web-serwisu REST (Administracja → Ustawienia → API).</div>
  </div>

  <div class="row g-2">
    <div class="col-sm-6 mb-3">
      <label class="form-label fw-semibold small">Projekt docelowy (ID lub identyfikator) <span class="text-danger">*</span></label>
      <input type="text" name="redmine_default_project_id" class="form-control form-control-sm"
             value="<?= h($settings['redmine_default_project_id']) ?>" placeholder="np. 1 lub helpdesk">
    </div>
    <div class="col-sm-6 mb-3">
      <label class="form-label fw-semibold small">Tracker (ID, opcjonalnie)</label>
      <input type="number" name="redmine_default_tracker_id" class="form-control form-control-sm"
             value="<?= h($settings['redmine_default_tracker_id']) ?>" placeholder="np. 3 (Support)">
      <div class="form-text">Typ zagadnienia z Administracja → Typy zagadnień. Puste = domyślny.</div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" name="_save" class="btn btn-primary btn-sm"><i class="bi bi-floppy"></i> Zapisz</button>
    <button type="submit" name="_test" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug"></i> Testuj połączenie</button>
  </div>
</form>
</div>
</div>
</div>

<div class="col-lg-5">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Jak to działa</div>
<div class="card-body small">
  <ul class="mb-2 ps-3">
    <li class="mb-2"><strong>Do Redmine:</strong> nowe zgłoszenie w Helpdesku SZO tworzy
      zagadnienie (issue) w Redmine, a jego numer zapisuje się przy zgłoszeniu.</li>
    <li class="mb-2"><strong>Z Redmine (co 10 min, cron):</strong> nowe notatki wracają jako
      wiadomości zgłoszenia, a zamknięcie issue ustawia status „Rozwiązane".</li>
    <li class="mb-2">Nie potrzebujesz OAuth (sekcja „Applications") — wystarczy <strong>klucz API</strong>.</li>
    <li class="mb-2">W Redmine: <em>Administracja → Ustawienia → API</em> → włącz „REST web service";
      klucz API weźmiesz z <em>Moje konto</em>.</li>
    <li class="mb-2">Wskaż projekt docelowy — jego identyfikator widać w adresie
      <code>/projects/&lt;identyfikator&gt;</code>.</li>
  </ul>
  <div class="text-muted">Synchronizacja jest <strong>dwukierunkowa</strong>. Wewnętrzny moduł Helpdesk działa dalej.</div>
</div>
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
