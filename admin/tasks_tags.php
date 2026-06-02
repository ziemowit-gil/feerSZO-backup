<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';

require_role('admin');
$uid = (int)(current_user()['id'] ?? 0);

// ── Obsługa POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'save_tag') {
        $tag_id      = (int)($_POST['tag_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $color       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#64748b';
        $text_color  = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['text_color'] ?? '') ? $_POST['text_color'] : '#ffffff';
        $ws_id       = trim($_POST['workspace_id'] ?? '') !== '' ? (int)$_POST['workspace_id'] : null;

        if (!$name) { flash_set('danger', 'Nazwa tagu jest wymagana.'); goto redirect; }

        if ($tag_id) {
            db()->prepare(
                "UPDATE task_tags SET name=?, color=?, text_color=?, workspace_id=? WHERE id=?"
            )->execute([$name, $color, $text_color, $ws_id, $tag_id]);
            flash_set('success', 'Tag zaktualizowany.');
        } else {
            db_insert('task_tags', [
                'workspace_id' => $ws_id,
                'name'         => $name,
                'color'        => $color,
                'text_color'   => $text_color,
                'is_active'    => 1,
                'created_by'   => $uid,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', 'Tag „' . $name . '" dodany.');
        }
        goto redirect;
    }

    if ($act === 'toggle_tag') {
        $tag_id = (int)($_POST['tag_id'] ?? 0);
        $tag    = db_one("SELECT * FROM task_tags WHERE id=?", [$tag_id]);
        if ($tag) {
            db()->prepare("UPDATE task_tags SET is_active=? WHERE id=?")
                ->execute([$tag['is_active'] ? 0 : 1, $tag_id]);
        }
        flash_set('success', 'Status tagu zmieniony.');
        goto redirect;
    }

    redirect:
    header('Location: ' . APP_URL . '/admin/tasks_tags.php');
    exit;
}

// ── Dane ──────────────────────────────────────────────────────────────────
$tags = db_all(
    "SELECT tt.*, tw.name AS workspace_name, u.name AS creator_name,
            COUNT(ttt.task_id) AS usage_count
     FROM task_tags tt
     LEFT JOIN task_workspaces tw ON tw.id = tt.workspace_id
     LEFT JOIN users u ON u.id = tt.created_by
     LEFT JOIN task_task_tags ttt ON ttt.tag_id = tt.id
     GROUP BY tt.id
     ORDER BY tt.workspace_id IS NOT NULL, tt.name"
);

$workspaces = db_all("SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name");

$PAGE_TITLE = 'Tagi — Zadania';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-tags text-primary me-2"></i>Słownik tagów
  </h5>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal"
          onclick="openTagModal(0)">
    <i class="bi bi-plus-lg me-1"></i>Nowy tag
  </button>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:180px">Tag</th>
          <th>Zakres</th>
          <th class="text-center" style="width:80px">Użycia</th>
          <th class="text-center" style="width:80px">Status</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tags): ?>
      <tr><td colspan="5" class="text-muted small ps-3 py-4 text-center">
        Brak tagów. Dodaj pierwszy tag, aby móc oznaczać zadania.
      </td></tr>
      <?php endif; ?>
      <?php foreach ($tags as $tag): ?>
      <tr>
        <td class="ps-3">
          <span class="badge px-2 py-1" style="background:<?= h($tag['color']) ?>;color:<?= h($tag['text_color']) ?>;font-size:.78rem">
            <?= h($tag['name']) ?>
          </span>
        </td>
        <td class="small text-muted">
          <?php if ($tag['workspace_id']): ?>
          <i class="bi bi-kanban me-1"></i><?= h($tag['workspace_name']) ?>
          <?php else: ?>
          <i class="bi bi-globe2 me-1"></i>Globalny
          <?php endif; ?>
        </td>
        <td class="text-center">
          <span class="badge bg-secondary bg-opacity-25 text-secondary"><?= (int)$tag['usage_count'] ?></span>
        </td>
        <td class="text-center">
          <span class="badge <?= $tag['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
            <?= $tag['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
          </span>
        </td>
        <td class="text-end pe-3">
          <div class="d-flex gap-1 justify-content-end">
            <button class="btn btn-xs btn-outline-secondary"
                    onclick="openTagModal(<?= $tag['id'] ?>, '<?= h(addslashes($tag['name'])) ?>',
                             '<?= h($tag['color']) ?>','<?= h($tag['text_color']) ?>',
                             <?= $tag['workspace_id'] ?: 'null' ?>)"
                    style="padding:.2rem .5rem;font-size:.72rem">
              <i class="bi bi-pencil"></i>
            </button>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"  value="toggle_tag">
              <input type="hidden" name="tag_id"   value="<?= $tag['id'] ?>">
              <button class="btn btn-xs <?= $tag['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      style="padding:.2rem .5rem;font-size:.72rem">
                <i class="bi bi-<?= $tag['is_active'] ? 'pause' : 'play' ?>"></i>
              </button>
            </form>
            <?= delete_btn('task_tags', (int)$tag['id'], $tag['name'] ?? '#'.$tag['id']) ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal ──────────────────────────────────────────────────────────── -->
<div class="modal fade" id="tagModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"  value="save_tag">
        <input type="hidden" name="tag_id"   id="tm-tag-id" value="0">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold" id="tm-title">Tag</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa *</label>
            <input type="text" name="name" id="tm-name" class="form-control form-control-sm"
                   required maxlength="60" placeholder="np. Pilne, Bug, Feature...">
          </div>
          <div class="mb-2 row g-2">
            <div class="col">
              <label class="form-label small fw-semibold">Kolor tła</label>
              <input type="color" name="color" id="tm-color"
                     class="form-control form-control-sm form-control-color" value="#2563eb">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">Kolor tekstu</label>
              <input type="color" name="text_color" id="tm-text-color"
                     class="form-control form-control-sm form-control-color" value="#ffffff">
            </div>
          </div>
          <div class="mb-2">
            <div class="oc-label small text-muted fw-semibold mb-1">Podgląd</div>
            <span id="tm-preview" class="badge px-2 py-1" style="font-size:.82rem">Podgląd</span>
          </div>
          <div class="mb-0">
            <label class="form-label small fw-semibold">Zakres</label>
            <select name="workspace_id" id="tm-ws" class="form-select form-select-sm">
              <option value="">Globalny (wszystkie obszary)</option>
              <?php foreach ($workspaces as $ws): ?>
              <option value="<?= $ws['id'] ?>"><?= h($ws['name']) ?></option>
              <?php endforeach; ?>
            </select>
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

<script>
function openTagModal(id, name='', color='#2563eb', textColor='#ffffff', wsId=null) {
    document.getElementById('tm-tag-id').value  = id;
    document.getElementById('tm-name').value    = name;
    document.getElementById('tm-color').value   = color;
    document.getElementById('tm-text-color').value = textColor;
    document.getElementById('tm-ws').value      = wsId || '';
    document.getElementById('tm-title').textContent = id ? 'Edytuj tag' : 'Nowy tag';
    updatePreview();
}

function updatePreview() {
    const pr = document.getElementById('tm-preview');
    const nm = document.getElementById('tm-name');
    pr.style.background = document.getElementById('tm-color').value;
    pr.style.color      = document.getElementById('tm-text-color').value;
    pr.textContent      = nm.value || 'Podgląd';
}

['tm-color','tm-text-color','tm-name'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updatePreview);
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
