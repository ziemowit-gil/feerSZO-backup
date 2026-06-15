<?php
/**
 * admin/print_template_editor.php — Edytor wzoru wydruku (hybryda).
 * Treść (Quill rich-text + zmienne) + układ (tło, nagłówek, tytuł, sloty podpis/pieczęć/QR).
 *
 * Nowy:     ?new=1 [&cat=zaswiadczenie]
 * Edycja:   ?id=N
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/print_templates.php';

require_role('admin');
pt_migrate();

$SELF       = APP_URL . '/admin/print_template_editor.php';
$LIST       = APP_URL . '/admin/print_templates.php';
$RENDER     = APP_URL . '/print/render.php';
$categories = pt_categories();

/* ── POST — zapis ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['_action'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $cat  = array_key_exists($_POST['category'] ?? '', $categories) ? $_POST['category'] : 'wlasne';
    $desc = trim($_POST['description'] ?? '');
    $body = $_POST['body'] ?? '';

    if (!$name) {
        flash_set('danger', 'Nazwa wzoru jest wymagana.');
        header('Location: ' . $SELF . ($id ? '?id=' . $id : '?new=1&cat=' . $cat)); exit;
    }

    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent'] ?? '') ? $_POST['accent'] : '#1e3a5f';
    $options = [
        'orientation'     => ($_POST['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
        'show_org_header' => !empty($_POST['show_org_header']),
        'show_title'      => !empty($_POST['show_title']),
        'title_text'      => trim($_POST['title_text'] ?? ''),
        'signature_slot'  => !empty($_POST['signature_slot']),
        'signature_label' => trim($_POST['signature_label'] ?? '') ?: 'Podpis osoby upoważnionej',
        'stamp_slot'      => !empty($_POST['stamp_slot']),
        'qr_slot'         => !empty($_POST['qr_slot']),
        'accent'          => $accent,
    ];

    // Tło: istniejące, nowy upload, lub usunięcie.
    $existing = $id ? (db_one("SELECT background_image FROM print_templates WHERE id=?", [$id])['background_image'] ?? null) : null;
    $bg = $existing;
    if (!empty($_POST['bg_remove'])) {
        if ($existing && is_file(UPLOAD_DIR . $existing)) @unlink(UPLOAD_DIR . $existing);
        $bg = null;
    }
    $up = pt_handle_bg_upload('bg_file');
    if ($up) {
        if ($existing && is_file(UPLOAD_DIR . $existing)) @unlink(UPLOAD_DIR . $existing);
        $bg = $up;
    }

    if ($act === 'create') {
        $id = db_insert('print_templates', [
            'name'             => $name,
            'category'         => $cat,
            'description'      => $desc,
            'body'             => $body,
            'options'          => json_encode($options),
            'background_image' => $bg,
            'created_by'       => (int)current_user()['id'],
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Wzór zapisany.');
    } else {
        db()->prepare(
            "UPDATE print_templates SET name=?, category=?, description=?, body=?, options=?, background_image=?,
             updated_at=datetime('now','localtime') WHERE id=?"
        )->execute([$name, $cat, $desc, $body, json_encode($options), $bg, $id]);
        flash_set('success', 'Wzór zaktualizowany.');
    }

    if (!empty($_POST['is_default'])) pt_set_default($id);

    header('Location: ' . $SELF . '?id=' . $id); exit;
}

/* ── GET — ładowanie ──────────────────────────────────────────────────────── */
$id  = (int)($_GET['id'] ?? 0);
$tpl = $id ? pt_get($id) : null;
if ($id && !$tpl) { http_response_code(404); die('Wzór nie istnieje.'); }

$init_cat  = $_GET['cat'] ?? 'zaswiadczenie';
$o         = $tpl ? pt_options($tpl) : pt_default_options();
$variables = pt_variables();
[$bg_b64, $bg_mime] = pt_bg_data($tpl['background_image'] ?? null);

$PAGE_TITLE = $tpl ? 'Edycja wzoru: ' . $tpl['name'] : 'Nowy wzór wydruku';
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
#editorWrap { display:flex; gap:0; border:1px solid #e2e8f0; border-radius:.5rem; overflow:hidden; }
#editorMain { flex:1; display:flex; flex-direction:column; min-width:0; }
#editorSide { width:250px; flex-shrink:0; display:flex; flex-direction:column; overflow:hidden; border-left:1px solid #e2e8f0; }
#quillEditor { min-height:380px; font-size:.93rem; }
.var-badge {
  display:inline-block; font-family:monospace; font-size:.72rem;
  background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;
  border-radius:4px; padding:.1rem .35rem; cursor:pointer; transition:background .12s; user-select:none;
}
.var-badge:hover { background:#dbeafe; }
.editor-tabs .btn { font-size:.76rem; padding:.25rem .6rem; }
.bg-preview { width:60px; height:78px; border:1px solid #e2e8f0; border-radius:4px; background:#f8fafc center/cover no-repeat; }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= $LIST ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h5 class="mb-0 fw-bold">
    <i class="bi bi-printer text-primary me-1"></i><?= $tpl ? h($tpl['name']) : 'Nowy wzór wydruku' ?>
  </h5>
  <?php if ($tpl): ?>
  <a href="<?= $RENDER ?>?template_id=<?= $tpl['id'] ?>&preview=1" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-eye me-1"></i>Podgląd
  </a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<form method="post" id="editorForm" enctype="multipart/form-data">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="<?= $tpl ? 'update' : 'create' ?>">
  <input type="hidden" name="id"      value="<?= $tpl ? $tpl['id'] : 0 ?>">
  <input type="hidden" name="body"    id="tpl-body">
  <input type="hidden" name="bg_remove" id="bg-remove" value="0">

  <!-- Meta -->
  <div class="row g-2 mb-3">
    <div class="col-md-5">
      <label class="form-label small fw-semibold mb-1">Nazwa wzoru *</label>
      <input name="name" class="form-control form-control-sm fw-semibold" required maxlength="200"
             value="<?= h($tpl['name'] ?? '') ?>" placeholder="np. Zaświadczenie o wolontariacie">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold mb-1">Kategoria</label>
      <select name="category" class="form-select form-select-sm">
        <?php foreach ($categories as $k => $v): ?>
        <option value="<?= $k ?>" <?= ($tpl['category'] ?? $init_cat) === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold mb-1">Opis (opcjonalnie)</label>
      <input name="description" class="form-control form-control-sm" value="<?= h($tpl['description'] ?? '') ?>">
    </div>
  </div>

  <div class="row g-3">
    <!-- Lewa: ustawienia układu -->
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header py-2 small fw-semibold bg-light"><i class="bi bi-layout-text-window-reverse me-1"></i>Układ dokumentu</div>
        <div class="card-body small">

          <label class="form-label fw-semibold mb-1">Orientacja</label>
          <div class="d-flex gap-3 mb-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="orientation" id="or-p" value="portrait" <?= ($o['orientation'] ?? 'portrait') !== 'landscape' ? 'checked' : '' ?>>
              <label class="form-check-label" for="or-p">Pionowa</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="orientation" id="or-l" value="landscape" <?= ($o['orientation'] ?? '') === 'landscape' ? 'checked' : '' ?>>
              <label class="form-check-label" for="or-l">Pozioma</label>
            </div>
          </div>

          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="show_org_header" id="o-hdr" <?= !empty($o['show_org_header']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-hdr">Nagłówek organizacji (logo + dane)</label>
          </div>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" name="show_title" id="o-title" <?= !empty($o['show_title']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-title">Tytuł dokumentu</label>
          </div>
          <input name="title_text" class="form-control form-control-sm mb-3" placeholder="Tytuł (pusty = nazwa wzoru)"
                 value="<?= h($o['title_text'] ?? '') ?>">

          <label class="form-label fw-semibold mb-1">Stopka — sloty</label>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" name="signature_slot" id="o-sign" <?= !empty($o['signature_slot']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-sign">Linia podpisu</label>
          </div>
          <input name="signature_label" class="form-control form-control-sm mb-2" placeholder="Opis podpisu"
                 value="<?= h($o['signature_label'] ?? 'Podpis osoby upoważnionej') ?>">
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" name="stamp_slot" id="o-stamp" <?= !empty($o['stamp_slot']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-stamp">Miejsce na pieczęć</label>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="qr_slot" id="o-qr" <?= !empty($o['qr_slot']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-qr">Kod QR (weryfikacja)</label>
          </div>

          <label class="form-label fw-semibold mb-1">Kolor akcentu</label>
          <input type="color" name="accent" class="form-control form-control-color form-control-sm mb-3"
                 value="<?= h($o['accent'] ?? '#1e3a5f') ?>">

          <label class="form-label fw-semibold mb-1">Tło / grafika (A4)</label>
          <div class="d-flex align-items-start gap-2 mb-2">
            <div class="bg-preview" id="bgPreview" <?= $bg_b64 ? 'style="background-image:url(data:' . h($bg_mime) . ';base64,' . $bg_b64 . ')"' : '' ?>></div>
            <div class="flex-grow-1">
              <input type="file" name="bg_file" id="bgFile" class="form-control form-control-sm"
                     accept=".jpg,.jpeg,.png,.svg">
              <?php if ($bg_b64): ?>
              <button type="button" class="btn btn-link btn-sm text-danger p-0 mt-1" onclick="removeBg()">
                <i class="bi bi-x-circle"></i> Usuń tło
              </button>
              <?php endif; ?>
              <div class="form-text" style="font-size:.68rem">JPG/PNG/SVG, do 8 MB. Najlepiej w proporcji A4.</div>
            </div>
          </div>

          <div class="form-check mt-3 pt-2 border-top">
            <input class="form-check-input" type="checkbox" name="is_default" id="o-def" <?= !empty($tpl['is_default']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-def">Domyślny wzór w kategorii</label>
          </div>
        </div>
      </div>
    </div>

    <!-- Prawa: edytor treści -->
    <div class="col-lg-8">
      <div id="editorWrap">
        <div id="editorMain">
          <div class="d-flex align-items-center gap-2 px-2 py-1 border-bottom bg-light flex-wrap">
            <div class="editor-tabs d-flex gap-1">
              <button type="button" class="btn btn-sm btn-primary active" id="btnVisual" onclick="setMode('visual')"><i class="bi bi-type me-1"></i>Wizualny</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHtml" onclick="setMode('html')"><i class="bi bi-code me-1"></i>HTML</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnText" onclick="setMode('text')"><i class="bi bi-fonts me-1"></i>Tekst</button>
            </div>
            <div id="quillToolbarWrap" class="flex-grow-1">
              <div id="quillToolbar" style="border:0;padding:0">
                <span class="ql-formats">
                  <select class="ql-header"><option selected></option><option value="1"></option><option value="2"></option><option value="3"></option></select>
                </span>
                <span class="ql-formats"><button class="ql-bold"></button><button class="ql-italic"></button><button class="ql-underline"></button></span>
                <span class="ql-formats"><select class="ql-align"></select></span>
                <span class="ql-formats"><button class="ql-list" value="ordered"></button><button class="ql-list" value="bullet"></button></span>
                <span class="ql-formats"><button class="ql-clean"></button></span>
              </div>
            </div>
            <button type="submit" class="btn btn-sm btn-success ms-auto" onclick="syncBody()"><i class="bi bi-check-lg me-1"></i>Zapisz wzór</button>
          </div>

          <div id="modeVisual"><div id="quillEditor"></div></div>
          <div id="modeHtml" style="display:none">
            <textarea id="tpl-html-src" class="form-control font-monospace" style="min-height:380px;font-size:.8rem;border:0;border-radius:0" placeholder="<p>Treść w HTML…</p>"></textarea>
          </div>
          <div id="modeText" style="display:none">
            <textarea id="tpl-text-src" class="form-control" style="min-height:380px;border:0;border-radius:0;line-height:1.6" placeholder="Treść w zwykłym tekście. Puste linie = akapity."></textarea>
          </div>
        </div>

        <div id="editorSide">
          <div class="px-2 py-1 border-bottom bg-light d-flex align-items-center justify-content-between">
            <span class="small fw-semibold text-muted">Zmienne</span>
            <small class="text-muted" style="font-size:.65rem">kliknij → wstaw</small>
          </div>
          <div class="overflow-y-auto p-2" style="font-size:.78rem;max-height:340px">
            <?php foreach ($variables as $group => $vars): ?>
            <div class="text-muted fw-bold mb-1 mt-2" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em"><?= h($group) ?></div>
            <?php foreach ($vars as $var => $vdesc): ?>
            <div class="mb-1">
              <span class="var-badge" onclick="insertVar(<?= json_encode($var) ?>)"><?= h($var) ?></span>
              <span class="text-muted ms-1" style="font-size:.66rem"><?= h($vdesc) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="form-text mt-1"><i class="bi bi-info-circle me-1"></i>Wstaw <code>{tresc_dokumentu}</code> w miejsce, gdzie ma się pojawić wygenerowana treść (np. zaświadczenia).</div>
    </div>
  </div>
</form>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
var quill = new Quill('#quillEditor', {
  theme: 'snow',
  modules: { toolbar: '#quillToolbar' },
  placeholder: 'Treść wzoru… wstaw zmienne z listy po prawej, np. {imie_nazwisko} albo {tresc_dokumentu}',
});
quill.root.innerHTML = <?= json_encode($tpl['body'] ?? '') ?>;

var _mode = 'visual';
function setMode(m) {
  var html = getHtml(); _mode = m;
  ['visual','html','text'].forEach(function(k) {
    document.getElementById('mode' + k[0].toUpperCase() + k.slice(1)).style.display = (k === m) ? '' : 'none';
    document.getElementById('btn' + k[0].toUpperCase() + k.slice(1)).className = 'btn btn-sm ' + (k === m ? 'btn-primary active' : 'btn-outline-secondary');
  });
  document.getElementById('quillToolbarWrap').style.display = (m === 'visual') ? '' : 'none';
  if (m === 'visual')      quill.root.innerHTML = html;
  else if (m === 'html')   document.getElementById('tpl-html-src').value = html;
  else { var d = document.createElement('div'); d.innerHTML = html; document.getElementById('tpl-text-src').value = (d.innerText || d.textContent || '').trim(); }
}
function getHtml() {
  if (_mode === 'visual') return quill.root.innerHTML;
  if (_mode === 'html')   return document.getElementById('tpl-html-src').value;
  var txt = document.getElementById('tpl-text-src').value;
  return txt.split(/\n{2,}/).map(function(p){ return '<p>' + p.replace(/\n/g,'<br>').trim() + '</p>'; }).join('');
}
function syncBody() { document.getElementById('tpl-body').value = getHtml(); }
function insertVar(v) {
  if (_mode === 'visual') {
    quill.focus();
    var r = quill.getSelection() || { index: quill.getLength()-1 };
    quill.insertText(r.index, v, 'user');
    quill.setSelection(r.index + v.length);
  } else {
    var ta = document.getElementById(_mode === 'html' ? 'tpl-html-src' : 'tpl-text-src');
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0,s) + v + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + v.length; ta.focus();
  }
}
function removeBg() {
  document.getElementById('bg-remove').value = '1';
  document.getElementById('bgPreview').style.backgroundImage = '';
  document.getElementById('bgFile').value = '';
}
document.getElementById('bgFile').addEventListener('change', function(e) {
  document.getElementById('bg-remove').value = '0';
  var f = e.target.files[0];
  if (f) { var r = new FileReader(); r.onload = function(ev){ document.getElementById('bgPreview').style.backgroundImage = 'url(' + ev.target.result + ')'; }; r.readAsDataURL(f); }
});
document.getElementById('editorForm').addEventListener('submit', syncBody);
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
