<?php
/**
 * Szablony pism EZD — CRUD. Wzorce tytułu/treści z tokenami {{token}} używane
 * przy dodawaniu pisma oraz w korespondencji seryjnej.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_role('admin');
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$user_id = (int)current_user()['id'];
$self    = APP_URL . '/admin/ezd_szablony.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    try {
        if ($op === 'save') {
            $sid = (int)($_POST['id'] ?? 0);
            if (!trim($_POST['nazwa'] ?? '')) throw new \RuntimeException('Nazwa jest wymagana.');
            if ($sid) { ezd_szablon_update($sid, $_POST, $user_id); flash_set('success', 'Szablon zapisany.'); }
            else      { ezd_szablon_create($_POST, $user_id);       flash_set('success', 'Szablon dodany.'); }
        } elseif ($op === 'delete') {
            ezd_szablon_delete((int)($_POST['id'] ?? 0), $user_id);
            flash_set('success', 'Szablon usunięty.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:' . $self); exit;
}

$edit = null;
if (($eid = (int)($_GET['edit'] ?? 0))) $edit = ezd_szablon_get($eid);
$szablony = ezd_szablony_all();

$PAGE_TITLE = 'Szablony pism EZD';
include dirname(__DIR__) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Szablony pism</li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text text-primary me-2"></i>Szablony pism</h4>
  <a href="<?= $self ?>?edit=0#form" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowy szablon</a>
</div>

<div class="row g-4">
  <!-- Lista -->
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body p-0">
        <?php if (!$szablony): ?>
          <p class="text-muted small p-3 mb-0">Brak szablonów. Dodaj pierwszy formularzem obok.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light"><tr><th>Nazwa</th><th>Kategoria</th><th>Kierunek</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($szablony as $s):
              $kier = EZD_KIERUNKI[$s['kierunek']] ?? ['label'=>$s['kierunek'],'icon'=>'bi-arrow-right','class'=>'secondary']; ?>
              <tr>
                <td class="fw-semibold"><?= h($s['nazwa']) ?><?php if($s['opis']): ?><br><span class="text-muted fw-normal" style="font-size:.75rem"><?= h($s['opis']) ?></span><?php endif; ?></td>
                <td><span class="badge bg-light text-dark border"><?= h(EZD_SZABLON_KATEGORIE[$s['kategoria']] ?? $s['kategoria']) ?></span></td>
                <td><span class="badge bg-<?= $kier['class'] ?> bg-opacity-10 text-<?= $kier['class'] ?>"><i class="bi <?= $kier['icon'] ?> me-1"></i><?= h($kier['label']) ?></span></td>
                <td><?= $s['aktywny'] ? '<span class="badge bg-success">Aktywny</span>' : '<span class="badge bg-secondary">Nieaktywny</span>' ?></td>
                <td class="text-end text-nowrap">
                  <a href="<?= $self ?>?edit=<?= (int)$s['id'] ?>#form" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                  <form method="post" action="<?= $self ?>" class="d-inline" onsubmit="return confirm('Usunąć szablon?');">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-outline-danger btn-sm py-0 px-1"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Formularz -->
  <div class="col-lg-5" id="form">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><?= $edit ? 'Edytuj szablon' : 'Nowy szablon' ?></div>
      <form method="post" action="<?= $self ?>">
        <div class="card-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" name="nazwa" class="form-control" required value="<?= h($edit['nazwa'] ?? '') ?>" placeholder="np. Pismo przewodnie">
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Kategoria</label>
              <select name="kategoria" class="form-select form-select-sm">
                <?php foreach (EZD_SZABLON_KATEGORIE as $kv=>$kl): ?>
                <option value="<?= $kv ?>" <?= ($edit['kategoria'] ?? 'pismo')===$kv?'selected':'' ?>><?= h($kl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Kierunek</label>
              <select name="kierunek" class="form-select form-select-sm">
                <?php foreach (EZD_KIERUNKI as $kv=>$kl): ?>
                <option value="<?= $kv ?>" <?= ($edit['kierunek'] ?? 'wychodzace')===$kv?'selected':'' ?>><?= h($kl['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Medium</label>
              <select name="rodzaj_medium" class="form-select form-select-sm">
                <?php foreach (EZD_MEDIA as $mv=>$ml): ?>
                <option value="<?= $mv ?>" <?= ($edit['rodzaj_medium'] ?? 'papier')===$mv?'selected':'' ?>><?= h($ml['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="aktywny" id="akt" value="1" <?= (!$edit || $edit['aktywny']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="akt">Aktywny</label>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Wzór tytułu pisma</label>
            <input type="text" name="tytul_wzor" class="form-control" value="<?= h($edit['tytul_wzor'] ?? '') ?>" placeholder="np. Pismo przewodnie do sprawy {{znak_sprawy}}">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Wzór treści</label>
            <textarea name="tresc_wzor" class="form-control font-monospace" rows="8" style="font-size:.82rem"><?= h($edit['tresc_wzor'] ?? '') ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Opis (wewnętrzny)</label>
            <input type="text" name="opis" class="form-control form-control-sm" value="<?= h($edit['opis'] ?? '') ?>">
          </div>

          <details class="mb-2">
            <summary class="fw-semibold small text-primary" style="cursor:pointer"><i class="bi bi-braces me-1"></i>Dostępne tokeny</summary>
            <div class="mt-2 small">
              <?php foreach (EZD_SZABLON_TOKENY as $grupa => $toks): ?>
              <div class="mb-1"><span class="text-muted"><?= h($grupa) ?>:</span>
                <?php foreach ($toks as $tk => $desc): ?>
                <code class="me-1" title="<?= h($desc) ?>" style="cursor:pointer;background:#f1f5f9;padding:1px 4px;border-radius:3px" onclick="insTok('{{<?= $tk ?>}}')">{{<?= $tk ?>}}</code>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
        <div class="card-footer d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Zapisz' : 'Dodaj' ?></button>
          <?php if ($edit): ?><a href="<?= $self ?>" class="btn btn-outline-secondary btn-sm">Anuluj</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function insTok(tok){
  var ta = document.querySelector('textarea[name="tresc_wzor"]');
  if(!ta) return;
  var s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? ta.value.length;
  ta.value = ta.value.slice(0,s) + tok + ta.value.slice(e);
  ta.focus(); ta.selectionStart = ta.selectionEnd = s + tok.length;
}
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
