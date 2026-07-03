<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/correspondence.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
$ezd_enabled = module_enabled('ezd_enabled');
require_module_enabled('correspondence_enabled', 'Moduł Korespondencja');

$id  = (int)($_GET['id'] ?? 0);
$it  = corr_get($id);
if (!$it) { flash_set('error', 'Nie znaleziono.'); header('Location: ' . APP_URL . '/correspondence/index.php'); exit; }

$PAGE_TITLE = 'Korespondencja: ' . $it['subject'];
$errors  = [];
$users   = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
$cats    = corr_categories();
$can_edit = can_write('correspondence');

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_check();
    $action = $_POST['action'] ?? 'edit';

    if ($action === 'delete') {
        corr_delete($id);
        log_system_action((int)current_user()['id'], 'corr_delete', "Usunięto korespondencję #$id");
        flash_set('success', 'Korespondencja usunięta.');
        header('Location: ' . APP_URL . '/correspondence/index.php'); exit;
    }

    if ($action === 'register_ezd' && $ezd_enabled) {
        $sprawa_id = (int)($_POST['sprawa_id'] ?? 0);
        if (!$sprawa_id) { flash_set('error', 'Wybierz sprawę EZD.'); }
        else {
            try {
                require_once dirname(__DIR__) . '/includes/ezd.php';
                $pismo_id = corr_register_in_ezd($id, $sprawa_id, (int)current_user()['id']);
                log_system_action((int)current_user()['id'], 'corr_ezd_link', "Korespondencja #$id → EZD pismo #$pismo_id");
                flash_set('success', 'Zarejestrowano w EZD.');
            } catch (\Throwable $e) {
                flash_set('error', 'Błąd: ' . $e->getMessage());
            }
        }
        header('Location: ' . APP_URL . '/correspondence/view.php?id=' . $id); exit;
    }

    if ($action === 'unlink_ezd') {
        corr_unlink_ezd($id);
        flash_set('success', 'Odłączono od EZD.');
        header('Location: ' . APP_URL . '/correspondence/view.php?id=' . $id); exit;
    }

    if ($action === 'edit') {
        $data = [
            'direction'    => $_POST['direction']    ?? $it['direction'],
            'number'       => trim($_POST['number']  ?? ''),
            'date'         => $_POST['date']         ?? $it['date'],
            'correspondent'=> trim($_POST['correspondent'] ?? ''),
            'subject'      => trim($_POST['subject'] ?? ''),
            'description'  => $_POST['description']  ?? '',
            'category'     => trim($_POST['category'] ?? ''),
            'status'       => $_POST['status']       ?? $it['status'],
            'handled_by'   => (int)($_POST['handled_by'] ?? 0) ?: null,
            'medium'       => array_key_exists($_POST['medium'] ?? '', CORR_MEDIA) ? $_POST['medium'] : $it['medium'],
        ];
        if (!$data['subject'])       $errors[] = 'Temat jest wymagany.';
        if (!$data['correspondent']) $errors[] = 'Nadawca/Odbiorca jest wymagany.';

        if (!$errors) {
            corr_update($id, $data);
            if (!empty($_FILES['attachment']['tmp_name'])) {
                $err = corr_upload($id, 'attachment', (int)current_user()['id']);
                if ($err) $errors[] = 'Plik: ' . $err;
            }
            if (!$errors) {
                log_system_action((int)current_user()['id'], 'corr_edit', "Edytowano korespondencję #$id");
                flash_set('success', 'Zapisano.');
                header('Location: ' . APP_URL . '/correspondence/view.php?id=' . $id); exit;
            }
        }
        $it = array_merge($it, $data);
    }
}

[$dlabel, $dicon, $dcolor] = corr_direction_label($it['direction']);
[$slabel, $scolor]         = corr_status_label($it['status']);

// EZD
$linked_pismo = null;
$ezd_sprawy   = [];
if ($ezd_enabled) {
    require_once dirname(__DIR__) . '/includes/ezd.php';
    $linked_pismo = corr_get_ezd_pismo($id);
    if (!$linked_pismo) {
        $ezd_sprawy = db_all(
            "SELECT s.id, s.znak_sprawy, s.title, t.title AS teczka_title
             FROM ezd_sprawy s JOIN ezd_teczki t ON t.id=s.teczka_id
             WHERE s.status='open' ORDER BY s.znak_sprawy DESC LIMIT 100"
        );
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/correspondence/index.php">Korespondencja</a></li>
    <li class="breadcrumb-item active"><?= h($it['subject']) ?></li>
  </ol>
</nav>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div>
<?php endif; ?>
<?= flash_html() ?>

<div class="row g-4">
  <!-- Formularz edycji -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
          <i class="bi <?= $dicon ?> text-<?= $dcolor ?> me-1"></i>
          <?= $dlabel ?> · <span class="badge bg-<?= $scolor ?> bg-opacity-15 text-<?= $scolor ?>"><?= $slabel ?></span>
          <?php $medium = CORR_MEDIA[$it['medium'] ?? 'papier'] ?? CORR_MEDIA['papier']; ?>
          <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.7rem"><i class="bi <?= $medium['icon'] ?> me-1"></i><?= h($medium['label']) ?></span>
        </span>
        <?php if ($can_edit): ?>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#edit-form">
          <i class="bi bi-pencil me-1"></i>Edytuj
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body">

        <!-- Podgląd -->
        <div id="view-mode">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;font-weight:600">Numer</div>
              <div class="font-monospace"><?= h($it['number'] ?: '—') ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;font-weight:600">Data</div>
              <div><?= h(date_pl($it['date'])) ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;font-weight:600">Kategoria</div>
              <div><?= $it['category'] ? h($it['category']) : '—' ?></div>
            </div>
          </div>
          <div class="mb-3">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;font-weight:600">Korespondent</div>
            <div class="fw-semibold"><?= h($it['correspondent']) ?></div>
          </div>
          <div class="mb-3">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;font-weight:600">Temat</div>
            <div class="fw-semibold fs-6"><?= h($it['subject']) ?></div>
          </div>
          <?php if ($it['description']): ?>
          <div class="border rounded p-3" style="background:#fafafa;font-size:.9rem">
            <?php
              // Prosty Markdown → HTML bez zewnętrznej biblioteki
              $desc = h($it['description']);
              $desc = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $desc);
              $desc = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $desc);
              $desc = preg_replace('/^#{3} (.+)$/m', '<h6>$1</h6>', $desc);
              $desc = preg_replace('/^#{2} (.+)$/m', '<h5>$1</h5>', $desc);
              $desc = preg_replace('/^# (.+)$/m', '<h4>$1</h4>', $desc);
              $desc = nl2br($desc);
              echo $desc;
            ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Formularz edycji (collapse) -->
        <?php if ($can_edit): ?>
        <div class="collapse mt-3" id="edit-form">
          <hr>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="edit">

            <div class="d-flex gap-3 mb-3">
              <?php foreach (['incoming'=>['Przychodząca','success'],'outgoing'=>['Wychodząca','primary']] as $v=>[$l,$c]): ?>
              <div class="flex-fill">
                <input type="radio" class="btn-check" name="direction" id="edr_<?= $v ?>"
                       value="<?= $v ?>" <?= $it['direction']===$v?'checked':'' ?>>
                <label class="btn btn-outline-<?= $c ?> w-100 btn-sm" for="edr_<?= $v ?>"><?= $l ?></label>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Numer</label>
                <input type="text" name="number" class="form-control form-control-sm" value="<?= h($it['number']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Data</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= h($it['date']) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                  <?php foreach (['new'=>'Nowa','in_progress'=>'W trakcie','replied'=>'Odpowiedziano','closed'=>'Zamknięta','archived'=>'Archiwum'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= $it['status']===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label form-label-sm fw-semibold">Rodzaj medium</label>
              <select name="medium" class="form-select form-select-sm" style="max-width:260px">
                <?php foreach (CORR_MEDIA as $mv=>$ml): ?>
                <option value="<?= $mv ?>" <?= ($it['medium']??'papier')===$mv?'selected':'' ?>><?= h($ml['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label form-label-sm fw-semibold">Nadawca/Odbiorca</label>
              <input type="text" name="correspondent" class="form-control form-control-sm" value="<?= h($it['correspondent']) ?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label form-label-sm fw-semibold">Temat</label>
              <input type="text" name="subject" class="form-control form-control-sm" value="<?= h($it['subject']) ?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label form-label-sm fw-semibold">Opis / Treść</label>
              <textarea name="description" class="form-control form-control-sm" rows="8"
                        style="font-family:monospace;font-size:.82rem"><?= h($it['description']) ?></textarea>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-md-6">
                <label class="form-label form-label-sm fw-semibold">Kategoria</label>
                <input type="text" name="category" class="form-control form-control-sm"
                       list="cat-list-e" value="<?= h($it['category']) ?>">
                <datalist id="cat-list-e">
                  <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
                </datalist>
              </div>
              <div class="col-md-6">
                <label class="form-label form-label-sm fw-semibold">Prowadzi</label>
                <select name="handled_by" class="form-select form-select-sm">
                  <option value="">— brak —</option>
                  <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= (int)$it['handled_by']===(int)$u['id']?'selected':'' ?>><?= h($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label form-label-sm fw-semibold">Zastąp załącznik</label>
              <input type="file" name="attachment" class="form-control form-control-sm"
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.png,.jpg,.jpeg,.eml,.msg,.zip,.txt">
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
    <!-- Metadane -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-info-circle me-1 text-primary"></i>Informacje
      </div>
      <div class="card-body" style="font-size:.83rem">
        <div class="mb-2"><span class="text-muted">Prowadzi:</span> <?= h($it['handler_name'] ?? '—') ?></div>
        <div class="mb-2"><span class="text-muted">Dodał:</span> <?= h($it['creator_name'] ?? '—') ?></div>
        <div class="mb-2"><span class="text-muted">Utworzono:</span> <?= h(date_pl(substr($it['created_at'],0,10))) ?></div>
        <div><span class="text-muted">Zaktualizowano:</span> <?= h(date_pl(substr($it['updated_at'],0,10))) ?></div>
      </div>
    </div>

    <!-- Załącznik -->
    <?php if ($it['attachment']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-paperclip me-1 text-primary"></i>Załącznik
      </div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/correspondence/download.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-download me-1"></i>Pobierz plik
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- EZD -->
    <?php if ($ezd_enabled): ?>
    <div class="card shadow-sm mb-3 <?= $linked_pismo ? 'border-success' : '' ?>">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-building-gear me-1 text-<?= $linked_pismo ? 'success' : 'primary' ?>"></i>
        Kancelaria EZD
        <?php if ($linked_pismo): ?>
        <span class="badge bg-success ms-1" style="font-size:.65rem">Połączone</span>
        <?php endif; ?>
      </div>
      <div class="card-body" style="font-size:.83rem">
        <?php if ($linked_pismo): ?>
        <div class="mb-2">
          <div class="text-muted mb-1">Sprawa:</div>
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $linked_pismo['sprawa_id'] ?>"
             class="fw-semibold font-monospace text-decoration-none">
            <?= h($linked_pismo['znak_sprawy']) ?>
          </a>
          <div class="text-muted" style="font-size:.75rem"><?= h($linked_pismo['sprawa_title']) ?></div>
        </div>
        <div class="mb-3">
          <div class="text-muted mb-1">Pismo:</div>
          <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $linked_pismo['id'] ?>"
             class="text-decoration-none">
            <?= h($linked_pismo['sygnatura']) ?>
          </a>
        </div>
        <?php if ($can_edit): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="unlink_ezd">
          <button type="submit" class="btn btn-outline-secondary btn-sm w-100"
                  onclick="return confirm('Odłączyć od EZD?')">
            <i class="bi bi-scissors me-1"></i>Odłącz od EZD
          </button>
        </form>
        <?php endif; ?>

        <?php elseif ($can_edit && $ezd_sprawy): ?>
        <div class="text-muted mb-2" style="font-size:.78rem">
          Zarejestruj tę korespondencję jako pismo w wybranej sprawie EZD:
        </div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="register_ezd">
          <select name="sprawa_id" class="form-select form-select-sm mb-2" required>
            <option value="">— wybierz sprawę —</option>
            <?php foreach ($ezd_sprawy as $s): ?>
            <option value="<?= $s['id'] ?>">
              <?= h($s['znak_sprawy']) ?> — <?= h(mb_substr($s['title'],0,40)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline-success btn-sm w-100">
            <i class="bi bi-building-gear me-1"></i>Zarejestruj w EZD
          </button>
        </form>

        <?php elseif (!$ezd_sprawy && !$linked_pismo): ?>
        <div class="text-muted" style="font-size:.78rem">
          Brak otwartych spraw EZD.
          <a href="<?= APP_URL ?>/ezd/sprawy/add.php">Utwórz sprawę</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Akcje -->
    <?php if ($can_edit): ?>
    <div class="card shadow-sm border-danger">
      <div class="card-body py-2">
        <form method="post" onsubmit="return confirm('Usunąć tę korespondencję? Operacji nie można cofnąć.')">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="action"  value="delete">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-trash3 me-1"></i>Usuń korespondencję
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
