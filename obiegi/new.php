<?php
/**
 * obiegi/new.php — Złożenie nowego wniosku (start obiegu).
 * Obsługuje uruchomienie z EZD: ?ezd_sprawa_id=&ezd_zalacznik_id= (plik z koszulki).
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

$ezd_on       = module_enabled('ezd_enabled');
$ezd_zal_id   = (int)($_GET['ezd_zalacznik_id'] ?? ($_POST['ezd_zalacznik_id'] ?? 0));
$launch_zal   = null;
if ($ezd_on && $ezd_zal_id) {
    require_once dirname(__DIR__) . '/includes/ezd.php';
    $launch_zal = ezd_zal_get($ezd_zal_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $def_id  = (int)($_POST['definition_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $spr_id  = (int)($_POST['ezd_sprawa_id'] ?? 0);
        if ($title === '') throw new \RuntimeException('Podaj tytuł wniosku.');
        $rid = obiegi_request_start($def_id, $title, $body, $uid, $spr_id ?: null);

        // Plik z koszulki EZD (uruchomienie z EZD)
        if ($ezd_zal_id) {
            try { obiegi_add_ezd_file($rid, $ezd_zal_id, $uid); } catch (\Throwable $e) {}
        }
        // Wgrany plik
        if (!empty($_FILES['file']['name'])) {
            obiegi_add_upload($rid, 'file', $uid);
        }
        flash_set('success', 'Wniosek złożony i przekazany do pierwszego kroku.');
        header('Location: ' . APP_URL . '/obiegi/view.php?id=' . $rid); exit;
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
}

$defs      = obiegi_definitions(true);
$sel_def   = (int)($_GET['definition_id'] ?? ($_POST['definition_id'] ?? 0));
$sel_spr   = (int)($_GET['ezd_sprawa_id'] ?? ($_POST['ezd_sprawa_id'] ?? ($launch_zal['sprawa_id'] ?? 0)));
$sprawy    = ($ezd_on) ? ezd_sprawy_all(['status' => 'open'], $uid) : [];
// Zapewnij, że wskazana koszulka (np. z uruchomienia z EZD) jest na liście.
if ($ezd_on && $sel_spr && !in_array($sel_spr, array_map(fn($s) => (int)$s['id'], $sprawy), true)) {
    $extra = ezd_sprawa_get($sel_spr);
    if ($extra) array_unshift($sprawy, $extra);
}
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
  <?php if ($launch_zal): ?>
    <div class="alert alert-primary d-flex align-items-center">
      <i class="bi bi-paperclip me-2"></i>
      <div>Do wniosku zostanie dołączony plik z koszulki: <strong><?= h($launch_zal['original_name']) ?></strong></div>
    </div>
  <?php endif; ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($ezd_zal_id): ?><input type="hidden" name="ezd_zalacznik_id" value="<?= $ezd_zal_id ?>"><?php endif; ?>

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
                  <li><?= h($s['name']) ?> <span class="text-muted">(<?= h(obiegi_step_assignee_label($s)) ?>)</span></li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="mb-3">
          <label class="form-label">Tytuł wniosku</label>
          <input name="title" class="form-control" required maxlength="200" value="<?= h($_POST['title'] ?? ($launch_zal['original_name'] ?? '')) ?>" placeholder="Krótki opis, np. Urlop 10–14 lipca">
        </div>
        <div class="mb-3">
          <label class="form-label">Treść / uzasadnienie</label>
          <textarea name="body" class="form-control" rows="5"><?= h($_POST['body'] ?? '') ?></textarea>
        </div>

        <?php if ($ezd_on): ?>
        <div class="mb-3">
          <label class="form-label">Koszulka EZD <span class="text-muted small">(opcjonalnie)</span></label>
          <select name="ezd_sprawa_id" class="form-select">
            <option value="">— brak powiązania —</option>
            <?php foreach ($sprawy as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sel_spr === (int)$s['id'] ? 'selected' : '' ?>>
                <?= h($s['znak_sprawy']) ?> — <?= h($s['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Powiąż wniosek ze sprawą (koszulką) w Wirtualnym biurku.</div>
        </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label">Załącznik <span class="text-muted small">(opcjonalnie, maks. 20 MB)</span></label>
          <input type="file" name="file" class="form-control">
          <div class="form-text">Dozwolone: PDF, DOC(X), XLS(X), ODT, JPG, PNG, TXT.</div>
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
