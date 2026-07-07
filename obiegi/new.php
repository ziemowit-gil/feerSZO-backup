<?php
/**
 * obiegi/new.php — Złożenie nowego wniosku (start obiegu).
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $def_id = (int)($_POST['definition_id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $body   = trim($_POST['body'] ?? '');
        if ($title === '') throw new \RuntimeException('Podaj tytuł wniosku.');
        $rid = obiegi_request_start($def_id, $title, $body, $uid);
        flash_set('success', 'Wniosek złożony i przekazany do pierwszego kroku.');
        header('Location: ' . APP_URL . '/obiegi/view.php?id=' . $rid); exit;
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
}

$defs      = obiegi_definitions(true);
$sel_def   = (int)($_GET['definition_id'] ?? ($_POST['definition_id'] ?? 0));
$PAGE_TITLE = 'Nowy wniosek — obieg';

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:720px">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-plus-circle me-2"></i>Nowy wniosek</h1>
    <a href="<?= APP_URL ?>/obiegi/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Wróć</a>
  </div>
  <?= flash_html() ?>

  <?php if (!$defs): ?>
    <div class="alert alert-info">Brak aktywnych typów obiegów.</div>
  <?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Typ obiegu</label>
          <select name="definition_id" id="defSelect" class="form-select" required>
            <option value="">— wybierz —</option>
            <?php foreach ($defs as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= $sel_def === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php foreach ($defs as $d): $st = obiegi_def_steps((int)$d['id']); ?>
          <div class="obieg-path alert alert-light border small<?= $sel_def === (int)$d['id'] ? '' : ' d-none' ?>" data-def="<?= (int)$d['id'] ?>">
            <?php if ($d['description']): ?><div class="mb-2 text-muted"><?= nl2br(h($d['description'])) ?></div><?php endif; ?>
            <div class="fw-semibold mb-1"><i class="bi bi-signpost-split me-1"></i>Ścieżka zatwierdzania:</div>
            <?php if (!$st): ?>
              <span class="text-danger">Ten obieg nie ma kroków — nie można złożyć wniosku.</span>
            <?php else: ?>
              <ol class="mb-0 ps-3">
                <?php foreach ($st as $s): ?>
                  <li><?= h($s['name']) ?> <span class="text-muted">(<?= h($s['role_name']) ?>)</span></li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="mb-3">
          <label class="form-label">Tytuł wniosku</label>
          <input name="title" class="form-control" required maxlength="200" value="<?= h($_POST['title'] ?? '') ?>" placeholder="Krótki opis, np. Urlop 10–14 lipca">
        </div>
        <div class="mb-3">
          <label class="form-label">Treść / uzasadnienie</label>
          <textarea name="body" class="form-control" rows="5"><?= h($_POST['body'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Złóż wniosek</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
(function(){
  var sel = document.getElementById('defSelect');
  if (!sel) return;
  function sync(){
    document.querySelectorAll('.obieg-path').forEach(function(el){
      el.classList.toggle('d-none', el.getAttribute('data-def') !== sel.value);
    });
  }
  sel.addEventListener('change', sync);
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
