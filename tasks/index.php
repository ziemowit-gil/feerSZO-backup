<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/org.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');
task_areas_migrate();

try {
    $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    if (($_tm['value'] ?? '1') === '0') {
        flash_set('error', 'Moduł zadań jest wyłączony przez administratora.');
        header('Location: ' . APP_URL . '/index.php'); exit;
    }
} catch (\Throwable $e) {}

$user     = current_user();
$uid      = (int)$user['id'];
$is_admin = is_admin();

$workspaces   = task_user_workspaces($uid);
$ws_id_param  = (int)($_GET['ws'] ?? 0);
$ws_id        = $ws_id_param;

// Jeśli podany ws nie należy do listy dostępnych — przekieruj na pierwszy dostępny
if ($ws_id && $workspaces && !in_array($ws_id, array_column($workspaces, 'id'), false)) {
    header('Location: ' . APP_URL . '/tasks/index.php?ws=' . (int)$workspaces[0]['id']);
    exit;
}
if (!$ws_id && $workspaces) {
    $ws_id = (int)$workspaces[0]['id'];
}

// Jednostki org — do filtra, modala i znacznika "moje" w pętli zadań poniżej
$all_org_units = [];
$uid_units     = []; // ID jednostek, do których należy bieżący użytkownik
try {
    $all_org_units = db_all("SELECT id, name, short_name FROM org_units WHERE status='active' ORDER BY name");
    $uid_units = array_map('intval', array_column(
        db_all("SELECT unit_id FROM org_members WHERE user_id=? AND status='active'", [$uid]),
        'unit_id'
    ));
} catch (\Throwable $e) {}

// Inicjalizacja zmiennych obszaru (uzupełnione po wyborze $ws_id)
$ws_members_for_assign = [];
$my_notify_prefs = ['notify_email' => 1, 'notify_sms' => 0, 'notify_push' => 0];

$workspace = null;
$tasks_raw = [];
$lists_map = [];

if ($ws_id) {
    task_require_workspace_access($ws_id);
    $workspace = db_one("SELECT * FROM task_workspaces WHERE id=? AND is_active=1", [$ws_id]);
    if ($workspace) {
        // Mapa list obszaru (bez kolumny is_active — nie istnieje)
        $lists_in_ws = db_all(
            "SELECT id, name, color, is_done_state FROM task_lists WHERE workspace_id=? ORDER BY position",
            [$ws_id]
        );
        foreach ($lists_in_ws as $l) {
            $lists_map[$l['id']] = $l;
        }

        // Wszystkie zadania płasko
        $tasks_raw = db_all(
            "SELECT t.*,
                    tl.name  AS list_name,
                    tl.color AS list_color,
                    tl.is_done_state,
                    ou.name  AS unit_name,
                    ou.short_name AS unit_short,
                    (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id)            AS assignee_count,
                    (SELECT COUNT(*) FROM task_subtasks   ts WHERE ts.task_id = t.id)             AS st_total,
                    (SELECT COUNT(*) FROM task_subtasks   ts WHERE ts.task_id = t.id AND ts.is_done=1) AS st_done
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.list_id
             LEFT JOIN org_units ou ON ou.id = t.unit_id
             WHERE t.workspace_id = ? AND t.deleted_at IS NULL
             ORDER BY
               CASE WHEN t.completed_at IS NULL AND t.due_date IS NOT NULL
                         AND t.due_date < date('now') THEN 0 ELSE 1 END,
               t.priority DESC,
               t.due_date  ASC NULLS LAST,
               t.created_at DESC",
            [$ws_id]
        );

        foreach ($tasks_raw as &$t) {
            $t['tags'] = db_all(
                "SELECT tt.* FROM task_task_tags ttt
                 JOIN task_tags tt ON tt.id = ttt.tag_id
                 WHERE ttt.task_id = ? ORDER BY tt.name",
                [$t['id']]
            );
            $t['assignees'] = db_all(
                "SELECT u.id, u.name FROM task_assignments ta
                 JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY u.name",
                [$t['id']]
            );
            if ($t['completed_at'] || $t['is_done_state']) {
                $t['_status'] = 'done';
            } elseif ((int)$t['assignee_count'] > 0) {
                $t['_status'] = 'taken';
            } else {
                $t['_status'] = 'open';
            }
            $t['_mine']   = in_array($uid, array_column($t['assignees'], 'id'), true)
                         || (!empty($t['unit_id']) && in_array((int)$t['unit_id'], $uid_units, true));
            $t['_overdue']= $t['due_date'] && !$t['completed_at']
                            && strtotime($t['due_date']) < strtotime('today');
        }
        unset($t);
    }
}

$available_tags = [];
if ($ws_id) {
    $available_tags = db_all(
        "SELECT * FROM task_tags WHERE is_active=1 AND (workspace_id IS NULL OR workspace_id=?) ORDER BY name",
        [$ws_id]
    );
}

$my_role = $ws_id ? task_workspace_role($ws_id, $uid) : null;
$can_add = in_array($my_role, ['admin', 'editor'], true);

// Dane per-obszar: picker osób + prefs powiadomień
if ($ws_id) {
    try {
        $ws_members_for_assign = db_all(
            "SELECT twm.user_id, u.name
             FROM task_workspace_members twm
             JOIN users u ON u.id = twm.user_id
             WHERE twm.workspace_id = ? AND u.is_active = 1
             ORDER BY u.name",
            [$ws_id]
        );
        $np = db_one(
            "SELECT notify_email, notify_sms, notify_push
             FROM task_workspace_members WHERE workspace_id=? AND user_id=?",
            [$ws_id, $uid]
        );
        if ($np) $my_notify_prefs = $np;
    } catch (\Throwable $e) {}
}

// Filtry
$filter_status   = $_GET['status'] ?? 'all';
$filter_priority = (int)($_GET['pri'] ?? 0);
$filter_tag      = (int)($_GET['tag'] ?? 0);
$filter_list     = (int)($_GET['list'] ?? 0);
$filter_area     = (int)($_GET['area'] ?? 0);
$filter_unit     = (int)($_GET['unit'] ?? 0);
$filter_q        = trim($_GET['q'] ?? '');
$all_areas       = task_get_areas();

// Zastosuj filtry
$tasks = array_filter($tasks_raw, function ($t) use ($filter_status, $filter_priority, $filter_tag, $filter_list, $filter_area, $filter_unit, $filter_q, $uid) {
    if ($filter_status === 'open'  && $t['_status'] !== 'open')  return false;
    if ($filter_status === 'taken' && $t['_status'] !== 'taken') return false;
    if ($filter_status === 'done'  && $t['_status'] !== 'done')  return false;
    if ($filter_status === 'mine'  && !$t['_mine'])              return false;
    if ($filter_priority && (int)$t['priority'] !== $filter_priority) return false;
    if ($filter_tag  && !in_array($filter_tag,  array_column($t['tags'], 'id'), true)) return false;
    if ($filter_list && (int)$t['list_id'] !== $filter_list)    return false;
    if ($filter_area && (int)($t['area_id'] ?? 0) !== $filter_area) return false;
    if ($filter_unit && (int)($t['unit_id'] ?? 0) !== $filter_unit) return false;
    if ($filter_q    && mb_stripos($t['title'] . ' ' . ($t['description'] ?? ''), $filter_q) === false) return false;
    return true;
});

// Liczniki
$cnt = ['all' => count($tasks_raw), 'open' => 0, 'taken' => 0, 'done' => 0, 'mine' => 0];
foreach ($tasks_raw as $t) {
    $cnt[$t['_status']]++;
    if ($t['_mine']) $cnt['mine']++;
}

$view_mode = $_GET['view'] ?? 'list'; // 'list' | 'kanban'

// ── Helper: renderuje region listy / kanbana (używany też przez ?_ajax=1) ─
function _tasks_list_html(array $tasks, array $cnt, array $lists_map, int $ws_id, string $view_mode, ?array $workspace, bool $can_add, bool $is_admin, array $all_org_units = []): string
{
    ob_start(); ?>
<?php if ($view_mode === 'kanban'): ?>
<!-- ── Widok Kanban ──────────────────────────────────────────────────────── -->
<div class="tk-kanban" id="tkListRegion">
  <?php foreach ($lists_map as $lid => $list): ?>
  <?php $col_tasks = array_values(array_filter($tasks, fn($t) => (int)$t['list_id'] === (int)$lid)); ?>
  <div class="tk-kanban-col" data-list-id="<?= (int)$lid ?>">
    <div class="tk-kanban-hdr" style="border-top:3px solid <?= h($list['color'] ?: '#94a3b8') ?>">
      <div class="d-flex align-items-center gap-2">
        <?php if ($list['is_done_state']): ?>
        <i class="bi bi-check-circle-fill text-success" style="font-size:.8rem" aria-hidden="true"></i>
        <?php endif; ?>
        <span class="fw-semibold"><?= h($list['name']) ?></span>
        <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.68rem"><?= count($col_tasks) ?></span>
      </div>
      <?php if ($can_add): ?>
      <button class="tk-col-add" title="Dodaj zadanie w tej kolumnie"
              aria-label="Dodaj zadanie w kolumnie <?= h($list['name'] ?? '') ?>"
              onclick="openAddModal(<?= (int)$lid ?>)">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
      </button>
      <?php endif; ?>
    </div>
    <div class="tk-kanban-body" data-list-id="<?= (int)$lid ?>">
      <?php foreach ($col_tasks as $t): ?>
      <?php
        $k_pri_color = match((int)$t['priority']) {
          4 => '#dc2626', 3 => '#f59e0b', 2 => '#3b82f6', default => '#94a3b8'
        };
        $k_pri_label = ['','Niski','Normalny','Wysoki','Krytyczny'][(int)$t['priority']] ?? '';
        $k_unit_name = $t['unit_short'] ?: ($t['unit_name'] ?? null);
      ?>
      <div class="tk-card <?= $t['_status']==='done'?'tk-card-done':'' ?>"
           data-task-id="<?= (int)$t['id'] ?>"
           role="button" tabindex="0"
           aria-label="Zadanie: <?= h($t['title']) ?><?= $k_unit_name ? ', '.h($k_unit_name) : '' ?>, priorytet <?= h($k_pri_label) ?><?= $t['_overdue'] ? ', po terminie' : '' ?>"
           onclick="openTask(<?= (int)$t['id'] ?>)"
           onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openTask(<?= (int)$t['id'] ?>)}">
        <div class="tk-card-pri-bar" style="background:<?= $k_pri_color ?>" aria-hidden="true"></div>
        <div class="tk-card-inner">
          <div class="tk-card-title"><?= h($t['title']) ?></div>
          <?php if ($k_unit_name): ?>
          <div class="tk-card-unit">
            <i class="bi bi-diagram-3" aria-hidden="true"></i> <?= h($k_unit_name) ?>
          </div>
          <?php endif; ?>
          <div class="tk-card-footer">
            <div class="d-flex align-items-center gap-1">
              <?php if ($t['due_date']): ?>
              <span class="tk-card-due <?= $t['_overdue'] ? 'overdue' : '' ?>">
                <i class="bi bi-calendar3" aria-hidden="true"></i> <?= h(date('d.m', strtotime($t['due_date']))) ?>
              </span>
              <?php endif; ?>
              <?php if ($t['st_total'] > 0): ?>
              <span class="tk-card-st" title="Podzadania">
                <i class="bi bi-check2-square" aria-hidden="true"></i> <?= (int)$t['st_done'] ?>/<?= (int)$t['st_total'] ?>
              </span>
              <?php endif; ?>
            </div>
            <div class="tk-card-avstack">
              <?php foreach (array_slice($t['assignees'], 0, 3) as $a): ?>
              <span class="tk-card-asgn" title="<?= h($a['name']) ?>"><?= h(mb_substr($a['name'],0,1)) ?></span>
              <?php endforeach; ?>
              <?php if (count($t['assignees']) > 3): ?>
              <span class="tk-card-asgn" style="background:#94a3b8">+<?= count($t['assignees'])-3 ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($col_tasks)): ?>
      <div class="tk-card-drop-hint">Przeciągnij tu lub kliknij +</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<!-- ── Tabela zadań ──────────────────────────────────────────────────────── -->
<div class="tk-wrap" id="tkListRegion">

  <!-- Pasek zbiorczych akcji -->
  <div class="tk-bulk-bar" id="tk-bulk-bar" role="toolbar" aria-label="Zbiorcze akcje">
    <span class="tk-bulk-count" id="tk-bulk-count" aria-live="polite">0 zaznaczonych</span>
    <div class="tk-bulk-sep"></div>

    <button class="tk-bulk-btn" onclick="bulkAction('assign_me')"
            aria-label="Przypisz zaznaczone zadania do siebie">
      <i class="bi bi-person-check" aria-hidden="true"></i>Przypisz do mnie
    </button>

    <button class="tk-bulk-btn" onclick="bulkAction('complete')"
            aria-label="Oznacz zaznaczone jako ukończone">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>Zakończ
    </button>

    <label class="visually-hidden" for="tk-bulk-pri">Zmień priorytet</label>
    <select id="tk-bulk-pri" class="tk-bulk-pri-sel"
            onchange="if(this.value){bulkAction('priority',{priority:parseInt(this.value)});this.value=''}"
            aria-label="Zmień priorytet zaznaczonych zadań">
      <option value="">⚑ Priorytet…</option>
      <option value="4">🔴 Krytyczny</option>
      <option value="3">🟡 Wysoki</option>
      <option value="2">🔵 Normalny</option>
      <option value="1">⚪ Niski</option>
    </select>

    <?php if (!empty($lists_map)): ?>
    <label class="visually-hidden" for="tk-bulk-list">Przenieś do listy</label>
    <select id="tk-bulk-list" class="tk-bulk-list-sel"
            onchange="if(this.value){bulkAction('move',{list_id:parseInt(this.value)});this.value=''}"
            aria-label="Przenieś zaznaczone zadania do wybranej kolumny">
      <option value="">↦ Przenieś do…</option>
      <?php foreach ($lists_map as $l): ?>
      <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <?php if ($can_add): ?>
    <div class="tk-bulk-sep"></div>
    <button class="tk-bulk-btn danger" onclick="bulkAction('delete')"
            aria-label="Usuń zaznaczone zadania">
      <i class="bi bi-trash3" aria-hidden="true"></i>Usuń
    </button>
    <?php endif; ?>

    <button class="tk-bulk-close" onclick="bulkClear()"
            aria-label="Anuluj zaznaczenie">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>

  <div style="overflow-x:auto">
    <table class="tk-table"
           id="task-table"
           role="grid"
           aria-label="Zadania obszaru <?= $workspace ? h($workspace['name']) : '' ?>"
           aria-rowcount="<?= count($tasks) ?>">
      <thead>
        <tr>
          <th scope="col" class="th-check">
            <input type="checkbox" class="tk-row-check" id="tk-check-all"
                   onchange="bulkToggleAll(this)"
                   aria-label="Zaznacz wszystkie zadania">
          </th>
          <th scope="col" style="width:3%" aria-label="Priorytet i tytuł">Zadanie</th>
          <th scope="col" style="width:10%" class="th-center">Status</th>
          <th scope="col" style="width:6%"  class="th-center" aria-label="Weryfikacja wykonania">Weryf.</th>
          <th scope="col" style="width:10%">Kategoria</th>
          <th scope="col" style="width:8%"  class="th-center">Priorytet</th>
          <th scope="col" style="width:8%">Termin</th>
          <th scope="col" style="width:10%">Postęp</th>
          <th scope="col" style="width:12%">Tagi</th>
          <th scope="col" style="width:10%">Przypisani</th>
          <th scope="col" style="width:9%"  class="th-center">Akcja</th>
        </tr>
      </thead>
      <tbody id="tk-tbody">

      <?php if (!$tasks): ?>
      <tr>
        <td colspan="11">
          <?php
          $is_brand_new = ($workspace && (int)($workspace['task_count'] ?? 0) === 0 && empty($lists_map));
          ?>
          <?php if ($is_brand_new && $can_add): ?>
          <div style="padding:2rem 1.5rem;text-align:center;max-width:480px;margin:0 auto">
            <div style="font-size:2.5rem;margin-bottom:.75rem">🎉</div>
            <div style="font-weight:700;font-size:1rem;color:#0f172a;margin-bottom:.35rem">Obszar „<?= $workspace ? h($workspace['name']) : '' ?>" jest gotowy!</div>
            <p style="font-size:.83rem;color:#64748b;line-height:1.6;margin-bottom:1.25rem">
              Teraz dodaj pierwsze zadania. Możesz też najpierw stworzyć listy
              (np. „Do zrobienia" / „W trakcie" / „Gotowe") żeby lepiej porządkować pracę.
            </p>
            <div style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap">
              <button type="button" onclick="openAddModal()"
                      style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:.55rem 1.1rem;font-size:.83rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem">
                <i class="bi bi-plus-lg"></i>Dodaj pierwsze zadanie
              </button>
              <?php if ($is_admin): ?>
              <a href="<?= APP_URL ?>/admin/tasks_workspaces.php"
                 style="background:#f8fafc;color:#374151;border:1.5px solid #e2e8f0;border-radius:8px;padding:.5rem 1rem;font-size:.83rem;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem">
                <i class="bi bi-list-ul"></i>Zarządzaj listami
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="tk-empty">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <p class="fw-semibold mb-1">Brak zadań spełniających kryteria</p>
            <p class="small mb-0">Zmień filtry lub <button type="button" class="btn btn-link btn-sm p-0" onclick="openAddModal()">dodaj nowe zadanie</button>.</p>
          </div>
          <?php endif; ?>
        </td>
      </tr>

      <?php else: foreach ($tasks as $task):
        $pri_meta = [
          4 => ['🔴','Krytyczny','#dc2626'],
          3 => ['🟡','Wysoki',   '#f59e0b'],
          2 => ['🔵','Normalny', '#3b82f6'],
          1 => ['⚪','Niski',    '#94a3b8'],
        ][(int)$task['priority']] ?? ['⚪','Normalny','#94a3b8'];

        $st_total = (int)$task['st_total'];
        $st_done  = (int)$task['st_done'];
        $st_pct   = $st_total ? round($st_done/$st_total*100) : 0;

        $status_info = match($task['_status']) {
          'open'  => ['s-open',  'Do zrobienia', 'bi-circle'],
          'taken' => ['s-taken', 'Przydzielone', 'bi-person-fill'],
          'done'  => ['s-done',  'Ukończone',    'bi-check-circle-fill'],
          default => ['s-open',  'Do zrobienia', 'bi-circle'],
        };

        $row_label = h($task['title'])
          . ', status: ' . $status_info[1]
          . ', priorytet: ' . $pri_meta[1]
          . ($task['_overdue'] ? ', po terminie' : '');
      ?>
      <tr class="<?= $task['_status']==='done'?'row-done':'' ?>"
          data-pri="<?= $task['priority'] ?>"
          data-task-id="<?= $task['id'] ?>"
          tabindex="0"
          role="row"
          aria-label="<?= $row_label ?>"
          onclick="if(!event.target.closest('td.td-check')&&!event.target.closest('td:last-child')&&!event.target.closest('.tk-status-btn')&&!event.target.closest('.dropdown'))openTask(<?= $task['id'] ?>)"
          onkeydown="if((event.key==='Enter'||event.key===' ')&&!event.target.closest('input'))openTask(<?= $task['id'] ?>)">

        <!-- Checkbox -->
        <td class="td-check" onclick="event.stopPropagation()">
          <input type="checkbox"
                 class="tk-row-check tk-row-select"
                 data-id="<?= $task['id'] ?>"
                 onchange="bulkOnCheck(this)"
                 aria-label="Zaznacz zadanie: <?= h($task['title']) ?>">
        </td>

        <!-- Zadanie: tytuł + podtytuł -->
        <td>
          <div class="tk-title"><?= h($task['title']) ?></div>
          <?php if ($task['description']): ?>
          <div class="tk-subtitle"><?= h(mb_substr(strip_tags($task['description']),0,80)) ?></div>
          <?php endif; ?>
        </td>

        <!-- Status — klikalny przycisk inline -->
        <td class="th-center" style="text-align:center" onclick="event.stopPropagation()">
          <?php if ($task['_status'] === 'done'): ?>
          <button type="button" class="tk-status s-done tk-status-btn"
                  data-task-id="<?= $task['id'] ?>"
                  onclick="tkToggleDone(this, <?= $task['id'] ?>)"
                  title="Kliknij aby cofnąć ukończenie">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Ukończone
          </button>
          <?php else: ?>
          <button type="button" class="tk-status <?= $status_info[0] ?> tk-status-btn"
                  data-task-id="<?= $task['id'] ?>"
                  onclick="tkToggleDone(this, <?= $task['id'] ?>)"
                  title="Kliknij aby oznaczyć jako ukończone">
            <i class="bi <?= $status_info[2] ?>" aria-hidden="true"></i> <?= $status_info[1] ?>
          </button>
          <?php endif; ?>
        </td>

        <!-- Weryfikacja wykonania: Z / ZP / O -->
        <td class="th-center" style="text-align:center">
          <?php if ($task['_status'] === 'done'): ?>
            <?php if (!empty($task['confirmed_at'])): ?>
            <span class="tk-confirm-badge zp"
                  title="Potwierdzone przez zlecającego <?= h(substr($task['confirmed_at'],0,10)) ?>"
                  aria-label="Zakończone i potwierdzone przez zlecającego">ZP</span>
            <?php elseif (!empty($task['rejected_at'])): ?>
            <span class="tk-confirm-badge o"
                  title="Odrzucone przez zlecającego <?= h(substr($task['rejected_at'],0,10)) ?>. Powód: <?= h($task['rejection_reason'] ?? '') ?>"
                  aria-label="Zakończone, ale odrzucone przez zlecającego. Powód: <?= h($task['rejection_reason'] ?? '') ?>">O</span>
            <?php else: ?>
            <span class="tk-confirm-badge z"
                  title="Zakończone, ale niepotwierdzone przez zlecającego"
                  aria-label="Zakończone, ale niepotwierdzone przez zlecającego">Z</span>
            <?php endif; ?>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Kategoria -->
        <td>
          <?php $lc = $task['list_color'] ?: '#94a3b8'; ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= h($lc) ?>;flex-shrink:0" aria-hidden="true"></span>
            <?= h($task['list_name']) ?>
          </span>
        </td>

        <!-- Priorytet — dropdown inline -->
        <td style="text-align:center" onclick="event.stopPropagation()">
          <div class="dropdown d-inline-block">
            <button class="pri-btn btn btn-link btn-sm p-0" data-bs-toggle="dropdown"
                    style="text-decoration:none"
                    title="Priorytet: <?= h($pri_meta[1]) ?>"
                    aria-label="Priorytet: <?= h($pri_meta[1]) ?>. Kliknij, aby zmienić">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $pri_meta[2] ?>;vertical-align:middle" aria-hidden="true"></span>
            </button>
            <ul class="dropdown-menu shadow-sm py-1">
              <?php foreach ([4=>'🔴 Krytyczny',3=>'🟡 Wysoki',2=>'🔵 Normalny',1=>'⚪ Niski'] as $p=>$pl): ?>
              <li><button class="dropdown-item" onclick="tkSetPriority(<?= $task['id'] ?>, <?= $p ?>)"><?= $pl ?></button></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </td>

        <!-- Termin -->
        <td>
          <?php if ($task['due_date']): ?>
          <span class="td-due <?= $task['_overdue']?'overdue':'' ?>"
                aria-label="Termin: <?= h($task['due_date']) ?><?= $task['_overdue']?' (po terminie)':'' ?>">
            <?php if ($task['_overdue']): ?>
            <i class="bi bi-alarm me-1" aria-hidden="true"></i>
            <?php else: ?>
            <i class="bi bi-calendar3 me-1 text-muted" aria-hidden="true"></i>
            <?php endif; ?>
            <?= h(date('d.m.Y', strtotime($task['due_date']))) ?>
          </span>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Postęp podzadań -->
        <td>
          <?php if ($st_total > 0): ?>
          <div class="tk-prog-wrap"
               aria-label="Podzadania: <?= $st_done ?>/<?= $st_total ?>">
            <div class="tk-prog-track"
                 role="progressbar"
                 aria-valuenow="<?= $st_pct ?>"
                 aria-valuemin="0" aria-valuemax="100">
              <div class="tk-prog-fill <?= $st_done===$st_total?'bg-success':'bg-primary' ?>"
                   style="width:<?= $st_pct ?>%"></div>
            </div>
            <span class="tk-prog-label"><?= $st_done ?>/<?= $st_total ?></span>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Tagi -->
        <td>
          <?php if ($task['tags']): ?>
          <div class="tk-tags">
            <?php foreach (array_slice($task['tags'],0,3) as $tag): ?>
            <span class="tk-tag"
                  style="background:<?= h($tag['color']) ?>;color:<?= h($tag['text_color']) ?>">
              <?= h($tag['name']) ?>
            </span>
            <?php endforeach; ?>
            <?php if (count($task['tags'])>3): ?>
            <span class="tk-tag" style="background:#f1f5f9;color:#64748b">
              +<?= count($task['tags'])-3 ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Przypisani + jednostka -->
        <td>
          <?php if ($task['assignees']): ?>
          <div class="tk-av-stack" aria-label="Przypisani: <?= h(implode(', ', array_column($task['assignees'],'name'))) ?>">
            <?php foreach (array_slice($task['assignees'],0,4) as $a): ?>
            <?= task_avatar_initials($a['name'], '#2563eb', '#fff') ?>
            <?php endforeach; ?>
            <?php if (count($task['assignees'])>4): ?>
            <span class="tk-av" style="background:#64748b"
                  aria-label="+<?= count($task['assignees'])-4 ?> więcej">
              +<?= count($task['assignees'])-4 ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">Brak</span>
          <?php endif; ?>
          <?php if (!empty($task['unit_name'])): ?>
          <div class="mt-1"><?= task_unit_badge((int)$task['unit_id']) ?></div>
          <?php endif; ?>
        </td>

        <!-- Akcja (klik zatrzymuje propagację) -->
        <td style="text-align:center" onclick="event.stopPropagation()">
          <?php if ($task['_status'] !== 'done'): ?>
          <div class="tk-actions justify-content-center">
            <?php if ($task['_mine']): ?>
            <button type="button"
                    class="btn-unclaim"
                    onclick="claimTask(<?= $task['id'] ?>,'remove',this)"
                    aria-label="Oddaj zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-person-dash me-1" aria-hidden="true"></i>Oddaj
            </button>
            <?php elseif ($task['_status'] === 'open'): ?>
            <button type="button"
                    class="btn-claim"
                    onclick="claimTask(<?= $task['id'] ?>,'add',this)"
                    aria-label="Weź zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Weź
            </button>
            <?php else: ?>
            <span class="text-muted" style="font-size:.73rem">—</span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.73rem">—</span>
          <?php endif; ?>
        </td>

      </tr>
      <?php endforeach; endif; ?>

      </tbody>
    </table>
  </div>

  <!-- Stopka z licznikiem -->
  <?php if ($tasks): ?>
  <div class="px-3 py-2 border-top" style="background:var(--tk-bg-soft);font-size:.75rem;color:var(--tk-muted)">
    Pokazano <strong><?= count($tasks) ?></strong> zadań
    <?php if ($cnt['all'] !== count($tasks)): ?>
    z <strong><?= $cnt['all'] ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Legenda kolumny "Weryf." -->
  <div class="tk-legend" aria-label="Legenda kolumny weryfikacji wykonania">
    <span><span class="tk-confirm-badge z" aria-hidden="true">Z</span> Zakończone, ale niepotwierdzone</span>
    <span><span class="tk-confirm-badge zp" aria-hidden="true">ZP</span> Zakończone i potwierdzone przez zlecającego</span>
    <span><span class="tk-confirm-badge o" aria-hidden="true">O</span> Odrzucone przez zlecającego (z podanym powodem)</span>
  </div>

</div>
<?php endif; ?>
<?php
    return ob_get_clean();
}

// ── AJAX — zwróć tylko listę/kanban ───────────────────────────────────────
if (isset($_GET['_ajax'])) {
    ob_start();
    echo _tasks_list_html($tasks, $cnt, $lists_map, $ws_id, $view_mode, $workspace, $can_add, $is_admin, $all_org_units);
    echo json_encode(['ok'=>true,'total'=>count($tasks),'list_html'=>ob_get_clean(),'counts'=>$cnt]);
    exit;
}

$PAGE_TITLE       = $workspace ? h($workspace['name']) : 'Zadania';
$PAGE_SUBTITLE    = $view_mode === 'kanban' ? 'Widok Kanban' : 'Widok tabelaryczny';
$TASKS_BREADCRUMB = $workspace ? h($workspace['name']) : 'Zadania';
$TASKS_WS_ID      = $ws_id;
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
/* ── Tokeny ─────────────────────────────────────────────────────────────── */
:root {
  --tk-focus:   #2563eb;
  --tk-border:  #e2e8f0;
  --tk-bg-soft: #f8fafc;
  --tk-text:    #0f172a;
  --tk-muted:   #64748b;
  --tk-radius:  .5rem;
  --tk-open:    #16a34a;
  --tk-taken:   #2563eb;
  --tk-done:    #64748b;
}

/* Skip link */
.skip-link{position:absolute;top:-3rem;left:1rem;z-index:9999;background:var(--tk-focus);
  color:#fff;padding:.4rem .9rem;border-radius:0 0 .4rem .4rem;font-size:.85rem;
  font-weight:600;text-decoration:none;transition:top .15s}
.skip-link:focus{top:0}

/* SR announce */
#tk-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

/* Kropka koloru obszaru — używana w nagłówku bieżącego obszaru poniżej */
.ws-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* ── Pasek filtrów ──────────────────────────────────────────────────────── */
.tk-toolbar{
  display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;
  background:#fff;border:1px solid var(--tk-border);
  border-radius:var(--tk-radius);
  padding:.55rem .75rem;
  margin-bottom:.85rem;
}
.tk-sep{width:1px;height:1.3rem;background:#e2e8f0;flex-shrink:0}

/* Status pills */
.tk-pill{
  display:inline-flex;align-items:center;gap:.3rem;
  padding:.22rem .65rem;border-radius:2rem;
  font-size:.77rem;font-weight:600;
  border:1.5px solid transparent;
  text-decoration:none;
  background:#f1f5f9;color:var(--tk-muted);
  transition:all .12s;white-space:nowrap;
}
.tk-pill:hover{border-color:#94a3b8}
.tk-pill.active{background:var(--tk-text);color:#fff;border-color:var(--tk-text)}
.tk-pill[data-s="open"].active  {background:var(--tk-open);border-color:var(--tk-open)}
.tk-pill[data-s="taken"].active {background:var(--tk-taken);border-color:var(--tk-taken)}
.tk-pill[data-s="done"].active  {background:var(--tk-done);border-color:var(--tk-done)}
.tk-pill[data-s="mine"].active  {background:#7c3aed;border-color:#7c3aed}
.tk-pill:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.pill-n{font-size:.67rem;opacity:.75}

.tk-select{
  font-size:.8rem;padding:.25rem .55rem;border-radius:.4rem;
  border:1.5px solid #e2e8f0;background:#fff;color:var(--tk-text);cursor:pointer;
}
.tk-select:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}

/* Przycisk "Filtry" + panel (priorytet/kategoria/tag/obszar/jednostka) */
.tk-filters-btn{
  display:inline-flex;align-items:center;gap:.35rem;
  padding:.25rem .7rem;border-radius:.4rem;
  border:1.5px solid #e2e8f0;background:#fff;color:var(--tk-text);
  font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap;
  transition:border-color .12s,color .12s;
}
.tk-filters-btn:hover{border-color:#94a3b8}
.tk-filters-btn.has-active{border-color:var(--tk-focus);color:var(--tk-focus)}
.tk-filters-badge{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:1.2rem;height:1.2rem;padding:0 .3rem;border-radius:999px;
  background:var(--tk-focus);color:#fff;font-size:.65rem;font-weight:700;
}
.tk-filters-panel{width:280px;padding:.85rem}
.tk-filters-field{margin-bottom:.65rem}
.tk-filters-field:last-of-type{margin-bottom:0}
.tk-filters-field label{
  display:block;font-size:.7rem;font-weight:700;color:var(--tk-muted);
  text-transform:uppercase;letter-spacing:.03em;margin-bottom:.25rem;
}
.tk-filters-field .tk-select{width:100%}
.tk-filters-panel-footer{
  display:flex;align-items:center;justify-content:flex-start;
  margin-top:.75rem;padding-top:.65rem;border-top:1px solid #f1f5f9;
}

.tk-search{
  font-size:.82rem;padding:.28rem .65rem;border-radius:.4rem;
  border:1.5px solid #e2e8f0;background:#fff;
  width:180px;min-width:120px;
}
.tk-search:focus{outline:2px solid var(--tk-focus);outline-offset:2px;border-color:transparent}

/* ── Tabela zadań ───────────────────────────────────────────────────────── */
.tk-wrap{
  background:#fff;
  border:1px solid var(--tk-border);
  border-radius:var(--tk-radius);
  overflow:hidden;
}

/* ── Bulk action bar ─────────────────────────────────────────────────── */
.tk-bulk-bar {
  display: none;
  align-items: center;
  gap: .5rem;
  padding: .55rem .85rem;
  background: #eff6ff;
  border-bottom: 1px solid #bfdbfe;
  flex-wrap: wrap;
}
.tk-bulk-bar.visible { display: flex; }
.tk-bulk-count {
  font-size: .82rem; font-weight: 700; color: var(--tk-taken);
  white-space: nowrap;
}
.tk-bulk-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .25rem .65rem; border-radius: .35rem;
  font-size: .78rem; font-weight: 600;
  border: 1.5px solid #dbeafe; background: #fff; color: #1d4ed8;
  cursor: pointer; white-space: nowrap; transition: all .1s;
}
.tk-bulk-btn:hover { background: #dbeafe; border-color: #93c5fd; }
.tk-bulk-btn:focus-visible { outline: 2px solid var(--tk-focus); }
.tk-bulk-btn.danger { color: #dc2626; border-color: #fecaca; }
.tk-bulk-btn.danger:hover { background: #fef2f2; border-color: #dc2626; }
.tk-bulk-sep { width: 1px; height: 1.2rem; background: #bfdbfe; flex-shrink: 0; }
.tk-bulk-close {
  margin-left: auto; background: none; border: none;
  color: #64748b; cursor: pointer; font-size: .85rem; padding: .1rem .3rem;
}
.tk-bulk-close:hover { color: #dc2626; }

/* Checkbox column */
.tk-table th.th-check, .tk-table td.td-check {
  width: 36px; text-align: center; padding: 0 .5rem;
}
.tk-row-check {
  width: 15px; height: 15px; cursor: pointer; accent-color: var(--tk-taken);
}
.tk-table tbody tr.selected { background: #eff6ff !important; }

/* Bulk select dropdown */
.tk-bulk-pri-sel, .tk-bulk-list-sel {
  font-size: .78rem; padding: .22rem .5rem; border-radius: .35rem;
  border: 1.5px solid #dbeafe; background: #fff; color: #1d4ed8; cursor: pointer;
}

.tk-table{
  width:100%;
  border-collapse:collapse;
  font-size:.84rem;
}

/* Nagłówek tabeli */
.tk-table thead th{
  background:var(--tk-bg-soft);
  font-size:.72rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.06em;
  color:var(--tk-muted);
  padding:.55rem .75rem;
  border-bottom:2px solid var(--tk-border);
  white-space:nowrap;
  text-align:left;
}
.tk-table thead th.th-center{text-align:center}
.tk-table thead th:first-child{padding-left:1rem}

/* Wiersze */
.tk-table tbody tr{
  border-bottom:1px solid #f1f5f9;
  cursor:pointer;
  transition:background .1s;
}
.tk-table tbody tr:last-child{border-bottom:none}
.tk-table tbody tr:hover{background:#f8fafc}
.tk-table tbody tr:focus-visible{
  outline:2px solid var(--tk-focus);outline-offset:-2px;
  background:#eff6ff;
}
.tk-table tbody tr.row-done{opacity:.65}

/* Komórki */
.tk-table td{
  padding:.6rem .75rem;
  vertical-align:middle;
  color:var(--tk-text);
}
.tk-table td:first-child{padding-left:1rem}

/* Pasek priorytetu (lewa krawędź) */
.tk-table tbody tr td:first-child{
  border-left:3px solid transparent;
}
.tk-table tbody tr[data-pri="4"] td:first-child{border-left-color:#dc2626}
.tk-table tbody tr[data-pri="3"] td:first-child{border-left-color:#f59e0b}
.tk-table tbody tr[data-pri="2"] td:first-child{border-left-color:#3b82f6}
.tk-table tbody tr[data-pri="1"] td:first-child{border-left-color:#94a3b8}

/* Tytuł */
.tk-title{
  font-weight:600;color:var(--tk-text);line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;
}
.tk-subtitle{font-size:.72rem;color:var(--tk-muted);margin-top:.1rem}

/* Status badge */
.tk-status{
  display:inline-flex;align-items:center;gap:.25rem;
  font-size:.7rem;font-weight:700;padding:.15rem .5rem;
  border-radius:2rem;white-space:nowrap;
}
.s-open  {background:#dcfce7;color:#15803d}
.s-taken {background:#dbeafe;color:#1d4ed8}
.s-done  {background:#f1f5f9;color:#64748b}

/* Badge weryfikacji wykonania (Z / ZP / O) */
.tk-confirm-badge{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:1.35rem;height:1.15rem;padding:0 .3rem;
  border-radius:.3rem;font-size:.62rem;font-weight:800;letter-spacing:.02em;
  vertical-align:middle;
}
.tk-confirm-badge.z  {background:#fef3c7;color:#b45309}
.tk-confirm-badge.zp {background:#ede9fe;color:#7c3aed}
.tk-confirm-badge.o  {background:#fee2e2;color:#dc2626}

/* Legenda pod tabelą */
.tk-legend{
  display:flex;flex-wrap:wrap;align-items:center;gap:.4rem 1.25rem;
  font-size:.75rem;color:#64748b;padding:.6rem .25rem .15rem;
  border-top:1px solid var(--tk-border,#e2e8f0);
}
.tk-legend .tk-confirm-badge{margin-right:.35rem}

/* Priorytet dot */
.pri-dot{
  display:inline-flex;align-items:center;gap:.3rem;
  font-size:.75rem;font-weight:600;white-space:nowrap;
}
.pri-dot i{font-size:.75rem}

/* Tagi */
.tk-tags{display:flex;flex-wrap:wrap;gap:.2rem}
.tk-tag{font-size:.64rem;padding:.08rem .38rem;border-radius:2rem;font-weight:700}

/* Postęp podzadań */
.tk-prog-wrap{display:flex;align-items:center;gap:.4rem}
.tk-prog-track{width:60px;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;flex-shrink:0}
.tk-prog-fill{height:100%;border-radius:2px}
.tk-prog-label{font-size:.68rem;color:var(--tk-muted);white-space:nowrap}

/* Avatary */
.tk-av-stack{display:flex}
.tk-av{display:inline-flex;align-items:center;justify-content:center;
  width:24px;height:24px;border-radius:50%;
  font-size:.58rem;font-weight:700;color:#fff;
  border:2px solid #fff;flex-shrink:0}
.tk-av-stack .tk-av+.tk-av{margin-left:-6px}

/* Kolumna akcji */
.tk-actions{display:flex;align-items:center;gap:.35rem}
.btn-claim{
  font-size:.73rem;font-weight:600;padding:.2rem .6rem;border-radius:2rem;
  border:1.5px solid var(--tk-open);color:var(--tk-open);background:#fff;
  white-space:nowrap;transition:all .12s;
}
.btn-claim:hover,.btn-claim:focus-visible{background:var(--tk-open);color:#fff}
.btn-claim:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.btn-claim:disabled{opacity:.5;cursor:not-allowed}
.btn-unclaim{
  font-size:.73rem;font-weight:600;padding:.2rem .6rem;border-radius:2rem;
  border:1.5px solid #e2e8f0;color:var(--tk-muted);background:#fff;
  white-space:nowrap;transition:all .12s;
}
.btn-unclaim:hover,.btn-unclaim:focus-visible{border-color:#dc2626;color:#dc2626}
.btn-unclaim:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}

/* Stan pusty */
.tk-empty{
  text-align:center;padding:3.5rem 1rem;color:var(--tk-muted);
}
.tk-empty i{font-size:2rem;display:block;margin-bottom:.6rem;opacity:.3}

/* Offcanvas */
#taskOffcanvas{width:600px;max-width:96vw}
#taskOffcanvas .offcanvas-header{border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem}
#taskOffcanvas .offcanvas-body{padding:0;overflow-y:auto}

/* Kolumna termin */
.td-due{white-space:nowrap;font-size:.78rem}
.td-due.overdue{color:#dc2626;font-weight:600}

/* Inline status / priority button */
.tk-status-btn{border:none;background:none;cursor:pointer;padding:.15rem .5rem;border-radius:2rem;font-size:.7rem;font-weight:700;display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap;transition:opacity .12s;}
.tk-status-btn:hover{opacity:.75}

/* ── Kanban ── */
.tk-kanban{display:flex;gap:1rem;overflow-x:auto;align-items:flex-start;padding-bottom:1.5rem}
.tk-kanban-col{min-width:256px;max-width:288px;background:#f8fafc;border-radius:10px;flex-shrink:0}
.tk-kanban-hdr{display:flex;justify-content:space-between;align-items:center;padding:.55rem .65rem .55rem;font-size:.82rem;border-bottom:1px solid #e2e8f0}
.tk-kanban-body{padding:.5rem;min-height:60px}
.tk-col-add{background:none;border:none;color:#94a3b8;cursor:pointer;padding:.15rem .3rem;border-radius:.3rem;font-size:.9rem;line-height:1;transition:all .12s;flex-shrink:0}
.tk-col-add:hover{color:#2563eb;background:#eff6ff}
.tk-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.4rem;cursor:pointer;transition:box-shadow .12s;overflow:hidden;display:flex}
.tk-card:hover{box-shadow:0 2px 10px rgba(0,0,0,.1);border-color:#cbd5e1}
.tk-card:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px;border-color:transparent}
.tk-card-done{opacity:.6}
.tk-card-pri-bar{width:3px;flex-shrink:0}
.tk-card-inner{flex:1;padding:.55rem .65rem;min-width:0}
.tk-card-title{font-size:.84rem;font-weight:500;color:#0f172a;line-height:1.4;margin-bottom:.25rem;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tk-card-unit{font-size:.68rem;color:#6d28d9;margin-bottom:.25rem;
  display:flex;align-items:center;gap:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-card-footer{display:flex;justify-content:space-between;align-items:center;gap:.3rem}
.tk-card-due{font-size:.7rem;color:#64748b;display:flex;align-items:center;gap:.2rem;white-space:nowrap}
.tk-card-due.overdue{color:#dc2626;font-weight:600}
.tk-card-st{font-size:.7rem;color:#64748b;display:flex;align-items:center;gap:.2rem;white-space:nowrap}
.tk-card-avstack{display:flex}
.tk-card-asgn{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#1d4ed8;font-size:.62rem;font-weight:700;border:2px solid #fff;flex-shrink:0}
.tk-card-avstack .tk-card-asgn+.tk-card-asgn{margin-left:-5px}
.tk-card-drop-hint{text-align:center;padding:.75rem .5rem;font-size:.75rem;color:#94a3b8;border:1.5px dashed #e2e8f0;border-radius:6px;margin:.25rem 0}
.tk-card-ghost{opacity:.4;background:#eff6ff!important;border-color:#93c5fd!important}
.tk-card-dragging{box-shadow:0 8px 24px rgba(0,0,0,.18);transform:rotate(1.5deg)}
/* Notify prefs modal */
.np-row{display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f1f5f9}
.np-row:last-child{border-bottom:none}
.np-icon{font-size:1.1rem;width:1.4rem;text-align:center;flex-shrink:0}
.np-label{flex:1;font-size:.87rem}
.np-label small{display:block;color:#94a3b8;font-size:.73rem;margin-top:.05rem}
</style>

<div id="tk-sr" aria-live="polite" aria-atomic="true"></div>

<?php if (!$workspaces): ?>
<!-- ══ ONBOARDING — brak obszarów ══════════════════════════════════════════ -->
<div style="max-width:680px;margin:2rem auto">

  <!-- Hero -->
  <div style="background:linear-gradient(135deg,#1e40af,#3b82f6);border-radius:16px;padding:2rem 2.5rem;color:#fff;margin-bottom:1.25rem;position:relative;overflow:hidden">
    <div style="position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.06);right:-60px;top:-60px"></div>
    <div style="font-size:2rem;margin-bottom:.75rem">📋</div>
    <h1 style="font-size:1.4rem;font-weight:800;margin:0 0 .4rem;letter-spacing:-.02em">Witaj w module Zadania!</h1>
    <p style="font-size:.88rem;opacity:.85;margin:0;line-height:1.6">
      Tu zarządzasz zadaniami organizacji — przypisujesz je do wolontariuszy,
      śledzisz postęp i widzisz kto nad czym pracuje.
    </p>
  </div>

  <?php if ($is_admin): ?>
  <!-- Kroki dla admina -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.5rem;margin-bottom:1rem">
    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:1rem">Jak zacząć — 3 kroki</div>

    <div style="display:flex;flex-direction:column;gap:.85rem">
      <div style="display:flex;align-items:flex-start;gap:1rem">
        <div style="width:32px;height:32px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0">1</div>
        <div>
          <div style="font-weight:600;font-size:.9rem;color:#0f172a">Utwórz obszar roboczy</div>
          <div style="font-size:.8rem;color:#64748b;margin-top:.15rem">Obszar to odpowiednik projektu lub działu — np. „Wolontariat 2026", „Komunikacja"</div>
          <a href="<?= APP_URL ?>/admin/tasks_workspaces.php" style="display:inline-flex;align-items:center;gap:.35rem;margin-top:.5rem;background:#2563eb;color:#fff;padding:.35rem .85rem;border-radius:7px;text-decoration:none;font-size:.8rem;font-weight:600">
            <i class="bi bi-plus-lg"></i>Utwórz obszar
          </a>
        </div>
      </div>

      <div style="display:flex;align-items:flex-start;gap:1rem;opacity:.5">
        <div style="width:32px;height:32px;border-radius:50%;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0">2</div>
        <div>
          <div style="font-weight:600;font-size:.9rem;color:#0f172a">Dodaj listy i zadania</div>
          <div style="font-size:.8rem;color:#64748b;margin-top:.15rem">W obszarze tworzysz listy (np. „Do zrobienia", „W trakcie", „Gotowe") i zadania w każdej z nich</div>
        </div>
      </div>

      <div style="display:flex;align-items:flex-start;gap:1rem;opacity:.5">
        <div style="width:32px;height:32px;border-radius:50%;background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0">3</div>
        <div>
          <div style="font-weight:600;font-size:.9rem;color:#0f172a">Przypisz wolontariuszy</div>
          <div style="font-size:.8rem;color:#64748b;margin-top:.15rem">Przypisuj zadania do konkretnych osób — wolontariusze widzą swoje zadania po zalogowaniu do panelu</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tip -->
  <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:1rem 1.25rem;display:flex;gap:.75rem;align-items:flex-start">
    <i class="bi bi-lightbulb-fill" style="color:#16a34a;flex-shrink:0;margin-top:.1rem"></i>
    <div style="font-size:.82rem;color:#15803d;line-height:1.5">
      <strong>Szybki start:</strong> Możesz też zaimportować tablice bezpośrednio z Trello —
      przejdź do <a href="<?= APP_URL ?>/admin/trello_import.php" style="color:#15803d;font-weight:600">Import z Trello</a> w panelu admina.
    </div>
  </div>

  <?php else: ?>
  <!-- Dla zwykłego użytkownika -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.75rem;text-align:center">
    <i class="bi bi-person-plus" style="font-size:2rem;color:#94a3b8;display:block;margin-bottom:.75rem"></i>
    <div style="font-weight:700;font-size:.95rem;color:#0f172a;margin-bottom:.3rem">Nie masz jeszcze przypisanego obszaru</div>
    <p style="font-size:.83rem;color:#64748b;line-height:1.6;margin:0">
      Poproś administratora lub koordynatora, aby dodał Cię do obszaru roboczego.
      Po przypisaniu zobaczysz tutaj swoje zadania.
    </p>
  </div>
  <?php endif; ?>

</div>

<?php else: ?>

<?php if ($workspace): ?>

<!-- ── Nagłówek obszaru: nazwa + główna akcja ──────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h2 class="h5 fw-bold mb-0 d-flex align-items-center gap-2">
    <span class="ws-dot" style="width:10px;height:10px;border-radius:50%;background:<?= h($workspace['color']) ?>" aria-hidden="true"></span>
    <?= h($workspace['name']) ?>
  </h2>
  <?php if ($can_add): ?>
  <button type="button"
          class="btn btn-primary"
          onclick="openAddModal()"
          aria-label="Dodaj nowe zadanie">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowe zadanie
  </button>
  <?php endif; ?>
</div>

<?php
$_tk_active_filters = (int)((bool)$filter_priority) + (int)((bool)$filter_list)
                    + (int)((bool)$filter_tag) + (int)((bool)$filter_area) + (int)((bool)$filter_unit);
?>
<!-- ── Pasek narzędzi ────────────────────────────────────────────────────── -->
<form id="tkFilterForm" class="tk-toolbar" role="search" aria-label="Filtry i wyszukiwanie" onsubmit="tkAjaxLoad(event)">
  <input type="hidden" name="ws"     value="<?= (int)$ws_id ?>">
  <input type="hidden" name="status" id="tk-status-hidden" value="<?= h($filter_status) ?>">
  <input type="hidden" name="view"   value="<?= h($view_mode) ?>">

  <!-- Szukaj -->
  <div>
    <label class="visually-hidden" for="tk-q">Szukaj zadania</label>
    <input type="search" id="tk-q" name="q" class="tk-search"
           placeholder="Szukaj…"
           value="<?= h($filter_q) ?>"
           aria-label="Szukaj zadania po tytule lub opisie">
  </div>

  <div class="tk-sep" role="separator" aria-hidden="true"></div>

  <!-- Status -->
  <div class="d-flex gap-1 flex-wrap" role="group" aria-label="Filtr statusu">
    <?php
    $sp = [
      'all'   => ['Wszystkie', 'bi-list-ul'],
      'open'  => ['Do zrobienia', 'bi-circle'],
      'taken' => ['Przydzielone', 'bi-person-fill'],
      'done'  => ['Ukończone', 'bi-check-circle-fill'],
      'mine'  => ['Moje',      'bi-person-check-fill'],
    ];
    foreach ($sp as $k => [$lbl, $ico]):
    ?>
    <button type="button"
       class="tk-pill <?= $filter_status===$k?'active':'' ?>"
       data-s="<?= $k ?>"
       aria-pressed="<?= $filter_status===$k?'true':'false' ?>"
       onclick="tkSetStatus('<?= $k ?>', this)">
      <i class="bi <?= $ico ?>" aria-hidden="true"></i><?= $lbl ?>
      <span class="pill-n" id="tk-cnt-<?= $k ?>"><?= $cnt[$k] ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="tk-sep" role="separator" aria-hidden="true"></div>

  <!-- Więcej filtrów — priorytet/kategoria/tag/obszar/jednostka pod jednym przyciskiem -->
  <div class="dropdown" id="tk-filters-wrap">
    <button type="button"
            class="tk-filters-btn <?= $_tk_active_filters ? 'has-active' : '' ?>"
            id="tk-filters-btn"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            aria-haspopup="true" aria-expanded="false"
            aria-label="Więcej filtrów<?= $_tk_active_filters ? " — {$_tk_active_filters} aktywnych" : '' ?>">
      <i class="bi bi-funnel<?= $_tk_active_filters ? '-fill' : '' ?>" aria-hidden="true"></i>
      Filtry
      <span class="tk-filters-badge <?= $_tk_active_filters ? '' : 'd-none' ?>" id="tk-filters-badge"><?= $_tk_active_filters ?></span>
    </button>
    <div class="dropdown-menu shadow tk-filters-panel" id="tk-filters-panel" role="menu" aria-label="Panel filtrów">

      <div class="tk-filters-field">
        <label for="tk-pri">Priorytet</label>
        <select id="tk-pri" name="pri" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_priority?'selected':'' ?>>Każdy priorytet</option>
          <option value="4" <?= $filter_priority==4?'selected':'' ?>>🔴 Krytyczny</option>
          <option value="3" <?= $filter_priority==3?'selected':'' ?>>🟡 Wysoki</option>
          <option value="2" <?= $filter_priority==2?'selected':'' ?>>🔵 Normalny</option>
          <option value="1" <?= $filter_priority==1?'selected':'' ?>>⚪ Niski</option>
        </select>
      </div>

      <?php if (count($lists_map) > 1): ?>
      <div class="tk-filters-field">
        <label for="tk-list">Kategoria</label>
        <select id="tk-list" name="list" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_list?'selected':'' ?>>Każda kategoria</option>
          <?php foreach ($lists_map as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $filter_list==$l['id']?'selected':'' ?>><?= h($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($available_tags): ?>
      <div class="tk-filters-field">
        <label for="tk-tag">Tag</label>
        <select id="tk-tag" name="tag" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_tag?'selected':'' ?>>Każdy tag</option>
          <?php foreach ($available_tags as $tg): ?>
          <option value="<?= $tg['id'] ?>" <?= $filter_tag==$tg['id']?'selected':'' ?>><?= h($tg['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($all_areas): ?>
      <div class="tk-filters-field">
        <label for="tk-area">Obszar</label>
        <select id="tk-area" name="area" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_area?'selected':'' ?>>Każdy obszar</option>
          <?php foreach ($all_areas as $ar): ?>
          <option value="<?= $ar['id'] ?>" <?= $filter_area==$ar['id']?'selected':'' ?>>
            <?= h($ar['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($all_org_units): ?>
      <div class="tk-filters-field">
        <label for="tk-unit">Jednostka</label>
        <select id="tk-unit" name="unit" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_unit?'selected':'' ?>>Każda jednostka</option>
          <?php foreach ($all_org_units as $ou): ?>
          <option value="<?= $ou['id'] ?>" <?= $filter_unit==$ou['id']?'selected':'' ?>>
            <?= h($ou['short_name'] ?: $ou['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="tk-filters-panel-footer">
        <button type="button" id="tk-clear-filters" class="btn btn-link btn-sm text-decoration-none p-0 <?= $_tk_active_filters || $filter_status!=='all' || $filter_q!=='' ? '' : 'd-none' ?>"
                onclick="tkClearFilters()">
          <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Wyczyść wszystkie filtry
        </button>
      </div>

    </div>
  </div>

  <!-- Powiadomienia + Widok + Dodaj zadanie + Usuń obszar -->
  <div class="ms-auto d-flex gap-2 align-items-center">
    <?php if ($ws_id && $my_role): ?>
    <?php $np_active = $my_notify_prefs['notify_email'] || $my_notify_prefs['notify_sms'] || $my_notify_prefs['notify_push']; ?>
    <button type="button" class="btn btn-outline-secondary btn-sm"
            onclick="openNotifyModal()"
            title="Powiadomienia dla tego obszaru"
            aria-haspopup="dialog">
      <i class="bi bi-bell<?= $np_active ? '-fill text-warning' : '' ?>"></i>
    </button>
    <?php endif; ?>
    <?php
    $kanban_url = '?' . http_build_query(array_merge($_GET, ['ws'=>$ws_id,'view'=>'kanban']));
    $list_url   = '?' . http_build_query(array_merge($_GET, ['ws'=>$ws_id,'view'=>'list']));
    ?>
    <?php if ($view_mode === 'kanban'): ?>
    <a href="<?= $list_url ?>" class="btn btn-outline-secondary btn-sm" title="Widok listy">
      <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista
    </a>
    <?php else: ?>
    <a href="<?= $kanban_url ?>" class="btn btn-outline-secondary btn-sm" title="Widok Kanban">
      <i class="bi bi-kanban me-1" aria-hidden="true"></i>Kanban
    </a>
    <?php endif; ?>

    <?php if ($can_add): ?>
    <button type="button"
            class="btn btn-outline-danger btn-sm"
            onclick="openDeleteWsModal()"
            aria-haspopup="dialog"
            aria-label="Usuń obszar <?= h($workspace['name']) ?>">
      <i class="bi bi-trash3" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
  </div>

</form>

<?php echo _tasks_list_html($tasks, $cnt, $lists_map, $ws_id, $view_mode, $workspace, $can_add, $is_admin, $all_org_units); ?>

<?php endif; /* workspace */ ?>
<?php endif; /* workspaces */ ?>

<!-- ── Offcanvas: szczegóły zadania ─────────────────────────────────────── -->
<div class="offcanvas offcanvas-end shadow-lg"
     tabindex="-1"
     id="taskOffcanvas"
     role="dialog"
     aria-labelledby="taskOffcanvasLabel"
     aria-modal="true">
  <div class="offcanvas-header">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body"
       id="taskOffcanvasBody"
       aria-live="polite"
       aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: dodaj zadanie ─────────────────────────────────────────────── -->
<?php if ($can_add && $workspace): ?>
<div class="modal fade" id="addTaskModal" tabindex="-1"
     aria-labelledby="addTaskModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="addTaskModalLabel">
          <i class="bi bi-plus-circle me-1 text-primary" aria-hidden="true"></i>Nowe zadanie
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Zamknij formularz dodawania zadania"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="at-title">
            Tytuł <span class="text-danger" aria-hidden="true">*</span>
            <span class="visually-hidden">(wymagane)</span>
          </label>
          <input type="text" id="at-title" class="form-control"
                 placeholder="Co trzeba zrobić?" maxlength="255" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="at-desc">
            Opis <span class="text-muted fw-normal">(opcjonalnie)</span>
          </label>
          <textarea id="at-desc" class="form-control" rows="3"
                    placeholder="Szczegóły zadania…"></textarea>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold small" for="at-list">Kolumna</label>
            <?php if ($lists_map): ?>
            <select id="at-list" class="form-select form-select-sm">
              <?php foreach ($lists_map as $l): ?>
              <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <div class="alert alert-warning py-2 small mb-0">
              <i class="bi bi-exclamation-triangle me-1"></i>
              Ten obszar roboczy nie ma kolumn. <a href="<?= APP_URL ?>/tasks/settings/workspaces.php?ws=<?= $ws_id ?>">Dodaj kolumnę</a> przed dodaniem zadania.
            </div>
            <input type="hidden" id="at-list" value="0">
            <?php endif; ?>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold small" for="at-priority">Priorytet</label>
            <select id="at-priority" class="form-select form-select-sm">
              <option value="1">⚪ Niski</option>
              <option value="2" selected>🔵 Normalny</option>
              <option value="3">🟡 Wysoki</option>
              <option value="4">🔴 Krytyczny</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold small" for="at-due">Termin</label>
            <input type="date" id="at-due" class="form-control form-control-sm">
          </div>
          <?php if ($all_areas): ?>
          <div class="col-12">
            <label class="form-label fw-semibold small" for="at-area">Obszar</label>
            <select id="at-area" class="form-select form-select-sm">
              <option value="0">— brak obszaru —</option>
              <?php foreach ($all_areas as $ar): ?>
              <option value="<?= $ar['id'] ?>"><?= h($ar['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <?php if ($ws_members_for_assign || $all_org_units): ?>
          <div class="col-12">
            <label class="form-label fw-semibold small mb-1">
              <i class="bi bi-person-check me-1"></i>Przypisz do
            </label>
            <div class="btn-group btn-group-sm w-100 mb-2" role="group" aria-label="Tryb przypisania">
              <input type="radio" class="btn-check" name="at-assign-mode" id="at-mode-person" value="person" checked onchange="atToggleMode('person')">
              <label class="btn btn-outline-primary" for="at-mode-person">
                <i class="bi bi-person me-1"></i>Osoby
              </label>
              <input type="radio" class="btn-check" name="at-assign-mode" id="at-mode-unit" value="unit" onchange="atToggleMode('unit')">
              <label class="btn btn-outline-secondary" for="at-mode-unit">
                <i class="bi bi-diagram-3 me-1"></i>Jednostki
              </label>
            </div>
            <!-- Panel: osoba -->
            <div id="at-person-panel">
              <?php if ($ws_members_for_assign): ?>
              <select id="at-user-select" multiple placeholder="Wyszukaj i dodaj osobę…" aria-label="Wybierz osoby">
                <?php foreach ($ws_members_for_assign as $m): ?>
                <option value="<?= (int)$m['user_id'] ?>"><?= h($m['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <p class="text-muted small mb-0">Brak użytkowników w obszarze.</p>
              <?php endif; ?>
            </div>
            <!-- Panel: jednostka -->
            <div id="at-unit-panel" class="d-none">
              <?php if ($all_org_units): ?>
              <select id="at-unit" class="form-select form-select-sm">
                <option value="0">— brak przypisania do jednostki —</option>
                <?php foreach ($all_org_units as $ou): ?>
                <option value="<?= $ou['id'] ?>"><?= h($ou['name']) ?><?= $ou['short_name'] ? ' (' . h($ou['short_name']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <p class="text-muted small mb-0">Brak jednostek organizacyjnych.</p>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div id="at-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm"
                id="at-submit" onclick="submitAddTask()">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zadanie
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: Powiadomienia dla obszaru ───────────────────────────────────── -->
<?php if ($ws_id && $my_role): ?>
<div class="modal fade" id="notifyPrefModal" tabindex="-1"
     aria-labelledby="notifyPrefModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="notifyPrefModalLabel">
          <i class="bi bi-bell me-1 text-warning"></i>Powiadomienia
          <span class="text-muted fw-normal small">— <?= h($workspace['name'] ?? '') ?></span>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted small mb-3">Wybierz kanały powiadomień dla tego obszaru roboczego.</p>
        <div class="np-row">
          <span class="np-icon text-primary"><i class="bi bi-envelope-fill"></i></span>
          <div class="np-label">
            E-mail
            <small>Powiadomienia o zmianach zadań</small>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="np-email" role="switch"
                   <?= $my_notify_prefs['notify_email'] ? 'checked' : '' ?>>
          </div>
        </div>
        <div class="np-row">
          <span class="np-icon text-success"><i class="bi bi-phone-fill"></i></span>
          <div class="np-label">
            SMS
            <small>Krótkie powiadomienia tekstowe</small>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="np-sms" role="switch"
                   <?= $my_notify_prefs['notify_sms'] ? 'checked' : '' ?>>
          </div>
        </div>
        <div class="np-row">
          <span class="np-icon text-secondary"><i class="bi bi-bell-fill"></i></span>
          <div class="np-label">
            Push
            <small class="text-warning-emphasis">Wkrótce dostępne</small>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="np-push" role="switch"
                   <?= $my_notify_prefs['notify_push'] ? 'checked' : '' ?> disabled>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">Zamknij</button>
        <button type="button" class="btn btn-primary btn-sm" id="np-save" onclick="saveNotifyPrefs()">
          <i class="bi bi-check-lg me-1"></i>Zapisz
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const CSRF     = <?= json_encode(csrf_token()) ?>;
const WS_ID    = <?= (int)$ws_id ?>;
const CAN_EDIT = <?= $can_add ? 'true' : 'false' ?>;
const BASE     = <?= json_encode(rtrim(APP_URL,'/')) ?>;

/* SR announce */
function tkAnnounce(msg) {
    const el = document.getElementById('tk-sr');
    if (!el) return;
    el.textContent = '';
    setTimeout(() => { el.textContent = msg; }, 50);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* Otwórz offcanvas z detalami */
function openTask(taskId) {
    const body = document.getElementById('taskOffcanvasBody');
    body.innerHTML = '<div class="text-center py-5 text-muted">'
        + '<div class="spinner-border spinner-border-sm" role="status">'
        + '<span class="visually-hidden">Ładowanie…</span></div>'
        + '<div class="mt-2 small">Ładowanie…</div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(
        document.getElementById('taskOffcanvas')
    ).show();
    fetch(BASE + '/tasks/detail.php?id=' + taskId)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => {
            body.innerHTML = '';
            body.appendChild(document.createRange().createContextualFragment(html));
        })
        .catch(err => {
            body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania: '
                + escHtml(String(err)) + '</div>';
        });
}

/* Weź / Oddaj */
function claimTask(taskId, action, btn) {
    btn.disabled = true;
    btn.textContent = action === 'add' ? 'Biorę…' : 'Oddaję…';
    fetch(BASE + '/tasks/api/claim.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, task_id: taskId, action: action})
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            tkAnnounce(action === 'add' ? 'Zadanie przypisane.' : 'Zadanie oddane.');
            tkAjaxLoad();
        } else {
            btn.disabled = false;
            btn.innerHTML = action === 'add'
                ? '<i class="bi bi-hand-index me-1"></i>Weź'
                : '<i class="bi bi-person-dash me-1"></i>Oddaj';
            alert(r.error || 'Błąd.');
        }
    })
    .catch(() => { btn.disabled = false; alert('Błąd połączenia.'); });
}

/* Czy jakiś filtr (poza domyślnym „wszystkie") jest aktywny */
function tkHasActiveFilters(form) {
    const fd = new FormData(form);
    if ((fd.get('q') || '').trim() !== '') return true;
    if ((fd.get('status') || 'all') !== 'all') return true;
    for (const key of ['pri', 'list', 'tag', 'area', 'unit']) {
        const v = fd.get(key);
        if (v !== null && v !== '0') return true;
    }
    return false;
}

function tkUpdateClearButton() {
    const form = document.getElementById('tkFilterForm');
    const btn  = document.getElementById('tk-clear-filters');
    if (!form || !btn) return;
    btn.classList.toggle('d-none', !tkHasActiveFilters(form));
}

/* Licznik aktywnych filtrów w panelu "Filtry" (pri/list/tag/area/unit — bez q/status) */
function tkUpdateFiltersBadge() {
    const form  = document.getElementById('tkFilterForm');
    const btn   = document.getElementById('tk-filters-btn');
    const badge = document.getElementById('tk-filters-badge');
    if (!form || !btn || !badge) return;
    const fd = new FormData(form);
    let n = 0;
    for (const key of ['pri', 'list', 'tag', 'area', 'unit']) {
        const v = fd.get(key);
        if (v !== null && v !== '0') n++;
    }
    badge.textContent = n;
    badge.classList.toggle('d-none', n === 0);
    btn.classList.toggle('has-active', n > 0);
    const icon = btn.querySelector('i');
    if (icon) icon.className = n > 0 ? 'bi bi-funnel-fill' : 'bi bi-funnel';
}

/* Resetuje wszystkie filtry naraz i przeładowuje listę */
function tkClearFilters() {
    const form = document.getElementById('tkFilterForm');
    if (!form) return;
    const q = document.getElementById('tk-q');
    if (q) q.value = '';
    ['tk-pri', 'tk-list', 'tk-tag', 'tk-area', 'tk-unit'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '0';
    });
    const hidden = document.getElementById('tk-status-hidden');
    if (hidden) hidden.value = 'all';
    document.querySelectorAll('.tk-pill[data-s]').forEach(p => {
        const active = p.dataset.s === 'all';
        p.classList.toggle('active', active);
        p.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    tkAjaxLoad();
}

document.addEventListener('DOMContentLoaded', function() { tkUpdateClearButton(); tkUpdateFiltersBadge(); });

/* AJAX: załaduj region listy */
function tkAjaxLoad(e) {
    if (e && e.preventDefault) e.preventDefault();
    const form   = document.getElementById('tkFilterForm');
    if (!form) return;
    tkUpdateClearButton();
    tkUpdateFiltersBadge();
    const params = new URLSearchParams(new FormData(form));
    params.set('_ajax', '1');
    fetch(BASE + '/tasks/index.php?' + params.toString())
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                const region = document.getElementById('tkListRegion');
                if (region) {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = d.list_html;
                    const newRegion = tmp.querySelector('#tkListRegion') || tmp.firstElementChild;
                    if (newRegion) region.replaceWith(newRegion);
                    else region.innerHTML = d.list_html;
                }
                if (d.counts) updateCounts(d.counts);
                tkAnnounce('Znaleziono ' + d.total + ' zadań.');
            }
        })
        .catch(() => {});
}

/* Aktualizuj liczniki na pillach statusu */
function updateCounts(counts) {
    ['all','open','taken','done','mine'].forEach(k => {
        const el = document.getElementById('tk-cnt-' + k);
        if (el && counts[k] !== undefined) el.textContent = counts[k];
    });
}

/* Ustaw status pill + hidden input */
function tkSetStatus(val, btn) {
    const hidden = document.getElementById('tk-status-hidden');
    if (hidden) hidden.value = val;
    document.querySelectorAll('.tk-pill[data-s]').forEach(p => {
        const active = p.dataset.s === val;
        p.classList.toggle('active', active);
        p.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    tkAjaxLoad();
}

/* Filtry URL (legacy — zachowane dla kompatybilności) */
function tkFilter(key, val) {
    const url = new URL(window.location.href);
    if (!val || val === '0') url.searchParams.delete(key);
    else url.searchParams.set(key, val);
    window.location.href = url.toString();
}

/* Wyszukiwanie z debounce 350ms → AJAX */
let _searchTimer;
document.addEventListener('DOMContentLoaded', function() {
    const qInput = document.getElementById('tk-q');
    if (qInput) {
        qInput.addEventListener('input', function() {
            clearTimeout(_searchTimer);
            _searchTimer = setTimeout(tkAjaxLoad, 350);
        });
    }
});

/* Inline zmiana statusu — toggle done/reopen */
function tkToggleDone(btn, taskId) {
    var isDone = btn.classList.contains('s-done');
    var action = isDone ? 'reopen' : 'complete';
    if (action === 'complete' && !confirm('Oznaczyć zadanie jako ukończone?')) return;
    fetch(BASE + '/tasks/api/task.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: action, id: taskId})
    }).then(r => r.json()).then(d => { if (d.ok) tkAjaxLoad(); else alert(d.error || 'Błąd.'); })
      .catch(() => alert('Błąd połączenia.'));
}

/* Inline zmiana priorytetu */
function tkSetPriority(taskId, pri) {
    fetch(BASE + '/tasks/api/task.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'update', id: taskId, priority: pri})
    }).then(r => r.json()).then(d => { if (d.ok) tkAjaxLoad(); else alert(d.error || 'Błąd.'); })
      .catch(() => alert('Błąd połączenia.'));
}

/* Dodaj zadanie — opcjonalny listId (z quick-add w kanbanie) */
function openAddModal(listId) {
    const el = document.getElementById('addTaskModal');
    if (!el) return;
    document.getElementById('at-title').value    = '';
    document.getElementById('at-desc').value     = '';
    document.getElementById('at-due').value      = '';
    document.getElementById('at-priority').value = '2';
    document.getElementById('at-error').classList.add('d-none');
    const listSel = document.getElementById('at-list');
    if (listSel && listId) listSel.value = String(listId);
    // Reset trybu przypisania → osoba
    const modePersonRadio = document.getElementById('at-mode-person');
    const modeUnitRadio   = document.getElementById('at-mode-unit');
    if (modePersonRadio) {
        modePersonRadio.checked = true;
        document.getElementById('at-person-panel')?.classList.remove('d-none');
        document.getElementById('at-unit-panel')?.classList.add('d-none');
    }
    // Reset wyboru osób
    atInitUserSelect();
    _atUserTs?.clear(true);
    // Reset unit select
    const unitSel = document.getElementById('at-unit');
    if (unitSel) unitSel.value = '0';
    bootstrap.Modal.getOrCreateInstance(el).show();
    setTimeout(() => document.getElementById('at-title').focus(), 350);
}

function atToggleMode(mode) {
    const pp = document.getElementById('at-person-panel');
    const up = document.getElementById('at-unit-panel');
    if (mode === 'unit') {
        // Sprawdź czy jakieś osoby są zaznaczone
        const selected = _atUserTs ? _atUserTs.getValue() : [];
        if (selected.length > 0) {
            if (!confirm('Przełączyć na przypisanie do jednostki?\nWybrane osoby zostaną odznaczone.')) {
                document.getElementById('at-mode-person').checked = true;
                return;
            }
            _atUserTs.clear(true);
        } else if (!confirm('Przypisać zadanie do jednostki organizacyjnej?\n(Osoby preferowane — tylko jeśli brak konkretnej osoby)')) {
            document.getElementById('at-mode-person').checked = true;
            return;
        }
        pp?.classList.add('d-none');
        up?.classList.remove('d-none');
    } else {
        pp?.classList.remove('d-none');
        up?.classList.add('d-none');
        const unitSel = document.getElementById('at-unit');
        if (unitSel) unitSel.value = '0';
    }
}

function submitAddTask() {
    const titleEl = document.getElementById('at-title');
    const title   = titleEl.value.trim();
    if (!title) { titleEl.classList.add('is-invalid'); titleEl.focus(); return; }
    titleEl.classList.remove('is-invalid');

    const listId = parseInt(document.getElementById('at-list')?.value);
    if (!WS_ID || !listId) {
        const err = document.getElementById('at-error');
        err.textContent = !WS_ID ? 'Nie wybrano obszaru roboczego.' : 'Ten obszar nie ma kolumn — dodaj je w ustawieniach.';
        err.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('at-submit');
    btn.disabled    = true;
    btn.textContent = 'Dodawanie…';

    const assignMode = document.querySelector('input[name="at-assign-mode"]:checked')?.value || 'person';
    const assignees  = assignMode === 'person'
        ? (_atUserTs ? _atUserTs.getValue().map(v => parseInt(v)) : [])
        : [];
    const unitId     = assignMode === 'unit'
        ? (parseInt(document.getElementById('at-unit')?.value || '0') || null)
        : null;

    fetch(BASE + '/tasks/api/task.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            _csrf:        CSRF,
            action:       'create',
            title:        title,
            description:  document.getElementById('at-desc').value,
            due_date:     document.getElementById('at-due').value || null,
            priority:     parseInt(document.getElementById('at-priority').value),
            list_id:      parseInt(document.getElementById('at-list').value),
            area_id:      parseInt(document.getElementById('at-area')?.value || '0') || null,
            unit_id:      unitId,
            assignees:    assignees,
            workspace_id: WS_ID
        })
    })
    .then(r => r.json())
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zadanie';
        if (r.ok) {
            bootstrap.Modal.getInstance(document.getElementById('addTaskModal')).hide();
            tkAnnounce('Zadanie dodane.');
            tkAjaxLoad();
        } else {
            const err = document.getElementById('at-error');
            err.textContent = r.error || 'Błąd zapisu.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled = false;
        const err = document.getElementById('at-error');
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
}

// ── Zbiorcze akcje ────────────────────────────────────────────────────────
function bulkSelected() {
    return Array.from(document.querySelectorAll('.tk-row-select:checked')).map(el => parseInt(el.dataset.id));
}

function bulkUpdateBar() {
    const ids  = bulkSelected();
    const bar  = document.getElementById('tk-bulk-bar');
    const cnt  = document.getElementById('tk-bulk-count');
    const all  = document.getElementById('tk-check-all');
    const rows = document.querySelectorAll('.tk-row-select');
    if (bar)  bar.classList.toggle('visible', ids.length > 0);
    if (cnt)  cnt.textContent = ids.length + ' zaznaczon' + (ids.length === 1 ? 'e' : 'ych');
    if (all)  all.indeterminate = ids.length > 0 && ids.length < rows.length;
    if (all)  all.checked = ids.length === rows.length && rows.length > 0;
    document.querySelectorAll('#tk-tbody tr').forEach(tr => {
        const cb = tr.querySelector('.tk-row-select');
        tr.classList.toggle('selected', cb?.checked || false);
    });
}

function bulkOnCheck(cb) {
    bulkUpdateBar();
}

function bulkToggleAll(masterCb) {
    document.querySelectorAll('.tk-row-select').forEach(cb => { cb.checked = masterCb.checked; });
    bulkUpdateBar();
}

function bulkClear() {
    document.querySelectorAll('.tk-row-select,.tk-check-all').forEach(cb => { cb.checked = false; });
    const all = document.getElementById('tk-check-all');
    if (all) { all.checked = false; all.indeterminate = false; }
    bulkUpdateBar();
}

function bulkAction(action, extra = {}) {
    const ids = bulkSelected();
    if (!ids.length) return;

    const labels = { assign_me:'Przypisz do mnie', unassign_me:'Odpnij mnie',
                     priority:'Zmień priorytet', move:'Przenieś', complete:'Zakończ', delete:'Usuń' };

    if (action === 'delete') {
        if (!confirm(`Usunąć ${ids.length} zadań? Tej operacji nie można cofnąć.`)) return;
    }
    if (action === 'complete') {
        if (!confirm(`Oznaczyć ${ids.length} zadań jako ukończone?`)) return;
    }

    fetch(BASE + '/tasks/api/bulk.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ _csrf: CSRF, action, task_ids: ids, ...extra })
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            tkAnnounce((labels[action] || action) + ': ' + (r.data?.affected || ids.length) + ' zadań.');
            bulkClear();
            tkAjaxLoad();
        } else {
            alert(r.error || 'Błąd zbiorczej akcji.');
        }
    })
    .catch(() => alert('Błąd połączenia.'));
}

const atTitle = document.getElementById('at-title');
if (atTitle) atTitle.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); submitAddTask(); }
});

// ── Kanban drag & drop (SortableJS) ──────────────────────────────────────
let _tkDragActive = false;

function tkInitKanban() {
    const bodies = document.querySelectorAll('.tk-kanban-body');
    if (!bodies.length || typeof Sortable === 'undefined') return;

    bodies.forEach(col => {
        Sortable.create(col, {
            group:     'tk-kanban',
            animation: 150,
            ghostClass:'tk-card-ghost',
            dragClass: 'tk-card-dragging',
            handle:    '.tk-card-inner',
            onStart: function() { _tkDragActive = true; },
            onEnd: function(evt) {
                _tkDragActive = false;
                const card       = evt.item;
                const taskId     = parseInt(card.dataset.taskId);
                const newListId  = parseInt(evt.to.dataset.listId);
                const orderedIds = Array.from(evt.to.querySelectorAll('[data-task-id]'))
                                       .map(c => parseInt(c.dataset.taskId));

                if (!taskId || !newListId) return;

                // Optymistyczny: aktualizuj liczniki kolumn
                evt.from.closest('.tk-kanban-col')
                   ?.querySelector('.badge')
                   ?.textContent > 0 && evt.from.closest('.tk-kanban-col')
                   .querySelectorAll('.tk-card').length;

                fetch(BASE + '/tasks/api/move.php', {
                    method:  'POST',
                    headers: {'Content-Type': 'application/json'},
                    body:    JSON.stringify({
                        _csrf:       CSRF,
                        task_id:     taskId,
                        list_id:     newListId,
                        position:    evt.newIndex + 1,
                        ordered_ids: orderedIds
                    })
                })
                .then(r => r.json())
                .then(r => {
                    if (!r.ok) {
                        tkAnnounce('Błąd przenoszenia: ' + (r.error || ''));
                        tkAjaxLoad(); // cofnij wizualnie
                    } else {
                        // Odśwież liczniki kolumn
                        document.querySelectorAll('.tk-kanban-col').forEach(col => {
                            const cnt = col.querySelectorAll('.tk-card').length;
                            const badge = col.querySelector('.tk-kanban-hdr .badge');
                            if (badge) badge.textContent = cnt;
                        });
                        // Usuń hint "brak zadań" jeśli kolumna niepusta
                        evt.to.querySelector('.tk-card-drop-hint')?.remove();
                        // Dodaj hint jeśli kolumna źródłowa pusta
                        if (!evt.from.querySelector('[data-task-id]')) {
                            const hint = document.createElement('div');
                            hint.className = 'tk-card-drop-hint';
                            hint.textContent = 'Przeciągnij tu lub kliknij +';
                            evt.from.appendChild(hint);
                        }
                    }
                })
                .catch(() => { tkAnnounce('Błąd połączenia.'); tkAjaxLoad(); });
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', tkInitKanban);

// Po przeładowaniu AJAX — reinicjuj kanban
const _origTkAjaxLoad = tkAjaxLoad;
window.tkAjaxLoad = function(e) {
    _origTkAjaxLoad(e);
    // Krótkie opóźnienie — daj czas na podmianę DOM
    setTimeout(tkInitKanban, 250);
};

// ── Dynamiczne odświeżanie co 30 sekund ────────────────────────────────────
// Ta sama zasada co w tasks/inbox.php: pauza gdy karta w tle lub trwa
// przeciąganie/otwarty modal, natychmiastowe odświeżenie po powrocie karty.
let _tkPollTimer  = null;
let _tkPollPaused = false;

function tkStartPolling() {
    clearInterval(_tkPollTimer);
    _tkPollTimer = setInterval(tkPollTick, 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            _tkPollPaused = true;
        } else {
            _tkPollPaused = false;
            tkPollTick();
        }
    });
}

function tkPollTick() {
    if (_tkPollPaused || _tkDragActive) return;
    // Nie przerywaj, gdy otwarty jest modal (np. tworzenie zadania) lub offcanvas ze szczegółami.
    if (document.querySelector('.modal.show, .offcanvas.show')) return;
    tkAjaxLoad();
}

document.addEventListener('DOMContentLoaded', tkStartPolling);

// ── Wybór osoby w modalu tworzenia (wyszukiwarka zamiast siatki chipów) ───
let _atUserTs = null;

function atInitUserSelect() {
    const el = document.getElementById('at-user-select');
    if (!el || typeof TomSelect === 'undefined' || _atUserTs) return;
    _atUserTs = new TomSelect(el, {
        plugins:     ['remove_button'],
        placeholder: 'Wyszukaj i dodaj osobę…',
    });
}

document.addEventListener('DOMContentLoaded', atInitUserSelect);

// ── Modal powiadomień ─────────────────────────────────────────────────────
function openNotifyModal() {
    bootstrap.Modal.getOrCreateInstance(
        document.getElementById('notifyPrefModal')
    ).show();
}

function saveNotifyPrefs() {
    const btn = document.getElementById('np-save');
    btn.disabled = true;
    fetch(BASE + '/tasks/api/notify_prefs.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            _csrf:        CSRF,
            workspace_id: WS_ID,
            notify_email: document.getElementById('np-email')?.checked ? 1 : 0,
            notify_sms:   document.getElementById('np-sms')?.checked   ? 1 : 0,
            notify_push:  document.getElementById('np-push')?.checked  ? 1 : 0,
        })
    })
    .then(r => r.json())
    .then(r => {
        btn.disabled = false;
        if (r.ok) {
            bootstrap.Modal.getInstance(document.getElementById('notifyPrefModal')).hide();
            // Aktualizuj ikonę dzwonka
            const bellBtn = document.querySelector('button[onclick="openNotifyModal()"] i');
            const anyOn = r.notify_email || r.notify_sms || r.notify_push;
            if (bellBtn) {
                bellBtn.className = 'bi bi-bell' + (anyOn ? '-fill text-warning' : '');
            }
        } else {
            alert('Błąd: ' + (r.error || 'Nie udało się zapisać.'));
        }
    })
    .catch(() => { btn.disabled = false; alert('Błąd połączenia.'); });
}
</script>

<?php if ($can_add && $workspace): ?>
<!-- ── Modal: Usuń obszar ────────────────────────────────────────────────── -->
<div id="del-ws-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
            z-index:9500;backdrop-filter:blur(2px)"
     aria-hidden="true"
     onclick="closeDeleteWsModal()"></div>

<div id="del-ws-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="del-ws-title"
     aria-describedby="del-ws-desc"
     tabindex="-1"
     style="display:none;position:fixed;top:50%;left:50%;
            transform:translate(-50%,-50%);
            z-index:9600;width:380px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;
            box-shadow:0 20px 48px rgba(0,0,0,.25);overflow:hidden">

  <!-- Nagłówek -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:.8rem 1.1rem;border-bottom:1px solid #fee2e2;background:#fef2f2">
    <h2 id="del-ws-title"
        style="font-size:.92rem;font-weight:700;margin:0;color:#dc2626;
               display:flex;align-items:center;gap:.4rem">
      <i class="bi bi-trash3-fill" aria-hidden="true"></i>
      Usuń obszar roboczy
    </h2>
    <button type="button" class="btn-close"
            id="del-ws-close-btn"
            onclick="closeDeleteWsModal()"
            aria-label="Anuluj usuwanie obszaru"
            style="font-size:.8rem"></button>
  </div>

  <!-- Treść -->
  <div style="padding:.95rem 1.1rem">
    <p id="del-ws-desc" style="font-size:.85rem;color:#374151;margin-bottom:.6rem;line-height:1.5">
      Usuwasz obszar roboczy:<br>
      <strong style="font-size:.95rem;color:#0f172a"><?= h($workspace['name']) ?></strong>
    </p>

    <!-- Liczniki -->
    <?php
    $ws_task_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",
        [$ws_id]
    )['n'] ?? 0);
    $ws_member_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM task_workspace_members WHERE workspace_id=?",
        [$ws_id]
    )['n'] ?? 0);
    ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;
                padding:.65rem .85rem;margin-bottom:.75rem;font-size:.82rem">
      <div style="display:flex;justify-content:space-between;padding:.2rem 0;border-bottom:1px solid #f1f5f9">
        <span style="color:#64748b">Zadania</span>
        <strong style="color:<?= $ws_task_count > 0 ? '#dc2626' : '#64748b' ?>">
          <?= $ws_task_count ?>
        </strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:.2rem 0">
        <span style="color:#64748b">Członkowie</span>
        <strong style="color:#64748b"><?= $ws_member_count ?></strong>
      </div>
    </div>

    <?php if ($ws_task_count > 0): ?>
    <div style="display:flex;gap:.5rem;align-items:flex-start;padding:.5rem .65rem;
                background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;
                font-size:.79rem;color:#92400e;margin-bottom:.75rem">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span>Wszystkie <strong><?= $ws_task_count ?> zadań</strong> zostaną trwale usunięte. Tej operacji nie można cofnąć.</span>
    </div>
    <?php endif; ?>

    <!-- Potwierdzenie -->
    <div style="margin-bottom:.65rem">
      <label for="del-ws-confirm"
             style="font-size:.79rem;font-weight:600;display:block;margin-bottom:.3rem;color:#374151">
        Wpisz nazwę obszaru aby potwierdzić:
        <code style="background:#f1f5f9;padding:.05rem .3rem;border-radius:.25rem;
                     font-size:.82rem;color:#dc2626"><?= h($workspace['name']) ?></code>
      </label>
      <input type="text"
             id="del-ws-confirm"
             class="form-control form-control-sm"
             autocomplete="off"
             autocorrect="off"
             spellcheck="false"
             placeholder="wpisz dokładną nazwę…"
             aria-required="true"
             oninput="delWsCheckConfirm(this)"
             onkeydown="if(event.key==='Enter'){event.preventDefault();delWsSubmit()}">
    </div>

    <div id="del-ws-err" class="alert alert-danger small py-2 d-none" role="alert"></div>
  </div>

  <!-- Stopka -->
  <div style="display:flex;justify-content:flex-end;gap:.5rem;
              padding:.65rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            onclick="closeDeleteWsModal()">Anuluj</button>
    <button type="button"
            class="btn btn-danger btn-sm"
            id="del-ws-submit-btn"
            disabled
            onclick="delWsSubmit()"
            aria-label="Potwierdź i usuń obszar <?= h($workspace['name']) ?>">
      <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Usuń bezpowrotnie
    </button>
  </div>
</div>

<script>
const _WS_NAME = <?= json_encode($workspace['name']) ?>;
const _WS_ID   = <?= (int)$ws_id ?>;
let   _delWsPrevFocus = null;

window.openDeleteWsModal = function() {
    const modal    = document.getElementById('del-ws-modal');
    const backdrop = document.getElementById('del-ws-backdrop');
    const inp      = document.getElementById('del-ws-confirm');
    const btn      = document.getElementById('del-ws-submit-btn');
    const err      = document.getElementById('del-ws-err');

    inp.value      = '';
    btn.disabled   = true;
    err.classList.add('d-none');

    _delWsPrevFocus       = document.activeElement;
    backdrop.style.display = 'block';
    modal.style.display    = 'block';
    backdrop.removeAttribute('aria-hidden');

    requestAnimationFrame(() => inp.focus());
    modal.addEventListener('keydown', _delWsTrapFocus);
};

window.closeDeleteWsModal = function() {
    document.getElementById('del-ws-modal').style.display    = 'none';
    document.getElementById('del-ws-backdrop').style.display = 'none';
    document.getElementById('del-ws-backdrop').setAttribute('aria-hidden', 'true');
    document.getElementById('del-ws-modal').removeEventListener('keydown', _delWsTrapFocus);
    (_delWsPrevFocus || document.querySelector('[onclick="openDeleteWsModal()"]'))?.focus();
    _delWsPrevFocus = null;
};

function _delWsTrapFocus(e) {
    if (e.key === 'Escape') { e.preventDefault(); closeDeleteWsModal(); return; }
    if (e.key !== 'Tab') return;
    const modal    = document.getElementById('del-ws-modal');
    const focusable = Array.from(modal.querySelectorAll(
        'button:not([disabled]),input,[tabindex]:not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
    else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
}

window.delWsCheckConfirm = function(inp) {
    const btn = document.getElementById('del-ws-submit-btn');
    btn.disabled = inp.value.trim() !== _WS_NAME;
    inp.style.borderColor = '';
};

window.delWsSubmit = function() {
    const inp = document.getElementById('del-ws-confirm');
    const btn = document.getElementById('del-ws-submit-btn');
    const err = document.getElementById('del-ws-err');

    if (inp.value.trim() !== _WS_NAME) {
        inp.style.borderColor = '#dc2626';
        inp.focus();
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Usuwam…';
    err.classList.add('d-none');

    fetch(BASE + '/tasks/api/delete_workspace.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ _csrf: CSRF, workspace_id: _WS_ID })
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            closeDeleteWsModal();
            // Przekieruj do dashboardu po usunięciu
            window.location.href = BASE + '/tasks/dashboard.php';
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Usuń bezpowrotnie';
            err.textContent = r.error || 'Błąd usuwania.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Usuń bezpowrotnie';
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
};
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
