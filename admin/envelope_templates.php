<?php
/**
 * admin/envelope_templates.php
 * Wzory kopert — lista i zarządzanie (DL/C6/C5/C4).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/envelopes.php';

require_role('admin');
env_migrate();

$SELF   = APP_URL . '/admin/envelope_templates.php';
$EDITOR = APP_URL . '/admin/envelope_editor.php';
$RENDER = APP_URL . '/print/envelope.php';
$formats = env_formats();

/* ── POST ─────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'toggle') {
        $row = db_one("SELECT is_active FROM envelope_templates WHERE id=?", [$id]);
        if ($row) {
            db()->prepare("UPDATE envelope_templates SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$row['is_active'] ? 0 : 1, $id]);
            flash_set('success', 'Status zmieniony.');
        }
    } elseif ($act === 'set_default') {
        env_set_default($id);
        flash_set('success', 'Ustawiono jako domyślny wzór koperty.');
    } elseif ($act === 'delete') {
        db()->prepare("DELETE FROM envelope_templates WHERE id=?")->execute([$id]);
        flash_set('success', 'Wzór koperty usunięty.');
    }
    header('Location: ' . $SELF); exit;
}

$templates = env_list();

$PAGE_TITLE = 'Wzory kopert';
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.env-thumb {
  width:78px; height:48px; border:1px solid #e2e8f0; border-radius:4px; flex-shrink:0;
  background:#f8fafc; position:relative; overflow:hidden;
}
.env-thumb::after { content:''; position:absolute; right:8px; bottom:7px; width:34px; height:11px; background:#e2e8f0; border-radius:2px; }
.env-thumb::before { content:''; position:absolute; left:6px; top:6px; width:22px; height:7px; background:#eef2f7; border-radius:2px; }
.fmt-badge { font-size:.72rem; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Wzory kopert</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-envelope me-2 text-primary"></i>Wzory kopert</h4>
  <div class="d-flex gap-2 ms-auto">
    <a href="<?= $EDITOR ?>?new=1" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowy wzór koperty
    </a>
  </div>
</div>

<?= flash_html() ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:96px"></th>
          <th>Nazwa</th>
          <th>Format</th>
          <th class="text-center" style="width:90px">Status</th>
          <th style="width:190px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$templates): ?>
        <tr><td colspan="5" class="text-center text-muted py-5">
          Brak wzorów kopert. Kliknij <strong>Nowy wzór koperty</strong>.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($templates as $t):
          $fmt = $formats[$t['format']] ?? $formats['DL'];
        ?>
        <tr>
          <td class="ps-3"><div class="env-thumb" title="<?= h($fmt['label']) ?>"></div></td>
          <td class="fw-semibold">
            <?= h($t['name']) ?>
            <?php if ($t['is_default']): ?>
            <span class="badge bg-primary fmt-badge ms-1"><i class="bi bi-star-fill"></i> domyślny</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-secondary bg-opacity-25 text-secondary fmt-badge"><?= h($fmt['label']) ?></span></td>
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
                <button class="btn btn-outline-warning btn-sm py-0 px-2" title="Ustaw jako domyślny">
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

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
