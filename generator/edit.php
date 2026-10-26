<?php
/** generator/edit.php — formularz dokumentu z wzoru (nowy: ?template=ID, edycja: ?id=DOK). Pola pochodzą z definicji wzoru. */
require_once __DIR__ . '/_boot.php';
if (!can_edit()) { flash_set('error', 'Brak uprawnień do tworzenia dokumentów.'); header('Location: index.php'); exit; }
$cu = current_user();
$uid = (int)($cu['id'] ?? 0);
$uname = (string)($cu['name'] ?? $cu['email'] ?? '');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = $id ? DocGenStore::document($id) : null;
if ($id && !$doc) { flash_set('error', 'Nie ma takiego dokumentu.'); header('Location: index.php'); exit; }
$tplId = $doc ? (int)$doc['template_id'] : (int)($_GET['template'] ?? $_POST['template'] ?? 0);
$tpl = $tplId ? DocGenStore::template($tplId) : null;
if (!$tpl) { flash_set('error', 'Wybierz wzór dokumentu.'); header('Location: index.php'); exit; }
$fields = $tpl['fields_def'];
$errs = [];
$values = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$values, $errs] = DocGenStore::collect($fields, $_POST);
    $title = trim((string)($_POST['title'] ?? '')) ?: DocGenStore::defaultTitle($tpl, $values);
    // wymagane braki nie blokują zapisu szkicu — tylko ostrzegają; blokują status „gotowy”
    $status = (string)($_POST['status'] ?? ($doc['status'] ?? 'szkic'));
    $hardErr = array_filter($errs, fn($e) => str_contains($e, 'format daty'));
    if (!$hardErr && !($errs && $status !== 'szkic')) {
        $id = DocGenStore::saveDocument($id, $tplId, $title, $values, $status, $uid, $uname);
        flash_set($errs ? 'warning' : 'success', $errs ? 'Zapisano szkic — uzupełnij: ' . implode(' ', $errs) : 'Zapisano dokument.');
        header('Location: view.php?id=' . $id); exit;
    }
} elseif ($doc) { $values = $doc['values']; }
else { foreach ($fields as $k => $f) $values[$k] = DocGenStore::defaultValue($f); }
$title = $title ?? ($doc['title'] ?? '');
$groups = [];
foreach ($fields as $k => $f) $groups[$f['group']][] = $f;
$PAGE_TITLE = $doc ? 'Edycja dokumentu' : 'Nowy dokument';
include dirname(__DIR__) . '/includes/header.php';
?>
<p class="mb-2"><a href="<?= $doc ? 'view.php?id=' . (int)$id : 'index.php' ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć</a></p>
<h4 class="mb-1"><?= h($tpl['name']) ?></h4>
<p class="text-muted small mb-3"><?= h($tpl['description']) ?></p>
<?php foreach ($errs as $e): ?><div class="alert alert-warning py-2" role="alert"><?= h($e) ?></div><?php endforeach; ?>
<form method="post" class="card shadow-sm"><div class="card-body">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="template" value="<?= (int)$tplId ?>">
  <div class="mb-3"><label class="form-label" for="g-title">Tytuł dokumentu (na liście)</label><input class="form-control" id="g-title" name="title" maxlength="255" value="<?= h($title) ?>" placeholder="puste = nazwa wzoru + osoba"></div>
  <?php foreach ($groups as $gname => $gf): ?>
  <fieldset class="mb-4"><legend class="h6 fw-bold border-bottom pb-1"><?= h($gname) ?></legend><div class="row g-2">
    <?php foreach ($gf as $f): $fid = 'v-' . $f['key']; $val = (string)($values[$f['key']] ?? ''); ?>
    <div class="<?= in_array($f['type'], ['textarea'], true) ? 'col-12' : 'col-md-6' ?>">
      <label class="form-label small mb-1" for="<?= h($fid) ?>"><?= h($f['label']) ?><?= $f['required'] ? ' <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> (wymagane)</span>' : '' ?></label>
      <?php if ($f['type'] === 'textarea'): ?><textarea class="form-control form-control-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]" rows="2"<?= $f['hint'] !== '' ? ' aria-describedby="' . h($fid) . '-h"' : '' ?>><?= h($val) ?></textarea>
      <?php elseif ($f['type'] === 'select'): ?><select class="form-select form-select-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]"><?php foreach ($f['options'] as $o): ?><option value="<?= h($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select>
      <?php elseif ($f['type'] === 'gender'): ?><select class="form-select form-select-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]"><option value="K" <?= $val !== 'M' ? 'selected' : '' ?>>K — forma żeńska</option><option value="M" <?= $val === 'M' ? 'selected' : '' ?>>M — forma męska</option></select>
      <?php elseif ($f['type'] === 'date'): ?>
        <div class="input-group input-group-sm"><input class="form-control" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]" type="date" value="<?= h($val) ?>"<?= $f['hint'] !== '' ? ' aria-describedby="' . h($fid) . '-h"' : '' ?>>
          <button type="button" class="btn btn-outline-secondary" data-dg-date="0" data-target="<?= h($fid) ?>">Dzisiaj</button>
          <button type="button" class="btn btn-outline-secondary" data-dg-date="3" data-target="<?= h($fid) ?>">+3 dni</button></div>
      <?php else: ?><input class="form-control form-control-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]" type="text" value="<?= h($val) ?>"<?= $f['hint'] !== '' ? ' aria-describedby="' . h($fid) . '-h"' : '' ?>><?php endif; ?>
      <?php if ($f['hint'] !== ''): ?><div class="form-text" id="<?= h($fid) ?>-h"><?= h($f['hint']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?></div></fieldset>
  <?php endforeach; ?>
  <div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label small mb-1" for="g-st">Status</label>
    <select class="form-select form-select-sm" id="g-st" name="status"><?php foreach (DocGenStore::STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= ($doc['status'] ?? 'szkic') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <div class="form-text">Status inny niż „szkic” wymaga uzupełnienia wymaganych pól.</div></div>
    <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button></div></div>
</div></form>
<script>
// Szybkie daty: „Dzisiaj” i „+3 dni” (od dzisiaj) wstawiają wartość do pola daty.
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-dg-date]'); if (!b) return;
  var el = document.getElementById(b.dataset.target); if (!el) return;
  var d = new Date(); d.setDate(d.getDate() + parseInt(b.dataset.dgDate, 10));
  el.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  el.dispatchEvent(new Event('change', {bubbles: true}));
});
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
