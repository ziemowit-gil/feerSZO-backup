<?php
/**
 * karty30/ti/notices.php — Komunikaty placówki TI — panel admina (CRUD).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_notices.php';

k30_require_access();
karty30_migrate();
ti_notices_migrate();

$can_write = can_write('karty30') || is_admin();
$cu = current_user();

// ── Akcje ─────────────────────────────────────────────────────────────────────
$op = $_POST['_op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('danger', 'Brak uprawnień do zapisu.'); header('Location: notices.php'); exit; }

    if (in_array($op, ['add','edit'])) {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $aud     = in_array($_POST['audience'] ?? '', ['all'], true) ? $_POST['audience'] : 'all';
        $pinned  = !empty($_POST['is_pinned']) ? 1 : 0;
        $active  = !empty($_POST['is_active']) ? 1 : 0;
        $expires = trim($_POST['expires_at'] ?? '');

        $errors = [];
        if (!$title) $errors[] = 'Podaj tytuł.';

        if (!$errors) {
            $data = compact('title','body','pinned','active','expires') + [
                'audience'    => $aud,
                'is_pinned'   => $pinned,
                'is_active'   => $active,
                'expires_at'  => $expires,
                'author_id'   => (int)$cu['id'],
                'author_name' => $cu['name'] ?? $cu['email'] ?? '',
            ];
            if ($id) {
                ti_notices_update($id, $data);
                flash_set('success', 'Komunikat zaktualizowany.');
            } else {
                ti_notices_save($data);
                flash_set('success', 'Komunikat opublikowany.');
            }
            header('Location: notices.php'); exit;
        }
        $form = compact('id','title','body','aud','pinned','active','expires','errors');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db_query("DELETE FROM k30_ti_notice_reads WHERE notice_id=?", [$id]);
            db_query("DELETE FROM k30_ti_notices WHERE id=?", [$id]);
            flash_set('success', 'Komunikat usunięty.');
        }
        header('Location: notices.php'); exit;
    }

    if ($op === 'toggle_active') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        if ($id) db_query("UPDATE k30_ti_notices SET is_active=?, updated_at=datetime('now') WHERE id=?", [$val, $id]);
        flash_set('success', $val ? 'Komunikat aktywowany.' : 'Komunikat dezaktywowany.');
        header('Location: notices.php'); exit;
    }

    if ($op === 'toggle_pin') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        if ($id) db_query("UPDATE k30_ti_notices SET is_pinned=?, updated_at=datetime('now') WHERE id=?", [$val, $id]);
        flash_set('success', $val ? 'Komunikat przypięty.' : 'Odepnięto komunikat.');
        header('Location: notices.php'); exit;
    }
}

$notices = ti_notices_list_admin();
$form    = $form ?? null;

// Wpis do edycji z GET
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? db_one("SELECT * FROM k30_ti_notices WHERE id=?", [$edit_id]) : null;

$PAGE_TITLE = 'Komunikaty placówki — TI';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:900px">
<?= flash_html() ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-megaphone me-2" style="color:#c2410c"></i>Komunikaty placówki TI</h1>
  <?php if ($can_write): ?>
  <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#noticeModal" onclick="noticeFormReset()">
    <i class="bi bi-plus-lg me-1"></i>Nowy komunikat
  </button>
  <?php endif; ?>
</div>

<?php if ($form && !empty($form['errors'])): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($form['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- Lista komunikatów -->
<?php if (!$notices): ?>
<div class="alert alert-light border text-body-secondary">
  <i class="bi bi-megaphone me-1"></i>Brak komunikatów. Kliknij „Nowy komunikat", aby opublikować pierwszy.
</div>
<?php else: ?>
<div class="d-flex flex-column gap-2">
<?php foreach ($notices as $n):
  $is_active = (int)$n['is_active'];
  $is_pinned = (int)$n['is_pinned'];
  $expired   = $n['expires_at'] && $n['expires_at'] < date('Y-m-d');
?>
<div class="card border-0 shadow-sm <?= !$is_active ? 'opacity-60' : '' ?>"
     style="<?= $is_pinned ? 'border-left:4px solid #f59e0b!important' : 'border-left:4px solid #c2410c40!important' ?>">
  <div class="card-body py-2 px-3">
    <div class="d-flex flex-wrap align-items-start gap-2">
      <div class="flex-grow-1 min-width-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <?php if ($is_pinned): ?><i class="bi bi-pin-angle-fill text-warning" title="Przypięty"></i><?php endif; ?>
          <span class="fw-semibold"><?= h($n['title']) ?></span>
          <?php if (!$is_active): ?><span class="badge text-bg-secondary">nieaktywny</span><?php endif; ?>
          <?php if ($expired): ?><span class="badge text-bg-warning">wygasł</span><?php endif; ?>
        </div>
        <?php if ($n['body']): ?>
        <div class="text-body-secondary small mt-1 text-truncate" style="max-width:600px"><?= h(mb_substr($n['body'],0,200)) ?><?= mb_strlen($n['body'])>200?'…':'' ?></div>
        <?php endif; ?>
        <div class="mt-1 d-flex flex-wrap gap-3 text-body-secondary" style="font-size:.78rem">
          <span><i class="bi bi-person me-1"></i><?= h($n['author_name'] ?? '—') ?></span>
          <span><i class="bi bi-clock me-1"></i><?= substr($n['created_at'],0,16) ?></span>
          <?php if ($n['expires_at']): ?><span><i class="bi bi-calendar-x me-1"></i>do <?= h($n['expires_at']) ?></span><?php endif; ?>
          <span><i class="bi bi-eye me-1"></i><?= (int)$n['reads_count'] ?> odczytań</span>
        </div>
      </div>
      <?php if ($can_write): ?>
      <div class="d-flex gap-1 flex-shrink-0">
        <!-- Przypnij/odepnij -->
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="toggle_pin">
          <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <input type="hidden" name="val" value="<?= $is_pinned ? 0 : 1 ?>">
          <button class="btn btn-sm <?= $is_pinned?'btn-warning':'btn-outline-secondary' ?> py-0 px-2" title="<?= $is_pinned?'Odepnij':'Przypnij' ?>">
            <i class="bi bi-pin-angle<?= $is_pinned?'-fill':'' ?>"></i>
          </button>
        </form>
        <!-- Aktywuj/dezaktywuj -->
        <form method="post" class="d-inline" <?= $is_active?'onsubmit="return confirm(\'Dezaktywować?\')"':'' ?>>
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <input type="hidden" name="val" value="<?= $is_active ? 0 : 1 ?>">
          <button class="btn btn-sm <?= $is_active?'btn-outline-secondary':'btn-outline-success' ?> py-0 px-2" title="<?= $is_active?'Dezaktywuj':'Aktywuj' ?>">
            <i class="bi bi-<?= $is_active?'eye-slash':'eye' ?>"></i>
          </button>
        </form>
        <!-- Edytuj -->
        <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                onclick="noticeFormEdit(<?= (int)$n['id'] ?>,<?= htmlspecialchars(json_encode($n['title']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($n['body']),ENT_QUOTES) ?>,<?= (int)$n['is_pinned'] ?>,<?= (int)$n['is_active'] ?>,<?= htmlspecialchars(json_encode($n['expires_at']??''),ENT_QUOTES) ?>)"
                title="Edytuj">
          <i class="bi bi-pencil"></i>
        </button>
        <!-- Usuń -->
        <form method="post" class="d-inline" onsubmit="return confirm('Trwale usunąć komunikat?')">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="delete">
          <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- Modal dodaj/edytuj -->
<?php if ($can_write): ?>
<div class="modal fade" id="noticeModal" tabindex="-1" aria-labelledby="noticeMTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post" id="noticeForm">
        <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="add">
        <input type="hidden" name="id" id="nf_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="noticeMTitle"><i class="bi bi-megaphone me-2"></i><span id="nf_title_label">Nowy komunikat</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" id="nf_title" required placeholder="np. Przerwa świąteczna, Ważna informacja organizacyjna">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Treść</label>
            <textarea class="form-control" name="body" id="nf_body" rows="5" placeholder="Treść komunikatu (opcjonalnie)…"></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label class="form-label">Data wygaśnięcia</label>
              <input type="date" class="form-control" name="expires_at" id="nf_expires" min="<?= date('Y-m-d') ?>">
              <div class="form-text">Zostaw puste = bez terminu wygaśnięcia.</div>
            </div>
            <div class="col-sm-6 d-flex flex-column gap-2 justify-content-center pt-3">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_pinned" id="nf_pinned" value="1">
                <label class="form-check-label" for="nf_pinned"><i class="bi bi-pin-angle me-1"></i>Przypnij na górze</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" id="nf_active" value="1" checked>
                <label class="form-check-label" for="nf_active">Aktywny (widoczny dla kursantów)</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Opublikuj</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function noticeFormReset() {
  document.getElementById('nf_id').value = '0';
  document.getElementById('nf_title').value = '';
  document.getElementById('nf_body').value = '';
  document.getElementById('nf_expires').value = '';
  document.getElementById('nf_pinned').checked = false;
  document.getElementById('nf_active').checked = true;
  document.getElementById('nf_title_label').textContent = 'Nowy komunikat';
  document.querySelector('#noticeForm [name="_op"]').value = 'add';
}
function noticeFormEdit(id, title, body, pinned, active, expires) {
  document.getElementById('nf_id').value = id;
  document.getElementById('nf_title').value = title;
  document.getElementById('nf_body').value = body;
  document.getElementById('nf_expires').value = expires;
  document.getElementById('nf_pinned').checked = !!pinned;
  document.getElementById('nf_active').checked = !!active;
  document.getElementById('nf_title_label').textContent = 'Edytuj komunikat';
  document.querySelector('#noticeForm [name="_op"]').value = 'edit';
  new bootstrap.Modal(document.getElementById('noticeModal')).show();
}
</script>
<?php endif; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
