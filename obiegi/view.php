<?php
/**
 * obiegi/view.php — Podgląd wniosku: ścieżka, oś czasu, panel decyzji.
 * Obsługuje POST: approve / reject / withdraw.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/obiegi.php';

require_login();
require_module_enabled('obiegi_enabled', 'Moduł Obiegi');

$u   = current_user();
$uid = (int)$u['id'];
$id  = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $note   = trim($_POST['note'] ?? '');
    try {
        $req = obiegi_request_get($id);
        if (!$req) throw new \RuntimeException('Nie znaleziono wniosku.');
        if ($action === 'approve') {
            if (!obiegi_can_act($req, $u)) throw new \RuntimeException('Brak uprawnień do decyzji na tym kroku.');
            obiegi_approve($id, $uid, $note);
            flash_set('success', 'Krok zatwierdzony.');
        } elseif ($action === 'reject') {
            if (!obiegi_can_act($req, $u)) throw new \RuntimeException('Brak uprawnień do decyzji na tym kroku.');
            if ($note === '') throw new \RuntimeException('Podaj powód odrzucenia.');
            obiegi_reject($id, $uid, $note);
            flash_set('success', 'Wniosek odrzucony.');
        } elseif ($action === 'withdraw') {
            obiegi_withdraw($id, $uid);
            flash_set('success', 'Wniosek wycofany.');
        } elseif ($action === 'add_file') {
            $is_owner = (int)$req['submitted_by'] === $uid;
            if (!$is_owner && !obiegi_can_act($req, $u) && !is_admin()) throw new \RuntimeException('Brak uprawnień do dodania pliku.');
            if (empty($_FILES['file']['name'])) throw new \RuntimeException('Nie wybrano pliku.');
            obiegi_add_upload($id, 'file', $uid);
            flash_set('success', 'Dodano plik.');
        } elseif ($action === 'del_file') {
            $fid = (int)($_POST['file_id'] ?? 0);
            $f = obiegi_file_get($fid);
            if (!$f || (int)$f['request_id'] !== $id) throw new \RuntimeException('Nie znaleziono pliku.');
            if ((int)$f['uploaded_by'] !== $uid && !is_admin()) throw new \RuntimeException('Można usunąć tylko własny plik.');
            obiegi_file_delete($fid);
            flash_set('success', 'Usunięto plik.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/obiegi/view.php?id=' . $id); exit;
}

$req = obiegi_request_get($id);
if (!$req) {
    http_response_code(404);
    $PAGE_TITLE = 'Nie znaleziono';
    include dirname(__DIR__) . '/includes/header.php';
    echo '<div class="container my-5"><div class="alert alert-danger">Nie znaleziono wniosku.</div></div>';
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$steps    = obiegi_def_steps((int)$req['definition_id']);
$curOrd   = (int)$req['current_step_order'];
$actions  = obiegi_request_actions($id);
$can_act  = obiegi_can_act($req, $u);
$is_owner = (int)$req['submitted_by'] === $uid;
$files    = obiegi_files($id);
$sprawa   = obiegi_request_sprawa($req);
$can_add_file = $is_owner || $can_act || is_admin();

$PAGE_TITLE = 'Wniosek: ' . $req['title'];
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:900px">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi <?= h($req['def_icon'] ?: 'bi-diagram-2') ?> me-2"></i><?= h($req['title']) ?></h1>
    <a href="<?= APP_URL ?>/obiegi/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Wróć</a>
  </div>
  <?= flash_html() ?>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div>
              <div class="small text-muted"><?= h($req['def_name']) ?></div>
              <div>Status: <?= obieg_status_badge($req['status']) ?></div>
            </div>
            <div class="small text-muted text-end">
              Wnioskodawca: <strong><?= h($req['submitter_name'] ?? '—') ?></strong><br>
              Złożono: <?= h(substr((string)$req['submitted_at'], 0, 16)) ?>
            </div>
          </div>
          <?php if ($sprawa): ?>
            <hr>
            <div class="small">
              <i class="bi bi-folder2-open me-1 text-muted"></i>Koszulka EZD:
              <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$sprawa['id'] ?>">
                <?= h($sprawa['znak_sprawy']) ?> — <?= h($sprawa['title']) ?>
              </a>
            </div>
          <?php elseif ((int)($req['ezd_sprawa_id'] ?? 0) > 0): ?>
            <hr><div class="small text-muted"><i class="bi bi-folder2 me-1"></i>Koszulka EZD #<?= (int)$req['ezd_sprawa_id'] ?> (brak dostępu lub usunięta)</div>
          <?php endif; ?>
          <?php if (trim((string)$req['body']) !== ''): ?>
            <hr><div style="white-space:pre-wrap"><?= h($req['body']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Załączniki -->
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-paperclip me-1"></i>Załączniki (<?= count($files) ?>)</div>
        <ul class="list-group list-group-flush">
          <?php if (!$files): ?>
            <li class="list-group-item text-muted small">Brak załączników.</li>
          <?php endif; ?>
          <?php foreach ($files as $f): ?>
            <li class="list-group-item d-flex align-items-center gap-2">
              <i class="bi bi-file-earmark text-muted"></i>
              <a href="<?= APP_URL ?>/obiegi/file.php?id=<?= (int)$f['id'] ?>" target="_blank"><?= h($f['original_name']) ?></a>
              <?php if ($f['source'] === 'ezd'): ?><span class="badge bg-info-subtle text-info-emphasis border border-info small">z koszulki</span><?php endif; ?>
              <span class="text-muted small ms-1"><?= h(function_exists('ezd_filesize') ? ezd_filesize((int)$f['file_size']) : ((int)$f['file_size'] . ' B')) ?></span>
              <a href="<?= APP_URL ?>/obiegi/file.php?id=<?= (int)$f['id'] ?>&dl=1" class="btn btn-sm btn-outline-secondary ms-auto py-0" title="Pobierz"><i class="bi bi-download"></i></a>
              <?php if (((int)$f['uploaded_by'] === $uid || is_admin()) && $req['status'] === 'w_toku'): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć plik?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                  <button name="action" value="del_file" class="btn btn-sm btn-outline-danger py-0" title="Usuń"><i class="bi bi-trash3"></i></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($can_add_file && $req['status'] === 'w_toku'): ?>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="file" name="file" class="form-control form-control-sm" required>
            <button name="action" value="add_file" class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-upload me-1"></i>Dodaj</button>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <!-- Ścieżka kroków -->
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold">Ścieżka zatwierdzania</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($steps as $i => $s): $ord = $i + 1;
            if ($req['status'] === 'zatwierdzony') { $state = 'done'; }
            elseif ($req['status'] === 'w_toku') { $state = $ord < $curOrd ? 'done' : ($ord === $curOrd ? 'current' : 'pending'); }
            elseif ($req['status'] === 'odrzucony') { $state = $ord < $curOrd ? 'done' : ($ord === $curOrd ? 'rejected' : 'pending'); }
            else { $state = $ord < $curOrd ? 'done' : 'pending'; }
            $ic = ['done'=>'bi-check-circle-fill text-success','current'=>'bi-hourglass-split text-warning','rejected'=>'bi-x-circle-fill text-danger','pending'=>'bi-circle text-muted'][$state];
          ?>
            <li class="list-group-item d-flex align-items-center<?= $state === 'current' ? ' bg-warning-subtle' : '' ?>">
              <i class="bi <?= $ic ?> me-2"></i>
              <span class="me-2 text-muted small"><?= $ord ?>.</span>
              <span><?= h($s['name']) ?></span>
              <span class="badge bg-light text-dark ms-2"><?= h(obiegi_step_assignee_label($s)) ?></span>
              <?php if ($state === 'current'): ?><span class="badge bg-warning text-dark ms-auto">tutaj</span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="col-lg-5">
      <!-- Panel decyzji -->
      <?php if ($can_act): ?>
        <div class="card shadow-sm mb-3 border-warning">
          <div class="card-header bg-warning-subtle fw-semibold"><i class="bi bi-hand-index me-1"></i>Twoja decyzja</div>
          <div class="card-body">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <div class="mb-2">
                <label class="form-label small mb-1">Uwagi (wymagane przy odrzuceniu)</label>
                <textarea name="note" class="form-control form-control-sm" rows="3"></textarea>
              </div>
              <div class="d-flex gap-2">
                <button name="action" value="approve" class="btn btn-success btn-sm flex-fill"><i class="bi bi-check-lg me-1"></i>Zatwierdź</button>
                <button name="action" value="reject" class="btn btn-danger btn-sm flex-fill"
                        onclick="return this.form.note.value.trim() || (alert('Podaj powód odrzucenia.'), false)">
                  <i class="bi bi-x-lg me-1"></i>Odrzuć
                </button>
              </div>
            </form>
          </div>
        </div>
      <?php elseif ($req['status'] === 'w_toku'): ?>
        <div class="alert alert-secondary small"><i class="bi bi-hourglass me-1"></i>Wniosek oczekuje na decyzję roli bieżącego kroku.</div>
      <?php endif; ?>

      <?php if ($is_owner && $req['status'] === 'w_toku'): ?>
        <form method="post" class="mb-3" onsubmit="return confirm('Wycofać wniosek?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <button name="action" value="withdraw" class="btn btn-outline-secondary btn-sm w-100">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Wycofaj wniosek
          </button>
        </form>
      <?php endif; ?>

      <!-- Oś czasu -->
      <div class="card shadow-sm">
        <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Historia</div>
        <div class="card-body">
          <ul class="list-unstyled mb-0 small">
            <?php foreach ($actions as $a): [$lbl, $ic, $cls] = obiegi_action_label($a['action']); ?>
              <li class="d-flex mb-3">
                <i class="bi <?= $ic ?> text-<?= $cls ?> me-2 mt-1"></i>
                <div>
                  <div><strong><?= h($lbl) ?></strong><?php if ($a['step_name']): ?> — <?= h($a['step_name']) ?><?php endif; ?></div>
                  <div class="text-muted"><?= h($a['actor_name'] ?? 'system') ?> · <?= h(substr((string)$a['created_at'], 0, 16)) ?></div>
                  <?php if (trim((string)$a['note']) !== ''): ?><div class="mt-1 p-2 bg-light rounded" style="white-space:pre-wrap"><?= h($a['note']) ?></div><?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
