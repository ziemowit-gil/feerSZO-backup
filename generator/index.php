<?php
/** generator/index.php — Generator dokumentów: lista wygenerowanych dokumentów i start z wzoru. */
require_once __DIR__ . '/_boot.php';
$q = trim((string)($_GET['q'] ?? ''));
$tpl = (int)($_GET['wzor'] ?? 0);
$st = (string)($_GET['status'] ?? '');
$docs = DocGenStore::documents($q, $tpl, $st);
$templates = DocGenStore::templates(true);
$codes = DocRegistry::forMany('gen_doc', array_column($docs, 'id'));
$PAGE_TITLE = 'Generator dokumentów';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-file-earmark-richtext text-primary"></i> Generator dokumentów <span class="badge bg-secondary ms-1"><?= count($docs) ?></span></h4>
  <?php if (is_admin()): ?><a class="btn btn-outline-secondary btn-sm" href="templates.php"><i class="bi bi-collection me-1"></i>Wzory</a><?php endif; ?>
</div>

<?php if (can_edit()): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <form method="get" action="edit.php" class="row g-2 align-items-end">
    <div class="col-md-8"><label class="form-label small mb-1" for="g-t">Nowy dokument z wzoru</label>
      <select class="form-select" id="g-t" name="template" required><option value="">— wybierz wzór —</option>
        <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h(($t['category'] !== '' ? $t['category'] . ' · ' : '') . $t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Utwórz dokument</button></div>
  </form>
</div></div>
<?php endif; ?>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-md-5"><label class="visually-hidden" for="g-q">Szukaj</label><input id="g-q" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Tytuł lub treść pól…"></div>
  <div class="col-md-3"><label class="visually-hidden" for="g-w">Wzór</label><select id="g-w" class="form-select form-select-sm" name="wzor"><option value="">— wszystkie wzory —</option>
    <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tpl === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="visually-hidden" for="g-s">Status</label><select id="g-s" class="form-select form-select-sm" name="status"><option value="">— status —</option>
    <?php foreach (DocGenStore::STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= $st === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Szukaj</button></div>
</form>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <caption class="visually-hidden">Wygenerowane dokumenty</caption>
  <thead class="table-light"><tr><th scope="col">Dokument</th><th scope="col">Wzór</th><th scope="col">Kod</th><th scope="col">Status</th><th scope="col">Utworzono</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php if (!$docs): ?><tr><td colspan="6" class="text-center text-muted py-4">Brak dokumentów — utwórz pierwszy z wzoru.</td></tr><?php endif; ?>
  <?php foreach ($docs as $d): $cd = $codes[(int)$d['id']] ?? null; ?>
    <tr><td><a href="view.php?id=<?= (int)$d['id'] ?>"><?= h($d['title'] ?: 'Dokument #' . $d['id']) ?></a></td>
      <td class="small"><?= h($d['template_name'] ?? '—') ?></td>
      <td class="small font-monospace"><?= $cd ? h($cd['code']) : '' ?></td>
      <td><span class="badge text-bg-<?= ['szkic' => 'secondary', 'gotowy' => 'primary', 'podpisany' => 'success', 'anulowany' => 'danger'][$d['status']] ?? 'secondary' ?>"><?= h(DocGenStore::STATUSES[$d['status']] ?? $d['status']) ?></span></td>
      <td class="small text-nowrap"><?= h(date('d.m.Y H:i', strtotime($d['created_at']))) ?><div class="text-muted"><?= h($d['created_by_name']) ?></div></td>
      <td class="text-end text-nowrap"><a class="btn btn-outline-secondary btn-sm" href="view.php?id=<?= (int)$d['id'] ?>&amp;docx=1" aria-label="Pobierz DOCX: <?= h($d['title']) ?>"><i class="bi bi-file-earmark-word" aria-hidden="true"></i></a>
        <?php if (can_edit()): ?><a class="btn btn-outline-primary btn-sm" href="edit.php?id=<?= (int)$d['id'] ?>" aria-label="Edytuj: <?= h($d['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
