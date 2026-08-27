<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resolutions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
require_module_enabled('resolutions_enabled', 'Moduł Uchwały i Zarządzenia');

$id  = (int)($_GET['id'] ?? 0);
$res = uchw_get($id);
if (!$res) { flash_set('error', 'Nie znaleziono.'); header('Location: ' . APP_URL . '/resolutions/index.php'); exit; }

$PAGE_TITLE = $res['number'] ? $res['number'] . ' — ' . $res['title'] : $res['title'];
$errors   = [];
$users    = uchw_users_list();
$cats     = uchw_categories();
$can_edit = can_write('resolutions');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_check();
    $action = $_POST['action'] ?? 'edit';

    if ($action === 'delete') {
        uchw_delete($id);
        log_system_action((int)current_user()['id'], 'uchw_delete', "Usunięto dokument #$id");
        flash_set('success', 'Dokument usunięty.');
        header('Location: ' . APP_URL . '/resolutions/index.php'); exit;
    }

    if ($action === 'status') {
        uchw_update($id, ['status' => $_POST['status'] ?? $res['status']]);
        flash_set('success', 'Status zaktualizowany.');
        header('Location: ' . APP_URL . '/resolutions/view.php?id=' . $id); exit;
    }

    if ($action === 'edit') {
        $data = [
            'type'      => $_POST['type']     ?? $res['type'],
            'number'    => trim($_POST['number'] ?? ''),
            'date'      => $_POST['date']     ?? $res['date'],
            'title'     => trim($_POST['title'] ?? ''),
            'body'      => $_POST['body']     ?? '',
            'category'  => trim($_POST['category'] ?? ''),
            'status'    => $_POST['status']   ?? $res['status'],
            'signed_by' => (int)($_POST['signed_by'] ?? 0) ?: null,
            'tags'      => trim($_POST['tags'] ?? ''),
        ];
        if (!$data['title']) $errors[] = 'Tytuł jest wymagany.';

        if (!$errors) {
            uchw_update($id, $data);
            if (!empty($_FILES['attachment']['tmp_name'])) {
                $err = uchw_upload($id, 'attachment');
                if ($err) $errors[] = 'Plik: ' . $err;
            }
            if (!$errors) {
                log_system_action((int)current_user()['id'], 'res_edit', "Edytowano dokument #$id");
                flash_set('success', 'Zapisano.');
                header('Location: ' . APP_URL . '/resolutions/view.php?id=' . $id); exit;
            }
        }
        $res = array_merge($res, $data);
    }
}

[$tlabel, $ticon, $tcolor] = uchw_type_label($res['type']);
[$slabel, $scolor]         = uchw_status_label($res['status']);

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/resolutions/index.php">Uchwały i Zarządzenia</a></li>
    <li class="breadcrumb-item active"><?= h($res['number'] ?: $res['title']) ?></li>
  </ol>
</nav>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div>
<?php endif; ?>
<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <span class="badge bg-<?= $tcolor ?> bg-opacity-15 text-<?= $tcolor ?> me-1">
            <i class="bi <?= $ticon ?> me-1"></i><?= $tlabel ?>
          </span>
          <span class="badge bg-<?= $scolor ?> <?= $scolor==='light'?'text-dark border':'' ?>"><?= $slabel ?></span>
        </div>
        <?php if ($can_edit): ?>
        <div class="d-flex gap-2">
          <!-- Szybka zmiana statusu -->
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="status">
            <select name="status" class="form-select form-select-sm" style="width:auto"
                    onchange="this.form.submit()">
              <option value="draft"    <?= $res['status']==='draft'   ?'selected':'' ?>>📝 Projekt</option>
              <option value="active"   <?= $res['status']==='active'  ?'selected':'' ?>>✅ Aktywna</option>
              <option value="archived" <?= $res['status']==='archived'?'selected':'' ?>>📦 Archiwum</option>
            </select>
          </form>
          <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#edit-form">
            <i class="bi bi-pencil me-1"></i>Edytuj
          </button>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <!-- Nagłówek dokumentu -->
        <div class="text-center mb-4 pb-3 border-bottom">
          <?php if ($res['number']): ?>
          <div class="font-monospace text-muted mb-1" style="font-size:.8rem"><?= h($res['number']) ?></div>
          <?php endif; ?>
          <h5 class="fw-bold mb-1"><?= h(strtoupper($tlabel)) ?></h5>
          <div class="fw-semibold" style="font-size:1rem">w sprawie: <?= h($res['title']) ?></div>
          <div class="text-muted mt-1" style="font-size:.83rem">
            z dnia <?= h(date_pl($res['date'])) ?>
            <?php if ($res['signer_name']): ?>
            · podpisał(a): <strong><?= h($res['signer_name']) ?></strong>
            <?php endif; ?>
          </div>
        </div>

        <!-- Treść -->
        <?php if ($res['body']): ?>
        <div class="res-body" style="font-size:.9rem;line-height:1.7">
          <?php
            $body = h($res['body']);
            $body = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $body);
            $body = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $body);
            $body = preg_replace('/^#{3} (.+)$/m', '<h6 class="mt-3">$1</h6>', $body);
            $body = preg_replace('/^#{2} (.+)$/m', '<h5 class="mt-3">$1</h5>', $body);
            $body = preg_replace('/^# (.+)$/m', '<h4 class="mt-3">$1</h4>', $body);
            $body = preg_replace('/^---+$/m', '<hr>', $body);
            $body = nl2br($body);
            echo $body;
          ?>
        </div>
        <?php else: ?>
        <div class="text-muted text-center py-3" style="font-size:.85rem">
          <i class="bi bi-file-text me-1"></i>Brak treści dokumentu.
        </div>
        <?php endif; ?>

        <!-- Formularz edycji (collapse) -->
        <?php if ($can_edit): ?>
        <div class="collapse mt-4" id="edit-form">
          <hr>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="edit">

            <div class="d-flex gap-2 mb-3 flex-wrap">
              <?php foreach (['uchwala'=>['Uchwała','primary'],'zarzadzenie'=>['Zarządzenie','warning'],'decyzja'=>['Decyzja','info']] as $v=>[$l,$c]): ?>
              <div>
                <input type="radio" class="btn-check" name="type" id="etype_<?= $v ?>"
                       value="<?= $v ?>" <?= $res['type']===$v?'checked':'' ?>>
                <label class="btn btn-outline-<?= $c ?> btn-sm" for="etype_<?= $v ?>"><?= $l ?></label>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-md-5">
                <label class="form-label form-label-sm fw-semibold">Numer</label>
                <input type="text" name="number" class="form-control form-control-sm" value="<?= h($res['number']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Data</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= h($res['date']) ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label form-label-sm fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                  <option value="draft"    <?= $res['status']==='draft'   ?'selected':'' ?>>Projekt</option>
                  <option value="active"   <?= $res['status']==='active'  ?'selected':'' ?>>Aktywna</option>
                  <option value="archived" <?= $res['status']==='archived'?'selected':'' ?>>Archiwum</option>
                </select>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label form-label-sm fw-semibold">Tytuł</label>
              <input type="text" name="title" class="form-control form-control-sm" value="<?= h($res['title']) ?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label form-label-sm fw-semibold">Treść</label>
              <textarea name="body" class="form-control form-control-sm" rows="12"
                        style="font-family:monospace;font-size:.82rem"><?= h($res['body']) ?></textarea>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Kategoria</label>
                <input type="text" name="category" class="form-control form-control-sm"
                       list="cat-list-e" value="<?= h($res['category']) ?>">
                <datalist id="cat-list-e">
                  <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
                </datalist>
              </div>
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Podpisał(a)</label>
                <select name="signed_by" class="form-select form-select-sm">
                  <option value="">— brak —</option>
                  <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= (int)$res['signed_by']===(int)$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Tagi</label>
                <input type="text" name="tags" class="form-control form-control-sm" value="<?= h($res['tags']) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label form-label-sm fw-semibold">Zastąp załącznik</label>
              <input type="file" name="attachment" class="form-control form-control-sm"
                     accept=".pdf,.doc,.docx,.odt,.png,.jpg,.jpeg,.zip">
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check me-1"></i>Zapisz</button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      data-bs-toggle="collapse" data-bs-target="#edit-form">Anuluj</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Panel boczny -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-info-circle me-1 text-primary"></i>Informacje
      </div>
      <div class="card-body" style="font-size:.83rem">
        <?php if ($res['category']): ?>
        <div class="mb-2"><span class="text-muted">Kategoria:</span>
          <span class="badge bg-light text-dark border"><?= h($res['category']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($res['tags']): ?>
        <div class="mb-2"><span class="text-muted">Tagi:</span> <?= h($res['tags']) ?></div>
        <?php endif; ?>
        <div class="mb-2"><span class="text-muted">Podpisał(a):</span> <?= h($res['signer_name'] ?? '—') ?></div>
        <div class="mb-2"><span class="text-muted">Dodał:</span> <?= h($res['creator_name'] ?? '—') ?></div>
        <div class="mb-2"><span class="text-muted">Utworzono:</span> <?= h(date_pl(substr($res['created_at'],0,10))) ?></div>
        <div><span class="text-muted">Zaktualizowano:</span> <?= h(date_pl(substr($res['updated_at'],0,10))) ?></div>
      </div>
    </div>

    <?php if ($res['attachment']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-paperclip me-1 text-primary"></i>Skan / Załącznik
      </div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/resolutions/download.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-download me-1"></i>Pobierz plik
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_edit): ?>
    <div class="card shadow-sm border-danger">
      <div class="card-body py-2">
        <form method="post" onsubmit="return confirm('Usunąć dokument? Operacji nie można cofnąć.')">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-trash3 me-1"></i>Usuń dokument
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
