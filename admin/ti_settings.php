<?php
/**
 * admin/ti_settings.php — Ustawienia Panelu dydaktyka (TI).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Panel dydaktyka — ustawienia';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $enabled = !empty($_POST['dyd_panel_enabled']) ? '1' : '0';
    $message = trim($_POST['dyd_panel_message'] ?? '');
    $resume  = trim($_POST['dyd_panel_resume'] ?? '');
    org_setting_set('dyd_panel_enabled', $enabled);
    org_setting_set('dyd_panel_message', $message);
    org_setting_set('dyd_panel_resume',  $resume);
    flash_set('success', 'Ustawienia panelu dydaktyka zapisane.');
    header('Location: ti_settings.php'); exit;
}

$enabled = org_setting('dyd_panel_enabled');
$enabled = ($enabled === '' || $enabled === '1'); // domyślnie włączony
$message = org_setting('dyd_panel_message');
$resume  = org_setting('dyd_panel_resume');

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/admin/index.php">Admin</a></li>
    <li class="breadcrumb-item active">Panel dydaktyka</li>
  </ol>
</nav>

<h1 class="h4 mb-4"><i class="bi bi-easel2 me-2"></i>Panel dydaktyka — ustawienia</h1>

<?php flash_render(); ?>

<form method="post" action="">
  <?= csrf_field() ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-toggle-on text-primary"></i> Dostępność panelu
    </div>
    <div class="card-body">
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               id="dyd_panel_enabled" name="dyd_panel_enabled"
               <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="dyd_panel_enabled">
          Panel dydaktyka jest włączony
        </label>
        <div class="form-text mt-1">
          Po wyłączeniu dydaktycy (prowadzący) widzą stronę przerwy z poniższym komunikatem.
          Administratorzy i pracownicy D3 nadal mają pełny dostęp.
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-chat-square-text text-warning"></i> Komunikat dla dydaktyków
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label for="dyd_panel_message" class="form-label">Treść komunikatu</label>
        <textarea class="form-control" id="dyd_panel_message" name="dyd_panel_message"
                  rows="3" maxlength="1000"
                  placeholder="np. Przerwa techniczna — panel dydaktyka jest tymczasowo niedostępny. Zapraszamy ponownie wkrótce."><?= h($message) ?></textarea>
        <div class="form-text">Pozostaw puste, aby wyświetlić domyślny komunikat.</div>
      </div>
      <div class="mb-0">
        <label for="dyd_panel_resume" class="form-label">Planowany czas wznowienia <span class="text-body-secondary">(opcjonalnie)</span></label>
        <input type="datetime-local" class="form-control" id="dyd_panel_resume" name="dyd_panel_resume"
               value="<?= h($resume) ?>" style="max-width:260px">
        <div class="form-text">Jeśli podasz datę i godzinę, wyświetli się ona na stronie przerwy.</div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
    </button>
    <a href="/karty30/ti/dydaktyk/index.php" target="_blank" class="btn btn-outline-secondary">
      <i class="bi bi-box-arrow-up-right me-1"></i>Podgląd panelu
    </a>
  </div>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
