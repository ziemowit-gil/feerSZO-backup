<?php
/**
 * helpdesk/admin_macros.php — Zarządzanie gotowymi odpowiedziami (makrami).
 * Edytor treści: Quill 2.0.3.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();
if (!is_admin() && !hd_is_operator()) { http_response_code(403); die('Brak dostępu.'); }

$uid = (int)(current_user()['id'] ?? 0);
$err = '';

// ── Akcje POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $body   = trim($_POST['body']  ?? '');
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $active = (int)!empty($_POST['is_active']);
        if ($title === '') { $err = 'Tytuł makra nie może być pusty.'; }
        else {
            if ($id) {
                db_update('helpdesk_macros', [
                    'title' => $title, 'body' => $body,
                    'sort_order' => $sort, 'is_active' => $active,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], $id);
                flash_set('success', 'Makro zaktualizowane.');
            } else {
                db_insert('helpdesk_macros', [
                    'title' => $title, 'body' => $body,
                    'sort_order' => $sort, 'is_active' => $active,
                    'created_by' => $uid, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                flash_set('success', 'Makro dodane.');
            }
            header('Location: admin_macros.php'); exit;
        }
    }

    if ($action === 'delete' && is_admin()) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db()->prepare("DELETE FROM helpdesk_macros WHERE id=?")->execute([$id]);
        flash_set('success', 'Makro usunięte.');
        header('Location: admin_macros.php'); exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $m = $id ? db_one("SELECT id, is_active FROM helpdesk_macros WHERE id=?", [$id]) : null;
        if ($m) db_update('helpdesk_macros', ['is_active' => $m['is_active'] ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')], $id);
        header('Location: admin_macros.php'); exit;
    }
}

$edit_id = (int)($_GET['edit'] ?? 0);
$edit    = $edit_id ? db_one("SELECT * FROM helpdesk_macros WHERE id=?", [$edit_id]) : null;
$macros  = db_all("SELECT m.*, u.name AS author FROM helpdesk_macros m LEFT JOIN users u ON u.id=m.created_by ORDER BY m.sort_order, m.title", []);

$PAGE_TITLE = 'Gotowe odpowiedzi — Helpdesk';
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<style>
.hd-am-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: .75rem; margin-bottom: 1.25rem;
}
.hd-am-header-left h4 { font-size: 1.05rem; font-weight: 800; margin: 0; color: var(--hd-tx); }
.hd-am-header-left p  { font-size: .8rem; color: var(--hd-tx3); margin: .15rem 0 0; }
.hd-am-card {
  background: var(--hd-panel); border: 1px solid var(--hd-bd);
  border-radius: 12px; overflow: hidden; box-shadow: var(--hd-shadow); margin-bottom: 1rem;
}
.hd-am-card-head {
  padding: .6rem .9rem; font-size: .83rem; font-weight: 700;
  background: var(--hd-bg); border-bottom: 1px solid var(--hd-bd-l);
  display: flex; align-items: center; gap: .4rem;
}
.hd-am-card-head i { color: var(--hd-accent); }
.hd-am-card-body { padding: 1rem 1.1rem; }
.hd-macro-row {
  display: grid; grid-template-columns: 1fr auto auto auto; gap: .6rem;
  align-items: center; padding: .55rem .9rem;
  border-bottom: 1px solid var(--hd-bd-l); transition: background .09s;
}
.hd-macro-row:last-child { border-bottom: none; }
.hd-macro-row:hover { background: var(--hd-bg); }
.hd-macro-row-main .hd-macro-row-title { font-size: .84rem; font-weight: 700; color: var(--hd-tx); }
.hd-macro-row-main .hd-macro-row-preview {
  font-size: .75rem; color: var(--hd-tx3); white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis; max-width: 420px;
}
.hd-macro-row-sort { font-size: .72rem; color: var(--hd-tx3); text-align: center; min-width: 24px; }
.hd-macro-row-status { min-width: 80px; }
.hd-macro-row-actions { display: flex; gap: .25rem; }
/* Quill customizations */
.hd-macro-quill-wrap .ql-container { font-size: .9rem; min-height: 160px; border-radius: 0 0 8px 8px; }
.hd-macro-quill-wrap .ql-toolbar { border-radius: 8px 8px 0 0; }
/* Preview pane */
.hd-macro-preview-pane {
  display: none; border: 1px solid var(--hd-bd-l); border-radius: 8px;
  padding: .8rem 1rem; background: var(--hd-bg); margin-top: .6rem;
  font-size: .85rem; color: var(--hd-tx);
}
.hd-macro-preview-pane.active { display: block; }
.hd-macro-preview-pane p { margin: 0; }
</style>

<!-- Header -->
<div class="hd-am-header">
  <div class="hd-am-header-left">
    <h4><i class="bi bi-card-text me-2" style="color:var(--hd-accent)"></i>Gotowe odpowiedzi</h4>
    <p>Szablony dla operatorów — dostępne w panelu makr podczas odpowiadania na zgłoszenia.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Wróć do konsoli
    </a>
    <a href="admin_macros.php?edit=0" class="btn btn-sm btn-primary" style="background:var(--hd-accent);border-color:var(--hd-accent)">
      <i class="bi bi-plus-lg me-1"></i>Nowe makro
    </a>
  </div>
</div>

<?= flash_html() ?>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= h($err) ?></div><?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<!-- Formularz edycji / nowe makro -->
<div class="hd-am-card mb-4">
  <div class="hd-am-card-head">
    <i class="bi bi-pencil-square"></i><?= $edit ? 'Edytuj makro' : 'Nowe makro' ?>
  </div>
  <div class="hd-am-card-body">
    <form method="post" id="macroForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <!-- Ukryte pole body wypełniane przez Quill -->
      <input type="hidden" name="body" id="macroBodyHidden" value="<?= h($edit['body'] ?? '') ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold mb-1" style="font-size:.85rem">
          Tytuł makra <span class="text-danger">*</span>
        </label>
        <input type="text" name="title" class="form-control" required maxlength="200"
               value="<?= h($edit['title'] ?? '') ?>"
               placeholder="np. Prośba o dodatkowe informacje">
        <div class="form-text" style="font-size:.77rem">Widoczny jako nazwa karty w panelu makr.</div>
      </div>

      <div class="mb-3">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <label class="form-label fw-semibold mb-0" style="font-size:.85rem">
            Treść odpowiedzi
          </label>
          <button type="button" id="macroPreviewBtn" class="btn btn-sm btn-link text-secondary p-0" style="font-size:.78rem">
            <i class="bi bi-eye me-1"></i>Podgląd
          </button>
        </div>
        <div class="hd-macro-quill-wrap" id="macroQuillWrap">
          <div id="macroQuillEditor" style="min-height:160px"></div>
        </div>
        <div class="hd-macro-preview-pane" id="macroPreviewPane">
          <div style="font-size:.73rem;color:var(--hd-tx3);margin-bottom:.4rem;font-weight:600">PODGLĄD:</div>
          <div id="macroPreviewContent"></div>
        </div>
        <div class="form-text mt-1" style="font-size:.77rem">
          Obsługuje formatowanie HTML (pogrubienie, listy, linki) — renderowane w formularzu odpowiedzi Helpdesk.
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-sm-3">
          <label class="form-label fw-semibold mb-1" style="font-size:.85rem">Kolejność</label>
          <input type="number" name="sort_order" class="form-control form-control-sm"
                 value="<?= (int)($edit['sort_order'] ?? 0) ?>" min="0">
          <div class="form-text" style="font-size:.75rem">Mniejsza = wyżej.</div>
        </div>
        <div class="col-sm-9 d-flex align-items-end pb-1">
          <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:.85rem">
            <input class="form-check-input mt-0" type="checkbox" name="is_active" id="macroActive" value="1"
                   <?= ($edit === null || !empty($edit['is_active'])) ? 'checked' : '' ?>>
            <span>Makro aktywne <span style="color:var(--hd-tx3);font-size:.78rem">(widoczne dla operatorów)</span></span>
          </label>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" id="macroSaveBtn" class="btn btn-primary" style="background:var(--hd-accent);border-color:var(--hd-accent)">
          <i class="bi bi-check-lg me-1"></i>Zapisz makro
        </button>
        <a href="admin_macros.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function(){
  var TOOLBAR = [
    [{ header:[false,2,3] }],
    ['bold','italic','underline','strike'],
    [{ list:'ordered'},{list:'bullet'}],
    ['blockquote','link'],
    ['clean']
  ];
  var initial = document.getElementById('macroBodyHidden').value || '';
  var q = new Quill('#macroQuillEditor', {
    theme: 'snow', modules: { toolbar: TOOLBAR }, placeholder: 'Wpisz treść gotowej odpowiedzi…'
  });
  /* Ustaw istniejącą treść */
  if (initial) { q.clipboard.dangerouslyPasteHTML(initial); }
  var qlEd = document.querySelector('#macroQuillEditor .ql-editor');
  if (qlEd) { qlEd.setAttribute('aria-label','Treść makra'); }

  /* Przycisk podglądu */
  var previewBtn  = document.getElementById('macroPreviewBtn');
  var previewPane = document.getElementById('macroPreviewPane');
  var previewBody = document.getElementById('macroPreviewContent');
  previewBtn.addEventListener('click', function() {
    var active = previewPane.classList.toggle('active');
    previewBtn.innerHTML = '<i class="bi bi-eye' + (active?'-slash':'') + ' me-1"></i>' + (active?'Ukryj':'Podgląd');
    if (active && q.getLength() > 1) previewBody.innerHTML = q.root.innerHTML;
  });

  /* Zapis: wstaw HTML do ukrytego pola przed submit */
  document.getElementById('macroForm').addEventListener('submit', function() {
    document.getElementById('macroBodyHidden').value = q.root.innerHTML;
  });
  document.getElementById('macroSaveBtn').addEventListener('click', function() {
    document.getElementById('macroBodyHidden').value = q.root.innerHTML;
  });
})();
</script>
<?php endif; ?>

<!-- Lista makr -->
<div class="hd-am-card">
  <div class="hd-am-card-head">
    <i class="bi bi-list-ul"></i>Wszystkie makra (<?= count($macros) ?>)
  </div>
  <?php if (!$macros): ?>
  <div class="text-center py-5" style="color:var(--hd-tx3)">
    <i class="bi bi-card-text" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
    <div style="font-size:.85rem">Brak makr — kliknij „Nowe makro" aby dodać pierwsze.</div>
  </div>
  <?php else: ?>
  <?php foreach ($macros as $m): ?>
  <div class="hd-macro-row">
    <div class="hd-macro-row-main">
      <div class="hd-macro-row-title"><?= h($m['title']) ?></div>
      <?php if ($m['body']): ?>
      <div class="hd-macro-row-preview"><?= h(mb_substr(strip_tags($m['body']), 0, 110)) ?></div>
      <?php endif; ?>
      <?php if (!empty($m['author'])): ?>
      <div style="font-size:.71rem;color:var(--hd-tx3);margin-top:.1rem"><i class="bi bi-person me-1"></i><?= h($m['author']) ?></div>
      <?php endif; ?>
    </div>
    <div class="hd-macro-row-sort"><?= (int)$m['sort_order'] ?></div>
    <div class="hd-macro-row-status">
      <?php if ($m['is_active']): ?>
      <span class="badge" style="background:var(--hd-accent-l);color:var(--hd-accent);font-size:.72rem;font-weight:600">Aktywne</span>
      <?php else: ?>
      <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.72rem">Nieakt.</span>
      <?php endif; ?>
    </div>
    <div class="hd-macro-row-actions">
      <a href="admin_macros.php?edit=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj">
        <i class="bi bi-pencil"></i>
      </a>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="toggle">
        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <button type="submit" class="btn btn-sm py-0 px-2 btn-outline-<?= $m['is_active'] ? 'warning' : 'success' ?>"
                title="<?= $m['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
          <i class="bi bi-<?= $m['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
        </button>
      </form>
      <?php if (is_admin()): ?>
      <form method="post" class="d-inline"
            onsubmit="return confirm('Usunąć makro „<?= h(addslashes($m['title'])) ?>”?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń">
          <i class="bi bi-trash3"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
