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
$sug = DocGenStore::suggestions($tplId, $fields);
$hasClient = (bool)array_filter($fields, fn($f) => $f['client'] !== '');
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
  <?php if ($hasClient && !$doc): ?>
  <div class="mb-3 p-2 border rounded bg-light"><label class="form-label small mb-1" for="dg-client">Uzupełnij z karty uczestnika TI (wpisz nazwisko)</label>
    <input class="form-control form-control-sm" id="dg-client" list="dg-clients" autocomplete="off" placeholder="np. Kowalski…" aria-describedby="dg-client-h"><datalist id="dg-clients"></datalist>
    <div class="form-text" id="dg-client-h" role="status">Po wybraniu uczestnika pola z dopiskiem „z karty TI” zostaną uzupełnione — możesz je poprawić.</div></div>
  <?php endif; ?>
  <?php foreach ($groups as $gname => $gf): ?>
  <fieldset class="mb-4"><legend class="h6 fw-bold border-bottom pb-1"><?= h($gname) ?></legend><div class="row g-2">
    <?php foreach ($gf as $f): $fid = 'v-' . $f['key']; $val = (string)($values[$f['key']] ?? ''); ?>
    <div class="<?= in_array($f['type'], ['textarea'], true) ? 'col-12' : 'col-md-6' ?>">
      <label class="form-label small mb-1" for="<?= h($fid) ?>"><?= h($f['label']) ?><?= $f['required'] ? ' <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> (wymagane)</span>' : '' ?><?= $f['client'] !== '' ? ' <span class="badge text-bg-light border fw-normal">z karty TI</span>' : '' ?></label>
      <?php if ($f['type'] === 'textarea'): ?><textarea class="form-control form-control-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]" rows="2"<?= $f['hint'] !== '' ? ' aria-describedby="' . h($fid) . '-h"' : '' ?>><?= h($val) ?></textarea>
      <?php elseif ($f['type'] === 'select'): ?><select class="form-select form-select-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]"><?php if (!$f['required']): ?><option value="">— nie zaznaczono —</option><?php endif; ?><?php foreach ($f['options'] as $o): ?><option value="<?= h($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select>
      <?php elseif ($f['type'] === 'gender'): ?><select class="form-select form-select-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]"<?= $f['client'] !== '' ? ' data-client="' . h($f['client']) . '"' : '' ?>><option value="K" <?= $val !== 'M' ? 'selected' : '' ?>>K — forma żeńska</option><option value="M" <?= $val === 'M' ? 'selected' : '' ?>>M — forma męska</option></select>
      <?php elseif ($f['type'] === 'date'): ?>
        <div class="input-group input-group-sm"><input class="form-control" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]" type="date" value="<?= h($val) ?>"<?= $f['hint'] !== '' ? ' aria-describedby="' . h($fid) . '-h"' : '' ?>>
          <button type="button" class="btn btn-outline-secondary" data-dg-date="0" data-target="<?= h($fid) ?>">Dzisiaj</button>
          <button type="button" class="btn btn-outline-secondary" data-dg-date="3" data-target="<?= h($fid) ?>">+3 dni</button></div>
      <?php else: ?><input class="form-control form-control-sm" id="<?= h($fid) ?>" name="v[<?= h($f['key']) ?>]" type="text" value="<?= h($val) ?>"<?= $f['hint'] !== '' ? ' aria-describedby="' . h($fid) . '-h"' : '' ?><?= !empty($sug[$f['key']]) ? ' list="dl-' . h($f['key']) . '" autocomplete="off"' : '' ?><?= $f['client'] !== '' ? ' data-client="' . h($f['client']) . '"' : '' ?>>
        <?php if (!empty($sug[$f['key']])): ?><datalist id="dl-<?= h($f['key']) ?>"><?php foreach ($sug[$f['key']] as $o): ?><option value="<?= h($o) ?>"></option><?php endforeach; ?></datalist><?php endif; ?><?php endif; ?>
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
// Uzupełnianie z karty uczestnika TI (generator/client.php): lista nazwisk w datalist, po wyborze — dane do pól z data-client.
(function () {
  var inp = document.getElementById('dg-client'), dl = document.getElementById('dg-clients'); if (!inp) return;
  var map = {}, t;
  inp.addEventListener('input', function () {
    var v = inp.value; if (map[v]) { pick(map[v]); return; }
    clearTimeout(t); if (v.length < 2) return;
    t = setTimeout(function () {
      fetch('client.php?q=' + encodeURIComponent(v), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (rows) {
        dl.innerHTML = ''; map = {};
        rows.forEach(function (r) { var label = r.name + ' (#' + r.id + ')'; map[label] = r.id; var o = document.createElement('option'); o.value = label; dl.appendChild(o); });
      }).catch(function () {});
    }, 200);
  });
  function pick(id) {
    fetch('client.php?id=' + id, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
      var n = 0;
      document.querySelectorAll('[data-client]').forEach(function (el) { var v = d[el.dataset.client]; if (v !== undefined && v !== '') { el.value = v; n++; } });
      document.getElementById('dg-client-h').textContent = 'Uzupełniono pól: ' + n + ' — sprawdź i popraw w razie potrzeby.';
    });
  }
})();
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
