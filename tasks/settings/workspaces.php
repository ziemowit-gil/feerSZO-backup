<?php
/**
 * tasks/settings/workspaces.php
 * Zarządzanie obszarami roboczymi — dostępne dla liderów obszarów i adminów.
 * Przeniesione z admin/tasks_workspaces.php do modułu Zadania.
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

// Lider widzi tylko swoje obszary; admin widzi wszystkie
if ($sys_admin) {
    $workspaces = db_all(
        "SELECT tw.*, u.name AS creator_name, COUNT(DISTINCT t.id) AS task_count
         FROM task_workspaces tw
         LEFT JOIN users u ON u.id=tw.created_by
         LEFT JOIN tasks t ON t.workspace_id=tw.id AND t.deleted_at IS NULL
         GROUP BY tw.id ORDER BY tw.name"
    );
} else {
    $workspaces = db_all(
        "SELECT tw.*, u.name AS creator_name, COUNT(DISTINCT t.id) AS task_count
         FROM task_workspaces tw
         JOIN task_workspace_members twm ON twm.workspace_id=tw.id AND twm.user_id=? AND twm.role IN ('admin','editor')
         LEFT JOIN users u ON u.id=tw.created_by
         LEFT JOIN tasks t ON t.workspace_id=tw.id AND t.deleted_at IS NULL
         GROUP BY tw.id ORDER BY tw.name",
        [$uid]
    );
    // Dodaj obszary gdzie jest created_by
    $created = db_all(
        "SELECT tw.*, u.name AS creator_name, COUNT(DISTINCT t.id) AS task_count
         FROM task_workspaces tw
         LEFT JOIN users u ON u.id=tw.created_by
         LEFT JOIN tasks t ON t.workspace_id=tw.id AND t.deleted_at IS NULL
         WHERE tw.created_by=?
         GROUP BY tw.id",
        [$uid]
    );
    $seen_ids = array_column($workspaces, 'id');
    foreach ($created as $c) {
        if (!in_array($c['id'], $seen_ids)) $workspaces[] = $c;
    }
    usort($workspaces, fn($a,$b) => strcmp($a['name'],$b['name']));
}

$active_ws = null; $lists = []; $members = []; $all_users = [];
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
    $linked_team_ids   = array_column($linked_teams, 'id');
    $linkable_teams    = array_filter(task_get_teams(), fn($t) => !in_array($t['id'], $linked_team_ids, true));
}

$PAGE_TITLE       = 'Obszary robocze';
$TASKS_BREADCRUMB = 'Obszary robocze';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h5 fw-bold mb-0">
      <i class="bi bi-sliders text-primary me-2" aria-hidden="true"></i>Obszary robocze
    </h1>
    <p class="text-muted small mb-0">Zarządzaj obszarami, kolumnami i członkami</p>
  </div>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#wsModal">
    <i class="bi bi-plus-lg me-1"></i>Nowy obszar
  </button>
</div>

<div class="row g-3">

  <!-- Lista obszarów -->
  <div class="col-lg-3">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small py-2 border-bottom">Obszary</div>
      <div class="list-group list-group-flush">
        <?php if (!$workspaces): ?>
        <div class="list-group-item text-muted small py-3 text-center">Brak obszarów.</div>
        <?php endif; ?>
        <?php foreach ($workspaces as $ws): ?>
        <a href="?ws=<?= $ws['id'] ?>"
           class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2
                  <?= $ws['id'] == $active_ws_id ? 'active' : '' ?>"
           style="<?= !$ws['is_active'] ? 'opacity:.55' : '' ?>">
          <span style="width:9px;height:9px;border-radius:50%;background:<?= h($ws['color']) ?>;flex-shrink:0"></span>
          <i class="bi <?= h($ws['icon']) ?>" style="font-size:.85rem;flex-shrink:0"></i>
          <span class="flex-grow-1 small text-truncate"><?= h($ws['name']) ?></span>
          <span style="font-size:.67rem;background:#f1f5f9;color:#64748b;border-radius:2rem;padding:.05rem .4rem">
            <?= (int)$ws['task_count'] ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Szczegóły -->
  <div class="col-lg-9">
    <?php if (!$active_ws): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-sliders display-4 opacity-25 d-block mb-2"></i>
        Wybierz obszar z listy lub utwórz nowy.
      </div>
    </div>
    <?php else:
      $ws_task_count = (int)(db_one("SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",[$active_ws_id])['n']??0);
    ?>

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

      <!-- ── Kolumny ── -->
      <div class="tab-pane fade show active" id="tab-lists">
        <div class="card border-0 shadow-sm mb-2">
          <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <span class="small fw-semibold">Kolejność i konfiguracja kolumn</span>
            <button class="btn btn-sm btn-outline-primary btn-sm"
                    onclick="openListModal(0,<?= $active_ws_id ?>)">
              <i class="bi bi-plus-lg me-1"></i>Dodaj kolumnę
            </button>
          </div>
          <div class="list-group list-group-flush" id="lists-sortable">
            <?php foreach ($lists as $list): ?>
            <div class="list-group-item d-flex align-items-center gap-3 py-2"
                 data-list-id="<?= $list['id'] ?>">
              <i class="bi bi-grip-vertical text-muted" style="cursor:grab;font-size:.85rem"></i>
              <span style="width:10px;height:10px;border-radius:2px;background:<?= h($list['color']?:'#e2e8f0') ?>;flex-shrink:0"></span>
              <span class="flex-grow-1 small fw-semibold">
                <?= h($list['name']) ?>
                <?php if ($list['is_done_state']): ?>
                <i class="bi bi-check-circle-fill text-success ms-1" style="font-size:.72rem"></i>
                <?php endif; ?>
                <?php if ($list['wip_limit']): ?>
                <span class="text-muted fw-normal ms-1" style="font-size:.72rem">WIP:<?= $list['wip_limit'] ?></span>
                <?php endif; ?>
              </span>
              <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.65rem">
                <?= (int)$list['task_count'] ?>
              </span>
              <div class="d-flex gap-1 flex-shrink-0">
                <button class="btn btn-outline-secondary"
                        style="padding:.18rem .45rem;font-size:.72rem"
                        onclick="openListModal(<?= $list['id'] ?>,<?= $active_ws_id ?>,'<?= h(addslashes($list['name'])) ?>','<?= h($list['color']) ?>',<?= $list['is_done_state'] ?>,<?= $list['wip_limit']?:'null' ?>)">
                  <i class="bi bi-pencil"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirmDelete(<?= $list['task_count'] ?>)">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="delete_list">
                  <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
                  <input type="hidden" name="list_id" value="<?= $list['id'] ?>">
                  <button class="btn btn-outline-danger" style="padding:.18rem .45rem;font-size:.72rem">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <form method="post" id="reorder-form">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="reorder_lists">
          <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
          <input type="hidden" name="order"   id="lists-order-input" value="">
          <button type="submit" id="save-order-btn" class="btn btn-sm btn-success d-none">
            <i class="bi bi-check2 me-1"></i>Zapisz kolejność
          </button>
        </form>
      </div>

      <!-- ── Członkowie ── -->
      <div class="tab-pane fade" id="tab-members">
        <div class="card border-0 shadow-sm mb-3">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="ps-3 small">Użytkownik</th>
                  <th class="small">Rola</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$members): ?>
                <tr><td colspan="3" class="text-muted small ps-3 py-3 text-center">
                  Brak przypisanych członków. Administratorzy systemu mają dostęp zawsze.
                </td></tr>
                <?php endif; ?>
                <?php foreach ($members as $m): ?>
                <tr>
                  <td class="ps-3 small">
                    <div class="fw-semibold"><?= h($m['name']) ?></div>
                    <div class="text-muted" style="font-size:.71rem"><?= h($m['email']) ?></div>
                  </td>
                  <td>
                    <span class="badge bg-<?= $m['role']==='admin'?'danger':($m['role']==='editor'?'primary':($m['role']==='member'?'info':'secondary')) ?>">
                      <?= h($m['role']) ?>
                    </span>
                  </td>
                  <td class="text-end pe-3">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                      <input type="hidden" name="_action"    value="remove_member">
                      <input type="hidden" name="ws_id"      value="<?= $active_ws_id ?>">
                      <input type="hidden" name="member_uid" value="<?= $m['user_id'] ?>">
                      <button class="btn btn-outline-danger" style="padding:.15rem .4rem;font-size:.72rem">
                        <i class="bi bi-person-dash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white small fw-semibold py-2">Dodaj użytkownika do obszaru</div>
          <div class="card-body py-3">
            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="add_member">
              <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
              <div class="col-sm-5">
                <label class="form-label small fw-semibold mb-1">Użytkownik</label>
                <select name="member_uid" class="form-select form-select-sm" required>
                  <option value="">— wybierz —</option>
                  <?php foreach ($all_users as $u):
                    if (!in_array($u['id'], $member_ids)): ?>
                  <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['email']) ?>)</option>
                  <?php endif; endforeach; ?>
                </select>
              </div>
              <div class="col-sm-3">
                <label class="form-label small fw-semibold mb-1">Rola</label>
                <select name="member_role" class="form-select form-select-sm">
                  <option value="member" selected>Member</option>
                  <option value="editor">Editor</option>
                  <option value="viewer">Viewer</option>
                  <option value="admin">Admin obszaru</option>
                </select>
              </div>
              <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-person-plus me-1"></i>Dodaj
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- ── Zespoły ── -->
      <div class="tab-pane fade" id="tab-teams">
        <p class="text-muted small">
          Zespół przypisany tutaj daje dostęp do obszaru WSZYSTKIM swoim członkom,
          dodatkowo obok osób dodanych pojedynczo w zakładce „Członkowie".
          Zarządzanie samymi zespołami (tworzenie, członkowie) — w
          <a href="<?= APP_URL ?>/tasks/settings/teams.php">ustawieniach Zespołów</a>.
        </p>
        <div class="card border-0 shadow-sm mb-3">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="ps-3 small">Zespół</th>
                  <th class="small">Rola w obszarze</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="ws-teams-tbody">
                <?php if (!$linked_teams): ?>
                <tr id="ws-teams-empty-row"><td colspan="3" class="text-muted small ps-3 py-3 text-center">
                  Brak przypisanych zespołów.
                </td></tr>
                <?php endif; ?>
                <?php foreach ($linked_teams as $t): ?>
                <tr data-team-id="<?= (int)$t['id'] ?>">
                  <td class="ps-3 small">
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= h($t['color']) ?>;margin-right:.4rem"></span>
                    <span class="fw-semibold"><?= h($t['name']) ?></span>
                    <span class="text-muted" style="font-size:.71rem"> · <?= (int)$t['member_count'] ?> os.</span>
                  </td>
                  <td>
                    <span class="badge bg-<?= $t['role']==='admin'?'danger':($t['role']==='editor'?'primary':($t['role']==='member'?'info':'secondary')) ?>">
                      <?= h($t['role']) ?>
                    </span>
                  </td>
                  <td class="text-end pe-3">
                    <button type="button" class="btn btn-outline-danger" style="padding:.15rem .4rem;font-size:.72rem"
                            onclick="wsUnlinkTeam(<?= (int)$t['id'] ?>, this)">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php if ($linkable_teams): ?>
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white small fw-semibold py-2">Przypisz zespół do obszaru</div>
          <div class="card-body py-3">
            <div class="row g-2 align-items-end">
              <div class="col-sm-5">
                <label class="form-label small fw-semibold mb-1">Zespół</label>
                <select id="ws-link-team-select" class="form-select form-select-sm">
                  <?php foreach ($linkable_teams as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" data-name="<?= h($t['name']) ?>" data-color="<?= h($t['color']) ?>" data-members="<?= (int)$t['member_count'] ?>"><?= h($t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-3">
                <label class="form-label small fw-semibold mb-1">Rola</label>
                <select id="ws-link-team-role" class="form-select form-select-sm">
                  <option value="member" selected>Member</option>
                  <option value="editor">Editor</option>
                  <option value="viewer">Viewer</option>
                  <option value="admin">Admin obszaru</option>
                </select>
              </div>
              <div class="col-auto">
                <button type="button" class="btn btn-sm btn-primary" onclick="wsLinkTeam(<?= (int)$active_ws_id ?>)">
                  <i class="bi bi-plus-lg me-1"></i>Przypisz
                </button>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <p class="text-muted small">Wszystkie aktywne zespoły są już przypisane do tego obszaru, albo nie istnieje żaden zespół — <a href="<?= APP_URL ?>/tasks/settings/teams.php">utwórz zespół</a>.</p>
        <?php endif; ?>
      </div>

      <!-- ── Ustawienia ── -->
      <div class="tab-pane fade" id="tab-settings">
        <?php
          $all_roles_for_ws = db_all("SELECT name, display_name FROM roles ORDER BY display_name");
          $ws_visible_roles = json_decode($active_ws['visible_roles'] ?? '', true) ?: [];
          $ws_edit_roles    = json_decode($active_ws['edit_roles']    ?? '', true) ?: [];
        ?>
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <form method="post" class="row g-3">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="save_workspace">
              <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
              <div class="col-sm-8">
                <label class="form-label small fw-semibold">Nazwa *</label>
                <input type="text" name="name" class="form-control form-control-sm"
                       value="<?= h($active_ws['name']) ?>" required maxlength="120">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">Kolor</label>
                <input type="color" name="color" class="form-control form-control-sm form-control-color"
                       value="<?= h($active_ws['color']) ?>">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold">Opis</label>
                <textarea name="description" class="form-control form-control-sm" rows="2"><?= h($active_ws['description']) ?></textarea>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Ikona (bez bi-)</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi <?= h($active_ws['icon']) ?>" id="ws-icon-preview"></i></span>
                  <input type="text" name="icon" id="ws-icon-input" class="form-control form-control-sm"
                         value="<?= h(ltrim($active_ws['icon'],'bi-')) ?>" placeholder="kanban">
                </div>
              </div>

              <?php if ($all_roles_for_ws): ?>
              <!-- ── Role widoczności / edycji ── -->
              <div class="col-12">
                <hr class="my-1">
                <div class="fw-semibold small mb-2">
                  <i class="bi bi-shield-lock me-1 text-primary"></i>Uprawnienia ról systemowych
                </div>
                <p class="text-muted small mb-2">
                  Puste = brak ograniczeń (każda rola z dostępem do obszaru).
                  Administratorzy systemu mają zawsze pełny dostęp.
                </p>
                <div class="row g-3">
                  <div class="col-sm-6">
                    <label class="form-label small fw-semibold text-secondary">
                      <i class="bi bi-eye me-1"></i>Może widzieć obszar (<code>visible_roles</code>)
                    </label>
                    <div class="border rounded p-2" style="max-height:160px;overflow-y:auto;background:#fafafa">
                      <?php foreach ($all_roles_for_ws as $r): ?>
                      <div class="form-check form-check-sm mb-1">
                        <input class="form-check-input" type="checkbox"
                               name="visible_roles[]" value="<?= h($r['name']) ?>"
                               id="vr_<?= h($r['name']) ?>"
                               <?= in_array($r['name'], $ws_visible_roles) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="vr_<?= h($r['name']) ?>">
                          <?= h($r['display_name']) ?>
                        </label>
                      </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <label class="form-label small fw-semibold text-secondary">
                      <i class="bi bi-pencil-square me-1"></i>Może edytować (<code>edit_roles</code>)
                    </label>
                    <div class="border rounded p-2" style="max-height:160px;overflow-y:auto;background:#fafafa">
                      <?php foreach ($all_roles_for_ws as $r): ?>
                      <div class="form-check form-check-sm mb-1">
                        <input class="form-check-input" type="checkbox"
                               name="edit_roles[]" value="<?= h($r['name']) ?>"
                               id="er_<?= h($r['name']) ?>"
                               <?= in_array($r['name'], $ws_edit_roles) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="er_<?= h($r['name']) ?>">
                          <?= h($r['display_name']) ?>
                        </label>
                      </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-check2 me-1"></i>Zapisz zmiany
                </button>
                <form method="post" class="d-inline mb-0">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="toggle_workspace">
                  <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
                  <button type="submit" class="btn btn-sm <?= $active_ws['is_active']?'btn-outline-warning':'btn-outline-success' ?>">
                    <i class="bi bi-<?= $active_ws['is_active']?'pause':'play' ?> me-1"></i>
                    <?= $active_ws['is_active']?'Dezaktywuj':'Aktywuj' ?>
                  </button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger ms-auto"
                        data-bs-toggle="modal" data-bs-target="#deleteWsModal"
                        onclick="prepareDeleteWs('<?= h(addslashes($active_ws['name'])) ?>',<?= $active_ws_id ?>,<?= $ws_task_count ?>)">
                  <i class="bi bi-trash3 me-1"></i>Usuń obszar
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

    </div><!-- /tab-content -->
    <?php endif; ?>
  </div><!-- /col-lg-9 -->
</div>

<!-- Modal: Nowy obszar -->
<div class="modal fade" id="wsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_workspace">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold">Nowy obszar roboczy</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa *</label>
            <input type="text" name="name" id="wsModalName" class="form-control form-control-sm" required maxlength="120" placeholder="np. Projekt 2026">
          </div>
          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label small fw-semibold">Kolor</label>
              <input type="color" name="color" class="form-control form-control-sm form-control-color" value="#2563eb">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">Ikona (bez bi-)</label>
              <input type="text" name="icon" class="form-control form-control-sm" value="kanban" placeholder="kanban">
            </div>
          </div>

          <?php if (ws_available()): ?>
          <hr class="my-2">
          <div class="mb-1">
            <label class="form-label small fw-semibold mb-1">
              <i class="bi bi-folder2-open me-1 text-primary"></i>Foldery w SharePoint
              <span class="text-muted fw-normal">(opcjonalnie)</span>
            </label>
            <div id="wsFolderList" class="d-flex flex-column gap-1 mb-1"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="wsAddFolder" style="font-size:.8rem">
              <i class="bi bi-folder-plus me-1"></i>Dodaj folder
            </button>
            <div class="form-text" style="font-size:.72rem">Foldery zostaną automatycznie utworzone w SharePoint po zapisaniu obszaru.</div>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Utwórz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('wsAddFolder')?.addEventListener('click', function() {
  const list = document.getElementById('wsFolderList');
  const row  = document.createElement('div');
  row.className = 'd-flex gap-1 align-items-center';
  row.innerHTML = `
    <input type="text" name="folders[]" class="form-control form-control-sm flex-grow-1"
           placeholder="Nazwa folderu" maxlength="120" style="font-size:.83rem">
    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 ws-rm-folder" title="Usuń">
      <i class="bi bi-x-lg" style="font-size:.75rem"></i>
    </button>`;
  row.querySelector('.ws-rm-folder').addEventListener('click', () => row.remove());
  list.appendChild(row);
  row.querySelector('input').focus();
});
</script>

<!-- Modal: Kolumna -->
<div class="modal fade" id="listModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_list">
        <input type="hidden" name="ws_id"   id="lm-ws-id"   value="">
        <input type="hidden" name="list_id" id="lm-list-id" value="">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold" id="lm-title">Kolumna</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa *</label>
            <input type="text" name="lname" id="lm-name" class="form-control form-control-sm" required maxlength="120">
          </div>
          <div class="row g-2 mb-2">
            <div class="col">
              <label class="form-label small fw-semibold">Kolor paska</label>
              <input type="color" name="lcolor" id="lm-color" class="form-control form-control-sm form-control-color" value="#e2e8f0">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">Limit WIP</label>
              <input type="number" name="wip_limit" id="lm-wip" class="form-control form-control-sm" min="0" placeholder="Brak">
            </div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_done_state" value="1" id="lm-done">
            <label class="form-check-label small" for="lm-done">Kolumna „ukończone"</label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Usuń obszar -->
<div class="modal fade" id="deleteWsModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content border-danger border-opacity-50">
      <form method="post">
        <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"      value="delete_workspace">
        <input type="hidden" name="ws_id"        id="del-ws-id"   value="">
        <input type="hidden" name="force_delete" value="1">
        <div class="modal-header py-2 bg-danger bg-opacity-10">
          <h6 class="modal-title fw-bold text-danger"><i class="bi bi-trash3 me-1"></i>Usuń obszar</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small mb-2">Usuwasz obszar: <strong id="del-ws-name"></strong></p>
          <div id="del-ws-warn" class="alert alert-warning small py-2 mb-2 d-none">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Obszar zawiera <strong id="del-ws-cnt"></strong> zadań — zostaną usunięte bezpowrotnie.
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="del-ws-chk" required>
            <label class="form-check-label small fw-semibold text-danger" for="del-ws-chk">
              Rozumiem, usuń bezpowrotnie
            </label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash3 me-1"></i>Usuń</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
// Ikona podgląd
const iconInp = document.getElementById('ws-icon-input');
const iconPrv = document.getElementById('ws-icon-preview');
if (iconInp && iconPrv) iconInp.addEventListener('input', () => iconPrv.className = 'bi bi-' + iconInp.value.trim());

// Modal kolumny
function openListModal(id, wsId, name='', color='#e2e8f0', done=0, wip=null) {
    document.getElementById('lm-ws-id').value   = wsId;
    document.getElementById('lm-list-id').value = id;
    document.getElementById('lm-name').value    = name;
    document.getElementById('lm-color').value   = color || '#e2e8f0';
    document.getElementById('lm-done').checked  = !!done;
    document.getElementById('lm-wip').value     = wip || '';
    document.getElementById('lm-title').textContent = id ? 'Edytuj kolumnę' : 'Nowa kolumna';
    new bootstrap.Modal(document.getElementById('listModal')).show();
}

function confirmDelete(cnt) {
    if (cnt > 0) { alert('Kolumna zawiera ' + cnt + ' zadań. Przenieś je najpierw.'); return false; }
    return confirm('Usunąć tę kolumnę?');
}

// Sortowanie kolumn
const sortEl = document.getElementById('lists-sortable');
if (sortEl) {
    Sortable.create(sortEl, {
        animation: 150, handle: '.bi-grip-vertical',
        onEnd: function() {
            const ids = Array.from(sortEl.querySelectorAll('[data-list-id]')).map(e=>e.dataset.listId).join(',');
            document.getElementById('lists-order-input').value = ids;
            document.getElementById('save-order-btn').classList.remove('d-none');
        }
    });
}

// Modal usuwania obszaru
function prepareDeleteWs(name, wsId, tasks) {
    document.getElementById('del-ws-id').value = wsId;
    document.getElementById('del-ws-name').textContent = name;
    document.getElementById('del-ws-chk').checked = false;
    const warn = document.getElementById('del-ws-warn');
    const cnt  = document.getElementById('del-ws-cnt');
    if (tasks > 0) { cnt.textContent = tasks; warn.classList.remove('d-none'); }
    else warn.classList.add('d-none');
}

// ── Zespoły przypisane do obszaru ────────────────────────────────────────
const WS_TEAMS_CSRF = <?= json_encode(csrf_token()) ?>;
const WS_TEAMS_BASE = <?= json_encode(rtrim(APP_URL, '/')) ?>;

function wsTeamApi(action, extra) {
    return fetch(WS_TEAMS_BASE + '/tasks/api/team.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify(Object.assign({_csrf: WS_TEAMS_CSRF, action: action}, extra || {}))
    }).then(r => r.json());
}

function wsLinkTeam(wsId) {
    const sel  = document.getElementById('ws-link-team-select');
    const role = document.getElementById('ws-link-team-role').value;
    if (!sel || !sel.value) return;
    wsTeamApi('link_workspace', {workspace_id: wsId, team_id: parseInt(sel.value, 10), role: role})
        .then(r => { if (r.ok) window.location.reload(); else alert(r.error || 'Błąd przypisania zespołu.'); });
}

function wsUnlinkTeam(teamId, btn) {
    const wsId = <?= (int)$active_ws_id ?>;
    if (!confirm('Odpiąć ten zespół od obszaru? Jego członkowie stracą dostęp nadany przez zespół (chyba że są dodani też pojedynczo).')) return;
    wsTeamApi('unlink_workspace', {workspace_id: wsId, team_id: teamId})
        .then(r => { if (r.ok) window.location.reload(); else alert(r.error || 'Błąd.'); });
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
