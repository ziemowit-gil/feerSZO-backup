<?php
/**
 * admin/print_templates.php
 * Wzory wydruków — lista i zarządzanie (certyfikaty, dyplomy, podziękowania, pisma, własne).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/print_templates.php';

require_role('admin');
pt_migrate();

$SELF       = APP_URL . '/admin/print_templates.php';
$EDITOR     = APP_URL . '/admin/print_template_editor.php';
$RENDER     = APP_URL . '/print/render.php';
$categories = pt_categories();

/* ── POST ─────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'import_docx') {
        $file = $_FILES['docx_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK ||
            strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'docx') {
            flash_set('danger', 'Dozwolony tylko plik .docx.');
            header('Location: ' . $SELF); exit;
        }
        $html = pt_docx_to_html($file['tmp_name']);
        if (!$html) {
            flash_set('danger', 'Nie udało się przetworzyć pliku DOCX.');
            header('Location: ' . $SELF); exit;
        }
        $cat  = array_key_exists($_POST['docx_category'] ?? '', $categories) ? $_POST['docx_category'] : 'wlasne';
        $name = pathinfo($file['name'], PATHINFO_FILENAME);
        $id   = db_insert('print_templates', [
            'name'       => $name,
            'category'   => $cat,
            'body'       => $html,
            'options'    => json_encode(pt_default_options()),
            'created_by' => (int)current_user()['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Zaimportowano „' . $name . '" z DOCX. Możesz teraz dopracować układ.');
        header('Location: ' . $EDITOR . '?id=' . $id); exit;
    }

    if ($act === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_one("SELECT is_active FROM print_templates WHERE id=?", [$id]);
        if ($row) {
            db()->prepare("UPDATE print_templates SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$row['is_active'] ? 0 : 1, $id]);
            flash_set('success', 'Status zmieniony.');
        }
        header('Location: ' . $SELF . pt_qs()); exit;
    }

    if ($act === 'set_default') {
        pt_set_default((int)($_POST['id'] ?? 0));
        flash_set('success', 'Ustawiono jako domyślny w kategorii.');
        header('Location: ' . $SELF . pt_qs()); exit;
    }

    if ($act === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_one("SELECT background_image FROM print_templates WHERE id=?", [$id]);
        if ($row && !empty($row['background_image'])) {
            $p = UPLOAD_DIR . $row['background_image'];
            if (is_file($p)) @unlink($p);
        }
        db()->prepare("DELETE FROM print_templates WHERE id=?")->execute([$id]);
        flash_set('success', 'Wzór usunięty.');
        header('Location: ' . $SELF . pt_qs()); exit;
    }

    header('Location: ' . $SELF); exit;
}

function pt_qs(): string {
    $c = $_GET['cat'] ?? '';
    return $c ? '?cat=' . urlencode($c) : '';
}

$filter    = $_GET['cat'] ?? '';
$templates = pt_list($filter);

$counts = ['' => count(pt_list(''))];
foreach (array_keys($categories) as $c) $counts[$c] = count(pt_list($c));

$PAGE_TITLE = 'Wzory wydruków';
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.cat-badge { font-size:.72rem; }
.tpl-thumb {
  width:46px; height:60px; border:1px solid #e2e8f0; border-radius:4px; flex-shrink:0;
  background:#f8fafc center/cover no-repeat; display:flex; align-items:center; justify-content:center;
  color:#cbd5e1; font-size:1.1rem;
}
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Wzory wydruków</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-printer me-2 text-primary"></i>Wzory wydruków</h4>
  <div class="d-flex gap-2 ms-auto">
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importDocxModal">
      <i class="bi bi-file-earmark-word me-1"></i>Import DOCX
    </button>
    <a href="<?= $EDITOR ?>?new=1" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowy wzór
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Filtry kategorii -->
<div class="mb-3 d-flex gap-2 flex-wrap">
  <?php
  $tabs = ['' => 'Wszystkie'] + $categories;
  foreach ($tabs as $key => $label):
      $active = $filter === $key;
      $url = $SELF . ($key ? '?cat=' . urlencode($key) : '');
  ?>
  <a href="<?= h($url) ?>" class="btn btn-sm btn-<?= $active ? '' : 'outline-' ?>secondary">
    <?= h($label) ?>
    <span class="badge bg-secondary ms-1"><?= $counts[$key] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:60px"></th>
          <th>Nazwa</th>
          <th>Kategoria</th>
          <th>Opis</th>
          <th class="text-center" style="width:90px">Status</th>
          <th style="width:200px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$templates): ?>
        <tr><td colspan="6" class="text-center text-muted py-5">
          Brak wzorów<?= $filter ? ' w tej kategorii' : '' ?>. Kliknij <strong>Nowy wzór</strong>.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($templates as $t):
          [$bg, $mime] = pt_bg_data($t['background_image'] ?? null);
        ?>
        <tr>
          <td class="ps-3">
            <div class="tpl-thumb" <?= $bg ? 'style="background-image:url(data:' . h($mime) . ';base64,' . $bg . ')"' : '' ?>>
              <?= $bg ? '' : '<i class="bi bi-file-earmark-text"></i>' ?>
            </div>
          </td>
          <td class="fw-semibold">
            <?= h($t['name']) ?>
            <?php if ($t['is_default']): ?>
            <span class="badge bg-primary cat-badge ms-1"><i class="bi bi-star-fill"></i> domyślny</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-secondary bg-opacity-25 text-secondary cat-badge"><?= h(pt_category_label($t['category'])) ?></span></td>
          <td class="small text-muted"><?= h(mb_substr($t['description'] ?? '', 0, 70)) ?></td>
          <td class="text-center">
            <span class="badge <?= $t['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $t['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
            </span>
          </td>
          <td class="pe-3">
            <div class="d-flex gap-1 justify-content-end">
              <a href="<?= $EDITOR ?>?id=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="Edytuj">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="<?= $RENDER ?>?template_id=<?= $t['id'] ?>&preview=1" target="_blank"
                 class="btn btn-outline-secondary btn-sm py-0 px-2" title="Podgląd">
                <i class="bi bi-eye"></i>
              </a>
              <?php if (!$t['is_default']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="set_default">
                <input type="hidden" name="id"      value="<?= $t['id'] ?>">
                <button class="btn btn-outline-warning btn-sm py-0 px-2" title="Ustaw jako domyślny w kategorii">
                  <i class="bi bi-star"></i>
                </button>
              </form>
              <?php endif; ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="toggle">
                <input type="hidden" name="id"      value="<?= $t['id'] ?>">
                <button class="btn btn-outline-secondary btn-sm py-0 px-2" title="<?= $t['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
                  <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>"></i>
                </button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć wzór «<?= h(addslashes($t['name'])) ?>»?')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id"      value="<?= $t['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Import DOCX -->
<div class="modal fade" id="importDocxModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="import_docx">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-word text-primary me-1"></i>Import z DOCX</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Plik Word (.docx) <span class="text-danger">*</span></label>
            <input type="file" name="docx_file" class="form-control form-control-sm"
                   accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
            <div class="form-text">Importujemy formatowanie tekstu (bold/italic/nagłówki/listy). Obrazy i tabele są pomijane.</div>
          </div>
          <div class="mb-0">
            <label class="form-label small fw-semibold">Kategoria</label>
            <select name="docx_category" class="form-select form-select-sm">
              <?php foreach ($categories as $k => $v): ?>
              <option value="<?= $k ?>"><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Importuj i edytuj</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
