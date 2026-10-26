<?php
/** generator/templates.php — wzory dokumentów (admin): lista, edycja treści i pól, nowy wzór, przywrócenie wzoru wbudowanego. */
require_once __DIR__ . '/_boot.php';
if (!is_admin()) { flash_set('error', 'Wzorami dokumentów zarządza administrator.'); header('Location: index.php'); exit; }
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'restore') {
        $t = $id ? DocGenStore::template($id) : null;
        if ($t && $t['builtin'] && DocGenStore::restoreBuiltin($t['slug'])) flash_set('success', 'Przywrócono wzór wbudowany.'); else flash_set('error', 'Tego wzoru nie da się przywrócić.');
        header('Location: templates.php?id=' . $id); exit;
    }
    if ($act === 'delete') {
        $e = DocGenStore::deleteTemplate($id);
        $e === '' ? flash_set('success', 'Usunięto wzór.') : flash_set('error', $e);
        header('Location: templates.php'); exit;
    }
    [$newId, $err] = DocGenStore::saveTemplate($id, $_POST);
    if ($err === '') { flash_set('success', 'Zapisano wzór.'); header('Location: templates.php?id=' . $newId); exit; }
}
$editing = $id > 0 || isset($_GET['nowy']) || $err !== '';
$tpl = $id ? DocGenStore::template($id) : null;
$form = $err !== '' ? $_POST : ($tpl ?? ['name' => '', 'category' => 'Umowy', 'description' => '', 'body' => '', 'is_active' => 1]);
$defs = $tpl['fields_def'] ?? [];
if ($err !== '') { $defs = []; foreach ((array)($_POST['f'] ?? []) as $r) if (($r['key'] ?? '') !== '') $defs[$r['key']] = $r + ['options' => '']; }
$bodyKeys = DocGenEngine::fieldKeys((string)($form['body'] ?? ''));
foreach ($bodyKeys as $k) if (!isset($defs[$k])) $defs[$k] = ['key' => $k, 'label' => $k, 'type' => 'text', 'required' => false, 'default' => '', 'group' => 'Dane', 'hint' => '', 'options' => ''];
$templates = DocGenStore::templates();
$PAGE_TITLE = 'Wzory dokumentów';
include dirname(__DIR__) . '/includes/header.php';
?>
<p class="mb-2"><a href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Generator dokumentów</a></p>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><i class="bi bi-collection text-primary"></i> Wzory dokumentów</h4><a class="btn btn-primary btn-sm" href="?nowy=1"><i class="bi bi-plus-lg"></i> Nowy wzór</a></div>
<?php if ($err !== ''): ?><div class="alert alert-danger" role="alert"><?= h($err) ?></div><?php endif; ?>

<?php if ($editing): ?>
<form method="post" class="card shadow-sm mb-4"><div class="card-body">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="row g-2 mb-2">
    <div class="col-md-6"><label class="form-label small mb-1" for="t-n">Nazwa wzoru</label><input class="form-control" id="t-n" name="name" required value="<?= h($form['name'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-1" for="t-c">Kategoria</label><input class="form-control" id="t-c" name="category" value="<?= h($form['category'] ?? '') ?>"></div>
    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="t-a" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="t-a">Aktywny (widoczny przy tworzeniu)</label></div></div>
  </div>
  <div class="mb-2"><label class="form-label small mb-1" for="t-d">Opis</label><input class="form-control" id="t-d" name="description" value="<?= h($form['description'] ?? '') ?>"></div>
  <div class="mb-2"><label class="form-label small mb-1" for="t-b">Treść wzoru</label>
    <textarea class="form-control font-monospace" id="t-b" name="body" rows="18" required aria-describedby="t-b-h" style="font-size:.8rem"><?= h($form['body'] ?? '') ?></textarea>
    <div class="form-text" id="t-b-h">Składnia: <code>{{pole}}</code> — wartość pola; <code>[Pani|Pan@plec]</code> — odmiana wg pola K/M; <code>{{#if pole=wartość}}…{{#else}}…{{/if}}</code> — warunek;
      <code># Tytuł</code>, <code>## Nagłówek</code>, <code>### Podtytuł</code>, <code>1. Ustęp</code>, <code>☐ Pole wyboru</code>, <code>**pogrubienie**</code>, <code>[[strona]]</code>, <code>[[podpisy: wiersz~wiersz || wiersz~wiersz]]</code>. Po zapisie pola z treści dołączą się do tabeli poniżej.</div></div>
  <h5 class="h6 fw-bold mt-3">Pola wzoru</h5>
  <div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Definicje pól</caption>
    <thead><tr><th scope="col">Klucz</th><th scope="col">Etykieta</th><th scope="col">Typ</th><th scope="col">Grupa</th><th scope="col">Domyślnie</th><th scope="col">Opcje (po przecinku)</th><th scope="col">Podpowiedź</th><th scope="col">Wym.</th></tr></thead><tbody>
    <?php $i = 0; foreach ($defs as $k => $f): ?>
    <tr><td><input type="hidden" name="f[<?= $i ?>][key]" value="<?= h($k) ?>"><code><?= h($k) ?></code></td>
      <td><input class="form-control form-control-sm" aria-label="Etykieta <?= h($k) ?>" name="f[<?= $i ?>][label]" value="<?= h($f['label'] ?? $k) ?>"></td>
      <td><select class="form-select form-select-sm" aria-label="Typ <?= h($k) ?>" name="f[<?= $i ?>][type]"><?php foreach (DocGenEngine::TYPES as $tk => $tl): ?><option value="<?= h($tk) ?>" <?= ($f['type'] ?? 'text') === $tk ? 'selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?></select></td>
      <td><input class="form-control form-control-sm" aria-label="Grupa <?= h($k) ?>" name="f[<?= $i ?>][group]" value="<?= h($f['group'] ?? 'Dane') ?>"></td>
      <td><input class="form-control form-control-sm" aria-label="Domyślna wartość <?= h($k) ?>" name="f[<?= $i ?>][default]" value="<?= h($f['default'] ?? '') ?>"></td>
      <td><input class="form-control form-control-sm" aria-label="Opcje <?= h($k) ?>" name="f[<?= $i ?>][options]" value="<?= h(is_array($f['options'] ?? '') ? implode(',', $f['options']) : ($f['options'] ?? '')) ?>"></td>
      <td><input class="form-control form-control-sm" aria-label="Podpowiedź <?= h($k) ?>" name="f[<?= $i ?>][hint]" value="<?= h($f['hint'] ?? '') ?>"></td>
      <td class="text-center"><input class="form-check-input" type="checkbox" aria-label="Wymagane: <?= h($k) ?>" name="f[<?= $i ?>][required]" value="1" <?= !empty($f['required']) ? 'checked' : '' ?>></td></tr>
    <?php $i++; endforeach; ?></tbody></table></div>
  <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz wzór</button><a class="btn btn-link" href="templates.php">Anuluj</a>
    <?php if ($tpl && $tpl['builtin']): ?><button class="btn btn-outline-warning ms-auto" name="act" value="restore" formnovalidate onclick="return confirm('Przywrócić wzór wbudowany? Twoje zmiany treści i pól zostaną nadpisane.')">Przywróć wzór wbudowany</button><?php endif; ?>
    <?php if ($tpl): ?><button class="btn btn-outline-danger" name="act" value="delete" formnovalidate onclick="return confirm('Usunąć wzór?')">Usuń</button><?php endif; ?></div>
</div></form>
<?php endif; ?>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><caption class="visually-hidden">Wzory</caption>
  <thead class="table-light"><tr><th scope="col">Wzór</th><th scope="col">Kategoria</th><th scope="col">Pola</th><th scope="col">Dokumenty</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead><tbody>
  <?php foreach ($templates as $t): ?><tr><td><a href="?id=<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a><?= $t['builtin'] ? ' <span class="badge text-bg-light border">wbudowany</span>' : '' ?></td>
    <td class="small"><?= h($t['category']) ?></td><td class="small"><?= count((array)(json_decode((string)$t['fields'], true) ?: [])) ?></td><td class="small"><?= (int)$t['docs'] ?></td>
    <td><?= (int)$t['is_active'] ? '<span class="badge text-bg-success">aktywny</span>' : '<span class="badge text-bg-secondary">wyłączony</span>' ?></td>
    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="edit.php?template=<?= (int)$t['id'] ?>">Utwórz dokument</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
