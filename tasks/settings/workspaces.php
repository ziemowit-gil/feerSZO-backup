<?php
/**
 * tasks/settings/workspaces.php
 * Zarządzanie obszarami roboczymi — dostępne dla liderów obszarów i adminów.
 * Przeniesione z admin/tasks_workspaces.php do modułu Zadania.
 *
 * Przebudowa na Tailwind (2026-09-04) — podział na pliki, patrz
 * tasks/settings/includes/: workspaces_sidebar.php (lista obszarów),
 * workspaces_tab_lists.php, workspaces_tab_members.php, workspaces_tab_teams.php,
 * workspaces_tab_settings.php (zakładki), workspaces_modals.php (modale),
 * assets/js/tasks-settings-workspaces.js (logika JS).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/workspaces.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid      = (int)(current_user()['id'] ?? 0);
$sys_admin = is_admin();

// Dostęp: admin systemu lub lider (admin/editor) co najmniej jednego obszaru
$_can_manage = $sys_admin || (bool)db_one(
    "SELECT 1 FROM task_workspace_members WHERE user_id=? AND role IN ('admin','editor')",
    [$uid]
);
if (!$_can_manage) {
    flash_set('error', 'Brak uprawnień do zarządzania obszarami.');
    header('Location: ' . APP_URL . '/tasks/dashboard.php'); exit;
}

$SELF = APP_URL . '/tasks/settings/workspaces.php';

// ── Obsługa POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act   = $_POST['_action'] ?? '';
    $ws_id = (int)($_POST['ws_id'] ?? 0);

    // Uprawnienie do konkretnego obszaru (nie tylko globalny admin)
    $ws_role = $ws_id ? task_workspace_role($ws_id, $uid) : null;
    $can_edit_ws = $sys_admin || in_array($ws_role, ['admin', 'editor'], true);

    // ── Utwórz / Edytuj obszar ────────────────────────────────────────────
    if ($act === 'save_workspace') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#2563eb';
        $icon  = preg_replace('/[^a-z0-9\-]/', '', $_POST['icon'] ?? 'kanban');

        // Role systemowe (visible/edit) — walidacja przez listę istniejących ról
        $all_role_names = array_column(db_all("SELECT name FROM roles ORDER BY display_name"), 'name');
        $norm_roles_arr = function(array $input) use ($all_role_names): string {
            $filtered = array_values(array_filter($input, fn($r) => in_array($r, $all_role_names, true)));
            return $filtered ? json_encode($filtered, JSON_UNESCAPED_UNICODE) : '';
        };
        $visible_roles = $norm_roles_arr((array)($_POST['visible_roles'] ?? []));
        $edit_roles    = $norm_roles_arr((array)($_POST['edit_roles']    ?? []));

        if (!$name) { flash_set('error', 'Nazwa obszaru jest wymagana.'); goto redirect; }

        if ($ws_id && $can_edit_ws) {
            db()->prepare(
                "UPDATE task_workspaces SET name=?, description=?, color=?, icon=?,
                 visible_roles=?, edit_roles=?,
                 updated_at=datetime('now','localtime') WHERE id=?"
            )->execute([$name, $desc, $color, 'bi-' . ltrim($icon, 'bi-'), $visible_roles, $edit_roles, $ws_id]);
            flash_set('success', 'Obszar zaktualizowany.');
        } elseif (!$ws_id) {
            // Nowy obszar — każdy lider może tworzyć
            $base = preg_replace('/-+/', '-', trim(preg_replace('/[^a-z0-9\-]/', '-', mb_strtolower($name)), '-'));
            $slug = $base ?: 'obszar';
            $n = 1;
            while (db_one("SELECT id FROM task_workspaces WHERE slug=?", [$slug])) {
                $slug = $base . '-' . $n++;
            }
            $id = db_insert('task_workspaces', [
                'slug'        => $slug,
                'name'        => $name,
                'description' => $desc,
                'color'       => $color,
                'icon'        => 'bi-' . ltrim($icon, 'bi-'),
                'is_active'   => 1,
                'created_by'  => $uid,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            foreach ([['Nowe',1,0,'#e2e8f0'],['W trakcie',2,0,'#2563eb'],['Do weryfikacji',3,0,'#f59e0b'],['Gotowe',4,1,'#16a34a']] as [$ln,$lp,$ld,$lc]) {
                db_insert('task_lists', ['workspace_id'=>$id,'name'=>$ln,'position'=>$lp,'color'=>$lc,'is_done_state'=>$ld,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            }
            $now_ts = date('Y-m-d H:i:s');
            $to_add = [['id'=>$uid]];
            foreach (db_all("SELECT id FROM users WHERE role='admin' AND is_active=1") as $a) {
                if ((int)$a['id'] !== $uid) $to_add[] = $a;
            }
            foreach ($to_add as $a) {
                try { db()->prepare("INSERT OR IGNORE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at) VALUES (?,?,'admin',?,?)")->execute([$id,(int)$a['id'],$uid,$now_ts]); } catch (\Throwable $e) {}
            }
            ws_sp_init_workspace($id);
            // Utwórz foldery SP podane w formularzu
            foreach ((array)($_POST['folders'] ?? []) as $fname) {
                $fname = trim($fname);
                if ($fname !== '') {
                    try { ws_create_folder($id, $fname, '', $uid); } catch (\Throwable $e) {}
                }
            }
            flash_set('success', 'Obszar „' . $name . '" utworzony.');
            header('Location: ' . $SELF . '?ws=' . $id); exit;
        }
        goto redirect;
    }

    // ── Przełącz aktywność ─────────────────────────────────────────────────
    if ($act === 'toggle_workspace' && $can_edit_ws) {
        $ws = db_one("SELECT * FROM task_workspaces WHERE id=?", [$ws_id]);
        if ($ws) {
            db()->prepare("UPDATE task_workspaces SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$ws['is_active'] ? 0 : 1, $ws_id]);
            flash_set('success', 'Status obszaru zmieniony.');
        }
        goto redirect;
    }

    // ── Usuń obszar ────────────────────────────────────────────────────────
    if ($act === 'delete_workspace' && $can_edit_ws) {
        $ws = db_one("SELECT * FROM task_workspaces WHERE id=?", [$ws_id]);
        if ($ws) {
            $task_ids = array_column(db_all("SELECT id FROM tasks WHERE workspace_id=?", [$ws_id]), 'id');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($task_ids) {
                    // Pliki fizyczne
                    $ph = implode(',', array_fill(0, count($task_ids), '?'));
                    foreach (db_all("SELECT stored_name FROM task_files WHERE task_id IN ($ph)", $task_ids) as $f) {
                        $path = dirname(dirname(__DIR__)) . '/uploads/tasks/' . $f['stored_name'];
                        if (file_exists($path)) @unlink($path);
                    }
                    // Child tables
                    foreach (['task_task_tags','task_subtasks','task_comments','task_files','task_list_time','task_history','task_assignments'] as $t) {
                        $pdo->prepare("DELETE FROM {$t} WHERE task_id IN ($ph)")->execute($task_ids);
                    }
                    $pdo->prepare("DELETE FROM tasks WHERE workspace_id=?")->execute([$ws_id]);
                }
                $pdo->prepare("DELETE FROM task_lists             WHERE workspace_id=?")->execute([$ws_id]);
                $pdo->prepare("DELETE FROM task_workspace_members WHERE workspace_id=?")->execute([$ws_id]);
                $pdo->prepare("DELETE FROM task_tags              WHERE workspace_id=?")->execute([$ws_id]);
                if ($task_ids) {
                    $ph2 = implode(',', array_fill(0, count($task_ids), '?'));
                    $pdo->prepare("DELETE FROM messages WHERE context_type='task' AND context_id IN ($ph2)")->execute($task_ids);
                }
                $pdo->prepare("DELETE FROM task_workspaces WHERE id=?")->execute([$ws_id]);
                $pdo->commit();
                flash_set('success', 'Obszar „' . $ws['name'] . '" usunięty.');
                header('Location: ' . $SELF); exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Błąd: ' . $e->getMessage());
            }
        }
        goto redirect;
    }

    // ── Kolumna: zapisz / usuń / kolejność ────────────────────────────────
    if ($act === 'save_list' && $can_edit_ws) {
        $list_id = (int)($_POST['list_id'] ?? 0);
        $lname   = trim($_POST['lname'] ?? '');
        $color   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['lcolor'] ?? '') ? $_POST['lcolor'] : '#e2e8f0';
        $done    = (int)($_POST['is_done_state'] ?? 0);
        $wip     = trim($_POST['wip_limit'] ?? '') !== '' ? max(1,(int)$_POST['wip_limit']) : null;
        if (!$ws_id || !$lname) { flash_set('error', 'Nazwa kolumny jest wymagana.'); goto redirect; }
        if ($list_id) {
            db()->prepare("UPDATE task_lists SET name=?,color=?,is_done_state=?,wip_limit=?,updated_at=datetime('now','localtime') WHERE id=? AND workspace_id=?")->execute([$lname,$color,$done,$wip,$list_id,$ws_id]);
        } else {
            $mp = db_one("SELECT MAX(position) AS m FROM task_lists WHERE workspace_id=?", [$ws_id]);
            db_insert('task_lists', ['workspace_id'=>$ws_id,'name'=>$lname,'position'=>(float)($mp['m']??0)+1,'color'=>$color,'is_done_state'=>$done,'wip_limit'=>$wip,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        }
        flash_set('success', 'Kolumna zapisana.');
        goto redirect;
    }

    if ($act === 'delete_list' && $can_edit_ws) {
        $list_id = (int)($_POST['list_id'] ?? 0);
        $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM tasks WHERE list_id=? AND deleted_at IS NULL", [$list_id])['c'] ?? 0);
        if ($cnt > 0) { flash_set('error', 'Kolumna zawiera ' . $cnt . ' zadań. Przenieś je najpierw.'); }
        else { db()->prepare("DELETE FROM task_lists WHERE id=? AND workspace_id=?")->execute([$list_id,$ws_id]); flash_set('success', 'Kolumna usunięta.'); }
        goto redirect;
    }

    if ($act === 'reorder_lists' && $can_edit_ws) {
        $ids = array_map('intval', explode(',', $_POST['order'] ?? ''));
        $pos = 1;
        $stmt = db()->prepare("UPDATE task_lists SET position=?,updated_at=datetime('now','localtime') WHERE id=? AND workspace_id=?");
        foreach ($ids as $lid) { $stmt->execute([$pos++,$lid,$ws_id]); }
        flash_set('success', 'Kolejność zapisana.');
        goto redirect;
    }

    // ── Członkowie ─────────────────────────────────────────────────────────
    if ($act === 'add_member' && $can_edit_ws) {
        $mu   = (int)($_POST['member_uid']  ?? 0);
        $role = in_array($_POST['member_role'] ?? '', ['admin','editor','member','viewer']) ? $_POST['member_role'] : 'member';
        if ($ws_id && $mu) {
            db()->prepare("INSERT OR REPLACE INTO task_workspace_members (workspace_id,user_id,role,added_by,added_at) VALUES (?,?,?,?,datetime('now','localtime'))")->execute([$ws_id,$mu,$role,$uid]);
            flash_set('success', 'Użytkownik dodany.');
        }
        goto redirect;
    }

    if ($act === 'remove_member' && $can_edit_ws) {
        $mu = (int)($_POST['member_uid'] ?? 0);
        db()->prepare("DELETE FROM task_workspace_members WHERE workspace_id=? AND user_id=?")->execute([$ws_id,$mu]);
        flash_set('success', 'Użytkownik usunięty.');
        goto redirect;
    }

    redirect:
    header('Location: ' . $SELF . ($ws_id ? '?ws=' . $ws_id : '')); exit;
}

// ── Dane widoku ────────────────────────────────────────────────────────────
$active_ws_id = (int)($_GET['ws'] ?? 0);

// Lider widzi tylko obszary gdzie ma admin/editor — bezpośrednio, jako twórca,
// LUB przez zespół (task_user_workspaces() liczy to poprawnie, patrz
// task_workspace_role() w includes/tasks.php). Admin systemu widzi wszystkie.
if ($sys_admin) {
    $workspaces = db_all(
        "SELECT tw.*, COUNT(DISTINCT t.id) AS task_count
         FROM task_workspaces tw
         LEFT JOIN tasks t ON t.workspace_id=tw.id AND t.deleted_at IS NULL
         GROUP BY tw.id ORDER BY tw.name"
    );
} else {
    $workspaces = array_values(array_filter(
        task_user_workspaces($uid),
        fn($w) => in_array($w['my_role'], ['admin', 'editor'], true)
    ));
}

$active_ws = null; $lists = []; $members = []; $all_users = [];
$linked_teams = []; $linkable_teams = [];
if ($active_ws_id) {
    $active_ws  = db_one("SELECT * FROM task_workspaces WHERE id=?", [$active_ws_id]);
    $ws_role    = task_workspace_role($active_ws_id, $uid);
    if (!$active_ws || (!$sys_admin && !in_array($ws_role, ['admin','editor'], true))) {
        flash_set('error', 'Brak dostępu do tego obszaru.');
        header('Location: ' . $SELF); exit;
    }
    $lists      = db_all("SELECT tl.*, COUNT(t.id) AS task_count FROM task_lists tl LEFT JOIN tasks t ON t.list_id=tl.id AND t.deleted_at IS NULL WHERE tl.workspace_id=? GROUP BY tl.id ORDER BY tl.position", [$active_ws_id]);
    $members    = db_all("SELECT twm.*, u.name, u.email FROM task_workspace_members twm JOIN users u ON u.id=twm.user_id WHERE twm.workspace_id=? ORDER BY u.name", [$active_ws_id]);
    $member_ids = array_column($members, 'user_id');
    $all_users  = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name");

    // Zespoły przypisane do obszaru (task_workspace_teams) — dostęp DODATKOWY
    // obok pojedynczych członków powyżej. Zob. task_workspace_team_role().
    task_teams_migrate();
    $linked_teams = db_all(
        "SELECT tt.id, tt.name, tt.color, tt.icon, wt.role,
                (SELECT COUNT(*) FROM task_team_members WHERE team_id = tt.id) AS member_count
         FROM task_workspace_teams wt
         JOIN task_teams tt ON tt.id = wt.team_id
         WHERE wt.workspace_id = ?
         ORDER BY tt.name",
        [$active_ws_id]
    );
    $linked_team_ids = array_column($linked_teams, 'id');
    $linkable_teams  = array_filter(task_get_teams(), fn($t) => !in_array($t['id'], $linked_team_ids, true));
    $ws_task_count   = (int)(db_one("SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL", [$active_ws_id])['n'] ?? 0);
}

$PAGE_TITLE       = 'Obszary robocze';
$TASKS_BREADCRUMB = 'Obszary robocze';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<?= flash_html() ?>

<div class="tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-2 tw-mb-4">
  <div>
    <h1 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
      <i class="bi bi-sliders tw-text-blue-600" aria-hidden="true"></i>Obszary robocze
    </h1>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Zarządzaj obszarami, kolumnami i członkami</p>
  </div>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#wsModal">
    <i class="bi bi-plus-lg me-1"></i>Nowy obszar
  </button>
</div>

<div class="row g-3">

  <div class="col-lg-3">
    <?php require_once __DIR__ . '/includes/workspaces_sidebar.php'; ?>
  </div>

  <div class="col-lg-9">
    <?php if (!$active_ws): ?>
    <div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-py-14 tw-text-center tw-text-slate-400">
      <i class="bi bi-sliders tw-text-4xl tw-block tw-mb-2 tw-opacity-40" aria-hidden="true"></i>
      Wybierz obszar z listy lub utwórz nowy.
    </div>
    <?php else: ?>

    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <a class="nav-link active" href="#tab-lists" data-bs-toggle="tab">
          <i class="bi bi-view-stacked me-1"></i>Kolumny <span class="badge bg-secondary ms-1"><?= count($lists) ?></span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="#tab-members" data-bs-toggle="tab">
          <i class="bi bi-people me-1"></i>Członkowie <span class="badge bg-secondary ms-1"><?= count($members) ?></span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="#tab-teams" data-bs-toggle="tab">
          <i class="bi bi-people-fill me-1"></i>Zespoły <span class="badge bg-secondary ms-1"><?= count($linked_teams) ?></span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="#tab-settings" data-bs-toggle="tab">
          <i class="bi bi-gear me-1"></i>Ustawienia
        </a>
      </li>
    </ul>

    <div class="tab-content">
      <div class="tab-pane fade show active" id="tab-lists">
        <?php require_once __DIR__ . '/includes/workspaces_tab_lists.php'; ?>
      </div>
      <div class="tab-pane fade" id="tab-members">
        <?php require_once __DIR__ . '/includes/workspaces_tab_members.php'; ?>
      </div>
      <div class="tab-pane fade" id="tab-teams">
        <?php require_once __DIR__ . '/includes/workspaces_tab_teams.php'; ?>
      </div>
      <div class="tab-pane fade" id="tab-settings">
        <?php require_once __DIR__ . '/includes/workspaces_tab_settings.php'; ?>
      </div>
    </div><!-- /tab-content -->
    <?php endif; ?>
  </div><!-- /col-lg-9 -->
</div>

<?php require_once __DIR__ . '/includes/workspaces_modals.php'; ?>

<script>
  <?php /* Sortable.js już załadowany globalnie w header_tasks.php — nie duplikować */ ?>
  window.TSK_WORKSPACES = { csrf: <?= json_encode(csrf_token()) ?>, base: <?= json_encode(rtrim(APP_URL, '/')) ?> };
</script>
<script src="<?= APP_URL ?>/assets/js/tasks-settings-workspaces.js" defer></script>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
