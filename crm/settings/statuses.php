<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!is_admin()) { flash_set('danger','Tylko administrator.'); header('Location: '.APP_URL.'/crm/dashboard.php'); exit; }
crm_migrate();

$PAGE_TITLE = 'CRM — Statusy kontaktów';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $slug  = preg_replace('/[^a-z0-9_\-]/u', '', mb_strtolower(trim($_POST['slug'] ?? '')));
        $label = trim($_POST['label'] ?? '');
        $color = trim($_POST['color'] ?? '#6B7280');
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if (!$slug || !$label) { flash_set('danger','Slug i etykieta są wymagane.'); goto redirect; }

        if ($id) {
            crm_db()->prepare("UPDATE crm_statuses SET label=?,color=?,sort_order=?,is_active=? WHERE id=?")
                ->execute([$label, $color, $sort, isset($_POST['is_active'])?1:0, $id]);
            flash_set('success','Status zaktualizowany.');
        } else {
            try {
                crm_db()->prepare("INSERT INTO crm_statuses (slug,label,color,sort_order,is_active) VALUES (?,?,?,?,1)")
                    ->execute([$slug, $label, $color, $sort]);
                flash_set('success', "Status \"{$label}\" dodany.");
            } catch (\Throwable $e) {
                flash_set('danger','Slug już istnieje — wybierz inny.');
            }
        }
    } elseif ($op === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = crm_one("SELECT is_active FROM crm_statuses WHERE id=?",[$id]);
        if ($cur) crm_db()->prepare("UPDATE crm_statuses SET is_active=? WHERE id=?")->execute([$cur['is_active']?0:1,$id]);
    } elseif ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)(crm_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE status=(SELECT slug FROM crm_statuses WHERE id=?)",[$id])['c'] ?? 0);
        if ($used > 0) { flash_set('danger',"Nie można usunąć — {$used} kontaktów używa tego statusu."); goto redirect; }
        crm_db()->prepare("DELETE FROM crm_statuses WHERE id=?")->execute([$id]);
        flash_set('success','Status usunięty.');
    } elseif ($op === 'reorder') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        foreach ($ids as $i => $sid) {
            crm_db()->prepare("UPDATE crm_statuses SET sort_order=? WHERE id=?")->execute([$i, $sid]);
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
    }
    redirect:
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$statuses = crm_all("SELECT * FROM crm_statuses ORDER BY sort_order, id");
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? crm_one("SELECT * FROM crm_statuses WHERE id=?",[$edit_id]) : null;

include __DIR__ . '/../includes/header_crm.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Statusy</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-bookmark-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Statusy kontaktów</h1>
    <div class="crm-object-count">Etapy lejka CRM — edytuj etykiety, kolory i kolejność</div>
  </div>
  <div class="crm-object-actions">
    <button class="btn btn-crm-primary btn-sm" onclick="document.getElementById('add-form').classList.toggle('d-none')">
      <i class="bi bi-plus-lg me-1"></i>Dodaj status
    </button>
  </div>
</div>

<?= flash_get() ?>

<!-- Lista statusów (przeciągana) -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white py-2 small fw-semibold text-muted">
    Przeciągnij wiersze, aby zmienić kolejność. Zmiany są zapisywane automatycznie.
  </div>
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:30px"></th>
          <th>Kolor</th>
          <th>Slug</th>
          <th>Etykieta</th>
          <th>Status</th>
          <th>Kontaktów</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="status-sortable">
        <?php foreach ($statuses as $s):
          $cnt = (int)(crm_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE status=?",[$s['slug']])['c'] ?? 0);
        ?>
        <tr data-id="<?= $s['id'] ?>">
          <td class="drag-handle text-muted" style="cursor:grab"><i class="bi bi-grip-vertical"></i></td>
          <td>
            <span class="badge" style="background:<?= h($s['color']) ?>;font-size:.75rem;padding:.35em .75em">
              <?= h($s['label']) ?>
            </span>
          </td>
          <td class="font-monospace small text-muted"><?= h($s['slug']) ?></td>
          <td class="fw-semibold"><?= h($s['label']) ?></td>
          <td>
            <?php if ($s['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">aktywny</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">nieaktywny</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-light text-dark border"><?= $cnt ?></span></td>
          <td class="d-flex gap-1">
            <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm <?= $s['is_active']?'btn-outline-warning':'btn-outline-success' ?> py-0 px-2">
                <?= $s['is_active']?'<i class="bi bi-pause"></i>':'<i class="bi bi-play"></i>' ?>
              </button>
            </form>
            <?php if ($cnt === 0): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2"
                      data-confirm="Usunąć status "<?= addslashes(h($s['label'])) ?>"?">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Formularz dodawania -->
<div id="add-form" class="card border-0 shadow-sm mb-3 <?= $edit_row ? '' : 'd-none' ?>">
  <div class="card-header bg-white py-2 fw-semibold"><?= $edit_row ? 'Edytuj status' : 'Nowy status' ?></div>
  <div class="card-body">
    <form method="post" class="row g-3 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= $edit_row['id'] ?>"><?php endif; ?>
      <div class="col-md-2">
        <label class="form-label small mb-1">Slug <span class="text-danger">*</span></label>
        <input type="text" name="slug" class="form-control form-control-sm font-monospace"
               value="<?= h($edit_row['slug'] ?? '') ?>" placeholder="np. vip"
               <?= $edit_row ? 'readonly' : 'required' ?>>
        <div class="form-text">Niezmienialny klucz</div>
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1">Etykieta <span class="text-danger">*</span></label>
        <input type="text" name="label" class="form-control form-control-sm" required
               value="<?= h($edit_row['label'] ?? '') ?>" placeholder="np. VIP">
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1">Kolor</label>
        <input type="color" name="color" class="form-control form-control-sm form-control-color"
               value="<?= h($edit_row['color'] ?? '#0176D3') ?>">
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1">Kolejność</label>
        <input type="number" name="sort_order" class="form-control form-control-sm"
               value="<?= $edit_row['sort_order'] ?? count($statuses) + 1 ?>">
      </div>
      <?php if ($edit_row): ?>
      <div class="col-auto d-flex align-items-center">
        <div class="form-check mb-0">
          <input type="checkbox" name="is_active" class="form-check-input" id="chkActive"
                 value="1" <?= $edit_row['is_active']?'checked':'' ?>>
          <label class="form-check-label small" for="chkActive">Aktywny</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-auto">
        <button type="submit" class="btn btn-crm-primary btn-sm">
          <?= $edit_row ? '<i class="bi bi-save me-1"></i>Zapisz' : '<i class="bi bi-plus-lg me-1"></i>Dodaj' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/settings/statuses.php" class="btn btn-outline-secondary btn-sm ms-1">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<script>
// Drag & drop reorder
(function() {
  const tbody = document.getElementById('status-sortable');
  if (!tbody) return;
  let drag = null;

  tbody.querySelectorAll('tr').forEach(tr => {
    tr.draggable = true;
    tr.addEventListener('dragstart', e => { drag = tr; tr.style.opacity = '.4'; });
    tr.addEventListener('dragend',   e => { drag = null; tr.style.opacity = ''; });
    tr.addEventListener('dragover',  e => { e.preventDefault(); const r = tr.getBoundingClientRect(); tr.style.borderTop = e.clientY < r.top + r.height/2 ? '2px solid var(--crm-primary)' : ''; tr.style.borderBottom = e.clientY >= r.top + r.height/2 ? '2px solid var(--crm-primary)' : ''; });
    tr.addEventListener('dragleave', e => { tr.style.borderTop = tr.style.borderBottom = ''; });
    tr.addEventListener('drop', e => {
      e.preventDefault();
      tr.style.borderTop = tr.style.borderBottom = '';
      if (!drag || drag === tr) return;
      const r = tr.getBoundingClientRect();
      tbody.insertBefore(drag, e.clientY < r.top + r.height/2 ? tr : tr.nextSibling);
      saveOrder();
    });
  });

  function saveOrder() {
    const ids = [...tbody.querySelectorAll('tr[data-id]')].map(tr => tr.dataset.id);
    const fd = new FormData();
    fd.append('_op', 'reorder');
    fd.append('_csrf', '<?= csrf_token() ?>');
    ids.forEach(id => fd.append('ids[]', id));
    fetch('', { method: 'POST', body: fd });
  }
})();
</script>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
