<?php
/**
 * tasks/archive.php — Archiwum zadań
 * Zadania ukończone > 7 dni temu są automatycznie przenoszone tutaj
 * przez cron/tasks_archive.php (kolumna tasks.archived_at). Stąd można
 * je tylko przeglądać albo przywrócić do aktywnej tablicy.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');
task_areas_migrate();

$uid = (int)(current_user()['id'] ?? 0);
$csrf = csrf_token();

$workspaces  = task_user_workspaces($uid);
$ws_id_param = (int)($_GET['ws'] ?? 0);
$ws_id       = $ws_id_param;

if ($ws_id && $workspaces && !in_array($ws_id, array_column($workspaces, 'id'), false)) {
    header('Location: ' . APP_URL . '/tasks/archive.php?ws=' . (int)$workspaces[0]['id']);
    exit;
}
if (!$ws_id && $workspaces) {
    $ws_id = (int)$workspaces[0]['id'];
}

$workspace = null;
$archived  = [];
$my_role   = null;
$can_edit  = false;

if ($ws_id) {
    task_require_workspace_access($ws_id);
    $workspace = db_one("SELECT * FROM task_workspaces WHERE id=? AND is_active=1", [$ws_id]);
    $my_role   = task_workspace_role($ws_id, $uid);
    $can_edit  = in_array($my_role, ['admin', 'editor'], true);

    if ($workspace) {
        $archived = db_all(
            "SELECT t.*, tl.name AS list_name
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.list_id
             WHERE t.workspace_id = ? AND t.archived_at IS NOT NULL AND t.deleted_at IS NULL
             ORDER BY t.archived_at DESC",
            [$ws_id]
        );
        foreach ($archived as &$t) {
            $t['assignees'] = db_all(
                "SELECT u.name FROM task_assignments ta JOIN users u ON u.id = ta.user_id
                 WHERE ta.task_id = ? ORDER BY u.name",
                [$t['id']]
            );
        }
        unset($t);
    }
}

$PAGE_TITLE       = 'Archiwum zadań';
$TASKS_WS_ID      = $ws_id;
$TASKS_BREADCRUMB = 'Archiwum zadań';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<div class="tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-2 tw-mb-4">
  <h2 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-archive-fill tw-text-slate-500" aria-hidden="true"></i>
    Archiwum zadań<?= $workspace ? ' — ' . h($workspace['name']) : '' ?>
  </h2>
  <a href="<?= APP_URL ?>/tasks/index.php<?= $ws_id ? '?ws='.$ws_id : '' ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do tablicy
  </a>
</div>

<div class="tw-flex tw-items-start tw-gap-2 tw-mb-4 tw-bg-slate-100 tw-text-slate-600 tw-rounded-lg tw-py-3 tw-px-4 tw-text-sm">
  <i class="bi bi-info-circle-fill tw-flex-shrink-0 tw-mt-1" aria-hidden="true"></i>
  <div>
    Zadania ukończone są automatycznie archiwizowane <strong>7 dni</strong> po realizacji —
    znikają z aktywnej tablicy, ale zostają tutaj do wglądu. W każdej chwili można
    przywrócić zadanie z powrotem na tablicę.
  </div>
</div>

<?php if (!$workspace): ?>
<div class="tw-text-center tw-text-slate-400 tw-py-12">
  <i class="bi bi-inbox tw-text-3xl tw-block tw-mb-2 tw-opacity-50" aria-hidden="true"></i>
  Nie masz dostępu do żadnego obszaru roboczego.
</div>
<?php elseif (!$archived): ?>
<div class="tw-text-center tw-text-slate-400 tw-py-12">
  <i class="bi bi-archive tw-text-3xl tw-block tw-mb-2 tw-opacity-50" aria-hidden="true"></i>
  Brak zarchiwizowanych zadań w tym obszarze.
</div>
<?php else: ?>
<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr>
          <th scope="col" class="ps-3">Zadanie</th>
          <th scope="col">Lista</th>
          <th scope="col">Ukończono</th>
          <th scope="col">Zarchiwizowano</th>
          <th scope="col">Przypisani</th>
          <?php if ($can_edit): ?><th scope="col" class="text-center pe-3">Akcja</th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="arch-tbody">
        <?php foreach ($archived as $t): ?>
        <tr id="arch-row-<?= (int)$t['id'] ?>">
          <td class="ps-3"><?= h($t['title']) ?></td>
          <td><span class="text-muted"><?= h($t['list_name']) ?></span></td>
          <td><?= h(substr($t['completed_at'] ?? '', 0, 10)) ?></td>
          <td><?= h(substr($t['archived_at'] ?? '', 0, 10)) ?></td>
          <td><?= $t['assignees'] ? h(implode(', ', array_column($t['assignees'], 'name'))) : '—' ?></td>
          <?php if ($can_edit): ?>
          <td class="text-center pe-3">
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="archRestore(<?= (int)$t['id'] ?>, this)">
              <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Przywróć
            </button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
const ARCH_CSRF = <?= json_encode($csrf) ?>;
const ARCH_BASE = <?= json_encode(rtrim(APP_URL, '/')) ?>;

function archRestore(id, btn) {
    if (!confirm('Przywrócić to zadanie na aktywną tablicę?')) return;
    btn.disabled = true;
    fetch(ARCH_BASE + '/tasks/api/task.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: ARCH_CSRF, action: 'unarchive', id: id})
    })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                const row = document.getElementById('arch-row-' + id);
                if (row) row.remove();
            } else {
                btn.disabled = false;
                alert(r.error || 'Błąd.');
            }
        })
        .catch(() => { btn.disabled = false; alert('Błąd połączenia.'); });
}
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
