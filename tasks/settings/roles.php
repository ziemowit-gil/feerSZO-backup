<?php
/**
 * tasks/settings/roles.php
 * Przegląd uprawnień systemowych ról do modułu Zadania.
 * Admin może tu zobaczyć które role mają dostęp read/write i przejść do zarządzania.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/permissions.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid       = (int)(current_user()['id'] ?? 0);
$sys_admin = is_admin();

if (!$sys_admin) {
    flash_set('error', 'Tylko administratorzy mogą zarządzać uprawnieniami ról.');
    header('Location: ' . APP_URL . '/tasks/dashboard.php'); exit;
}

_permissions_init();

// Pobierz role z ich uprawnieniami do modułu 'zadania'
$roles = db_all("SELECT r.id, r.name, r.display_name, r.description,
                        rp_r.id AS perm_read_id, rp_w.id AS perm_write_id
                 FROM roles r
                 LEFT JOIN role_permissions rp_r
                        ON rp_r.role_id=r.id AND rp_r.module='zadania' AND rp_r.can_read=1
                 LEFT JOIN role_permissions rp_w
                        ON rp_w.role_id=r.id AND rp_w.module='zadania' AND rp_w.can_write=1
                 ORDER BY r.sort_order, r.display_name");

// Obszary z visible_roles/edit_roles
task_areas_migrate();
$workspaces_with_roles = db_all(
    "SELECT id, name, color, visible_roles, edit_roles
     FROM task_workspaces WHERE is_active=1 ORDER BY name"
);

$PAGE_TITLE       = 'Uprawnienia — Zadania';
$TASKS_BREADCRUMB = 'Uprawnienia ról';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<?= flash_html() ?>

<div class="tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-2 tw-mb-4">
  <div>
    <h1 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
      <i class="bi bi-shield-lock tw-text-blue-600" aria-hidden="true"></i>Uprawnienia ról — Moduł Zadania
    </h1>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Role systemowe i ich dostęp do modułu zadań oraz poszczególnych obszarów</p>
  </div>
  <?php if ($sys_admin): ?>
  <a href="<?= APP_URL ?>/tasks/settings/fields.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-ui-checks-grid me-1"></i>Uprawnienia pól
  </a>
  <a href="<?= APP_URL ?>/admin/roles.php" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-pencil-square me-1"></i>Zarządzaj rolami
  </a>
  <?php endif; ?>
</div>

<!-- ── Role systemowe ── -->
<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden tw-mb-4">
  <div class="tw-bg-white tw-font-semibold tw-text-sm tw-py-2 tw-px-3 tw-border-b tw-border-slate-100">
    <i class="bi bi-people me-1 text-primary"></i>Role systemowe — dostęp do modułu Zadania
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3 small">Rola</th>
          <th class="small text-center">Odczyt (<code>zadania</code>)</th>
          <th class="small text-center">Zapis (<code>zadania</code>)</th>
          <th class="small">Opis</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $r): ?>
        <tr>
          <td class="ps-3">
            <div class="fw-semibold small"><?= h($r['display_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><code><?= h($r['name']) ?></code></div>
          </td>
          <td class="text-center">
            <?php if ($r['perm_read_id']): ?>
            <i class="bi bi-check-circle-fill text-success" title="Może czytać"></i>
            <?php else: ?>
            <i class="bi bi-x-circle text-secondary opacity-40" title="Brak dostępu"></i>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($r['perm_write_id']): ?>
            <i class="bi bi-check-circle-fill text-success" title="Może pisać"></i>
            <?php else: ?>
            <i class="bi bi-x-circle text-secondary opacity-40" title="Brak dostępu"></i>
            <?php endif; ?>
          </td>
          <td class="text-muted small pe-3"><?= h($r['description'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$roles): ?>
        <tr><td colspan="4" class="text-muted small ps-3 py-3 text-center">Brak zdefiniowanych ról.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="tw-bg-white tw-border-t tw-border-slate-100 tw-py-2 tw-px-3">
    <span class="text-muted small">
      <i class="bi bi-info-circle me-1"></i>
      Administratorzy systemu mają zawsze pełny dostęp do wszystkich obszarów, niezależnie od ustawień poniżej.
      Aby zmienić uprawnienia ról przejdź do
      <a href="<?= APP_URL ?>/admin/roles.php">Administracja → Role</a>.
    </span>
  </div>
</div>

<!-- ── Obszary z ograniczeniami ról ── -->
<?php
$ws_with_restr = array_filter($workspaces_with_roles, fn($w) =>
    !empty($w['visible_roles']) || !empty($w['edit_roles'])
);
?>
<?php if ($workspaces_with_roles): ?>
<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden">
  <div class="tw-bg-white tw-font-semibold tw-text-sm tw-py-2 tw-px-3 tw-border-b tw-border-slate-100">
    <i class="bi bi-kanban me-1 text-primary"></i>Obszary robocze — ograniczenia per rola
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3 small">Obszar</th>
          <th class="small">Widoczny dla ról (<code>visible_roles</code>)</th>
          <th class="small">Edytowalny przez role (<code>edit_roles</code>)</th>
          <th class="small"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($workspaces_with_roles as $ws): ?>
        <?php
          $vr = json_decode($ws['visible_roles'] ?? '', true) ?: [];
          $er = json_decode($ws['edit_roles']    ?? '', true) ?: [];
        ?>
        <tr>
          <td class="ps-3">
            <span style="display:inline-flex;align-items:center;gap:.4rem">
              <span style="width:8px;height:8px;border-radius:50%;background:<?= h($ws['color']) ?>;flex-shrink:0"></span>
              <span class="fw-semibold small"><?= h($ws['name']) ?></span>
            </span>
          </td>
          <td>
            <?php if ($vr): ?>
              <?php foreach ($vr as $rn): ?>
              <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.7rem"><?= h($rn) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
            <span class="text-muted small">Wszystkie</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($er): ?>
              <?php foreach ($er as $rn): ?>
              <span class="badge bg-warning bg-opacity-10 text-warning-emphasis" style="font-size:.7rem"><?= h($rn) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
            <span class="text-muted small">Bez ograniczeń</span>
            <?php endif; ?>
          </td>
          <td class="pe-3">
            <a href="<?= APP_URL ?>/tasks/settings/workspaces.php?ws=<?= $ws['id'] ?>"
               class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;padding:.15rem .4rem">
              <i class="bi bi-gear"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$ws_with_restr): ?>
  <div class="tw-bg-white tw-border-t tw-border-slate-100 tw-py-2 tw-px-3">
    <span class="text-muted small">
      <i class="bi bi-info-circle me-1"></i>Żaden obszar nie ma ustawionych ograniczeń ról.
      Dodaj je w <a href="<?= APP_URL ?>/tasks/settings/workspaces.php">Ustawieniach obszarów</a>.
    </span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
