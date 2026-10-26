<?php
/** generator/view.php — podgląd dokumentu, pobranie DOCX (?docx=1), wersja do druku (?print=1), zmiana statusu i usunięcie. */
require_once __DIR__ . '/_boot.php';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = $id ? DocGenStore::document($id) : null;
if (!$doc) { flash_set('error', 'Nie ma takiego dokumentu.'); header('Location: index.php'); exit; }
$tpl = DocGenStore::template((int)$doc['template_id']);
if (!$tpl) { flash_set('error', 'Wzór tego dokumentu został usunięty.'); header('Location: index.php'); exit; }
$cu = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: view.php?id=' . $id); exit; }
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'status' && isset(DocGenStore::STATUSES[$_POST['status'] ?? ''])) {
        DocGenStore::saveDocument($id, (int)$doc['template_id'], $doc['title'], $doc['values'], (string)$_POST['status'], 0, '');
        flash_set('success', 'Zmieniono status.');
    } elseif ($act === 'delete' && is_admin()) {
        DocGenStore::deleteDocument($id); flash_set('success', 'Usunięto dokument.'); header('Location: index.php'); exit;
    }
    header('Location: view.php?id=' . $id); exit;
}
$built = DocGenStore::build($tpl, $doc['values']);
$wantDocx = isset($_GET['docx']);
$wantPrint = isset($_GET['print']);
if ($wantDocx || $wantPrint) {
    if ($doc['status'] === 'anulowany') { http_response_code(409); exit('Dokument jest anulowany.'); }
    if ($wantDocx) {
        $codes = DocRegistry::footerCodes('gen_doc', $id);
        $bytes = DocGenEngine::docx($built['blocks'], $doc['title'] ?: $built['title'], $codes);
        $fn = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)(iconv('UTF-8', 'ASCII//TRANSLIT', $doc['title'] ?: 'dokument') ?: 'dokument')), '_');
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . ($fn ?: 'dokument') . '.docx"');
        header('Cache-Control: no-store, private');
        echo $bytes; exit;
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo DocGenEngine::html($built['blocks'], $doc['title'] ?: $built['title'], DocRegistry::footerHtml('gen_doc', $id));
    exit;
}
$code = DocRegistry::assign('gen_doc', $id);
$bodyHtml = DocGenEngine::bodyHtml($built['blocks']);
$css = DocGenEngine::css();
$missing = [];
foreach ($tpl['fields_def'] as $k => $f) if ($f['required'] && trim((string)($doc['values'][$k] ?? '')) === '') $missing[] = $f['label'];
$PAGE_TITLE = $doc['title'] ?: 'Dokument';
include dirname(__DIR__) . '/includes/header.php';
?>
<p class="mb-2"><a href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wszystkie dokumenty</a></p>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div><h4 class="mb-1"><?= h($doc['title'] ?: 'Dokument #' . $id) ?></h4>
    <div class="small text-muted"><?= h($tpl['name']) ?> · <span class="badge text-bg-secondary"><?= h(DocGenStore::STATUSES[$doc['status']] ?? $doc['status']) ?></span>
      <?php if ($code): ?> · kod <span class="font-monospace"><?= h($code['code']) ?></span> · <?= h($code['doc_number']) ?> <?= DocRegistry::badge($code) ?><?php endif; ?></div></div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary btn-sm" href="?id=<?= (int)$id ?>&amp;docx=1"><i class="bi bi-file-earmark-word me-1"></i>Pobierz DOCX</a>
    <a class="btn btn-outline-secondary btn-sm" href="?id=<?= (int)$id ?>&amp;print=1" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Wersja do druku</a>
    <?php if (can_edit()): ?><a class="btn btn-outline-primary btn-sm" href="edit.php?id=<?= (int)$id ?>"><i class="bi bi-pencil me-1"></i>Edytuj dane</a><?php endif; ?></div>
</div>
<?php if ($missing): ?><div class="alert alert-warning py-2">Do uzupełnienia (w dokumencie widoczne jako linie): <?= h(implode('; ', $missing)) ?>.</div><?php endif; ?>
<?php if (can_edit()): ?>
<form method="post" class="d-flex gap-2 align-items-end mb-3 no-print"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="status">
  <div><label class="form-label small mb-1" for="v-st">Status</label><select class="form-select form-select-sm" id="v-st" name="status"><?php foreach (DocGenStore::STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= $doc['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-outline-secondary btn-sm">Zmień</button>
  <?php if (is_admin()): ?><button class="btn btn-outline-danger btn-sm ms-auto" name="act" value="delete" onclick="return confirm('Usunąć dokument?')">Usuń</button><?php endif; ?></form>
<?php endif; ?>
<style><?= str_replace('body{', '.dg-wrap{', preg_replace('/\.dg-page\{[^}]*\}/', '.dg-page{padding:14mm 16mm;background:#fff;color:#111;border:1px solid #ddd;box-shadow:0 1px 4px rgba(0,0,0,.1)}', $css)) ?></style>
<div class="dg-wrap"><div class="dg-page"><?= $bodyHtml ?></div></div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
