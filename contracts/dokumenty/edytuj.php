<?php
/**
 * contracts/dokumenty/edytuj.php
 * Krok 2 — podgląd i live edycja wygenerowanego dokumentu przed eksportem
 * / podpisaniem. Treść edytowana w TinyMCE, zapisywana do contract_documents.
 *
 * GET  ?id=N
 * POST _action=save        (body)
 * POST _action=set_status  (status)
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_document_engine.php';

require_login();

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = $id ? cgd_get($id) : null;
if (!$doc) { http_response_code(404); exit('Dokument nie istnieje.'); }

$can_edit_doc = can_edit();
$SELF = APP_URL . '/contracts/dokumenty/edytuj.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit_doc) { http_response_code(403); exit('Brak uprawnień.'); }
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'save') {
        cgd_update_content($id, $_POST['body'] ?? '');
        flash_set('success', 'Zmiany zapisane.');
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'set_status') {
        if (cgd_set_status($id, $_POST['status'] ?? '')) {
            flash_set('success', 'Status dokumentu zmieniony.');
        } else {
            flash_set('error', 'Nieprawidłowy status.');
        }
        header('Location: ' . $SELF); exit;
    }

    header('Location: ' . $SELF); exit;
}

$doc = cgd_get($id); // odśwież po ewentualnym POST (dla spójności przy błędach)
$tpl = cte_get((int)$doc['template_id']) ?? db_one("SELECT name FROM contract_doc_templates WHERE id=?", [$doc['template_id']]);
$statuses  = cgd_statuses();
$view_url  = APP_URL . '/contracts/' . $doc['contract_type'] . '/view.php?id=' . $doc['contract_id'] . '&tab=docs';
$src_row   = cgd_source_row($doc['contract_type'], (int)$doc['contract_id']);

$PAGE_TITLE = 'Edycja dokumentu — ' . ($tpl['name'] ?? 'Umowa');
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
#docEditorFallback { display:none; }
</style>

<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
  <a href="<?= h($view_url) ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h5 class="mb-0 fw-bold">
    <i class="bi bi-file-earmark-richtext text-primary me-1"></i><?= h($tpl['name'] ?? 'Dokument') ?>
  </h5>
  <span class="badge bg-secondary bg-opacity-25 text-secondary">
    <?= h($statuses[$doc['status']] ?? $doc['status']) ?>
  </span>
  <?php if (!empty($doc['nr_karty'])): ?>
  <span class="badge bg-light text-dark border font-monospace"><?= h($doc['nr_karty']) ?></span>
  <?php endif; ?>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/contracts/dokumenty/pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-danger">
      <i class="bi bi-file-earmark-pdf me-1"></i>Pobierz PDF
    </a>
    <?php if (class_exists('ZipArchive')): ?>
    <a href="<?= APP_URL ?>/contracts/dokumenty/docx.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-file-earmark-word me-1"></i>Pobierz Word
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<?php if ($can_edit_doc): ?>
<div class="card shadow-sm mb-3">
  <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
    <span class="small fw-semibold text-muted">Status dokumentu:</span>
    <?php foreach ($statuses as $key => $label): ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="set_status">
      <input type="hidden" name="status"  value="<?= h($key) ?>">
      <button class="btn btn-sm <?= $doc['status'] === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
              <?= $doc['status'] === $key ? 'disabled' : '' ?>>
        <?= h($label) ?>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-2">
  <div class="card-body" style="max-width:210mm;margin:0 auto">
    <?= cgd_org_header_html($doc, $src_row) ?>
  </div>
</div>

<form method="post" id="docForm">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="save">

  <?php if ($can_edit_doc): ?>
  <div class="card shadow-sm mb-2">
    <div class="card-body p-0">
      <textarea id="docBody" name="body" style="width:100%;min-height:520px"><?= h($doc['tresc_finalna']) ?></textarea>
      <div id="docEditorFallback" class="alert alert-warning m-2 mb-0 py-2 small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Edytor formatowania się nie wczytał (brak dostępu do CDN?) — treść można edytować jako zwykły tekst,
        zmiany i tak zostaną zapisane poprawnie.
      </div>
    </div>
  </div>
  <div class="d-flex justify-content-end">
    <button type="submit" class="btn btn-success">
      <i class="bi bi-check-lg me-1"></i>Zapisz zmiany
    </button>
  </div>
  <?php else: ?>
  <div class="card shadow-sm mb-2">
    <div class="card-body" style="max-width:210mm;margin:0 auto">
      <?= $doc['tresc_finalna'] ?>
    </div>
  </div>
  <?php endif; ?>
</form>

<!-- TinyMCE 7 (CDN, spójny z ezd/poczta/compose) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
<?php if ($can_edit_doc): ?>
if (!window.tinymce) {
  document.getElementById('docEditorFallback').style.display = 'block';
} else {
  tinymce.init({
    selector: '#docBody',
    license_key: 'gpl',
    promotion: false,
    branding: false,
    menubar: 'edit format insert table',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | '
           + 'alignleft aligncenter alignright alignjustify | bullist numlist | link table | removeformat',
    plugins: 'lists link table code',
    height: 560,
    content_style: 'body { font-family: Calibri, Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #1f2937; padding: 14px 18px; text-align: justify; }',
    entity_encoding: 'raw',
    setup: function (editor) {
      editor.on('change', function () { editor.save(); });
    }
  });
}
document.getElementById('docForm').addEventListener('submit', function () {
  if (window.tinymce && tinymce.get('docBody')) tinymce.triggerSave();
});
<?php else: ?>
// Podgląd tylko do odczytu — bez inicjalizacji edytora.
<?php endif; ?>
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
