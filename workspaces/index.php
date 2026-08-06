<?php
/**
 * workspaces/index.php — Lista koszulek (Workspace) użytkownika.
 * Wyświetla tylko workspace'y, do których użytkownik należy (przez task_workspace_members).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/workspaces.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań (wymagany dla Koszulek)');

$user      = current_user();
$workspaces = ws_list_user_workspaces($user['id']);
$sp_ok     = ws_available();

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid px-3 px-md-4">

  <!-- Nagłówek -->
  <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
    <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-folder2-open" style="color:#2563eb"></i>
      Koszulki / Workspace
    </h5>
    <?php if (!$sp_ok): ?>
    <span class="badge bg-warning text-dark">
      <i class="bi bi-exclamation-triangle me-1"></i>SharePoint niekonfigurowany
    </span>
    <?php endif; ?>
  </div>

  <?php if (!$sp_ok): ?>
  <div class="alert alert-warning mb-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    SharePoint nie jest w pełni skonfigurowany. Przejdź do
    <a href="<?= APP_URL ?>/admin/m365_settings.php">Ustawień M365</a>
    i włącz integrację SharePoint (<code>sp_enabled=1</code>, <code>sp_site_url</code>).
    Możesz przeglądać metadane, ale upload/pobieranie nie będą działać.
  </div>
  <?php endif; ?>

  <?php if (empty($workspaces)): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-folder2" style="font-size:2.5rem;opacity:.3"></i>
    <p class="mt-2 mb-1">Nie masz dostępu do żadnego obszaru roboczego.</p>
    <small>Poproś administratora o przypisanie do projektu w module Zadań.</small>
  </div>
  <?php else: ?>

  <div class="row g-3">
    <?php foreach ($workspaces as $ws):
      $color  = $ws['color'] ?: '#3b82f6';
      $icon   = $ws['icon']  ?: 'bi-folder2';
      $role   = $ws['ws_role'] ?? 'viewer';
      $badge  = match($role) {
          'admin'  => ['bg-danger',   'Admin'],
          'editor' => ['bg-warning text-dark', 'Edytor'],
          'member' => ['bg-primary',  'Członek'],
          default  => ['bg-secondary','Widz'],
      };
    ?>
    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
      <a href="<?= APP_URL ?>/workspaces/view.php?ws=<?= (int)$ws['id'] ?>"
         class="card shadow-sm border-0 text-decoration-none h-100 ws-card" style="--ws-color:<?= h($color) ?>">
        <div class="card-body p-3">
          <div class="d-flex align-items-start gap-2 mb-2">
            <div class="ws-icon rounded-2 d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:<?= h($color) ?>18">
              <i class="bi <?= h($icon) ?>" style="color:<?= h($color) ?>;font-size:1.2rem"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold text-truncate text-dark" style="font-size:.92rem">
                <?= h($ws['name']) ?>
              </div>
              <span class="badge <?= $badge[0] ?> rounded-pill" style="font-size:.65rem">
                <?= $badge[1] ?>
              </span>
            </div>
          </div>
          <div class="d-flex gap-3 mt-2" style="font-size:.78rem;color:#6b7280">
            <span><i class="bi bi-folder me-1"></i><?= (int)$ws['folder_count'] ?> folderów</span>
            <span><i class="bi bi-file-earmark me-1"></i><?= (int)$ws['file_count'] ?> plików</span>
          </div>
        </div>
        <div class="card-footer bg-transparent border-top-0 py-2 px-3" style="border-top:2px solid <?= h($color) ?>!important">
          <small class="text-muted">
            <i class="bi bi-arrow-right-short"></i> Otwórz koszulki
          </small>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<style>
.ws-card { transition: transform .12s, box-shadow .12s; }
.ws-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.1) !important; }
</style>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
