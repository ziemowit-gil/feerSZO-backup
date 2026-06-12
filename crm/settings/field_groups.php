<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) { flash_set('danger','Brak uprawnień do ustawień CRM.'); header('Location: '.APP_URL.'/crm/dashboard.php'); exit; }
crm_migrate();

$PAGE_TITLE = 'CRM — Grupy pól';

$APPLIES_OPTIONS = ['both' => 'Wszystkie', 'osoba' => 'Tylko osoby', 'organizacja' => 'Tylko firmy'];
$ICON_SUGGESTIONS = ['bi-card-list','bi-person-vcard','bi-building','bi-geo-alt','bi-telephone','bi-envelope','bi-cash-coin','bi-briefcase','bi-gear','bi-info-circle','bi-map','bi-tags'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if (!$label) { flash_set('danger','Nazwa grupy jest wymagana.'); goto redirect; }
        $norm = function(array $v): string {
            $v = array_values(array_filter($v, fn($r) => is_string($r) && $r !== ''));
            return $v ? json_encode($v, JSON_UNESCAPED_UNICODE) : '';
        };
        $data = [
            'label'         => $label,
            'icon'          => trim($_POST['icon'] ?? 'bi-card-list'),
            'applies_to'    => in_array($_POST['applies_to']??'', array_keys($APPLIES_OPTIONS)) ? $_POST['applies_to'] : 'both',
            'sort_order'    => (int)($_POST['sort_order'] ?? 0),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            'visible_roles' => $norm((array)($_POST['visible_roles'] ?? [])),
            'edit_roles'    => $norm((array)($_POST['edit_roles'] ?? [])),
        ];
        if ($id) {
            crm_update('crm_field_groups', $data, $id);
            flash_set('success','Grupa zaktualizowana.');
        } else {
            $data['is_active'] = 1;
            crm_insert('crm_field_groups', $data);
            flash_set('success', "Grupa \"{$label}\" dodana.");
        }
    } elseif ($op === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = crm_one("SELECT is_active FROM crm_field_groups WHERE id=?",[$id]);
        if ($cur) crm_db()->prepare("UPDATE crm_field_groups SET is_active=? WHERE id=?")->execute([$cur['is_active']?0:1,$id]);
    } elseif ($op === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $used = (int)(crm_one("SELECT COUNT(*) AS c FROM crm_contact_field_defs WHERE group_id=?",[$id])['c'] ?? 0);
        if ($used > 0) { flash_set('danger',"Nie można usunąć — {$used} pól należy do tej grupy. Najpierw odepnij pola."); goto redirect; }
        crm_db()->prepare("DELETE FROM crm_field_groups WHERE id=?")->execute([$id]);
        flash_set('success','Grupa usunięta.');
    } elseif ($op === 'reorder') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        foreach ($ids as $i => $gid) {
            crm_db()->prepare("UPDATE crm_field_groups SET sort_order=? WHERE id=?")->execute([$i, $gid]);
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
    }
    redirect:
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$groups    = crm_all("SELECT g.*, (SELECT COUNT(*) FROM crm_contact_field_defs f WHERE f.group_id=g.id) AS field_count FROM crm_field_groups g ORDER BY sort_order, id");
$edit_id   = (int)($_GET['edit'] ?? 0);
$edit      = $edit_id ? crm_one("SELECT * FROM crm_field_groups WHERE id=?",[$edit_id]) : null;
$all_roles = crm_all_roles();

include __DIR__ . '/../includes/header_crm.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Grupy pól</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-layers-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Grupy pól kontaktu</h1>
    <div class="crm-object-count">Sekcje formularza — grupuj pola niestandardowe w logiczne bloki</div>
  </div>
  <div class="crm-object-actions d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/crm/settings/fields.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-list-columns me-1"></i>Pola niestandardowe
    </a>
    <a href="<?= APP_URL ?>/crm/settings/fields_system.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-database-lock me-1"></i>Pola systemowe
    </a>
    <button class="btn btn-crm-primary btn-sm" onclick="document.getElementById('add-form').classList.toggle('d-none')">
      <i class="bi bi-plus-lg me-1"></i>Nowa grupa
    </button>
  </div>
</div>

<?= flash_get() ?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white py-2 small fw-semibold text-muted">
    Grupy służą jako sekcje w formularzu kontaktu. Każde pole niestandardowe można przypisać do grupy.
  </div>
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:30px"></th>
          <th>Ikona</th>
          <th>Nazwa grupy</th>
          <th>Dotyczy</th>
          <th>Pól</th>
          <th class="d-none d-lg-table-cell">Uprawnienia</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="groups-sortable">
        <?php foreach ($groups as $g):
          $g_vis  = json_decode($g['visible_roles'] ?? '', true) ?: [];
          $g_edit = json_decode($g['edit_roles']    ?? '', true) ?: [];
        ?>
        <tr data-id="<?= $g['id'] ?>">
          <td class="text-muted" style="cursor:grab"><i class="bi bi-grip-vertical"></i></td>
          <td><i class="bi <?= h($g['icon']) ?>" style="font-size:1.1rem;color:var(--crm-primary)"></i></td>
          <td class="fw-semibold"><?= h($g['label']) ?></td>
          <td class="text-muted small"><?= h($APPLIES_OPTIONS[$g['applies_to']] ?? $g['applies_to']) ?></td>
          <td>
            <a href="<?= APP_URL ?>/crm/settings/fields.php?group=<?= $g['id'] ?>" class="badge bg-light text-dark border text-decoration-none">
              <?= $g['field_count'] ?> pól →
            </a>
          </td>
          <td class="d-none d-lg-table-cell" style="min-width:140px">
            <?php if ($g_vis): ?>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1" title="Kto widzi">
                <i class="bi bi-eye me-1"></i><?= implode(', ', array_map('h', $g_vis)) ?>
              </span>
            <?php endif; ?>
            <?php if ($g_edit): ?>
              <span class="badge bg-warning-subtle text-warning-emphasis border me-1" title="Kto edytuje">
                <i class="bi bi-pencil me-1"></i><?= implode(', ', array_map('h', $g_edit)) ?>
              </span>
            <?php endif; ?>
            <?php if (!$g_vis && !$g_edit): ?>
              <span class="text-muted small">wszyscy</span>
            <?php endif; ?>
          </td>
          <td>
            <?= $g['is_active']
              ? '<span class="badge bg-success-subtle text-success border border-success-subtle">aktywna</span>'
              : '<span class="badge bg-secondary-subtle text-secondary border">nieaktywna</span>' ?>
          </td>
          <td class="d-flex gap-1">
            <a href="?edit=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= $g['id'] ?>">
              <button class="btn btn-sm <?= $g['is_active']?'btn-outline-warning':'btn-outline-success' ?> py-0 px-2">
                <?= $g['is_active']?'<i class="bi bi-pause"></i>':'<i class="bi bi-play"></i>' ?>
              </button>
            </form>
            <?php if ((int)$g['field_count'] === 0): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= $g['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2"
                      data-confirm="Usunąć grupę "<?= addslashes(h($g['label'])) ?>"?">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$groups): ?>
        <tr><td colspan="8" class="text-muted text-center py-3 small">Brak grup — dodaj pierwszą poniżej.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Formularz -->
<div id="add-form" class="card border-0 shadow-sm mb-3 <?= $edit ? '' : 'd-none' ?>">
  <div class="card-header bg-white py-2 fw-semibold"><?= $edit ? 'Edytuj grupę' : 'Nowa grupa pól' ?></div>
  <div class="card-body">
    <form method="post" class="row g-3 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
      <div class="col-md-4">
        <label class="form-label small mb-1">Nazwa grupy <span class="text-danger">*</span></label>
        <input type="text" name="label" class="form-control form-control-sm" required
               value="<?= h($edit['label'] ?? '') ?>" placeholder="np. Dane dodatkowe">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Ikona Bootstrap</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi <?= h($edit['icon'] ?? 'bi-card-list') ?>" id="iconPreview"></i></span>
          <input type="text" name="icon" id="iconInput" class="form-control font-monospace"
                 value="<?= h($edit['icon'] ?? 'bi-card-list') ?>"
                 oninput="document.getElementById('iconPreview').className='bi '+this.value">
        </div>
        <div class="mt-1 d-flex flex-wrap gap-1">
          <?php foreach ($ICON_SUGGESTIONS as $ic): ?>
          <button type="button" class="btn btn-outline-secondary py-0 px-1"
                  style="font-size:.75rem" title="<?= h($ic) ?>"
                  onclick="document.getElementById('iconInput').value='<?= $ic ?>'; document.getElementById('iconPreview').className='bi <?= $ic ?>'">
            <i class="bi <?= $ic ?>"></i>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Dotyczy</label>
        <select name="applies_to" class="form-select form-select-sm">
          <?php foreach ($APPLIES_OPTIONS as $v => $l): ?>
          <option value="<?= h($v) ?>" <?= ($edit['applies_to']??'both')===$v?'selected':'' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1">Kolejność</label>
        <input type="number" name="sort_order" class="form-control form-control-sm"
               value="<?= $edit['sort_order'] ?? count($groups) + 1 ?>">
      </div>
      <?php if ($edit): ?>
      <div class="col-auto d-flex align-items-center">
        <div class="form-check mb-0">
          <input type="checkbox" name="is_active" class="form-check-input" id="chkGrpActive"
                 value="1" <?= $edit['is_active']?'checked':'' ?>>
          <label class="form-check-label small" for="chkGrpActive">Aktywna</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <hr class="my-2">
        <?php
          $eg_vis  = $edit ? json_decode($edit['visible_roles'] ?? '', true) ?: [] : [];
          $eg_edit = $edit ? json_decode($edit['edit_roles']    ?? '', true) ?: [] : [];
        ?>
        <div class="small fw-semibold mb-1 d-flex align-items-center gap-1">
          <i class="bi bi-shield-lock text-primary"></i> Uprawnienia grupy
          <span class="text-muted fw-normal">(puste = wszyscy)</span>
        </div>
        <div class="row g-2">
          <div class="col-sm-6">
            <p class="small mb-1"><i class="bi bi-eye text-secondary me-1"></i>Kto może <u>widzieć</u> pola tej grupy?</p>
            <div class="border rounded p-2 d-flex flex-wrap gap-2" style="background:#FAFAFA">
              <?php foreach ($all_roles as $rname => $rlabel): ?>
              <div class="form-check mb-0">
                <input type="checkbox" class="form-check-input" id="gvis_<?= h($rname) ?>"
                       name="visible_roles[]" value="<?= h($rname) ?>"
                       <?= in_array($rname, $eg_vis, true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="gvis_<?= h($rname) ?>"><?= h($rlabel) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-sm-6">
            <p class="small mb-1"><i class="bi bi-pencil-square text-secondary me-1"></i>Kto może <u>edytować</u> pola tej grupy?</p>
            <div class="border rounded p-2 d-flex flex-wrap gap-2" style="background:#FAFAFA">
              <?php foreach ($all_roles as $rname => $rlabel): ?>
              <div class="form-check mb-0">
                <input type="checkbox" class="form-check-input" id="gedit_<?= h($rname) ?>"
                       name="edit_roles[]" value="<?= h($rname) ?>"
                       <?= in_array($rname, $eg_edit, true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="gedit_<?= h($rname) ?>"><?= h($rlabel) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-crm-primary btn-sm">
          <?= $edit ? '<i class="bi bi-save me-1"></i>Zapisz' : '<i class="bi bi-plus-lg me-1"></i>Dodaj' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/settings/field_groups.php" class="btn btn-outline-secondary btn-sm ms-1">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  const tbody = document.getElementById('groups-sortable');
  if (!tbody) return;
  let drag = null;
  tbody.querySelectorAll('tr[data-id]').forEach(tr => {
    tr.draggable = true;
    tr.addEventListener('dragstart', () => { drag = tr; tr.style.opacity='.4'; });
    tr.addEventListener('dragend',   () => { drag=null; tr.style.opacity=''; });
    tr.addEventListener('dragover',  e => { e.preventDefault(); const r=tr.getBoundingClientRect(); tr.style.borderTop = e.clientY<r.top+r.height/2?'2px solid var(--crm-primary)':''; tr.style.borderBottom=e.clientY>=r.top+r.height/2?'2px solid var(--crm-primary)':''; });
    tr.addEventListener('dragleave', () => { tr.style.borderTop=tr.style.borderBottom=''; });
    tr.addEventListener('drop', e => {
      e.preventDefault(); tr.style.borderTop=tr.style.borderBottom='';
      if (!drag||drag===tr) return;
      const r=tr.getBoundingClientRect();
      tbody.insertBefore(drag, e.clientY<r.top+r.height/2?tr:tr.nextSibling);
      const ids=[...tbody.querySelectorAll('tr[data-id]')].map(t=>t.dataset.id);
      const fd=new FormData(); fd.append('_op','reorder'); fd.append('_csrf','<?= csrf_token() ?>');
      ids.forEach(id=>fd.append('ids[]',id));
      fetch('',{method:'POST',body:fd});
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
