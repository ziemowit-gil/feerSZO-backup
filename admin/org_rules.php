<?php
/**
 * admin/org_rules.php — Zarządzanie zasadami i wprowadzeniem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_rules.php';

require_role('admin', 'editor');
org_rules_migrate();

$PAGE_TITLE = 'Zasady organizacji — zarządzanie';

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $uid    = (int)(current_user()['id'] ?? 0);

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $cat     = array_key_exists($_POST['category']??'', ORG_RULE_CATEGORIES) ? $_POST['category'] : 'intro';
        $icon    = trim($_POST['icon'] ?? 'bi-file-text');
        $color   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color']??'') ? $_POST['color'] : '#2563EB';
        $mand    = isset($_POST['is_mandatory']) ? 1 : 0;
        $order   = (int)($_POST['sort_order'] ?? 0);

        if (!$title) { flash_set('danger', 'Tytuł jest wymagany.'); header('Location: org_rules.php'); exit; }

        $data = ['title'=>$title,'content'=>$content,'category'=>$cat,'icon'=>$icon,'color'=>$color,
                 'is_mandatory'=>$mand,'sort_order'=>$order,'updated_by'=>$uid,'updated_at'=>date('Y-m-d H:i:s')];

        if ($id) {
            db_update('org_rules', $id, $data);
            flash_set('success', 'Zasada zaktualizowana.');
        } else {
            $data['created_by'] = $uid;
            db_insert('org_rules', $data);
            flash_set('success', 'Zasada dodana.');
        }
        header('Location: org_rules.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE org_rules SET status='deleted' WHERE id=?")->execute([$id]);
        flash_set('success', 'Zasada usunięta.');
        header('Location: org_rules.php'); exit;
    }

    if ($action === 'toggle_mandatory') {
        $id = (int)($_POST['id'] ?? 0);
        $r  = db_one("SELECT is_mandatory FROM org_rules WHERE id=?", [$id]);
        if ($r) db()->prepare("UPDATE org_rules SET is_mandatory=? WHERE id=?")->execute([$r['is_mandatory']?0:1, $id]);
        header('Location: org_rules.php'); exit;
    }
}

// Pokaż potwierdzenia dla konkretnej zasady
$show_acks = (int)($_GET['acks'] ?? 0);
if ($show_acks) {
    $acks_rule = db_one("SELECT * FROM org_rules WHERE id=?", [$show_acks]);
    $acks      = db_all(
        "SELECT a.*, u.name AS user_name, u.email FROM org_rules_ack a
         LEFT JOIN users u ON u.id=a.user_id
         WHERE a.rule_id=? ORDER BY a.ack_at DESC",
        [$show_acks]
    );
}

// Formularz edycji
$edit_rule = null;
$edit_id   = (int)($_GET['edit'] ?? 0);
if ($edit_id) {
    $edit_rule = db_one("SELECT * FROM org_rules WHERE id=?", [$edit_id]);
}

$rules = db_all("SELECT r.*, (SELECT COUNT(*) FROM org_rules_ack a WHERE a.rule_id=r.id) AS ack_count
                 FROM org_rules r WHERE r.status='active' ORDER BY r.category, r.sort_order, r.title");

$cat_groups = [];
foreach ($rules as $r) {
    $cat_groups[$r['category']][] = $r;
}

// Łączna liczba aktywnych użytkowników
$total_users = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE is_active=1")['c'] ?? 0);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-building-heart text-primary me-2"></i>Zasady i Wprowadzenie</h4>
    <div class="text-muted small">Zarządzaj treściami wprowadzającymi dla wolontariuszy i pracowników</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/org_intro/index.php" class="btn btn-outline-primary btn-sm" target="_blank">
      <i class="bi bi-eye me-1"></i>Podgląd modułu
    </a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ruleModal"
            onclick="resetForm()">
      <i class="bi bi-plus-lg me-1"></i>Dodaj zasadę
    </button>
  </div>
</div>

<?= flash_html() ?>

<?php if ($show_acks && $acks_rule): ?>
<!-- Widok potwierdzeń -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center gap-2 py-2">
    <i class="bi bi-people text-muted"></i>
    <span class="fw-semibold">Potwierdzenia: <?= h($acks_rule['title']) ?></span>
    <span class="badge bg-success ms-1"><?= count($acks) ?>/<?= $total_users ?></span>
    <a href="org_rules.php" class="btn btn-outline-secondary btn-sm ms-auto py-0">← Wróć</a>
  </div>
  <?php if ($acks): ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.84rem">
      <thead class="table-light"><tr><th>Użytkownik</th><th>E-mail</th><th>Data potwierdzenia</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($acks as $a): ?>
        <tr>
          <td class="fw-semibold"><?= h($a['user_name']) ?></td>
          <td class="text-muted"><?= h($a['email']) ?></td>
          <td><?= date('d.m.Y H:i', strtotime($a['ack_at'])) ?></td>
          <td class="text-muted font-monospace" style="font-size:.75rem"><?= h($a['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card-body text-center text-muted py-4">Brak potwierdzeń.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Lista zasad wg kategorii -->
<?php if (!$rules): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-file-earmark-plus d-block mb-2 opacity-25" style="font-size:2.5rem"></i>
  Brak zasad. Kliknij „Dodaj zasadę" aby dodać pierwszą.
</div>
<?php endif; ?>

<?php foreach ($cat_groups as $cat_key => $cat_rules):
  $cat_cfg = ORG_RULE_CATEGORIES[$cat_key] ?? ['label'=>ucfirst($cat_key),'icon'=>'bi-file-text','color'=>'#6B7280'];
?>
<div class="d-flex align-items-center gap-2 mb-2 mt-3" style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF">
  <div style="width:18px;height:18px;border-radius:4px;background:<?= h($cat_cfg['color']) ?>;display:flex;align-items:center;justify-content:center">
    <i class="bi <?= h($cat_cfg['icon']) ?>" style="color:#fff;font-size:.6rem"></i>
  </div>
  <?= h($cat_cfg['label']) ?>
</div>

<div class="card shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th style="width:30px">Sort</th>
          <th>Tytuł</th>
          <th>Obowiązkowe</th>
          <th class="text-center">Potwierdzenia</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cat_rules as $r):
        $pct = $total_users > 0 ? round($r['ack_count']/$total_users*100) : 0;
      ?>
      <tr>
        <td class="text-muted" style="font-size:.75rem"><?= (int)$r['sort_order'] ?></td>
        <td>
          <div class="d-flex align-items-center gap-2">
            <div style="width:24px;height:24px;border-radius:6px;background:<?= h($r['color']?:$cat_cfg['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi <?= h($r['icon'] ?? 'bi-file-text') ?>" style="color:#fff;font-size:.65rem"></i>
            </div>
            <a href="<?= APP_URL ?>/org_intro/view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none text-dark" target="_blank">
              <?= h($r['title']) ?>
            </a>
          </div>
        </td>
        <td>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="toggle_mandatory">
            <input type="hidden" name="id"      value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm py-0 px-2 <?= $r['is_mandatory']?'btn-danger':'btn-outline-secondary' ?>">
              <?php if ($r['is_mandatory']): ?>
              <i class="bi bi-asterisk me-1"></i>Obowiązkowe
              <?php else: ?>
              <i class="bi bi-circle me-1"></i>Opcjonalne
              <?php endif; ?>
            </button>
          </form>
        </td>
        <td class="text-center">
          <div class="d-flex align-items-center gap-2 justify-content-center">
            <div style="width:80px;background:#F3F4F6;border-radius:3px;height:6px">
              <div style="background:<?= $pct>=100?'#16A34A':'#2563EB' ?>;height:6px;border-radius:3px;width:<?= $pct ?>%"></div>
            </div>
            <a href="?acks=<?= (int)$r['id'] ?>" class="text-decoration-none" style="font-size:.78rem">
              <strong><?= (int)$r['ack_count'] ?></strong>/<span class="text-muted"><?= $total_users ?></span>
            </a>
          </div>
        </td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end">
            <button class="btn btn-outline-primary btn-sm py-0 px-2"
                    onclick="openEdit(<?= json_encode($r) ?>)"
                    data-bs-toggle="modal" data-bs-target="#ruleModal">
              <i class="bi bi-pencil"></i>
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę zasadę?')">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id"      value="<?= (int)$r['id'] ?>">
              <button class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<!-- Modal dodaj/edytuj -->
<div class="modal fade" id="ruleModal" tabindex="-1" aria-modal="true" aria-labelledby="ruleModalLabel">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="ruleForm">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id"      id="f_id" value="">

        <div class="modal-header border-bottom py-2">
          <h5 class="modal-title fw-bold" id="ruleModalLabel">
            <i class="bi bi-file-earmark-plus me-2 text-primary"></i>
            <span id="modalTitleText">Nowa zasada</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">Tytuł <span class="text-danger">*</span></label>
              <input type="text" name="title" id="f_title" class="form-control" required placeholder="np. Regulamin Wolontariatu">
            </div>

            <div class="col-sm-4">
              <label class="form-label fw-semibold small">Kategoria</label>
              <select name="category" id="f_category" class="form-select">
                <?php foreach (ORG_RULE_CATEGORIES as $ck => $cv): ?>
                <option value="<?= h($ck) ?>"><?= h($cv['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-3">
              <label class="form-label fw-semibold small">Kolejność</label>
              <input type="number" name="sort_order" id="f_sort" class="form-control" value="0" min="0">
            </div>

            <div class="col-sm-3">
              <label class="form-label fw-semibold small">Kolor</label>
              <input type="color" name="color" id="f_color" class="form-control form-control-color w-100" value="#2563EB">
            </div>

            <div class="col-sm-2">
              <label class="form-label fw-semibold small">Ikona BI</label>
              <input type="text" name="icon" id="f_icon" class="form-control form-control-sm font-monospace" value="bi-file-text" placeholder="bi-file-text">
            </div>

            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_mandatory" id="f_mandatory" value="1">
                <label class="form-check-label fw-semibold" for="f_mandatory">
                  <i class="bi bi-asterisk text-danger me-1"></i>Obowiązkowe do przeczytania
                </label>
                <div class="form-text">Użytkownicy będą proszeni o potwierdzenie przeczytania.</div>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold small">Treść</label>
              <textarea name="content" id="f_content" class="form-control" rows="10"
                        placeholder="Wpisz treść zasady, regulaminu lub artykułu wprowadzającego…"
                        style="font-family:system-ui;font-size:.92rem"></textarea>
              <div class="form-text">Obsługiwane jest formatowanie HTML.</div>
            </div>
          </div>
        </div>

        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1"></i>Zapisz
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function resetForm() {
  document.getElementById('f_id').value      = '';
  document.getElementById('f_title').value   = '';
  document.getElementById('f_content').value = '';
  document.getElementById('f_category').value= 'intro';
  document.getElementById('f_sort').value    = '0';
  document.getElementById('f_color').value   = '#2563EB';
  document.getElementById('f_icon').value    = 'bi-file-text';
  document.getElementById('f_mandatory').checked = false;
  document.getElementById('modalTitleText').textContent = 'Nowa zasada';
}

function openEdit(r) {
  document.getElementById('f_id').value      = r.id;
  document.getElementById('f_title').value   = r.title;
  document.getElementById('f_content').value = r.content;
  document.getElementById('f_category').value= r.category;
  document.getElementById('f_sort').value    = r.sort_order;
  document.getElementById('f_color').value   = r.color || '#2563EB';
  document.getElementById('f_icon').value    = r.icon || 'bi-file-text';
  document.getElementById('f_mandatory').checked = r.is_mandatory == 1;
  document.getElementById('modalTitleText').textContent = 'Edytuj zasadę';
}

<?php if ($edit_rule): ?>
openEdit(<?= json_encode($edit_rule) ?>);
var m = new bootstrap.Modal(document.getElementById('ruleModal'));
m.show();
<?php endif; ?>
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
