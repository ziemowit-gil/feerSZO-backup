<?php
/**
 * admin/template_editor.php — Pełnoekranowy edytor wzoru dokumentu.
 * Nowy wzór: ?new=1 [&type=wolontariat]
 * Edycja:    ?id=N
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/contract_template_engine.php';
require_once dirname(__DIR__) . '/includes/contract_document_engine.php';
require_role('admin');
cte_migrate();
cgd_migrate();

$SELF = APP_URL . '/admin/template_editor.php';
$LIST = APP_URL . '/admin/contract_templates.php';

$type_labels = [
    'universal'   => 'Uniwersalny (wszystkie typy)',
    'wolontariat' => 'Wolontariat',
    'zlecenie'    => 'Zlecenie',
    'dzielo'      => 'Dzieło',
    'praca'       => 'Praca',
    'uslugi'      => 'Usługi',
    'inne'        => 'Inne',
];

// ── POST — zapis ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['_action'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = array_key_exists($_POST['type'] ?? '', $type_labels) ? $_POST['type'] : 'universal';
    $desc = trim($_POST['description'] ?? '');
    $body = $_POST['body'] ?? '';
    $verifies_data = isset($_POST['verifies_data']) ? 1 : 0;

    if (!$name) {
        flash_set('danger', 'Nazwa wzoru jest wymagana.');
        header('Location: ' . $SELF . ($id ? '?id=' . $id : '?new=1&type=' . $type));
        exit;
    }

    if ($act === 'create') {
        $new_id = db_insert('contract_doc_templates', [
            'name'        => $name,
            'type'        => $type,
            'description' => $desc,
            'body'        => $body,
            'verifies_data' => $verifies_data,
            'created_by'  => (int)current_user()['id'],
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Wzor zapisany.');
        header('Location: ' . $SELF . '?id=' . $new_id); exit;
    }

    if ($act === 'update') {
        db()->prepare(
            "UPDATE contract_doc_templates SET name=?,type=?,description=?,body=?,verifies_data=?,
             updated_at=datetime('now','localtime') WHERE id=?"
        )->execute([$name, $type, $desc, $body, $verifies_data, $id]);
        flash_set('success', 'Wzor zaktualizowany.');
        header('Location: ' . $SELF . '?id=' . $id); exit;
    }

    header('Location: ' . $LIST); exit;
}

// ── Import DOCX ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') { /* handled above */ }
if (!empty($_FILES['docx_file']['tmp_name'])) {
    // Import obsługiwany przez contract_templates.php; tu przekierujemy
}

// ── GET — ładowanie ───────────────────────────────────────────────────────────
$id      = (int)($_GET['id'] ?? 0);
$is_new  = isset($_GET['new']) || !$id;
$tpl     = $id ? db_one("SELECT * FROM contract_doc_templates WHERE id=?", [$id]) : null;
$init_type = $_GET['type'] ?? 'wolontariat';

if ($id && !$tpl) { http_response_code(404); die('Wzór nie istnieje.'); }

$variables = cte_variables();
$PAGE_TITLE = $tpl ? 'Edycja: ' . $tpl['name'] : 'Nowy wzór dokumentu';
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
#editorWrap { display:flex; gap:0; height:calc(100vh - 130px); min-height:500px; background:#fff; }
#editorMain { flex:1; display:flex; flex-direction:column; min-width:0; }
#editorSide { width:260px; flex-shrink:0; display:flex; flex-direction:column; overflow:hidden; border-left:1px solid #e2e8f0; }
#quillEditor { flex:1; font-size:.93rem; overflow-y:auto; }
#tpl-html-src, #tpl-text-src { flex:1; resize:none; border:0; border-radius:0; font-size:.82rem; padding:.75rem; }
.var-badge {
  display:inline-block; font-family:monospace; font-size:.72rem;
  background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;
  border-radius:4px; padding:.1rem .35rem; cursor:pointer; transition:background .12s; user-select:none;
}
.var-badge:hover { background:#dbeafe; }
.editor-tabs .btn { font-size:.76rem; padding:.25rem .6rem; border-radius:4px; }
</style>

<!-- Topbar edytora -->
<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
  <a href="<?= $LIST ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h5 class="mb-0 fw-bold">
    <i class="bi bi-file-earmark-text text-primary me-1"></i>
    <?= $tpl ? h($tpl['name']) : 'Nowy wzór dokumentu' ?>
  </h5>
  <?php if ($tpl): ?>
  <a href="<?= APP_URL ?>/contracts/print_template.php?template_id=<?= $tpl['id'] ?>&preview=1"
     target="_blank" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-eye me-1"></i>Podgląd
  </a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<form method="post" id="editorForm">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="<?= $tpl ? 'update' : 'create' ?>">
  <input type="hidden" name="id"      value="<?= $tpl ? $tpl['id'] : 0 ?>">
  <input type="hidden" name="body"    id="tpl-body">

  <!-- Meta — nazwa, typ, opis -->
  <div class="row g-2 mb-2">
    <div class="col-md-5">
      <input name="name" class="form-control form-control-sm fw-semibold"
             placeholder="Nazwa wzoru *" required maxlength="200"
             value="<?= h($tpl['name'] ?? '') ?>">
    </div>
    <div class="col-md-3">
      <select name="type" class="form-select form-select-sm">
        <?php foreach ($type_labels as $k => $v): ?>
        <option value="<?= $k ?>" <?= ($tpl['type'] ?? $init_type) === $k ? 'selected' : '' ?>>
          <?= h($v) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <input name="description" class="form-control form-control-sm text-muted"
             placeholder="Krótki opis (opcjonalnie)"
             value="<?= h($tpl['description'] ?? '') ?>">
    </div>
  </div>

  <div class="form-check mb-2">
    <input type="checkbox" class="form-check-input" id="verifies_data" name="verifies_data" value="1"
           <?= !empty($tpl['verifies_data']) ? 'checked' : '' ?>>
    <label class="form-check-label small" for="verifies_data">
      Ten wzór to <strong>Karta Weryfikacji Danych</strong> — wygenerowanie go zapisuje datę
      potwierdzenia aktualności danych tej umowy (ważność: <?= CGD_VERIFICATION_VALIDITY_MONTHS ?> mies.)
    </label>
  </div>

  <!-- Edytor pełnoekranowy -->
  <div id="editorWrap" class="border rounded overflow-hidden">

    <!-- Lewa: edytor -->
    <div id="editorMain">
      <!-- Zakładki trybu + toolbar Quill -->
      <div class="d-flex align-items-center gap-2 px-2 py-1 border-bottom bg-light flex-shrink-0">
        <div class="editor-tabs d-flex gap-1">
          <button type="button" class="btn btn-sm btn-primary active" id="btnVisual" onclick="setMode('visual')">
            <i class="bi bi-type me-1"></i>Wizualny
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHtml" onclick="setMode('html')">
            <i class="bi bi-code me-1"></i>HTML
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnText" onclick="setMode('text')">
            <i class="bi bi-fonts me-1"></i>Tekst
          </button>
        </div>
        <div id="quillToolbarWrap" class="flex-grow-1">
          <div id="quillToolbar" style="border:0;padding:0">
            <span class="ql-formats">
              <select class="ql-header"><option selected></option><option value="1"></option><option value="2"></option><option value="3"></option></select>
            </span>
            <span class="ql-formats">
              <button class="ql-bold"></button>
              <button class="ql-italic"></button>
              <button class="ql-underline"></button>
            </span>
            <span class="ql-formats">
              <select class="ql-align"></select>
            </span>
            <span class="ql-formats">
              <button class="ql-list" value="ordered"></button>
              <button class="ql-list" value="bullet"></button>
            </span>
            <span class="ql-formats">
              <button class="ql-indent" value="-1"></button>
              <button class="ql-indent" value="+1"></button>
            </span>
            <span class="ql-formats"><button class="ql-clean"></button></span>
          </div>
        </div>
        <!-- Zapisz -->
        <button type="submit" class="btn btn-sm btn-success ms-auto flex-shrink-0" onclick="syncBody()">
          <i class="bi bi-check-lg me-1"></i>Zapisz wzór
        </button>
      </div>

      <!-- Visual (Quill) -->
      <div id="modeVisual" class="d-flex flex-column flex-grow-1">
        <div id="quillEditor" style="flex:1"></div>
      </div>
      <!-- HTML -->
      <div id="modeHtml" class="d-flex flex-column flex-grow-1" style="display:none!important">
        <textarea id="tpl-html-src" class="form-control flex-grow-1"
                  placeholder="<p>Treść w HTML…</p>"></textarea>
      </div>
      <!-- Tekst -->
      <div id="modeText" class="d-flex flex-column flex-grow-1" style="display:none!important">
        <textarea id="tpl-text-src" class="form-control flex-grow-1"
                  placeholder="Treść w tekście zwykłym. Puste linie = akapity."></textarea>
      </div>
    </div>

    <!-- Prawa: zmienne -->
    <div id="editorSide">
      <div class="px-2 py-1 border-bottom bg-light d-flex align-items-center justify-content-between flex-shrink-0">
        <span class="small fw-semibold text-muted">Zmienne</span>
        <small class="text-muted" style="font-size:.65rem">kliknij → wstaw</small>
      </div>
      <div class="overflow-y-auto flex-grow-1 p-2" style="font-size:.78rem">
        <?php foreach ($variables as $group => $vars): ?>
        <div class="text-muted fw-bold mb-1 mt-2" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em"><?= h($group) ?></div>
        <?php foreach ($vars as $var => $desc): ?>
        <div class="mb-1">
          <span class="var-badge" onclick="insertVar(<?= json_encode($var) ?>)"><?= h($var) ?></span>
          <span class="text-muted ms-1" style="font-size:.67rem"><?= h($desc) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</form>

<!-- Quill -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
var quill = new Quill('#quillEditor', {
  theme: 'snow',
  modules: { toolbar: '#quillToolbar' },
  placeholder: 'Treść dokumentu… wstaw zmienne z listy po prawej, np. {imie_nazwisko}',
  bounds: '#editorWrap',
});
quill.root.innerHTML = <?= json_encode($tpl['body'] ?? '') ?>;

var _mode = 'visual';

function setMode(m) {
  var html = getHtml();
  _mode = m;
  ['visual','html','text'].forEach(function(k) {
    document.getElementById('mode' + k.charAt(0).toUpperCase() + k.slice(1)).style.setProperty('display', k === m ? 'flex' : 'none', 'important');
    var btn = document.getElementById('btn' + k.charAt(0).toUpperCase() + k.slice(1));
    btn.className = 'btn btn-sm ' + (k === m ? 'btn-primary active' : 'btn-outline-secondary');
  });
  document.getElementById('quillToolbarWrap').style.display = m === 'visual' ? '' : 'none';

  if (m === 'visual') {
    quill.root.innerHTML = html;
  } else if (m === 'html') {
    document.getElementById('tpl-html-src').value = html;
  } else {
    var tmp = document.createElement('div'); tmp.innerHTML = html;
    document.getElementById('tpl-text-src').value = (tmp.innerText || tmp.textContent || '').trim();
  }
}

function getHtml() {
  if (_mode === 'visual') return quill.root.innerHTML;
  if (_mode === 'html')   return document.getElementById('tpl-html-src').value;
  var txt = document.getElementById('tpl-text-src').value;
  return txt.split(/\n{2,}/).map(function(p) {
    return '<p>' + p.replace(/\n/g, '<br>').trim() + '</p>';
  }).join('');
}

function syncBody() { document.getElementById('tpl-body').value = getHtml(); }

function insertVar(v) {
  if (_mode === 'visual') {
    quill.focus();
    var r = quill.getSelection() || { index: quill.getLength()-1 };
    quill.insertText(r.index, v, 'user');
    quill.setSelection(r.index + v.length);
  } else {
    var id = _mode === 'html' ? 'tpl-html-src' : 'tpl-text-src';
    var ta = document.getElementById(id);
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0,s) + v + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
  }
}

document.getElementById('editorForm').addEventListener('submit', function() { syncBody(); });

// Resize editor to fill height
(function resize() {
  var wrap = document.getElementById('editorWrap');
  if (!wrap) return;
  var top = wrap.getBoundingClientRect().top + window.scrollY;
  wrap.style.height = (window.innerHeight - top - 20) + 'px';
})();
window.addEventListener('resize', function() {
  var wrap = document.getElementById('editorWrap');
  if (!wrap) return;
  var top = wrap.getBoundingClientRect().top + window.scrollY;
  wrap.style.height = (window.innerHeight - top - 20) + 'px';
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
