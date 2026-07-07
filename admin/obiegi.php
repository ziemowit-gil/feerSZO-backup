<?php
/**
 * admin/obiegi.php — Edytor typów obiegów (micro-BPM).
 * Master-detail: lista definicji + edytor kroków (każdy krok = rola).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/obiegi.php';

require_role('admin');
require_module_enabled('obiegi_enabled', 'Moduł Obiegi (micro-BPM)');

$PAGE_TITLE = 'Obiegi — definicje procesów';
$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $id = obiegi_def_create(trim($_POST['name'] ?? ''), trim($_POST['description'] ?? ''), trim($_POST['icon'] ?? ''), $uid);
            flash_set('success', 'Utworzono typ obiegu. Dodaj teraz kroki.');
            header('Location: obiegi.php?id=' . $id); exit;
        }
        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            obiegi_def_update($id, trim($_POST['name'] ?? ''), trim($_POST['description'] ?? ''), trim($_POST['icon'] ?? ''), (int)!empty($_POST['is_active']));
            flash_set('success', 'Zapisano ustawienia obiegu.');
            header('Location: obiegi.php?id=' . $id); exit;
        }
        if ($action === 'save_steps') {
            $id = (int)($_POST['id'] ?? 0);
            $names = $_POST['step_name'] ?? [];
            $roles = $_POST['step_role'] ?? [];
            $steps = [];
            foreach ($names as $i => $n) {
                $steps[] = ['name' => (string)$n, 'role_name' => (string)($roles[$i] ?? '')];
            }
            obiegi_def_save_steps($id, $steps);
            flash_set('success', 'Zapisano kroki obiegu.');
            header('Location: obiegi.php?id=' . $id); exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            obiegi_def_delete($id);
            flash_set('success', 'Usunięto typ obiegu.');
            header('Location: obiegi.php'); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
        header('Location: obiegi.php' . (!empty($_POST['id']) ? '?id=' . (int)$_POST['id'] : '')); exit;
    }
}

$defs  = obiegi_definitions(false);
$sel_id = (int)($_GET['id'] ?? 0);
$sel    = $sel_id ? obiegi_definition($sel_id) : null;
$steps  = $sel ? obiegi_def_steps($sel_id) : [];
$roles  = roles_all();

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid my-4" style="max-width:1200px">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-diagram-2 me-2"></i>Obiegi — definicje procesów</h1>
    <a href="<?= APP_URL ?>/obiegi/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-up-right me-1"></i>Przejdź do obiegów
    </a>
  </div>
  <?= flash_html() ?>

  <div class="row g-3">
    <!-- Lista definicji -->
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Typy obiegów</span>
          <span class="badge bg-secondary"><?= count($defs) ?></span>
        </div>
        <div class="list-group list-group-flush">
          <?php if (!$defs): ?>
            <div class="list-group-item text-muted small">Brak zdefiniowanych obiegów.</div>
          <?php endif; ?>
          <?php foreach ($defs as $d): ?>
            <a href="?id=<?= (int)$d['id'] ?>"
               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= $sel_id === (int)$d['id'] ? ' active' : '' ?>">
              <span><i class="bi <?= h($d['icon'] ?: 'bi-diagram-2') ?> me-2"></i><?= h($d['name']) ?></span>
              <span>
                <?php if (!$d['is_active']): ?><span class="badge bg-secondary me-1">wył.</span><?php endif; ?>
                <span class="badge bg-<?= $sel_id === (int)$d['id'] ? 'light text-dark' : 'primary' ?>"><?= (int)$d['step_count'] ?> kr.</span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="card-body">
          <button class="btn btn-sm btn-primary w-100" data-bs-toggle="collapse" data-bs-target="#newDefForm">
            <i class="bi bi-plus-lg me-1"></i>Nowy typ obiegu
          </button>
          <div class="collapse mt-3" id="newDefForm">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <div class="mb-2">
                <label class="form-label small mb-1">Nazwa</label>
                <input name="name" class="form-control form-control-sm" required placeholder="np. Wniosek urlopowy">
              </div>
              <div class="mb-2">
                <label class="form-label small mb-1">Opis</label>
                <textarea name="description" class="form-control form-control-sm" rows="2"></textarea>
              </div>
              <div class="mb-2">
                <label class="form-label small mb-1">Ikona (Bootstrap Icons)</label>
                <input name="icon" class="form-control form-control-sm" value="bi-diagram-2" placeholder="bi-diagram-2">
              </div>
              <button class="btn btn-sm btn-success w-100"><i class="bi bi-check-lg me-1"></i>Utwórz</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Edytor definicji -->
    <div class="col-lg-8">
      <?php if (!$sel): ?>
        <div class="card shadow-sm"><div class="card-body text-muted">
          <i class="bi bi-arrow-left me-1"></i>Wybierz typ obiegu z listy albo utwórz nowy.
        </div></div>
      <?php else: ?>
        <div class="card shadow-sm mb-3">
          <div class="card-header fw-semibold">Ustawienia obiegu</div>
          <div class="card-body">
            <form method="post" class="row g-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $sel_id ?>">
              <div class="col-md-6">
                <label class="form-label small mb-1">Nazwa</label>
                <input name="name" class="form-control form-control-sm" required value="<?= h($sel['name']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label small mb-1">Ikona</label>
                <input name="icon" class="form-control form-control-sm" value="<?= h($sel['icon']) ?>">
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                  <input type="checkbox" class="form-check-input" id="isActive" name="is_active" value="1" <?= $sel['is_active'] ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="isActive">Aktywny</label>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label small mb-1">Opis</label>
                <textarea name="description" class="form-control form-control-sm" rows="2"><?= h($sel['description']) ?></textarea>
              </div>
              <div class="col-12 d-flex justify-content-between">
                <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
                <button class="btn btn-sm btn-outline-danger" formaction="obiegi.php" name="action" value="delete"
                        onclick="return confirm('Usunąć ten typ obiegu wraz z krokami? Istniejące wnioski pozostaną, ale bez definicji.')">
                  <i class="bi bi-trash me-1"></i>Usuń
                </button>
              </div>
            </form>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            Kroki obiegu (kolejność = ścieżka zatwierdzania)
          </div>
          <div class="card-body">
            <?php if (!$roles): ?>
              <div class="alert alert-warning small">Brak zdefiniowanych ról. Dodaj role w panelu ról, aby przypisać je do kroków.</div>
            <?php endif; ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_steps">
              <input type="hidden" name="id" value="<?= $sel_id ?>">
              <div id="stepsWrap">
                <?php
                $render_rows = $steps ?: [];
                if (!$render_rows) $render_rows = [['name' => '', 'role_name' => '']];
                foreach ($render_rows as $i => $st): ?>
                  <div class="step-row d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-secondary step-num"><?= $i + 1 ?></span>
                    <input name="step_name[]" class="form-control form-control-sm" placeholder="Nazwa kroku (np. Akceptacja przełożonego)" value="<?= h($st['name']) ?>">
                    <select name="step_role[]" class="form-select form-select-sm" style="max-width:260px">
                      <option value="">— rola zatwierdzająca —</option>
                      <?php foreach ($roles as $r): ?>
                        <option value="<?= h($r['name']) ?>" <?= ($st['role_name'] ?? '') === $r['name'] ? 'selected' : '' ?>>
                          <?= h($r['display_name'] ?: $r['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-danger step-del" title="Usuń krok"><i class="bi bi-x-lg"></i></button>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="addStep">
                <i class="bi bi-plus-lg me-1"></i>Dodaj krok
              </button>
              <hr>
              <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz kroki</button>
              <span class="text-muted small ms-2">Puste kroki są pomijane przy zapisie.</span>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<template id="stepRowTpl">
  <div class="step-row d-flex align-items-center gap-2 mb-2">
    <span class="badge bg-secondary step-num">?</span>
    <input name="step_name[]" class="form-control form-control-sm" placeholder="Nazwa kroku">
    <select name="step_role[]" class="form-select form-select-sm" style="max-width:260px">
      <option value="">— rola zatwierdzająca —</option>
      <?php foreach ($roles as $r): ?>
        <option value="<?= h($r['name']) ?>"><?= h($r['display_name'] ?: $r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-danger step-del" title="Usuń krok"><i class="bi bi-x-lg"></i></button>
  </div>
</template>
<script>
(function(){
  var wrap = document.getElementById('stepsWrap');
  if (!wrap) return;
  function renum(){
    wrap.querySelectorAll('.step-num').forEach(function(b,i){ b.textContent = i+1; });
  }
  document.getElementById('addStep').addEventListener('click', function(){
    var tpl = document.getElementById('stepRowTpl');
    wrap.appendChild(tpl.content.cloneNode(true));
    renum();
  });
  wrap.addEventListener('click', function(e){
    var btn = e.target.closest('.step-del');
    if (!btn) return;
    var rows = wrap.querySelectorAll('.step-row');
    if (rows.length <= 1) { btn.closest('.step-row').querySelectorAll('input,select').forEach(function(el){ el.value=''; }); return; }
    btn.closest('.step-row').remove();
    renum();
  });
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
