<?php
/**
 * admin/email_templates.php — Wizualny edytor maili systemowych.
 *
 * Lista:    (bez parametrów) — kafelki wszystkich szablonów z rejestru.
 * Edytor:   ?key=welcome — edytor wizualny (Quill) + HTML + podgląd na żywo.
 *
 * Akcje POST: save | reset | test.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/email_templates.php';
require_role('admin');
email_tpl_migrate();

$SELF = APP_URL . '/admin/email_templates.php';
$reg  = email_tpl_registry();

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';
    $key = $_POST['key'] ?? '';

    if (!email_tpl_exists($key)) {
        flash_set('danger', 'Nieznany szablon.');
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'save') {
        $subject = trim($_POST['subject'] ?? '');
        $body    = $_POST['body'] ?? '';
        // Flaga „aktywny" działa tylko dla maili automatycznych; ręczne zawsze aktywne.
        $enabled = email_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($subject === '') {
            flash_set('danger', 'Temat nie może być pusty.');
            header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
        }
        email_tpl_save($key, $subject, $body, $enabled, (int)(current_user()['id'] ?? 0));
        flash_set('success', 'Szablon „' . ($reg[$key]['label'] ?? $key) . '" zapisany.');
        header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
    }

    if ($act === 'reset') {
        email_tpl_reset($key);
        flash_set('success', 'Przywrócono domyślną treść szablonu.');
        header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
    }

    if ($act === 'test') {
        $to = trim($_POST['test_email'] ?? '') ?: (current_user()['email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Podaj poprawny adres e-mail do testu.');
            header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
        }
        // Zapisz najpierw bieżącą wersję z formularza, aby test odzwierciedlał edycję.
        $subject = trim($_POST['subject'] ?? '');
        $body    = $_POST['body'] ?? '';
        $enabled = email_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($subject !== '') {
            email_tpl_save($key, $subject, $body, $enabled, (int)(current_user()['id'] ?? 0));
        }
        $r = email_tpl_render($key, email_tpl_sample_vars($key));
        try {
            mail_queue_add($to, $to, '[TEST] ' . $r['subject'], $r['html'], '', 'email_tpl_test', null, '', true);
            mail_queue_process();
            flash_set('success', 'Wysłano testowy e-mail na: ' . h($to));
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd wysyłki testu: ' . h($e->getMessage()));
        }
        header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
    }

    header('Location: ' . $SELF); exit;
}

// ── GET — lista ───────────────────────────────────────────────────────────────
$key = $_GET['key'] ?? '';

if (!$key || !email_tpl_exists($key)) {
    $PAGE_TITLE = 'Maile systemowe';
    include dirname(__DIR__) . '/includes/header.php';

    // Pogrupuj szablony
    $groups = [];
    foreach ($reg as $k => $t) {
        $groups[$t['group']][$k] = $t;
    }
    ?>
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <h4 class="mb-0 fw-bold"><i class="bi bi-envelope-paper text-primary me-2"></i>Maile systemowe</h4>
      <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-grid me-1"></i>Panel admina
      </a>
    </div>
    <?= flash_html() ?>
    <p class="text-muted small mb-4" style="max-width:760px">
      Edytuj temat i treść automatycznych wiadomości wysyłanych przez system. Zmiany wchodzą
      w życie natychmiast — bez nadpisania używana jest treść domyślna. W edytorze wstawiasz
      zmienne <code>{{nazwa}}</code>, które system podstawia przy wysyłce.
    </p>

    <?php foreach ($groups as $gname => $items): ?>
      <div class="text-muted fw-bold mb-2" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em"><?= h($gname) ?></div>
      <div class="row g-3 mb-4">
        <?php foreach ($items as $k => $t):
            $cur = email_tpl_get($k); ?>
          <div class="col-md-6 col-xl-4">
            <a href="<?= $SELF ?>?key=<?= urlencode($k) ?>"
               class="text-decoration-none d-block h-100 border rounded-3 p-3 email-tpl-card">
              <div class="d-flex align-items-start gap-2">
                <span class="email-tpl-ico"><i class="bi <?= h($t['icon']) ?>"></i></span>
                <div class="flex-grow-1 min-w-0">
                  <div class="fw-semibold text-dark d-flex align-items-center gap-2">
                    <?= h($t['label']) ?>
                    <?php if ($cur['is_custom']): ?>
                      <span class="badge bg-primary-subtle text-primary border border-primary-subtle"
                            style="font-size:.6rem">zmieniony</span>
                    <?php endif; ?>
                    <?php if (!$cur['enabled']): ?>
                      <span class="badge bg-secondary-subtle text-secondary border"
                            style="font-size:.6rem">wyłączony</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted mt-1" style="font-size:.78rem;line-height:1.35"><?= h($t['description']) ?></div>
                </div>
                <i class="bi bi-chevron-right text-muted flex-shrink-0"></i>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <style>
    .email-tpl-card { transition:border-color .12s, box-shadow .12s, transform .12s; background:#fff; }
    .email-tpl-card:hover { border-color:#93c5fd!important; box-shadow:0 4px 14px rgba(37,99,235,.10); transform:translateY(-1px); }
    .email-tpl-ico {
      flex-shrink:0; width:38px; height:38px; border-radius:9px; display:flex;
      align-items:center; justify-content:center; background:#eff6ff; color:#2563eb; font-size:1.1rem;
    }
    .min-w-0 { min-width:0; }
    </style>
    <?php
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

// ── GET — edytor ───────────────────────────────────────────────────────────────
$t       = $reg[$key];
$cur     = email_tpl_get($key);
$vars    = $t['vars'];
$samples = email_tpl_sample_vars($key);
$my_email = current_user()['email'] ?? '';

$PAGE_TITLE = 'Mail: ' . $t['label'];
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
#etWrap { display:flex; gap:0; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
#etMain { flex:1; display:flex; flex-direction:column; min-width:0; }
#etPreview { width:46%; flex-shrink:0; border-left:1px solid #e2e8f0; display:flex; flex-direction:column; background:#f8fafc; }
#etPreview iframe { flex:1; width:100%; border:0; background:#fff; }
#etEditor { min-height:340px; font-size:.92rem; }
#et-html-src { flex:1; resize:none; border:0; border-radius:0; font-size:.82rem; padding:.75rem; min-height:340px; font-family:ui-monospace,Menlo,monospace; }
.var-badge {
  display:inline-block; font-family:monospace; font-size:.72rem;
  background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;
  border-radius:4px; padding:.1rem .4rem; cursor:pointer; transition:background .12s; user-select:none;
}
.var-badge:hover { background:#dbeafe; }
.editor-tabs .btn { font-size:.76rem; padding:.25rem .6rem; border-radius:4px; }
@media (max-width: 991px){ #etWrap{flex-direction:column} #etPreview{width:auto;border-left:0;border-top:1px solid #e2e8f0;min-height:380px} }
</style>

<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
  <a href="<?= $SELF ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h5 class="mb-0 fw-bold">
    <i class="bi <?= h($t['icon']) ?> text-primary me-1"></i><?= h($t['label']) ?>
  </h5>
  <?php if ($cur['is_custom']): ?>
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">zmieniony</span>
  <?php else: ?>
    <span class="badge bg-light text-muted border">domyślny</span>
  <?php endif; ?>
</div>
<p class="text-muted small mb-2"><?= h($t['description']) ?></p>
<?= flash_html() ?>

<form method="post" id="etForm">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="key"   value="<?= h($key) ?>">
  <input type="hidden" name="_action" id="etAction" value="save">
  <input type="hidden" name="body"  id="et-body">

  <!-- Temat + włącznik -->
  <div class="row g-2 mb-2 align-items-center">
    <div class="col-md-9">
      <div class="input-group input-group-sm">
        <span class="input-group-text">Temat</span>
        <input name="subject" id="et-subject" class="form-control fw-semibold"
               value="<?= h($cur['subject']) ?>" maxlength="300" required>
      </div>
    </div>
    <div class="col-md-3">
      <?php if (email_tpl_is_auto($key)): ?>
      <div class="form-check form-switch mb-0" title="Wyłączenie wstrzymuje automatyczną wysyłkę tego maila">
        <input class="form-check-input" type="checkbox" name="enabled" id="et-enabled" <?= $cur['enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="et-enabled">Automatyczna wysyłka</label>
      </div>
      <?php else: ?>
      <span class="badge bg-light text-muted border" title="Mail wysyłany ręcznie przez administratora">
        <i class="bi bi-hand-index me-1"></i>wysyłka ręczna
      </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pasek narzędzi -->
  <div class="d-flex align-items-center gap-2 px-2 py-1 border rounded-top bg-light flex-wrap">
    <div class="editor-tabs d-flex gap-1">
      <button type="button" class="btn btn-sm btn-primary active" id="btnVisual" onclick="setMode('visual')">
        <i class="bi bi-type me-1"></i>Wizualny
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHtml" onclick="setMode('html')">
        <i class="bi bi-code me-1"></i>HTML
      </button>
    </div>
    <div id="quillToolbarWrap" class="flex-grow-1">
      <div id="quillToolbar" style="border:0;padding:0">
        <span class="ql-formats">
          <select class="ql-header"><option selected></option><option value="1"></option><option value="2"></option><option value="3"></option></select>
        </span>
        <span class="ql-formats">
          <button class="ql-bold"></button><button class="ql-italic"></button><button class="ql-underline"></button>
        </span>
        <span class="ql-formats"><select class="ql-color"></select><select class="ql-background"></select></span>
        <span class="ql-formats"><select class="ql-align"></select></span>
        <span class="ql-formats"><button class="ql-list" value="ordered"></button><button class="ql-list" value="bullet"></button></span>
        <span class="ql-formats"><button class="ql-link"></button></span>
        <span class="ql-formats"><button class="ql-clean"></button></span>
      </div>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshPreview()">
      <i class="bi bi-arrow-clockwise me-1"></i>Odśwież podgląd
    </button>
  </div>

  <!-- Edytor + podgląd -->
  <div id="etWrap" class="rounded-bottom" style="border-top:0;border-radius:0 0 8px 8px">
    <div id="etMain">
      <div id="modeVisual" class="d-flex flex-column flex-grow-1">
        <div id="etEditor" style="flex:1"></div>
      </div>
      <div id="modeHtml" class="d-flex flex-column flex-grow-1" style="display:none!important">
        <textarea id="et-html-src" class="form-control flex-grow-1"></textarea>
      </div>
    </div>
    <div id="etPreview">
      <div class="px-2 py-1 border-bottom bg-white d-flex align-items-center justify-content-between">
        <span class="small fw-semibold text-muted"><i class="bi bi-eye me-1"></i>Podgląd (z przykładowymi danymi)</span>
      </div>
      <iframe id="etFrame" title="Podgląd maila"></iframe>
    </div>
  </div>

  <!-- Zmienne -->
  <div class="border rounded p-2 mt-3">
    <div class="small fw-semibold text-muted mb-2">
      Zmienne <small class="fw-normal">— kliknij, aby wstawić w miejscu kursora</small>
    </div>
    <div class="d-flex flex-wrap gap-2" style="font-size:.78rem">
      <?php foreach ($vars as $name => $meta): ?>
        <span class="var-badge" title="<?= h($meta['label']) ?>"
              onclick="insertVar('{{<?= h($name) ?>}}')">{{<?= h($name) ?>}}</span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Akcje -->
  <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
    <button type="submit" class="btn btn-success" onclick="document.getElementById('etAction').value='save';syncBody()">
      <i class="bi bi-check-lg me-1"></i>Zapisz szablon
    </button>

    <div class="input-group input-group-sm ms-auto" style="max-width:340px">
      <span class="input-group-text"><i class="bi bi-send"></i></span>
      <input type="email" name="test_email" class="form-control" placeholder="adres do testu"
             value="<?= h($my_email) ?>">
      <button type="submit" class="btn btn-outline-primary"
              onclick="document.getElementById('etAction').value='test';syncBody()">Wyślij test</button>
    </div>

    <button type="submit" class="btn btn-outline-danger btn-sm"
            onclick="if(!confirm('Przywrócić domyślną treść tego maila? Twoje zmiany zostaną usunięte.')){return false;}document.getElementById('etAction').value='reset'">
      <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślny
    </button>
  </div>
</form>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
var SAMPLES = <?= json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var INIT_BODY = <?= json_encode($cur['body']) ?>;

var quill = new Quill('#etEditor', {
  theme: 'snow',
  modules: { toolbar: '#quillToolbar' },
  placeholder: 'Treść maila… wstaw zmienne, np. {{org}}',
});
quill.root.innerHTML = INIT_BODY;
document.getElementById('et-html-src').value = INIT_BODY;

var _mode = 'visual';

function getHtml() {
  return _mode === 'visual' ? quill.root.innerHTML : document.getElementById('et-html-src').value;
}
function syncBody() { document.getElementById('et-body').value = getHtml(); }

function setMode(m) {
  var html = getHtml();
  _mode = m;
  document.getElementById('modeVisual').style.setProperty('display', m === 'visual' ? 'flex' : 'none', 'important');
  document.getElementById('modeHtml').style.setProperty('display', m === 'html' ? 'flex' : 'none', 'important');
  document.getElementById('btnVisual').className = 'btn btn-sm ' + (m === 'visual' ? 'btn-primary active' : 'btn-outline-secondary');
  document.getElementById('btnHtml').className   = 'btn btn-sm ' + (m === 'html'   ? 'btn-primary active' : 'btn-outline-secondary');
  document.getElementById('quillToolbarWrap').style.display = m === 'visual' ? '' : 'none';
  if (m === 'visual') quill.root.innerHTML = html;
  else document.getElementById('et-html-src').value = html;
  refreshPreview();
}

function insertVar(v) {
  if (_mode === 'visual') {
    quill.focus();
    var r = quill.getSelection() || { index: quill.getLength() - 1 };
    quill.insertText(r.index, v, 'user');
    quill.setSelection(r.index + v.length);
  } else {
    var ta = document.getElementById('et-html-src');
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
  }
  refreshPreview();
}

function applySamples(html) {
  return html.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, name) {
    return Object.prototype.hasOwnProperty.call(SAMPLES, name) ? SAMPLES[name] : m;
  });
}

function refreshPreview() {
  var frame = document.getElementById('etFrame');
  var doc = frame.contentDocument || frame.contentWindow.document;
  doc.open(); doc.write(applySamples(getHtml())); doc.close();
}

document.getElementById('etForm').addEventListener('submit', syncBody);
quill.on('text-change', function(){ if(_mode==='visual') refreshPreview(); });
document.getElementById('et-html-src').addEventListener('input', function(){ if(_mode==='html') refreshPreview(); });

refreshPreview();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
