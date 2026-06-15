<?php
/**
 * admin/envelope_editor.php — Edytor wzoru koperty.
 * Lewy panel: ustawienia układu. Prawy: żywy podgląd (skalowany, dane przykładowe).
 *
 * Nowy:   ?new=1
 * Edycja: ?id=N
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/envelopes.php';

require_role('admin');
env_migrate();

$SELF    = APP_URL . '/admin/envelope_editor.php';
$LIST    = APP_URL . '/admin/envelope_templates.php';
$RENDER  = APP_URL . '/print/envelope.php';
$formats = env_formats();

/* ── POST — zapis ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['_action'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $fmt  = array_key_exists($_POST['format'] ?? '', $formats) ? $_POST['format'] : 'DL';

    if (!$name) {
        flash_set('danger', 'Nazwa wzoru jest wymagana.');
        header('Location: ' . $SELF . ($id ? '?id=' . $id : '?new=1')); exit;
    }

    $options = [
        'font'            => ($_POST['font'] ?? '') === 'arial' ? 'arial' : 'montserrat',
        'font_size'       => in_array($_POST['font_size'] ?? '', ['small','normal','large'], true) ? $_POST['font_size'] : 'normal',
        'show_sender'     => !empty($_POST['show_sender']),
        'show_logo'       => !empty($_POST['show_logo']),
        'show_stamp_hint' => !empty($_POST['show_stamp_hint']),
        'recipient_pos'   => ($_POST['recipient_pos'] ?? '') === 'center' ? 'center' : 'standard',
        'sender_text'     => trim($_POST['sender_text'] ?? ''),
    ];

    if ($act === 'create') {
        $id = db_insert('envelope_templates', [
            'name'       => $name,
            'format'     => $fmt,
            'options'    => json_encode($options),
            'created_by' => (int)current_user()['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Wzór koperty zapisany.');
    } else {
        db()->prepare(
            "UPDATE envelope_templates SET name=?, format=?, options=?, updated_at=datetime('now','localtime') WHERE id=?"
        )->execute([$name, $fmt, json_encode($options), $id]);
        flash_set('success', 'Wzór koperty zaktualizowany.');
    }

    if (!empty($_POST['is_default'])) env_set_default($id);

    header('Location: ' . $SELF . '?id=' . $id); exit;
}

/* ── GET — ładowanie ──────────────────────────────────────────────────────── */
$id  = (int)($_GET['id'] ?? 0);
$tpl = $id ? env_get($id) : null;
if ($id && !$tpl) { http_response_code(404); die('Wzór nie istnieje.'); }

$o      = $tpl ? env_options($tpl) : env_default_options();
$curFmt = $tpl['format'] ?? 'DL';
$sender_default = env_sender_text(['sender_text' => '']); // z danych organizacji (do podglądu)

$PAGE_TITLE = $tpl ? 'Edycja koperty: ' . $tpl['name'] : 'Nowy wzór koperty';
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
#envPreviewBox { background:#eef2f7; border:1px solid #e2e8f0; border-radius:.5rem; padding:1.25rem; overflow:hidden; }
#envPreviewScale { transform-origin:top left; }
#envPaper { background:#fff; position:relative; box-shadow:0 2px 14px rgba(0,0,0,.12); overflow:hidden; color:#000; }
.env-prev-sender   { position:absolute; white-space:pre-line; }
.env-prev-stamp    { position:absolute; border:1px dashed #c0c0c0; border-radius:6px; display:flex; align-items:center; justify-content:center; text-align:center; color:#aaa; }
.env-prev-recipient{ position:absolute; white-space:pre-line; }
.env-prev-recipient .rn { font-weight:700; }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= $LIST ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h5 class="mb-0 fw-bold">
    <i class="bi bi-envelope text-primary me-1"></i><?= $tpl ? h($tpl['name']) : 'Nowy wzór koperty' ?>
  </h5>
  <?php if ($tpl): ?>
  <a href="<?= $RENDER ?>?template_id=<?= $tpl['id'] ?>&preview=1" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-eye me-1"></i>Podgląd / wydruk
  </a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<form method="post" id="envForm">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="<?= $tpl ? 'update' : 'create' ?>">
  <input type="hidden" name="id"      value="<?= $tpl ? $tpl['id'] : 0 ?>">

  <div class="row g-3">
    <!-- Lewa: ustawienia -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header py-2 small fw-semibold bg-light"><i class="bi bi-sliders me-1"></i>Ustawienia koperty</div>
        <div class="card-body small">

          <label class="form-label fw-semibold mb-1">Nazwa wzoru *</label>
          <input name="name" class="form-control form-control-sm fw-semibold mb-3" required maxlength="200"
                 value="<?= h($tpl['name'] ?? '') ?>" placeholder="np. Koperta DL — korespondencja">

          <label class="form-label fw-semibold mb-1">Format</label>
          <select name="format" id="fmt" class="form-select form-select-sm mb-3">
            <?php foreach ($formats as $code => $f): ?>
            <option value="<?= $code ?>" <?= $curFmt === $code ? 'selected' : '' ?>><?= h($f['label']) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold mb-1">Czcionka</label>
              <select name="font" id="font" class="form-select form-select-sm">
                <option value="montserrat" <?= ($o['font'] ?? 'montserrat') !== 'arial' ? 'selected' : '' ?>>Montserrat</option>
                <option value="arial"      <?= ($o['font'] ?? '') === 'arial' ? 'selected' : '' ?>>Arial</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold mb-1">Wielkość adresu</label>
              <select name="font_size" id="font_size" class="form-select form-select-sm">
                <option value="small"  <?= ($o['font_size'] ?? '') === 'small'  ? 'selected' : '' ?>>Mała</option>
                <option value="normal" <?= ($o['font_size'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normalna</option>
                <option value="large"  <?= ($o['font_size'] ?? '') === 'large'  ? 'selected' : '' ?>>Duża</option>
              </select>
            </div>
          </div>

          <label class="form-label fw-semibold mb-1">Pozycja adresata</label>
          <div class="d-flex gap-3 mb-3">
            <div class="form-check">
              <input class="form-check-input env-live" type="radio" name="recipient_pos" id="rp-std" value="standard" <?= ($o['recipient_pos'] ?? 'standard') !== 'center' ? 'checked' : '' ?>>
              <label class="form-check-label" for="rp-std">Standardowa (dół-prawo)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input env-live" type="radio" name="recipient_pos" id="rp-ctr" value="center" <?= ($o['recipient_pos'] ?? '') === 'center' ? 'checked' : '' ?>>
              <label class="form-check-label" for="rp-ctr">Wyśrodkowana</label>
            </div>
          </div>

          <label class="form-label fw-semibold mb-1">Nadawca (organizacja)</label>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input env-live" type="checkbox" name="show_sender" id="o-sender" <?= !empty($o['show_sender']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-sender">Pokaż blok nadawcy (góra-lewo)</label>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input env-live" type="checkbox" name="show_logo" id="o-logo" <?= !empty($o['show_logo']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-logo">Logo organizacji w nadawcy</label>
          </div>
          <textarea name="sender_text" id="sender_text" class="form-control form-control-sm mb-3" rows="3"
                    placeholder="Pusty = dane organizacji (nazwa, adres, miejscowość)"><?= h($o['sender_text'] ?? '') ?></textarea>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input env-live" type="checkbox" name="show_stamp_hint" id="o-stamp" <?= !empty($o['show_stamp_hint']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-stamp">Ramka „miejsce na znaczek" (góra-prawo)</label>
          </div>

          <div class="form-check mt-2 pt-2 border-top">
            <input class="form-check-input" type="checkbox" name="is_default" id="o-def" <?= !empty($tpl['is_default']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="o-def">Domyślny wzór koperty</label>
          </div>

          <button type="submit" class="btn btn-success btn-sm w-100 mt-3"><i class="bi bi-check-lg me-1"></i>Zapisz wzór</button>
        </div>
      </div>
    </div>

    <!-- Prawa: podgląd -->
    <div class="col-lg-7">
      <div class="d-flex align-items-center mb-2">
        <span class="small fw-semibold text-muted"><i class="bi bi-eye me-1"></i>Podgląd (dane przykładowe)</span>
      </div>
      <div id="envPreviewBox">
        <div id="envPreviewScale"><div id="envPaper"></div></div>
      </div>
      <div class="form-text mt-1"><i class="bi bi-info-circle me-1"></i>Adresat na podglądzie to dane przykładowe — przy druku pochodzą z systemu lub są wpisywane ręcznie.</div>
    </div>
  </div>
</form>

<script>
var FORMATS = <?= json_encode(array_map(fn($f) => ['w'=>$f['w'],'h'=>$f['h']], $formats)) ?>;
var SENDER_DEFAULT = <?= json_encode($sender_default) ?>;
var REC_PT = { small:12, normal:14, large:16 };
var MM = 3.7795, PT = 1.3333;            // mm→px, pt→px @96dpi
var SAMPLE_REC = ['Jan Kowalski', 'ul. Przykładowa 12/3', '00-001 Warszawa'];

function val(id){ return document.getElementById(id); }
function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function renderPreview() {
  var fmt = val('fmt').value, f = FORMATS[fmt] || FORMATS.DL;
  var w = f.w * MM, h = f.h * MM;
  var paper = val('envPaper');
  paper.style.width = w + 'px';
  paper.style.height = h + 'px';
  paper.style.fontFamily = val('font').value === 'arial'
    ? "Arial,'Helvetica Neue',Helvetica,sans-serif" : "'Montserrat','Segoe UI',Arial,sans-serif";

  var html = '';

  // Nadawca
  if (val('o-sender').checked) {
    var sender = (val('sender_text').value.trim() || SENDER_DEFAULT);
    var logo = val('o-logo').checked
      ? '<div style="width:'+(28*MM)+'px;height:'+(12*MM)+'px;background:#eef2f7;border-radius:3px;margin-bottom:'+(2*MM)+'px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:9px">logo</div>'
      : '';
    html += '<div class="env-prev-sender" style="top:'+(10*MM)+'px;left:'+(12*MM)+'px;max-width:'+(Math.max(70, f.w*0.45)*MM)+'px;font-size:'+(9.5*PT)+'px;line-height:1.4">'
          + logo + esc(sender) + '</div>';
  }

  // Znaczek
  if (val('o-stamp').checked) {
    html += '<div class="env-prev-stamp" style="top:'+(8*MM)+'px;right:'+(10*MM)+'px;width:'+(24*MM)+'px;height:'+(24*MM)+'px;font-size:'+(7*PT)+'px">miejsce<br>na<br>znaczek</div>';
  }

  // Adresat
  var pt = REC_PT[val('font_size').value] || 14;
  var center = val('rp-ctr').checked;
  var recStyle = center
    ? 'left:'+(12*MM)+'px;right:'+(12*MM)+'px;top:'+(f.h*0.46*MM)+'px;text-align:center'
    : 'left:'+(f.w*0.46*MM)+'px;right:'+(12*MM)+'px;top:'+(f.h*0.52*MM)+'px';
  var recHtml = SAMPLE_REC.map(function(l,i){ return '<div class="'+(i===0?'rn':'')+'">'+esc(l)+'</div>'; }).join('');
  html += '<div class="env-prev-recipient" style="'+recStyle+';font-size:'+(pt*PT)+'px;line-height:1.5">'+recHtml+'</div>';

  paper.innerHTML = html;

  // Skalowanie do szerokości boxa
  var box = val('envPreviewBox');
  var avail = box.clientWidth - 40;
  var scale = Math.min(1, avail / w);
  val('envPreviewScale').style.transform = 'scale(' + scale + ')';
  box.style.height = (h * scale + 40) + 'px';
}

document.querySelectorAll('#fmt,#font,#font_size,#sender_text,.env-live').forEach(function(el){
  el.addEventListener('input', renderPreview);
  el.addEventListener('change', renderPreview);
});
window.addEventListener('resize', renderPreview);
renderPreview();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
