<?php
/**
 * tasks/settings/fields.php
 * Uprawnienia per-pole zadania: widoczność i edytowalność dla ról obszaru
 * (member/viewer). Admin/editor obszaru mają zawsze pełny dostęp — poniższa
 * konfiguracja zastępuje dawny twardo zakodowany zestaw pól edytowalnych
 * przez member/viewer w tasks/api/task.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

if (!is_admin()) {
    flash_set('danger', 'Tylko administratorzy mogą zarządzać uprawnieniami pól zadań.');
    header('Location: ' . APP_URL . '/tasks/dashboard.php');
    exit;
}

task_field_perms_migrate();

$WS_ROLES = ['member' => 'Uczestnik (member)', 'viewer' => 'Obserwator (viewer)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach (array_keys(TASK_GOVERNED_FIELDS) as $fkey) {
        $vis  = json_encode(array_values(array_filter((array)($_POST['visible_roles'][$fkey] ?? []), fn($r) => $r !== '')));
        $edit = json_encode(array_values(array_filter((array)($_POST['edit_roles'][$fkey]   ?? []), fn($r) => $r !== '')));
        $vis  = $vis === '[]' ? '' : $vis;
        $edit = $edit === '[]' ? '' : $edit;

        $exists = db_one("SELECT field_key FROM task_field_perms WHERE field_key=?", [$fkey]);
        if ($exists) {
            db()->prepare("UPDATE task_field_perms SET visible_roles=?, edit_roles=? WHERE field_key=?")
                ->execute([$vis, $edit, $fkey]);
        } else {
            db()->prepare("INSERT INTO task_field_perms (field_key, visible_roles, edit_roles) VALUES (?,?,?)")
                ->execute([$fkey, $vis, $edit]);
        }
    }
    flash_set('success', 'Uprawnienia pól zadań zapisane.');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$perms = [];
foreach (db_all("SELECT field_key, visible_roles, edit_roles FROM task_field_perms") as $r) {
    $perms[$r['field_key']] = $r;
}

$PAGE_TITLE       = 'Ustawienia pól — Zadania';
$TASKS_BREADCRUMB = 'Uprawnienia pól';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<?= flash_html() ?>

<div class="tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-2 tw-mb-4">
  <div>
    <h1 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
      <i class="bi bi-ui-checks-grid tw-text-blue-600" aria-hidden="true"></i>Uprawnienia pól — Moduł Zadania
    </h1>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Kontrola, które pola zadania widzą i mogą edytować uczestnicy (member) i obserwatorzy (viewer)</p>
  </div>
  <a href="<?= APP_URL ?>/tasks/settings/roles.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-shield-lock me-1"></i>Uprawnienia ról
  </a>
</div>

<div class="tw-flex tw-gap-2 tw-items-start tw-bg-blue-50 tw-border tw-border-blue-100 tw-rounded-lg tw-py-2 tw-px-3 tw-text-sm tw-mb-4">
  <i class="bi bi-info-circle tw-mt-[.15rem] tw-text-blue-600" aria-hidden="true"></i>
  <span>Liderzy obszaru (role <strong>admin</strong> i <strong>editor</strong>) zawsze widzą i mogą edytować
  wszystkie pola zadania, niezależnie od ustawień poniżej. Konfiguracja dotyczy wyłącznie ról
  <strong>member</strong> i <strong>viewer</strong>.</span>
</div>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

  <div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden tw-mb-4">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="tw-min-w-[180px]">Pole</th>
            <th>
              <i class="bi bi-eye me-1 text-secondary"></i>Kto może <u>widzieć</u>?
              <span class="tw-text-slate-400 tw-font-normal tw-text-[.75rem]">(puste = wszyscy)</span>
            </th>
            <th>
              <i class="bi bi-pencil me-1 text-secondary"></i>Kto może <u>edytować</u>?
              <span class="tw-text-slate-400 tw-font-normal tw-text-[.75rem]">(puste = nikt z member/viewer)</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (TASK_GOVERNED_FIELDS as $fkey => $flabel):
            $fp_vis  = json_decode($perms[$fkey]['visible_roles'] ?? '', true) ?: [];
            $fp_edit = json_decode($perms[$fkey]['edit_roles']    ?? '', true) ?: [];
          ?>
          <tr>
            <td>
              <span class="fw-semibold"><?= h($flabel) ?></span>
              <code class="text-muted ms-1" style="font-size:.72rem"><?= h($fkey) ?></code>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach ($WS_ROLES as $rname => $rlabel): ?>
                <div class="form-check mb-0">
                  <input type="checkbox" class="form-check-input"
                         id="vis_<?= h($fkey) ?>_<?= h($rname) ?>"
                         name="visible_roles[<?= h($fkey) ?>][]"
                         value="<?= h($rname) ?>"
                         <?= in_array($rname, $fp_vis, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="vis_<?= h($fkey) ?>_<?= h($rname) ?>">
                    <?= h($rlabel) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach ($WS_ROLES as $rname => $rlabel): ?>
                <div class="form-check mb-0">
                  <input type="checkbox" class="form-check-input"
                         id="edit_<?= h($fkey) ?>_<?= h($rname) ?>"
                         name="edit_roles[<?= h($fkey) ?>][]"
                         value="<?= h($rname) ?>"
                         <?= in_array($rname, $fp_edit, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="edit_<?= h($fkey) ?>_<?= h($rname) ?>">
                    <?= h($rlabel) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="d-flex gap-2 mb-5">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>Zapisz uprawnienia
    </button>
    <a href="<?= APP_URL ?>/tasks/dashboard.php" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</form>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
