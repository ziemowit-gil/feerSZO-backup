<?php
/**
 * karty30/ti/sms_templates.php — Edytor szablonów SMS dla modułu Dydaktyka (TI).
 *
 * Lista:  (bez parametrów) — wszystkie szablony z rejestru (grupa "TI — Zajęcia").
 * Edytor: ?key=ti_lesson_reminder — zwykły textarea (SMS = czysty tekst),
 *         licznik znaków/segmentów, wstawianie zmiennych, test wysyłki.
 *
 * Akcje POST: save | reset | test.
 *
 * Świadomie NIE obejmuje SMS-ów z hasłami/kodami logowania — patrz komentarz
 * na górze includes/sms_templates.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms_templates.php';
require_role('admin');
sms_tpl_migrate();

$SELF   = APP_URL . '/karty30/ti/sms_templates.php';
$EMAIL_URL = APP_URL . '/karty30/ti/email_templates.php';
$reg    = sms_tpl_registry();

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';
    $key = $_POST['key'] ?? '';

    if (!isset($reg[$key])) {
        flash_set('danger', 'Nieznany szablon SMS.');
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'save') {
        $message = trim($_POST['message'] ?? '');
        $enabled = sms_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($message === '') {
            flash_set('danger', 'Treść SMS-a nie może być pusta.');
            header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
        }
        sms_tpl_save($key, $message, $enabled, (int)(current_user()['id'] ?? 0));
        flash_set('success', 'Szablon „' . ($reg[$key]['label'] ?? $key) . '" zapisany.');
        header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
    }

    if ($act === 'reset') {
        sms_tpl_reset($key);
        flash_set('success', 'Przywrócono domyślną treść szablonu.');
        header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
    }

    if ($act === 'test') {
        $phone = trim($_POST['test_phone'] ?? '');
        if ($phone === '') {
            flash_set('danger', 'Podaj numer telefonu do testu.');
            header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
        }
        $message = trim($_POST['message'] ?? '');
        $enabled = sms_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($message !== '') {
            sms_tpl_save($key, $message, $enabled, (int)(current_user()['id'] ?? 0));
        }
        if (!sms_channel_ready()) {
            flash_set('danger', 'Kanał SMS nie jest skonfigurowany — nie można wysłać testu.');
            header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
        }
        $r = sms_tpl_render($key, sms_tpl_sample_vars($key));
        try {
            sms_send($phone, '[TEST] ' . $r['message']);
            flash_set('success', 'Wysłano testowy SMS na: ' . h($phone));
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd wysyłki testu: ' . h($e->getMessage()));
        }
        header('Location: ' . $SELF . '?key=' . urlencode($key)); exit;
    }

    header('Location: ' . $SELF); exit;
}

// ── GET — lista ────────────────────────────────────────────────────────────
$key = $_GET['key'] ?? '';
if (!$key || !isset($reg[$key])) {
    $PAGE_TITLE = 'Szablony SMS — TI';
    include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
    ?>
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <h4 class="mb-0 fw-bold"><i class="bi bi-chat-left-text text-primary me-2"></i>Szablony SMS — Dydaktyka (TI)</h4>
      <a href="<?= $EMAIL_URL ?>" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-envelope-paper me-1"></i>Szablony e-mail
      </a>
    </div>
    <?= flash_html() ?>
    <p class="text-muted small mb-4" style="max-width:760px">
      Edytuj treść powiadomień SMS wysyłanych przez moduł Dydaktyka (TI). Zmiany wchodzą w życie
      natychmiast — bez nadpisania używana jest treść domyślna. Wstawiaj zmienne <code>{{nazwa}}</code>,
      które system podstawia przy wysyłce. Nie obejmuje SMS-ów z hasłami/kodami logowania —
      te zostają na stałe w kodzie ze względów bezpieczeństwa.
    </p>

    <div class="row g-3">
      <?php foreach ($reg as $k => $t):
          $cur = sms_tpl_get($k);
          $len = mb_strlen($cur['message']); ?>
        <div class="col-md-6 col-xl-4">
          <a href="<?= $SELF ?>?key=<?= urlencode($k) ?>"
             class="text-decoration-none d-block h-100 border rounded-3 p-3 sms-tpl-card">
            <div class="d-flex align-items-start gap-2">
              <span class="sms-tpl-ico"><i class="bi <?= h($t['icon']) ?>"></i></span>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold text-dark d-flex align-items-center gap-2 flex-wrap">
                  <?= h($t['label']) ?>
                  <?php if ($cur['is_custom']): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.6rem">zmieniony</span>
                  <?php endif; ?>
                  <?php if (!$cur['enabled']): ?>
                    <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.6rem">wyłączony</span>
                  <?php endif; ?>
                </div>
                <div class="text-muted mt-1" style="font-size:.78rem;line-height:1.35"><?= h($t['description']) ?></div>
                <div class="text-muted mt-1" style="font-size:.7rem"><?= $len ?> znaków</div>
              </div>
              <i class="bi bi-chevron-right text-muted flex-shrink-0"></i>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <style>
    .sms-tpl-card { transition:border-color .12s,box-shadow .12s,transform .12s; background:#fff; }
    .sms-tpl-card:hover { border-color:#93c5fd!important; box-shadow:0 4px 14px rgba(37,99,235,.10); transform:translateY(-1px); }
    .sms-tpl-ico { flex-shrink:0;width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:#eff6ff;color:#4338ca;font-size:1.1rem; }
    .min-w-0 { min-width:0; }
    </style>
    <?php
    include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php';
    exit;
}

// ── GET — edytor ────────────────────────────────────────────────────────────
$t    = $reg[$key];
$cur  = sms_tpl_get($key);
$vars = $t['vars'];

$PAGE_TITLE = 'SMS TI: ' . $t['label'];
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<style>
#stPreview { background:#f1f5f9; border-radius:12px; padding:.9rem 1rem; font-size:.92rem; white-space:pre-wrap; word-break:break-word; max-width:340px; }
.var-badge { display:inline-block;font-family:monospace;font-size:.72rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:4px;padding:.1rem .4rem;cursor:pointer;transition:background .12s;user-select:none; }
.var-badge:hover { background:#dbeafe; }
#st-message { font-family:ui-monospace,Menlo,monospace; font-size:.92rem; }
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

<form method="post" id="stForm">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="key"     value="<?= h($key) ?>">
  <input type="hidden" name="_action" id="stAction" value="save">

  <div class="row g-3">
    <div class="col-lg-7">
      <?php if (sms_tpl_is_auto($key)): ?>
      <div class="form-check form-switch mb-2" title="Wyłączenie wstrzymuje automatyczną wysyłkę">
        <input class="form-check-input" type="checkbox" name="enabled" id="st-enabled" <?= $cur['enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="st-enabled">Automatyczna wysyłka</label>
      </div>
      <?php else: ?>
      <span class="badge bg-light text-muted border mb-2"><i class="bi bi-hand-index me-1"></i>wysyłka ręczna</span>
      <?php endif; ?>

      <textarea name="message" id="st-message" class="form-control" rows="5" maxlength="2000"
                required><?= h($cur['message']) ?></textarea>
      <div class="d-flex justify-content-between mt-1">
        <span class="small text-muted" id="stLen"></span>
        <span class="small text-muted" id="stSegments"></span>
      </div>

      <div class="border rounded p-2 mt-2">
        <div class="small fw-semibold text-muted mb-2">
          Zmienne <small class="fw-normal">— kliknij, aby wstawić w miejscu kursora</small>
        </div>
        <div class="d-flex flex-wrap gap-2" style="font-size:.78rem">
          <?php foreach ($vars as $name => $meta): ?>
            <span class="var-badge" title="<?= h($meta['label']) ?>" onclick="insertVar('{{<?= h($name) ?>}}')">{{<?= h($name) ?>}}</span>
          <?php endforeach; ?>
        </div>
        <div class="mt-2 pt-2 border-top" style="font-size:.72rem;color:#6c757d">
          <?php foreach ($vars as $name => $meta): ?>
            <div><code>{{<?= h($name) ?>}}</code> — <?= h($meta['label']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
        <button type="submit" class="btn btn-success" onclick="document.getElementById('stAction').value='save'">
          <i class="bi bi-check-lg me-1"></i>Zapisz szablon
        </button>

        <div class="input-group input-group-sm ms-auto" style="max-width:280px">
          <span class="input-group-text"><i class="bi bi-send"></i></span>
          <input type="text" name="test_phone" class="form-control" placeholder="numer telefonu do testu">
          <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('stAction').value='test'">Wyślij test</button>
        </div>

        <button type="submit" class="btn btn-outline-danger btn-sm"
                onclick="if(!confirm('Przywrócić domyślną treść? Twoje zmiany zostaną usunięte.')){return false;}document.getElementById('stAction').value='reset'">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślny
        </button>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="small fw-semibold text-muted mb-2"><i class="bi bi-phone me-1"></i>Podgląd (z przykładowymi danymi)</div>
      <div id="stPreview"></div>
    </div>
  </div>
</form>

<script>
var SAMPLES = <?= json_encode(sms_tpl_sample_vars($key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var ta = document.getElementById('st-message');

function applySamples(txt) {
  return txt.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, name) {
    return Object.prototype.hasOwnProperty.call(SAMPLES, name) ? SAMPLES[name] : m;
  });
}

// Segment SMS: 160 znaków bez polskich diakrytyków (GSM-7), 70 z nimi (UCS-2) —
// przybliżenie, dokładne liczenie zależy od bramki, ale to wystarczający sygnał.
function updateCounters() {
  var v = ta.value;
  var hasPl = /[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/.test(v);
  var per = hasPl ? 70 : 160;
  var segs = v.length === 0 ? 0 : Math.ceil(v.length / per);
  document.getElementById('stLen').textContent = v.length + ' znaków' + (hasPl ? ' (zawiera polskie znaki → UCS-2)' : '');
  document.getElementById('stSegments').textContent = segs + ' ' + (segs === 1 ? 'segment SMS' : 'segmenty/ów SMS') + ' (~' + per + ' zn./segment)';
  document.getElementById('stPreview').textContent = applySamples(v);
}

function insertVar(v) {
  var s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
  updateCounters();
}

ta.addEventListener('input', updateCounters);
updateCounters();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
