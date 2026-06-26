<?php
/**
 * helpdesk/admin_macros.php — Zarządzanie gotowymi odpowiedziami (makrami).
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
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
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

$PAGE_TITLE = 'Zarządzanie makrami — Helpdesk';
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-card-text text-primary me-2"></i>Gotowe odpowiedzi (makra)</h4>
    <div class="text-muted small">Szablony edytowalne przez operatora, dostępne w formularzu odpowiedzi.</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Wróć do konsoli</a>
    <a href="admin_macros.php?edit=0" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Nowe makro</a>
  </div>
</div>

<?= flash_html() ?>
<?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<!-- Formularz edycji / nowe makro -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header py-2 fw-semibold" style="font-size:.9rem">
    <i class="bi bi-pencil me-1"></i><?= $edit ? 'Edytuj makro' : 'Nowe makro' ?>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold mb-1">Tytuł makra <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required maxlength="200"
               value="<?= h($edit['title'] ?? '') ?>" placeholder="np. Prośba o dodatkowe informacje">
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold mb-1">Treść odpowiedzi</label>
        <div class="mb-1 text-muted" style="font-size:.78rem">Obsługuje HTML (bold, listy, linki) — edytor Quill w formularzu odpowiedzi.</div>
        <textarea name="body" id="macroBodyRaw" class="form-control font-monospace" rows="10"
                  style="font-size:.82rem"><?= h($edit['body'] ?? '') ?></textarea>
        <div class="form-text">Możesz wpisać plain-text lub HTML. Edytor WYSIWYG w helpdesku wyrenderuje formatowanie.</div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label fw-semibold mb-1">Kolejność</label>
          <input type="number" name="sort_order" class="form-control" value="<?= (int)($edit['sort_order'] ?? 0) ?>" min="0">
          <div class="form-text">Mniejsza liczba = wyżej na liście.</div>
        </div>
        <div class="col-sm-8 d-flex align-items-end">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="macroActive" value="1"
                   <?= ($edit === null || !empty($edit['is_active'])) ? 'checked' : '' ?>>
            <label class="form-check-label" for="macroActive">Makro aktywne (widoczne dla operatorów)</label>
          </div>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        <a href="admin_macros.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Lista makr -->
<div class="card border-0 shadow-sm">
  <div class="card-header py-2 fw-semibold" style="font-size:.9rem"><i class="bi bi-list-ul me-1"></i>Wszystkie makra (<?= count($macros) ?>)</div>
  <?php if (!$macros): ?>
  <div class="card-body text-muted text-center py-4">
    <i class="bi bi-card-text" style="font-size:2rem;opacity:.3"></i>
    <div class="mt-2">Brak makr. Dodaj pierwsze makro klikając „Nowe makro".</div>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:36px">#</th>
          <th>Tytuł</th>
          <th style="width:80px">Kol.</th>
          <th style="width:90px">Status</th>
          <th style="width:130px">Autor</th>
          <th style="width:150px" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($macros as $m): ?>
        <tr>
          <td class="text-muted font-monospace" style="font-size:.78rem"><?= (int)$m['id'] ?></td>
          <td>
            <div class="fw-semibold"><?= h($m['title']) ?></div>
            <?php if ($m['body']): ?>
            <div class="text-muted" style="font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:380px"><?= h(mb_substr(strip_tags($m['body']), 0, 100)) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-muted text-center"><?= (int)$m['sort_order'] ?></td>
          <td>
            <?php if ($m['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">Aktywne</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">Nieaktywne</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="font-size:.8rem"><?= h($m['author'] ?? '—') ?></td>
          <td class="text-end">
            <a href="admin_macros.php?edit=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-<?= $m['is_active'] ? 'warning' : 'success' ?> py-0 px-2"
                      title="<?= $m['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
                <i class="bi bi-<?= $m['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
              </button>
            </form>
            <?php if (is_admin()): ?>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć makro „<?= h(addslashes($m['title'])) ?>"?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
