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
$PAGE_TITLE = mb_strimwidth($doc['title'] ?: 'Dokument', 0, 38, '…');   // krótki — tytuł ląduje w pasku nawigacji
include dirname(__DIR__) . '/includes/header.php';
$_stCls = ['szkic' => 'secondary', 'gotowy' => 'primary', 'podpisany' => 'success', 'anulowany' => 'danger'][$doc['status']] ?? 'secondary';
?>
<p class="mb-2"><a href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wszystkie dokumenty</a></p>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div class="flex-grow-1" style="min-width:18rem;max-width:62rem">
    <h4 class="mb-1"><?= h($doc['title'] ?: 'Dokument #' . $id) ?></h4>
    <div class="small text-muted d-flex flex-wrap align-items-center gap-2">
      <span class="badge text-bg-<?= $_stCls ?>"><?= h(DocGenStore::STATUSES[$doc['status']] ?? $doc['status']) ?></span>
      <?php if (mb_strpos((string)$doc['title'], mb_substr($tpl['name'], 0, 30)) !== 0): ?><span>Wzór: <?= h($tpl['name']) ?></span><?php endif; ?>
      <?php if ($code): ?><span class="font-monospace"><?= h($code['code']) ?></span><span><?= h($code['doc_number']) ?></span><?= DocRegistry::badge($code) ?><?php endif; ?>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-end no-print">
    <a class="btn btn-primary btn-sm" href="?id=<?= (int)$id ?>&amp;docx=1"><i class="bi bi-file-earmark-word me-1"></i>DOCX</a>
    <a class="btn btn-outline-secondary btn-sm" href="?id=<?= (int)$id ?>&amp;print=1" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Do druku</a>
    <?php if (can_edit()): ?><a class="btn btn-outline-primary btn-sm" href="edit.php?id=<?= (int)$id ?>"><i class="bi bi-pencil me-1"></i>Edytuj</a>
    <form method="post" class="d-flex gap-1 align-items-end ms-md-2"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="status">
      <div><label class="visually-hidden" for="v-st">Status</label><select class="form-select form-select-sm" id="v-st" name="status"><?php foreach (DocGenStore::STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= $doc['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-outline-secondary btn-sm">Zmień status</button></form>
    <?php if (is_admin()): ?><form method="post" onsubmit="return confirm('Usunąć dokument?')"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="act" value="delete">
      <button class="btn btn-outline-danger btn-sm" aria-label="Usuń dokument"><i class="bi bi-trash3" aria-hidden="true"></i></button></form><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php if ($missing): ?><div class="alert alert-warning py-2 small">Do uzupełnienia (w dokumencie widoczne jako linie): <?= h(implode('; ', $missing)) ?>. <?php if (can_edit()): ?><a href="edit.php?id=<?= (int)$id ?>">Uzupełnij dane</a><?php endif; ?></div><?php endif; ?>
<style><?= str_replace('body{', '.dg-wrap{', preg_replace('/\.dg-page\{[^}]*\}/', '.dg-page{max-width:210mm;margin:0 auto;padding:16mm 18mm;background:#fff;color:#111;border:1px solid #d5d9de;box-shadow:0 2px 10px rgba(0,0,0,.12)}', $css)) ?>.dg-wrap{background:#eef0f3;padding:1.2rem .5rem;border-radius:.4rem}</style>
<div class="dg-wrap"><div class="dg-page"><?= $bodyHtml ?></div></div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
