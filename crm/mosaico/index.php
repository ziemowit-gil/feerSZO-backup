<?php
/**
 * crm/mosaico/index.php — strona CRM (header_crm.php) z pełnoekranowym iframe
 * do edytora Mosaico (editor.php). Analogiczny wzorzec do crm/webmail.php —
 * osobny CSS/JS Mosaico nie miesza się z resztą CRM.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$can_write = can_write('crm') || is_admin();
if (!$can_write) {
    flash_set('error', 'Brak uprawnień do edycji szablonów.');
    header('Location: ' . APP_URL . '/crm/templates.php');
    exit;
}

$template_id = (int)($_GET['template_id'] ?? 0);
$PAGE_TITLE  = 'CRM — Edytor Mosaico';

$iframe_src = APP_URL . '/crm/mosaico/editor.php' . ($template_id ? '?template_id=' . $template_id : '');

include __DIR__ . '/../includes/header_crm.php';
?>
<style>
.crm-mosaico-wrap {
    display: flex;
    flex-direction: column;
    height: calc(100vh - var(--crm-topbar-h, 56px) - 1rem);
    min-height: 500px;
}
.crm-mosaico-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .4rem .75rem;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px 8px 0 0;
    font-size: .82rem;
}
.crm-mosaico-frame {
    flex: 1;
    width: 100%;
    border: 1px solid #E5E7EB;
    border-top: none;
    border-radius: 0 0 8px 8px;
    background: #fff;
}
</style>

<div class="crm-mosaico-wrap">
  <div class="crm-mosaico-bar">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-palette-fill" style="color:var(--crm-primary)" aria-hidden="true"></i>
      <span class="fw-semibold">Edytor Mosaico</span>
      <span class="text-muted">— zmiany zapisują się przy każdym „Download HTML” w edytorze</span>
    </div>
    <a href="<?= APP_URL ?>/crm/templates.php" class="btn btn-sm btn-crm-outline">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do szablonów
    </a>
  </div>
  <iframe class="crm-mosaico-frame" src="<?= h($iframe_src) ?>" title="Edytor Mosaico"></iframe>
</div>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
