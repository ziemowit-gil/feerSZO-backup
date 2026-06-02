<?php
/**
 * crm/groups.php — Lista grup kontaktów CRM z obsługą podgrup.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$crm_can_write  = can_write('crm') || is_admin();
$crm_can_delete = can_delete('crm') || is_admin();
$PAGE_TITLE = 'CRM — Grupy kontaktów';

const GROUP_COLORS = [
    '#2E844A' => 'Zielony',   '#0176D3' => 'Niebieski', '#7F2B8B' => 'Fioletowy',
    '#FE9339' => 'Pomarańczowy','#032D60'=> 'Granatowy', '#E31010' => 'Czerwony',
    '#64748b' => 'Szary',     '#0D9488' => 'Morski',    '#DC2626' => 'Karmazynowy',
    '#D97706' => 'Złoty',
];
const GROUP_ICONS = [
    'bi-collection-fill' => 'Kolekcja', 'bi-people-fill'    => 'Osoby',
    'bi-building-fill'   => 'Budynek',  'bi-star-fill'      => 'Gwiazdka',
    'bi-heart-fill'      => 'Serce',    'bi-briefcase-fill' => 'Teczka',
    'bi-mortarboard-fill'=> 'Edukacja', 'bi-megaphone-fill' => 'Komunikacja',
    'bi-cash-coin'       => 'Finanse',  'bi-globe2'         => 'Globus',
    'bi-lightning-charge-fill' => 'Działanie', 'bi-diagram-3-fill' => 'Struktura',
];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            flash_set('danger', 'Nazwa grupy jest wymagana.');
        } elseif (db_one("SELECT id FROM crm_groups WHERE name=?", [$name])) {
            flash_set('danger', 'Grupa o tej nazwie już istnieje.');
        } else {
            $parent_id = ((int)($_POST['parent_id'] ?? 0)) ?: null;
            $gid = db_insert('crm_groups', [
                'name'        => $name,
                'description' => trim($_POST['description'] ?? '') ?: null,
                'color'       => $_POST['color'] ?? '#2E844A',
                'icon'        => $_POST['icon']  ?? 'bi-collection-fill',
                'parent_id'   => $parent_id,
                'created_by'  => (int)(current_user()['id'] ?? 0),
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', 'Grupa "' . $name . '" utworzona.');
            header('Location: ' . APP_URL . '/crm/group/view.php?id=' . $gid);
            exit;
        }
    }

    if ($action === 'update') {
        $gid  = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($gid && $name) {
            $parent_id = ((int)($_POST['parent_id'] ?? 0)) ?: null;
            if ($parent_id === $gid) $parent_id = null; // nie możesz być własną podgrupą
            db()->prepare(
                "UPDATE crm_groups SET name=?,description=?,color=?,icon=?,parent_id=?,updated_at=? WHERE id=?"
            )->execute([
                $name,
                trim($_POST['description'] ?? '') ?: null,
                $_POST['color'] ?? '#2E844A',
                $_POST['icon']  ?? 'bi-collection-fill',
                $parent_id,
                date('Y-m-d H:i:s'),
                $gid,
            ]);
            flash_set('success', 'Grupa zaktualizowana.');
        }
    }

    if ($action === 'delete' && $crm_can_delete) {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid) {
            // Przesuń podgrupy na poziom wyżej (orphan)
            db()->prepare("UPDATE crm_groups SET parent_id=NULL WHERE parent_id=?")->execute([$gid]);
            $g = db_one("SELECT name FROM crm_groups WHERE id=?", [$gid]);
            CrmManager::deleteGroup($gid);
            flash_set('success', 'Grupa "' . ($g['name'] ?? '') . '" usunięta.');
        }
    }

    header('Location: ' . APP_URL . '/crm/groups.php');
    exit;
}

// ── Pobierz grupy hierarchicznie ──────────────────────────────────────────────
$all_groups = db_all(
    "SELECT g.*,
            (SELECT COUNT(*) FROM crm_group_members gm WHERE gm.group_id=g.id) AS member_count,
            (SELECT COUNT(*) FROM crm_groups sg WHERE sg.parent_id=g.id) AS subgroup_count,
            (SELECT GROUP_CONCAT(gt.tag,',') FROM crm_group_tags gt WHERE gt.group_id=g.id) AS tags_csv
     FROM crm_groups g ORDER BY g.parent_id IS NOT NULL, g.parent_id, g.sort_order, g.name"
);

// Buduj drzewo: root → children
$roots    = [];
$children = [];
foreach ($all_groups as $g) {
    if ($g['parent_id']) {
        $children[$g['parent_id']][] = $g;
    } else {
        $roots[] = $g;
    }
}

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.gc { background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:1rem 1.1rem;display:flex;align-items:center;gap:.85rem;transition:box-shadow .15s,border-color .15s;border-left:4px solid var(--gc-color,#E5E7EB) }
.gc:hover { box-shadow:0 4px 12px rgba(0,0,0,.09);border-top-color:#D1D5DB;border-right-color:#D1D5DB;border-bottom-color:#D1D5DB }
.gc-icon { width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:#fff;flex-shrink:0 }
.gc-name { font-weight:700;font-size:.93rem;color:#111827;text-decoration:none }
.gc-name:hover { color:var(--crm-primary) }
.gc-sub { font-size:.75rem;color:#9CA3AF;margin-top:1px }
.gc-stat-val { font-size:1.3rem;font-weight:800;line-height:1 }
.gc-stat-lbl { font-size:.68rem;color:#9CA3AF }
.gc-tag { display:inline-flex;align-items:center;gap:.2rem;padding:.1rem .45rem;border-radius:2rem;background:#F3F4F6;color:#6B7280;font-size:.68rem;font-weight:500 }
.gc-actions { display:flex;flex-direction:column;gap:.2rem;flex-shrink:0;opacity:0;transition:opacity .15s }
.gc:hover .gc-actions { opacity:1 }
/* Podgrupy — indent */
.subgroup-block { margin-top:.5rem;padding-left:1.5rem;border-left:2px solid #F3F4F6;margin-left:22px }
.subgroup-block .gc { border-radius:8px;padding:.75rem 1rem }
.subgroup-block .gc-icon { width:36px;height:36px;font-size:1rem;border-radius:8px }
.subgroup-block .gc-stat-val { font-size:1.1rem }
/* Kolorki / ikony picker */
.color-swatch { width:26px;height:26px;border-radius:50%;border:2px solid transparent;cursor:pointer;transition:border-color .12s,transform .12s }
.color-swatch:hover,.color-swatch.selected { border-color:#0f172a;transform:scale(1.15) }
.icon-btn { width:34px;height:34px;border-radius:7px;border:2px solid transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.05rem;background:#F1F5F9;transition:border-color .12s,background .12s }
.icon-btn:hover,.icon-btn.selected { border-color:#2E844A;background:#EFF7ED }
</style>

<div class="crm-page-header mb-4">
  <div>
    <div class="crm-page-title"><i class="bi bi-collection-fill" style="color:var(--crm-primary)"></i> Grupy kontaktów</div>
    <div class="crm-page-subtitle"><?= count($all_groups) ?> grup · hierarchia i podgrupy</div>
  </div>
  <div class="crm-page-actions">
    <?php if ($crm_can_write): ?>
    <button class="btn btn-crm-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createGroupModal">
      <i class="bi bi-plus-lg me-1"></i>Nowa grupa
    </button>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/mass_send.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-megaphone me-1"></i>Wysyłka masowa
    </a>
  </div>
</div>

<?php if (!$all_groups): ?>
<div class="text-center py-5">
  <i class="bi bi-collection" style="font-size:2.5rem;color:#E5E7EB;display:block;margin-bottom:.75rem"></i>
  <h5 class="text-muted">Brak grup</h5>
  <p class="text-muted small">Grupy pozwalają segmentować kontakty i wysyłać wiadomości masowe.</p>
  <?php if ($crm_can_write): ?>
  <button class="btn btn-crm-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#createGroupModal">
    <i class="bi bi-plus-lg me-1"></i>Utwórz pierwszą grupę
  </button>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="d-flex flex-column gap-2">
<?php foreach ($roots as $g):
  $tags = $g['tags_csv'] ? array_filter(explode(',', $g['tags_csv'])) : [];
  $subs = $children[$g['id']] ?? [];
?>
  <!-- Grupa główna -->
  <div>
    <div class="gc" style="--gc-color:<?= h($g['color']) ?>">
      <div class="gc-icon" style="background:<?= h($g['color']) ?>">
        <i class="bi <?= h($g['icon']) ?>"></i>
      </div>
      <div style="flex:1;min-width:0">
        <a href="<?= APP_URL ?>/crm/group/view.php?id=<?= (int)$g['id'] ?>" class="gc-name">
          <?= h($g['name']) ?>
        </a>
        <?php if ($g['description']): ?>
        <div class="gc-sub"><?= h(mb_substr($g['description'], 0, 80)) ?></div>
        <?php endif; ?>
        <?php if ($tags): ?>
        <div class="mt-1 d-flex flex-wrap gap-1">
          <?php foreach ($tags as $t): ?>
          <span class="gc-tag"><i class="bi bi-tag" style="font-size:.6rem"></i><?= h($t) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($g['subgroup_count'] > 0): ?>
        <div class="gc-sub mt-1">
          <i class="bi bi-diagram-3 me-1"></i><?= (int)$g['subgroup_count'] ?> podgrup
        </div>
        <?php endif; ?>
      </div>
      <div class="text-end flex-shrink-0">
        <div class="gc-stat-val" style="color:<?= h($g['color']) ?>"><?= (int)$g['member_count'] ?></div>
        <div class="gc-stat-lbl">kontaktów</div>
      </div>
      <div class="gc-actions">
        <a href="<?= APP_URL ?>/crm/group/view.php?id=<?= (int)$g['id'] ?>" class="btn btn-crm-ghost btn-sm py-0" title="Otwórz">
          <i class="bi bi-eye"></i>
        </a>
        <?php if ($crm_can_write): ?>
        <button class="btn btn-crm-ghost btn-sm py-0" title="Edytuj"
                onclick="openEdit(<?= json_encode(['id'=>$g['id'],'name'=>$g['name'],'description'=>$g['description'],'color'=>$g['color'],'icon'=>$g['icon'],'parent_id'=>$g['parent_id']]) ?>)">
          <i class="bi bi-pencil"></i>
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/crm/mass_send.php?group_id=<?= (int)$g['id'] ?>" class="btn btn-crm-ghost btn-sm py-0" title="Wyślij masowo">
          <i class="bi bi-megaphone"></i>
        </a>
        <?php if ($crm_can_delete): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Usunąć grupę &quot;<?= h(addslashes($g['name'])) ?>&quot;?')">
          <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
          <input type="hidden" name="_action"  value="delete">
          <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
          <button class="btn btn-crm-ghost btn-sm py-0 text-danger" title="Usuń"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Podgrupy -->
    <?php if ($subs): ?>
    <div class="subgroup-block">
      <?php foreach ($subs as $sg):
        $stags = $sg['tags_csv'] ? array_filter(explode(',', $sg['tags_csv'])) : [];
      ?>
      <div class="gc mb-1" style="--gc-color:<?= h($sg['color']) ?>">
        <div class="gc-icon" style="background:<?= h($sg['color']) ?>">
          <i class="bi <?= h($sg['icon']) ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
          <a href="<?= APP_URL ?>/crm/group/view.php?id=<?= (int)$sg['id'] ?>" class="gc-name" style="font-size:.87rem">
            <?= h($sg['name']) ?>
          </a>
          <?php if ($stags): ?>
          <div class="d-flex flex-wrap gap-1 mt-1">
            <?php foreach ($stags as $t): ?>
            <span class="gc-tag"><i class="bi bi-tag" style="font-size:.6rem"></i><?= h($t) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="text-end flex-shrink-0">
          <div class="gc-stat-val" style="color:<?= h($sg['color']) ?>;font-size:1.1rem"><?= (int)$sg['member_count'] ?></div>
          <div class="gc-stat-lbl">kontaktów</div>
        </div>
        <div class="gc-actions">
          <a href="<?= APP_URL ?>/crm/group/view.php?id=<?= (int)$sg['id'] ?>" class="btn btn-crm-ghost btn-sm py-0" title="Otwórz">
            <i class="bi bi-eye"></i>
          </a>
          <?php if ($crm_can_write): ?>
          <button class="btn btn-crm-ghost btn-sm py-0" title="Edytuj"
                  onclick="openEdit(<?= json_encode(['id'=>$sg['id'],'name'=>$sg['name'],'description'=>$sg['description'],'color'=>$sg['color'],'icon'=>$sg['icon'],'parent_id'=>$sg['parent_id']]) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
          <?php if ($crm_can_delete): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć podgrupę?')">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"  value="delete">
            <input type="hidden" name="group_id" value="<?= (int)$sg['id'] ?>">
            <button class="btn btn-crm-ghost btn-sm py-0 text-danger" title="Usuń"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if ($crm_can_write): ?>
      <button class="btn btn-outline-secondary btn-sm mt-1" style="font-size:.73rem;border-style:dashed"
              data-bs-toggle="modal" data-bs-target="#createGroupModal"
              onclick="document.getElementById('create_parent').value='<?= (int)$g['id'] ?>'">
        <i class="bi bi-plus me-1"></i>Dodaj podgrupę do „<?= h(mb_substr($g['name'],0,24)) ?>"
      </button>
      <?php endif; ?>
    </div>
    <?php elseif ($crm_can_write): ?>
    <div class="ms-4 mt-1">
      <button class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;border-style:dashed;opacity:.6"
              data-bs-toggle="modal" data-bs-target="#createGroupModal"
              onclick="document.getElementById('create_parent').value='<?= (int)$g['id'] ?>'">
        <i class="bi bi-diagram-3 me-1"></i>Dodaj podgrupę
      </button>
    </div>
    <?php endif; ?>

  </div>
<?php endforeach; ?>
</div>

<?php endif; ?>


<!-- ═══ MODAL: Nowa / Edytuj ═══════════════════════════════════════════════ -->
<?php if ($crm_can_write): ?>

<!-- Modal Nowa -->
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:500px">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="create">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-collection-fill me-2 text-success"></i>Nowa grupa</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php _group_fields('create', $roots) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Utwórz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Edytuj -->
<div class="modal fade" id="editGroupModal" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:500px">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"  value="update">
        <input type="hidden" name="group_id" id="eGid">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edytuj grupę</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php _group_fields('edit', $roots) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<?php
function _group_fields(string $p, array $roots): void {
    $colors = GROUP_COLORS;
    $icons  = GROUP_ICONS;
?>
<div class="mb-3">
  <label class="form-label fw-semibold small">Nazwa <span class="text-danger">*</span></label>
  <input type="text" name="name" id="<?= $p ?>_name" class="form-control form-control-sm" required maxlength="80" placeholder="np. Wolontariusze 2025">
</div>
<div class="mb-3">
  <label class="form-label fw-semibold small">Opis</label>
  <textarea name="description" id="<?= $p ?>_desc" class="form-control form-control-sm" rows="2" placeholder="Krótki opis…"></textarea>
</div>
<div class="mb-3">
  <label class="form-label fw-semibold small">Podgrupa (nadrzędna)</label>
  <select name="parent_id" id="<?= $p ?>_parent" class="form-select form-select-sm">
    <option value="">— grupa główna (brak nadrzędnej) —</option>
    <?php foreach ($roots as $r): ?>
    <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="form-text" style="font-size:.71rem">Wybierz nadrzędną grupę, by stworzyć podgrupę.</div>
</div>
<div class="mb-3">
  <div class="form-label fw-semibold small mb-2">Kolor</div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($colors as $hex => $label): $first = $hex === '#2E844A'; ?>
    <label title="<?= h($label) ?>">
      <input type="radio" name="color" value="<?= h($hex) ?>" class="visually-hidden <?= $p ?>-color-r" <?= $first?'checked':'' ?>>
      <span class="color-swatch <?= $first?'selected':'' ?>" style="background:<?= h($hex) ?>" onclick="syncSwatch(this,'<?= $p ?>')"></span>
    </label>
    <?php endforeach; ?>
  </div>
</div>
<div>
  <div class="form-label fw-semibold small mb-2">Ikona</div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($icons as $cls => $label): $first = $cls === 'bi-collection-fill'; ?>
    <label title="<?= h($label) ?>">
      <input type="radio" name="icon" value="<?= h($cls) ?>" class="visually-hidden <?= $p ?>-icon-r" <?= $first?'checked':'' ?>>
      <span class="icon-btn <?= $first?'selected':'' ?>" onclick="syncIcon(this,'<?= $p ?>')"><i class="bi <?= h($cls) ?>"></i></span>
    </label>
    <?php endforeach; ?>
  </div>
</div>
<?php } ?>

<script>
function syncSwatch(el, p) {
  el.closest('.d-flex').querySelectorAll('.color-swatch').forEach(s=>s.classList.remove('selected'));
  el.classList.add('selected');
  el.previousElementSibling.checked = true;
}
function syncIcon(el, p) {
  el.closest('.d-flex').querySelectorAll('.icon-btn').forEach(b=>b.classList.remove('selected'));
  el.classList.add('selected');
  el.previousElementSibling.checked = true;
}

function openEdit(g) {
  document.getElementById('eGid').value       = g.id;
  document.getElementById('edit_name').value  = g.name || '';
  document.getElementById('edit_desc').value  = g.description || '';
  document.getElementById('edit_parent').value= g.parent_id || '';

  // Kolor
  document.querySelectorAll('.edit-color-r').forEach(r => {
    r.checked = (r.value === g.color);
    r.nextElementSibling.classList.toggle('selected', r.checked);
  });
  // Ikona
  document.querySelectorAll('.edit-icon-r').forEach(r => {
    r.checked = (r.value === g.icon);
    r.nextElementSibling.classList.toggle('selected', r.checked);
  });

  new bootstrap.Modal(document.getElementById('editGroupModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
