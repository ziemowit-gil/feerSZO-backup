<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/workspaces.php';

require_role('admin');
$uid = (int)(current_user()['id'] ?? 0);

// ── Obsługa POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    // ── Utwórz / Edytuj obszar ────────────────────────────────────────────
    if ($act === 'save_workspace') {
        $ws_id = (int)($_POST['ws_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#2563eb';
        $icon  = preg_replace('/[^a-z0-9\-]/', '', $_POST['icon'] ?? 'bi-kanban');
        $slug  = trim(preg_replace('/[^a-z0-9\-]/', '-', mb_strtolower($name)));
        $slug  = preg_replace('/-+/', '-', trim($slug, '-'));

        if (!$name) { flash_set('danger', 'Nazwa obszaru jest wymagana.'); goto redirect; }

        if ($ws_id) {
            db()->prepare(
                "UPDATE task_workspaces SET name=?, description=?, color=?, icon=?, updated_at=datetime('now','localtime')
                 WHERE id=?"
            )->execute([$name, $desc, $color, 'bi-' . ltrim($icon, 'bi-'), $ws_id]);
            flash_set('success', 'Obszar zaktualizowany.');
        } else {
            // Unikalny slug
            $base = $slug ?: 'obszar';
            $slug = $base;
            $n    = 1;
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
            // Dodaj domyślne listy
            foreach ([
                ['Nowe',        1, 0, '#e2e8f0'],
                ['W trakcie',   2, 0, '#2563eb'],
                ['Do weryfikacji', 3, 0, '#f59e0b'],
                ['Gotowe',      4, 1, '#16a34a'],
            ] as [$lname, $pos, $done, $lcolor]) {
                db_insert('task_lists', [
                    'workspace_id'  => $id,
                    'name'          => $lname,
                    'position'      => $pos,
                    'color'         => $lcolor,
                    'is_done_state' => $done,
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
            // Twórca obszaru → lider (admin) + wszyscy sys-admini
            $now_ts    = date('Y-m-d H:i:s');
            $to_add    = [['id' => $uid]]; // twórca zawsze pierwszy
            $sys_admins = db_all("SELECT id FROM users WHERE role='admin' AND is_active=1");
            foreach ($sys_admins as $adm) {
                if ((int)$adm['id'] !== $uid) $to_add[] = $adm;
            }
            foreach ($to_add as $adm) {
                try {
                    db()->prepare(
                        "INSERT OR IGNORE INTO task_workspace_members
                         (workspace_id, user_id, role, added_by, added_at)
                         VALUES (?, ?, 'admin', ?, ?)"
                    )->execute([$id, (int)$adm['id'], $uid, $now_ts]);
                } catch (\Throwable $e) {}
            }
            ws_sp_init_workspace($id);
            foreach ((array)($_POST['folders'] ?? []) as $fname) {
                $fname = trim($fname);
                if ($fname !== '') {
                    try { ws_create_folder($id, $fname, '', $uid); } catch (\Throwable $e) {}
                }
            }
            flash_set('success', 'Obszar „' . $name . '" utworzony z domyślnymi listami.');
        }
        goto redirect;
    }

    // ── Przełącz aktywność obszaru ────────────────────────────────────────
    if ($act === 'toggle_workspace') {
        $ws_id = (int)($_POST['ws_id'] ?? 0);
        $ws    = db_one("SELECT * FROM task_workspaces WHERE id=?", [$ws_id]);
        if ($ws) {
            db()->prepare(
                "UPDATE task_workspaces SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?"
            )->execute([$ws['is_active'] ? 0 : 1, $ws_id]);
            flash_set('success', 'Status obszaru zmieniony.');
        }
        goto redirect;
    }

    // ── Usuń obszar ───────────────────────────────────────────────────────
    if ($act === 'delete_workspace') {
        $ws_id = (int)($_POST['ws_id'] ?? 0);
        $ws    = db_one("SELECT * FROM task_workspaces WHERE id=?", [$ws_id]);
        if ($ws) {
            $task_count = (int)(db_one(
                "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",
                [$ws_id]
            )['n'] ?? 0);

            if ($task_count > 0 && empty($_POST['force_delete'])) {
                flash_set('danger', 'Obszar ma ' . $task_count . ' aktywnych zadań. Zaznacz opcję potwierdzenia, aby usunąć.');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // Soft-delete wszystkich zadań
                    $now = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET deleted_at=? WHERE workspace_id=?")->execute([$now, $ws_id]);
                    // Usuń listy, członków, tagi obszaru
                    $pdo->prepare("DELETE FROM task_lists            WHERE workspace_id=?")->execute([$ws_id]);
                    $pdo->prepare("DELETE FROM task_workspace_members WHERE workspace_id=?")->execute([$ws_id]);
                    $pdo->prepare("DELETE FROM task_tags              WHERE workspace_id=?")->execute([$ws_id]);
                    // Usuń sam obszar
                    $pdo->prepare("DELETE FROM task_workspaces WHERE id=?")->execute([$ws_id]);
                    $pdo->commit();
                    flash_set('success', 'Obszar „' . $ws['name'] . '" i jego zadania zostały usunięte.');
                    header('Location: tasks_workspaces.php'); exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    flash_set('danger', 'Błąd usuwania: ' . $e->getMessage());
                }
            }
        }
        goto redirect;
    }

    // ── Zapisz listę (kolumnę) ─────────────────────────────────────────────
    if ($act === 'save_list') {
        $ws_id    = (int)($_POST['ws_id']   ?? 0);
        $list_id  = (int)($_POST['list_id'] ?? 0);
        $lname    = trim($_POST['lname']    ?? '');
        $color    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['lcolor'] ?? '') ? $_POST['lcolor'] : '#e2e8f0';
        $done     = (int)($_POST['is_done_state'] ?? 0);
        $wip      = trim($_POST['wip_limit'] ?? '') !== '' ? max(1, (int)$_POST['wip_limit']) : null;

        if (!$ws_id || !$lname) { flash_set('danger', 'Nazwa kolumny jest wymagana.'); goto redirect; }

        if ($list_id) {
            db()->prepare(
                "UPDATE task_lists SET name=?, color=?, is_done_state=?, wip_limit=?,
                 updated_at=datetime('now','localtime') WHERE id=? AND workspace_id=?"
            )->execute([$lname, $color, $done, $wip, $list_id, $ws_id]);
        } else {
            $max_p = db_one("SELECT MAX(position) AS m FROM task_lists WHERE workspace_id=?", [$ws_id]);
            db_insert('task_lists', [
                'workspace_id'  => $ws_id,
                'name'          => $lname,
                'position'      => (float)($max_p['m'] ?? 0) + 1,
                'color'         => $color,
                'is_done_state' => $done,
                'wip_limit'     => $wip,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
        flash_set('success', 'Kolumna zapisana.');
        goto redirect;
    }

    // ── Usuń listę ─────────────────────────────────────────────────────────
    if ($act === 'delete_list') {
        $ws_id   = (int)($_POST['ws_id']   ?? 0);
        $list_id = (int)($_POST['list_id'] ?? 0);
        $count   = db_one("SELECT COUNT(*) AS c FROM tasks WHERE list_id=? AND deleted_at IS NULL", [$list_id]);
        if ((int)$count['c'] > 0) {
            flash_set('danger', 'Nie można usunąć kolumny zawierającej zadania. Przenieś lub usuń zadania najpierw.');
        } else {
            db()->prepare("DELETE FROM task_lists WHERE id=? AND workspace_id=?")->execute([$list_id, $ws_id]);
            flash_set('success', 'Kolumna usunięta.');
        }
        goto redirect;
    }

    // ── Zapisz kolejność list ──────────────────────────────────────────────
    if ($act === 'reorder_lists') {
        $ws_id = (int)($_POST['ws_id'] ?? 0);
        $ids   = array_map('intval', explode(',', $_POST['order'] ?? ''));
        $pos   = 1;
        $stmt  = db()->prepare(
            "UPDATE task_lists SET position=?, updated_at=datetime('now','localtime')
             WHERE id=? AND workspace_id=?"
        );
        foreach ($ids as $lid) {
            $stmt->execute([$pos++, $lid, $ws_id]);
        }
        flash_set('success', 'Kolejność zapisana.');
        goto redirect;
    }

    // ── Zarządzanie członkami ──────────────────────────────────────────────
    if ($act === 'add_member') {
        $ws_id      = (int)($_POST['ws_id']      ?? 0);
        $member_uid = (int)($_POST['member_uid'] ?? 0);
        $role       = in_array($_POST['member_role'] ?? '', ['admin','editor','viewer'])
                      ? $_POST['member_role'] : 'editor';
        if ($ws_id && $member_uid) {
            db()->prepare(
                "INSERT OR REPLACE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at)
                 VALUES (?, ?, ?, ?, datetime('now','localtime'))"
            )->execute([$ws_id, $member_uid, $role, $uid]);
            flash_set('success', 'Użytkownik dodany do obszaru.');
        }
        goto redirect;
    }

    if ($act === 'remove_member') {
        $ws_id      = (int)($_POST['ws_id']      ?? 0);
        $member_uid = (int)($_POST['member_uid'] ?? 0);
        db()->prepare("DELETE FROM task_workspace_members WHERE workspace_id=? AND user_id=?")
            ->execute([$ws_id, $member_uid]);
        flash_set('success', 'Użytkownik usunięty z obszaru.');
        goto redirect;
    }

    redirect:
    header('Location: ' . APP_URL . '/admin/tasks_workspaces.php' . ($ws_id ? '?ws=' . $ws_id : ''));
    exit;
}

// ── Dane do widoku ────────────────────────────────────────────────────────
$active_ws_id = (int)($_GET['ws'] ?? 0);
$workspaces   = db_all(
    "SELECT tw.*, u.name AS creator_name, COUNT(DISTINCT t.id) AS task_count
     FROM task_workspaces tw
     LEFT JOIN users u ON u.id = tw.created_by
     LEFT JOIN tasks t ON t.workspace_id = tw.id AND t.deleted_at IS NULL
     GROUP BY tw.id
     ORDER BY tw.name"
);

$active_ws  = null;
$lists      = [];
$members    = [];
$all_users  = [];

if ($active_ws_id) {
    $active_ws = db_one("SELECT * FROM task_workspaces WHERE id=?", [$active_ws_id]);
    $lists     = db_all(
        "SELECT tl.*, COUNT(t.id) AS task_count FROM task_lists tl
         LEFT JOIN tasks t ON t.list_id = tl.id AND t.deleted_at IS NULL
         WHERE tl.workspace_id=? GROUP BY tl.id ORDER BY tl.position",
        [$active_ws_id]
    );
    $members   = db_all(
        "SELECT twm.*, u.name, u.email FROM task_workspace_members twm
         JOIN users u ON u.id = twm.user_id
         WHERE twm.workspace_id=? ORDER BY u.name",
        [$active_ws_id]
    );
    $member_ids = array_column($members, 'user_id');
    $all_users  = db_all(
        "SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name"
    );
}

$PAGE_TITLE = 'Obszary robocze — Zadania';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-kanban text-primary me-2"></i>Obszary robocze
  </h5>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-eye me-1"></i>Podgląd tablicy
    </a>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#wsModal">
      <i class="bi bi-plus-lg me-1"></i>Nowy obszar
    </button>
  </div>
</div>

<div class="row g-3">

  <!-- ── Lista obszarów ──────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small py-2">Obszary</div>
      <div class="list-group list-group-flush">
        <?php if (!$workspaces): ?>
        <div class="list-group-item text-muted small py-3 text-center">
          Brak obszarów — utwórz pierwszy.
        </div>
        <?php endif; ?>
        <?php foreach ($workspaces as $ws): ?>
        <a href="?ws=<?= $ws['id'] ?>"
           class="list-group-item list-group-item-action d-flex gap-2 align-items-center py-2
                  <?= $ws['id'] == $active_ws_id ? 'active' : '' ?>
                  <?= !$ws['is_active'] ? 'text-muted' : '' ?>">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= h($ws['color']) ?>;flex-shrink:0"></span>
          <i class="bi <?= h($ws['icon']) ?>" style="font-size:.85rem"></i>
          <span class="flex-grow-1 small"><?= h($ws['name']) ?></span>
          <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.65rem">
            <?= (int)$ws['task_count'] ?>
          </span>
          <?php if (!$ws['is_active']): ?>
          <span class="badge bg-secondary" style="font-size:.6rem">nieakt.</span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── Szczegóły wybranego obszaru ─────────────────────────────────── -->
  <div class="col-lg-8">
    <?php if (!$active_ws): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-kanban display-4 opacity-25 d-block mb-2"></i>
        Wybierz obszar z listy lub utwórz nowy.
      </div>
    </div>

    <?php else: ?>

    <!-- Tabs: listy / członkowie / ustawienia -->
    <ul class="nav nav-tabs mb-3" id="wsTabs">
      <li class="nav-item">
        <a class="nav-link active" href="#tab-lists" data-bs-toggle="tab">
          <i class="bi bi-view-stacked me-1"></i>Kolumny (<?= count($lists) ?>)
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="#tab-members" data-bs-toggle="tab">
          <i class="bi bi-people me-1"></i>Członkowie (<?= count($members) ?>)
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="#tab-settings" data-bs-toggle="tab">
          <i class="bi bi-gear me-1"></i>Ustawienia
        </a>
      </li>
    </ul>

    <div class="tab-content">

      <!-- ── Kolumny ──────────────────────────────────────────────────── -->
      <div class="tab-pane fade show active" id="tab-lists">
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <span class="small fw-semibold">Kolumny tablicy</span>
            <button class="btn btn-sm btn-outline-primary"
                    onclick="openListModal(0, <?= $active_ws_id ?>)">
              <i class="bi bi-plus-lg me-1"></i>Dodaj kolumnę
            </button>
          </div>
          <div class="list-group list-group-flush" id="lists-sortable">
            <?php foreach ($lists as $list): ?>
            <div class="list-group-item d-flex align-items-center gap-3 py-2"
                 data-list-id="<?= $list['id'] ?>">
              <i class="bi bi-grip-vertical text-muted" style="cursor:grab"></i>
              <span class="badge" style="width:12px;height:12px;border-radius:2px;padding:0;background:<?= h($list['color']?:'#e2e8f0') ?>"></span>
              <span class="flex-grow-1 small fw-semibold">
                <?= h($list['name']) ?>
                <?php if ($list['is_done_state']): ?>
                <i class="bi bi-check-circle-fill text-success ms-1" style="font-size:.75rem" title="Kolumna ukończonych"></i>
                <?php endif; ?>
                <?php if ($list['wip_limit']): ?>
                <span class="text-muted fw-normal ms-1" style="font-size:.75rem">WIP: <?= $list['wip_limit'] ?></span>
                <?php endif; ?>
              </span>
              <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.65rem">
                <?= (int)$list['task_count'] ?> zadań
              </span>
              <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-secondary"
                        onclick="openListModal(<?= $list['id'] ?>, <?= $active_ws_id ?>,
                                 '<?= h(addslashes($list['name'])) ?>',
                                 '<?= h($list['color']) ?>',
                                 <?= $list['is_done_state'] ?>,
                                 <?= $list['wip_limit'] ?: 'null' ?>)"
                        style="padding:.2rem .5rem;font-size:.75rem">
                  <i class="bi bi-pencil"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirmDelete(<?= $list['task_count'] ?>)">
                  <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"  value="delete_list">
                  <input type="hidden" name="ws_id"    value="<?= $active_ws_id ?>">
                  <input type="hidden" name="list_id"  value="<?= $list['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger"
                          style="padding:.2rem .5rem;font-size:.75rem">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Zapisz kolejność -->
        <form method="post" id="reorder-form">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="reorder_lists">
          <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
          <input type="hidden" name="order"   id="lists-order-input" value="">
          <button type="submit" id="save-order-btn" class="btn btn-sm btn-success" style="display:none">
            <i class="bi bi-check2 me-1"></i>Zapisz kolejność
          </button>
        </form>
      </div>

      <!-- ── Członkowie ────────────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tab-members">
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <thead class="table-light">
                <tr>
                  <th class="small ps-3">Użytkownik</th>
                  <th class="small">Rola w obszarze</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$members): ?>
              <tr><td colspan="3" class="text-muted small ps-3 py-3">
                Brak członków (administratorzy systemu mają dostęp zawsze).
              </td></tr>
              <?php endif; ?>
              <?php foreach ($members as $m): ?>
              <tr>
                <td class="small ps-3">
                  <strong><?= h($m['name']) ?></strong>
                  <div class="text-muted" style="font-size:.72rem"><?= h($m['email']) ?></div>
                </td>
                <td>
                  <span class="badge bg-<?= $m['role'] === 'admin' ? 'danger' : ($m['role'] === 'editor' ? 'primary' : 'secondary') ?>">
                    <?= $m['role'] ?>
                  </span>
                </td>
                <td class="text-end pe-2">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                    <input type="hidden" name="_action"    value="remove_member">
                    <input type="hidden" name="ws_id"      value="<?= $active_ws_id ?>">
                    <input type="hidden" name="member_uid" value="<?= $m['user_id'] ?>">
                    <button class="btn btn-xs btn-outline-danger" style="padding:.15rem .45rem;font-size:.72rem">
                      <i class="bi bi-x"></i>
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
          <div class="card-header bg-white small fw-semibold py-2">Dodaj użytkownika</div>
          <div class="card-body py-3">
            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="add_member">
              <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
              <div class="col-sm-5">
                <label class="form-label small fw-semibold mb-1">Użytkownik</label>
                <select name="member_uid" class="form-select form-select-sm" required>
                  <option value="">— wybierz —</option>
                  <?php foreach ($all_users as $u): ?>
                  <?php if (!in_array($u['id'], $member_ids)): ?>
                  <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['email']) ?>)</option>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-3">
                <label class="form-label small fw-semibold mb-1">Rola</label>
                <select name="member_role" class="form-select form-select-sm">
                  <option value="editor">Editor</option>
                  <option value="viewer">Viewer</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
              <div class="col-sm-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-person-plus me-1"></i>Dodaj
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- ── Ustawienia ────────────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tab-settings">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <form method="post" class="row g-3">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="save_workspace">
              <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">

              <div class="col-sm-8">
                <label class="form-label small fw-semibold">Nazwa obszaru *</label>
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
                <label class="form-label small fw-semibold">Ikona Bootstrap Icons</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi <?= h($active_ws['icon']) ?>" id="ws-icon-preview"></i></span>
                  <input type="text" name="icon" class="form-control form-control-sm"
                         id="ws-icon-input"
                         value="<?= h(ltrim($active_ws['icon'], 'bi-')) ?>"
                         placeholder="kanban, check2-square, ...">
                </div>
                <div class="form-text" style="font-size:.72rem">
                  Wpisz nazwę ikony bez prefiksu „bi-". <a href="https://icons.getbootstrap.com" target="_blank">Lista ikon</a>
                </div>
              </div>
              <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-check2 me-1"></i>Zapisz zmiany
                </button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="toggle_workspace">
                  <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
                  <button type="submit" class="btn btn-sm <?= $active_ws['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                    <i class="bi bi-<?= $active_ws['is_active'] ? 'pause' : 'play' ?> me-1"></i>
                    <?= $active_ws['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>
                  </button>
                </form>

                <!-- Usuń obszar -->
                <button type="button"
                        class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal"
                        data-bs-target="#deleteWsModal"
                        data-ws-name="<?= h($active_ws['name']) ?>"
                        data-ws-id="<?= $active_ws_id ?>"
                        data-task-count="<?= (int)(db_one("SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",[$active_ws_id])['n']??0) ?>"
                        onclick="prepareDeleteWs(this)">
                  <i class="bi bi-trash me-1"></i>Usuń obszar
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

    </div><!-- /tab-content -->
    <?php endif; /* active_ws */ ?>
  </div><!-- /col-lg-8 -->

</div><!-- /row -->

<!-- ── Modal: Nowy obszar ──────────────────────────────────────────────── -->
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
            <input type="text" name="name" class="form-control form-control-sm" required maxlength="120"
                   placeholder="np. Projekt FEER 2026">
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
          <div>
            <label class="form-label small fw-semibold mb-1">
              <i class="bi bi-folder2-open me-1 text-primary"></i>Foldery w SharePoint
              <span class="text-muted fw-normal">(opcjonalnie)</span>
            </label>
            <div id="adminWsFolderList" class="d-flex flex-column gap-1 mb-1"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="adminWsAddFolder" style="font-size:.8rem">
              <i class="bi bi-folder-plus me-1"></i>Dodaj folder
            </button>
            <div class="form-text" style="font-size:.72rem">Foldery zostaną automatycznie utworzone w SharePoint po zapisaniu obszaru.</div>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-check2 me-1"></i>Utwórz
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('adminWsAddFolder')?.addEventListener('click', function() {
  const list = document.getElementById('adminWsFolderList');
  const row  = document.createElement('div');
  row.className = 'd-flex gap-1 align-items-center';
  row.innerHTML = `
    <input type="text" name="folders[]" class="form-control form-control-sm flex-grow-1"
           placeholder="Nazwa folderu" maxlength="120" style="font-size:.83rem">
    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń" onclick="this.closest('div').remove()">
      <i class="bi bi-x-lg" style="font-size:.75rem"></i>
    </button>`;
  list.appendChild(row);
  row.querySelector('input').focus();
});
</script>

<!-- ── Modal: Kolumna ──────────────────────────────────────────────────── -->
<div class="modal fade" id="listModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"  value="save_list">
        <input type="hidden" name="ws_id"    id="lm-ws-id"   value="">
        <input type="hidden" name="list_id"  id="lm-list-id" value="">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold" id="lm-title">Kolumna</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa *</label>
            <input type="text" name="lname" id="lm-name" class="form-control form-control-sm" required maxlength="120">
          </div>
          <div class="mb-2 row g-2">
            <div class="col">
              <label class="form-label small fw-semibold">Kolor paska</label>
              <input type="color" name="lcolor" id="lm-color" class="form-control form-control-sm form-control-color" value="#e2e8f0">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">Limit WIP</label>
              <input type="number" name="wip_limit" id="lm-wip" class="form-control form-control-sm"
                     min="0" placeholder="Brak">
            </div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_done_state" value="1" id="lm-done">
            <label class="form-check-label small" for="lm-done">
              Kolumna „ukończone" (przenoszone karty otrzymują datę ukończenia)
            </label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-check2 me-1"></i>Zapisz
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
// ── Ikona podglądu ─────────────────────────────────────────────────────
const iconInput   = document.getElementById('ws-icon-input');
const iconPreview = document.getElementById('ws-icon-preview');
if (iconInput && iconPreview) {
    iconInput.addEventListener('input', function() {
        iconPreview.className = 'bi bi-' + this.value.trim();
    });
}

// ── Modal kolumny ──────────────────────────────────────────────────────
function openListModal(listId, wsId, name='', color='#e2e8f0', done=0, wip=null) {
    document.getElementById('lm-ws-id').value   = wsId;
    document.getElementById('lm-list-id').value = listId;
    document.getElementById('lm-name').value    = name;
    document.getElementById('lm-color').value   = color || '#e2e8f0';
    document.getElementById('lm-done').checked  = !!done;
    document.getElementById('lm-wip').value     = wip || '';
    document.getElementById('lm-title').textContent = listId ? 'Edytuj kolumnę' : 'Nowa kolumna';
    new bootstrap.Modal(document.getElementById('listModal')).show();
}

function confirmDelete(taskCount) {
    if (taskCount > 0) { alert('Kolumna zawiera ' + taskCount + ' zadań. Przenieś je przed usunięciem.'); return false; }
    return confirm('Usunąć tę kolumnę?');
}

// ── Sortowanie list drag & drop ─────────────────────────────────────────
const listSortable = document.getElementById('lists-sortable');
if (listSortable) {
    Sortable.create(listSortable, {
        animation: 150,
        handle: '.bi-grip-vertical',
        onEnd: function() {
            const ids = Array.from(listSortable.querySelectorAll('[data-list-id]'))
                .map(el => el.dataset.listId).join(',');
            document.getElementById('lists-order-input').value = ids;
            document.getElementById('save-order-btn').style.display = '';
        }
    });
}
</script>

<!-- ── Modal: Usuń obszar ──────────────────────────────────────────────── -->
<div class="modal fade" id="deleteWsModal" tabindex="-1"
     aria-labelledby="deleteWsModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-sm">
    <div class="modal-content border-danger border-opacity-50">
      <form method="post">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"  value="delete_workspace">
        <input type="hidden" name="ws_id"    id="del-ws-id"    value="">
        <input type="hidden" name="force_delete" value="1">

        <div class="modal-header py-2 bg-danger bg-opacity-10">
          <h6 class="modal-title fw-bold text-danger" id="deleteWsModalLabel">
            <i class="bi bi-trash me-1"></i>Usuń obszar roboczy
          </h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"
                  aria-label="Anuluj"></button>
        </div>

        <div class="modal-body">
          <p class="mb-2 small">
            Usuwasz obszar: <strong id="del-ws-name"></strong>
          </p>
          <div id="del-ws-task-warn" class="alert alert-warning small py-2 mb-2 d-none">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Ten obszar zawiera <strong id="del-ws-task-count"></strong> zadań.
            Wszystkie zostaną trwale usunięte.
          </div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox"
                   id="del-confirm-chk" required
                   oninvalid="this.setCustomValidity('Zaznacz potwierdzenie')">
            <label class="form-check-label small fw-semibold text-danger" for="del-confirm-chk">
              Rozumiem, usuń obszar i wszystkie jego zadania bezpowrotnie
            </label>
          </div>
        </div>

        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary"
                  data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-danger">
            <i class="bi bi-trash me-1"></i>Usuń bezpowrotnie
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function prepareDeleteWs(btn) {
    const name  = btn.dataset.wsName  || '';
    const wsId  = btn.dataset.wsId    || '';
    const tasks = parseInt(btn.dataset.taskCount) || 0;

    document.getElementById('del-ws-id').value   = wsId;
    document.getElementById('del-ws-name').textContent = name;
    document.getElementById('del-confirm-chk').checked = false;

    const warn = document.getElementById('del-ws-task-warn');
    const cnt  = document.getElementById('del-ws-task-count');
    if (tasks > 0) {
        cnt.textContent = tasks;
        warn.classList.remove('d-none');
    } else {
        warn.classList.add('d-none');
    }
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
